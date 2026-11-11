<?php

namespace App\Services\Attendance;

use App\Models\Document;
use App\Models\User;
use App\Services\Imports\MalwareScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** M10 / documents: private scanned supporting documents, delivered only as authorized attachments. */
class LeaveDocument
{
    public function __construct(private MalwareScanner $scanner) {}

    public static function disk(): string
    {
        return config('attendance.document_disk') === 'local' ? 'local' : 's3';
    }

    /** Production always scans, whatever the setting says. */
    public static function scanRequired(): bool
    {
        return config('attendance.document_scan_required') || app()->isProduction();
    }

    public static function bucket(): ?string
    {
        return self::disk() === 's3' ? config('filesystems.disks.s3.bucket') : 'local';
    }

    public function store(User $actor, UploadedFile $file, string $key): Document
    {
        Validator::make(['support' => $file], ['support' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']])->validate();
        if (! self::bucket()) {
            throw ValidationException::withMessages(['support' => 'Private document storage is unavailable. Please contact your administrator.']);
        }
        if (self::scanRequired()) {
            try {
                $this->scanner->scan($file->getRealPath());
            } catch (\RuntimeException) {
                throw ValidationException::withMessages(['support' => 'The document security check failed or is unavailable. Try again with a valid document.']);
            }
        }
        $bytes = file_get_contents($file->getRealPath());
        $mime = $file->getMimeType();
        if ($mime === 'application/pdf') {
            if (! str_starts_with($bytes, '%PDF-') || ! str_contains(substr($bytes, -2048), '%%EOF')) {
                throw ValidationException::withMessages(['support' => 'Choose a complete, valid PDF.']);
            }
        } else {
            $size = @getimagesizefromstring($bytes);
            $limit = ini_parse_quantity(ini_get('memory_limit'));
            if ($size && $limit > 0 && memory_get_usage(true) + $size[0] * $size[1] * 20 + strlen($bytes) * 4 > $limit * .8) {
                throw ValidationException::withMessages(['support' => 'Resize this image before uploading so it can be processed safely.']);
            }
            if (! $size || max($size[0], $size[1]) > 4096 || $size[0] * $size[1] > 8000000) {
                throw ValidationException::withMessages(['support' => 'Use an image up to 4096 pixels per side and 8 megapixels.']);
            }
            $source = @imagecreatefromstring($bytes);
            if (! $source) {
                throw ValidationException::withMessages(['support' => 'This image could not be read.']);
            }
            $image = imagecreatetruecolor($size[0], $size[1]);
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
            imagecopy($image, $source, 0, 0, 0, 0, $size[0], $size[1]);
            ob_start();
            imagejpeg($image, null, 90);
            $bytes = ob_get_clean();
            imagedestroy($source);
            imagedestroy($image);
            $mime = 'image/jpeg';
        }
        if (! is_string($bytes) || strlen($bytes) > 5242880) {
            throw ValidationException::withMessages(['support' => 'The processed document must be no larger than 5 MB.']);
        }
        try {
            $saved = Storage::disk(self::disk())->put($key, Crypt::encryptString($bytes), ['visibility' => 'private']);
        } catch (\Throwable) {
            $saved = false;
        }
        if (! $saved) {
            throw ValidationException::withMessages(['support' => 'Your document could not be saved. Try again later.']);
        }
        $doc = new Document;
        $doc->forceFill(['owner_type' => 'user', 'owner_id' => $actor->id, 'uploaded_by' => $actor->id, 'disk' => self::disk(), 'bucket' => self::bucket(), 'object_key' => $key, 'original_filename' => $mime === 'application/pdf' ? 'support.pdf' : 'support.jpg', 'mime_type' => $mime, 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'document_type_code' => 'leave_support', 'scan_status' => self::scanRequired() ? 'clean' : 'skipped'])->save();

        return $doc;
    }

    public function bytes(Document $doc): string
    {
        $expectedBucket = $doc->disk === 's3' ? config('filesystems.disks.s3.bucket') : 'local';
        $scanAccepted = $doc->scan_status === 'clean' || ($doc->scan_status === 'skipped' && ! self::scanRequired());
        if (! in_array($doc->disk, ['s3', 'local'], true) || blank($expectedBucket) || $doc->bucket !== $expectedBucket || ! $scanAccepted || $doc->document_type_code !== 'leave_support' || $doc->size_bytes > 5242880 || ! in_array($doc->mime_type, ['application/pdf', 'image/jpeg'], true)) {
            throw new \RuntimeException('Invalid document');
        }
        $encrypted = Storage::disk($doc->disk)->get($doc->object_key);
        if (! is_string($encrypted) || strlen($encrypted) > 14000000) {
            throw new \RuntimeException('Unavailable document');
        }
        $bytes = Crypt::decryptString($encrypted);
        if (strlen($bytes) !== (int) $doc->size_bytes || ! hash_equals($doc->sha256, hash('sha256', $bytes))) {
            throw new \RuntimeException('Invalid checksum');
        }

        return $bytes;
    }
}
