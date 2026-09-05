<?php

declare(strict_types=1);

require_once __DIR__ . '/GeocodingProviderInterface.php';

final class NominatimGeocodingProvider implements GeocodingProviderInterface
{
    private const SOURCE_NAME = 'OpenStreetMap Nominatim';

    public function search(string $query): DataEnvelope
    {
        $base = rtrim((string) Config::get('NOMINATIM_BASE_URL', 'https://nominatim.openstreetmap.org'), '/');
        $ua = (string) Config::get('NOMINATIM_USER_AGENT', 'FloodRoutePH/1.0');

        // Bias toward the Philippines; still allow the raw query through.
        $url = $base . '/search?' . http_build_query([
            'q' => $query,
            'format' => 'jsonv2',
            'countrycodes' => 'ph',
            'limit' => 6,
            'addressdetails' => 0,
        ]);

        try {
            $res = HttpClient::getJson($url, ['User-Agent' => $ua]);
        } catch (HttpClientException $e) {
            Logger::warning('Nominatim search failed', ['error' => $e->getMessage()]);
            return DataEnvelope::unavailable(self::SOURCE_NAME, 'Geocoding service did not respond.');
        }

        if ($res['status'] !== 200 || !is_array($res['body'])) {
            return DataEnvelope::unavailable(self::SOURCE_NAME, 'Geocoding service returned an error.');
        }

        $results = array_map(static fn ($r) => [
            'label' => $r['display_name'] ?? $query,
            'lat' => (float) $r['lat'],
            'lon' => (float) $r['lon'],
        ], $res['body']);

        return DataEnvelope::realTime(self::SOURCE_NAME, 'https://nominatim.openstreetmap.org', $results);
    }

    public function reverse(float $lat, float $lon): DataEnvelope
    {
        $base = rtrim((string) Config::get('NOMINATIM_BASE_URL', 'https://nominatim.openstreetmap.org'), '/');
        $ua = (string) Config::get('NOMINATIM_USER_AGENT', 'FloodRoutePH/1.0');

        $url = $base . '/reverse?' . http_build_query([
            'lat' => $lat,
            'lon' => $lon,
            'format' => 'jsonv2',
        ]);

        try {
            $res = HttpClient::getJson($url, ['User-Agent' => $ua]);
        } catch (HttpClientException $e) {
            Logger::warning('Nominatim reverse failed', ['error' => $e->getMessage()]);
            return DataEnvelope::unavailable(self::SOURCE_NAME, 'Reverse geocoding did not respond.');
        }

        if ($res['status'] !== 200 || !is_array($res['body'])) {
            return DataEnvelope::unavailable(self::SOURCE_NAME, 'Reverse geocoding returned an error.');
        }

        $label = $res['body']['display_name'] ?? sprintf('%.5f, %.5f', $lat, $lon);
        return DataEnvelope::realTime(self::SOURCE_NAME, 'https://nominatim.openstreetmap.org', [
            'label' => $label,
            'lat' => $lat,
            'lon' => $lon,
        ]);
    }
}
