// The tablet's own battery, shown as a small overlay in the top-left corner.
// It uses the browser's Battery Status API, so the server is not involved.
// A browser without the API (desktop Firefox, Safari) shows nothing.

const LOW = 0.2;

// Pure: the text and the low flag for one battery state.
export function batteryView(level, charging) {
  const text = `🔋 ${Math.round(level * 100)}%${charging ? ' ⚡' : ''}`;
  return { text, low: !charging && level <= LOW };
}

export async function startBattery(el, nav = navigator) {
  if (typeof nav.getBattery !== 'function') return;
  let battery;
  try {
    battery = await nav.getBattery();
  } catch (e) {
    console.error('battery:', e);
    return;
  }
  const show = () => {
    const view = batteryView(battery.level, battery.charging);
    el.textContent = view.text;
    el.classList.toggle('low', view.low);
    el.hidden = false;
  };
  battery.addEventListener('levelchange', show);
  battery.addEventListener('chargingchange', show);
  show();
}
