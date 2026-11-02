// Builds the dashboard from the layout in the server's settings file.
// Widget contract: create(el, cfg, ctx) returns { refresh(): Promise<void> },
// where ctx = { config: <the plugin's public config>, layout, bus }.
// A plugin may also have web/status.js with status(el, ctx); its text goes
// into the footer.
import { getJson } from './api.js';
import { bus } from './bus.js';
import { widgetSource, widgetClass } from './widgets.js';
import * as placeholder from './widgets/placeholder.js';
import { startBattery } from './battery.js';

const core = { placeholder };

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

// Returns the widget module, or null when the type is unknown or its
// file does not load.
async function loadWidget(type, pluginNames) {
  const source = widgetSource(type, pluginNames);
  if (!source) return null;
  if (source.core) return core[source.core];
  try {
    return await import(source.url);
  } catch (e) {
    console.error(`widget ${type}:`, e);
    return null;
  }
}

// status.js is optional, so a plugin without one is skipped quietly.
async function loadStatus(plugin) {
  try {
    const mod = await import(`../plugins/${plugin}/status.js`);
    return typeof mod.status === 'function' ? mod.status : null;
  } catch (e) {
    return null;
  }
}

async function start() {
  const config = await loadConfig();
  const layout = config.layout;
  const pluginNames = Object.keys(config.plugins);
  const ctxFor = (plugin) => ({ config: config.plugins[plugin] || {}, layout, bus });

  const grid = document.getElementById('grid');
  grid.textContent = '';
  grid.style.gridTemplateColumns = `repeat(${layout.columns}, minmax(0, 1fr))`;
  grid.style.gridTemplateRows = (layout.rows || []).join(' ');

  const widgets = [];
  for (const cfg of layout.widgets) {
    const el = document.createElement('section');
    el.style.gridColumn = `${cfg.col} / span ${cfg.colSpan || 1}`;
    el.style.gridRow = `${cfg.row} / span ${cfg.rowSpan || 1}`;
    grid.appendChild(el);
    const mod = await loadWidget(cfg.type, pluginNames);
    const plugin = String(cfg.type).includes('.') ? cfg.type.split('.')[0] : null;
    if (mod) {
      el.className = `w ${widgetClass(cfg.type)}`;
      widgets.push(mod.create(el, cfg, ctxFor(plugin)));
    } else {
      el.className = 'w w-placeholder';
      widgets.push(placeholder.create(el, { ...cfg, label: `unknown widget: ${cfg.type}` }));
    }
  }

  const footer = document.getElementById('status');
  const statuses = [];
  for (const plugin of pluginNames) {
    const status = await loadStatus(plugin);
    if (!status) continue;
    const el = document.createElement('span');
    footer.appendChild(el);
    statuses.push(() => status(el, ctxFor(plugin)));
  }

  // One request at a time: krpi's PHP server handles requests one by one.
  async function refreshAll() {
    for (const w of widgets) {
      try { await w.refresh(); } catch (e) { /* each widget shows its own error */ }
    }
    for (const s of statuses) {
      try { await s(); } catch (e) { /* the status shows its own error */ }
    }
  }
  await refreshAll();
  setInterval(refreshAll, config.refresh_seconds * 1000);
}

startBattery(document.getElementById('battery'));
start();
