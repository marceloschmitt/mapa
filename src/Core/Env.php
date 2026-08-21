<?php
declare(strict_types=1);

namespace Mapa\Core;

class Env
{
    /** @var array<string, string>|null */
    private static $cache = null;

    public static function get(string $key, string $default = ''): string
    {
        $env = self::load();

        if (isset($env[$key]) && trim($env[$key]) !== '') {
            return trim($env[$key]);
        }

        $systemValue = getenv($key);
        if (is_string($systemValue) && trim($systemValue) !== '') {
            return trim($systemValue);
        }

        return $default;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = strtolower(self::get($key, $default ? 'true' : 'false'));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string, string> */
    public static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $candidates = [
            '.env',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                self::$cache = self::parseFile($candidate);
                return self::$cache;
            }
        }

        self::$cache = [];
        return self::$cache;
    }

    /** @return array<string, string> */
    private static function parseFile(string $envPath): array
    {
        $vars = [];
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return $vars;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            $parts = explode('=', $line, 2);
            $key = trim($parts[0]);
            $value = isset($parts[1]) ? trim($parts[1]) : '';

            if ($key !== '') {
                $vars[$key] = $value;
            }
        }

        return $vars;
    }
}
