/**
 * Leaflet map wrapper. Keeps all map-specific code (layers, styling,
 * marker creation) out of app.js so app.js can stay focused on
 * orchestration/state.
 */
const RISK_COLORS = {
  SAFE: '#2FBF8F',
  CAUTION: '#F5B700',
  HIGH_RISK: '#FF7A33',
  AVOID: '#E4483B',
};

const FloodMap = (() => {
  let map;
  let originMarker = null;
  let destMarker = null;
  let routeLayers = []; // one polyline per candidate route
  let zoneLayer = null;
  let reportLayer = null;

  // Default view: Metro Manila, since that's where the demo flood data lives.
  const DEFAULT_CENTER = [14.6091, 121.0223];
  const DEFAULT_ZOOM = 12;

  function init() {
    map = L.map('map', { zoomControl: true, attributionControl: true })
      .setView(DEFAULT_CENTER, DEFAULT_ZOOM);

    // Dark basemap tiles (CARTO dark matter, free for reasonable use with
    // attribution). Swap the tile URL for your own tile provider in
    // production if you expect meaningful traffic.
    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
      subdomains: 'abcd',
      maxZoom: 19,
    }).addTo(map);

    L.control.zoom({ position: 'bottomright' }).remove(); // we use our own FAB for recenter; keep chrome minimal

    return map;
  }

  function setOrigin(lat, lon) {
    if (originMarker) map.removeLayer(originMarker);
    const icon = L.divIcon({ className: '', html: '<div class="gps-dot"></div>', iconSize: [16, 16] });
    originMarker = L.marker([lat, lon], { icon, zIndexOffset: 500 }).addTo(map);
  }

  function setDestination(lat, lon, label) {
    if (destMarker) map.removeLayer(destMarker);
    destMarker = L.marker([lat, lon], { title: label || '' }).addTo(map);
  }

  function clearRoutes() {
    routeLayers.forEach((l) => map.removeLayer(l));
    routeLayers = [];
  }

  /**
   * @param {Array<{id:string, geometry:number[][], risk:{level:string}}>} routes
   * @param {string} selectedId
   * @param {(id:string)=>void} onSelect
   */
  function drawRoutes(routes, selectedId, onSelect) {
    clearRoutes();
    // Draw non-selected routes first (dimmer), selected route last (on top).
    const ordered = [...routes].sort((a, b) => (a.id === selectedId ? 1 : 0) - (b.id === selectedId ? 1 : 0));

    ordered.forEach((route) => {
      const isSelected = route.id === selectedId;
      const color = RISK_COLORS[route.risk.level] || '#3FC7E0';
      const line = L.polyline(route.geometry, {
        color,
        weight: isSelected ? 6 : 4,
        opacity: isSelected ? 0.95 : 0.45,
        lineCap: 'round',
        lineJoin: 'round',
      }).addTo(map);
      line.on('click', () => onSelect(route.id));
      routeLayers.push(line);
    });

    if (ordered.length) {
      const bounds = L.latLngBounds(ordered.flatMap((r) => r.geometry));
      map.fitBounds(bounds, { padding: [60, 60] });
    }
  }

  /** @param {GeoJSON.FeatureCollection} zonesGeoJson */
  function drawFloodZones(zonesGeoJson) {
    if (zoneLayer) map.removeLayer(zoneLayer);
    if (!zonesGeoJson) return;
    zoneLayer = L.geoJSON(zonesGeoJson, {
      style: (feature) => ({
        color: '#FF7A33',
        weight: 1.5,
        fillColor: '#FF7A33',
        fillOpacity: feature.properties.risk_level === 'high' ? 0.22 : 0.13,
        dashArray: '4,3',
      }),
      onEachFeature: (feature, layer) => {
        layer.bindPopup(
          `<strong>${feature.properties.name}</strong><br/>${feature.properties.typical_trigger || ''}`
        );
      },
    }).addTo(map);
  }

  /** @param {Array<{lat:number, lon:number, severity:string, description:string, age_minutes:number}>} reports */
  function drawFloodReports(reports) {
    if (reportLayer) map.removeLayer(reportLayer);
    reportLayer = L.layerGroup();
    (reports || []).forEach((r) => {
      const icon = L.divIcon({ className: '', html: '<div class="flood-report-marker"></div>', iconSize: [14, 14] });
      const marker = L.marker([r.lat, r.lon], { icon });
      marker.bindPopup(
        `<strong>${r.severity.toUpperCase()} flood report</strong><br/>${r.description}<br/><em>${r.age_minutes} min ago (demo data)</em>`
      );
      reportLayer.addLayer(marker);
    });
    reportLayer.addTo(map);
  }

  function panTo(lat, lon, zoom) {
    map.setView([lat, lon], zoom || map.getZoom());
  }

  function getMap() {
    return map;
  }

  return { init, setOrigin, setDestination, clearRoutes, drawRoutes, drawFloodZones, drawFloodReports, panTo, getMap };
})();
