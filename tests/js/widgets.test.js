import test from 'node:test';
import assert from 'node:assert/strict';
import { widgetSource, widgetClass } from '../../public/js/widgets.js';

test('plugin widget type maps to the plugin web file', () => {
  assert.deepEqual(widgetSource('thermobeacon.sensor', ['thermobeacon']), {
    plugin: 'thermobeacon', url: '../plugins/thermobeacon/widgets/sensor.js',
  });
});

test('core widget type has no url', () => {
  assert.deepEqual(widgetSource('placeholder', ['thermobeacon']), { core: 'placeholder' });
});

test('widget of a plugin that is not enabled is unknown', () => {
  assert.equal(widgetSource('locsolo.graph', ['thermobeacon']), null);
});

test('malformed widget types are unknown', () => {
  for (const type of ['', 'a.b.c', '.x', 'x.', 'thermobeacon./x', 'thermobeacon.../a', 'nope']) {
    assert.equal(widgetSource(type, ['thermobeacon']), null, type);
  }
});

test('widget css class uses the part after the dot', () => {
  assert.equal(widgetClass('thermobeacon.graph'), 'w-graph');
  assert.equal(widgetClass('placeholder'), 'w-placeholder');
});
