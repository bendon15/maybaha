# Flood provider integration notes

There is currently no single official, publicly-documented, machine-readable
real-time API for Philippine flood incidents that this app calls out of the
box. `MockFloodProvider` fills that gap with static demo zones/reports so
the rest of the application (map, risk engine, UI states) is fully
functional and testable.

When you're ready to wire up real data, these are the sources worth
building a `RealFloodProvider` against (verify current availability/terms
before integrating, since government data portals change):

- **DOST-Project NOAH / UP NOAH** — historical flood hazard maps and, at
  times, near-real-time flood sensor data for parts of Metro Manila and
  other river basins.
- **PAGASA** — rainfall/flood advisories and forecasts (Flood Forecasting
  and Warning System, FFWS) for major river basins (Marikina, Pampanga,
  Cagayan, etc.). No stable public JSON API as of this writing; advisories
  are typically published as bulletins/PDFs, so scraping or manual
  ingestion may be required.
- **MMDA** — Metro Manila flood control and traffic advisories, sometimes
  posted via social media/press releases rather than a formal API.
- **LGU-level advisories** (e.g. Marikina City's river monitoring, which
  has historically had public gauge readings) — best integrated
  per-LGU since formats vary.

To add a real provider:

1. Implement `FloodProviderInterface`.
2. Return `DataEnvelope::realTime(...)` on success, `DataEnvelope::stale(...)`
   if the upstream source's own timestamp is older than your freshness
   threshold, and `DataEnvelope::unavailable(...)` on failure — never fall
   back to mock data inside a "real" provider silently.
3. Wire it up in `services/flood/FloodProviderFactory.php` behind the
   `FLOOD_PROVIDER` env var.
