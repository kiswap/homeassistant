// Pure display helpers for the forecast widget. No DOM access.

// WMO weather codes as Open-Meteo sends them.
// https://open-meteo.com/en/docs (section "WMO Weather interpretation codes")
export function weatherIcon(code, isDay) {
  if (code === null || code === undefined) return '';
  if (code === 0) return isDay ? '☀️' : '🌙';
  if (code === 1) return isDay ? '🌤️' : '🌙';
  if (code === 2) return isDay ? '⛅' : '☁️';
  if (code === 3) return '☁️';
  if (code === 45 || code === 48) return '🌫️';
  if (code >= 51 && code <= 57) return isDay ? '🌦️' : '🌧️';
  if (code >= 61 && code <= 67) return '🌧️';
  if (code >= 71 && code <= 77) return '🌨️';
  if (code >= 80 && code <= 82) return isDay ? '🌦️' : '🌧️';
  if (code === 85 || code === 86) return '🌨️';
  if (code >= 95) return '⛈️';
  return '';
}

// The top of the column scale in mm. At least 2 mm, so a drizzle does not
// look like a downpour.
export function scaleMax(blocks) {
  return Math.max(2, ...blocks.map((b) => b.mm || 0));
}

export function barPercent(mm, max) {
  if (!mm) return 0;
  return Math.min(100, (mm / max) * 100);
}

// "1.2" above a column; empty when there is (almost) no rain.
export function formatMm(mm) {
  return mm >= 0.1 ? mm.toFixed(1) : '';
}

export function formatProbability(p) {
  return p === null || p === undefined ? '–' : `${p}%`;
}

// CSS class for the chance of rain: grey when unlikely, blue when likely.
export function probabilityClass(p) {
  if (p >= 50) return 'p-hi';
  if (p >= 20) return 'p-mid';
  return 'p-lo';
}
