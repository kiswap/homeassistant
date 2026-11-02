// Builds the temperature and humidity graph as an SVG string. No DOM access.
// Temperature: solid line, right axis. Humidity: dashed line, left axis 0–100 %.

const PAD = { left: 40, right: 40, top: 10, bottom: 24 };
const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const GAP_BUCKETS = 3;

function tempAxis(series) {
  const temps = series.flatMap((s) => s.points.map((p) => p[1]));
  if (temps.length === 0) return { min: 0, max: 30 };
  const min = Math.floor(Math.min(...temps) / 5) * 5;
  const max = Math.max(Math.ceil(Math.max(...temps) / 5) * 5, min + 10);
  return { min, max };
}

/** Splits points into runs without gaps longer than GAP_BUCKETS buckets. The chart joins the runs with a dotted line. */
function segments(points, bucketSeconds) {
  const runs = [];
  let run = [];
  for (const p of points) {
    if (run.length && p[0] - run[run.length - 1][0] > GAP_BUCKETS * bucketSeconds) {
      runs.push(run);
      run = [];
    }
    run.push(p);
  }
  if (run.length) runs.push(run);
  return runs;
}

function timeLabel(ts, week) {
  const d = new Date(ts * 1000);
  if (week) return WEEKDAYS[d.getDay()];
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

export function renderChart({ series, from, to, bucketSeconds, colors, width, height }) {
  const plotW = width - PAD.left - PAD.right;
  const plotH = height - PAD.top - PAD.bottom;
  const axis = tempAxis(series);
  const span = Math.max(1, to - from);
  const x = (ts) => (PAD.left + ((ts - from) / span) * plotW).toFixed(1);
  const yTemp = (t) => (PAD.top + (1 - (t - axis.min) / (axis.max - axis.min)) * plotH).toFixed(1);
  const yHum = (h) => (PAD.top + (1 - h / 100) * plotH).toFixed(1);
  const out = [];

  out.push(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" width="${width}" height="${height}" font-family="sans-serif" font-size="12">`);

  for (let t = axis.min; t <= axis.max; t += 5) {
    const y = yTemp(t);
    out.push(`<line x1="${PAD.left}" y1="${y}" x2="${width - PAD.right}" y2="${y}" stroke="#e3e7ec"/>`);
    out.push(`<text x="${width - PAD.right + 6}" y="${y}" dominant-baseline="middle" fill="#5f6870">${t}°</text>`);
  }
  for (const h of [0, 50, 100]) {
    out.push(`<text x="${PAD.left - 6}" y="${yHum(h)}" text-anchor="end" dominant-baseline="middle" fill="#1a62c9">${h}%</text>`);
  }

  const week = to - from > 72 * 3600;
  const labelCount = width < 450 ? 4 : 6;
  for (let i = 0; i < labelCount; i++) {
    const ts = from + (span * i) / (labelCount - 1);
    const anchor = i === 0 ? 'start' : i === labelCount - 1 ? 'end' : 'middle';
    out.push(`<text x="${x(ts)}" y="${height - 6}" text-anchor="${anchor}" fill="#5f6870">${timeLabel(ts, week)}</text>`);
  }

  for (const s of series) {
    const color = colors[s.id] || '#444';
    const runs = segments(s.points, bucketSeconds);
    // A dotted line joins the runs, so a gap reads as a guess, not as a reading.
    for (let i = 1; i < runs.length; i++) {
      const a = runs[i - 1][runs[i - 1].length - 1];
      const b = runs[i][0];
      out.push(`<polyline class="gap" fill="none" stroke="${color}" stroke-width="2" stroke-dasharray="1 3" stroke-linecap="round" opacity="0.6" points="${x(a[0])},${yTemp(a[1])} ${x(b[0])},${yTemp(b[1])}"/>`);
      out.push(`<polyline class="gap" fill="none" stroke="${color}" stroke-width="1.5" stroke-dasharray="1 3" stroke-linecap="round" opacity="0.5" points="${x(a[0])},${yHum(a[2])} ${x(b[0])},${yHum(b[2])}"/>`);
    }
    for (const run of runs) {
      const temp = run.map((p) => `${x(p[0])},${yTemp(p[1])}`).join(' ');
      const hum = run.map((p) => `${x(p[0])},${yHum(p[2])}`).join(' ');
      out.push(`<polyline fill="none" stroke="${color}" stroke-width="2.5" stroke-linejoin="round" points="${temp}"/>`);
      out.push(`<polyline fill="none" stroke="${color}" stroke-width="1.5" stroke-dasharray="6 4" opacity="0.8" points="${hum}"/>`);
    }
  }

  out.push('</svg>');
  return out.join('');
}
