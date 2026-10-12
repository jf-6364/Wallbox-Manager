(function () {
    'use strict';
    var cards = new Map();
    var latest = null;
    var receivedAt = 0;
    var number = new Intl.NumberFormat('de-DE', { maximumFractionDigits: 2 });
    var energyFormat = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
    function el(id) { return document.getElementById(id); }
    function text(id, value) { el(id).textContent = value; }
    function power(value) { return value === null || value === undefined ? 'Keine Messung' : number.format(value / 1000) + ' kW'; }
    function energy(value) { return energyFormat.format(value || 0) + ' kWh'; }
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
        metrics.append(metric('Tatsächlich laden', 'measured'), metric('Sollwert / Anforderung', 'command'), metric('Geladene Energie', 'energy'), metric('Aktive Phasen', 'phases'));
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
        card.append(head, lock, metrics, controls, reason, gap);
        var fields = {};
        card.querySelectorAll('[data-field]').forEach(function (node) { fields[node.dataset.field] = node; });
        cards.set(id, { node: card, fields: fields });
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
            f.command.textContent = number.format(box.command) + ' ' + box.unit + ' / ' + power(box.power);
            f.energy.textContent = energy(box.energy) + (box.energyMethod === 'berechnet' ? ' (berechnet)' : '');
            f.phases.textContent = box.phases ? String(box.phases) : 'Unbestätigt';
            f.reason.textContent = box.reason;
            f.gap.textContent = box.gap || '';
            f.gap.hidden = !box.gap;
        });
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
        });
    }
    window.handleMessage = function (message) {
        try { render(typeof message === 'string' ? JSON.parse(message) : message); }
        catch (error) { showError(error); }
    };
    el('manager').addEventListener('change', function () { action('ManagerEnabled', this.checked); });
    el('mode').addEventListener('change', function () { action('DistributionMode', Number(this.value)); });
    el('battery-enable').addEventListener('change', function () { action('BatteryEnabled', this.checked); });
    render(window.WBM_INITIAL);
    action('Refresh', 0);
    setInterval(countdown, 1000);
}());