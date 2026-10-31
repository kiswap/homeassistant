import test from 'node:test';
import assert from 'node:assert/strict';
import {
  weatherIcon, scaleMax, barPercent, formatMm, formatProbability, probabilityClass, smoothPath,
} from '../../web/weather.js';

test('weatherIcon by WMO code', () => {
  assert.equal(weatherIcon(0, true), '☀️');
  assert.equal(weatherIcon(0, false), '🌙');
  assert.equal(weatherIcon(3, true), '☁️');
  assert.equal(weatherIcon(45, true), '🌫️');
  assert.equal(weatherIcon(63, true), '🌧️');
  assert.equal(weatherIcon(73, false), '🌨️');
  assert.equal(weatherIcon(81, true), '🌦️');
  assert.equal(weatherIcon(95, true), '⛈️');
  assert.equal(weatherIcon(null, true), '');
});

test('scaleMax is at least 2 mm', () => {
  assert.equal(scaleMax([{ mm: 0.3 }, { mm: null }]), 2);
  assert.equal(scaleMax([{ mm: 0.3 }, { mm: 5.5 }]), 5.5);
});

test('barPercent', () => {
  assert.equal(barPercent(1, 2), 50);
  assert.equal(barPercent(null, 2), 0);
  assert.equal(barPercent(9, 2), 100);
});

test('formatMm shows 0 for no rain', () => {
  assert.equal(formatMm(1.24), '1.2');
  assert.equal(formatMm(0.1), '0.1');
  assert.equal(formatMm(0.04), '0');
  assert.equal(formatMm(0), '0');
  assert.equal(formatMm(null), '');
});

test('smoothPath passes through every point', () => {
  assert.equal(smoothPath([{ x: 0, y: 5 }]), 'M0.0 5.0');
  const d = smoothPath([{ x: 0, y: 0 }, { x: 10, y: 10 }, { x: 20, y: 0 }]);
  assert.match(d, /^M0\.0 0\.0 C[^C]+ 10\.0 10\.0 C[^C]+ 20\.0 0\.0$/);
});

test('probability text and class', () => {
  assert.equal(formatProbability(40), '40%');
  assert.equal(formatProbability(null), '–');
  assert.equal(probabilityClass(5), 'p-lo');
  assert.equal(probabilityClass(20), 'p-mid');
  assert.equal(probabilityClass(80), 'p-hi');
  assert.equal(probabilityClass(null), 'p-lo');
});
