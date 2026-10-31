// Precipitation forecast for the next 24 hours: one column per 3 hours.
// The column height is the rain in mm, the icon above it the weather, and
// the line under it the start hour and the chance of rain.
import { getJson } from '../../../js/api.js';
import { formatTemp, formatClock } from '../../../js/format.js';
import {
  weatherIcon, scaleMax, barPercent, formatMm, formatProbability, probabilityClass,
} from '../weather.js';

export function create(el, cfg, ctx) {
  el.innerHTML = `
    <header>
      <span class="name"></span>
      <span class="fc-now"></span>
      <span class="btns"><button class="btn refresh-btn" title="Refresh">⟳</button></span>
    </header>
    <div class="fc-cols"></div>
    <div class="meta"></div>
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

    const meta = el.querySelector('.meta');
    meta.textContent = data.stale
      ? `offline, forecast from ${formatClock(data.fetched)}`
      : `updated ${formatClock(data.fetched)} · Open-Meteo`;
    meta.classList.toggle('stale-text', data.stale);
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
