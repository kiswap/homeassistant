// Clock: the time in the middle, the date with the Hungarian day name under
// it, sunrise and sunset in the bottom-left corner, and the moon phase in
// the bottom-right corner. Everything is computed in the browser from the
// tablet's clock and the place in the weather settings, so the widget makes
// no requests. It redraws itself at the start of every minute instead of
// waiting for the dashboard's data refresh, which can be late.
import { formatDate, formatTime, sunTimes, moonPhase } from '../sky.js';

// Budapest, for a settings file without a place.
const LATITUDE = 47.4979;
const LONGITUDE = 19.0402;

export function create(el, cfg, ctx) {
  el.innerHTML = `
    <div class="ck-time"></div>
    <div class="ck-bottom">
      <div class="ck-sun"><div class="ck-rise"></div><div class="ck-set"></div></div>
      <div class="ck-date"><span class="ck-ymd"></span><span class="ck-day"></span></div>
      <div class="ck-moon"></div>
    </div>`;
  const latitude = ctx.config.latitude ?? LATITUDE;
  const longitude = ctx.config.longitude ?? LONGITUDE;

  function render() {
    const now = new Date();
    el.querySelector('.ck-time').textContent = formatTime(now);
    const { date, day } = formatDate(now);
    el.querySelector('.ck-ymd').textContent = date;
    el.querySelector('.ck-day').textContent = day;
    const { rise, set } = sunTimes(now, latitude, longitude);
    el.querySelector('.ck-rise').textContent = rise ? `🌅 ${formatTime(rise)}` : '';
    el.querySelector('.ck-set').textContent = set ? `🌇 ${formatTime(set)}` : '';
    el.querySelector('.ck-moon').textContent = moonPhase(now);
  }

  function tick() {
    render();
    const now = new Date();
    setTimeout(tick, 60000 - now.getSeconds() * 1000 - now.getMilliseconds() + 50);
  }
  tick();

  return { refresh: async () => render() };
}
