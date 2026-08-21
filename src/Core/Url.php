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
}
