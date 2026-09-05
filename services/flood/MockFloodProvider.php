<?php

declare(strict_types=1);

require_once __DIR__ . '/FloodProviderInterface.php';

/**
 * Demo flood provider. Reads hand-authored GeoJSON zones and synthetic
 * incident reports from /data. See services/flood/README.md for what a
 * real provider integration would need to do instead.
 */
final class MockFloodProvider implements FloodProviderInterface
{
    private const SOURCE_NAME = 'Demo Flood Data Set';

    public function getFloodData(): DataEnvelope
    {
        $dataDir = dirname(__DIR__, 2) . '/data';
        $zonesPath = $dataDir . '/flood_zones.geojson';
        $reportsPath = $dataDir . '/flood_reports.json';

        if (!is_file($zonesPath) || !is_file($reportsPath)) {
            return DataEnvelope::unavailable(self::SOURCE_NAME, 'Demo flood data files are missing.');
        }

        $zones = json_decode((string) file_get_contents($zonesPath), true);
        $reportsRaw = json_decode((string) file_get_contents($reportsPath), true);

        $now = time();
        $reports = array_map(static function (array $r) use ($now) {
            $reportedAt = $now - ((int) $r['age_minutes'] * 60);
            return [
                'id' => $r['id'],
                'lat' => (float) $r['lat'],
                'lon' => (float) $r['lon'],
                'severity' => $r['severity'],
                'description' => $r['description'],
                'reported_at' => date('c', $reportedAt),
                'age_minutes' => (int) $r['age_minutes'],
            ];
        }, $reportsRaw['reports'] ?? []);

        return DataEnvelope::demo(self::SOURCE_NAME, [
            'zones' => $zones,
            'reports' => $reports,
        ]);
    }
}
