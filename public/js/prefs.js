// Viewer choices that survive a page reload: which sensors the graph shows
// and which time range it uses. Stored in localStorage.

const KEY = 'thermobeacon.prefs.v1';
const RANGES = [24, 48, 168];

export function createPrefs(storage, defaults) {
  let state = load();

  function load() {
    let saved = {};
    try {
      saved = JSON.parse(storage.getItem(KEY) || '{}') || {};
    } catch (e) {
      saved = {};
    }
    return {
      graphSensors: Array.isArray(saved.graphSensors) ? saved.graphSensors : [...defaults.graphSensors],
      range: RANGES.includes(saved.range) ? saved.range : defaults.range,
    };
  }

  function save() {
    try {
      storage.setItem(KEY, JSON.stringify(state));
    } catch (e) {
      // Storage may be unavailable; the choice then lasts until reload.
    }
  }

  return {
    graphSensors: () => [...state.graphSensors],
    toggleGraphSensor(id) {
      const on = !state.graphSensors.includes(id);
      state.graphSensors = on ? [...state.graphSensors, id] : state.graphSensors.filter((s) => s !== id);
      save();
      return on;
    },
    range: () => state.range,
    setRange(hours) {
      if (!RANGES.includes(hours)) return;
      state.range = hours;
      save();
    },
  };
}
