# Maybaha — Flood Risk Route Navigator (Philippines)

A flood-aware driving navigation web app for the Philippines. It plans
driving routes, checks them against flood-prone zones, recent flood
reports, and rainfall along the way, and gives you a transparent risk
rating (**SAFE / CAUTION / HIGH RISK / AVOID**) with the specific reasons
behind it — never a bare "this route is safe" claim.

> **This is a decision-support tool, not a guarantee of safety.** It can
> only reflect the data it has access to (see "Data sources" below). Always
> use your own judgment, watch the road, and follow instructions from
> local authorities during severe weather.

---

## 1. Stack

- **Backend:** plain PHP 8.1+ (no framework), provider-based service layer
- **Frontend:** HTML + CSS + vanilla JavaScript, [Leaflet](https://leafletjs.com/) for the map
- **Map tiles:** OpenStreetMap-compatible (CARTO dark basemap by default)
- **Data layer:** flat files (GeoJSON/JSON) for the demo; designed to drop
  in PostgreSQL/PostGIS later without changing the API contracts (see §6)

No Composer, no Node build step, no database required to run the demo.

---

## 2. Project structure

```
maybaha/
├── public/                  # Web-servable frontend (the only thing besides /api a browser should reach)
│   ├── index.html
│   └── assets/{css,js}/
├── api/                     # PHP API endpoints (thin controllers)
│   ├── _bootstrap.php       # shared config/CORS/validation/error handling
│   ├── geocode.php
│   ├── route.php
│   ├── flood-risk.php
│   ├── weather.php
│   └── route-analysis.php   # main aggregate endpoint the frontend calls
├── services/                # Provider-based service layer
│   ├── weather/  flood/  routing/  geocoding/   (interface + implementations)
│   ├── ProviderFactory.php  # single switchboard: real vs demo provider per service
│   ├── RiskEngine.php       # turns weather+flood data into an explainable risk rating
│   ├── DataEnvelope.php     # REAL_TIME / DEMO / STALE / UNAVAILABLE wrapper for every response
│   ├── HttpClient.php, Logger.php, RateLimiter.php
├── config/config.php         # .env loader
├── data/                     # demo flood zones/reports (flat files)
├── logs/
├── .env.example
├── .htaccess                 # Apache: clean API URLs + blocks direct access to services/config/data/logs
├── router.php                 # dev-only router for `php -S`
├── composer.json / composer.lock   # no PHP dependencies; present because Vercel's Docker build expects them
├── Caddyfile                  # web server config for the Vercel/FrankenPHP deployment
├── Dockerfile.vercel          # container image for Vercel's container-function runtime
└── vercel.json                # tells Vercel to run Dockerfile.vercel as a service
```

---

## 3. Local setup

Requirements: PHP 8.1+ with the `curl` extension enabled (`mbstring` recommended).

```bash
cd maybaha
cp .env.example .env
php -S localhost:8080 router.php
```

Open `http://localhost:8080`. Allow location access when prompted, or type
a starting point manually. Try a destination like "Marikina" or "Cubao" to
see the demo flood zones/reports trigger a HIGH RISK / AVOID rating.

The dev server proxies `/api/<name>` to `/api/<name>.php` via `router.php`,
matching the clean URLs used in production (see `.htaccess`).

---

## 4. Configuration (`.env`)

Copy `.env.example` to `.env` and adjust. Key settings:

| Variable | Purpose |
|---|---|
| `CORS_ALLOWED_ORIGINS` | Comma-separated allowed origins (or `*` for local dev only) |
| `WEATHER_PROVIDER` / `OPENWEATHERMAP_API_KEY` | Set to `openweathermap` + a real key for live rainfall; otherwise the app automatically uses the demo weather generator and labels it `DEMO` in the UI |
| `FLOOD_PROVIDER` | `mock` today — see `services/flood/README.md` for what's needed to wire up a real feed |
| `OSRM_BASE_URL` | Defaults to the public OSRM demo server (rate-limited, fine for demos). **Run your own OSRM/GraphHopper/Valhalla instance for production traffic** — the public demo server is not meant for sustained load. |
| `NOMINATIM_BASE_URL` / `NOMINATIM_USER_AGENT` | OpenStreetMap's geocoder requires a descriptive User-Agent per its usage policy |
| `RATE_LIMIT_MAX_REQUESTS` / `_WINDOW_SECONDS` | Simple per-IP file-backed rate limiting |

Never commit your real `.env` — it's already in `.gitignore`.

---

## 5. Data sources & honesty about data quality

Every single API response is wrapped in a `DataEnvelope` with one of four
statuses, and the frontend always shows which one it got:

- **REAL_TIME** — live data from an external service (OSRM routing,
  Nominatim geocoding, OpenWeatherMap if configured)
- **DEMO** — synthetic/illustrative data (the flood zones/reports dataset,
  and weather when no API key is configured). Rendered with a visible
  amber "DEMO DATA" badge — it is never presented as if it were live.
- **STALE** — a real provider responded, but its own data timestamp is
  older than the freshness threshold
- **UNAVAILABLE** — the provider failed; the app shows this plainly rather
  than silently substituting fake data

**Current honest state of each data type:**

| Data | Status today | Real integration path |
|---|---|---|
| Routing | **Real** (OSRM public demo server) | Swap `OSRM_BASE_URL` for your own instance |
| Geocoding | **Real** (OpenStreetMap Nominatim) | Works out of the box; respect Nominatim's usage policy at scale |
| Weather | **Demo** by default, real if you add an OpenWeatherMap key | See `services/weather/` |
| Flood zones/incidents | **Demo only** | No stable public real-time PH flood API exists yet; see `services/flood/README.md` for PAGASA/Project NOAH/MMDA/LGU integration notes |

---

## 6. Adding PostgreSQL/PostGIS later

Nothing in `api/` or the frontend assumes flat files. To move the flood
data layer to PostGIS:

1. Implement a new `FloodProviderInterface` (e.g. `PostgisFloodProvider`)
   that queries flood zone polygons with `ST_Intersects` against the route
   geometry and recent reports with `ST_DWithin`.
2. Return the same shape (`DataEnvelope` wrapping `{zones, reports}`) so
   `RiskEngine` and the frontend need zero changes.
3. Point `ProviderFactory::flood()` at it behind a new `FLOOD_PROVIDER`
   value.

The same pattern applies to routing/weather/geocoding if you later want a
database-backed cache in front of those APIs.

---

## 7. API endpoints

All endpoints return `{"ok": true, "result": ...}` or
`{"ok": false, "error": {"code", "message"}}`, validate/sanitize input,
and are rate-limited per IP.

- `GET /api/geocode?q=<query>` — search; `?mode=reverse&lat=&lon=` for reverse geocoding
- `GET /api/route?from_lat=&from_lon=&to_lat=&to_lon=` — raw candidate routes, no risk analysis
- `GET /api/flood-risk` — current flood zones + incident reports
- `GET|POST /api/weather` — conditions at a point or list of `points`
- `GET /api/route-analysis?from_lat=&from_lon=&to_lat=&to_lon=` — **the main endpoint**: routes + per-route risk rating + reasons + data provenance, sorted lowest-risk first

---

## 8. Deploy to shared hosting / VPS (Apache)

1. Upload the whole `maybaha/` directory so its root is your domain's
   document root (the `.htaccess` at the project root blocks direct access
   to `services/`, `config/`, `data/`, and `logs/`, and rewrites clean
   `/api/<name>` URLs to the matching PHP file).
2. `cp .env.example .env` and fill in real values on the server — never
   upload a `.env` from your machine with real secrets in git.
3. Ensure `logs/` and `data/cache/` (created automatically) are writable
   by the PHP process.
4. Set `CORS_ALLOWED_ORIGINS` to your real domain(s).
5. If you configure a real weather/flood provider, confirm outbound HTTPS
   is allowed from the server to that provider.
6. For anything beyond light demo traffic, replace the public OSRM demo
   server with your own routing instance — it is rate-limited and not
   intended for production load.

On **nginx**, translate the two rules in `.htaccess` (block internal
folders, rewrite `/api/<name>` → `/api/<name>.php`) into your server block;
nginx doesn't read `.htaccess`.

---

## 9. Deploy to GitHub

Nothing PHP-specific here — push the project as a normal repo:

```bash
cd maybaha
git init
git add .
git commit -m "Initial commit"
git branch -M main
git remote add origin https://github.com/<your-username>/<your-repo>.git
git push -u origin main
```

`.env` is already excluded via `.gitignore` — don't commit it. `.env.example`,
`composer.json`, and `composer.lock` **should** be committed; Vercel's build
needs them.

---

## 10. Deploy to Vercel (Docker + FrankenPHP)

Vercel's Functions runtime is built for Node.js/Python/Go/Ruby, not plain
`.php` files — so this app deploys as a Docker container running
[FrankenPHP](https://frankenphp.dev/) (a combined Caddy web server + PHP
runtime), using Vercel's [container-image function
support](https://vercel.com/docs/functions/container-images). The three
files that make this work are already in the project root:

- **`Caddyfile`** — routes `/api/<name>` to `/app/api/<name>.php`, and
  everything else to the static frontend in `/app/public`.
- **`Dockerfile.vercel`** — builds a FrankenPHP image containing the app.
- **`vercel.json`** — tells Vercel to run `Dockerfile.vercel` as a
  container service and route all traffic to it.

### One-time setup

You need Docker running locally only to test with `vercel dev`; Vercel
itself builds the image on its own infrastructure when you deploy.

```bash
npm install -g vercel   # if you don't already have it
vercel login
```

### Deploy via Git (recommended)

1. Push the repo to GitHub (see §8a).
2. In the [Vercel dashboard](https://vercel.com/new), import the repository.
   Vercel will detect `vercel.json` and build `Dockerfile.vercel`
   automatically — no other project settings needed.
3. Before the first deploy (or right after), add environment variables in
   **Project Settings → Environment Variables**. At minimum:
   - `CORS_ALLOWED_ORIGINS` → your Vercel deployment URL (e.g.
     `https://maybaha.vercel.app`)
   - Optionally `WEATHER_PROVIDER=openweathermap` and
     `OPENWEATHERMAP_API_KEY=<your key>` for real rainfall data — leave
     unset to keep using the free demo weather generator.
   - You do **not** need to set `LOG_PATH` or `RATE_LIMIT_CACHE_DIR` —
     `Dockerfile.vercel` already points those at `/tmp` for you.
4. Push to `main` (or open a PR) — Vercel deploys automatically and gives
   PRs their own preview URL.

### Deploy via CLI (no GitHub required)

```bash
cd maybaha
vercel deploy --prod
```

### Free-tier notes

Everything here fits Vercel's free **Hobby** plan and free upstream APIs:

- Hobby includes container/Function usage (Active CPU hours, invocations)
  that's generous for a personal project's traffic; it scales to zero
  when idle, so there's no cost sitting there unused.
- Routing (OSRM public demo server) and geocoding (Nominatim) are free,
  no API key required — same as running locally.
- Weather defaults to the free demo generator unless you add an
  OpenWeatherMap key (OpenWeatherMap also has a free tier if you want
  real rainfall data).
- The flood zone/report data is a bundled demo dataset — no external
  service or key involved at all.

### Things that behave differently on Vercel vs. a normal PHP host

- **No persistent local storage.** The container's filesystem doesn't
  survive cold starts, so rate-limit counters and log lines written to
  `/tmp` disappear between them. That's fine for basic per-instance abuse
  prevention and won't break anything — it just means don't expect a
  running log file to inspect; use `vercel logs <deployment-url>` instead.
  If you later want rate limits or logs that persist and are shared
  across instances, swap in a Vercel-native store (Upstash/Marketplace
  Redis for rate limiting, a logging integration from the Vercel
  Marketplace) behind the same `RateLimiter`/`Logger` classes.
- **Cold starts.** The first request after idle time will be slower while
  the container spins up.
- **No `.env` file in production.** Environment variables come from the
  Vercel dashboard, not a committed file — `config/config.php` already
  reads real process environment variables via `getenv()`, so nothing
  else needs to change.

### Testing the container build locally first

```bash
vercel dev -L
# then, in another terminal:
curl http://localhost:3000/api/flood-risk
```

This runs the same `Dockerfile.vercel`/`Caddyfile` you'll deploy, so if it
works here it'll work on Vercel.

---

## 11. Known limitations (by design, for this demo)

- Flood incident/zone data is illustrative, not a real feed — see §5.
- The public OSRM/Nominatim servers used by default are rate-limited
  shared resources; fine for trying the app, not for production traffic.
- Rate limiting is a simple per-IP file counter suitable for one server;
  use Redis or similar behind a load balancer.
- No user accounts, saved trips, or push notifications — out of scope for
  this pass but the provider architecture doesn't block adding them.
