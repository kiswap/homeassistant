import test from 'node:test';
import assert from 'node:assert/strict';
import { renderChart } from '../../public/js/chart.js';

const base = { from: 0, to: 86400, bucketSeconds: 300, colors: { a: '#d93025', b: '#188038' }, width: 600, height: 200 };

function pts(list) { return list.map(([ts, t, h]) => [ts, t, h]); }
function count(s, needle) { return s.split(needle).length - 1; }

test('chart empty series', () => {
  const s = renderChart({ ...base, series: [{ id: 'a', label: 'a', points: [] }] });
  assert.ok(s.startsWith('<svg'));
  assert.ok(!s.includes('NaN'));
  assert.equal(count(s, '<polyline'), 0);
});

test('chart no series at all', () => {
  const s = renderChart({ ...base, series: [] });
  assert.ok(s.startsWith('<svg'));
  assert.ok(!s.includes('NaN'));
});

test('chart negative range', () => {
  const s = renderChart({ ...base, series: [{ id: 'a', label: 'a', points: pts([[0, -4, 80], [300, 8, 70]]) }] });
  assert.ok(s.includes('>-5°<'), 'has -5° label');
  assert.ok(s.includes('>10°<'), 'has 10° label');
  assert.ok(!s.includes('NaN'));
});

test('chart axis is at least 10 degrees wide', () => {
  const s = renderChart({ ...base, series: [{ id: 'a', label: 'a', points: pts([[0, 21, 60], [300, 22, 61]]) }] });
  assert.ok(s.includes('>20°<'));
  assert.ok(s.includes('>30°<'));
});

test('chart humidity dashed, temperature solid, colored per series', () => {
  const s = renderChart({ ...base, series: [
    { id: 'a', label: 'a', points: pts([[0, 1, 50], [300, 2, 51]]) },
    { id: 'b', label: 'b', points: pts([[0, 5, 70], [300, 6, 71]]) },
  ] });
  assert.equal(count(s, '<polyline'), 4);
  assert.equal(count(s, 'stroke-dasharray'), 2);
  assert.ok(s.includes('stroke="#d93025"'));
  assert.ok(s.includes('stroke="#188038"'));
  assert.ok(s.includes('>100%<'));
});

test('chart breaks line on gap', () => {
  const s = renderChart({ ...base, series: [{ id: 'a', label: 'a', points: pts([[0, 1, 50], [300, 2, 50], [600, 3, 50], [3600, 4, 50], [3900, 5, 50]]) }] });
  // 2 temperature segments + 2 humidity segments
  assert.equal(count(s, '<polyline'), 4);
});

test('chart week range uses weekday labels', () => {
  const s = renderChart({ ...base, from: 0, to: 168 * 3600, bucketSeconds: 1800, series: [] });
  assert.match(s, />(Mon|Tue|Wed|Thu|Fri|Sat|Sun)</);
});

test('chart day range uses clock labels', () => {
  const s = renderChart({ ...base, series: [] });
  assert.match(s, />\d\d:\d\d</);
});

test('narrow chart uses 4 time labels instead of 6', () => {
  const labels = (w) => (renderChart({ ...base, width: w, series: [] }).match(/>\d\d:\d\d</g) || []).length;
  assert.equal(labels(600), 6);
  assert.equal(labels(320), 4);
});

test('chart humidity axis on the left, temperature axis on the right', () => {
  const s = renderChart({ ...base, series: [{ id: 'a', label: 'a', points: pts([[0, 21, 60], [300, 22, 61]]) }] });
  const labelX = (text) => Number(s.match(new RegExp(`<text x="([\\d.]+)"[^>]*>${text}<`))[1]);
  assert.ok(labelX('100%') < base.width / 2, 'humidity labels left');
  assert.ok(labelX('20°') > base.width / 2, 'temperature labels right');
});
