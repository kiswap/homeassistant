// One sensor: big temperature, smaller humidity, faded icon behind them.
import { getJson } from '../../../js/api.js';
import { formatTemp, formatHum, formatAge, formatClock, isStale } from '../../../js/format.js';
import { getPrefs } from '../prefs.js';

// Placeholder icons; the user will choose final ones later.
const ICONS = { room: '🛋️', kitchen: '🍳', tree: '🌳' };

export function create(el, cfg, ctx) {
  const prefs = getPrefs(ctx.layout);
  const info = ctx.config.sensors.find((s) => s.id === cfg.sensor) || { id: cfg.sensor, name: cfg.sensor, icon: '' };
  el.innerHTML = `
    <div class="bg-icon"></div>
    <header>
      <span class="name"></span>
      <span class="btns">
        <button class="btn graph-btn" title="Show in graph">📈</button>
        <button class="btn refresh-btn" title="Refresh">⟳</button>
      </span>
    </header>
    <div class="temp"><span class="v">–</span><small>°C</small></div>
    <div class="hum"><span class="v">–</span><small>%</small></div>
    <div class="meta"></div>
    <div class="error"></div>`;
  el.querySelector('.bg-icon').textContent = ICONS[info.icon] || '';
  el.querySelector('.name').textContent = info.name;

  const graphBtn = el.querySelector('.graph-btn');
  const refreshBtn = el.querySelector('.refresh-btn');
  const showGraphState = () => graphBtn.classList.toggle('on', prefs.graphSensors().includes(info.id));
  showGraphState();

  graphBtn.addEventListener('click', () => {
    prefs.toggleGraphSensor(info.id);
    showGraphState();
    ctx.bus.dispatchEvent(new Event('graph-sensors-changed'));
  });
  ctx.bus.addEventListener('graph-sensors-changed', showGraphState);

  async function refresh() {
    refreshBtn.classList.add('busy');
    try {
      const data = await getJson(`api/thermobeacon/sensors?id=${encodeURIComponent(info.id)}`);
      const latest = data.sensors[0].latest;
      const stale = isStale(latest && latest.ts, data.now, ctx.config.stale_after_seconds);
      el.classList.toggle('stale', stale);
      el.querySelector('.temp .v').textContent = formatTemp(latest && latest.temperature_c);
      el.querySelector('.hum .v').textContent = formatHum(latest && latest.humidity_pct);
      let meta;
      if (!latest) meta = 'no data';
      else if (stale) meta = `no data since ${formatClock(latest.ts)}`;
      else meta = `${formatAge(data.now - latest.ts)} · battery ${latest.battery_pct}%`;
      el.querySelector('.meta').textContent = meta;
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
