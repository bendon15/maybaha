<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

enforce_rate_limit('weather');

$params = request_params();

// Accept either a single lat/lon or a JSON array of points under "points".
$points = [];
if (isset($params['points']) && is_array($params['points'])) {
    foreach ($params['points'] as $p) {
        if (isset($p['lat'], $p['lon']) && is_numeric($p['lat']) && is_numeric($p['lon'])) {
            $points[] = ['lat' => (float) $p['lat'], 'lon' => (float) $p['lon']];
        }
    }
} elseif (isset($params['lat'], $params['lon'])) {
    $points[] = [
        'lat' => require_float($params, 'lat', 4.0, 21.5),
        'lon' => require_float($params, 'lon', 115.0, 127.5),
    ];
}

if (empty($points)) {
    json_fail(400, 'INVALID_PARAM', 'Provide either lat/lon or a points array.');
}

if (count($points) > 50) {
    json_fail(400, 'INVALID_PARAM', 'Too many points requested at once (max 50).');
}

$weatherProvider = ProviderFactory::weather();
$envelope = $weatherProvider->getConditionsAlong($points);

if ($envelope->status === DataEnvelope::UNAVAILABLE) {
    json_fail(502, 'WEATHER_UNAVAILABLE', $envelope->note ?? 'Weather data is currently unavailable.');
}

json_ok(['result' => $envelope->toArray()]);
