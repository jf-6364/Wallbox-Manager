(function () {
    'use strict';
    var cards = new Map();
    var latest = null;
    var receivedAt = 0;
    var number = new Intl.NumberFormat('de-DE', { useGrouping: false, maximumFractionDigits: 2 });
    var energyFormat = new Intl.NumberFormat('de-DE', { useGrouping: false, minimumFractionDigits: 2, maximumFractionDigits: 2 });
    var ampereFormat = new Intl.NumberFormat('de-DE', { useGrouping: false, minimumFractionDigits: 1, maximumFractionDigits: 1 });
    var moneyFormat = new Intl.NumberFormat('de-DE', { useGrouping: false, minimumFractionDigits: 2, maximumFractionDigits: 2 });
    var dashboardOpen = false;
    var energyItems = new Map();
    var selectedEnergy = null;
    var tooltipPoint = {x: .6, y: .4};
    function el(id) { return document.getElementById(id); }
    function text(id, value) { el(id).textContent = value; }
    function power(value) { return value === null || value === undefined ? 'Keine Messung' : ampereFormat.format(value) + ' W'; }
    function energy(value) { return energyFormat.format(value || 0) + ' kWh'; }
    function compactLayout() {
        var root = document.getElementById('wbm');
        var height = root && root.clientHeight ? root.clientHeight : window.innerHeight;
        var width = root && root.clientWidth ? root.clientWidth : window.innerWidth;
        return typeof height === 'number' && (height < 480 || width < 440);
    }
    function updateLayout() {
        if (dashboardOpen) highlightEnergy();
        var root = document.getElementById('wbm');
        if (!root) return;
        var compact = compactLayout();
        if (root.dataset.compact === String(compact)) return;
        root.dataset.compact = String(compact);
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
    function downloadBilling(id) {
        try {
            var box = latest && latest.boxes[id];
            var bill = box && box.billing;
            if (!bill || !bill.pdf) return;
            var bytes = Uint8Array.from(atob(bill.pdf), function (ch) { return ch.charCodeAt(0); });
            var url = URL.createObjectURL(new Blob([bytes], {type: 'application/pdf'}));
            var link = document.createElement('a');
            link.href = url; link.download = (bill.last.fileName || 'Wallbox-' + id + '-Abrechnung.pdf').replace(/[^a-zA-Z0-9._-]/g, '_');
            document.body.append(link); link.click(); link.remove();
            setTimeout(function () { URL.revokeObjectURL(url); }, 10000);
        } catch (error) { showError(error); }
    }
    function billingDetails(id) {
        var details = document.createElement('details'); details.className = 'billing-details'; details.dataset.field = 'billing'; details.hidden = true;
        var summary = document.createElement('summary'); summary.textContent = 'Abrechnung';
        var period = document.createElement('p'); period.dataset.field = 'billingPeriod';
        var values = document.createElement('div'); values.className = 'device-secondary';
        values.append(metric('PV-Überschussladen', 'billingPV'), metric('Normalladen', 'billingNormal'), metric('Gesamtbetrag', 'billingCost'));
        var rates = document.createElement('p'); rates.dataset.field = 'billingRates';
        var unknown = document.createElement('p'); unknown.dataset.field = 'billingUnknown'; unknown.className = 'lock';
        var status = document.createElement('p'); status.dataset.field = 'billingState';
        var button = document.createElement('button'); button.type = 'button'; button.className = 'wb-button';
        button.dataset.field = 'billingPDF'; button.textContent = 'Letzte Abrechnung als PDF'; button.disabled = true;
        button.addEventListener('click', function () { downloadBilling(id); });
        details.append(summary, period, values, rates, unknown, status, button);
        return details;
    }
    function renderBilling(fields, billing) {
        fields.billing.hidden = !billing || !billing.enabled;
        if (!billing) return;
        var session = billing.active || billing.last;
        var money = function (v) { return moneyFormat.format(v || 0) + ' €'; };
        fields.billingPeriod.textContent = session ? (billing.active ? 'Laufender Ladegang seit ' : 'Letzter Ladegang vom ') + new Date(session.started * 1000).toLocaleString('de-DE') : 'Noch kein Ladegang erfasst';
        fields.billingPV.textContent = energy(session && session.pvEnergy) + ' · ' + money(session && session.pvCost);
        fields.billingNormal.textContent = energy(session && session.normalEnergy) + ' · ' + money(session && session.normalCost);
        fields.billingCost.textContent = money(session && session.totalCost);
        var mode = billing.mode === 'pv' ? 'PV-Überschussladen' : (billing.mode === 'unassigned' ? 'Ungeklärt' : 'Normalladen');
        fields.billingRates.textContent = 'Tarif: ' + mode + ' · Aktuelle Preise: PV ' + money(billing.pvPrice) + '/kWh · Normal ' + money(billing.normalPrice) + '/kWh';
        fields.billingUnknown.hidden = !(session && session.unassignedEnergy > 0);
        fields.billingUnknown.textContent = 'Tarif ungeklärt: ' + energy(session && session.unassignedEnergy) + ' – Abrechnung prüfen';
        fields.billingState.textContent = billing.error || (billing.pending ? 'PDF wird erstellt …' : 'Energie aus gemessener Ladeleistung berechnet.');
        if (session && session.warnings && session.warnings.length) fields.billingState.textContent += ' Hinweise im PDF beachten.';
        if (billing.active && billing.idleSince !== null && billing.idleSince !== undefined) {
            fields.billingState.textContent += ' Ladepause: Abschluss nach ' + number.format(billing.idleSeconds) + ' s ohne Ladeleistung.';
        }
        fields.billingPDF.disabled = !billing.pdf;
        fields.billingPDF.textContent = billing.last && billing.last.invoice ? 'Rechnung ' + billing.last.invoice.number + ' als PDF' : 'Letzte Abrechnung als PDF';
    }
    function createCard(id) {
        var card = document.createElement('article');
        card.className = 'box';
        var head = document.createElement('div');
        head.className = 'box-top';
        var heading = document.createElement('div');
        heading.className = 'box-heading';
        var title = document.createElement('h2');
        title.className = 'box-title';
        title.dataset.field = 'name';
        var badge = document.createElement('span');
        badge.className = 'badge';
        badge.dataset.field = 'status';
        heading.append(title, badge);
        var measured = document.createElement('strong');
        measured.className = 'box-measured'; measured.dataset.field = 'measured';
        var lock = document.createElement('p');
        lock.className = 'lock';
        lock.dataset.field = 'lock';
        lock.textContent = 'Extern gesperrt – manuelle Freigabe hebt die Sperre nicht auf';
        var metrics = document.createElement('div');
        metrics.className = 'device-metrics';
        metrics.append(metric('Sollwert', 'command'), metric('Aktive Phasen', 'phases'));
        var details = document.createElement('details');
        details.className = 'device-details';
        details.open = false;
        var summary = document.createElement('summary');
        summary.textContent = 'Details';
        var secondary = document.createElement('div');
        secondary.className = 'device-secondary';
        secondary.append(metric('Geladene Energie', 'energy'));
        var controls = document.createElement('div');
        controls.className = 'device-controls';
        var manualLabel = document.createElement('label');
        manualLabel.className = 'switch';
        var manual = document.createElement('input');
        manual.type = 'checkbox';
        manual.setAttribute('aria-label', 'Wallbox ' + id + ' freigeben');
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
        head.append(heading, measured, manualLabel);
        controls.append(priorityLabel);
        details.append(summary, metrics, secondary, controls);
        var reason = document.createElement('p');
        reason.className = 'reason'; reason.dataset.field = 'reason';
        var gap = document.createElement('p');
        gap.className = 'gap'; gap.dataset.field = 'gap';
        card.append(head, lock, reason, gap, details, billingDetails(id));
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
            f.command.textContent = (box.unit === 'kW' ? number : ampereFormat).format(box.command) + ' ' + box.unit;
            f.manual.setAttribute('aria-label', box.name + ' freigeben');
            f.energy.textContent = energy(box.energy) + (box.energyMethod === 'berechnet' ? ' (berechnet)' : '');
            f.phases.textContent = box.phases ? String(box.phases) : 'Unbestätigt';
            f.reason.textContent = box.reason;
            f.gap.textContent = box.gap || '';
            f.gap.hidden = !box.gap;
            renderBilling(f, box.billing);
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
            var bounds = el('energy-chart-wrap').getBoundingClientRect();
            tooltipPoint = {x: (event.clientX - bounds.left) / bounds.width, y: (event.clientY - bounds.top) / bounds.height};
        } else tooltipPoint = {x: .55, y: .3};
        highlightEnergy();
    }
    function highlightEnergy() {
        energyItems.forEach(function (item, key) {
            item.segment.classList.toggle('is-active', key === selectedEnergy);
            item.segment.classList.toggle('is-muted', selectedEnergy !== null && key !== selectedEnergy);
            item.legend.classList.toggle('is-active', key === selectedEnergy);
            item.row.classList.toggle('is-active', key === selectedEnergy);
        });
        var tooltip = el('energy-tooltip');
        var item = energyItems.get(selectedEnergy);
        tooltip.hidden = !item;
        if (!item) return;
        tooltip.textContent = item.data.name + ': ' + energy(item.data.value) + '\n' + number.format(item.data.percent) + ' %';
        var bounds = el('energy-chart-wrap').getBoundingClientRect();
        var left = Math.min(Math.max(8, tooltipPoint.x * bounds.width + 12), Math.max(8, bounds.width - tooltip.offsetWidth - 8));
        var top = Math.min(Math.max(8, tooltipPoint.y * bounds.height + 12), Math.max(8, bounds.height - tooltip.offsetHeight - 8));
        tooltip.style.left = left + 'px'; tooltip.style.top = top + 'px';
    }
    function makeEnergyItem(row) {
        var segment = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        segment.setAttribute('class', 'energy-segment');
        segment.setAttribute('fill', 'none'); segment.setAttribute('stroke-width', '56');
        segment.setAttribute('tabindex', '0'); segment.setAttribute('role', 'img');
        segment.dataset.energyKey = row.key;
        var legend = document.createElement('button');
        legend.type = 'button'; legend.className = 'legend-button';
        var dot = document.createElement('span'); dot.className = 'energy-dot'; dot.setAttribute('aria-hidden', 'true');
        var name = document.createElement('span'); name.className = 'legend-label';
        legend.append(dot, name);
        var line = document.createElement('button');
        line.type = 'button'; line.className = 'energy-row';
        var rowName = document.createElement('span'); rowName.className = 'energy-row-name';
        var rowDot = document.createElement('span'); rowDot.className = 'energy-dot'; rowDot.setAttribute('aria-hidden', 'true');
        var label = document.createElement('span'); rowName.append(rowDot, label);
        var values = document.createElement('span'); values.className = 'energy-row-values';
        var amount = document.createElement('span'); var detail = document.createElement('small');
        values.append(amount, detail); line.append(rowName, values);
        [segment, legend, line].forEach(function (node) {
            node.addEventListener('pointerenter', function (event) { selectEnergy(row.key, node === segment ? event : null); });
            node.addEventListener('pointermove', function (event) { if (node === segment) selectEnergy(row.key, event); });
            node.addEventListener('pointerleave', function () { if (selectedEnergy === row.key && document.activeElement !== node) selectEnergy(null); });
            node.addEventListener('focus', function () { selectEnergy(row.key); });
            node.addEventListener('blur', function () { if (selectedEnergy === row.key) selectEnergy(null); });
            node.addEventListener('click', function () { selectEnergy(row.key); });
        });
        el('energy-segments').append(segment); el('energy-legend').append(legend); el('energy-rows').append(line);
        var item = {segment: segment, legend: legend, row: line, name: name, label: label, amount: amount, detail: detail, data: row};
        energyItems.set(row.key, item);
        return item;
    }
    function renderEnergyDashboard() {
        if (!latest) return;
        var data = dashboardData();
        text('dashboard-total', energy(data.total)); text('dashboard-summary-total', energy(data.total));
        el('energy-empty').hidden = data.denominator > 0;
        var keys = data.rows.map(function (row) { return row.key; });
        energyItems.forEach(function (item, key) {
            if (keys.indexOf(key) !== -1) return;
            item.segment.remove(); item.legend.remove(); item.row.remove(); energyItems.delete(key);
            if (selectedEnergy === key) selectedEnergy = null;
        });
        var start = 0;
        data.rows.forEach(function (row) {
            var item = energyItems.get(row.key) || makeEnergyItem(row);
            item.data = row;
            var angle = row.percent / 100 * Math.PI * 2;
            item.segment.setAttribute('d', segmentPath(start, start + angle)); start += angle;
            item.segment.style.display = row.value > 0 ? '' : 'none';
            item.segment.setAttribute('stroke', row.color);
            var description = row.name + ': ' + energy(row.value) + ', ' + number.format(row.percent) + ' %';
            item.segment.setAttribute('aria-label', description);
            item.legend.setAttribute('aria-label', description); item.row.setAttribute('aria-label', description);
            item.legend.style.setProperty('--segment-color', row.color); item.row.style.setProperty('--segment-color', row.color);
            item.name.textContent = row.name; item.label.textContent = row.name;
            item.amount.textContent = energy(row.value);
            item.detail.textContent = number.format(row.percent) + ' %' + (row.method === 'berechnet' ? ' · berechnet' : '') + (row.enabled === false ? ' · deaktiviert' : '');
        });
        var note = 'Fortlaufende Zählerstände bis zur letzten Aktualisierung. Ein Energie-Reset beginnt den Zählzeitraum neu.';
        if (data.rows.some(function (row) { return row.archived > 0; })) note += ' Frühere Geräte am selben Wallbox-Platz sind enthalten.';
        if (data.inconsistent) note += ' Die Wallbox-Summe weicht vom Gesamtzähler ab; die Anteile beziehen sich auf die Wallbox-Summe.';
        text('energy-note', note);
        highlightEnergy();
    }
    function openDashboard() {
        dashboardOpen = true;
        el('tile-view').hidden = true; el('energy-dashboard').hidden = false;
        renderEnergyDashboard(); el('energy-close').focus();
    }
    function closeDashboard() {
        dashboardOpen = false; selectEnergy(null);
        el('energy-dashboard').hidden = true; el('tile-view').hidden = false;
        if (document.fullscreenElement === el('wbm') && document.exitFullscreen) document.exitFullscreen().catch(function () {});
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
    el('energy-open').addEventListener('click', openDashboard);
    el('energy-close').addEventListener('click', closeDashboard);
    el('energy-fullscreen').hidden = !(el('wbm').requestFullscreen && document.fullscreenEnabled);
    el('energy-fullscreen').addEventListener('click', function () {
        var pending = document.fullscreenElement === el('wbm') ? document.exitFullscreen() : el('wbm').requestFullscreen();
        // Some Symcon iframe hosts do not grant fullscreen; the dashboard remains usable.
        pending.catch(function () { el('energy-fullscreen').hidden = true; });
    });
    if (typeof document.addEventListener === 'function') {
        document.addEventListener('keydown', function (event) {
            if (!dashboardOpen) return;
            if (event.key === 'Escape') { event.preventDefault(); closeDashboard(); }
            if (event.key === 'Tab') {
                var nodes = Array.from(el('energy-dashboard').querySelectorAll('button, [tabindex="0"]')).filter(function (node) { return node.getClientRects().length > 0; });
                var first = nodes[0], last = nodes[nodes.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        });
        document.addEventListener('fullscreenchange', function () {
            var active = document.fullscreenElement === el('wbm');
            el('energy-fullscreen').setAttribute('aria-label', active ? 'Vollbild beenden' : 'Dashboard im Vollbild anzeigen');
            if (dashboardOpen) highlightEnergy();
        });
    }
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
