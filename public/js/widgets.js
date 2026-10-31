// Where the code for a widget type lives. "<plugin>.<widget>" comes from
// plugins/<plugin>/web/widgets/<widget>.js; a type without a dot is a core
// widget. Returns null for an unknown type, which the dashboard shows as a
// placeholder.
const CORE = ['placeholder'];
const NAME = /^[a-z0-9_]+$/;

export function widgetSource(type, pluginNames) {
  if (CORE.includes(type)) return { core: type };
  const parts = String(type).split('.');
  if (parts.length !== 2 || !parts.every((p) => NAME.test(p))) return null;
  const [plugin, widget] = parts;
  if (!pluginNames.includes(plugin)) return null;
  // Relative to public/js/main.js, so it also works under nginx's prefix.
  return { plugin, url: `../plugins/${plugin}/widgets/${widget}.js` };
}

// CSS class of the widget's box: w-sensor, w-graph, w-placeholder.
export function widgetClass(type) {
  return `w-${String(type).split('.').pop()}`;
}
