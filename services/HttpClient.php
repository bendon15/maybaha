<?php

declare(strict_types=1);

final class HttpClientException extends RuntimeException
{
}

final class HttpClient
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:mixed}
     * @throws HttpClientException
     */
    public static function getJson(string $url, array $headers = [], int $timeoutSeconds = 6): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => self::formatHeaders($headers + ['Accept' => 'application/json']),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new HttpClientException("HTTP request failed ({$errno}): {$error}");
        }

        $decoded = json_decode((string) $body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpClientException('Upstream response was not valid JSON');
        }

        return ['status' => $status, 'body' => $decoded];
    }

    /** @param array<string,string> $headers */
    private static function formatHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[] = "{$k}: {$v}";
        }
        return $out;
    }
}
