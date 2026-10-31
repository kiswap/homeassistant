// The footer line "collector: 2 min ago": when the tablet app last uploaded.
import { getJson } from '../../js/api.js';
import { formatAge, isStale } from '../../js/format.js';

export async function status(el, ctx) {
  try {
    const data = await getJson('api/thermobeacon/sensors');
    const seen = data.collector_last_seen;
    el.textContent = seen === null ? 'collector: never' : `collector: ${formatAge(data.now - seen)}`;
    el.classList.toggle('stale', isStale(seen, data.now, ctx.config.stale_after_seconds));
  } catch (e) {
    el.textContent = 'collector: unknown';
    el.classList.remove('stale');
  }
}
