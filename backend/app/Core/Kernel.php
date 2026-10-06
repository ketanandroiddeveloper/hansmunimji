<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\Authenticate;
use App\Middleware\Authorize;
use App\Middleware\Cors;
use App\Middleware\Csrf;
use App\Middleware\JsonBody;
use App\Middleware\Middleware;
use App\Middleware\RateLimit;
use App\Middleware\RequestId;
use App\Middleware\RequireTwoFactor;
use App\Middleware\SecurityHeaders;

final class Kernel
{
    /** Global middleware, outermost first. */
    private const GLOBAL = [RequestId::class, SecurityHeaders::class, Cors::class, JsonBody::class];

    /** @var array<string, class-string<Middleware>> */
    private const ALIASES = [
        'auth' => Authenticate::class,
        'csrf' => Csrf::class,
        'can' => Authorize::class,
        'throttle' => RateLimit::class,
        'two_factor' => RequireTwoFactor::class,
    ];

    public function __construct(private Container $container, private Router $router, private ErrorHandler $errors)
    {
    }

    public function handle(Request $request): Response
    {
        $stack = [];
        foreach (self::GLOBAL as $class) {
            $stack[] = [$class, []];
        }

        $core = function (Request $request) use (&$stack): Response {
            $route = $this->router->match($request->method, $request->path);
            $request->withAttribute('route_params', $route['params']);
            $request->withAttribute('route_pattern', $route['pattern']);

            $routeStack = [];
            foreach ($route['middleware'] as $definition) {
                [$alias, $argString] = array_pad(explode(':', $definition, 2), 2, '');
                $class = self::ALIASES[$alias] ?? throw new \RuntimeException("Unknown middleware alias '{$alias}'.");
                $routeStack[] = [$class, $argString === '' ? [] : explode(',', $argString)];
            }

            [$controllerClass, $method] = $route['handler'];
            $destination = function (Request $request) use ($controllerClass, $method): Response {
                $controller = $this->container->get($controllerClass);

                return $controller->{$method}($request);
            };

            return $this->pipeline($routeStack, $destination)($request);
        };

        try {
            return $this->pipeline($stack, $core)($request);
        } catch (\Throwable $e) {
            $response = $this->errors->render($e, $request);
            // Global headers still apply to error responses.
            return $this->container->get(SecurityHeaders::class)->apply(
                $this->container->get(Cors::class)->apply($request, $response)
            );
        }
    }

    /**
     * @param list<array{0: class-string<Middleware>, 1: list<string>}> $stack
     * @param callable(Request): Response $destination
     * @return callable(Request): Response
     */
    private function pipeline(array $stack, callable $destination): callable
    {
        $next = $destination;
        foreach (array_reverse($stack) as [$class, $args]) {
            $middleware = $this->container->get($class);
            $next = static fn (Request $request): Response => $middleware->handle($request, $next, ...$args);
        }

        return $next;
    }
}
