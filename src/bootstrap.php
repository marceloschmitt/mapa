<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Mapa\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = 'src/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

if (!function_exists('url')) {
    function url(string $path = '/'): string
    {
        return Mapa\Core\Url::to($path);
    }
}

Mapa\Core\Session::start();
