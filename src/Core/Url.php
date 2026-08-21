<?php
declare(strict_types=1);

namespace Mapa\Core;

class Url
{
    /**
     * Monta URL sem depender de rewrite do Apache.
     * Ex.: /login -> /index.php/login ; / -> /index.php
     */
    public static function to(string $path = '/'): string
    {
        $query = '';
        if (strpos($path, '?') !== false) {
            [$path, $query] = explode('?', $path, 2);
            $query = '?' . $query;
        }

        $path = '/' . trim($path, '/');
        if ($path === '/') {
            return '/index.php' . $query;
        }

        return '/index.php' . $path . $query;
    }

    /**
     * Base pública do portal a partir do pedido HTTP atual (CLI retorna null).
     * Ex.: https://mapa.exemplo.edu.br ou http://localhost:8080/mapa
     */
    public static function detectPublicBase(): ?string
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return null;
        }

        $hostHeader = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
        $host = trim(explode(',', $hostHeader)[0]);
        if ($host === '') {
            return null;
        }

        $protoHeader = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        $https = $protoHeader === 'https'
            || (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';

        $scheme = $https ? 'https' : 'http';

        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $basePath = dirname($scriptName);
        if ($basePath === '/' || $basePath === '\\' || $basePath === '.') {
            $basePath = '';
        }

        return $scheme . '://' . $host . $basePath;
    }
}
