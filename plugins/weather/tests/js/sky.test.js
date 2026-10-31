import test from 'node:test';
import assert from 'node:assert/strict';
import { formatDate, formatTime, sunTimes, moonPhase } from '../../web/sky.js';

const BUDAPEST = [47.4979, 19.0402];

test('formatDate gives YYYY.MM.DD and the Hungarian day name', () => {
  assert.deepEqual(formatDate(new Date(2026, 9, 31, 10, 0)), { date: '2026.10.31', day: 'Szombat' });
  assert.deepEqual(formatDate(new Date(2026, 10, 1, 10, 0)), { date: '2026.11.01', day: 'Vasárnap' });
  assert.deepEqual(formatDate(new Date(2026, 10, 2, 10, 0)), { date: '2026.11.02', day: 'Hétfő' });
  assert.deepEqual(formatDate(new Date(2026, 10, 5, 10, 0)), { date: '2026.11.05', day: 'Csütörtök' });
});

test('formatTime is 24-hour without seconds', () => {
  assert.equal(formatTime(new Date(2026, 9, 31, 7, 5, 59)), '07:05');
  assert.equal(formatTime(new Date(2026, 9, 31, 23, 59, 1)), '23:59');
  assert.equal(formatTime(new Date(2026, 9, 31, 0, 0, 0)), '00:00');
});

// Reference times from Open-Meteo for Budapest, in UTC.
function assertNear(actual, expectedUtc) {
  const diff = Math.abs(actual.getTime() - expectedUtc) / 60000;
  assert.ok(diff <= 3, `${actual.toISOString()} is ${diff.toFixed(1)} min from ${new Date(expectedUtc).toISOString()}`);
}

test('sunTimes matches Open-Meteo in autumn', () => {
  const { rise, set } = sunTimes(new Date(2026, 9, 31, 10, 0), ...BUDAPEST);
  assertNear(rise, Date.UTC(2026, 9, 31, 5, 25));
  assertNear(set, Date.UTC(2026, 9, 31, 15, 28));
});

test('sunTimes matches Open-Meteo in summer', () => {
  const { rise, set } = sunTimes(new Date(2026, 6, 31, 10, 0), ...BUDAPEST);
  assertNear(rise, Date.UTC(2026, 6, 31, 3, 20));
  assertNear(set, Date.UTC(2026, 6, 31, 18, 19));
});

test('sunTimes is null when the sun does not set', () => {
  assert.deepEqual(sunTimes(new Date(2026, 5, 21, 12, 0), 80, 0), { rise: null, set: null });
});

test('moonPhase around known new and full moons', () => {
  assert.equal(moonPhase(new Date(Date.UTC(2026, 9, 26, 4, 12))), '🌕');
  assert.equal(moonPhase(new Date(Date.UTC(2026, 10, 9, 7, 2))), '🌑');
  assert.equal(moonPhase(new Date(Date.UTC(2026, 10, 1, 20, 0))), '🌗');
  // Before the reference new moon too.
  assert.equal(moonPhase(new Date(Date.UTC(1999, 11, 22, 17, 31))), '🌕');
});
