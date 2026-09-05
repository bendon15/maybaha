/**
 * Thin wrapper around the backend API. Every call returns the parsed JSON
 * body on success and throws an Error (with a user-friendly `.message`)
 * on failure, so callers don't need to repeat status-code handling.
 */
const Api = (() => {
  const BASE = 'api'; // relative: works whether served from / or a subpath

  async function call(path, params = {}, { method = 'GET' } = {}) {
    let url = `${BASE}/${path}`;
    const opts = { method, headers: {} };

    if (method === 'GET') {
      const qs = new URLSearchParams();
      for (const [k, v] of Object.entries(params)) {
        if (v === undefined || v === null) continue;
        qs.set(k, typeof v === 'object' ? JSON.stringify(v) : v);
      }
      const qsStr = qs.toString();
      if (qsStr) url += `?${qsStr}`;
    } else {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(params);
    }

    let res, body;
    try {
      res = await fetch(url, opts);
      body = await res.json();
    } catch (e) {
      throw new Error('Could not reach the server. Check your connection and try again.');
    }

    if (!res.ok || body.ok === false) {
      const msg = body?.error?.message || `Request failed (${res.status})`;
      throw new Error(msg);
    }
    return body.result;
  }

  return {
    geocodeSearch: (q) => call('geocode', { mode: 'search', q }),
    geocodeReverse: (lat, lon) => call('geocode', { mode: 'reverse', lat, lon }),
    routeAnalysis: (fromLat, fromLon, toLat, toLon) =>
      call('route-analysis', { from_lat: fromLat, from_lon: fromLon, to_lat: toLat, to_lon: toLon }),
    floodRisk: () => call('flood-risk'),
  };
})();
