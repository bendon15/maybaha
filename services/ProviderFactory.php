<?php

declare(strict_types=1);

require_once __DIR__ . '/weather/WeatherProviderInterface.php';
require_once __DIR__ . '/weather/MockWeatherProvider.php';
require_once __DIR__ . '/weather/OpenWeatherMapProvider.php';
require_once __DIR__ . '/flood/FloodProviderInterface.php';
require_once __DIR__ . '/flood/MockFloodProvider.php';
require_once __DIR__ . '/routing/RoutingProviderInterface.php';
require_once __DIR__ . '/routing/OSRMRoutingProvider.php';
require_once __DIR__ . '/geocoding/GeocodingProviderInterface.php';
require_once __DIR__ . '/geocoding/NominatimGeocodingProvider.php';

/**
 * Single switchboard for which concrete provider backs each service.
 * Swapping a provider later (new weather API, real flood feed, self-hosted
 * OSRM) means changing the relevant `.env` value only, never application
 * code that calls these services.
 */
final class ProviderFactory
{
    public static function weather(): WeatherProviderInterface
    {
        $choice = strtolower((string) Config::get('WEATHER_PROVIDER', 'mock'));
        $apiKey = (string) Config::get('OPENWEATHERMAP_API_KEY', '');

        if ($choice === 'openweathermap' && $apiKey !== '') {
            return new OpenWeatherMapProvider();
        }

        return new MockWeatherProvider();
    }

    public static function flood(): FloodProviderInterface
    {
        // Only a mock provider exists today; the switch is kept so a real
        // provider can be added under FLOOD_PROVIDER=<name> without
        // touching any calling code.
        return new MockFloodProvider();
    }

    public static function routing(): RoutingProviderInterface
    {
        return new OSRMRoutingProvider();
    }

    public static function geocoding(): GeocodingProviderInterface
    {
        return new NominatimGeocodingProvider();
    }
}
