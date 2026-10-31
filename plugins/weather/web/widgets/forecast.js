// Precipitation forecast for the next 24 hours: one column per 3 hours.
// The column height is the rain in mm, the icon above it the weather, and
// the line under it the start hour and the chance of rain. The hourly
// temperature is a curve over the columns, labelled once per block.
import { getJson } from '../../../js/api.js';
import { formatTemp, formatClock } from '../../../js/format.js';
import {
  weatherIcon, scaleMax, barPercent, formatMm, formatProbability, probabilityClass, smoothPath,
} from '../weather.js';

const SVG = 'http://www.w3.org/2000/svg';
const HOUR = 3600;

export function create(el, cfg, ctx) {
  el.innerHTML = `
    <header>
      <span class="name"></span>
      <span class="fc-updated"></span>
      <span class="fc-now"></span>
      <span class="btns"><button class="btn refresh-btn" title="Refresh">⟳</button></span>
    </header>
    <div class="fc-cols"></div>
    <div class="error"></div>`;
  el.querySelector('.name').textContent = ctx.config.place || 'Weather';
  const refreshBtn = el.querySelector('.refresh-btn');

  function render(data) {
    const now = data.current;
    el.querySelector('.fc-now').textContent = now.temperature_c === null
      ? ''
      : `${weatherIcon(now.code, now.is_day)} ${formatTemp(now.temperature_c)}°`;

    const max = scaleMax(data.blocks);
    const cols = el.querySelector('.fc-cols');
    cols.textContent = '';
    for (const b of data.blocks) {
      const col = document.createElement('div');
      col.className = 'fc-col';
      col.innerHTML = `
        <div class="fc-icon"></div>
        <div class="fc-bar-area"><div class="fc-mm"></div><div class="fc-bar"></div></div>
        <div class="fc-hour"></div>
        <div class="fc-prob"></div>`;
      col.querySelector('.fc-icon').textContent = weatherIcon(b.code, b.is_day);
      col.querySelector('.fc-mm').textContent = formatMm(b.mm);
      col.querySelector('.fc-bar').style.height = `${barPercent(b.mm, max)}%`;
      col.querySelector('.fc-hour').textContent = `${String(b.hour).padStart(2, '0')}h`;
      const prob = col.querySelector('.fc-prob');
      prob.textContent = formatProbability(b.probability);
      prob.classList.add(probabilityClass(b.probability));
      cols.appendChild(col);
    }
    drawTemperatures(cols, data);

    const updated = el.querySelector('.fc-updated');
    updated.textContent = data.stale
      ? `offline, from ${formatClock(data.fetched)}`
      : `${formatClock(data.fetched)} · Open-Meteo`;
    updated.classList.toggle('stale-text', data.stale);
  }

  // The curve sits over the bar areas, in their upper part, so it stays
  // clear of light rain. Each block is 3 hours wide; an hour's point is at
  // the middle of its hour.
  function drawTemperatures(cols, data) {
    const temps = data.temperatures || [];
    const area = cols.querySelector('.fc-bar-area');
    if (!temps.length || !area) return;
    const width = cols.clientWidth;
    const top = area.offsetTop;
    const height = area.offsetHeight;
    const first = data.blocks[0].start;
    const span = data.blocks.length * 3 * HOUR;
    const values = temps.map((t) => t.c);
    const min = Math.min(...values);
    const range = Math.max(4, Math.max(...values) - min);
    const x = (ts) => ((ts + HOUR / 2 - first) / span) * width;
    const y = (c) => top + 14 + (1 - (c - min) / range) * Math.max(0, height * 0.55 - 14);

    const svg = document.createElementNS(SVG, 'svg');
    svg.setAttribute('class', 'fc-temp');
    svg.setAttribute('width', width);
    svg.setAttribute('height', cols.clientHeight);
    const path = document.createElementNS(SVG, 'path');
    path.setAttribute('d', smoothPath(temps.map((t) => ({ x: x(t.ts), y: y(t.c) }))));
    svg.appendChild(path);
    // One label per block, at its middle hour.
    for (const b of data.blocks) {
      const t = temps.find((p) => p.ts === b.start + HOUR);
      if (!t) continue;
      const label = document.createElementNS(SVG, 'text');
      label.setAttribute('x', x(t.ts).toFixed(1));
      label.setAttribute('y', (y(t.c) - 5).toFixed(1));
      label.textContent = `${Math.round(t.c)}°`;
      svg.appendChild(label);
    }
    cols.appendChild(svg);
  }

  async function refresh() {
    refreshBtn.classList.add('busy');
    try {
      render(await getJson('api/weather/forecast'));
      el.querySelector('.error').textContent = '';
    } catch (e) {
      el.querySelector('.error').textContent = `cannot load (${e.message})`;
    } finally {
      refreshBtn.classList.remove('busy');
    }
  }

  refreshBtn.addEventListener('click', refresh);
  return { refresh };
}
