(function () {
    'use strict';
    if (typeof window.WBM_TILE_CLEANUP === 'function') window.WBM_TILE_CLEANUP();
    // Keep only the newest SDK snapshot; device control runs independently in PHP.
    var DISPLAY_INTERVAL = window.WBM_WEB ? 1000 : 2500;
    var displayTimer = null;
    var pendingMessage = null;
    var pendingAt = 0;
    var disposed = false;
    var inView = true;
    var cleanup = [];
    var cards = new Map();
    var latest = null;
    var receivedAt = 0;
    var number = new Intl.NumberFormat('de-DE', { useGrouping: false, maximumFractionDigits: 2 });
    var energyFormat = new Intl.NumberFormat('de-DE', { useGrouping: false, minimumFractionDigits: 2, maximumFractionDigits: 2 });
    var ampereFormat = new Intl.NumberFormat('de-DE', { useGrouping: false, minimumFractionDigits: 1, maximumFractionDigits: 1 });
    var moneyFormat = new Intl.NumberFormat('de-DE', { useGrouping: false, minimumFractionDigits: 2, maximumFractionDigits: 2 });
    var priceFormat = new Intl.NumberFormat('de-DE', { maximumFractionDigits: 2 });
    var dateFormat = new Intl.DateTimeFormat('de-DE', {year: 'numeric', month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit'});
    var dashboardOpen = false;
    var energyItems = new Map();
    var selectedEnergy = null;
    var tooltipPoint = {x: .6, y: .4};
    var tooltipClient = null;
    var highlightFrame = 0;
    var highlightedEnergy;
    var chartBounds = null;
    var tooltipSize = null;
    var energySignature = '';
    var themeSignature = '';
    var brightnessCache = new Map();
    var colorContext = null;
    function on(target, name, callback, options, disposers) {
        target.addEventListener(name, callback, options);
        (disposers || cleanup).push(function () { if (target.removeEventListener) target.removeEventListener(name, callback, options); });
    }
    function frame(callback) {
        if (window.requestAnimationFrame) return window.requestAnimationFrame(callback);
        callback(); return 0;
    }
    function el(id) { return document.getElementById(id); }
    function put(node, value) { value = value == null ? '' : String(value); if (node.textContent !== value) node.textContent = value; }
    function assign(node, name, value) { if (node[name] !== value) node[name] = value; }
    function attribute(node, name, value) { if (node.getAttribute(name) !== value) node.setAttribute(name, value); }
    function datum(node, name, value) { if (node.dataset[name] !== value) node.dataset[name] = value; }
    function text(id, value) { put(el(id), value); }
    function power(value) { return value === null || value === undefined ? 'Keine Messung' : ampereFormat.format(value) + ' W'; }
    function chargingPower(value) { return value === null || value === undefined ? 'Keine Messung' : number.format(value / 1000) + ' kW'; }
    function energy(value) { return energyFormat.format(value || 0) + ' kWh'; }
    function compactLayout() {
        var root = document.getElementById('wbm');
        var height = root && root.clientHeight ? root.clientHeight - Number(root.dataset.headerSpace || 0) : window.innerHeight;
        var width = root && root.clientWidth ? root.clientWidth : window.innerWidth;
        return typeof height === 'number' && (height < 480 || width < 440);
    }
    function updateLayout() {
        syncHeaderSpace();
        chartBounds = tooltipSize = null;
        if (dashboardOpen) scheduleHighlight();
        var root = document.getElementById('wbm');
        if (!root) return;
        var compact = compactLayout();
        if (root.dataset.compact === String(compact)) return;
        root.dataset.compact = String(compact);
    }
    function syncHeaderSpace() {
        var root = el('wbm');
        if (!root || typeof root.getBoundingClientRect !== 'function' || typeof document.querySelectorAll !== 'function') return;
        var top = root.getBoundingClientRect().top;
        var found = false, bottom = 0;
        // Some SDK hosts overlay their header; others reserve its height in the body.
        document.querySelectorAll('header, [role="banner"]').forEach(function (header) {
            if (root.contains(header)) return;
            found = true;
            if (header.getClientRects().length) bottom = Math.max(bottom, header.getBoundingClientRect().bottom);
        });
        // The legacy header may live outside this document, including in a native WebView.
        var space = found ? Math.max(0, bottom - top) : (top < 32 ? Math.max(0, 64 - top) : 0);
        var value = Math.ceil(space) + 'px';
        if (root.style.getPropertyValue('--wb-header-space') !== value) root.style.setProperty('--wb-header-space', value);
        datum(root, 'headerSpace', String(Math.ceil(space)));
    }
    function themeParameter(names) {
        var parameters = typeof queryParameters !== 'undefined' ? queryParameters : null;
        var local = typeof URLSearchParams === 'function' && window.location ? new URLSearchParams(window.location.search) : null;
        for (var i = 0; i < names.length; i++) {
            var value = parameters && typeof parameters.get === 'function' ? parameters.get(names[i]) : null;
            value = value || (local && local.get(names[i]));
            if (value) return value;
        }
        return '';
    }
    function validColor(value) {
        value = (value || '').trim();
        if (/^[\da-f]{6}$/i.test(value)) value = '#' + value;
        return value && typeof CSS !== 'undefined' && CSS.supports('color', value) ? value : '';
    }
    function brightness(value) {
        if (!value) return null;
        if (brightnessCache.has(value)) return brightnessCache.get(value);
        if (!colorContext) colorContext = document.createElement('canvas').getContext('2d', {willReadFrequently: true});
        if (!colorContext) return null;
        colorContext.clearRect(0, 0, 1, 1); colorContext.fillStyle = value; colorContext.fillRect(0, 0, 1, 1);
        var pixel = colorContext.getImageData(0, 0, 1, 1).data;
        var result = pixel[3] === 0 ? null : (pixel[0] * .299 + pixel[1] * .587 + pixel[2] * .114);
        if (brightnessCache.size >= 32) brightnessCache.clear();
        brightnessCache.set(value, result); return result;
    }
    function syncTheme() {
        if (!document.documentElement || typeof getComputedStyle !== 'function') return;
        var root = document.documentElement;
        var styles = getComputedStyle(root);
        var foreground = validColor(styles.getPropertyValue('--content-color')) || validColor(themeParameter(['textcolor', 'textColor', 'contentColor']));
        var surface = validColor(styles.getPropertyValue('--card-color')) || validColor(themeParameter(['backgroundColor', 'backgroundcolor', 'cardColor']));
        var accent = validColor(styles.getPropertyValue('--accent-color')) || validColor(themeParameter(['accentColor', 'accentcolor']));
        var preferredDark = !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
        var signature = [foreground, surface, accent, preferredDark].join('|');
        if (signature === themeSignature) return;
        themeSignature = signature;
        [['--wb-host-text', foreground], ['--wb-host-surface', surface], ['--wb-host-accent', accent]].forEach(function (pair) {
            if (root.style.getPropertyValue(pair[0]) !== pair[1]) {
                if (pair[1]) root.style.setProperty(pair[0], pair[1]);
                else root.style.removeProperty(pair[0]);
            }
        });
        var backgroundBrightness = brightness(surface);
        var textBrightness = brightness(foreground);
        var dark = backgroundBrightness !== null ? backgroundBrightness < 128 : (textBrightness !== null ? textBrightness > 128 : preferredDark);
        var theme = dark ? 'dark' : 'light';
        if (root.dataset.wbTheme !== theme) root.dataset.wbTheme = theme;
        var onAccent = (accent ? brightness(accent) > 155 : dark) ? '#123b33' : '#fff';
        if (root.style.getPropertyValue('--wb-on-accent') !== onAccent) root.style.setProperty('--wb-on-accent', onAccent);
    }
    function action(ident, value) {
        try {
            if (typeof requestAction !== 'function') throw new Error('Keine Verbindung zur Symcon-Kachel');
            var pending = requestAction(ident, value);
            if (pending && typeof pending.catch === 'function') pending.catch(showError);
        } catch (error) { showError(error); }
    }
    function showError(error) {
        text('errors', 'Bedienaktion fehlgeschlagen: ' + (error.message || error));
        assign(el('errors'), 'hidden', false);
    }
    function icon(name) {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        attribute(svg, 'class', 'wb-icon'); attribute(svg, 'viewBox', '0 0 24 24'); attribute(svg, 'aria-hidden', 'true');
        var use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
        attribute(use, 'href', '#wb-i-' + name); svg.append(use);
        return svg;
    }
    function tone(box) {
        if (box.fault || box.status === 'Fehler') return 'error';
        if (!box.manual || !box.enabled) return 'idle';
        if (box.status === 'Laden') return 'charging';
        if (box.status === 'Gesperrt' || box.external === false) return 'idle';
        return 'waiting';
    }
    function metric(label, key) {
        var div = document.createElement('div');
        var span = document.createElement('span');
        put(span, label);
        var strong = document.createElement('strong');
        strong.dataset.field = key;
        div.append(span, strong);
        return div;
    }
    function downloadBilling(id) {
        try {
            var box = latest && latest.boxes[id];
            var bill = box && box.billing;
            if (!bill || (!bill.pdf && !bill.pdfURL)) return;
            if (window.WBM_WEB && bill.pdfURL) {
                var download = document.createElement('a'); download.href = bill.pdfURL;
                download.download = (bill.last.fileName || 'Abrechnung.pdf').replace(/[^a-zA-Z0-9._-]/g, '_');
                document.body.append(download); download.click(); download.remove(); return;
            }
            var bytes = Uint8Array.from(atob(bill.pdf), function (ch) { return ch.charCodeAt(0); });
            var url = URL.createObjectURL(new Blob([bytes], {type: 'application/pdf'}));
            var link = document.createElement('a');
            link.href = url; link.download = (bill.last.fileName || 'Wallbox-' + id + '-Abrechnung.pdf').replace(/[^a-zA-Z0-9._-]/g, '_');
            document.body.append(link); link.click(); link.remove();
            setTimeout(function () { URL.revokeObjectURL(url); }, 10000);
        } catch (error) { showError(error); }
    }
    function billingDetails(id) {
        var details = document.createElement('section'); details.className = 'billing-details'; details.dataset.field = 'billing'; assign(details, 'hidden', true);
        var period = document.createElement('p'); period.dataset.field = 'billingPeriod';
        var values = document.createElement('div'); values.className = 'device-secondary';
        values.append(metric('PV-Überschussladen', 'billingPV'), metric('Normalladen', 'billingNormal'), metric('Gesamtbetrag', 'billingCost'));
        var unknown = document.createElement('p'); unknown.dataset.field = 'billingUnknown'; unknown.className = 'lock';
        var status = document.createElement('p'); status.dataset.field = 'billingState';
        var button = document.createElement('button'); button.type = 'button'; button.className = 'wb-button';
        button.dataset.field = 'billingPDF'; put(button, 'Letzte Abrechnung als PDF'); assign(button, 'disabled', true);
        on(button, 'click', function () { downloadBilling(id); });
        details.append(period, values, unknown, status, button);
        return details;
    }
    function renderBilling(fields, billing) {
        var enabled = !!(billing && billing.enabled);
        assign(fields.billing, 'hidden', !enabled);
        assign(fields.billingCostSummary, 'hidden', !enabled);
        assign(fields.billingMode, 'hidden', !enabled);
        assign(fields.billingModeRow, 'hidden', !enabled);
        assign(fields.billingCostSummaryRow, 'hidden', !enabled);
        put(fields.detailsLabel, enabled ? 'Details & Rechnung' : 'Details');
        if (!enabled) { put(fields.billingRates, 'Preise nicht eingerichtet'); return; }
        var session = billing.active || billing.last;
        var money = function (v) { return moneyFormat.format(v || 0) + ' €'; };
        put(fields.billingPeriod, session ? (billing.active ? 'Laufender Ladegang seit ' : 'Letzter Ladegang vom ') + dateFormat.format(new Date(session.started * 1000)) : 'Noch kein Ladegang erfasst');
        put(fields.billingPV, energy(session && session.pvEnergy) + ' · ' + money(session && session.pvCost));
        put(fields.billingNormal, energy(session && session.normalEnergy) + ' · ' + money(session && session.normalCost));
        put(fields.billingCost, money(session && session.totalCost));
        var mode = billing.mode === 'pv' ? 'PV-Überschussladen' : (billing.mode === 'unassigned' ? 'Ungeklärt' : 'Normalladen');
        put(fields.billingMode, mode);
        datum(fields.billingMode, 'mode', billing.mode);
        put(fields.billingRates, 'PV ' + priceFormat.format(billing.pvPrice * 100) + ' ct/kWh · Normal ' + priceFormat.format(billing.normalPrice * 100) + ' ct/kWh');
        put(fields.billingCostSummary, session ? (billing.active ? 'Ladevorgang: ' : 'Letzter Ladevorgang: ') + money(session.totalCost) + ' · ' + energy(session.totalEnergy) : 'Noch kein Ladegang erfasst');
        assign(fields.billingUnknown, 'hidden', !(session && session.unassignedEnergy > 0));
        put(fields.billingUnknown, 'Tarif ungeklärt: ' + energy(session && session.unassignedEnergy) + ' – Abrechnung prüfen');
        var billingState = billing.error || (billing.pending ? 'PDF wird erstellt …' : 'Energie aus gemessener Ladeleistung berechnet.');
        if (session && session.warnings && session.warnings.length) billingState += ' Hinweise im PDF beachten.';
        put(fields.billingState, billingState);
        assign(fields.billingPDF, 'disabled', !(billing.pdf || (window.WBM_WEB && billing.pdfURL)));
        put(fields.billingPDF, billing.last && billing.last.invoice ? 'Rechnung ' + billing.last.invoice.number + ' als PDF' : 'Letzte Abrechnung als PDF');
    }
    function createCard(id) {
        var card = document.createElement('article'); card.className = 'box';
        var head = document.createElement('div'); head.className = 'box-top';
        var heading = document.createElement('div'); heading.className = 'box-heading';
        var identity = document.createElement('div'); identity.className = 'box-identity';
        var title = document.createElement('h2'); title.className = 'box-title'; title.dataset.field = 'name';
        var badge = document.createElement('span'); badge.className = 'badge';
        var badgeIcon = icon('circle'); badgeIcon.firstChild.dataset.field = 'statusIcon';
        var badgeText = document.createElement('span'); badgeText.dataset.field = 'status';
        badge.append(badgeIcon, badgeText); identity.append(title, badge); heading.append(identity);
        var manual = document.createElement('button'); manual.type = 'button'; manual.className = 'wb-button session-button'; manual.dataset.field = 'manual';
        var manualIcon = icon('play'); manualIcon.firstChild.dataset.field = 'manualIcon';
        var manualText = document.createElement('span'); manualText.dataset.field = 'manualText'; manual.append(manualIcon, manualText);
        on(manual, 'click', function () { action('W' + id + 'Manual', manual.dataset.active !== 'true'); });
        head.append(heading, manual);
        var body = document.createElement('div'); body.className = 'box-body';
        var reading = document.createElement('div'); reading.className = 'box-reading';
        var readingValues = document.createElement('div');
        var readingLabel = document.createElement('span'); readingLabel.className = 'reading-label'; put(readingLabel, 'Ladeleistung');
        var measured = document.createElement('strong'); measured.className = 'box-measured'; measured.dataset.field = 'measured';
        readingValues.append(readingLabel, measured); reading.append(icon('bolt'), readingValues);
        var request = document.createElement('div'); request.className = 'box-request';
        var requestLabel = document.createElement('span'); requestLabel.className = 'reading-label'; requestLabel.append(icon('plug'), document.createTextNode('Sollwert'));
        var setpoint = document.createElement('span'); setpoint.dataset.field = 'setpoint'; setpoint.className = 'box-setpoint'; request.append(requestLabel, setpoint);
        var info = document.createElement('div'); info.className = 'box-info';
        function infoRow(symbolName, key, label) {
            var row = document.createElement('p'); row.className = 'box-info-row'; row.dataset.field = key + 'Row';
            var content = document.createElement('span');
            if (label) { var caption = document.createElement('span'); put(caption, label); content.append(caption); }
            var value = document.createElement('span'); value.dataset.field = key; content.append(value);
            row.append(icon(symbolName), content); return row;
        }
        info.append(infoRow('car', 'billingMode', 'Lademodus: '), infoRow('database', 'billingRates', 'Tarif: '), infoRow('chart', 'billingCostSummary', ''));
        body.append(reading, request, info);
        var lock = document.createElement('p'); lock.className = 'lock'; lock.dataset.field = 'lock';
        put(lock, 'Extern gesperrt – der gestartete Vorgang bleibt erhalten');
        var reason = document.createElement('p'); reason.className = 'reason'; reason.dataset.field = 'reason';
        var gap = document.createElement('p'); gap.className = 'gap'; gap.dataset.field = 'gap';
        var details = document.createElement('details'); details.className = 'device-details';
        var summary = document.createElement('summary');
        var summaryText = document.createElement('span'); summaryText.dataset.field = 'detailsLabel'; put(summaryText, 'Details');
        summary.append(icon('chevron'), summaryText);
        var metrics = document.createElement('div'); metrics.className = 'device-metrics'; metrics.append(metric('Sollwert', 'command'), metric('Aktive Phasen', 'phases'));
        var secondary = document.createElement('div'); secondary.className = 'device-secondary'; secondary.append(metric('Geladene Energie', 'energy'));
        var controls = document.createElement('div'); controls.className = 'device-controls';
        var priorityLabel = document.createElement('label'); priorityLabel.className = 'priority-label'; put(priorityLabel, 'Priorität');
        var priority = document.createElement('input'); priority.type = 'number'; priority.min = '1'; priority.max = '999'; priority.step = '1'; priority.dataset.field = 'priority';
        on(priority, 'change', function () {
            var v = Number(priority.value);
            if (Number.isInteger(v) && v >= 1 && v <= 999) action('W' + id + 'Priority', v);
            else showError(new Error('Priorität muss zwischen 1 und 999 liegen'));
        });
        priorityLabel.append(priority); controls.append(priorityLabel);
        details.append(summary, metrics, secondary, controls, billingDetails(id));
        card.append(head, body, lock, reason, gap, details);
        var fields = {};
        card.querySelectorAll('[data-field]').forEach(function (node) { fields[node.dataset.field] = node; });
        cards.set(id, {node: card, fields: fields, details: details}); el('boxes').append(card);
        return cards.get(id);
    }
    function render(data, arrival) {
        if (!data || !data.boxes) return;
        latest = data;
        receivedAt = arrival;
        var managerEnabled = !!(data.managerEnabled === undefined ? data.manager : data.managerEnabled);
        attribute(el('mode-even'), 'aria-pressed', String(data.mode === 0));
        attribute(el('mode-priority'), 'aria-pressed', String(data.mode === 1));
        text('budget-label', data.pvActive ? 'PV-Budget' : 'Budget');
        text('base', chargingPower(data.base));
        text('support', chargingPower(data.battery.confirmed));
        assign(el('support-metric'), 'hidden', !data.batteryConfigured);
        text('measured', chargingPower(data.measured));
        text('energy', energy(data.energy));
        text('status', data.status);
        datum(el('status-pill'), 'active', String(managerEnabled));
        datum(el('status-pill'), 'error', String(!!(data.errors && data.errors.length)));
        attribute(el('status-icon'), 'href', '#wb-i-' + (data.errors && data.errors.length ? 'alert' : (managerEnabled ? 'check' : 'circle')));
        assign(el('errors'), 'hidden', !data.errors || data.errors.length === 0);
        text('errors', (data.errors || []).join('\n'));
        assign(el('overrun'), 'hidden', !(data.overrun > 0));
        text('overrun', 'Zeitweilige Budgetüberschreitung: ' + power(data.overrun));
        Object.values(data.boxes).forEach(function (box) {
            var item = cards.get(box.id) || createCard(box.id);
            assign(item.node, 'hidden', !box.enabled);
            datum(item.node, 'state', box.status);
            var stateTone = tone(box); datum(item.node, 'tone', stateTone);
            attribute(item.fields.statusIcon, 'href', '#wb-i-' + ({error: 'alert', idle: 'circle', charging: 'bolt', waiting: 'clock'}[stateTone]));
            var f = item.fields;
            put(f.name, box.name);
            put(f.status, box.status);
            assign(f.lock, 'hidden', box.external);
            datum(f.manual, 'active', String(!!box.manual));
            attribute(f.manual, 'aria-pressed', String(!!box.manual));
            put(f.manualText, box.manual ? 'Ladevorgang stoppen' : 'Ladevorgang starten');
            attribute(f.manualIcon, 'href', '#wb-i-' + (box.manual ? 'stop' : 'play'));
            assign(f.manual, 'disabled', !box.enabled);
            if (document.activeElement !== f.priority) assign(f.priority, 'value', String(box.priority));
            put(f.measured, chargingPower(box.measured));
            put(f.command, (box.unit === 'kW' ? number : ampereFormat).format(box.command) + ' ' + box.unit);
            put(f.setpoint, 'Soll ' + f.command.textContent + (box.phases ? ' · ' + box.phases + (box.phases === 1 ? ' Phase' : ' Phasen') : ''));
            attribute(f.manual, 'aria-label', box.name + ': ' + f.manualText.textContent);
            put(f.energy, energy(box.energy) + (box.energyMethod === 'berechnet' ? ' (berechnet)' : ''));
            put(f.phases, box.phases ? String(box.phases) : 'Unbestätigt');
            put(f.gap, box.gap || '');
            assign(f.gap, 'hidden', !box.gap);
            renderBilling(f, box.billing);
        });
        datum(el('boxes'), 'count', String(Object.values(data.boxes).filter(function (box) { return box.enabled; }).length));
        assign(el('battery'), 'hidden', !data.batteryConfigured);
        assign(el('battery-enable'), 'checked', !!data.batteryEnabled);
        assign(el('battery-lock'), 'hidden', data.battery.external);
        text('soc', data.battery.soc === null ? 'Keine Messung' : number.format(data.battery.soc) + ' %');
        text('capacity', energy(data.battery.capacity));
        text('reserve-energy', energy(data.battery.energy));
        text('battery-request', power(data.battery.commanded));
        text('battery-power', power(data.battery.measured));
        text('battery-state', data.battery.reason);
        assign(el('simulation-footer'), 'hidden', !data.simulation);
        countdown();
        if (dashboardOpen) renderEnergyDashboard();
    }
    function nonnegative(value) { return typeof value === 'number' && Number.isFinite(value) ? Math.max(0, value) : 0; }
    function dashboardData() {
        var colors = ['#e4ab00', '#b58b00', '#826800', '#ffbf00'];
        var rows = Object.values(latest.boxes).map(function (box) {
            var archived = nonnegative(box.archivedEnergy);
            return {key: String(box.id), name: box.name, value: nonnegative(box.energy) + archived,
                enabled: box.enabled, archived: archived, method: box.energyMethod,
                color: colors[(Number(box.id) - 1) % colors.length] || colors[0]};
        }).filter(function (row) { return row.enabled || row.value > 0; });
        var sum = rows.reduce(function (total, row) { return total + row.value; }, 0);
        var total = nonnegative(latest.energy);
        // Older telemetry may include archived devices only in the global counter.
        if (total - sum > .000001) rows.push({key: 'previous', name: 'Weitere / archivierte Energie', value: total - sum, color: '#bfbab0', method: ''});
        var denominator = Math.max(total, sum);
        rows.forEach(function (row) { row.percent = denominator > 0 ? row.value / denominator * 100 : 0; });
        return {rows: rows, total: total, denominator: denominator, inconsistent: sum - total > .000001};
    }
    function segmentPath(start, end) {
        // A tiny separator keeps neighbouring segments readable, including a full ring.
        var gap = Math.min(.012, (end - start) / 4);
        start += gap; end -= gap;
        function point(angle) { return [200 + 145 * Math.sin(angle), 200 - 145 * Math.cos(angle)]; }
        var a = point(start), b = point(end);
        return 'M ' + a.join(' ') + ' A 145 145 0 ' + (end - start > Math.PI ? '1' : '0') + ' 1 ' + b.join(' ');
    }
    function selectEnergy(key, event) {
        selectedEnergy = energyItems.has(key) ? key : null;
        if (event && typeof event.clientX === 'number') {
            tooltipClient = {x: event.clientX, y: event.clientY};
        } else { tooltipClient = null; tooltipPoint = {x: .55, y: .3}; }
        scheduleHighlight();
    }
    function scheduleHighlight() {
        if (highlightFrame || paused() || !dashboardOpen) return;
        highlightFrame = frame(function () { highlightFrame = 0; highlightEnergy(); });
    }
    function highlightEnergy() {
        if (selectedEnergy !== highlightedEnergy) {
            energyItems.forEach(function (item, key) {
                item.segment.classList.toggle('is-active', key === selectedEnergy);
                item.segment.classList.toggle('is-muted', selectedEnergy !== null && key !== selectedEnergy);
                item.legend.classList.toggle('is-active', key === selectedEnergy);
                item.row.classList.toggle('is-active', key === selectedEnergy);
            });
            highlightedEnergy = selectedEnergy;
        }
        var tooltip = el('energy-tooltip');
        var item = energyItems.get(selectedEnergy);
        assign(tooltip, 'hidden', !item);
        if (!item) return;
        var label = item.data.name + ': ' + energy(item.data.value) + '\n' + number.format(item.data.percent) + ' %';
        if (tooltip.textContent !== label) { put(tooltip, label); tooltipSize = null; }
        var bounds = chartBounds || (chartBounds = el('energy-chart-wrap').getBoundingClientRect());
        if (tooltipClient) {
            tooltipPoint = {x: (tooltipClient.x - bounds.left) / bounds.width, y: (tooltipClient.y - bounds.top) / bounds.height};
            tooltipClient = null;
        }
        var size = tooltipSize || (tooltipSize = {width: tooltip.offsetWidth, height: tooltip.offsetHeight});
        var left = Math.min(Math.max(8, tooltipPoint.x * bounds.width + 12), Math.max(8, bounds.width - size.width - 8));
        var top = Math.min(Math.max(8, tooltipPoint.y * bounds.height + 12), Math.max(8, bounds.height - size.height - 8));
        var transform = 'translate(' + Math.round(left) + 'px,' + Math.round(top) + 'px)';
        if (tooltip.style.transform !== transform) tooltip.style.transform = transform;
    }
    function makeEnergyItem(row) {
        var events = [];
        var segment = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        attribute(segment, 'class', 'energy-segment');
        attribute(segment, 'fill', 'none'); attribute(segment, 'stroke-width', '56');
        attribute(segment, 'tabindex', '0'); attribute(segment, 'role', 'img');
        segment.dataset.energyKey = row.key;
        var legend = document.createElement('button');
        legend.type = 'button'; legend.className = 'legend-button';
        var dot = document.createElement('span'); dot.className = 'energy-dot'; attribute(dot, 'aria-hidden', 'true');
        var name = document.createElement('span'); name.className = 'legend-label';
        legend.append(dot, name);
        var line = document.createElement('button');
        line.type = 'button'; line.className = 'energy-row';
        var rowName = document.createElement('span'); rowName.className = 'energy-row-name';
        var rowDot = document.createElement('span'); rowDot.className = 'energy-dot'; attribute(rowDot, 'aria-hidden', 'true');
        var label = document.createElement('span'); rowName.append(rowDot, label);
        var values = document.createElement('span'); values.className = 'energy-row-values';
        var amount = document.createElement('span'); var detail = document.createElement('small');
        values.append(amount, detail); line.append(rowName, values);
        [segment, legend, line].forEach(function (node) {
            on(node, 'pointerenter', function (event) { selectEnergy(row.key, node === segment ? event : null); }, undefined, events);
            on(node, 'pointermove', function (event) { if (node === segment) selectEnergy(row.key, event); }, undefined, events);
            on(node, 'pointerleave', function () { if (selectedEnergy === row.key && document.activeElement !== node) selectEnergy(null); }, undefined, events);
            on(node, 'focus', function () { selectEnergy(row.key); }, undefined, events);
            on(node, 'blur', function () { if (selectedEnergy === row.key) selectEnergy(null); }, undefined, events);
            on(node, 'click', function () { selectEnergy(row.key); }, undefined, events);
        });
        el('energy-segments').append(segment); el('energy-legend').append(legend); el('energy-rows').append(line);
        var item = {segment: segment, legend: legend, row: line, name: name, label: label, amount: amount, detail: detail, data: row, dispose: function () { events.forEach(function (stop) { stop(); }); }};
        energyItems.set(row.key, item);
        return item;
    }
    function renderEnergyDashboard() {
        if (!latest) return;
        var data = dashboardData();
        data.rows.forEach(function (row) { var item = energyItems.get(row.key); if (item) item.data = row; });
        var signature = JSON.stringify([energy(data.total), data.inconsistent, data.rows.map(function (row) {
            return [row.key, row.name, energy(row.value), number.format(row.percent), row.enabled, row.archived > 0, row.method];
        })]);
        // Keep SVG nodes and focus stable when the displayed energy values did not change.
        if (signature === energySignature) return;
        energySignature = signature;
        chartBounds = tooltipSize = null; highlightedEnergy = undefined;
        text('dashboard-total', energy(data.total)); text('dashboard-summary-total', energy(data.total));
        assign(el('energy-empty'), 'hidden', data.denominator > 0);
        var keys = data.rows.map(function (row) { return row.key; });
        energyItems.forEach(function (item, key) {
            if (keys.indexOf(key) !== -1) return;
            item.dispose(); item.segment.remove(); item.legend.remove(); item.row.remove(); energyItems.delete(key);
            if (selectedEnergy === key) selectedEnergy = null;
        });
        var start = 0;
        data.rows.forEach(function (row) {
            var item = energyItems.get(row.key) || makeEnergyItem(row);
            item.data = row;
            var angle = row.percent / 100 * Math.PI * 2;
            attribute(item.segment, 'd', segmentPath(start, start + angle)); start += angle;
            item.segment.style.display = row.value > 0 ? '' : 'none';
            attribute(item.segment, 'stroke', row.color);
            var description = row.name + ': ' + energy(row.value) + ', ' + number.format(row.percent) + ' %';
            attribute(item.segment, 'aria-label', description);
            attribute(item.legend, 'aria-label', description); attribute(item.row, 'aria-label', description);
            item.legend.style.setProperty('--segment-color', row.color); item.row.style.setProperty('--segment-color', row.color);
            put(item.name, row.name); put(item.label, row.name);
            put(item.amount, energy(row.value));
            put(item.detail, number.format(row.percent) + ' %' + (row.method === 'berechnet' ? ' · berechnet' : '') + (row.enabled === false ? ' · deaktiviert' : ''));
        });
        var note = 'Fortlaufende Zählerstände bis zur letzten Aktualisierung. Ein Energie-Reset beginnt den Zählzeitraum neu.';
        if (data.rows.some(function (row) { return row.archived > 0; })) note += ' Frühere Geräte am selben Wallbox-Platz sind enthalten.';
        if (data.inconsistent) note += ' Die Wallbox-Summe weicht vom Gesamtzähler ab; die Anteile beziehen sich auf die Wallbox-Summe.';
        text('energy-note', note);
        scheduleHighlight();
    }
    function openDashboard() {
        dashboardOpen = true;
        attribute(el('energy-open'), 'aria-expanded', 'true');
        chartBounds = tooltipSize = null; energySignature = '';
        assign(el('tile-view'), 'hidden', true); assign(el('energy-dashboard'), 'hidden', false);
        renderEnergyDashboard(); el('energy-close').focus();
    }
    function closeDashboard() {
        selectEnergy(null); highlightEnergy(); dashboardOpen = false;
        attribute(el('energy-open'), 'aria-expanded', 'false');
        assign(el('energy-dashboard'), 'hidden', true); assign(el('tile-view'), 'hidden', false);
        el('energy-open').focus();
    }
    function countdown() {
        if (!latest) return;
        var elapsed = Math.floor((Date.now() - receivedAt) / 1000);
        Object.values(latest.boxes).forEach(function (box) {
            var item = cards.get(box.id);
            if (!item) return;
            var remaining = Math.max(0, box.remaining - elapsed);
            var reason = box.reason || box.status;
            if (remaining > 0) reason += box.status === 'Einschaltverzögerung' ? ' – Start in ' + remaining + ' s' : ' – noch ' + remaining + ' s';
            put(item.fields.reason, reason);
            assign(item.fields.reason, 'hidden', remaining === 0 && reason === box.status);
        });
    }
    function paused() { return disposed || !inView || document.hidden === true; }
    function pauseDisplay() {
        if (displayTimer !== null) clearTimeout(displayTimer);
        displayTimer = null;
        if (highlightFrame && window.cancelAnimationFrame) window.cancelAnimationFrame(highlightFrame);
        highlightFrame = 0;
    }
    function updateDisplay() {
        displayTimer = null;
        if (paused()) return;
        if (pendingMessage !== null) {
            var message = pendingMessage; pendingMessage = null;
            try { render(typeof message === 'string' ? JSON.parse(message) : message, pendingAt); }
            catch (error) { showError(error); }
        } else countdown();
        syncTheme(); syncHeaderSpace();
        displayTimer = setTimeout(updateDisplay, DISPLAY_INTERVAL);
    }
    function resumeDisplay() {
        if (paused()) return;
        pauseDisplay(); updateDisplay();
        if (dashboardOpen) scheduleHighlight();
    }
    window.handleMessage = function (message) {
        if (disposed) return;
        // Defer parsing too: telemetry may contain a large PDF payload.
        pendingMessage = message; pendingAt = Date.now();
        if (!latest && !paused()) resumeDisplay();
    };
    on(el('mode-even'), 'click', function () { action('DistributionMode', 0); });
    on(el('mode-priority'), 'click', function () { action('DistributionMode', 1); });
    on(el('battery-enable'), 'change', function () { action('BatteryEnabled', this.checked); });
    on(el('energy-open'), 'click', openDashboard);
    on(el('energy-close'), 'click', closeDashboard);
    if (typeof document.addEventListener === 'function') {
        on(document, 'keydown', function (event) {
            if (!dashboardOpen) return;
            if (event.key === 'Escape') { event.preventDefault(); closeDashboard(); }
            if (event.key === 'Tab') {
                var nodes = Array.from(el('energy-dashboard').querySelectorAll('button, a[href], input, select, textarea, [tabindex="0"]')).filter(function (node) { return !node.disabled && node.getClientRects().length > 0; });
                var first = nodes[0], last = nodes[nodes.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        });
    }
    syncTheme();
    updateLayout();
    if (typeof ResizeObserver === 'function' && document.getElementById('wbm')) {
        var resizeObserver = new ResizeObserver(updateLayout); resizeObserver.observe(el('wbm'));
        cleanup.push(function () { resizeObserver.disconnect(); });
    }
    if (typeof window.addEventListener === 'function') {
        on(window, 'resize', function () { updateLayout(); syncTheme(); });
        if (window.matchMedia) {
            var scheme = window.matchMedia('(prefers-color-scheme: dark)');
            if (scheme.addEventListener) on(scheme, 'change', syncTheme);
        }
    }
    if (typeof MutationObserver === 'function' && document.documentElement) {
        var observer = new MutationObserver(function () { syncTheme(); syncHeaderSpace(); });
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['style', 'class', 'data-theme'] });
        observer.observe(document.body, { attributes: true, childList: true, attributeFilter: ['style', 'class', 'data-theme'] });
        observer.observe(document.head, { childList: true, subtree: true, characterData: true });
        cleanup.push(function () { observer.disconnect(); });
    }
    if (typeof document.addEventListener === 'function') {
        on(document, 'visibilitychange', function () { if (document.hidden) pauseDisplay(); else resumeDisplay(); });
        on(el('energy-dashboard').querySelector('.dashboard-scroll'), 'scroll', function () { chartBounds = null; }, {passive: true});
    }
    if (typeof IntersectionObserver === 'function') {
        var visibilityObserver = new IntersectionObserver(function (entries) {
            var visible = entries[0].isIntersecting;
            if (inView === visible) return;
            inView = visible; if (visible) resumeDisplay(); else pauseDisplay();
        });
        visibilityObserver.observe(el('wbm')); cleanup.push(function () { visibilityObserver.disconnect(); });
    }
    window.WBM_TILE_CLEANUP = function () {
        disposed = true; pauseDisplay();
        energyItems.forEach(function (item) { item.dispose(); });
        cleanup.forEach(function (stop) { stop(); });
    };
    window.handleMessage(window.WBM_INITIAL);
    action('Refresh', 0);
}());
