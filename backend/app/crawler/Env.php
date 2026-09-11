<?php

namespace app\crawler;

class Env
{
    public static function str(string $key, string $default = ''): string
    {
        $val = getenv($key);
        if ($val === false || $val === '') {
            return $default;
        }
        return $val;
    }

    public static function int(string $key, int $default): int
    {
        $val = getenv($key);
        if ($val === false || $val === '') {
            return $default;
        }
        return (int) $val;
    }
}

