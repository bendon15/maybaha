<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

enforce_rate_limit('flood-risk');

$floodProvider = ProviderFactory::flood();
$envelope = $floodProvider->getFloodData();

if ($envelope->status === DataEnvelope::UNAVAILABLE) {
    json_fail(502, 'FLOOD_DATA_UNAVAILABLE', $envelope->note ?? 'Flood data is currently unavailable.');
}

json_ok(['result' => $envelope->toArray()]);
