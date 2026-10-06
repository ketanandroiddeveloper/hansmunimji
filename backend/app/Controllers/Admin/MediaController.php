<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Clock;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Jobs\JobQueue;
use App\Security\AuditLogger;
use App\Services\Media\MediaService;

final class MediaController extends Controller
{
    public function __construct(private Database $db, private Clock $clock, private MediaService $media, private AuditLogger $audit, private JobQueue $jobs)
    {
    }

    public function index(Request $request): Response
    {
        [$page, $perPage] = $this->pagination($request, 40);
        $where = ['1 = 1'];
        $params = [];
        if ($category = $request->query('category')) {
            $where[] = 'category = ?';
            $params[] = (string) $category;
        }
        if ($type = $request->query('type')) {
            $where[] = $type === 'video' ? "mime LIKE 'video/%'" : "mime LIKE 'image/%'";
        }
        if ($q = trim((string) $request->query('q', ''))) {
            $where[] = '(alt LIKE ? OR original_name LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like);
        }
        $sql = ' FROM media WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->value('SELECT COUNT(*)' . $sql, $params);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->all("SELECT *{$sql} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}", $params);

        return $this->ok(array_map(fn ($r) => $this->media->present($r), $rows), 200, $this->meta($page, $perPage, $total) + [
            'categories' => array_column($this->db->all('SELECT DISTINCT category FROM media ORDER BY category'), 'category'),
        ]);
    }

    public function show(Request $request): Response
    {
        return $this->ok($this->media->present($this->media->find($request->intParam('id'))));
    }

    public function store(Request $request): Response
    {
        $meta = Validator::validate($request->all(), [
            'alt' => 'required|string|max:255',
            'caption' => 'nullable|string|max:500',
            'category' => 'nullable|slug|max:60',
        ]);
        $item = $this->media->storeUpload($request->file('file') ?? [], $meta, $this->userId($request));
        $this->audit->record($this->userId($request), 'media.uploaded', 'media', $item['id'], [], $request);

        return $this->ok($item, 201);
    }

    public function update(Request $request): Response
    {
        $id = $request->intParam('id');
        $this->media->find($id);
        $data = Validator::validate($request->json(), [
            'alt' => 'sometimes|required|string|max:255',
            'caption' => 'sometimes|nullable|string|max:500',
            'category' => 'sometimes|required|slug|max:60',
            'focal_point' => 'sometimes|nullable|string|max:20',
        ]);
        if (isset($data['focal_point']) && !preg_match('/^\d{1,3}% \d{1,3}%$/', (string) $data['focal_point'])) {
            throw \App\Core\HttpException::validation(['focal_point' => ['Use the form "50% 30%".']]);
        }
        $this->db->update('media', $data + ['updated_at' => $this->clock->nowString()], ['id' => $id]);
        $this->audit->record($this->userId($request), 'media.updated', 'media', $id, ['fields' => array_keys($data)], $request);
        $this->jobs->push('frontend.rebuild', [], 'frontend.rebuild', 60, 3);

        return $this->ok($this->media->present($this->media->find($id)));
    }

    public function replace(Request $request): Response
    {
        $id = $request->intParam('id');
        $item = $this->media->replace($id, $request->file('file') ?? [], $this->userId($request));
        $this->audit->record($this->userId($request), 'media.replaced', 'media', $id, [], $request);
        $this->jobs->push('frontend.rebuild', [], 'frontend.rebuild', 60, 3);

        return $this->ok($item);
    }

    public function destroy(Request $request): Response
    {
        $id = $request->intParam('id');
        $this->media->delete($id);
        $this->audit->record($this->userId($request), 'media.deleted', 'media', $id, [], $request);
        $this->jobs->push('frontend.rebuild', [], 'frontend.rebuild', 60, 3);

        return Response::noContent();
    }
}
