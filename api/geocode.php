<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

enforce_rate_limit('geocode');

$params = request_params();
$mode = $params['mode'] ?? 'search';

$geocoder = ProviderFactory::geocoding();

if ($mode === 'reverse') {
    $lat = require_float($params, 'lat', 4.0, 21.5);
    $lon = require_float($params, 'lon', 115.0, 127.5);
    $envelope = $geocoder->reverse($lat, $lon);
} else {
    $query = require_string($params, 'q', 200);
    $envelope = $geocoder->search($query);
}

if ($envelope->status === DataEnvelope::UNAVAILABLE) {
    json_fail(502, 'GEOCODE_UNAVAILABLE', $envelope->note ?? 'Geocoding is currently unavailable.');
}

json_ok(['result' => $envelope->toArray()]);
