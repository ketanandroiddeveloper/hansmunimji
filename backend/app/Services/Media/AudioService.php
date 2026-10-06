<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Security\SignedUrl;
use App\Security\Tokens;

/**
 * Audio library. Files live on the private disk and are only reachable through short-lived
 * HMAC-signed stream URLs. "clients" tracks require a valid appointment or application access token.
 */
final class AudioService
{
    public const MIMES = ['audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/aac' => 'aac', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/flac' => 'flac'];
    private const MAX_BYTES = 300 * 1024 * 1024;
    private const URL_TTL = 600;

    public function __construct(
        private Database $db,
        private Clock $clock,
        private Config $config,
        private SignedUrl $signer,
        private MediaService $media,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function publicList(?string $category): array
    {
        $sql = "SELECT * FROM audio_tracks WHERE status = 'published' AND access <> 'private'";
        $params = [];
        if ($category) {
            $sql .= ' AND category = ?';
            $params[] = $category;
        }
        $rows = $this->db->all($sql . ' ORDER BY is_featured DESC, sort_order, id', $params);
        $covers = $this->media->presentMany(array_column($rows, 'cover_media_id'));

        return array_map(static fn ($r) => [
            'slug' => $r['slug'],
            'title' => $r['title'],
            'description' => $r['description'],
            'category' => $r['category'],
            'duration_seconds' => $r['duration_seconds'] !== null ? (int) $r['duration_seconds'] : null,
            'access' => $r['access'],
            'is_featured' => (bool) $r['is_featured'],
            'available' => $r['file_path'] !== null,
            'cover' => $covers[(int) $r['cover_media_id']] ?? null,
        ], $rows);
    }

    /** @return array{url: string, expires_in: int} */
    public function streamUrl(string $slug, ?string $accessReference, ?string $accessToken): array
    {
        $track = $this->db->first("SELECT * FROM audio_tracks WHERE slug = ? AND status = 'published'", [$slug]) ?? throw HttpException::notFound('Track not found.');
        if ($track['file_path'] === null) {
            throw HttpException::conflict('not_available', 'This recording is being prepared.');
        }
        if ($track['access'] === 'private') {
            throw HttpException::notFound('Track not found.');
        }
        if ($track['access'] === 'clients' && !$this->hasClientAccess($accessReference, $accessToken)) {
            throw HttpException::forbidden('This recording is reserved for clients of the practice.');
        }
        $token = $this->signer->sign(['t' => (int) $track['id']], self::URL_TTL);

        // Relative to the API origin so it works behind proxies and in development.
        return ['url' => '/api/v1/audio/stream/' . $token, 'expires_in' => self::URL_TTL];
    }

    /** Streams the file with HTTP Range support. */
    public function stream(string $token, Request $request): Response
    {
        $claims = $this->signer->verify($token) ?? throw new HttpException(403, 'link_expired', 'This audio link has expired. Please press play again.');
        $track = $this->db->first('SELECT file_path, mime, size_bytes FROM audio_tracks WHERE id = ?', [(int) $claims['t']]) ?? throw HttpException::notFound();
        $root = realpath($this->config->get('app.storage_path') . '/private/audio');
        $file = $root !== false ? realpath($root . '/' . $track['file_path']) : false;
        if ($root === false || $file === false || !str_starts_with($file, $root) || !is_file($file)) {
            throw HttpException::notFound();
        }

        $size = (int) filesize($file);
        $start = 0;
        $end = $size - 1;
        $status = 200;
        $range = $request->header('range');
        if ($range !== null && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m)) {
            if ($m[1] === '' && $m[2] !== '') {
                $start = max(0, $size - (int) $m[2]);
            } else {
                $start = (int) $m[1];
                $end = $m[2] !== '' ? min((int) $m[2], $size - 1) : $end;
            }
            if ($start > $end || $start >= $size) {
                return new Response('', 416, ['Content-Range' => "bytes */{$size}"]);
            }
            $status = 206;
        }
        $length = $end - $start + 1;
        $headers = [
            'Content-Type' => (string) $track['mime'],
            'Content-Length' => (string) $length,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=600',
            'Content-Disposition' => 'inline',
        ];
        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        return Response::stream(static function () use ($file, $start, $length) {
            $handle = fopen($file, 'rb');
            fseek($handle, $start);
            $remaining = $length;
            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, (int) min(65536, $remaining));
                if ($chunk === false) {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
                flush();
            }
            fclose($handle);
        }, $status, $headers);
    }

    /** Stores an uploaded audio file on the private disk. @return array{file_path: string, mime: string, size_bytes: int} */
    public function storeUpload(array $upload): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $upload['tmp_name'])) {
            throw HttpException::validation(['file' => ['The upload did not complete. Please try again.']]);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $upload['tmp_name']) ?: '';
        $ext = self::MIMES[$mime] ?? throw HttpException::validation(['file' => ['Upload an MP3, M4A, AAC, OGG, WAV or FLAC file.']]);
        $size = (int) $upload['size'];
        if ($size > self::MAX_BYTES) {
            throw HttpException::validation(['file' => ['Audio files must be 300 MB or smaller.']]);
        }
        $relative = gmdate('Y/m') . '/' . Tokens::uuid() . '.' . $ext;
        $target = $this->config->get('app.storage_path') . '/private/audio/' . $relative;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0750, true);
        }
        if (!move_uploaded_file((string) $upload['tmp_name'], $target)) {
            throw new \RuntimeException('Could not store the audio file.');
        }

        return ['file_path' => $relative, 'mime' => $mime, 'size_bytes' => $size];
    }

    public function deleteFile(string $relativePath): void
    {
        $root = realpath($this->config->get('app.storage_path') . '/private/audio');
        $path = realpath($root . '/' . $relativePath);
        if ($root !== false && $path !== false && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path)) {
            unlink($path);
        }
    }

    private function hasClientAccess(?string $reference, ?string $token): bool
    {
        if (!$reference || !$token) {
            return false;
        }
        $hash = Tokens::hash($token);
        $appointment = $this->db->value(
            "SELECT 1 FROM appointments WHERE reference = ? AND access_token_hash = ? AND status IN ('confirmed','rescheduled','completed')",
            [$reference, $hash],
        );
        if ($appointment) {
            return true;
        }

        return (bool) $this->db->value(
            "SELECT 1 FROM applications WHERE reference = ? AND access_token_hash = ? AND status IN ('approved','invited') AND access_expires_at > ?",
            [$reference, $hash, $this->clock->nowString()],
        );
    }
}
