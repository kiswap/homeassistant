// Rain radar around the place, on an OpenStreetMap base map. It loops
// through the frames of the last hour and holds on the newest one.
import { getJson } from '../../../js/api.js';
import { formatClock } from '../../../js/format.js';
import { fitZoom, project, tilesFor, decodeRle, radarColor, OUTSIDE } from '../map.js';

const TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const FRAME_MS = 500;
const NEWEST_MS = 2500;
// The newest frame is normally 6 to 11 minutes old.
const STALE_SECONDS = 20 * 60;

export function create(el, cfg, ctx) {
  el.innerHTML = `
    <header>
      <span class="name">Radar</span>
      <span class="radar-time"></span>
    </header>
    <div class="map">
      <div class="map-tiles"></div>
      <canvas class="map-radar"></canvas>
      <div class="map-attrib">© OpenStreetMap · HungaroMet</div>
    </div>
    <div class="error"></div>`;
  const place = ctx.config;
  const mapEl = el.querySelector('.map');
  const tilesEl = el.querySelector('.map-tiles');
  const canvas = el.querySelector('canvas');
  const timeEl = el.querySelector('.radar-time');

  let view = null;
  let grid = null;
  let times = [];
  const frames = new Map(); // time => Uint8Array of cells
  let shown = -1;
  let timer = null;
  let now = 0;

  // Builds the base map for the current box size. Returns false while the
  // box has no size yet.
  function layout() {
    const width = mapEl.clientWidth;
    const height = mapEl.clientHeight;
    if (!width || !height) return false;
    if (view && view.width === width && view.height === height) return true;
    const zoom = fitZoom(place.radius_km, place.latitude, Math.min(width, height));
    view = { lat: place.latitude, lon: place.longitude, zoom, width, height };
    canvas.width = width;
    canvas.height = height;
    tilesEl.textContent = '';
    for (const t of tilesFor(view, Math.round(zoom))) {
      const img = document.createElement('img');
      img.src = TILE_URL.replace('{z}', t.z).replace('{x}', t.x).replace('{y}', t.y);
      img.style.left = `${t.left}px`;
      img.style.top = `${t.top}px`;
      img.style.width = `${t.width}px`;
      img.style.height = `${t.height}px`;
      tilesEl.appendChild(img);
    }
    return true;
  }

  function draw(cells) {
    const g = canvas.getContext('2d');
    g.clearRect(0, 0, canvas.width, canvas.height);
    if (!cells) return;
    // Rows are latitude lines and columns longitude lines, so they stay
    // straight on the map; only their spacing changes.
    const ys = [];
    for (let r = 0; r <= grid.rows; r++) {
      ys.push(Math.round(project(view, grid.north + grid.dlat / 2 - r * grid.dlat, place.longitude).y));
    }
    const xs = [];
    for (let c = 0; c <= grid.cols; c++) {
      xs.push(Math.round(project(view, place.latitude, grid.west - grid.dlon / 2 + c * grid.dlon).x));
    }
    g.globalAlpha = 0.8;
    for (let r = 0; r < grid.rows; r++) {
      if (ys[r + 1] < 0 || ys[r] > canvas.height) continue;
      for (let c = 0; c < grid.cols; c++) {
        const raw = cells[r * grid.cols + c];
        const color = raw === OUTSIDE ? '#8a939c' : radarColor(raw);
        if (!color) continue;
        g.fillStyle = color;
        g.globalAlpha = raw === OUTSIDE ? 0.25 : 0.8;
        g.fillRect(xs[c], ys[r], xs[c + 1] - xs[c], ys[r + 1] - ys[r]);
      }
    }
  }

  function show(index) {
    shown = index;
    const time = times[index];
    draw(frames.get(time));
    const newest = index === times.length - 1;
    const stale = newest && now - time > STALE_SECONDS;
    timeEl.textContent = stale ? `no radar since ${formatClock(time)}` : formatClock(time);
    timeEl.classList.toggle('old', !newest);
    timeEl.classList.toggle('stale-text', stale);
  }

  function step() {
    timer = null;
    if (!times.length) return;
    const next = (shown + 1) % times.length;
    show(next);
    timer = setTimeout(step, next === times.length - 1 ? NEWEST_MS : FRAME_MS);
  }

  async function refresh() {
    try {
      const list = await getJson('api/weather/radar');
      now = list.now;
      if (!layout()) return;
      if (!list.grid) {
        times = [];
        draw(null);
        timeEl.textContent = 'no radar data';
        return;
      }
      grid = list.grid;
      const size = grid.rows * grid.cols;
      for (const time of list.frames) {
        if (frames.has(time)) continue;
        const frame = await getJson(`api/weather/radar-frame?time=${time}`);
        frames.set(time, decodeRle(frame.rle, size));
      }
      for (const time of frames.keys()) {
        if (!list.frames.includes(time)) frames.delete(time);
      }
      times = list.frames.filter((t) => frames.has(t));
      if (timer === null) {
        shown = -1;
        step();
      }
      el.querySelector('.error').textContent = '';
    } catch (e) {
      el.querySelector('.error').textContent = `cannot load (${e.message})`;
    }
  }

  return { refresh };
}
