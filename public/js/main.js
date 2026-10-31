// Builds the dashboard from the layout in the server's settings file.
// Widget contract: create(el, cfg, ctx) returns { refresh(): Promise<void> }.
import { getJson } from './api.js';
import { bus } from './bus.js';
import { createPrefs } from './prefs.js';
import { formatAge, isStale } from './format.js';
import * as sensor from './widgets/sensor.js';
import * as graph from './widgets/graph.js';
import * as placeholder from './widgets/placeholder.js';

const registry = { sensor, graph, placeholder };

async function loadConfig() {
  for (;;) {
    try {
      return await getJson('api/config');
    } catch (e) {
      document.getElementById('grid').textContent = `Cannot reach the server (${e.message}). Retrying…`;
      await new Promise((resolve) => setTimeout(resolve, 10000));
    }
  }
}

async function showCollector(config) {
  const box = document.getElementById('collector');
  try {
    const data = await getJson('api/sensors');
    const seen = data.collector_last_seen;
    box.textContent = seen === null ? 'collector: never' : `collector: ${formatAge(data.now - seen)}`;
    box.classList.toggle('stale', isStale(seen, data.now, config.stale_after_seconds));
  } catch (e) {
    box.textContent = 'collector: unknown';
  }
}

async function start() {
  const config = await loadConfig();
  const layout = config.layout;
  const graphCfg = layout.widgets.find((w) => w.type === 'graph');
  const prefs = createPrefs(window.localStorage, {
    graphSensors: graphCfg && graphCfg.sensors ? graphCfg.sensors : [],
    range: 24,
  });
  const ctx = { prefs, bus, config };

  const grid = document.getElementById('grid');
  grid.textContent = '';
  grid.style.gridTemplateColumns = `repeat(${layout.columns}, minmax(0, 1fr))`;
  grid.style.gridTemplateRows = (layout.rows || []).join(' ');

  const widgets = layout.widgets.map((cfg) => {
    const el = document.createElement('section');
    const type = registry[cfg.type] ? cfg.type : 'placeholder';
    el.className = `w w-${type}`;
    el.style.gridColumn = `${cfg.col} / span ${cfg.colSpan || 1}`;
    el.style.gridRow = `${cfg.row} / span ${cfg.rowSpan || 1}`;
    grid.appendChild(el);
    const settings = registry[cfg.type] ? cfg : { ...cfg, label: `unknown widget: ${cfg.type}` };
    return registry[type].create(el, settings, ctx);
  });

  // One request at a time: krpi's PHP server handles requests one by one.
  async function refreshAll() {
    for (const w of widgets) {
      try { await w.refresh(); } catch (e) { /* each widget shows its own error */ }
    }
    await showCollector(config);
  }
  await refreshAll();
  setInterval(refreshAll, config.refresh_seconds * 1000);
}

start();
