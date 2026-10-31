import test from 'node:test';
import assert from 'node:assert/strict';
import { formatTemp, formatHum, formatAge, isStale, formatClock } from '../../public/js/format.js';

test('formatTemp one decimal', () => assert.equal(formatTemp(23.38), '23.4'));
test('formatTemp negative', () => assert.equal(formatTemp(-3.5), '-3.5'));
test('formatTemp rounds tiny negatives to 0.0, not -0.0', () => assert.equal(formatTemp(-0.04), '0.0'));
test('formatTemp null', () => assert.equal(formatTemp(null), '–'));
test('formatHum', () => { assert.equal(formatHum(61.5), '61.5'); assert.equal(formatHum(null), '–'); });

test('formatAge', () => {
  assert.equal(formatAge(30), 'just now');
  assert.equal(formatAge(300), '5 min ago');
  assert.equal(formatAge(2 * 3600), '2 h ago');
  assert.equal(formatAge(3 * 86400), '3 d ago');
  assert.equal(formatAge(-20), 'just now');
});

test('isStale', () => {
  assert.equal(isStale(1000, 1601, 600), true);
  assert.equal(isStale(1000, 1600, 600), false);
  assert.equal(isStale(null, 1, 600), true);
});

test('formatClock is HH:MM', () => assert.match(formatClock(1793400000), /^\d\d:\d\d$/));
