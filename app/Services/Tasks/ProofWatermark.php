<?php

namespace App\Services\Tasks;

use App\Models\Document;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** M06 / UI23: stamp normalized proof once, before encryption and the integrity hash are recorded. */
class ProofWatermark
{
    public function stamp(string $jpeg, Task $task, Document $document, CarbonInterface $recordedAt): string
    {
        $image = imagecreatefromstring($jpeg);
        if (! $image) {
            throw ValidationException::withMessages(['proof' => 'The proof image could not be prepared.']);
        }
        $lines = [
            'NEXORA | '.Str::ascii($task->source_task_id ?: $task->public_id),
            $document->proofReference(),
            $recordedAt->copy()->timezone(config('dcms.business_timezone'))->format('d/m/Y H:i').' MYT',
        ];
        $font = 5;
        $lineHeight = imagefontheight($font) + 4;
        $width = max(array_map(fn (string $line): int => strlen($line) * imagefontwidth($font), $lines)) + 4;
        $height = count($lines) * $lineHeight + 4;
        $stamp = imagecreatetruecolor($width, $height);
        try {
            imagealphablending($stamp, false);
            imagesavealpha($stamp, true);
            imagefill($stamp, 0, 0, imagecolorallocatealpha($stamp, 0, 0, 0, 127));
            imagealphablending($stamp, true);
            $white = imagecolorallocate($stamp, 255, 255, 255);
            $red = imagecolorallocate($stamp, 255, 0, 0);
            foreach ($lines as $index => $line) {
                $x = $width - strlen($line) * imagefontwidth($font) - 2;
                $y = 2 + $index * $lineHeight;
                foreach ([[-1, -1], [-1, 1], [1, -1], [1, 1]] as [$dx, $dy]) {
                    imagestring($stamp, $font, $x + $dx, $y + $dy, $line, $white);
                }
                imagestring($stamp, $font, $x, $y, $line, $red);
            }
            $padding = max(1, (int) round(min(imagesx($image), imagesy($image)) * 0.02));
            $scale = min((imagesx($image) - 2 * $padding) / $width, (imagesy($image) - 2 * $padding) / $height, max(0.1, imagesx($image) / 1400));
            $targetWidth = max(1, (int) floor($width * $scale));
            $targetHeight = max(1, (int) floor($height * $scale));
            imagecopyresampled($image, $stamp, imagesx($image) - $padding - $targetWidth, $padding, 0, 0, $targetWidth, $targetHeight, $width, $height);
            ob_start();
            imagejpeg($image, null, 90);
            $bytes = ob_get_clean();
            if (! is_string($bytes) || strlen($bytes) > config('task_execution.max_image_kib') * 1024) {
                throw ValidationException::withMessages(['proof' => 'Watermarked image exceeds the upload limit. Resize it and retry.']);
            }

            return $bytes;
        } finally {
            imagedestroy($stamp);
            imagedestroy($image);
        }
    }
}
