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
    get firstChild() { return this.children[0] || null; }
    append(...elements) { this.children.push(...elements); }
    addEventListener(name, callback) { this.listeners[name] = callback; }
    removeEventListener(name, callback) { if (this.listeners[name] === callback) delete this.listeners[name]; }
    getAttribute(name) { return this[name] ?? null; }
    setAttribute(name, value) { this[name] = value; }
    querySelectorAll(selector) {
        assert.equal(selector, '[data-field]');
        const result = [];
        function walk(node) { if (node.dataset.field) result.push(node); node.children.forEach(walk); }
        this.children.forEach(walk);
        return result;
    }
    change() { this.listeners.change.call(this); }
    click() { this.listeners.click.call(this); }
}
const ids = ['wbm', 'status-pill', 'status-icon', 'mode-even', 'mode-priority', 'budget-label', 'base', 'support-metric', 'support', 'measured', 'energy', 'overrun', 'status', 'errors', 'boxes', 'battery',
    'battery-enable', 'battery-lock', 'soc', 'capacity', 'reserve-energy', 'battery-request', 'battery-power', 'battery-state', 'simulation', 'simulation-footer',
    'energy-open', 'energy-close'];
const elements = Object.fromEntries(ids.map(id => [id, new Element()]));
const document = {getElementById: id => {assert.ok(elements[id], id); return elements[id];}, createElement: tag => new Element(tag), createElementNS: (ns, tag) => new Element(tag), createTextNode: value => { const node = new Element('#text'); node.textContent = value; return node; }, activeElement: null};
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
let now = 1700000000000, nextTimer = 1;
const timers = new Map();
class ClockDate extends Date { static now() { return now; } }
function advance(ms) {
    const end = now + ms;
    while (true) {
        const due = [...timers].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
        if (!due) break;
        now = due[1].at; timers.delete(due[0]); due[1].fn();
    }
    now = end;
}
function deliver(message) { context.window.handleMessage(message); advance(2500); }
const context = {document, window:{WBM_INITIAL:data}, Map, Intl, Date:ClockDate, Number, Object, JSON, Error, String,
    setTimeout:(fn, delay) => { const id = nextTimer++; timers.set(id, {fn, at:now + delay}); return id; },
    clearTimeout:id => timers.delete(id),
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
    assert.equal(elements['budget-label'].textContent, 'Budget');
    assert.equal(elements.base.textContent, '12 kW'); assert.equal(elements.energy.textContent, '124,31 kWh');
    assert.ok(fields1.energy.textContent.includes('(berechnet)')); assert.equal(fields2.measured.textContent, 'Keine Messung');
    assert.ok(fields1.command.textContent.startsWith('7,0 A'));
    assert.ok(fields1.energy.textContent.startsWith('12,12 kWh'));
});
test('Namen werden als Text gesetzt; HTML wird nicht interpretiert', () => assert.equal(fields1.name.textContent, data.boxes[1].name));
test('Externe Sperre bleibt sichtbar und wird durch manuellen Klick nicht geändert', () => {
    assert.equal(fields1.lock.hidden, false); fields1.manual.click();
    assert.deepEqual(actions.at(-1), {ident:'W1Manual', value:false}); assert.equal(data.boxes[1].external, false);
});
test('Modus-, Speicher- und Prioritätsaktionen sind korrekt typisiert', () => {
    elements['mode-priority'].click(); assert.deepEqual(actions.at(-1), {ident:'DistributionMode',value:1});
    elements['mode-even'].click(); assert.deepEqual(actions.at(-1), {ident:'DistributionMode',value:0});
    elements['battery-enable'].checked = false; elements['battery-enable'].change(); assert.deepEqual(actions.at(-1), {ident:'BatteryEnabled',value:false});
    fields1.priority.value = '7'; fields1.priority.change(); assert.deepEqual(actions.at(-1), {ident:'W1Priority',value:7});
});
test('Ungültige Priorität sendet keine Geräteaktion', () => {
    const n = actions.length; fields1.priority.value = '0'; fields1.priority.change(); assert.equal(actions.length,n); assert.equal(elements.errors.hidden,false);
});
test('Zeitbedingung und Messlücke erscheinen als Text', () => {
    assert.ok(fields2.reason.textContent.includes('Start in 20 s')); assert.equal(fields2.gap.textContent,'Messlücke');
});
test('handleMessage aktualisiert Karten ohne Neuerzeugung', () => {
    const before = elements.boxes.children[0]; data.boxes[1].measured = 4100;
    deliver(JSON.stringify(data)); assert.equal(elements.boxes.children[0],before); assert.equal(fields1.measured.textContent,'4,1 kW');
    data.pvActive = true; deliver(data); assert.equal(elements['budget-label'].textContent, 'PV-Budget');
    data.pvActive = false; deliver(data); assert.equal(elements['budget-label'].textContent, 'Budget');
});
test('Fehler-, Überziehungs- und Speicherzustände werden angezeigt', () => {
    data.errors = ['Speicher offline']; data.overrun = 500; data.battery.external = false; deliver(data);
    assert.equal(elements.errors.textContent,'Speicher offline'); assert.equal(elements.overrun.hidden,false); assert.equal(elements['battery-lock'].hidden,false);
});
test('Deaktivierte Plätze und optionaler Speicher werden ausgeblendet', () => {
    data.boxes[2].enabled = false; data.batteryConfigured = false; deliver(data);
    assert.equal(card2.hidden,true); assert.equal(elements.battery.hidden,true);
    assert.equal(elements['support-metric'].hidden,true);
});
test('Wallboxkarte zeigt bestätigten Modus, manuellen Ladegang und Sollstrom je Phase', () => {
    data.mode = 1; data.boxes[1].manual = false; deliver(data);
    assert.equal(elements['mode-priority']['aria-pressed'], 'true');
    assert.equal(elements['mode-even']['aria-pressed'], 'false');
    assert.equal(fields1.manualText.textContent, 'Ladevorgang starten');
    assert.equal(fields1.manual['aria-pressed'], 'false');
    assert.equal(fields1.setpoint.textContent, 'Soll 7,0 A · 3 Phasen');
    assert.equal(fields1.billingRates.textContent, 'Preise nicht eingerichtet');
});
test('Tarife und Ladegangkosten sind ohne Abrechnungsdetails sichtbar und unterscheiden fehlende Preise', () => {
    data.boxes[1].billing = {enabled: true, mode: 'pv', pvPrice: .10, normalPrice: .32,
        active: {started: 1700000000, totalCost: 1.23, totalEnergy: 4.567}, last: null, pending: 0};
    deliver(data);
    assert.equal(fields1.billingRates.textContent, 'PV 10 ct/kWh · Normal 32 ct/kWh');
    assert.equal(fields1.billingMode.textContent, 'PV-Überschussladen');
    assert.equal(fields1.billingCostSummary.textContent, 'Ladevorgang: 1,23 € · 4,57 kWh');
    assert.equal(fields1.billingCostSummary.hidden, false);
    data.boxes[1].billing.mode = 'unassigned'; deliver(data);
    assert.equal(fields1.billingMode.textContent, 'Ungeklärt');
    data.boxes[1].billing.enabled = false; deliver(data);
    assert.equal(fields1.billingRates.textContent, 'Preise nicht eingerichtet');
    assert.equal(fields1.billingCostSummary.hidden, true);
});
test('Eingehende Signalfolgen werden alle 2500 ms auf den neuesten Stand zusammengefasst', () => {
    const before = fields1.measured.textContent;
    const snapshot = JSON.parse(JSON.stringify(data));
    const actionCount = actions.length;
    for (let i = 1; i <= 200; i++) {
        snapshot.boxes[1].measured = i * 10;
        context.window.handleMessage(JSON.stringify(snapshot));
    }
    assert.equal(fields1.measured.textContent, before);
    advance(2499); assert.equal(fields1.measured.textContent, before);
    advance(1); assert.equal(fields1.measured.textContent, '2 kW');
    assert.equal(timers.size, 1); assert.equal(actions.length, actionCount);
});
test('Verzögert angezeigte Countdown-Werte berücksichtigen die Empfangszeit', () => {
    const snapshot = JSON.parse(JSON.stringify(data));
    snapshot.boxes[2].remaining = 20;
    snapshot.boxes[2].enabled = true;
    deliver(JSON.stringify(snapshot));
    assert.ok(fields2.reason.textContent.includes('Start in 18 s'));
});
test('Start- und Stopp-Button sendet pro Wallbox nur die ausdrücklich gewählte Aktion', () => {
    data.boxes[1].manual = false; deliver(JSON.stringify(data));
    assert.equal(fields1.manual.tagName, 'BUTTON');
    assert.equal(fields1.manualText.textContent, 'Ladevorgang starten');
    fields1.manual.click(); assert.deepEqual(actions.at(-1), {ident:'W1Manual', value:true});
    data.boxes[1].manual = true; deliver(JSON.stringify(data));
    assert.equal(fields1.manualText.textContent, 'Ladevorgang stoppen');
    assert.equal(fields1.manual['aria-pressed'], 'true');
    fields1.manual.click(); assert.deepEqual(actions.at(-1), {ident:'W1Manual', value:false});
});
test('PV-Wechsel und Nullleistung ändern den bestätigten Startzustand des Buttons nicht', () => {
    const actionCount = actions.length;
    for (const pv of [true, false, true]) {
        data.pvActive = pv; data.base = 0; data.boxes[1].measured = 0;
        deliver(JSON.stringify(data));
        assert.equal(fields1.manualText.textContent, 'Ladevorgang stoppen');
        assert.equal(fields1.manual['aria-pressed'], 'true');
    }
    assert.equal(actions.length, actionCount);
});
test('Managerzustand wird ohne Schalter korrekt angezeigt und sendet keine Manageraktion', () => {
    const snapshot = JSON.parse(JSON.stringify(data)); const n = actions.length;
    snapshot.managerEnabled = false; snapshot.manager = true; snapshot.errors = []; snapshot.status = 'Manager ausgeschaltet';
    deliver(snapshot);
    assert.equal(elements['status-pill'].dataset.active, 'false');
    assert.equal(elements['status-icon'].href, '#wb-i-circle');
    assert.equal(elements.status.textContent, 'Manager ausgeschaltet');
    assert.equal(actions.length, n);
});
test('Aufräumen beendet den Anzeigetimer und entfernt Bedienereignisse', () => {
    context.window.WBM_TILE_CLEANUP();
    assert.equal(timers.size, 0);
    assert.equal(elements['energy-open'].listeners.click, undefined);
    const before = fields1.measured.textContent;
    data.boxes[1].measured = 9999;
    context.window.handleMessage(JSON.stringify(data)); advance(10000);
    assert.equal(fields1.measured.textContent, before);
});
console.log(count + ' JavaScript-Tests, 0 Fehler');
