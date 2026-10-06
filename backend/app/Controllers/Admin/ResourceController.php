<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Clock;
use App\Core\Container;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Jobs\JobQueue;
use App\Security\AuditLogger;
use App\Security\HtmlSanitizer;

/**
 * Definition-driven admin CRUD. Subclasses declare the table, validation rules, which fields are
 * rich text (sanitised) or JSON, and which columns are searchable/sortable/filterable.
 * Only declared fields are ever written (mass-assignment safe); identifiers come from code only.
 */
abstract class ResourceController extends Controller
{
    protected Database $db;
    protected Clock $clock;
    protected HtmlSanitizer $sanitizer;
    protected AuditLogger $audit;
    protected JobQueue $jobs;

    public function __construct(Container $container)
    {
        $this->db = $container->get(Database::class);
        $this->clock = $container->get(Clock::class);
        $this->sanitizer = $container->get(HtmlSanitizer::class);
        $this->audit = $container->get(AuditLogger::class);
        $this->jobs = $container->get(JobQueue::class);
        $this->boot($container);
    }

    abstract protected function table(): string;

    /** @return array<string, string> validation rules for create */
    abstract protected function rules(): array;

    /** Optional extra dependencies. */
    protected function boot(Container $container): void
    {
    }

    /** @return list<string> */
    protected function htmlFields(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function jsonFields(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function searchable(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function sortable(): array
    {
        return ['id'];
    }

    /** @return list<string> exact-match filters accepted from the query string */
    protected function filterable(): array
    {
        return [];
    }

    protected function defaultSort(): string
    {
        return '-id';
    }

    protected function hasTimestamps(): bool
    {
        return true;
    }

    /** Whether saving this resource affects public, prerendered pages. */
    protected function affectsPublicSite(): bool
    {
        return true;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    protected function present(array $row): array
    {
        foreach ($this->jsonFields() as $field) {
            if (isset($row[$field]) && is_string($row[$field])) {
                $row[$field] = json_decode($row[$field], true);
            }
        }

        return $row;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    protected function beforeSave(array $data, ?array $existing): array
    {
        return $data;
    }

    /** @param array<string, mixed> $input */
    protected function afterSave(int $id, array $input, ?array $existing): void
    {
    }

    public function index(Request $request): Response
    {
        [$page, $perPage] = $this->pagination($request, 25);
        $where = [];
        $params = [];
        $q = trim((string) $request->query('q', ''));
        if ($q !== '' && $this->searchable() !== []) {
            $where[] = '(' . implode(' OR ', array_map(static fn ($c) => "`{$c}` LIKE ?", $this->searchable())) . ')';
            foreach ($this->searchable() as $_) {
                $params[] = '%' . addcslashes($q, '%_\\') . '%';
            }
        }
        foreach ($this->filterable() as $column) {
            $value = $request->query($column);
            if ($value !== null && $value !== '') {
                $where[] = "`{$column}` = ?";
                $params[] = (string) $value;
            }
        }
        $sort = (string) $request->query('sort', $this->defaultSort());
        $direction = str_starts_with($sort, '-') ? 'DESC' : 'ASC';
        $column = ltrim($sort, '-');
        if (!in_array($column, $this->sortable(), true)) {
            $column = ltrim($this->defaultSort(), '-');
            $direction = str_starts_with($this->defaultSort(), '-') ? 'DESC' : 'ASC';
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM `{$this->table()}`{$whereSql}", $params);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->all("SELECT * FROM `{$this->table()}`{$whereSql} ORDER BY `{$column}` {$direction} LIMIT {$perPage} OFFSET {$offset}", $params);

        return $this->ok(array_map(fn ($r) => $this->present($r), $rows), 200, $this->meta($page, $perPage, $total));
    }

    public function show(Request $request): Response
    {
        return $this->ok($this->present($this->findOrFail($request->intParam('id'))));
    }

    public function store(Request $request): Response
    {
        $input = $request->all();
        $data = $this->prepare(Validator::validate($input, $this->rules()), null, $input);
        if ($this->hasTimestamps()) {
            $data['created_at'] = $data['updated_at'] = $this->clock->nowString();
        }
        $id = $this->guardIntegrity(fn () => $this->db->transaction(function () use ($data, $input) {
            $id = $this->db->insert($this->table(), $data);
            $this->afterSave($id, $input, null);

            return $id;
        }));
        $this->audit->record($this->userId($request), $this->table() . '.created', $this->table(), $id, [], $request);
        $this->touchPublicSite();

        return $this->ok($this->present($this->findOrFail($id)), 201);
    }

    public function update(Request $request): Response
    {
        $id = $request->intParam('id');
        $existing = $this->findOrFail($id);
        $input = $request->all();
        $rules = array_map(static fn ($r) => 'sometimes|' . $r, $this->rules());
        $data = $this->prepare(Validator::validate($input, $rules), $existing, $input);
        if ($this->hasTimestamps()) {
            $data['updated_at'] = $this->clock->nowString();
        }
        $this->guardIntegrity(fn () => $this->db->transaction(function () use ($id, $data, $input, $existing) {
            if ($data !== []) {
                $this->db->update($this->table(), $data, ['id' => $id]);
            }
            $this->afterSave($id, $input, $existing);
        }));
        $this->audit->record($this->userId($request), $this->table() . '.updated', $this->table(), $id, ['fields' => array_keys($data)], $request);
        $this->touchPublicSite();

        return $this->ok($this->present($this->findOrFail($id)));
    }

    public function destroy(Request $request): Response
    {
        $id = $request->intParam('id');
        $this->findOrFail($id);
        try {
            $this->db->delete($this->table(), ['id' => $id]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw HttpException::conflict('in_use', 'This item is referenced elsewhere. Unpublish or archive it instead.');
            }
            throw $e;
        }
        $this->audit->record($this->userId($request), $this->table() . '.deleted', $this->table(), $id, [], $request);
        $this->touchPublicSite();

        return Response::noContent();
    }

    /** @return array<string, mixed> */
    protected function findOrFail(int $id): array
    {
        return $this->db->first("SELECT * FROM `{$this->table()}` WHERE id = ?", [$id]) ?? throw HttpException::notFound();
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function prepare(array $data, ?array $existing, array $input): array
    {
        // Only write fields the client actually sent; on create, omitted/null values fall back to column defaults.
        $data = array_filter(
            $data,
            static fn ($value, $key) => array_key_exists($key, $input) && ($existing !== null || $value !== null),
            ARRAY_FILTER_USE_BOTH,
        );
        foreach ($this->htmlFields() as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $this->sanitizer->clean($data[$field] === null ? null : (string) $data[$field]);
            }
        }
        foreach ($this->jsonFields() as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && !is_array($data[$field])) {
                throw HttpException::validation([$field => ['Must be a list or object.']]);
            }
        }
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $data[$key] = $value ? 1 : 0;
            }
        }
        if (isset($data['slug'])) {
            $clash = $this->db->value("SELECT id FROM `{$this->table()}` WHERE slug = ? AND id <> ?", [$data['slug'], $existing['id'] ?? 0]);
            if ($clash) {
                throw HttpException::validation(['slug' => ['This slug is already in use.']]);
            }
        }

        return $this->beforeSave($data, $existing);
    }

    /** Maps database integrity errors caused by client input to 422 responses. */
    private function guardIntegrity(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (\PDOException $e) {
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            $message = (string) ($e->errorInfo[2] ?? '');
            if ($driverCode === 1048 && preg_match("/Column '([a-z_]+)'/", $message, $m)) {
                throw HttpException::validation([$m[1] => ['This field cannot be empty.']]);
            }
            if ($driverCode === 1452) {
                throw HttpException::validation(['_' => ['A referenced item (such as an image, service or city) no longer exists.']]);
            }
            if ($driverCode === 1062) {
                throw HttpException::validation(['_' => ['An item with these details already exists.']]);
            }
            throw $e;
        }
    }

    private function touchPublicSite(): void
    {
        if ($this->affectsPublicSite()) {
            // Debounced: one pending rebuild at a time, delayed to batch consecutive edits.
            $this->jobs->push('frontend.rebuild', [], 'frontend.rebuild', 60, 3);
        }
    }
}
