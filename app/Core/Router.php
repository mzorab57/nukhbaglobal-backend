<?php

declare(strict_types=1);

namespace App\Core;

use App\Helpers\Response;
use RuntimeException;

final class Router
{
    /**
     * @var array<int, string>
     */
    private const SUPPORTED_METHODS = ['GET', 'POST'];

    /**
     * @var array<string, array<int, array{uri:string, pattern:string, parameters:array<int,string>, handler:callable|array{0:string,1:string}}>>
     */
    private array $routes = [
        'GET' => [],
        'POST' => [],
    ];

    public function get(string $uri, callable|array $handler): void
    {
        $this->addRoute('GET', $uri, $handler);
    }

    public function post(string $uri, callable|array $handler): void
    {
        $this->addRoute('POST', $uri, $handler);
    }

    public function dispatch(?string $requestUri = null, ?string $requestMethod = null): void
    {
        $method = strtoupper($requestMethod ?? $_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = $this->normalizeUri($requestUri ?? $_SERVER['REQUEST_URI'] ?? '/');
        $matchedRoute = $this->matchRoute($method, $path);

        if ($matchedRoute === null) {
            $allowedMethods = $this->getAllowedMethodsForPath($path);

            if ($allowedMethods !== []) {
                header('Allow: ' . implode(', ', $allowedMethods));
                Response::jsonResponse(false, 'Method not allowed.', [], 405);
            }

            Response::jsonResponse(false, 'Route not found.', [], 404);
        }

        $this->invokeHandler($matchedRoute['handler'], $matchedRoute['parameters']);
    }

    private function addRoute(string $method, string $uri, callable|array $handler): void
    {
        if (!in_array($method, self::SUPPORTED_METHODS, true)) {
            throw new RuntimeException(sprintf('HTTP method "%s" is not supported.', $method));
        }

        $normalizedUri = $this->normalizeUri($uri);
        [$pattern, $parameterNames] = $this->compilePattern($normalizedUri);

        $this->routes[$method][] = [
            'uri' => $normalizedUri,
            'pattern' => '#^' . $pattern . '$#',
            'parameters' => $parameterNames,
            'handler' => $handler,
        ];
    }

    /**
     * @return array{0:string,1:array<int,string>}
     */
    private function compilePattern(string $normalizedUri): array
    {
        $parameterNames = [];
        $segments = explode('/', trim($normalizedUri, '/'));
        $compiledSegments = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $matches) === 1) {
                $parameterNames[] = $matches[1];
                $compiledSegments[] = '([^/]+)';

                continue;
            }

            $compiledSegments[] = preg_quote($segment, '#');
        }

        if ($compiledSegments === []) {
            return ['/', $parameterNames];
        }

        return ['/' . implode('/', $compiledSegments), $parameterNames];
    }

    private function normalizeUri(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $normalized = '/' . trim($path, '/');

        return $normalized === '//' ? '/' : $normalized;
    }

    /**
     * @return array{handler:callable|array{0:string,1:string}, parameters:array<int, string>}|null
     */
    private function matchRoute(string $method, string $path): ?array
    {
        $routes = $this->routes[$method] ?? [];

        foreach ($routes as $route) {
            if (preg_match($route['pattern'], $path, $matches) !== 1) {
                continue;
            }

            array_shift($matches);
            $parameters = array_map('urldecode', $matches);

            return [
                'handler' => $route['handler'],
                'parameters' => $parameters,
            ];
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function getAllowedMethodsForPath(string $path): array
    {
        $allowedMethods = [];

        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $route) {
                if (preg_match($route['pattern'], $path) !== 1) {
                    continue;
                }

                $allowedMethods[] = $method;
                break;
            }
        }

        return $allowedMethods;
    }

    /**
     * @param array<int, string> $parameters
     */
    private function invokeHandler(callable|array $handler, array $parameters = []): void
    {
        if (is_callable($handler)) {
            $handler(...$parameters);

            return;
        }

        if (count($handler) !== 2 || !is_string($handler[0]) || !is_string($handler[1])) {
            throw new RuntimeException('Route handler array must contain a controller class and method name.');
        }

        [$controllerClass, $method] = $handler;

        if (!class_exists($controllerClass)) {
            throw new RuntimeException(sprintf('Controller class "%s" was not found.', $controllerClass));
        }

        $controller = new $controllerClass();

        if (!method_exists($controller, $method)) {
            throw new RuntimeException(sprintf('Method "%s::%s" was not found.', $controllerClass, $method));
        }

        $controller->{$method}(...$parameters);
    }
}
