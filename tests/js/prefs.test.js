import test from 'node:test';
import assert from 'node:assert/strict';
import { createPrefs } from '../../public/js/prefs.js';

function fakeStorage(initial = {}) {
  const m = new Map(Object.entries(initial));
  return { getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)), _m: m };
}
const defaults = { graphSensors: ['konyha', 'kinti'], range: 24 };

test('prefs defaults', () => {
  const p = createPrefs(fakeStorage(), defaults);
  assert.deepEqual(p.graphSensors(), ['konyha', 'kinti']);
  assert.equal(p.range(), 24);
});

test('prefs defaults and toggle persist', () => {
  const s = fakeStorage();
  const p = createPrefs(s, defaults);
  assert.equal(p.toggleGraphSensor('utazo'), true);
  assert.equal(p.toggleGraphSensor('kinti'), false);
  const again = createPrefs(s, defaults);
  assert.deepEqual(again.graphSensors().sort(), ['konyha', 'utazo']);
});

test('prefs range persists and rejects unknown', () => {
  const s = fakeStorage();
  createPrefs(s, defaults).setRange(168);
  assert.equal(createPrefs(s, defaults).range(), 168);
  s.setItem('thermobeacon.prefs.v1', JSON.stringify({ range: 12 }));
  assert.equal(createPrefs(s, defaults).range(), 24);
});

test('prefs corrupt storage falls back', () => {
  const p = createPrefs(fakeStorage({ 'thermobeacon.prefs.v1': '{bad' }), defaults);
  assert.deepEqual(p.graphSensors(), ['konyha', 'kinti']);
  assert.equal(p.range(), 24);
});

test('prefs survive a throwing storage', () => {
  const broken = { getItem() { throw new Error('denied'); }, setItem() { throw new Error('denied'); } };
  const p = createPrefs(broken, defaults);
  assert.equal(p.toggleGraphSensor('utazo'), true);
  assert.deepEqual(p.graphSensors().sort(), ['kinti', 'konyha', 'utazo']);
});
