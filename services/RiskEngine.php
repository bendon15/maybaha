<?php

declare(strict_types=1);

/**
 * Turns raw weather + flood data into a transparent risk rating for a
 * route. Every rating always comes with the list of reasons that produced
 * it — the UI must never show a color without the "why".
 *
 * This is a heuristic aid, not a certified hazard model. It never asserts
 * that a route is safe — only that available data does or doesn't show a
 * known problem, which is a meaningfully weaker and more honest claim.
 */
final class RiskEngine
{
    public const SAFE = 'SAFE';
    public const CAUTION = 'CAUTION';
    public const HIGH_RISK = 'HIGH_RISK';
    public const AVOID = 'AVOID';

    private const LEVEL_ORDER = [self::SAFE => 0, self::CAUTION => 1, self::HIGH_RISK => 2, self::AVOID => 3];

    private const NEAR_REPORT_RADIUS_M = 500;
    private const STALE_AFTER_MINUTES = 45;

    /**
     * @param array<int,array{0:float,1:float}> $routeLatLon
     */
    public function assessRoute(
        array $routeLatLon,
        DataEnvelope $weather,
        DataEnvelope $flood
    ): array {
        $reasons = [];
        $level = self::SAFE;
        $samples = $this->samplePoints($routeLatLon, 12);

        // --- Flood zones ---
        $zoneHits = [];
        if ($flood->status !== DataEnvelope::UNAVAILABLE) {
            $zones = $flood->data['zones']['features'] ?? [];
            foreach ($zones as $zone) {
                foreach ($samples as $pt) {
                    if ($this->pointInPolygon($pt, $zone['geometry']['coordinates'][0] ?? [])) {
                        $zoneHits[] = $zone['properties'];
                        break;
                    }
                }
            }
        }
        foreach ($zoneHits as $zp) {
            $bumped = ($zp['risk_level'] ?? '') === 'high' ? self::HIGH_RISK : self::CAUTION;
            $level = $this->maxLevel($level, $bumped);
            $reasons[] = "Route passes through a known flood-prone area: {$zp['name']}.";
        }

        // --- Flood incident reports near the route ---
        $reportHits = [];
        if ($flood->status !== DataEnvelope::UNAVAILABLE) {
            foreach (($flood->data['reports'] ?? []) as $report) {
                foreach ($samples as $pt) {
                    $dist = $this->haversineMeters($pt[0], $pt[1], $report['lat'], $report['lon']);
                    if ($dist <= self::NEAR_REPORT_RADIUS_M) {
                        $reportHits[] = ['report' => $report, 'distance_m' => round($dist)];
                        break;
                    }
                }
            }
        }
        foreach ($reportHits as $hit) {
            $sev = $hit['report']['severity'];
            $bumped = match ($sev) {
                'severe' => self::AVOID,
                'moderate' => self::HIGH_RISK,
                default => self::CAUTION,
            };
            $level = $this->maxLevel($level, $bumped);
            $ageMin = $hit['report']['age_minutes'] ?? null;
            $ageText = $ageMin !== null ? "reported {$ageMin} min ago" : 'recently reported';
            $reasons[] = ucfirst($sev) . " flood report within " . round($hit['distance_m']) . "m of the route ({$ageText}).";
        }

        // --- Rainfall along the route ---
        $maxRain = 0.0;
        if ($weather->status !== DataEnvelope::UNAVAILABLE) {
            foreach (($weather->data ?? []) as $cond) {
                $maxRain = max($maxRain, (float) ($cond['rain_mm_last_hour'] ?? 0));
            }
        }
        if ($maxRain >= 15) {
            $level = $this->maxLevel($level, self::HIGH_RISK);
            $reasons[] = "Heavy rainfall detected along the route (up to {$maxRain}mm/hr).";
        } elseif ($maxRain >= 7.5) {
            $level = $this->maxLevel($level, self::CAUTION);
            $reasons[] = "Moderate rainfall detected along the route (up to {$maxRain}mm/hr).";
        }

        // --- Data quality caveats ---
        if ($weather->status === DataEnvelope::UNAVAILABLE) {
            $reasons[] = 'Weather data is currently unavailable for this route — rainfall risk could not be assessed.';
        } elseif ($weather->status === DataEnvelope::STALE) {
            $reasons[] = 'Weather data is older than expected; conditions may have changed.';
        }
        if ($flood->status === DataEnvelope::UNAVAILABLE) {
            $reasons[] = 'Flood report data is currently unavailable — known incidents could not be checked.';
        }

        if (empty($reasons)) {
            $reasons[] = 'No flood reports, known flood-prone zones, or significant rainfall detected along this route in the available data.';
        }

        return [
            'level' => $level,
            'reasons' => $reasons,
            'max_rain_mm_hr' => $maxRain,
            'zone_hits' => array_values(array_unique(array_map(static fn ($z) => $z['name'], $zoneHits))),
            'report_hits' => count($reportHits),
            'sampled_points' => count($samples),
        ];
    }

    public function maxLevel(string $a, string $b): string
    {
        return self::LEVEL_ORDER[$a] >= self::LEVEL_ORDER[$b] ? $a : $b;
    }

    /** @param array<int,array{0:float,1:float}> $route */
    public function samplePoints(array $route, int $maxPoints): array
    {
        $count = count($route);
        if ($count <= $maxPoints || $count === 0) {
            return $route;
        }
        $step = max(1, (int) floor($count / $maxPoints));
        $out = [];
        for ($i = 0; $i < $count; $i += $step) {
            $out[] = $route[$i];
        }
        return $out;
    }

    public function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $r * $c;
    }

    /**
     * Ray casting point-in-polygon test.
     * @param array{0:float,1:float} $point [lat, lon]
     * @param array<int,array{0:float,1:float}> $polygon [[lon,lat], ...] GeoJSON order
     */
    public function pointInPolygon(array $point, array $polygon): bool
    {
        [$lat, $lon] = $point;
        $inside = false;
        $n = count($polygon);
        if ($n < 3) {
            return false;
        }
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $polygon[$i][0];
            $yi = $polygon[$i][1];
            $xj = $polygon[$j][0];
            $yj = $polygon[$j][1];
            $intersect = (($yi > $lat) !== ($yj > $lat)) &&
                ($lon < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi);
            if ($intersect) {
                $inside = !$inside;
            }
        }
        return $inside;
    }
}
