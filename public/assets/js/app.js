/**
 * App orchestration: geolocation, destination search, triggering route
 * analysis, and rendering results. Keeps DOM wiring together in one place;
 * map/layer specifics live in map.js, network calls in api.js.
 */
(() => {
  const state = {
    origin: null, // {lat, lon, label}
    destination: null, // {lat, lon, label}
    routes: [],
    selectedRouteId: null,
    floodData: null,
  };

  const els = {
    originInput: document.getElementById('originInput'),
    destInput: document.getElementById('destInput'),
    useGpsBtn: document.getElementById('useGpsBtn'),
    findRoutesBtn: document.getElementById('findRoutesBtn'),
    suggestions: document.getElementById('suggestions'),
    recenterBtn: document.getElementById('recenterBtn'),
    resultsPanel: document.getElementById('resultsPanel'),
    resultsTitle: document.getElementById('resultsTitle'),
    resultsSub: document.getElementById('resultsSub'),
    routeList: document.getElementById('routeList'),
    routeDetail: document.getElementById('routeDetail'),
    closeResultsBtn: document.getElementById('closeResultsBtn'),
    toast: document.getElementById('toast'),
    dataStatusPill: document.getElementById('dataStatusPill'),
    dataStatusLabel: document.getElementById('dataStatusLabel'),
    dataStatusModal: document.getElementById('dataStatusModal'),
    dataStatusModalBody: document.getElementById('dataStatusModalBody'),
    closeDataModalBtn: document.getElementById('closeDataModalBtn'),
  };

  let suggestionDebounce = null;
  let activeSuggestField = null; // 'origin' | 'dest'

  function showToast(message, isError = false) {
    els.toast.textContent = message;
    els.toast.hidden = false;
    els.toast.classList.toggle('error', isError);
    clearTimeout(showToast._t);
    showToast._t = setTimeout(() => { els.toast.hidden = true; }, 5000);
  }

  function fmtDistance(m) {
    return m >= 1000 ? `${(m / 1000).toFixed(1)} km` : `${Math.round(m)} m`;
  }

  function fmtDuration(s) {
    const min = Math.round(s / 60);
    if (min < 60) return `${min} min`;
    return `${Math.floor(min / 60)}h ${min % 60}min`;
  }

  function relativeTime(iso) {
    if (!iso) return 'unknown';
    const diffMs = Date.now() - new Date(iso).getTime();
    const mins = Math.round(diffMs / 60000);
    if (mins < 1) return 'just now';
    if (mins === 1) return '1 minute ago';
    if (mins < 60) return `${mins} minutes ago`;
    const hrs = Math.round(mins / 60);
    return `${hrs} hour${hrs === 1 ? '' : 's'} ago`;
  }

  function riskLabel(level) {
    return { SAFE: 'Safe', CAUTION: 'Caution', HIGH_RISK: 'High risk', AVOID: 'Avoid' }[level] || level;
  }

  /* ---------------------------------------------------------------------
   * Geolocation
   * ------------------------------------------------------------------- */
  function requestGeolocation() {
    if (!('geolocation' in navigator)) {
      showToast('Your browser does not support GPS location. Enter your starting point manually.', true);
      return;
    }
    els.useGpsBtn.textContent = '...';
    navigator.geolocation.getCurrentPosition(
      async (pos) => {
        els.useGpsBtn.textContent = 'GPS';
        const { latitude: lat, longitude: lon } = pos.coords;
        state.origin = { lat, lon, label: 'Current location' };
        FloodMap.setOrigin(lat, lon);
        FloodMap.panTo(lat, lon, 14);
        els.originInput.value = 'Current location';
        try {
          const rev = await Api.geocodeReverse(lat, lon);
          if (rev.data?.label) {
            state.origin.label = rev.data.label;
            els.originInput.value = rev.data.label;
          }
        } catch (_) { /* reverse geocode is a nicety, not required */ }
      },
      (err) => {
        els.useGpsBtn.textContent = 'GPS';
        const messages = {
          1: 'Location permission denied. Enter your starting point manually.',
          2: 'Location unavailable right now. Enter your starting point manually.',
          3: 'Location request timed out. Enter your starting point manually.',
        };
        showToast(messages[err.code] || 'Could not get your location.', true);
      },
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
    );
  }

  /* ---------------------------------------------------------------------
   * Destination / origin search suggestions
   * ------------------------------------------------------------------- */
  function wireSearchField(input, fieldName) {
    input.addEventListener('input', () => {
      activeSuggestField = fieldName;
      clearTimeout(suggestionDebounce);
      const q = input.value.trim();
      if (q.length < 3) {
        hideSuggestions();
        return;
      }
      suggestionDebounce = setTimeout(() => runSearch(q), 350);
    });
    input.addEventListener('focus', () => { activeSuggestField = fieldName; });
  }

  async function runSearch(query) {
    try {
      const res = await Api.geocodeSearch(query);
      renderSuggestions(res.data || []);
    } catch (e) {
      hideSuggestions();
    }
  }

  function renderSuggestions(results) {
    els.suggestions.innerHTML = '';
    if (!results.length) { hideSuggestions(); return; }
    results.forEach((r) => {
      const li = document.createElement('li');
      li.textContent = r.label;
      li.addEventListener('click', () => selectPlace(r));
      els.suggestions.appendChild(li);
    });
    els.suggestions.hidden = false;
  }

  function hideSuggestions() {
    els.suggestions.hidden = true;
    els.suggestions.innerHTML = '';
  }

  function selectPlace(place) {
    if (activeSuggestField === 'origin') {
      state.origin = { lat: place.lat, lon: place.lon, label: place.label };
      els.originInput.value = place.label;
      FloodMap.setOrigin(place.lat, place.lon);
    } else {
      state.destination = { lat: place.lat, lon: place.lon, label: place.label };
      els.destInput.value = place.label;
      FloodMap.setDestination(place.lat, place.lon, place.label);
    }
    hideSuggestions();
  }

  /* ---------------------------------------------------------------------
   * Route analysis
   * ------------------------------------------------------------------- */
  async function findRoutes() {
    if (!state.origin) {
      showToast('Set your starting point first (tap GPS or type an address).', true);
      return;
    }
    if (!state.destination) {
      showToast('Enter a destination.', true);
      return;
    }

    els.findRoutesBtn.disabled = true;
    els.findRoutesBtn.textContent = 'Analyzing route…';

    try {
      const result = await Api.routeAnalysis(state.origin.lat, state.origin.lon, state.destination.lat, state.destination.lon);
      state.routes = result.routes;
      state.floodData = result.flood_data;
      state.selectedRouteId = result.routes[0]?.id || null;

      FloodMap.drawFloodZones(result.flood_data?.data?.zones);
      FloodMap.drawFloodReports(result.flood_data?.data?.reports);
      FloodMap.drawRoutes(state.routes, state.selectedRouteId, onSelectRoute);

      renderResults();
      updateDataStatusPill();
    } catch (e) {
      showToast(e.message || 'Could not analyze this route.', true);
    } finally {
      els.findRoutesBtn.disabled = false;
      els.findRoutesBtn.textContent = 'Find safest route';
    }
  }

  function onSelectRoute(id) {
    state.selectedRouteId = id;
    FloodMap.drawRoutes(state.routes, state.selectedRouteId, onSelectRoute);
    renderResults();
  }

  function renderResults() {
    els.resultsPanel.hidden = false;
    els.resultsTitle.textContent = `${state.routes.length} route${state.routes.length === 1 ? '' : 's'} found`;
    els.resultsSub.textContent = `${state.origin.label || 'Your location'} → ${state.destination.label}`;

    els.routeList.innerHTML = '';
    state.routes.forEach((route, idx) => {
      const card = document.createElement('div');
      card.className = 'route-card' + (route.id === state.selectedRouteId ? ' selected' : '');
      card.addEventListener('click', () => onSelectRoute(route.id));

      card.innerHTML = `
        <div class="route-risk-chip ${route.risk.level}">${riskLabel(route.risk.level)}</div>
        <div class="route-card-main">
          <div class="route-card-title">Route ${String.fromCharCode(65 + idx)}</div>
          <div class="route-card-meta">${fmtDistance(route.distance_m)} · ${fmtDuration(route.duration_s)}</div>
          <div class="route-card-badges">
            ${route.is_fastest ? '<span class="badge fastest">Fastest</span>' : ''}
            <span class="badge">${route.risk.reasons.length} finding${route.risk.reasons.length === 1 ? '' : 's'}</span>
          </div>
        </div>
      `;
      els.routeList.appendChild(card);
    });

    renderRouteDetail();
  }

  function renderRouteDetail() {
    const route = state.routes.find((r) => r.id === state.selectedRouteId);
    if (!route) { els.routeDetail.hidden = true; return; }

    els.routeDetail.hidden = false;
    const weatherEnv = route.weather;

    els.routeDetail.innerHTML = `
      <div class="detail-risk-banner ${route.risk.level}">${riskLabel(route.risk.level).toUpperCase()}</div>
      <ul class="reasons-list">
        ${route.risk.reasons.map((r) => `<li>${escapeHtml(r)}</li>`).join('')}
      </ul>
      <div class="source-meta">
        <div><strong>Weather:</strong> ${weatherEnv.source_name || 'unknown'}
          <span class="freshness-tag ${weatherEnv.status}">${weatherEnv.status.replace('_', ' ')}</span>
          — updated ${relativeTime(weatherEnv.retrieved_at)}
        </div>
        <div><strong>Flood data:</strong> ${state.floodData?.source_name || 'unknown'}
          <span class="freshness-tag ${state.floodData?.status}">${(state.floodData?.status || '').replace('_', ' ')}</span>
          — updated ${relativeTime(state.floodData?.retrieved_at)}
        </div>
      </div>
      <button class="use-route-btn" type="button" id="useRouteBtn">Use this route</button>
    `;

    document.getElementById('useRouteBtn').addEventListener('click', () => {
      showToast('Route selected. Follow it on the map — conditions can change, so stay alert.');
    });
  }

  function escapeHtml(s) {
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
  }

  /* ---------------------------------------------------------------------
   * Data status pill + modal
   * ------------------------------------------------------------------- */
  function updateDataStatusPill() {
    const statuses = [
      ...(state.routes[0]?.weather ? [state.routes[0].weather.status] : []),
      ...(state.floodData ? [state.floodData.status] : []),
    ];
    let cls = 'ok', label = 'Live data';
    if (statuses.includes('UNAVAILABLE')) { cls = 'down'; label = 'Data issues'; }
    else if (statuses.some((s) => s === 'DEMO' || s === 'STALE')) { cls = 'mixed'; label = 'Demo data in use'; }

    els.dataStatusPill.className = `data-status-pill ${cls}`;
    els.dataStatusLabel.textContent = label;

    els.dataStatusModalBody.innerHTML = `
      <div class="modal-row"><span>Routing</span><span class="freshness-tag REAL_TIME">REAL TIME</span></div>
      <div class="modal-row"><span>Weather</span><span class="freshness-tag ${state.routes[0]?.weather.status || 'UNAVAILABLE'}">${(state.routes[0]?.weather.status || 'UNAVAILABLE').replace('_', ' ')}</span></div>
      <div class="modal-row"><span>Flood zones &amp; reports</span><span class="freshness-tag ${state.floodData?.status || 'UNAVAILABLE'}">${(state.floodData?.status || 'UNAVAILABLE').replace('_', ' ')}</span></div>
    `;
  }

  /* ---------------------------------------------------------------------
   * Wiring
   * ------------------------------------------------------------------- */
  function init() {
    FloodMap.init();
    wireSearchField(els.originInput, 'origin');
    wireSearchField(els.destInput, 'dest');

    els.useGpsBtn.addEventListener('click', requestGeolocation);
    els.recenterBtn.addEventListener('click', () => {
      if (state.origin) FloodMap.panTo(state.origin.lat, state.origin.lon, 15);
      else requestGeolocation();
    });
    els.findRoutesBtn.addEventListener('click', findRoutes);
    els.closeResultsBtn.addEventListener('click', () => { els.resultsPanel.hidden = true; });

    wireBottomSheetDrag();

    els.dataStatusPill.addEventListener('click', () => { els.dataStatusModal.hidden = false; });
    els.closeDataModalBtn.addEventListener('click', () => { els.dataStatusModal.hidden = true; });
    els.dataStatusModal.addEventListener('click', (e) => { if (e.target === els.dataStatusModal) els.dataStatusModal.hidden = true; });

    document.addEventListener('click', (e) => {
      if (!els.suggestions.contains(e.target) && e.target !== els.originInput && e.target !== els.destInput) {
        hideSuggestions();
      }
    });

    // Try to get GPS location on load so the origin field is pre-filled,
    // but don't block the UI or nag if the user declines.
    requestGeolocation();

    // Periodically refresh the "updated X ago" text without re-fetching data.
    setInterval(() => { if (!els.routeDetail.hidden) renderRouteDetail(); }, 30000);
  }

  /* ---------------------------------------------------------------------
   * Mobile bottom-sheet drag (collapsed / half / full) — no-op on desktop
   * since the panel is a fixed sidebar there (CSS media query handles it).
   * ------------------------------------------------------------------- */
  function wireBottomSheetDrag() {
    const handle = document.getElementById('sheetHandle');
    const panel = els.resultsPanel;
    const HEIGHTS = { collapsed: 96, half: Math.round(window.innerHeight * 0.45), full: Math.round(window.innerHeight * 0.78) };
    let dragging = false;
    let startY = 0;
    let startHeight = 0;

    function isMobile() { return window.matchMedia('(max-width: 859px)').matches; }
    function setHeight(px) {
      panel.style.maxHeight = `${px}px`;
      panel.style.height = `${px}px`;
    }

    function onStart(clientY) {
      if (!isMobile()) return;
      dragging = true;
      startY = clientY;
      startHeight = panel.getBoundingClientRect().height;
    }
    function onMove(clientY) {
      if (!dragging) return;
      const delta = startY - clientY;
      const next = Math.min(HEIGHTS.full, Math.max(60, startHeight + delta));
      setHeight(next);
    }
    function onEnd() {
      if (!dragging) return;
      dragging = false;
      const h = panel.getBoundingClientRect().height;
      // Snap to the nearest of three stops.
      const stops = [HEIGHTS.collapsed, HEIGHTS.half, HEIGHTS.full];
      const nearest = stops.reduce((a, b) => (Math.abs(b - h) < Math.abs(a - h) ? b : a));
      setHeight(nearest);
      if (nearest === HEIGHTS.collapsed) {
        // Let the header remain visible/tappable to re-expand.
        panel.classList.add('collapsed');
      } else {
        panel.classList.remove('collapsed');
      }
    }

    handle.addEventListener('touchstart', (e) => onStart(e.touches[0].clientY), { passive: true });
    handle.addEventListener('touchmove', (e) => onMove(e.touches[0].clientY), { passive: true });
    handle.addEventListener('touchend', onEnd);

    handle.addEventListener('mousedown', (e) => { onStart(e.clientY); e.preventDefault(); });
    window.addEventListener('mousemove', (e) => onMove(e.clientY));
    window.addEventListener('mouseup', onEnd);

    handle.addEventListener('click', () => {
      if (!isMobile()) return;
      const h = panel.getBoundingClientRect().height;
      setHeight(h < HEIGHTS.half ? HEIGHTS.half : HEIGHTS.collapsed);
    });
  }

  document.addEventListener('DOMContentLoaded', init);
})();
