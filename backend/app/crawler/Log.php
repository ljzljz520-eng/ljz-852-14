<?php

namespace app\crawler;

class Log
{
    public static function info(string $msg, array $ctx = []): void
    {
        self::write('INFO', $msg, $ctx);
    }

    public static function warn(string $msg, array $ctx = []): void
    {
        self::write('WARN', $msg, $ctx);
    }

    public static function error(string $msg, array $ctx = []): void
    {
        self::write('ERROR', $msg, $ctx);
    }

    private static function write(string $level, string $msg, array $ctx): void
    {
        $ts = date('c');
        $line = json_encode([
            'ts' => $ts,
            'level' => $level,
            'msg' => $msg,
            'ctx' => $ctx,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            $line = $ts . ' ' . $level . ' ' . $msg;
        }
        fwrite(STDERR, $line . "\n");
    }
}

