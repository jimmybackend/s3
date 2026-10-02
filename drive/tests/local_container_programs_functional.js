'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
class Element {
  constructor(tag) { this.tag = tag; this.children = []; this.textContent = ''; this.className = ''; }
  append(...children) { this.children.push(...children); }
  set innerHTML(value) { throw new Error('Program inventory must use text nodes'); }
}
const document = { addEventListener() {}, createElement: tag => new Element(tag) };
const window = {};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../js/so-node.js'), 'utf8'), { window, document, Element, console });
const monitor = window.ArcadeCloudOsNodeMonitor;
const states = ['host','container','container_stopped','service_stopped','unavailable','unknown'];
const card = monitor.programsCard(states.map(state => ({ name: '<img src=x onerror=alert(1)>', state, installed: state === 'host', available: ['host','container'].includes(state) })));
const rows = card.children.slice(1);
assert.equal(rows.length, 6);
const labels = rows.map(row => row.children[1].textContent);
assert.deepEqual(labels, ['Instalado en el host','Disponible en contenedor local','Contenedor local detenido','Servicio local detenido; programa sin verificar','No disponible en este nodo','Contenedor local no verificable (helper/permisos)']);
assert.equal(rows.filter(row => row.className.includes('os-node-status-active')).length, 2);
assert(rows.every(row => row.children[0].textContent === '<img src=x onerror=alert(1)>'));
const both = monitor.programsCard([{ name: 'Git', state: 'host', installed: true, available: true, container_installed: true, container_available: false }]);
assert.match(both.children[1].children[1].textContent, /Instalado en el host.*Contenedor local detenido/);
console.log('OK: local capability labels, available states, coexistence and text-only rendering');
