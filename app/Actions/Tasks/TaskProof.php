<?php

namespace App\Actions\Tasks;

use App\Models\Document;
use App\Models\OutboxEvent;
use App\Models\Task;
use App\Models\TaskSubmissionDocument;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Imports\MalwareScanner;
use App\Services\Tasks\RiderAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** M06 / UI23: bounded raster images, encrypted private S3, fail-closed asynchronous scan. */
class TaskProof
{
    public function __construct(private RiderAccess $access, private RiderWork $work) {}

    /** Production always scans, whatever the environment configuration says. */
    public static function scanRequired(): bool
    {
        return config('task_execution.scan_required') || app()->isProduction();
    }

    public static function bucket(): ?string
    {
        return config('task_execution.disk') === 's3' ? config('filesystems.disks.s3.bucket') : 'local';
    }

    public static function storageReady(): bool
    {
        return filled(self::bucket());
    }

    public function upload(User $actor, Task $context, int $version, UploadedFile $file, string $category, ?string $caption): Document
    {
        Gate::forUser($actor->fresh())->authorize('execute', $context);
        Validator::make(['file' => $file, 'category' => $category, 'caption' => $caption], [
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('task_execution.max_image_kib')],
            'category' => ['required', Rule::exists('reference_values', 'code')->where('category', 'image_category')->where('is_active', true)],
            'caption' => ['nullable', 'string', 'max:1000'],
        ])->validate();
        $this->access->current($actor, $context) || abort(404);
        $bytes = $this->raster($file);
        $bucket = self::bucket();
        if (! $bucket) {
            throw new RuntimeException('private_storage_unavailable');
        }
        $object = 'tasks/proof/'.Str::uuid7().'.encrypted';
        $stored = false;
        try {
            return DB::transaction(function () use ($actor, $context, $version, $bytes, $category, $caption, $bucket, $object, &$stored): Document {
                $task = $this->access->locked($actor, $context);
                if ($task->work_status !== 'in_progress' || $task->review_status === 'returned') {
                    throw ValidationException::withMessages(['proof' => 'Start work before uploading proof.']);
                }
                $visit = $task->activities()->where('performed_by', $actor->id)->where('activity_type_code', 'visit')->latest('id')->first();
                if (! $visit || $task->submissions()->get()->contains(fn ($s) => ($s->completion_snapshot_ciphertext['visit'] ?? null) === $visit->public_id)) {
                    throw ValidationException::withMessages(['proof' => 'Save a new visit draft before adding proof.']);
                }
                $documents = Document::query()->where('owner_type', 'task')->where('owner_id', $task->id)->where('activity_id', $visit->id)->where('uploaded_by', $actor->id)->where('document_type_code', 'proof_image');
                $hash = hash('sha256', $bytes);
                if ($existing = (clone $documents)->where('sha256', $hash)->where('image_category_code', $category)->first()) {
                    return $existing;
                }
                if ($version !== $task->lock_version) {
                    throw ValidationException::withMessages(['version' => 'This task changed. Reload and review before uploading.']);
                }
                if ($documents->count() >= config('task_execution.max_images')) {
                    throw ValidationException::withMessages(['proof' => 'Remove a draft image before adding more. Maximum 10 images.']);
                }
                if (! Storage::disk(config('task_execution.disk'))->put($object, Crypt::encryptString($bytes), ['visibility' => 'private'])) {
                    throw new RuntimeException('private_storage_failed');
                }
                $stored = true;
                $document = new Document;
                $document->forceFill(['owner_type' => 'task', 'owner_id' => $task->id, 'activity_id' => $visit->id, 'uploaded_by' => $actor->id, 'disk' => config('task_execution.disk'), 'bucket' => $bucket, 'object_key' => $object, 'original_filename' => 'proof.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => strlen($bytes), 'sha256' => $hash, 'document_type_code' => 'proof_image', 'image_category_code' => $category, 'caption_ciphertext' => trim($caption ?? '') ?: null, 'scan_status' => self::scanRequired() ? 'pending' : 'skipped'])->save();
                if (self::scanRequired()) {
                    $this->enqueue($document);
                }
                $this->work->change($actor, $task, 'proof_uploaded', 'in_progress', 'none');

                return $document->refresh();
            }, 3);
        } catch (\Throwable $error) {
            if ($stored) {
                try {
                    Storage::disk(config('task_execution.disk'))->delete($object);
                } catch (\Throwable) {
                }
            }
            throw $error;
        }
    }

    private function raster(UploadedFile $file): string
    {
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] < 1 || $info[1] < 1 ||
            max($info[0], $info[1]) > config('task_execution.max_dimension') || config('task_execution.max_pixels') < $info[0] * $info[1]) {
            throw ValidationException::withMessages(['proof' => 'Choose a valid JPEG, PNG or WebP up to 8192px and 24 megapixels.']);
        }
        $limit = ini_parse_quantity(ini_get('memory_limit'));
        if ($limit > 0 && memory_get_usage(true) + $info[0] * $info[1] * 16 + $file->getSize() * 4 > $limit * 0.8) {
            throw ValidationException::withMessages(['proof' => 'This image exceeds the safe decoder memory budget. Resize it and retry.']);
        }
        $source = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if (! $source) {
            throw ValidationException::withMessages(['proof' => 'This image could not be decoded.']);
        }
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($file->getRealPath(), null, true);
            $orientation = (int) ($exif['IFD0']['Orientation'] ?? 1);
            if (in_array($orientation, [2, 5, 7], true)) {
                imageflip($source, IMG_FLIP_HORIZONTAL);
            }
            if ($orientation === 4) {
                imageflip($source, IMG_FLIP_VERTICAL);
            }
            $angle = match ($orientation) {
                3 => 180,5,8 => 90,6,7 => -90,default => 0
            };
            if ($angle !== 0) {
                $rotated = imagerotate($source, $angle, 0);
                imagedestroy($source);
                if (! $rotated) {
                    throw ValidationException::withMessages(['proof' => 'Image orientation could not be normalized.']);
                }
                $source = $rotated;
            }
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = imagecreatetruecolor($width, $height);
        try {
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
            ob_start();
            imagejpeg($canvas, null, 90);
            $bytes = ob_get_clean();
            if (! is_string($bytes) || strlen($bytes) > config('task_execution.max_image_kib') * 1024) {
                throw ValidationException::withMessages(['proof' => 'Decoded image exceeds the upload limit.']);
            }

            return $bytes;
        } finally {
            imagedestroy($source);
            imagedestroy($canvas);
        }
    }

    public function retry(User $actor, Task $context, int $version, string $reference): void
    {
        DB::transaction(function () use ($actor, $context, $version, $reference): void {
            $task = $this->access->locked($actor, $context, $version);
            $document = $this->owned($task, $actor, $reference)->lockForUpdate()->firstOrFail();
            abort_if(TaskSubmissionDocument::query()->where('document_id', $document->id)->exists(), 403);
            if ($document->scan_status !== 'error') {
                throw ValidationException::withMessages(['proof' => 'Only failed scans can be retried.']);
            }
            $document->scan_status = 'pending';
            $document->save();
            $this->enqueue($document);
            $this->work->change($actor, $task, 'proof_retry', 'in_progress', 'none');
        }, 3);
    }

    public function remove(User $actor, Task $context, int $version, string $reference): void
    {
        DB::transaction(function () use ($actor, $context, $version, $reference): void {
            $task = $this->access->locked($actor, $context, $version);
            $document = $this->owned($task, $actor, $reference)->lockForUpdate()->firstOrFail();
            if (TaskSubmissionDocument::query()->where('document_id', $document->id)->exists()) {
                throw ValidationException::withMessages(['proof' => 'Submitted evidence is immutable.']);
            }
            $document->delete();
            $this->work->change($actor, $task, 'proof_removed', 'in_progress', 'none');
        }, 3);
    }

    private function owned(Task $task, User $actor, string $reference): Builder
    {
        return Document::query()->where('public_id', $reference)->where('owner_type', 'task')->where('owner_id', $task->id)->where('uploaded_by', $actor->id)->where('document_type_code', 'proof_image');
    }

    private function enqueue(Document $document): void
    {
        $event = new OutboxEvent;
        $event->forceFill(['aggregate_type' => 'document', 'aggregate_id' => $document->id, 'event_type' => 'tasks.proof_scan', 'event_key' => (string) Str::uuid7(), 'payload' => ['document' => $document->public_id], 'available_at' => now()])->save();
    }

    public function bytes(Document $document): string
    {
        if ($document->disk !== config('task_execution.disk') || $document->bucket !== self::bucket() || $document->document_type_code !== 'proof_image' ||
            $document->size_bytes > config('task_execution.max_image_kib') * 1024) {
            throw new RuntimeException('proof_storage_invalid');
        }
        $encrypted = Storage::disk(config('task_execution.disk'))->get($document->object_key);
        if (! is_string($encrypted) || strlen($encrypted) > config('task_execution.max_image_kib') * 2048 + 8192) {
            throw new RuntimeException('proof_unavailable');
        }
        $bytes = Crypt::decryptString($encrypted);
        if (strlen($bytes) !== (int) $document->size_bytes || ! hash_equals($document->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('proof_integrity_failed');
        }

        return $bytes;
    }

    public function scan(int $id, MalwareScanner $scanner): void
    {
        $document = Document::query()->find($id);
        if (! $document || $document->owner_type !== 'task' || $document->document_type_code !== 'proof_image' || $document->scan_status !== 'pending') {
            return;
        }
        $path = tempnam(sys_get_temp_dir(), 'debtops-proof-');
        try {
            file_put_contents($path, $this->bytes($document));
            $scanner->scan($path);
            $status = 'clean';
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'malware_detected') {
                throw new RuntimeException('proof_scan_unavailable');
            }
            $status = 'rejected';
        } finally {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
        DB::transaction(function () use ($document, $status): void {
            $fresh = Document::query()->lockForUpdate()->find($document->id);
            if ($fresh && $fresh->scan_status === 'pending') {
                $fresh->scan_status = $status;
                $fresh->save();
                app(AuditWriter::class)->record(null, 'proof_scan_result', $fresh->public_id, ['status' => $status], module: 'M06', entityType: 'document');
            }
        });
    }
}
