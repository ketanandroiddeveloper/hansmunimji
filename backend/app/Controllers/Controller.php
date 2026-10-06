<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

abstract class Controller
{
    /** @param array<string, mixed>|null $meta */
    protected function ok(mixed $data, int $status = 200, ?array $meta = null): Response
    {
        return Response::json($data, $status, $meta);
    }

    /** Short public caching for anonymous, published content. */
    protected function cached(mixed $data, int $seconds = 300, ?array $meta = null): Response
    {
        return Response::json($data, 200, $meta)->header('Cache-Control', "public, max-age={$seconds}, stale-while-revalidate=600");
    }

    /** @return array{0: int, 1: int} page, per_page */
    protected function pagination(Request $request, int $default = 20): array
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', $default)));

        return [$page, $perPage];
    }

    /** @return array{page: int, per_page: int, total: int, total_pages: int} */
    protected function meta(int $page, int $perPage, int $total): array
    {
        return ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int) ceil($total / max(1, $perPage))];
    }

    protected function userId(Request $request): int
    {
        return (int) $request->attribute('user_id', 0);
    }

    protected function accessToken(Request $request): string
    {
        return (string) $request->header('x-access-token', '');
    }
}
