<?php

declare(strict_types=1);

/**
 * Very small file-backed sliding-window rate limiter, keyed by client IP + route.
 * Good enough for a single-server demo/small deployment. Swap for Redis
 * behind a real load-balanced deployment.
 */
final class RateLimiter
{
    public static function check(string $bucket): bool
    {
        $max = Config::getInt('RATE_LIMIT_MAX_REQUESTS', 30);
        $window = Config::getInt('RATE_LIMIT_WINDOW_SECONDS', 60);

        $ip = self::clientIp();
        $dir = self::cacheDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $bucket . '_' . $ip) . '.json';

        $now = time();
        $entries = [];
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $entries = $raw ? (json_decode($raw, true) ?: []) : [];
        }

        // Drop timestamps outside the window.
        $entries = array_values(array_filter($entries, fn ($t) => $t > $now - $window));

        if (count($entries) >= $max) {
            return false;
        }

        $entries[] = $now;
        @file_put_contents($file, json_encode($entries), LOCK_EX);
        return true;
    }

    private static function clientIp(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Where per-IP rate-limit counters are written. Absolute path (e.g.
     * /tmp/cache/ratelimit on Vercel's container runtime, since the app
     * directory there is not guaranteed writable/persistent) is used as-is;
     * a relative path resolves against the project root for traditional
     * hosting. Note this resets on every cold start / new instance in a
     * serverless environment — fine for basic abuse prevention, not a
     * substitute for a shared store (e.g. Redis) under real load.
     */
    private static function cacheDir(): string
    {
        $configured = (string) Config::get('RATE_LIMIT_CACHE_DIR', 'data/cache/ratelimit');
        return str_starts_with($configured, '/')
            ? $configured
            : dirname(__DIR__) . '/' . ltrim($configured, '/');
    }
}
