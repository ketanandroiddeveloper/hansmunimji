<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Route table with prefix/middleware groups.
 *
 * Handlers are `[ControllerClass::class, 'method']`. Middleware are alias strings with optional
 * colon-separated arguments, e.g. `auth`, `csrf`, `can:applications.view`, `throttle:login,5,900`.
 */
final class Router
{
    /** @var list<array{method: string, pattern: string, regex: string, handler: array{0: class-string, 1: string}, middleware: list<string>}> */
    private array $routes = [];

    /** @var list<array{prefix: string, middleware: list<string>}> */
    private array $groupStack = [];

    /** @param list<string> $middleware */
    public function group(string $prefix, array $middleware, callable $routes): void
    {
        $this->groupStack[] = ['prefix' => $prefix, 'middleware' => $middleware];
        $routes($this);
        array_pop($this->groupStack);
    }

    /** @param array{0: class-string, 1: string} $handler @param list<string> $middleware */
    public function get(string $path, array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    /** @param array{0: class-string, 1: string} $handler @param list<string> $middleware */
    public function post(string $path, array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    /** @param array{0: class-string, 1: string} $handler @param list<string> $middleware */
    public function put(string $path, array $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    /** @param array{0: class-string, 1: string} $handler @param list<string> $middleware */
    public function patch(string $path, array $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    /** @param array{0: class-string, 1: string} $handler @param list<string> $middleware */
    public function delete(string $path, array $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /**
     * Registers standard admin CRUD routes for a resource controller.
     *
     * @param class-string $controller
     */
    public function resource(string $path, string $controller, string $permission): void
    {
        $can = ["can:{$permission}"];
        $this->get($path, [$controller, 'index'], $can);
        $this->post($path, [$controller, 'store'], $can);
        $this->get("{$path}/{id}", [$controller, 'show'], $can);
        $this->put("{$path}/{id}", [$controller, 'update'], $can);
        $this->patch("{$path}/{id}", [$controller, 'update'], $can);
        $this->delete("{$path}/{id}", [$controller, 'destroy'], $can);
    }

    /** @param array{0: class-string, 1: string} $handler @param list<string> $middleware */
    private function add(string $method, string $path, array $handler, array $middleware): void
    {
        $prefix = '';
        $groupMiddleware = [];
        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'];
            $groupMiddleware = [...$groupMiddleware, ...$group['middleware']];
        }

        $pattern = '/' . trim($prefix . '/' . trim($path, '/'), '/');
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => $regex,
            'handler' => $handler,
            'middleware' => [...$groupMiddleware, ...$middleware],
        ];
    }

    /**
     * @return array{handler: array{0: class-string, 1: string}, middleware: list<string>, params: array<string, string>, pattern: string}
     */
    public function match(string $method, string $path): array
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }

            return [
                'handler' => $route['handler'],
                'middleware' => $route['middleware'],
                'params' => array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY),
                'pattern' => $route['pattern'],
            ];
        }

        if ($allowed !== []) {
            throw new HttpException(405, 'method_not_allowed', 'Method not allowed.', [], ['Allow' => implode(', ', array_unique($allowed))]);
        }

        throw HttpException::notFound('Endpoint not found.');
    }

    /** @return list<array{method: string, pattern: string}> */
    public function list(): array
    {
        return array_map(static fn (array $r) => ['method' => $r['method'], 'pattern' => $r['pattern']], $this->routes);
    }
}
