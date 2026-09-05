<?php

declare(strict_types=1);

interface GeocodingProviderInterface
{
    /**
     * Resolve a free-text query into candidate locations.
     * @return DataEnvelope data = list of {label, lat, lon}
     */
    public function search(string $query): DataEnvelope;

    /** @return DataEnvelope data = {label, lat, lon}|null */
    public function reverse(float $lat, float $lon): DataEnvelope;
}
