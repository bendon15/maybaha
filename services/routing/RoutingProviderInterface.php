<?php

declare(strict_types=1);

interface RoutingProviderInterface
{
    /**
     * @return DataEnvelope data = list of routes:
     *   [{geometry: [[lat,lon],...], distance_m, duration_s, id}]
     */
    public function getRoutes(float $fromLat, float $fromLon, float $toLat, float $toLon): DataEnvelope;
}
