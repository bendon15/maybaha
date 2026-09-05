<?php

declare(strict_types=1);

require_once __DIR__ . '/WeatherProviderInterface.php';

final class OpenWeatherMapProvider implements WeatherProviderInterface
{
    private const SOURCE_NAME = 'OpenWeatherMap';

    public function getConditionsAlong(array $points): DataEnvelope
    {
        $apiKey = (string) Config::get('OPENWEATHERMAP_API_KEY', '');
        if ($apiKey === '') {
            return DataEnvelope::unavailable(self::SOURCE_NAME, 'No API key configured.');
        }

        // Sample at most a handful of points along the route to stay within
        // free-tier rate limits.
        $sample = $this->downsample($points, 5);
        $conditions = [];

        foreach ($sample as $p) {
            $url = 'https://api.openweathermap.org/data/2.5/weather?' . http_build_query([
                'lat' => $p['lat'],
                'lon' => $p['lon'],
                'appid' => $apiKey,
                'units' => 'metric',
            ]);

            try {
                $res = HttpClient::getJson($url, [], 6);
            } catch (HttpClientException $e) {
                Logger::warning('OpenWeatherMap request failed', ['error' => $e->getMessage()]);
                continue;
            }

            if ($res['status'] !== 200) {
                continue;
            }

            $body = $res['body'];
            $conditions[] = [
                'lat' => $p['lat'],
                'lon' => $p['lon'],
                'rain_mm_last_hour' => (float) ($body['rain']['1h'] ?? 0.0),
                'condition' => $body['weather'][0]['main'] ?? 'Unknown',
                'description' => $body['weather'][0]['description'] ?? '',
            ];
        }

        if (!$conditions) {
            return DataEnvelope::unavailable(self::SOURCE_NAME, 'Weather service returned no usable data.');
        }

        return DataEnvelope::realTime(self::SOURCE_NAME, 'https://openweathermap.org', $conditions);
    }

    /** @param array<int,array{lat:float,lon:float}> $points */
    private function downsample(array $points, int $max): array
    {
        $count = count($points);
        if ($count <= $max) {
            return $points;
        }
        $step = (int) ceil($count / $max);
        $out = [];
        for ($i = 0; $i < $count; $i += $step) {
            $out[] = $points[$i];
        }
        return $out;
    }
}
