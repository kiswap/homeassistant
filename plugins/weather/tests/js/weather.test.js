import test from 'node:test';
import assert from 'node:assert/strict';
import {
  weatherIcon, scaleMax, barPercent, formatMm, formatProbability, probabilityClass,
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

test('formatMm hides traces', () => {
  assert.equal(formatMm(1.24), '1.2');
  assert.equal(formatMm(0.04), '');
  assert.equal(formatMm(null), '');
});

test('probability text and class', () => {
  assert.equal(formatProbability(40), '40%');
  assert.equal(formatProbability(null), '–');
  assert.equal(probabilityClass(5), 'p-lo');
  assert.equal(probabilityClass(20), 'p-mid');
  assert.equal(probabilityClass(80), 'p-hi');
  assert.equal(probabilityClass(null), 'p-lo');
});
