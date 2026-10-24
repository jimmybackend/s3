'use strict';
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
let now = 1000000;
let interval;
let reloaded = 0;
let visibility;
const output = {textContent: ''};
const context = {
    Date: {now: () => now},
    document: {
        getElementById: () => output,
        querySelector: () => ({dataset: {loginRetrySeconds: '65'}}),
        addEventListener: (event, callback) => { visibility = callback; }
    },
    window: {
        setInterval: callback => { interval = callback; return 1; },
        clearInterval: () => {},
        location: {reload: () => { reloaded++; }}
    }
};
vm.runInNewContext(fs.readFileSync(require('path').join(__dirname, '../js/login-cooldown.js'), 'utf8'), context);
interval();
assert.equal(output.textContent, '01:05');
now += 60000; visibility();
assert.equal(output.textContent, '00:05', 'tab resume uses elapsed time instead of interval count');
assert.equal(reloaded, 0, 'must not offer access early');
now += 5000; interval();
assert.equal(output.textContent, '00:00');
assert.equal(reloaded, 1, 'expiry reloads page for authoritative server check');
console.log('login countdown functional: ok');
