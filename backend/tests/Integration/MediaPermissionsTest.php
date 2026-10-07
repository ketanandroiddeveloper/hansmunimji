<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Services\Media\MediaService;

final class MediaPermissionsTest extends IntegrationTestCase
{
    public function testPublicImagesStayReadableByTheWebServerUnderARestrictiveUmask(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'img');
        $image = imagecreatetruecolor(800, 600);
        imagepng($image, $source);

        $previous = umask(077);
        try {
            $media = $this->c->get(MediaService::class)->storeFromPath($source, 'hero.png', ['alt' => 'Hero']);
        } finally {
            umask($previous);
            @unlink($source);
        }

        $root = $this->c->get(Config::class)->get('app.storage_path') . '/public';
        $dir = $root . '/' . dirname((string) $this->db->value('SELECT path FROM media WHERE id = ?', [$media['id']]));
        for ($d = $dir; $d !== $root; $d = dirname($d)) {
            self::assertSame('755', decoct(fileperms($d) & 0777), "directory {$d}");
        }
        $files = glob($dir . '/*') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            self::assertSame('644', decoct(fileperms($file) & 0777), basename($file));
        }
    }
}
