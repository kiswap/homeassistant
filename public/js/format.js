// Pure display helpers. No DOM access.

function oneDecimal(value) {
  if (value === null || value === undefined || Number.isNaN(value)) return '–';
  const text = value.toFixed(1);
  return text === '-0.0' ? '0.0' : text;
}

export function formatTemp(celsius) {
  return oneDecimal(celsius);
}

export function formatHum(percent) {
  return oneDecimal(percent);
}

export function formatAge(seconds) {
  if (seconds < 60) return 'just now';
  if (seconds < 3600) return `${Math.floor(seconds / 60)} min ago`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)} h ago`;
  return `${Math.floor(seconds / 86400)} d ago`;
}

export function isStale(readingTs, nowTs, staleAfter) {
  if (readingTs === null || readingTs === undefined) return true;
  return nowTs - readingTs > staleAfter;
}

export function formatClock(ts) {
  const d = new Date(ts * 1000);
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}
