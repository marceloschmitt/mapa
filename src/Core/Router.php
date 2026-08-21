<?php
declare(strict_types=1);

namespace Mapa\Core;

use Mapa\Models\UserRepository;

class Router
{
    /** @var array<string, array{0: class-string, 1: string}> */
    private $routes = [];

    public function get(string $path, array $handler): void
    {
        $this->routes['GET ' . $this->normalize($path)] = $handler;
    }

    public function post(string $path, array $handler): void
    {
        $this->routes['POST ' . $this->normalize($path)] = $handler;
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = $this->currentPath();
        $key = $method . ' ' . $path;

        if ($path !== '/setup') {
            $adminPendente = (new UserRepository())->findAdminPendenteSenha();
            if ($adminPendente !== null) {
                header('Location: ' . Url::to('/setup'));
                exit;
            }
        }

        if (!isset($this->routes[$key])) {
            http_response_code(404);
            echo 'Página não encontrada: ' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8');
            return;
        }

        [$className, $action] = $this->routes[$key];
        $controller = new $className();
        $controller->$action();
    }

    private function currentPath(): string
    {
        $pathInfo = $_SERVER['PATH_INFO'] ?? '';
        if (is_string($pathInfo) && $pathInfo !== '') {
            return $this->normalize($pathInfo);
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            return '/';
        }

        if (preg_match('#/index\.php(/.*)?$#', $path, $matches)) {
            $path = isset($matches[1]) && $matches[1] !== '' ? $matches[1] : '/';
        }

        return $this->normalize($path);
    }

    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
