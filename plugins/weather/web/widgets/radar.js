// Rain radar around the place, on an OpenStreetMap base map. It loops
// through the measured frames of the last hour, holds on the newest one,
// and then plays the 2-hour forecast. A time line in the header shows
// where the shown frame is: 1 hour ago, now, 2 hours ahead.
import { getJson } from '../../../js/api.js';
import { formatClock } from '../../../js/format.js';
import {
  fitZoom, project, tilesFor, decodeRle, radarColor, OUTSIDE, timeline, linePosition,
} from '../map.js';

const TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const FRAME_MS = 500;
const NEWEST_MS = 2500;
const LAST_MS = 1200;
const DOT_PX = 14; // .tl-dot width in dashboard.css
// The newest measured frame is normally 6 to 11 minutes old.
const STALE_SECONDS = 20 * 60;
// Forecast rain is drawn lighter than measured rain.
const MEASURED_ALPHA = 0.8;
const FORECAST_ALPHA = 0.55;

// A frame of the list is { time } when measured, { time, run } when forecast.
const frameKey = (f) => (f.run ? `${f.time}-f${f.run}` : String(f.time));

export function create(el, cfg, ctx) {
  el.innerHTML = `
    <header>
      <span class="name">Radar</span>
      <div class="tl">
        <span class="tl-from"></span>
        <div class="tl-seg tl-past"></div>
        <span class="tl-now"></span>
        <div class="tl-seg tl-future"></div>
        <span class="tl-to"></span>
      </div>
    </header>
    <div class="radar-stale stale-text"></div>
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
  const past = el.querySelector('.tl-past');
  const future = el.querySelector('.tl-future');
  const dot = document.createElement('span');
  dot.className = 'tl-dot';
  const staleEl = el.querySelector('.radar-stale');
  const nowEl = el.querySelector('.tl-now');

  let view = null;
  let grid = null;
  let sequence = []; // frames that are loaded, in time order
  let newestMeasured = -1; // index in sequence
  const cells = new Map(); // frame key => Uint8Array
  let shown = -1;
  let timer = null;
  let line = timeline(0);

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

  function draw(frameCells, alpha) {
    const g = canvas.getContext('2d');
    g.clearRect(0, 0, canvas.width, canvas.height);
    if (!frameCells) return;
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
    for (let r = 0; r < grid.rows; r++) {
      if (ys[r + 1] < 0 || ys[r] > canvas.height) continue;
      for (let c = 0; c < grid.cols; c++) {
        const raw = frameCells[r * grid.cols + c];
        const color = raw === OUTSIDE ? '#8a939c' : radarColor(raw);
        if (!color) continue;
        g.fillStyle = color;
        g.globalAlpha = raw === OUTSIDE ? 0.25 : alpha;
        g.fillRect(xs[c], ys[r], xs[c + 1] - xs[c], ys[r + 1] - ys[r]);
      }
    }
  }

  function drawLine() {
    // The segments' lengths follow their time spans.
    past.style.flexGrow = String(line.now - line.from);
    future.style.flexGrow = String(line.to - line.now);
    el.querySelector('.tl-from').textContent = formatClock(line.from);
    nowEl.textContent = formatClock(line.now);
    el.querySelector('.tl-to').textContent = formatClock(line.to);
  }

  function show(index) {
    shown = index;
    const frame = sequence[index];
    draw(cells.get(frameKey(frame)), frame.run ? FORECAST_ALPHA : MEASURED_ALPHA);
    // The newest measured frame is "now": the current time is highlighted
    // instead of a dot.
    const current = index === newestMeasured;
    nowEl.classList.toggle('on', current);
    dot.style.display = current ? 'none' : '';
    const pos = linePosition(frame.time, line);
    const segment = pos.future ? future : past;
    if (dot.parentNode !== segment) segment.appendChild(dot);
    // Kept inside the segment: at 0 the dot's left edge is at the start,
    // at 1 its right edge is at the end.
    dot.style.left = `calc(${pos.at * 100}% - ${pos.at * DOT_PX}px)`;
    dot.classList.toggle('forecast', pos.future);
  }

  function step() {
    timer = null;
    if (!sequence.length) return;
    const next = (shown + 1) % sequence.length;
    show(next);
    let wait = FRAME_MS;
    if (next === newestMeasured) wait = NEWEST_MS;
    else if (next === sequence.length - 1) wait = LAST_MS;
    timer = setTimeout(step, wait);
  }

  async function refresh() {
    try {
      const list = await getJson('api/weather/radar');
      line = timeline(list.now, list.frames.length ? list.frames[0].time : undefined);
      drawLine();
      if (!layout()) return;
      if (!list.grid) {
        sequence = [];
        draw(null);
        staleEl.textContent = 'no radar data';
        return;
      }
      grid = list.grid;
      const size = grid.rows * grid.cols;
      for (const frame of list.frames) {
        const key = frameKey(frame);
        if (cells.has(key)) continue;
        const run = frame.run ? `&run=${frame.run}` : '';
        const data = await getJson(`api/weather/radar-frame?time=${frame.time}${run}`);
        cells.set(key, decodeRle(data.rle, size));
      }
      const keys = list.frames.map(frameKey);
      for (const key of cells.keys()) {
        if (!keys.includes(key)) cells.delete(key);
      }
      sequence = list.frames.filter((f) => cells.has(frameKey(f)));
      newestMeasured = -1;
      sequence.forEach((f, i) => { if (!f.run) newestMeasured = i; });
      const newest = sequence[newestMeasured];
      staleEl.textContent = !newest || list.now - newest.time > STALE_SECONDS
        ? `no radar since ${newest ? formatClock(newest.time) : '–'}`
        : '';
      if (shown >= sequence.length) shown = -1;
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
