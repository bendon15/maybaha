<?php

declare(strict_types=1);

interface FloodProviderInterface
{
    /**
     * @return DataEnvelope data = {
     *   zones: GeoJSON FeatureCollection of flood-prone polygons,
     *   reports: list of {lat, lon, severity, reported_at, description}
     * }
     */
    public function getFloodData(): DataEnvelope;
}
