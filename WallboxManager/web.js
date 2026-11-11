(function () {
    'use strict';
    if (window.WBM_WEB_DISPOSE) window.WBM_WEB_DISPOSE();
    var context = window.WBM_WEB, latest = window.WBM_WEB_DATA;
    var statusTimer, detailTimer, disposed = false, loadingStatus = false, loadingDetails = false;
    var history = {samples:[], started:null}, invoices = [], invoiceTotal = 0, invoiceMore = false;
    var billingRows = new Map(), invoiceRows = new Map(), lineNodes = new Map();
    var invoiceRequest = 0, invoiceBusy = false;
    var hiddenSeries = new Set(), graphPoints = [], graphNames = [], graphStart = 0, graphEnd = 1, graphSignature = '';
    var selectedPoint = -1, hoverFrame = 0, hoverClient = null, graphBounds = null;
    var colors = ['#00a98b','#4188e8','#c18b00','#a66bdd'];
    var number = new Intl.NumberFormat('de-DE',{maximumFractionDigits:2});
    var fixed = new Intl.NumberFormat('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2});
    var dates = new Map();
    function el(id) { return document.getElementById(id); }
    function put(node,value) { value = String(value == null ? '' : value); if (node.textContent !== value) node.textContent = value; }
    function text(id,value) { put(el(id),value); }
    function hide(node,value) { if (node.hasAttribute('hidden') !== value) node.toggleAttribute('hidden',value); }
    function attr(node,name,value) { if (node.getAttribute(name) !== value) node.setAttribute(name,value); }
    function n(value) { return typeof value === 'number' && Number.isFinite(value) ? value : 0; }
    function kwh(value) { return fixed.format(n(value)) + ' kWh'; }
    function money(value) { return fixed.format(n(value)) + ' €'; }
    function date(value,zone) {
        if (typeof value !== 'number') return '–';
        zone = zone || context.timezone;
        if (!dates.has(zone)) dates.set(zone,new Intl.DateTimeFormat('de-DE',{timeZone:zone,day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}));
        return dates.get(zone).format(new Date(value * 1000));
    }
    function dashboardVisible() { return !el('energy-dashboard').hidden && !document.hidden; }
    function rowCells(body,key,map,count) {
        var row = map.get(key);
        if (row) return row;
        var node = document.createElement('tr'), cells = [];
        for (var i = 0; i < count; i++) { var cell = document.createElement('td'); cells.push(cell); node.append(cell); }
        row = {node:node,cells:cells}; map.set(key,row); body.append(node); return row;
    }
    function renderBilling() {
        if (!latest || !latest.boxes || !dashboardVisible()) return;
        var total = {pv:0,normal:0,unknown:0,cost:0,sessions:0};
        Object.values(latest.boxes).forEach(function (box) {
            var b = box.billing || {}, t = b.totals || {};
            total.pv += n(t.pvEnergy); total.normal += n(t.normalEnergy); total.unknown += n(t.unassignedEnergy);
            total.cost += n(t.totalCost); total.sessions += n(t.sessions);
            var row = rowCells(el('web-billing-rows'),String(box.id),billingRows,8), c = row.cells;
            var known = b.enabled || n(t.pvEnergy) + n(t.normalEnergy) + n(t.unassignedEnergy) > 0;
            put(c[0],box.name + (box.enabled ? '' : ' · deaktiviert'));
            put(c[1],kwh(n(box.energy) + n(box.archivedEnergy)));
            put(c[2],known ? kwh(t.pvEnergy) : '–'); put(c[3],known ? kwh(t.normalEnergy) : '–');
            put(c[4],known ? kwh(t.unassignedEnergy) : '–'); put(c[5],known ? money(t.totalCost) : '–'); put(c[6],known ? String(n(t.sessions)) : '–');
            put(c[7],typeof b.pvPrice === 'number' ? 'PV ' + number.format(b.pvPrice * 100) + ' ct/kWh · Normal ' + number.format(b.normalPrice * 100) + ' ct/kWh' : '–');
            var option = el('invoice-box').querySelector('option[value="' + box.id + '"]'); if (option) put(option,box.name);
        });
        var sum = total.pv + total.normal + total.unknown;
        text('web-billed-energy',kwh(sum)); text('web-pv-energy',kwh(total.pv)); text('web-total-cost',money(total.cost));
        text('web-pv-share',sum > 0 ? number.format(total.pv / sum * 100) + ' % der Abrechnungsenergie' : 'Noch keine Abrechnungsenergie');
        text('web-session-count',String(total.sessions));
    }
    function renderInvoices() {
        invoices.forEach(function (document,index) {
            var row = rowCells(el('web-invoice-rows'),String(document.mediaID),invoiceRows,7), c = row.cells;
            put(c[0],document.invoice && document.invoice.number || document.title || 'Abrechnung'); put(c[1],document.name);
            put(c[2],document.detailed ? date(document.started,document.timezone) + ' – ' + date(document.ended,document.timezone) : 'Älteres PDF · Details im Dokument');
            put(c[3],document.detailed ? kwh(document.pvEnergy) + ' / ' + kwh(document.normalEnergy) : '–');
            put(c[4],document.detailed ? kwh(document.totalEnergy) : '–'); put(c[5],document.detailed ? money(document.totalCost) : '–');
            if (!row.link) { row.link = window.document.createElement('a'); put(row.link,'Herunterladen'); c[6].append(row.link); }
            attr(row.link,'href',document.url); attr(row.link,'download',document.fileName || 'Abrechnung.pdf');
            var current = el('web-invoice-rows').children[index];
            if (current !== row.node) el('web-invoice-rows').insertBefore(row.node,current || null);
        });
        var keys = new Set(invoices.map(function (row) { return String(row.mediaID); }));
        invoiceRows.forEach(function (row,key) { if (!keys.has(key)) { row.node.remove(); invoiceRows.delete(key); } });
        hide(el('web-invoice-empty'),invoiceTotal !== 0); hide(el('invoice-more'),!invoiceMore);
        text('web-archive-status',invoices.length + ' von ' + invoiceTotal + ' Rechnungen');
    }
    function svg(tag,attributes) {
        var node = document.createElementNS('http://www.w3.org/2000/svg',tag);
        Object.keys(attributes).forEach(function (key) { attr(node,key,attributes[key]); }); return node;
    }
    function renderHistory() {
        if (!dashboardVisible() || !latest || !latest.boxes) return;
        var samples = history.samples || [], last = samples[samples.length - 1];
        var names = Object.values(latest.boxes).filter(function (box) { return box.enabled || samples.some(function (point) { return point.boxes[box.id] !== null && point.boxes[box.id] !== undefined; }); });
        var signature = JSON.stringify([samples.length,last && last.time,el('history-hours').value,names.map(function (box) { return [box.id,box.name,box.measured,hiddenSeries.has(box.id)]; }),Math.floor(n(latest.time) / 60)]);
        if (signature === graphSignature) return; graphSignature = signature;
        graphEnd = Math.max(n(latest.time),last ? last.time : 0); graphStart = Math.max(graphEnd - Number(el('history-hours').value) * 3600,history.started || graphEnd - 60);
        if (graphEnd <= graphStart) graphStart = graphEnd - 60;
        graphPoints = samples.filter(function (point) { return point.time >= graphStart && point.time <= graphEnd; });
        if (latest.time && (!graphPoints.length || latest.time > graphPoints[graphPoints.length-1].time)) {
            var live = {time:latest.time,boxes:{}}; names.forEach(function (box) { live.boxes[box.id] = box.measured; }); graphPoints.push(live);
        }
        graphNames = names;
        var max = 1000;
        graphPoints.forEach(function (point) { names.forEach(function (box) { if (!hiddenSeries.has(box.id)) max = Math.max(max,n(point.boxes[box.id])); }); });
        max = Math.ceil(max / 1000) * 1000;
        text('history-max',number.format(max / 1000) + ' kW'); text('history-mid',number.format(max / 2000) + ' kW');
        text('history-start',date(graphStart)); text('history-end',date(graphEnd));
        text('web-history-note',history.started ? 'Aufzeichnung seit ' + date(history.started) + ' · Messpunkte im Minutenabstand · Messlücken bleiben leer.' : 'Der Verlauf beginnt mit der Aktivierung der Webansicht.');
        hide(el('history-empty'),samples.length > 0);
        var keys = new Set(names.map(function (box) { return box.id; }));
        lineNodes.forEach(function (item,key) { if (!keys.has(key)) { item.path.remove(); item.button.remove(); lineNodes.delete(key); } });
        names.forEach(function (box) {
            var item = lineNodes.get(box.id), color = colors[(box.id - 1) % colors.length];
            if (!item) {
                var path = svg('path',{'class':'history-line',stroke:color}); el('history-lines').append(path);
                var button = document.createElement('button'); button.type = 'button'; button.className = 'legend-button history-toggle';
                var dot = document.createElement('span'); dot.className = 'energy-dot'; dot.style.setProperty('--segment-color',color);
                var label = document.createElement('span'); button.append(dot,label); el('history-legend').append(button);
                button.addEventListener('click',function () { if (hiddenSeries.has(box.id)) hiddenSeries.delete(box.id); else hiddenSeries.add(box.id); graphSignature=''; renderHistory(); });
                item = {path:path,button:button,label:label}; lineNodes.set(box.id,item);
            }
            put(item.label,box.name); attr(item.button,'aria-pressed',String(!hiddenSeries.has(box.id))); hide(item.path,hiddenSeries.has(box.id));
            var d = '', previous = null;
            graphPoints.forEach(function (point) {
                var value = point.boxes[box.id];
                if (typeof value !== 'number' || !Number.isFinite(value)) { previous = null; return; }
                var x = (point.time - graphStart) / (graphEnd - graphStart) * 1000, y = 260 - Math.max(0,value) / max * 260;
                d += (previous !== null && point.time - previous <= 150 ? 'L' : 'M') + x.toFixed(2) + ' ' + y.toFixed(2); previous = point.time;
            });
            attr(item.path,'d',d);
        });
        graphBounds = null; selectedPoint = -1; hide(el('history-tooltip'),true); hide(el('history-cursor'),true);
    }
    function selectHistory(index) {
        if (!graphPoints.length) return;
        index = Math.max(0,Math.min(index,graphPoints.length - 1)); selectedPoint = index;
        var point = graphPoints[index], tip = el('history-tooltip');
        put(tip,date(point.time) + '\n' + graphNames.filter(function (box) { return !hiddenSeries.has(box.id); }).map(function (box) {
            var power = point.boxes[box.id]; return box.name + ': ' + (typeof power === 'number' ? number.format(power / 1000) + ' kW' : 'Keine Messung');
        }).join('\n'));
        hide(tip,false); hide(el('history-cursor'),false);
        var x = (point.time - graphStart) / (graphEnd - graphStart) * 1000; attr(el('history-cursor'),'x1',String(x)); attr(el('history-cursor'),'x2',String(x));
        var bounds = graphBounds || (graphBounds = el('history-chart').getBoundingClientRect());
        var left = Math.max(8,Math.min(62 + x / 1000 * bounds.width + 10,62 + bounds.width - tip.offsetWidth - 8));
        tip.style.transform = 'translate(' + Math.round(left) + 'px,16px)';
    }
    function hover() {
        hoverFrame = 0;
        if (!hoverClient || !graphPoints.length) return;
        var bounds = graphBounds || (graphBounds = el('history-chart').getBoundingClientRect());
        var target = graphStart + Math.max(0,Math.min(1,(hoverClient.x - bounds.left) / bounds.width)) * (graphEnd - graphStart);
        var low = 0, high = graphPoints.length - 1;
        while (low < high) { var mid = Math.floor((low + high) / 2); if (graphPoints[mid].time < target) low = mid + 1; else high = mid; }
        if (low > 0 && target - graphPoints[low-1].time < graphPoints[low].time - target) low--;
        selectHistory(low);
    }
    el('history-chart').addEventListener('pointermove',function (event) { hoverClient = {x:event.clientX}; if (!hoverFrame) hoverFrame = requestAnimationFrame(hover); });
    el('history-chart').addEventListener('pointerleave',function () { hoverClient=null; hide(el('history-tooltip'),true); hide(el('history-cursor'),true); });
    el('history-chart').addEventListener('keydown',function (event) { if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') { event.preventDefault(); selectHistory(selectedPoint < 0 ? graphPoints.length - 1 : selectedPoint + (event.key === 'ArrowRight' ? 1 : -1)); } });
    el('history-chart').addEventListener('focus',function () { selectHistory(graphPoints.length - 1); });
    el('history-hours').addEventListener('change',function () { graphSignature=''; renderHistory(); });
    var graphResize = new ResizeObserver(function () { graphBounds=null; }); graphResize.observe(el('history-chart-wrap'));
    el('energy-dashboard').querySelector('.dashboard-scroll').addEventListener('scroll',function () { graphBounds=null; },{passive:true});

    async function loadInvoices(append,force) {
        if (invoiceBusy && !force) return;
        var request = ++invoiceRequest, filter = el('invoice-box').value;
        var offset = append ? invoices.length : 0, wanted = append ? 50 : Math.max(50,invoices.length);
        var previous = append ? invoices.slice() : [], items = [], data;
        invoiceBusy = true; el('invoice-more').disabled = true;
        try {
            do {
                data = await window.WBM_WEB_FETCH('/api/invoices?box=' + filter + '&offset=' + offset + '&limit=' + Math.min(100,wanted - items.length));
                if (disposed || request !== invoiceRequest) return;
                items = items.concat(data.items); offset += data.items.length;
            } while (data.hasMore && data.items.length && items.length < wanted);
            var unique = new Map(); previous.concat(items).forEach(function (row) { unique.set(row.mediaID,row); });
            invoices = Array.from(unique.values()); invoiceTotal = data.total; invoiceMore = data.hasMore;
            renderInvoices();
        } finally {
            if (request === invoiceRequest) { invoiceBusy = false; el('invoice-more').disabled = false; }
        }
    }
    async function details() {
        clearTimeout(detailTimer);
        if (disposed || !dashboardVisible() || loadingDetails) return;
        loadingDetails = true; renderBilling(); renderHistory();
        var results = await Promise.allSettled([window.WBM_WEB_FETCH('/api/history'),loadInvoices(false)]);
        if (!disposed) {
            if (results[0].status === 'fulfilled') { history=results[0].value; renderHistory(); }
            if (results.some(function (result) { return result.status === 'rejected'; })) text('web-archive-status','Detaildaten können gerade nicht aktualisiert werden.');
        }
        loadingDetails = false;
        if (!disposed && dashboardVisible()) detailTimer = setTimeout(details,1000);
    }
    el('invoice-more').addEventListener('click',function () { loadInvoices(true).catch(function (e) { text('web-archive-status',e.message); }); });
    el('invoice-box').addEventListener('change',function () { invoices=[]; loadInvoices(false,true).catch(function (e) { text('web-archive-status',e.message); }); });
    document.querySelectorAll('[data-jump]').forEach(function (button) { button.addEventListener('click',function () { el(button.dataset.jump).scrollIntoView({block:'start',behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth'}); }); });
    var panelObserver = new MutationObserver(function () { if (dashboardVisible()) details(); else clearTimeout(detailTimer); });
    panelObserver.observe(el('energy-dashboard'),{attributes:true,attributeFilter:['hidden']});

    window.WBM_WEB_UPDATE = function (data) { latest=data; renderBilling(); renderHistory(); };
    async function poll() {
        clearTimeout(statusTimer);
        if (disposed || document.hidden || loadingStatus) return;
        loadingStatus = true;
        try {
            var response = await window.WBM_WEB_FETCH('/api/status');
            if (!disposed) {
                window.WBM_WEB_ACCEPT(response.telemetry);
                var stale = Date.now() / 1000 - n(response.telemetry.time) > Math.max(30,context.interval * 3);
                text('web-connection',stale ? 'Keine aktuellen Regeldaten' : 'Verbunden'); el('web-connection').dataset.error=String(stale);
            }
        } catch (e) { if (!disposed) { text('web-connection',e.message); el('web-connection').dataset.error='true'; } }
        loadingStatus = false;
        if (!disposed && !document.hidden) statusTimer = setTimeout(poll,1000);
    }
    function visibility() { clearTimeout(statusTimer); clearTimeout(detailTimer); if (!document.hidden && !disposed) { poll(); if (dashboardVisible()) details(); } }
    document.addEventListener('visibilitychange',visibility);
    var themeKey = 'wbm-web-theme-' + context.instance;
    function applyTheme() {
        var value = el('web-theme').value, root = document.documentElement;
        if (value === 'system') ['--content-color','--card-color','--accent-color'].forEach(function (key) { root.style.removeProperty(key); });
        else {
            root.style.setProperty('--content-color',value === 'dark' ? '#f5f6f8' : '#15191f');
            root.style.setProperty('--card-color',value === 'dark' ? '#32363e' : '#ffffff');
            root.style.setProperty('--accent-color',value === 'dark' ? '#56cdb0' : '#00b79d');
        }
        try { localStorage.setItem(themeKey,value); } catch (e) { /* The page works without storage. */ }
    }
    try { var saved=localStorage.getItem(themeKey); if (['system','light','dark'].indexOf(saved) >= 0) el('web-theme').value=saved; } catch (e) {}
    el('web-theme').addEventListener('change',applyTheme); applyTheme();
    window.WBM_WEB_DISPOSE = function () {
        disposed=true; invoiceRequest++; clearTimeout(statusTimer); clearTimeout(detailTimer); cancelAnimationFrame(hoverFrame);
        panelObserver.disconnect(); graphResize.disconnect(); document.removeEventListener('visibilitychange',visibility);
    };
    window.addEventListener('pagehide',window.WBM_WEB_DISPOSE,{once:true});
    poll();
}());
