// Run with node tests/map-regression.cjs; no third-party dependencies required.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(process.argv[2] || path.join(__dirname,
    '../resources/views/widgets/offline-devices-map.blade.php'), 'utf8');
const script = source.match(/<script[^>]*>([\s\S]*?)<\/script>/)[1]
    .replace(/\{\{\s*\$widget_id\s*\}\}/g, '1')
    .replace(/\{\{\s*Js::from\(\$group_ids\)\s*\}\}/g, '[1, 2]')
    .replace(/\{\{\s*Js::from\(\$map_config\)\s*\}\}/g, '{}')
    .replace(/\{\{\s*Js::from\(\$\w+\)\s*\}\}/g, '[]')
    .replace(/\{\{\s*\(int\) \$radius\s*\}\}/g, '80')
    .replace(/\{\{\s*\$fit_to_markers[^}]*\}\}/g, 'true')
    .replace(/\{\{\s*route\([^}]*\}\}/g, '/maps/devices');

function setup() {
    const requests = [], events = {}, elements = [];
    let fitCount = 0, destroyed = false;
    const map = {
        scrollWheelZoom: { enable() {}, disable() {} },
        addLayer() {}, fitBounds() { fitCount++; }, invalidateSize() {},
    };
    const $ = () => ({ on(event, callback) { events[event] = callback; return this; } });
    $.ajax = () => {
        const callbacks = {};
        const request = {
            done(fn) { callbacks.done = fn; return this; },
            fail(fn) { callbacks.fail = fn; return this; },
            always(fn) { callbacks.always = fn; return this; },
            resolve(data) { callbacks.done(data); callbacks.always?.(); },
            reject() { callbacks.fail({ statusText: 'Unavailable' }); callbacks.always?.(); },
        };
        requests.push(request);
        return request;
    };
    vm.runInNewContext(script, {
        $, URL, window: { location: { href: 'https://nms.example/dashboard' } },
        document: { createElement(tag) {
            const element = { tag, style: {}, children: [], listeners: {}, setAttribute() {}, appendChild(child) { this.children.push(child); }, replaceChildren(...children) { this.children = children; }, addEventListener(name, fn) { this.listeners[name] = fn; } };
            elements.push(element);
            return element;
        } },
        loadjs: (_url, callback) => callback(),
        init_map: () => map, get_map: () => map, destroy_map: () => { destroyed = true; },
        L: {
            DomEvent: { disableClickPropagation() {}, disableScrollPropagation() {} },
            AwesomeMarkers: { icon: options => ({ options }) },
            LatLng: function (lat, lng) { this.lat = lat; this.lng = lng; },
            marker: (location, options) => ({ location, options, bindPopup(content) { this.popup = content; } }),
            markerClusterGroup: () => ({
                layers: [], clearLayers() { this.layers = []; },
                addLayers(markers) { this.layers.push(...markers); }, getBounds() { return {}; },
            }),
            control: () => ({ addTo() { this.onAdd(); } }),
        },
    });
    return { requests, events, elements, map, fits: () => fitCount, destroyed: () => destroyed };
}
const device = (name, url = '/device/1') => ({
    sname: name, url, typeIcon: 'server', status: 0, maintenance: 0, lat: 10, lng: 20,
});

const s = setup();
const snapshot = devices => ({ devices, observed_at: '2026-09-25T10:00:00Z' });
const hostileName = '<img src=x onerror=alert(1)>';
s.requests[0].resolve(snapshot({ 1: device(hostileName), 2: device('Unsafe link', 'javascript:alert(1)'), 3: { ...device('Missing coordinates'), lat: null, lng: null, site: '<script>alert(1)</script>' } }));
assert.equal(s.map.markerCluster.layers.length, 2, 'devices without coordinates must not create markers');
const popup = s.map.markerCluster.layers[0].popup;
assert.equal(typeof popup, 'object', 'popup must use a DOM element instead of HTML');
assert.equal(popup.children[0].textContent, hostileName);
assert.equal(popup.children[0].href, 'https://nms.example/device/1');
assert.equal(s.map.markerCluster.layers[1].popup.children[0].href, undefined, 'unsafe URL must not be clickable');
assert.equal(s.fits(), 1);
assert.ok(s.elements.some(e => e.textContent?.includes('1 without coordinates')));
assert.ok(s.elements.some(e => e.textContent?.includes('<script>alert(1)</script>')));
const fit = s.elements.find(e => e.textContent === 'Fit current outages');
fit.listeners.click();
assert.equal(s.fits(), 2, 'fit action must fit currently mapped outages');
const original = s.map.markerCluster.layers;
s.events.refresh();
s.requests[1].reject();
assert.equal(s.map.markerCluster.layers, original, 'failure must preserve the complete snapshot');
const warning = s.elements.find(element => element.className === 'alert alert-warning');
assert.equal(warning.hidden, false);
assert.match(warning.textContent, /previous device data/);
s.events.refresh();
s.requests[2].resolve(snapshot({ 4: device('Recovered') }));
assert.equal(s.map.markerCluster.layers[0].popup.children[0].textContent, 'Recovered');
assert.equal(warning.hidden, true);
assert.equal(s.fits(), 2, 'refresh must preserve pan and zoom');
s.events.refresh();
s.events.refresh();
s.requests[4].resolve(snapshot({ 5: device('Newest') }));
s.requests[3].resolve(snapshot({ 6: device('Outdated') }));
assert.equal(s.map.markerCluster.layers[0].popup.children[0].textContent, 'Newest');
s.events.refresh();
s.events.destroy();
s.requests[5].resolve(snapshot({}));
assert.equal(s.destroyed(), true);
assert.equal(s.map.markerCluster.layers[0].popup.children[0].textContent, 'Newest', 'late response must not render after destroy');

const initialFailure = setup();
initialFailure.requests[0].reject();
assert.equal(initialFailure.map.markerCluster, undefined);
assert.match(initialFailure.elements[0].textContent, /unavailable/);
assert.equal(initialFailure.elements[0].hidden, false);
initialFailure.events.refresh();
initialFailure.requests[1].resolve(snapshot({}));
assert.equal(initialFailure.map.markerCluster.layers.length, 0, 'complete empty data can clear markers');
assert.equal(initialFailure.elements[0].hidden, true);
assert.ok(initialFailure.elements.some(e => e.textContent === 'No offline devices match the selected filters.'));
console.log('Map regression checks passed: safe popup/list, coordinate coverage, fit action, failure retention, recovery, view preservation, race and destroy guards.');
