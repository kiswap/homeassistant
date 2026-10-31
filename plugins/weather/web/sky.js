// Pure helpers for the clock widget: the date text, sunrise and sunset,
// and the moon phase. They need no server, so the clock keeps working
// when the server is down. No DOM access.

const DAYS = ['Vasárnap', 'Hétfő', 'Kedd', 'Szerda', 'Csütörtök', 'Péntek', 'Szombat'];
const DAY_MS = 86400000;
const UNIX_EPOCH_JD = 2440587.5;
const J2000 = 2451545;
const RAD = Math.PI / 180;

function pad(n) {
  return String(n).padStart(2, '0');
}

// "2026.10.31" and "Szombat", in the tablet's local time.
export function formatDate(d) {
  return {
    date: `${d.getFullYear()}.${pad(d.getMonth() + 1)}.${pad(d.getDate())}`,
    day: DAYS[d.getDay()],
  };
}

// "14:07", 24-hour, without seconds.
export function formatTime(d) {
  return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

// Sunrise and sunset on the local calendar day of d, as Date objects.
// Uses the sunrise equation (https://en.wikipedia.org/wiki/Sunrise_equation),
// which is within a minute or two at Budapest's latitude. Both are null on
// a day when the sun does not rise or does not set.
export function sunTimes(d, latitude, longitude) {
  const noonJd = Date.UTC(d.getFullYear(), d.getMonth(), d.getDate(), 12) / DAY_MS + UNIX_EPOCH_JD;
  const n = noonJd - J2000;
  const jStar = n - longitude / 360;
  const m = (357.5291 + 0.98560028 * jStar) % 360;
  const c = 1.9148 * Math.sin(m * RAD) + 0.02 * Math.sin(2 * m * RAD) + 0.0003 * Math.sin(3 * m * RAD);
  const lambda = (m + c + 180 + 102.9372) % 360;
  const transit = J2000 + jStar + 0.0053 * Math.sin(m * RAD) - 0.0069 * Math.sin(2 * lambda * RAD);
  const sinDecl = Math.sin(lambda * RAD) * Math.sin(23.4397 * RAD);
  const cosDecl = Math.cos(Math.asin(sinDecl));
  const cosHour = (Math.sin(-0.833 * RAD) - Math.sin(latitude * RAD) * sinDecl)
    / (Math.cos(latitude * RAD) * cosDecl);
  if (cosHour < -1 || cosHour > 1) return { rise: null, set: null };
  const half = Math.acos(cosHour) / RAD / 360;
  const toDate = (jd) => new Date(Math.round((jd - UNIX_EPOCH_JD) * DAY_MS));
  return { rise: toDate(transit - half), set: toDate(transit + half) };
}

// The moon as one of the 8 phase pictograms, from the time since a known
// new moon (6 January 2000, 18:14 UTC). The phase can be off by about half
// a day, which does not change the pictogram most days.
const MOONS = ['🌑', '🌒', '🌓', '🌔', '🌕', '🌖', '🌗', '🌘'];
const NEW_MOON_MS = Date.UTC(2000, 0, 6, 18, 14);
const SYNODIC_DAYS = 29.530588853;

export function moonPhase(d) {
  const days = (d.getTime() - NEW_MOON_MS) / DAY_MS;
  const age = ((days % SYNODIC_DAYS) + SYNODIC_DAYS) % SYNODIC_DAYS;
  return MOONS[Math.round((age / SYNODIC_DAYS) * 8) % 8];
}
