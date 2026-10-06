<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Security\Tokens;

/**
 * Media library. Images are decoded and re-encoded with GD (stripping EXIF/GPS metadata and any
 * embedded payloads), then published as responsive AVIF/WebP/JPEG variants plus a tiny blurred
 * placeholder. Non-image files are stored as-is after MIME sniffing.
 */
final class MediaService
{
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];
    public const VIDEO_MIMES = ['video/mp4', 'video/webm'];
    public const WIDTHS = [480, 768, 1200, 1800, 2400];
    private const MAX_IMAGE_BYTES = 20 * 1024 * 1024;
    private const MAX_VIDEO_BYTES = 250 * 1024 * 1024;

    public function __construct(private Database $db, private Clock $clock, private Config $config)
    {
    }

    /**
     * @param array{alt?: string, caption?: ?string, category?: string, disk?: string} $meta
     * @return array<string, mixed> presented media
     */
    public function storeFromPath(string $sourcePath, string $originalName, array $meta, ?int $userId = null): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath) ?: 'application/octet-stream';
        $size = (int) filesize($sourcePath);
        $disk = ($meta['disk'] ?? 'public') === 'private' ? 'private' : 'public';
        $uuid = Tokens::uuid();
        $base = gmdate('Y/m') . '/' . $uuid;
        $dir = $this->root($disk) . '/' . $base;

        if (in_array($mime, self::IMAGE_MIMES, true)) {
            if ($size > self::MAX_IMAGE_BYTES) {
                throw HttpException::validation(['file' => ['Images must be 20 MB or smaller.']]);
            }
            $processed = $this->processImage($sourcePath, $mime, $dir);
            $path = $base . '/original.jpg';
            $storedMime = 'image/jpeg';
            $width = $processed['width'];
            $height = $processed['height'];
            $variants = ['base' => $base, 'widths' => $processed['widths'], 'formats' => ['avif', 'webp', 'jpg'], 'lqip' => $processed['lqip']];
            $size = (int) filesize($dir . '/original.jpg');
        } elseif (in_array($mime, self::VIDEO_MIMES, true)) {
            if ($size > self::MAX_VIDEO_BYTES) {
                throw HttpException::validation(['file' => ['Videos must be 250 MB or smaller.']]);
            }
            $ext = $mime === 'video/webm' ? 'webm' : 'mp4';
            $this->ensureDir($dir);
            if (!copy($sourcePath, $dir . '/video.' . $ext)) {
                throw new \RuntimeException('Could not store the uploaded file.');
            }
            $path = $base . '/video.' . $ext;
            $storedMime = $mime;
            $width = $height = null;
            $variants = null;
        } else {
            throw HttpException::validation(['file' => ['Upload a JPEG, PNG, WebP or AVIF image, or an MP4/WebM video.']]);
        }

        $id = $this->db->insert('media', [
            'uuid' => $uuid,
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr(basename($originalName), 0, 255),
            'mime' => $storedMime,
            'size_bytes' => $size,
            'width' => $width,
            'height' => $height,
            'alt' => mb_substr(trim((string) ($meta['alt'] ?? '')), 0, 255),
            'caption' => isset($meta['caption']) ? mb_substr((string) $meta['caption'], 0, 500) : null,
            'category' => preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($meta['category'] ?? 'general'))) ?: 'general',
            'variants' => $variants,
            'created_by' => $userId,
            'created_at' => $this->clock->nowString(),
            'updated_at' => $this->clock->nowString(),
        ]);

        return $this->present($this->find($id));
    }

    /** @param array<string, mixed> $upload a $_FILES entry */
    public function storeUpload(array $upload, array $meta, ?int $userId): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $upload['tmp_name'])) {
            throw HttpException::validation(['file' => ['The upload did not complete. Please try again.']]);
        }

        return $this->storeFromPath((string) $upload['tmp_name'], (string) $upload['name'], $meta, $userId);
    }

    /** Replaces the file behind a media item, keeping its id (and therefore all references). */
    public function replace(int $id, array $upload, ?int $userId): array
    {
        $existing = $this->find($id);
        $new = $this->storeUpload($upload, ['alt' => $existing['alt'], 'caption' => $existing['caption'], 'category' => $existing['category'], 'disk' => $existing['disk']], $userId);
        $newRow = $this->find((int) $new['id']);
        $this->db->transaction(function () use ($id, $newRow) {
            $this->db->delete('media', ['id' => $newRow['id']]);
            $this->db->update('media', [
                'uuid' => $newRow['uuid'],
                'path' => $newRow['path'],
                'mime' => $newRow['mime'],
                'size_bytes' => $newRow['size_bytes'],
                'width' => $newRow['width'],
                'height' => $newRow['height'],
                'variants' => $newRow['variants'],
                'original_name' => $newRow['original_name'],
                'updated_at' => $this->clock->nowString(),
            ], ['id' => $id]);
        });
        $this->deleteFiles($existing);

        return $this->present($this->find($id));
    }

    public function delete(int $id): void
    {
        $row = $this->find($id);
        $this->db->delete('media', ['id' => $id]);
        $this->deleteFiles($row);
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        return $this->db->first('SELECT * FROM media WHERE id = ?', [$id]) ?? throw HttpException::notFound('Media not found.');
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function presentMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->db->all("SELECT * FROM media WHERE id IN ({$placeholders})", $ids) as $row) {
            $out[(int) $row['id']] = $this->present($row);
        }

        return $out;
    }

    /** @return array<string, mixed> public representation; never exposes private storage paths */
    public function present(array $row): array
    {
        $variants = is_string($row['variants'] ?? null) ? json_decode((string) $row['variants'], true) : ($row['variants'] ?? null);
        $out = [
            'id' => (int) $row['id'],
            'alt' => (string) $row['alt'],
            'caption' => $row['caption'],
            'category' => $row['category'],
            'mime' => $row['mime'],
            'width' => $row['width'] !== null ? (int) $row['width'] : null,
            'height' => $row['height'] !== null ? (int) $row['height'] : null,
            'focal_point' => $row['focal_point'] ?? null,
        ];
        if ($row['disk'] !== 'public') {
            return $out + ['private' => true];
        }
        $baseUrl = (string) $this->config->get('app.public_media_url');
        if (is_array($variants)) {
            $sources = [];
            foreach ($variants['formats'] as $format) {
                $sources[$format] = implode(', ', array_map(static fn (int $w) => "{$baseUrl}/{$variants['base']}/{$w}.{$format} {$w}w", $variants['widths']));
            }
            $largest = max($variants['widths']);
            $out += [
                'url' => "{$baseUrl}/{$variants['base']}/{$largest}.jpg",
                'srcset' => $sources,
                'placeholder' => $variants['lqip'] ?? null,
            ];
        } else {
            $out['url'] = $baseUrl . '/' . $row['path'];
        }

        return $out;
    }

    /** @return array{width: int, height: int, widths: list<int>, lqip: string} */
    private function processImage(string $source, string $mime, string $dir): array
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($source),
            'image/png' => @imagecreatefrompng($source),
            'image/webp' => @imagecreatefromwebp($source),
            'image/avif' => @imagecreatefromavif($source),
            default => false,
        };
        if (!$image instanceof \GdImage) {
            throw HttpException::validation(['file' => ['The image could not be read.']]);
        }

        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($source);
            $image = match ((int) ($exif['Orientation'] ?? 1)) {
                3 => imagerotate($image, 180, 0),
                6 => imagerotate($image, -90, 0),
                8 => imagerotate($image, 90, 0),
                default => $image,
            };
        }

        // Flatten transparency onto the brand background so JPEG variants match.
        $width = imagesx($image);
        $height = imagesy($image);
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 11, 18, 32));
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
        $image = $canvas;

        $this->ensureDir($dir);
        imagejpeg($image, $dir . '/original.jpg', 88);

        $widths = array_values(array_filter(self::WIDTHS, static fn (int $w) => $w <= $width));
        if ($widths === []) {
            $widths = [$width];
        }
        foreach ($widths as $w) {
            $h = (int) round($height * $w / $width);
            $resized = imagecreatetruecolor($w, $h);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $w, $h, $width, $height);
            imageinterlace($resized, true);
            imagejpeg($resized, "{$dir}/{$w}.jpg", 80);
            imagewebp($resized, "{$dir}/{$w}.webp", 78);
            imageavif($resized, "{$dir}/{$w}.avif", 52, 6);
        }

        $lw = 24;
        $lh = max(1, (int) round($height * $lw / $width));
        $tiny = imagecreatetruecolor($lw, $lh);
        imagecopyresampled($tiny, $image, 0, 0, 0, 0, $lw, $lh, $width, $height);
        ob_start();
        imagejpeg($tiny, null, 50);
        $lqip = 'data:image/jpeg;base64,' . base64_encode((string) ob_get_clean());

        return ['width' => $width, 'height' => $height, 'widths' => $widths, 'lqip' => $lqip];
    }

    private function deleteFiles(array $row): void
    {
        $dir = $this->root((string) $row['disk']) . '/' . dirname((string) $row['path']);
        $root = realpath($this->root((string) $row['disk']));
        $real = realpath($dir);
        if ($root === false || $real === false || !str_starts_with($real, $root) || $real === $root) {
            return;
        }
        foreach (glob($real . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($real);
    }

    private function root(string $disk): string
    {
        return $this->config->get('app.storage_path') . '/' . ($disk === 'private' ? 'private/media' : 'public');
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create the media directory.');
        }
    }
}
