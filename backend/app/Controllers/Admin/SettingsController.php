<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Jobs\JobQueue;
use App\Security\AuditLogger;
use App\Services\SettingsService;

/**
 * Ordinary, non-secret settings only. Credentials are environment-managed and never accepted here
 * (SettingsService rejects secret-like keys).
 */
final class SettingsController extends Controller
{
    public function __construct(private SettingsService $settings, private AuditLogger $audit, private JobQueue $jobs)
    {
    }

    public function index(Request $request): Response
    {
        return $this->ok($this->settings->all());
    }

    public function update(Request $request): Response
    {
        $input = $request->json();
        $items = $input['settings'] ?? null;
        if (!is_array($items) || $items === []) {
            throw HttpException::validation(['settings' => ['Provide a list of {key, value, is_public} items.']]);
        }
        $keys = [];
        foreach ($items as $i => $item) {
            $key = is_array($item) ? ($item['key'] ?? null) : null;
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $key)) {
                throw HttpException::validation(["settings.{$i}.key" => ['Use a dotted lowercase key such as contact.email.']]);
            }
            if (strlen(json_encode($item['value'] ?? null)) > 20000) {
                throw HttpException::validation(["settings.{$i}.value" => ['This value is too large.']]);
            }
            try {
                $this->settings->set($key, $item['value'] ?? null, isset($item['is_public']) ? (bool) $item['is_public'] : null, $this->userId($request));
            } catch (\InvalidArgumentException $e) {
                throw HttpException::validation(["settings.{$i}.key" => [$e->getMessage()]]);
            }
            $keys[] = $key;
        }
        $this->audit->record($this->userId($request), 'settings.updated', 'settings', null, ['keys' => $keys], $request);
        $this->jobs->push('frontend.rebuild', [], 'frontend.rebuild', 60, 3);

        return $this->ok($this->settings->all());
    }
}
