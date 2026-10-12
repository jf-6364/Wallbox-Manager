'use strict';
// DOM testdouble: runs the actual tile.js, exercises actions and safe text updates.
// Layout, CSS and Symcon's SDK injection require a real browser / Symcon.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

class Element {
    constructor(tag = 'div') {
        this.tagName = tag.toUpperCase(); this.children = []; this.dataset = {};
        this.listeners = {}; this.value = ''; this.textContent = ''; this.hidden = false;
    }
    append(...elements) { this.children.push(...elements); }
    addEventListener(name, callback) { this.listeners[name] = callback; }
    querySelectorAll(selector) {
        assert.equal(selector, '[data-field]');
        const result = [];
        function walk(node) { if (node.dataset.field) result.push(node); node.children.forEach(walk); }
        this.children.forEach(walk);
        return result;
    }
    change() { this.listeners.change.call(this); }
}
const ids = ['wbm', 'manager', 'mode', 'policy', 'base', 'support-metric', 'support', 'confirmed', 'measured', 'assigned', 'energy', 'overrun', 'status', 'errors', 'boxes', 'battery',
    'battery-enable', 'battery-lock', 'soc', 'capacity', 'reserve-energy', 'battery-request', 'battery-power', 'battery-state', 'updated', 'simulation'];
const elements = Object.fromEntries(ids.map(id => [id, new Element()]));
const document = {getElementById: id => {assert.ok(elements[id], id); return elements[id];}, createElement: tag => new Element(tag), activeElement: null};
const actions = [];
const data = {
    manager:true, managerEnabled:true, mode:0, strict:true, base:12000, measured:6000, assigned:10000, energy:124.312,
    status:'Automatische Regelung aktiv', errors:[], time:1700000000, overrun:0, batteryConfigured:true, batteryEnabled:true,
    battery:{theoretical:4000, confirmed:2000, soc:76, capacity:10, energy:5.6, commanded:2000, measured:1900, reason:'Unterstützung verfügbar', external:true},
    boxes: {
        1:{id:1, name:'Garage <script>alert(1)</script>', enabled:true, manual:true, external:false, priority:1, measured:3000, power:4000, command:7, unit:'A', energy:12.123, energyMethod:'berechnet', phases:3, status:'Laden', reason:'Laden', remaining:0, gap:''},
        2:{id:2, name:'Stellplatz', enabled:true, manual:true, external:true, priority:2, measured:null, power:0, command:1, unit:'A', energy:0, energyMethod:'Gerätezähler', phases:1, status:'Einschaltverzögerung', reason:'Einschaltverzögerung', remaining:20, gap:'Messlücke'}
    }
};
const context = {document, window:{WBM_INITIAL:data}, Map, Intl, Date, Number, Object, JSON, Error, String, setInterval:fn => {context.tick = fn;},
    requestAction:(ident,value) => actions.push({ident,value})};
vm.createContext(context);
const source = fs.readFileSync(path.join(__dirname, '../WallboxManager/tile.js'), 'utf8');
new vm.Script(source, {filename:'tile.js'}).runInContext(context);
const card1 = elements.boxes.children[0];
const fields1 = Object.fromEntries(card1.querySelectorAll('[data-field]').map(el => [el.dataset.field,el]));
const card2 = elements.boxes.children[1];
const fields2 = Object.fromEntries(card2.querySelectorAll('[data-field]').map(el => [el.dataset.field,el]));
let count = 0;
function test(name, fn) { fn(); count++; console.log('OK  ' + name); }
test('Echte JavaScript-Datei ist syntaktisch gültig und baut zwei Karten', () => assert.equal(elements.boxes.children.length, 2));
test('Initialzustand, deutsche Einheiten und Gesamtenergie', () => {
    assert.equal(elements.base.textContent, '12 kW'); assert.equal(elements.energy.textContent, '124,312 kWh');
    assert.ok(fields1.energy.textContent.includes('(berechnet)')); assert.equal(fields2.measured.textContent, 'Keine Messung');
});
test('Namen werden als Text gesetzt; HTML wird nicht interpretiert', () => assert.equal(fields1.name.textContent, data.boxes[1].name));
test('Externe Sperre bleibt sichtbar und wird durch manuellen Klick nicht geändert', () => {
    assert.equal(fields1.lock.hidden, false); fields1.manual.checked = false; fields1.manual.change();
    assert.deepEqual(actions.at(-1), {ident:'W1Manual', value:false}); assert.equal(data.boxes[1].external, false);
});
test('Manager-, Modus-, Speicher- und Prioritätsaktionen sind korrekt typisiert', () => {
    elements.manager.checked = false; elements.manager.change(); assert.deepEqual(actions.at(-1), {ident:'ManagerEnabled',value:false});
    elements.mode.value = '1'; elements.mode.change(); assert.deepEqual(actions.at(-1), {ident:'DistributionMode',value:1});
    elements['battery-enable'].checked = false; elements['battery-enable'].change(); assert.deepEqual(actions.at(-1), {ident:'BatteryEnabled',value:false});
    fields1.priority.value = '7'; fields1.priority.change(); assert.deepEqual(actions.at(-1), {ident:'W1Priority',value:7});
});
test('Ungültige Priorität sendet keine Geräteaktion', () => {
    const n = actions.length; fields1.priority.value = '0'; fields1.priority.change(); assert.equal(actions.length,n); assert.equal(elements.errors.hidden,false);
});
test('handleMessage aktualisiert Karten ohne Neuerzeugung', () => {
    const before = elements.boxes.children[0]; data.boxes[1].measured = 4100;
    context.window.handleMessage(JSON.stringify(data)); assert.equal(elements.boxes.children[0],before); assert.equal(fields1.measured.textContent,'4,1 kW');
});
test('Zeitbedingung und Messlücke erscheinen als Text', () => {
    assert.ok(fields2.reason.textContent.includes('Start in 20 s')); assert.equal(fields2.gap.textContent,'Messlücke');
});
test('Fehler-, Überziehungs- und Speicherzustände werden angezeigt', () => {
    data.errors = ['Speicher offline']; data.overrun = 500; data.battery.external = false; context.window.handleMessage(data);
    assert.equal(elements.errors.textContent,'Speicher offline'); assert.equal(elements.overrun.hidden,false); assert.equal(elements['battery-lock'].hidden,false);
});
test('Deaktivierte Plätze und optionaler Speicher werden ausgeblendet', () => {
    data.boxes[2].enabled = false; data.batteryConfigured = false; context.window.handleMessage(data);
    assert.equal(card2.hidden,true); assert.equal(elements.battery.hidden,true);
    assert.equal(elements['support-metric'].hidden,true);
});
console.log(count + ' JavaScript-Tests, 0 Fehler');
