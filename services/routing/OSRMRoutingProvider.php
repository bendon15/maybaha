<?php

declare(strict_types=1);

require_once __DIR__ . '/RoutingProviderInterface.php';

final class OSRMRoutingProvider implements RoutingProviderInterface
{
    private const SOURCE_NAME = 'OSRM routing engine';

    public function getRoutes(float $fromLat, float $fromLon, float $toLat, float $toLon): DataEnvelope
    {
        $base = rtrim((string) Config::get('OSRM_BASE_URL', 'https://router.project-osrm.org'), '/');

        // OSRM expects lon,lat order.
        $coords = "{$fromLon},{$fromLat};{$toLon},{$toLat}";
        $url = "{$base}/route/v1/driving/{$coords}?" . http_build_query([
            'alternatives' => 'true',
            'overview' => 'full',
            'geometries' => 'geojson',
            'steps' => 'false',
        ]);

        try {
            $res = HttpClient::getJson($url, [], 10);
        } catch (HttpClientException $e) {
            Logger::warning('OSRM request failed', ['error' => $e->getMessage()]);
            return DataEnvelope::unavailable(self::SOURCE_NAME, 'Routing service did not respond.');
        }

        if ($res['status'] !== 200 || ($res['body']['code'] ?? '') !== 'Ok') {
            return DataEnvelope::unavailable(self::SOURCE_NAME, 'No drivable route was found between these points.');
        }

        $routes = [];
        foreach (($res['body']['routes'] ?? []) as $i => $r) {
            $coordsLonLat = $r['geometry']['coordinates'] ?? [];
            $latLon = array_map(static fn ($c) => [$c[1], $c[0]], $coordsLonLat);
            $routes[] = [
                'id' => 'route-' . ($i + 1),
                'geometry' => $latLon,
                'distance_m' => (float) ($r['distance'] ?? 0),
                'duration_s' => (float) ($r['duration'] ?? 0),
            ];
        }

        if (!$routes) {
            return DataEnvelope::unavailable(self::SOURCE_NAME, 'No drivable route was found between these points.');
        }

        return DataEnvelope::realTime(
            self::SOURCE_NAME,
            'https://project-osrm.org',
            $routes,
            'Public OSRM demo server. Fine for demos; run your own OSRM instance for production traffic.'
        );
    }
}
