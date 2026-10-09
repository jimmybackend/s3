/* Regression tests for SO image window orientation, bounds and cleanup. */
const assert = require('node:assert/strict');
const { ArcadeCloudImageWindowFit } = require('../js/os-window-manager.js');

const scenarios = [
  [1920, 1080, 1280, 800, 74, 'landscape'],
  [800, 1600, 1280, 800, 74, 'portrait'],
  [300, 300, 1280, 800, 74, 'square'],
  [1200, 2000, 390, 844, 74, 'portrait'],
  [1920, 1080, 390, 844, 74, 'landscape'],
  [100, 200, 390, 844, 74, 'portrait']
];
for (const [imageWidth, imageHeight, viewportWidth, viewportHeight, chrome, orientation] of scenarios) {
  const fit = ArcadeCloudImageWindowFit.calculate(imageWidth, imageHeight, viewportWidth, viewportHeight, chrome);
  assert.ok(fit, 'Geometry should exist');
  assert.ok(fit.width <= viewportWidth - 16, 'Window must fit the desktop width');
  assert.ok(fit.height <= viewportHeight - 58, 'Window must fit above taskbar');
  const imageAspect = (fit.width - 2) / (fit.height - chrome - 2);
  const expectedAspect = imageWidth / imageHeight;
  assert.ok(Math.abs(imageAspect - expectedAspect) < .02,
    'Image area should preserve ratio for ' + orientation);
  if (orientation === 'portrait') assert.ok(fit.height - chrome > fit.width - 2);
  if (orientation === 'landscape') assert.ok(fit.width - 2 > fit.height - chrome - 2);
}
assert.equal(ArcadeCloudImageWindowFit.calculate(0, 100, 1000, 700), null);
assert.equal(ArcadeCloudImageWindowFit.calculate(NaN, 100, 1000, 700), null);

const win = new EventTarget();
win.innerWidth = 1200;
win.innerHeight = 800;
const image = new EventTarget();
image.naturalWidth = 1920;
image.naturalHeight = 1080;
image.complete = true;
let cleanup;
const manager = { addCleanup: (_id, fn) => { cleanup = fn; } };
const style = { setProperty(key, value) { this[key] = value; } };
const classes = new Set();
const element = {
  isConnected: true,
  dataset: {},
  style,
  classList: { add(key) { classes.add(key); }, contains(key) { return classes.has(key); } },
  querySelector(selector) {
    if (selector === '.os-window-titlebar') return { getBoundingClientRect: () => ({ height: 42 }) };
    if (selector === '.os-statusbar') return { getBoundingClientRect: () => ({ height: 32 }) };
    return null;
  }
};
const record = { id: 'img-test', maximized: false };
ArcadeCloudImageWindowFit.bind(win, element, image, manager, record);
assert.equal(record.autoImageFit, true);
assert.ok(classes.has('os-image-window'));
const initialWidth = Number.parseFloat(style.width);
assert.ok(initialWidth > 0 && initialWidth <= 1184);
assert.equal(style['--os-image-fit-width'], style.width);

image.naturalWidth = 900;
image.naturalHeight = 1600;
image.dispatchEvent(new Event('load'));
assert.ok(Number.parseFloat(style.height) - 74 > Number.parseFloat(style.width) - 2);

win.innerWidth = 390;
win.innerHeight = 844;
win.dispatchEvent(new Event('resize'));
assert.ok(Number.parseFloat(style.width) <= 374);
assert.ok(Number.parseFloat(style.height) <= 786);
assert.ok(Number.parseFloat(style.left) >= 8);

record.maximized = true;
const beforeMaxResize = style.width;
win.innerWidth = 800;
win.dispatchEvent(new Event('resize'));
assert.equal(style.width, beforeMaxResize, 'Maximized windows should not be force-resized');
record.maximized = false;
image.dispatchEvent(new Event('load'));
assert.notEqual(style.width, beforeMaxResize);

assert.equal(typeof cleanup, 'function');
cleanup();
const detachedWidth = style.width;
win.innerWidth = 1200;
win.dispatchEvent(new Event('resize'));
assert.equal(style.width, detachedWidth, 'Closed viewers should not process resize events');
console.log('SO image window geometry tests passed');
