<?php

namespace App\Domain\Evidence;

use App\Domain\Audit\SecurityEvents;
use App\Models\Photo;
use App\Models\User;
use App\Models\Visit;
use App\Support\DomainRuleException;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Evidence upload pipeline: verify real MIME -> decode -> fix orientation -> downscale ->
 * RE-ENCODE as JPEG (strips EXIF and any embedded payload) -> store under a random key.
 */
class EvidenceStorage
{
    private const MAX_PHOTOS_PER_VISIT = 80;

    public function storePhoto(UploadedFile $file, Visit $visit, User $user, array $meta = []): Photo
    {
        if (!empty($meta['client_uuid'])) {
            $dup = Photo::query()->where('taken_by', $user->getKey())->where('client_uuid', $meta['client_uuid'])->first();
            if ($dup) {
                return $dup;   // idempotent replay
            }
        }
        if (Photo::query()->where('visit_id', $visit->id)->count() >= self::MAX_PHOTOS_PER_VISIT) {
            throw new DomainRuleException('The maximum number of photos for this visit has been reached.', 'photo_limit');
        }

        $mime = $file->getMimeType();
        if (!in_array($mime, config('field.uploads.image_mimes'), true)) {
            throw new DomainRuleException('Only JPEG, PNG or WebP images are accepted.', 'invalid_image');
        }
        $raw = @file_get_contents($file->getRealPath());
        $image = $raw === false ? false : @imagecreatefromstring($raw);
        if ($image === false) {
            throw new DomainRuleException('The file is not a valid image.', 'invalid_image');
        }

        $image = $this->fixOrientation($image, $raw, $mime);
        $image = $this->downscale($image, (int) config('field.uploads.max_dimension'));
        $width = imagesx($image);
        $height = imagesy($image);

        ob_start();
        imagejpeg($image, null, (int) config('field.uploads.jpeg_quality'));
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $sha = hash('sha256', $bytes);
        $disk = config('field.uploads.disk');
        $path = 'visits/'.$visit->id.'/'.Str::uuid().'.jpg';
        Storage::disk($disk)->put($path, $bytes);

        // Re-using a photo already submitted on another visit is a classic fraud pattern.
        if (Photo::query()->where('sha256', $sha)->where('visit_id', '!=', $visit->id)->exists()) {
            SecurityEvents::log('photo_reuse_detected', $user->getKey(), 'warning', ['visit_id' => $visit->id, 'sha256' => $sha]);
        }

        return Photo::query()->create([
            'client_uuid' => $meta['client_uuid'] ?? null, 'visit_id' => $visit->id, 'question_id' => $meta['question_id'] ?? null,
            'taken_by' => $user->getKey(), 'kind' => $meta['kind'] ?? 'evidence', 'disk' => $disk, 'path' => $path,
            'original_name' => mb_substr(preg_replace('/[^\w.\- ]/u', '_', (string) $file->getClientOriginalName()), 0, 120),
            'mime' => 'image/jpeg', 'size_bytes' => strlen($bytes), 'width' => $width, 'height' => $height, 'sha256' => $sha,
            'captured_at' => isset($meta['captured_at']) ? Carbon::parse($meta['captured_at']) : now(),
            'latitude' => $meta['latitude'] ?? null, 'longitude' => $meta['longitude'] ?? null,
        ]);
    }

    /** @return array{path:string, mime:string, size:int} */
    public function storeDocument(UploadedFile $file, string $folder): array
    {
        $map = config('field.uploads.document_mimes');
        $mime = $file->getMimeType();
        if (!isset($map[$mime])) {
            throw new DomainRuleException('Only PDF, JPEG or PNG documents are accepted.', 'invalid_document');
        }
        $path = $folder.'/'.Str::uuid().'.'.$map[$mime];
        Storage::disk(config('field.uploads.disk'))->put($path, file_get_contents($file->getRealPath()));

        return ['path' => $path, 'mime' => $mime, 'size' => $file->getSize()];
    }

    private function fixOrientation(\GdImage $image, string $raw, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($raw));
        $angle = match ($exif['Orientation'] ?? 1) {
            3 => 180, 6 => -90, 8 => 90, default => 0,
        };
        if ($angle !== 0 && ($rotated = imagerotate($image, $angle, 0)) !== false) {
            imagedestroy($image);
            return $rotated;
        }
        return $image;
    }

    private function downscale(\GdImage $image, int $max): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        if (max($w, $h) <= $max) {
            return $image;
        }
        $ratio = $max / max($w, $h);
        $scaled = imagescale($image, (int) round($w * $ratio), (int) round($h * $ratio));
        if ($scaled === false) {
            return $image;
        }
        imagedestroy($image);
        return $scaled;
    }
}
