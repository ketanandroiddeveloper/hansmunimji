<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Clock;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Jobs\JobQueue;
use App\Security\AuditLogger;
use App\Security\HtmlSanitizer;

/** Edits the primary practitioner profile. Credentials live in `qualifications` (separate resource). */
final class PractitionerController extends Controller
{
    private const RULES = [
        'slug' => 'required|slug|max:120',
        'full_name' => 'required|string|max:190',
        'honorific' => 'nullable|string|max:60',
        'title' => 'nullable|string|max:190',
        'short_bio' => 'nullable|string|max:2000',
        'biography' => 'nullable|string|max:100000',
        'philosophy' => 'nullable|string|max:50000',
        'approach' => 'nullable|string|max:50000',
        'expertise' => 'nullable|array',
        'experience' => 'nullable|array',
        'portrait_media_id' => 'nullable|integer',
        'secondary_media_id' => 'nullable|integer',
        'same_as' => 'nullable|array',
    ];

    public function __construct(private Database $db, private Clock $clock, private HtmlSanitizer $sanitizer, private AuditLogger $audit, private JobQueue $jobs)
    {
    }

    public function show(Request $request): Response
    {
        $row = $this->db->first('SELECT * FROM practitioner_profiles WHERE is_primary = 1 LIMIT 1') ?? throw HttpException::notFound('No practitioner profile has been created yet.');
        foreach (['expertise', 'experience', 'same_as'] as $f) {
            $row[$f] = json_decode((string) $row[$f], true) ?: [];
        }

        return $this->ok($row);
    }

    public function update(Request $request): Response
    {
        $row = $this->db->first('SELECT id FROM practitioner_profiles WHERE is_primary = 1 LIMIT 1');
        $rules = $row === null ? self::RULES : array_map(static fn ($r) => 'sometimes|' . $r, self::RULES);
        $data = Validator::validate($request->json(), $rules);
        foreach (['biography', 'philosophy', 'approach'] as $f) {
            if (array_key_exists($f, $data)) {
                $data[$f] = $this->sanitizer->clean($data[$f]);
            }
        }
        foreach ($data['same_as'] ?? [] as $url) {
            if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) {
                throw HttpException::validation(['same_as' => ['Each profile link must be an https:// URL.']]);
            }
        }
        if (isset($data['expertise'])) {
            $data['expertise'] = array_values(array_filter(array_map(static fn ($v) => is_string($v) ? trim(strip_tags($v)) : null, $data['expertise'])));
        }
        if (isset($data['experience'])) {
            $data['experience'] = array_values(array_filter(array_map(static function ($item) {
                if (!is_array($item)) {
                    return null;
                }
                $clean = static fn (string $key, int $max) => mb_substr(trim(strip_tags((string) ($item[$key] ?? ''))), 0, $max);
                $entry = ['period' => $clean('period', 60), 'title' => $clean('title', 190), 'description' => $clean('description', 2000)];

                return $entry['title'] === '' ? null : $entry;
            }, $data['experience'])));
        }

        $now = $this->clock->nowString();
        if ($row === null) {
            $id = $this->db->insert('practitioner_profiles', $data + ['is_primary' => 1, 'created_at' => $now, 'updated_at' => $now]);
        } else {
            $id = (int) $row['id'];
            $this->db->update('practitioner_profiles', $data + ['updated_at' => $now], ['id' => $id]);
        }
        $this->audit->record($this->userId($request), 'practitioner.updated', 'practitioner_profile', $id, ['fields' => array_keys($data)], $request);
        $this->jobs->push('frontend.rebuild', [], 'frontend.rebuild', 60, 3);

        return $this->show($request);
    }
}
