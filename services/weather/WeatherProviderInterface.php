<?php

declare(strict_types=1);

interface WeatherProviderInterface
{
    /**
     * @param array<int,array{lat:float,lon:float}> $points sample points along a route
     * @return DataEnvelope data = list of
     *   {lat, lon, rain_mm_last_hour, condition, description}
     */
    public function getConditionsAlong(array $points): DataEnvelope;
}
