<?php

namespace App\Services\Tasks;

use App\Models\Document;
use App\Models\Task;
use Carbon\CarbonInterface;
use GdImage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * M06 / UI23: stamp normalized proof once, before encryption and the integrity hash are recorded.
 * One orange-red line in the top-right corner, e.g. "Nexora_FG_150_8800001050_2026-11-14 12:52": company name, task code,
 * account number and server time (MYT). It is drawn at the photo's own resolution with a bold serif font sized to the photo.
 */
class ProofWatermark
{
    /** Bold serif shipped with the dompdf dependency; the built-in bitmap font is used only if it is missing. */
    private const FONT = 'vendor/dompdf/dompdf/lib/fonts/DejaVuSerif-Bold.ttf';

    /** Text height as a share of the photo's shorter side, with pixel limits for tiny and very large photos. */
    private const TEXT_RATIO = 0.026;

    private const MIN_TEXT_PX = 14;

    private const MAX_TEXT_PX = 110;

    /** Orange-red text colour (#E8461E). */
    private const COLOUR = [232, 70, 30];

    public function stamp(string $jpeg, Task $task, Document $document, CarbonInterface $recordedAt): string
    {
        $image = imagecreatefromstring($jpeg);
        if (! $image) {
            throw ValidationException::withMessages(['proof' => 'The proof image could not be prepared.']);
        }
        $line = $this->text($task, $recordedAt);
        try {
            imagealphablending($image, true);
            $font = base_path(self::FONT);
            is_file($font) ? $this->drawVectorStamp($image, $line, $font) : $this->drawBitmapStamp($image, $line);
            ob_start();
            imagejpeg($image, null, 90);
            $bytes = ob_get_clean();
            if (! is_string($bytes) || strlen($bytes) > config('task_execution.max_image_kib') * 1024) {
                throw ValidationException::withMessages(['proof' => 'Watermarked image exceeds the upload limit. Resize it and retry.']);
            }

            return $bytes;
        } finally {
            imagedestroy($image);
        }
    }

    /** Company name, task code (letters and number split, e.g. FG150 → FG_150), account number and server time, joined by underscores. */
    private function text(Task $task, CarbonInterface $recordedAt): string
    {
        $taskCode = Str::ascii((string) ($task->source_task_id ?: $task->public_id));
        if (preg_match('/^([A-Za-z]+)(\d+)$/', $taskCode, $match) === 1) {
            $taskCode = $match[1].'_'.$match[2];
        }
        $account = $task->relationLoaded('account') ? $task->account?->account_number : $task->account()->value('account_number');

        return implode('_', array_filter([
            Str::ascii((string) config('dcms.branding.name', 'Nexora')),
            $taskCode,
            $account ? Str::ascii((string) $account) : null,
            $recordedAt->copy()->timezone(config('dcms.business_timezone'))->format('Y-m-d H:i'),
        ]));
    }

    /** Right-aligned single line in the top-right corner, with a faint shadow so it stays readable on light backgrounds. */
    private function drawVectorStamp(GdImage $image, string $line, string $font): void
    {
        $imageWidth = imagesx($image);
        $imageHeight = imagesy($image);
        $margin = max(4, (int) round(min($imageWidth, $imageHeight) * 0.025));
        $textPx = (int) round(min($imageWidth, $imageHeight) * self::TEXT_RATIO);
        $textPx = max(self::MIN_TEXT_PX, min(self::MAX_TEXT_PX, $textPx));
        /** Shrink the text until the line fits across the photo. */
        while (true) {
            $points = $textPx * 0.75;
            $width = $this->textWidth($points, $font, $line);
            if ($textPx <= 6 || $width <= $imageWidth - 2 * $margin) {
                break;
            }
            $textPx--;
        }
        $x = $imageWidth - $margin - $width;
        $baseline = $margin + $textPx;
        $shadowOffset = max(1, (int) round($textPx / 18));
        imagettftext($image, $points, 0, $x + $shadowOffset, $baseline + $shadowOffset, imagecolorallocatealpha($image, 0, 0, 0, 85), $font, $line);
        imagettftext($image, $points, 0, $x, $baseline, imagecolorallocate($image, ...self::COLOUR), $font, $line);
    }

    private function textWidth(float $points, string $font, string $text): int
    {
        $box = imagettfbbox($points, 0, $font, $text);

        return $box === false ? 0 : (int) ceil(abs($box[2] - $box[0]));
    }

    /** Fallback when the vector font is unavailable: GD's largest bitmap font, never shrunk below its natural size. */
    private function drawBitmapStamp(GdImage $image, string $line): void
    {
        $font = 5;
        $width = strlen($line) * imagefontwidth($font) + 2;
        $height = imagefontheight($font) + 2;
        $stamp = imagecreatetruecolor($width, $height);
        try {
            imagealphablending($stamp, false);
            imagesavealpha($stamp, true);
            imagefill($stamp, 0, 0, imagecolorallocatealpha($stamp, 0, 0, 0, 127));
            imagealphablending($stamp, true);
            imagestring($stamp, $font, 1, 1, $line, imagecolorallocate($stamp, ...self::COLOUR));
            $padding = max(1, (int) round(min(imagesx($image), imagesy($image)) * 0.025));
            $scale = min((imagesx($image) - 2 * $padding) / $width, (imagesy($image) - 2 * $padding) / $height, max(1, imagesx($image) / 1400));
            $targetWidth = max(1, (int) floor($width * $scale));
            $targetHeight = max(1, (int) floor($height * $scale));
            imagecopyresampled($image, $stamp, imagesx($image) - $padding - $targetWidth, $padding, 0, 0, $targetWidth, $targetHeight, $width, $height);
        } finally {
            imagedestroy($stamp);
        }
    }
}
