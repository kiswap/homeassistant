// Temperature (solid) and humidity (dashed) over 24 h, 2 d, or 1 w.
import { getJson } from '../../../js/api.js';
import { renderChart } from '../../../js/chart.js';
import { getPrefs } from '../prefs.js';

const RANGES = [[24, '24h'], [48, '2d'], [168, '1w']];
const PALETTE = ['#d93025', '#188038', '#9334e6', '#e37400', '#00838f'];

export function create(el, cfg, ctx) {
  const prefs = getPrefs(ctx.layout);
  const sensors = ctx.config.sensors;
  const colors = Object.fromEntries(sensors.map((s, i) => [s.id, PALETTE[i % PALETTE.length]]));
  el.innerHTML = `
    <header>
      <span>${cfg.title ? '' : 'Temperature & humidity'}</span>
      <span class="btns">
        <span class="range"></span>
        <button class="btn refresh-btn" title="Refresh">⟳</button>
      </span>
    </header>
    <div class="legend"></div>
    <div class="chart"></div>
    <div class="error"></div>`;
  if (cfg.title) el.querySelector('header span').textContent = cfg.title;

  const rangeBox = el.querySelector('.range');
  const legend = el.querySelector('.legend');
  const chart = el.querySelector('.chart');
  const refreshBtn = el.querySelector('.refresh-btn');
  let last = null;

  for (const [hours, label] of RANGES) {
    const b = document.createElement('button');
    b.className = 'btn';
    b.textContent = label;
    b.dataset.hours = hours;
    b.addEventListener('click', () => {
      prefs.setRange(hours);
      showRange();
      refresh();
    });
    rangeBox.appendChild(b);
  }
  function showRange() {
    for (const b of rangeBox.children) b.classList.toggle('on', Number(b.dataset.hours) === prefs.range());
  }
  showRange();

  // Graph sensors in config order, so colors and legend stay stable.
  const selected = () => sensors.map((s) => s.id).filter((id) => prefs.graphSensors().includes(id));

  function draw() {
    legend.innerHTML = '';
    if (!last || last.series.length === 0) {
      chart.innerHTML = '<div class="empty">Tap 📈 on a sensor to show it here.</div>';
      return;
    }
    for (const s of last.series) {
      const item = document.createElement('span');
      item.innerHTML = '<i></i>';
      item.firstChild.style.color = colors[s.id];
      item.append(sensors.find((x) => x.id === s.id).name);
      legend.appendChild(item);
    }
    const key = document.createElement('span');
    key.innerHTML = '<i></i>°C &nbsp; <i class="dashed"></i>%';
    legend.appendChild(key);
    chart.innerHTML = renderChart({
      series: last.series,
      from: last.from,
      to: last.to,
      bucketSeconds: last.bucket_seconds,
      colors,
      width: Math.max(200, chart.clientWidth),
      height: Math.max(120, chart.clientHeight),
    });
  }

  async function refresh() {
    const ids = selected();
    refreshBtn.classList.add('busy');
    try {
      last = ids.length === 0
        ? { series: [] }
        : await getJson(`api/thermobeacon/history?sensors=${ids.map(encodeURIComponent).join(',')}&hours=${prefs.range()}`);
      el.querySelector('.error').textContent = '';
      draw();
    } catch (e) {
      el.querySelector('.error').textContent = `cannot load (${e.message})`;
    } finally {
      refreshBtn.classList.remove('busy');
    }
  }

  refreshBtn.addEventListener('click', refresh);
  ctx.bus.addEventListener('graph-sensors-changed', refresh);
  let resizeTimer = null;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(draw, 200);
  });
  return { refresh };
}
