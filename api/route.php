<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

enforce_rate_limit('route');

$params = request_params();
$fromLat = require_float($params, 'from_lat', 4.0, 21.5);
$fromLon = require_float($params, 'from_lon', 115.0, 127.5);
$toLat = require_float($params, 'to_lat', 4.0, 21.5);
$toLon = require_float($params, 'to_lon', 115.0, 127.5);

$router = ProviderFactory::routing();
$envelope = $router->getRoutes($fromLat, $fromLon, $toLat, $toLon);

if ($envelope->status === DataEnvelope::UNAVAILABLE) {
    json_fail(502, 'ROUTING_UNAVAILABLE', $envelope->note ?? 'Routing is currently unavailable.');
}

json_ok(['result' => $envelope->toArray()]);
