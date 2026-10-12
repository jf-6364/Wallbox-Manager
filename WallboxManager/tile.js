(function () {
    'use strict';
    var cards = new Map();
    var latest = null;
    var receivedAt = 0;
    var number = new Intl.NumberFormat('de-DE', { maximumFractionDigits: 2 });
    var energyFormat = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    var ampereFormat = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    function el(id) { return document.getElementById(id); }
    function text(id, value) { el(id).textContent = value; }
    function power(value) { return value === null || value === undefined ? 'Keine Messung' : number.format(value / 1000) + ' kW'; }
    function energy(value) { return energyFormat.format(value || 0) + ' kWh'; }
    function compactLayout() {
        var root = document.getElementById('wbm');
        var height = root && root.clientHeight ? root.clientHeight : window.innerHeight;
        var width = root && root.clientWidth ? root.clientWidth : window.innerWidth;
        return typeof height === 'number' && (height < 480 || width < 440);
    }
    function updateLayout() {
        var root = document.getElementById('wbm');
        if (!root) return;
        var compact = compactLayout();
        if (root.dataset.compact === String(compact)) return;
        root.dataset.compact = String(compact);
        cards.forEach(function (item) { item.details.open = !compact; });
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
        var canvas = document.createElement('canvas');
        var context = canvas.getContext('2d');
        if (!context) return null;
        context.fillStyle = value;
        context.fillRect(0, 0, 1, 1);
        var pixel = context.getImageData(0, 0, 1, 1).data;
        return pixel[3] === 0 ? null : (pixel[0] * .299 + pixel[1] * .587 + pixel[2] * .114);
    }
    function syncTheme() {
        if (!document.documentElement || typeof getComputedStyle !== 'function') return;
        var root = document.documentElement;
        var styles = getComputedStyle(root);
        var foreground = validColor(styles.getPropertyValue('--content-color')) || validColor(themeParameter(['textcolor', 'textColor', 'contentColor']));
        var surface = validColor(styles.getPropertyValue('--card-color')) || validColor(themeParameter(['backgroundColor', 'backgroundcolor', 'cardColor']));
        var accent = validColor(styles.getPropertyValue('--accent-color')) || validColor(themeParameter(['accentColor', 'accentcolor']));
        [['--wb-host-text', foreground], ['--wb-host-surface', surface], ['--wb-host-accent', accent]].forEach(function (pair) {
            if (root.style.getPropertyValue(pair[0]) !== pair[1]) {
                if (pair[1]) root.style.setProperty(pair[0], pair[1]);
                else root.style.removeProperty(pair[0]);
            }
        });
        var backgroundBrightness = brightness(surface);
        var textBrightness = brightness(foreground);
        var dark = backgroundBrightness !== null ? backgroundBrightness < 128 : (textBrightness !== null ? textBrightness > 128 : !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches));
        var theme = dark ? 'dark' : 'light';
        if (root.dataset.wbTheme !== theme) root.dataset.wbTheme = theme;
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
        el('errors').hidden = false;
    }
    function metric(label, key) {
        var div = document.createElement('div');
        var span = document.createElement('span');
        span.textContent = label;
        var strong = document.createElement('strong');
        strong.dataset.field = key;
        div.append(span, strong);
        return div;
    }
    function createCard(id) {
        var card = document.createElement('article');
        card.className = 'box';
        var head = document.createElement('div');
        head.className = 'card-heading';
        var title = document.createElement('h2');
        title.className = 'box-title';
        title.dataset.field = 'name';
        var badge = document.createElement('span');
        badge.className = 'badge';
        badge.dataset.field = 'status';
        head.append(title, badge);
        var lock = document.createElement('p');
        lock.className = 'lock';
        lock.dataset.field = 'lock';
        lock.textContent = 'Extern gesperrt – manuelle Freigabe hebt die Sperre nicht auf';
        var metrics = document.createElement('div');
        metrics.className = 'device-metrics';
        metrics.append(metric('Tatsächlich laden', 'measured'), metric('Sollwert / Anforderung', 'command'));
        var details = document.createElement('details');
        details.className = 'device-details';
        details.open = !compactLayout();
        var summary = document.createElement('summary');
        summary.textContent = 'Energie und Phasen';
        var secondary = document.createElement('div');
        secondary.className = 'device-secondary';
        secondary.append(metric('Geladene Energie', 'energy'), metric('Aktive Phasen', 'phases'));
        details.append(summary, secondary);
        var controls = document.createElement('div');
        controls.className = 'device-controls';
        var manualLabel = document.createElement('label');
        manualLabel.className = 'switch';
        var manual = document.createElement('input');
        manual.type = 'checkbox';
        manual.dataset.field = 'manual';
        manual.addEventListener('change', function () { action('W' + id + 'Manual', manual.checked); });
        var manualText = document.createElement('span');
        manualText.textContent = 'Freigabe';
        manualLabel.append(manual, manualText);
        var priorityLabel = document.createElement('label');
        priorityLabel.className = 'priority-label';
        priorityLabel.textContent = 'Priorität';
        var priority = document.createElement('input');
        priority.type = 'number';
        priority.min = '1'; priority.max = '999'; priority.step = '1';
        priority.dataset.field = 'priority';
        priority.addEventListener('change', function () {
            var v = Number(priority.value);
            if (Number.isInteger(v) && v >= 1 && v <= 999) action('W' + id + 'Priority', v);
            else showError(new Error('Priorität muss zwischen 1 und 999 liegen'));
        });
        priorityLabel.append(priority);
        controls.append(manualLabel, priorityLabel);
        var reason = document.createElement('p');
        reason.className = 'reason'; reason.dataset.field = 'reason';
        var gap = document.createElement('p');
        gap.className = 'gap'; gap.dataset.field = 'gap';
        card.append(head, lock, metrics, details, reason, gap, controls);
        var fields = {};
        card.querySelectorAll('[data-field]').forEach(function (node) { fields[node.dataset.field] = node; });
        cards.set(id, { node: card, fields: fields, details: details });
        el('boxes').append(card);
        return cards.get(id);
    }
    function render(data) {
        if (!data || !data.boxes) return;
        latest = data;
        receivedAt = Date.now();
        el('manager').checked = !!data.managerEnabled;
        if (data.managerEnabled === undefined) el('manager').checked = !!data.manager;
        el('mode').value = String(data.mode);
        text('policy', data.strict ? 'Budget strikt' : 'Zeitweise Überschreitung zugelassen');
        text('base', power(data.base));
        text('support', power(data.battery.theoretical));
        el('support-metric').hidden = !data.batteryConfigured;
        text('confirmed', 'davon bestätigt: ' + power(data.battery.confirmed));
        text('measured', power(data.measured));
        text('assigned', 'zugewiesen: ' + power(data.assigned));
        text('energy', energy(data.energy));
        text('status', data.status);
        el('errors').hidden = !data.errors || data.errors.length === 0;
        text('errors', (data.errors || []).join('\n'));
        el('overrun').hidden = !(data.overrun > 0);
        text('overrun', 'Zeitweilige Budgetüberschreitung: ' + power(data.overrun));
        Object.values(data.boxes).forEach(function (box) {
            var item = cards.get(box.id) || createCard(box.id);
            item.node.hidden = !box.enabled;
            item.node.dataset.state = box.status;
            var f = item.fields;
            f.name.textContent = box.name;
            f.status.textContent = box.status;
            f.lock.hidden = box.external;
            f.manual.checked = box.manual;
            f.manual.disabled = !box.enabled;
            if (document.activeElement !== f.priority) f.priority.value = box.priority;
            f.measured.textContent = power(box.measured);
            f.command.textContent = (box.unit === 'A' ? ampereFormat : number).format(box.command) + ' ' + box.unit + ' / ' + power(box.power);
            f.energy.textContent = energy(box.energy) + (box.energyMethod === 'berechnet' ? ' (berechnet)' : '');
            f.phases.textContent = box.phases ? String(box.phases) : 'Unbestätigt';
            f.reason.textContent = box.reason;
            f.gap.textContent = box.gap || '';
            f.gap.hidden = !box.gap;
        });
        el('boxes').dataset.count = String(Object.values(data.boxes).filter(function (box) { return box.enabled; }).length);
        el('battery').hidden = !data.batteryConfigured;
        el('battery-enable').checked = !!data.batteryEnabled;
        el('battery-lock').hidden = data.battery.external;
        text('soc', data.battery.soc === null ? 'Keine Messung' : number.format(data.battery.soc) + ' %');
        text('capacity', energy(data.battery.capacity));
        text('reserve-energy', energy(data.battery.energy));
        text('battery-request', power(data.battery.commanded));
        text('battery-power', power(data.battery.measured));
        text('battery-state', data.battery.reason);
        text('updated', 'Aktualisiert: ' + new Date(data.time * 1000).toLocaleTimeString('de-DE'));
        el('simulation').hidden = !data.simulation;
        countdown();
        updateLayout();
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
            item.fields.reason.textContent = reason;
            item.fields.reason.hidden = remaining === 0 && reason === box.status;
        });
    }
    window.handleMessage = function (message) {
        try { render(typeof message === 'string' ? JSON.parse(message) : message); }
        catch (error) { showError(error); }
    };
    el('manager').addEventListener('change', function () { action('ManagerEnabled', this.checked); });
    el('mode').addEventListener('change', function () { action('DistributionMode', Number(this.value)); });
    el('battery-enable').addEventListener('change', function () { action('BatteryEnabled', this.checked); });
    syncTheme();
    if (typeof ResizeObserver === 'function' && document.getElementById('wbm')) {
        new ResizeObserver(updateLayout).observe(document.getElementById('wbm'));
    }
    if (typeof window.addEventListener === 'function') {
        window.addEventListener('resize', function () { updateLayout(); syncTheme(); });
        if (window.matchMedia) {
            var scheme = window.matchMedia('(prefers-color-scheme: dark)');
            if (scheme.addEventListener) scheme.addEventListener('change', syncTheme);
        }
    }
    if (typeof MutationObserver === 'function' && document.documentElement) {
        var observer = new MutationObserver(syncTheme);
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['style', 'class', 'data-theme'] });
        observer.observe(document.body, { attributes: true, attributeFilter: ['style', 'class', 'data-theme'] });
        observer.observe(document.head, { childList: true, subtree: true, characterData: true });
    }
    render(window.WBM_INITIAL);
    action('Refresh', 0);
    setInterval(function () { countdown(); syncTheme(); }, 1000);
}());
