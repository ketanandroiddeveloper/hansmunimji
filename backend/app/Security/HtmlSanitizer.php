<?php

declare(strict_types=1);

namespace App\Security;

use HTMLPurifier;
use HTMLPurifier_Config;

/** Allowlist sanitiser for admin-authored rich text. */
final class HtmlSanitizer
{
    private ?HTMLPurifier $purifier = null;

    public function __construct(private string $cacheDir)
    {
    }

    public function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        return $this->purifier()->purify($html);
    }

    private function purifier(): HTMLPurifier
    {
        if ($this->purifier !== null) {
            return $this->purifier;
        }
        $config = HTMLPurifier_Config::createDefault();
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0750, true);
        }
        $config->set('Cache.SerializerPath', $this->cacheDir);
        $config->set('HTML.Allowed', 'p,br,strong,em,u,s,blockquote,h2,h3,h4,ul,ol,li,a[href|title|rel|target],hr,figure,figcaption,img[src|alt|width|height|loading]');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.Nofollow', false);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('AutoFormat.RemoveEmpty', true);

        return $this->purifier = new HTMLPurifier($config);
    }
}
