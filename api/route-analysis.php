<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

enforce_rate_limit('route-analysis');

$params = request_params();
$fromLat = require_float($params, 'from_lat', 4.0, 21.5);
$fromLon = require_float($params, 'from_lon', 115.0, 127.5);
$toLat = require_float($params, 'to_lat', 4.0, 21.5);
$toLon = require_float($params, 'to_lon', 115.0, 127.5);

$router = ProviderFactory::routing();
$routeEnvelope = $router->getRoutes($fromLat, $fromLon, $toLat, $toLon);

if ($routeEnvelope->status === DataEnvelope::UNAVAILABLE) {
    json_fail(502, 'ROUTING_UNAVAILABLE', $routeEnvelope->note ?? 'Could not calculate a route between these points.');
}

$floodProvider = ProviderFactory::flood();
$floodEnvelope = $floodProvider->getFloodData();

$weatherProvider = ProviderFactory::weather();
$riskEngine = new RiskEngine();

$analyzedRoutes = [];
foreach ($routeEnvelope->data as $route) {
    $samples = $riskEngine->samplePoints($route['geometry'], 12);
    $weatherPoints = array_map(static fn ($p) => ['lat' => $p[0], 'lon' => $p[1]], $samples);
    $weatherEnvelope = $weatherProvider->getConditionsAlong($weatherPoints);

    $assessment = $riskEngine->assessRoute($route['geometry'], $weatherEnvelope, $floodEnvelope);

    $analyzedRoutes[] = [
        'id' => $route['id'],
        'geometry' => $route['geometry'],
        'distance_m' => $route['distance_m'],
        'duration_s' => $route['duration_s'],
        'risk' => $assessment,
        'weather' => $weatherEnvelope->toArray(),
    ];
}

// Sort so the lowest-risk route is presented first, but keep OSRM's
// original fastest route identifiable via `is_fastest`.
$levelOrder = ['SAFE' => 0, 'CAUTION' => 1, 'HIGH_RISK' => 2, 'AVOID' => 3];
$fastestId = $analyzedRoutes[0]['id'] ?? null;
usort($analyzedRoutes, fn ($a, $b) => $levelOrder[$a['risk']['level']] <=> $levelOrder[$b['risk']['level']]);
foreach ($analyzedRoutes as &$r) {
    $r['is_fastest'] = $r['id'] === $fastestId;
}
unset($r);

json_ok([
    'result' => [
        'routes' => $analyzedRoutes,
        'flood_data' => $floodEnvelope->toArray(),
        'routing_source' => [
            'source_name' => $routeEnvelope->sourceName,
            'status' => $routeEnvelope->status,
            'retrieved_at' => $routeEnvelope->retrievedAt,
        ],
    ],
]);
