<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Resources;

use App\Controllers\Admin\ResourceController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Services\Media\AudioService;

final class AudioTracksController extends ResourceController
{
    private AudioService $audio;

    protected function boot(Container $container): void
    {
        $this->audio = $container->get(AudioService::class);
    }

    protected function table(): string
    {
        return 'audio_tracks';
    }

    protected function rules(): array
    {
        return [
            'slug' => 'required|slug|max:160',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'category' => 'required|in:guided_meditation,sonic_healing,vedic_chant,catharsis,sample',
            'duration_seconds' => 'nullable|integer|min:1',
            'cover_media_id' => 'nullable|integer',
            'access' => 'required|in:public,clients,private',
            'is_featured' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
            'status' => 'required|in:draft,published',
        ];
    }

    protected function searchable(): array
    {
        return ['title', 'slug'];
    }

    protected function sortable(): array
    {
        return ['id', 'title', 'sort_order', 'updated_at'];
    }

    protected function filterable(): array
    {
        return ['category', 'access', 'status'];
    }

    protected function defaultSort(): string
    {
        return 'sort_order';
    }

    protected function present(array $row): array
    {
        $row['has_file'] = $row['file_path'] !== null;
        unset($row['file_path']);

        return $row;
    }

    /** POST /admin/audio/{id}/file (multipart, field "file") */
    public function upload(Request $request): Response
    {
        $id = $request->intParam('id');
        $existing = $this->findOrFail($id);
        $stored = $this->audio->storeUpload($request->file('file') ?? []);
        $this->db->update('audio_tracks', $stored + ['updated_at' => $this->clock->nowString()], ['id' => $id]);
        if ($existing['file_path']) {
            $this->audio->deleteFile((string) $existing['file_path']);
        }
        $this->audit->record($this->userId($request), 'audio_tracks.file_uploaded', 'audio_tracks', $id, ['mime' => $stored['mime'], 'size' => $stored['size_bytes']], $request);

        return $this->ok($this->present($this->findOrFail($id)));
    }

    public function destroy(Request $request): Response
    {
        $existing = $this->findOrFail($request->intParam('id'));
        $response = parent::destroy($request);
        if ($existing['file_path']) {
            $this->audio->deleteFile((string) $existing['file_path']);
        }

        return $response;
    }
}
