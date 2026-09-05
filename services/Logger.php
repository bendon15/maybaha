<?php

declare(strict_types=1);

final class Logger
{
    private static array $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    public static function log(string $level, string $message, array $context = []): void
    {
        $configuredLevel = strtolower((string) Config::get('LOG_LEVEL', 'info'));
        $threshold = self::$levels[$configuredLevel] ?? 1;
        $current = self::$levels[strtolower($level)] ?? 1;
        if ($current < $threshold) {
            return;
        }

        $configuredPath = (string) Config::get('LOG_PATH', 'logs/app.log');
        // Absolute path (e.g. /tmp/logs/app.log on Vercel's container
        // runtime, where the app directory is not writable/persistent)
        // is used as-is; a relative path resolves against the project root
        // for traditional hosting.
        $path = str_starts_with($configuredPath, '/')
            ? $configuredPath
            : dirname(__DIR__) . '/' . ltrim($configuredPath, '/');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = sprintf(
            "[%s] %s: %s %s\n",
            date('c'),
            strtoupper($level),
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : ''
        );

        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    public static function debug(string $m, array $c = []): void { self::log('debug', $m, $c); }
    public static function info(string $m, array $c = []): void { self::log('info', $m, $c); }
    public static function warning(string $m, array $c = []): void { self::log('warning', $m, $c); }
    public static function error(string $m, array $c = []): void { self::log('error', $m, $c); }
}
