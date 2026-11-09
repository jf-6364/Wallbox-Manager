(function () {
    'use strict';
    var context = window.WBM_WEB;
    var latestTime = Number(window.WBM_INITIAL.time || 0);
    window.WBM_WEB_DATA = window.WBM_INITIAL;
    window.WBM_WEB_ACCEPT = function (data) {
        if (!data || Number(data.time || 0) < latestTime) return;
        latestTime = Number(data.time || 0); window.WBM_WEB_DATA = data;
        if (window.handleMessage) window.handleMessage(data);
        if (window.WBM_WEB_UPDATE) window.WBM_WEB_UPDATE(data);
    };
    window.WBM_WEB_FETCH = async function (suffix, options) {
        var response = await fetch(context.base + suffix, Object.assign({credentials:'same-origin', cache:'no-store'}, options || {}));
        if (response.status === 401) throw new Error('Anmeldung abgelaufen. Seite neu laden.');
        var data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Verbindung zur Webansicht fehlgeschlagen');
        return data;
    };
    window.requestAction = function (ident, value) {
        if (ident === 'Refresh') return Promise.resolve();
        return window.WBM_WEB_FETCH('/api/action', {method:'POST', headers:{'Content-Type':'application/json','X-WBM-CSRF':context.csrf}, body:JSON.stringify({ident:ident,value:value})})
            .then(function (response) { window.WBM_WEB_ACCEPT(response.telemetry); });
    };
}());
