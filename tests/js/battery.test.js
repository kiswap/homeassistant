import test from 'node:test';
import assert from 'node:assert/strict';
import { batteryView, startBattery } from '../../public/js/battery.js';

test('batteryView rounds the level to a whole percent', () => {
  assert.deepEqual(batteryView(0.864, false), { text: '🔋 86%', low: false });
});

test('batteryView adds a flash while charging', () => {
  assert.deepEqual(batteryView(0.5, true), { text: '🔋 50% ⚡', low: false });
});

test('batteryView marks 20% and below as low', () => {
  assert.equal(batteryView(0.2, false).low, true);
  assert.equal(batteryView(0.21, false).low, false);
});

test('batteryView is not low while charging', () => {
  assert.equal(batteryView(0.1, true).low, false);
});

test('startBattery does nothing without the battery API', async () => {
  const el = { textContent: '', hidden: true, classList: { toggle() {} } };
  await startBattery(el, {});
  assert.equal(el.textContent, '');
  assert.equal(el.hidden, true);
});

test('startBattery shows the level and follows battery events', async () => {
  const listeners = {};
  const battery = {
    level: 0.86, charging: false,
    addEventListener: (name, fn) => { listeners[name] = fn; },
  };
  const classes = new Set();
  const el = { textContent: '', hidden: true, classList: { toggle: (c, on) => (on ? classes.add(c) : classes.delete(c)) } };
  await startBattery(el, { getBattery: async () => battery });
  assert.equal(el.textContent, '🔋 86%');
  assert.equal(el.hidden, false);

  battery.level = 0.15;
  listeners.levelchange();
  assert.equal(el.textContent, '🔋 15%');
  assert.equal(classes.has('low'), true);

  battery.charging = true;
  listeners.chargingchange();
  assert.equal(el.textContent, '🔋 15% ⚡');
  assert.equal(classes.has('low'), false);
});
