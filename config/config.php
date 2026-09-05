<?php
/**
 * Minimal .env loader + typed config accessor.
 * No composer dependency required, keeps deployment simple on shared hosts.
 */

declare(strict_types=1);

final class Config
{
    private static ?array $values = null;

    public static function load(): void
    {
        if (self::$values !== null) {
            return;
        }

        self::$values = [];

        $envPath = dirname(__DIR__) . '/.env';
        if (is_file($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (!str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                // Strip surrounding quotes if present.
                if (strlen($value) >= 2 && (
                    ($value[0] === '"' && $value[-1] === '"') ||
                    ($value[0] === "'" && $value[-1] === "'")
                )) {
                    $value = substr($value, 1, -1);
                }
                self::$values[$key] = $value;
            }
        }

        // Environment variables (e.g. set by the host) always win over .env.
        foreach ($_ENV as $key => $value) {
            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }
        // Fall back to getenv() — platforms like Vercel's container runtime
        // inject project env vars into the process environment rather than
        // via a .env file, and don't reliably populate $_ENV depending on
        // the php.ini variables_order setting.
        $fromEnv = getenv($key);
        return $fromEnv !== false ? $fromEnv : $default;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v === null ? $default : (int) $v;
    }
}
