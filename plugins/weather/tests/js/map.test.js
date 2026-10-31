import test from 'node:test';
import assert from 'node:assert/strict';
import {
  worldPx, fitZoom, project, tilesFor, decodeRle, radarColor, OUTSIDE, timeline, linePosition,
} from '../../web/map.js';

const BUDAPEST = { lat: 47.4979, lon: 19.0402 };

test('worldPx matches the OpenStreetMap tile of Budapest', () => {
  const p = worldPx(BUDAPEST.lat, BUDAPEST.lon, 7);
  assert.equal(Math.floor(p.x / 256), 70);
  assert.equal(Math.floor(p.y / 256), 44);
  assert.deepEqual(worldPx(0, 0, 0), { x: 128, y: 128 });
});

test('fitZoom: 300 px cover 100 km at that zoom', () => {
  const z = fitZoom(50, BUDAPEST.lat, 300);
  const metresPerPx = (156543.03392 * Math.cos((BUDAPEST.lat * Math.PI) / 180)) / Math.pow(2, z);
  assert.ok(Math.abs(300 * metresPerPx - 100000) < 1e-6, String(z));
  assert.ok(Math.abs(fitZoom(50, 0, 600) - fitZoom(100, 0, 300) - 2) < 1e-9, 'twice the size is two levels');
});

test('project puts the centre in the middle of the box', () => {
  const view = { ...BUDAPEST, zoom: 8, width: 300, height: 200 };
  const p = project(view, BUDAPEST.lat, BUDAPEST.lon);
  assert.ok(Math.abs(p.x - 150) < 1e-6 && Math.abs(p.y - 100) < 1e-6);
  assert.ok(project(view, BUDAPEST.lat + 0.1, BUDAPEST.lon).y < 100, 'north is up');
});

test('tilesFor covers the box without gaps', () => {
  const view = { ...BUDAPEST, zoom: 8.2, width: 300, height: 250 };
  const tiles = tilesFor(view, 8);
  assert.ok(tiles.every((t) => t.z === 8));
  for (const t of tiles) {
    const right = tiles.find((u) => u.x === t.x + 1 && u.y === t.y);
    if (right) assert.equal(right.left, t.left + t.width);
    const below = tiles.find((u) => u.x === t.x && u.y === t.y + 1);
    if (below) assert.equal(below.top, t.top + t.height);
  }
  assert.ok(Math.min(...tiles.map((t) => t.left)) <= 0);
  assert.ok(Math.min(...tiles.map((t) => t.top)) <= 0);
  assert.ok(Math.max(...tiles.map((t) => t.left + t.width)) >= 300);
  assert.ok(Math.max(...tiles.map((t) => t.top + t.height)) >= 250);
  assert.ok(tiles.every((t) => Math.abs(t.width - 256 * Math.pow(2, 0.2)) <= 1));
});

test('decodeRle expands count/value pairs', () => {
  const rle = Buffer.from([3, 0, 1, 7, 2, OUTSIDE]).toString('base64');
  assert.deepEqual([...decodeRle(rle, 6)], [0, 0, 0, 7, OUTSIDE, OUTSIDE]);
  assert.deepEqual([...decodeRle(rle, 4)], [0, 0, 0, 7], 'never writes past the size');
});

test('radarColor by dBZ band', () => {
  assert.equal(radarColor(0), null);
  assert.equal(radarColor(OUTSIDE), null);
  assert.equal(radarColor(70), null, '3 dBZ is noise');
  assert.equal(radarColor(74), '#9fd3ff', '5 dBZ');
  assert.equal(radarColor(114), '#2db84d', '25 dBZ');
  assert.equal(radarColor(160), '#e8401c', '48 dBZ');
  assert.equal(radarColor(174), '#b0189a', '55 dBZ');
});

test('timeline: 1 hour past, 2 hours future', () => {
  const line = timeline(10000);
  assert.deepEqual(line, { from: 6400, now: 10000, to: 17200 });
  assert.deepEqual(linePosition(6400, line), { future: false, at: 0 });
  assert.deepEqual(linePosition(8200, line), { future: false, at: 0.5 });
  assert.deepEqual(linePosition(10000, line), { future: false, at: 1 }, 'now ends the past segment');
  assert.deepEqual(linePosition(13600, line), { future: true, at: 0.5 });
  assert.deepEqual(linePosition(0, line), { future: false, at: 0 }, 'clamped at the left end');
  assert.deepEqual(linePosition(99999, line), { future: true, at: 1 }, 'clamped at the right end');
});

test('timeline starts at the oldest frame', () => {
  const line = timeline(10000, 5800);
  assert.equal(line.from, 5800);
  assert.deepEqual(linePosition(5800, line), { future: false, at: 0 });
  assert.equal(timeline(10000, 10000).from, 9940, 'the past segment never has zero length');
});
