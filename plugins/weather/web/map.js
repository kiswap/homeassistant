// Map maths for the radar widget (Web Mercator, as OpenStreetMap tiles
// use) and the radar frame format. No DOM access.

const TILE = 256;
const EARTH_M_PER_PX = 156543.03392; // metres per pixel at zoom 0 on the equator

// Pixel position of a point on the whole-world map at a zoom level.
export function worldPx(lat, lon, zoom) {
  const n = TILE * Math.pow(2, zoom);
  const phi = (lat * Math.PI) / 180;
  return {
    x: ((lon + 180) / 360) * n,
    y: ((1 - Math.log(Math.tan(phi) + 1 / Math.cos(phi)) / Math.PI) / 2) * n,
  };
}

// The (fractional) zoom at which radiusKm around the point fills sizePx.
export function fitZoom(radiusKm, lat, sizePx) {
  const metresPerPx = (2 * radiusKm * 1000) / sizePx;
  return Math.log2((EARTH_M_PER_PX * Math.cos((lat * Math.PI) / 180)) / metresPerPx);
}

// view = { lat, lon, zoom, width, height }: the point at the centre of a
// width x height box. Returns the box position of another point.
export function project(view, lat, lon) {
  const c = worldPx(view.lat, view.lon, view.zoom);
  const p = worldPx(lat, lon, view.zoom);
  return { x: p.x - c.x + view.width / 2, y: p.y - c.y + view.height / 2 };
}

// The map tiles of zoom level tileZoom that cover the view, with their box
// positions. Edges are rounded so neighbouring tiles neither gap nor overlap.
export function tilesFor(view, tileZoom) {
  const scale = Math.pow(2, view.zoom - tileZoom);
  const c = worldPx(view.lat, view.lon, tileZoom);
  const halfW = view.width / 2 / scale;
  const halfH = view.height / 2 / scale;
  const last = Math.pow(2, tileZoom) - 1;
  const edgeX = (x) => Math.round((x * TILE - c.x) * scale + view.width / 2);
  const edgeY = (y) => Math.round((y * TILE - c.y) * scale + view.height / 2);
  const tiles = [];
  const y0 = Math.max(0, Math.floor((c.y - halfH) / TILE));
  const y1 = Math.min(last, Math.floor((c.y + halfH) / TILE));
  for (let y = y0; y <= y1; y++) {
    for (let x = Math.floor((c.x - halfW) / TILE); x <= Math.floor((c.x + halfW) / TILE); x++) {
      tiles.push({
        z: tileZoom, x, y,
        left: edgeX(x), top: edgeY(y),
        width: edgeX(x + 1) - edgeX(x), height: edgeY(y + 1) - edgeY(y),
      });
    }
  }
  return tiles;
}

// A frame comes as base64 of (count, value) byte pairs; see RadarFile::rle().
export function decodeRle(base64, size) {
  const bin = atob(base64);
  const cells = new Uint8Array(size);
  let at = 0;
  for (let i = 0; i + 1 < bin.length && at < size; i += 2) {
    const count = bin.charCodeAt(i);
    const value = bin.charCodeAt(i + 1);
    cells.fill(value, at, Math.min(size, at + count));
    at += count;
  }
  return cells;
}

export const OUTSIDE = 255;

// Colour of a radar cell. The byte is dBZ = raw / 2 - 32. Weak echoes
// (below 5 dBZ) are mostly noise, so they are not drawn.
const BANDS = [
  [55, '#b0189a'],
  [45, '#e8401c'],
  [35, '#f2c200'],
  [25, '#2db84d'],
  [15, '#3f8cff'],
  [5, '#9fd3ff'],
];

export function radarColor(raw) {
  if (raw === 0 || raw === OUTSIDE) return null;
  const dbz = raw / 2 - 32;
  for (const [min, color] of BANDS) {
    if (dbz >= min) return color;
  }
  return null;
}
