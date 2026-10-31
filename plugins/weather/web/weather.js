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

// "1.2" above a column, "0" when there is (almost) no rain, empty when unknown.
export function formatMm(mm) {
  if (mm === null || mm === undefined) return '';
  return mm >= 0.05 ? mm.toFixed(1) : '0';
}

// An SVG path through the points as a smooth curve (Catmull-Rom splines
// turned into cubic Béziers). points: [{ x, y }], at least one.
export function smoothPath(points) {
  const f = (v) => v.toFixed(1);
  let d = `M${f(points[0].x)} ${f(points[0].y)}`;
  for (let i = 0; i + 1 < points.length; i++) {
    const p0 = points[Math.max(0, i - 1)];
    const p1 = points[i];
    const p2 = points[i + 1];
    const p3 = points[Math.min(points.length - 1, i + 2)];
    const c1 = { x: p1.x + (p2.x - p0.x) / 6, y: p1.y + (p2.y - p0.y) / 6 };
    const c2 = { x: p2.x - (p3.x - p1.x) / 6, y: p2.y - (p3.y - p1.y) / 6 };
    d += ` C${f(c1.x)} ${f(c1.y)} ${f(c2.x)} ${f(c2.y)} ${f(p2.x)} ${f(p2.y)}`;
  }
  return d;
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
