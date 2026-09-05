<?php

declare(strict_types=1);

require_once __DIR__ . '/WeatherProviderInterface.php';

/**
 * Demo weather provider. Generates plausible-looking but entirely
 * synthetic rainfall figures so the rest of the app (UI, risk engine)
 * can be exercised without a paid API key or PAGASA integration.
 *
 * IMPORTANT: every response from this provider is wrapped as
 * DataEnvelope::demo(), which the frontend renders with a visible
 * "DEMO DATA" badge. This class must never be silently swapped in for
 * a real provider without that label following it.
 */
final class MockWeatherProvider implements WeatherProviderInterface
{
    private const SOURCE_NAME = 'Demo Weather Generator';

    public function getConditionsAlong(array $points): DataEnvelope
    {
        $conditions = [];
        // Vary "rain" gently over a 20-minute cycle so repeated demo
        // requests don't look perfectly static.
        $cycle = intdiv(time(), 60) % 20;

        foreach ($points as $p) {
            $seed = crc32(sprintf('%.3f,%.3f', $p['lat'], $p['lon'])) + $cycle;
            $rainMm = round((($seed % 40) / 10) + (($seed % 3 === 0) ? 6 : 0), 1);

            $condition = 'Light Rain';
            if ($rainMm >= 15) {
                $condition = 'Heavy Rain';
            } elseif ($rainMm >= 7.5) {
                $condition = 'Moderate Rain';
            } elseif ($rainMm < 1) {
                $condition = 'Clear';
            }

            $conditions[] = [
                'lat' => $p['lat'],
                'lon' => $p['lon'],
                'rain_mm_last_hour' => $rainMm,
                'condition' => $condition,
                'description' => strtolower($condition) . ' (simulated)',
            ];
        }

        return DataEnvelope::demo(self::SOURCE_NAME, $conditions);
    }
}
