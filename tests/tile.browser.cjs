'use strict';
// Optional browser checks: requires Playwright and an installed Chromium.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const data = {
    manager: true, managerEnabled: true, mode: 0, strict: true,
    base: 12000, measured: 12000, assigned: 12000, energy: 2.41,
    status: 'Automatische Regelung aktiv', errors: [], time: 1791838054,
    overrun: 0, batteryConfigured: false, batteryEnabled: false, simulation: false,
    battery: {theoretical: 0, confirmed: 0, soc: null, capacity: 10, energy: 0, commanded: 0, measured: null, reason: 'Deaktiviert', external: true},
    boxes: Object.fromEntries([1, 2].map(id => [id, {
        id, name: 'Wallbox ' + id, enabled: true, manual: true, external: true,
        priority: id, measured: 6000, power: 6000, command: 6000, unit: 'W',
        energy: id === 1 ? 1.676 : .734, energyMethod: 'berechnet', phases: 3,
        status: 'Laden', reason: 'Laden', remaining: 0, gap: ''
    }]))
};
const template = fs.readFileSync(path.join(root, 'WallboxManager/tile.html'), 'utf8');
const css = fs.readFileSync(path.join(root, 'WallboxManager/tile.css'), 'utf8');
const js = fs.readFileSync(path.join(root, 'WallboxManager/tile.js'), 'utf8');
function html(parameters = '') {
    const bridge = `window.queryParameters = new URLSearchParams(${JSON.stringify(parameters)});
        window.actions = []; window.requestAction = (ident, value) => window.actions.push({ident, value});`;
    return template.replace('/*__CSS__*/', () => css)
        .replace('/*__DATA__*/', () => JSON.stringify(data))
        .replace('/*__JS__*/', () => bridge + '\n' + js);
}
async function layout(page) {
    const result = await page.evaluate(() => {
        const main = document.getElementById('wbm');
        const content = document.querySelector('.wb-content');
        const box = main.getBoundingClientRect();
        return {
            width: innerWidth, height: innerHeight, mainWidth: box.width, mainHeight: box.height,
            documentWidth: document.documentElement.scrollWidth,
            documentHeight: document.documentElement.scrollHeight,
            contentWidth: content.clientWidth, contentScrollWidth: content.scrollWidth,
            font: parseFloat(getComputedStyle(main).fontSize)
        };
    });
    assert.equal(result.mainWidth, result.width);
    assert.equal(result.mainHeight, result.height);
    assert.ok(result.documentWidth <= result.width, JSON.stringify(result));
    assert.ok(result.documentHeight <= result.height, JSON.stringify(result));
    assert.ok(result.contentScrollWidth <= result.contentWidth + 1, JSON.stringify(result));
    assert.ok(result.font >= 12);
}
async function palette(page) {
    return page.evaluate(() => {
        const main = document.getElementById('wbm');
        return {
            theme: document.documentElement.dataset.wbTheme,
            text: getComputedStyle(main).color,
            panel: getComputedStyle(document.querySelector('.box')).backgroundColor,
            background: getComputedStyle(document.body).backgroundColor,
            scheme: getComputedStyle(document.documentElement).colorScheme,
            accent: getComputedStyle(main).getPropertyValue('--wb-accent').trim()
        };
    });
}
(async () => {
    const options = {headless: true};
    const executable = process.env.WBM_CHROMIUM_PATH || (fs.existsSync('/usr/bin/chromium') ? '/usr/bin/chromium' : null);
    if (executable) options.executablePath = executable;
    const browser = await chromium.launch(options);
    try {
        const page = await browser.newPage({viewport: {width: 1280, height: 720}, colorScheme: 'light'});
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setContent(html('textcolor=%23f0f0f3&backgroundColor=%231c1c21&accentColor=%2300bfa5'));
        let colors = await palette(page);
        assert.equal(colors.theme, 'dark');
        assert.equal(colors.text, 'rgb(240, 240, 243)');
        assert.equal(colors.scheme, 'dark');
        assert.equal(colors.background, 'rgb(28, 28, 33)');
        assert.ok((await page.evaluate(() => getComputedStyle(document.body).fontFamily)).startsWith('system-ui, -apple-system, BlinkMacSystemFont'));
        assert.equal(await page.locator('.tile-view').evaluate(node => getComputedStyle(node).borderRadius), '6px');
        assert.equal(await page.locator('#energy-open .wb-icon').getAttribute('aria-hidden'), 'true');
        assert.equal(await page.locator('#support-metric').isVisible(), false);
        assert.equal(await page.locator('#energy').textContent(), '2,41 kWh');
        assert.equal(await page.locator('[data-field="energy"]').first().textContent(), '1,68 kWh (berechnet)');
        for (const [width, height] of [[1280, 720], [1440, 900], [800, 600], [960, 320], [360, 740], [320, 360], [240, 260]]) {
            await page.setViewportSize({width, height});
            await layout(page);
            await page.locator('.box').last().locator('[data-field="manual"]').scrollIntoViewIfNeeded();
            assert.equal(await page.locator('.box').last().locator('[data-field="manual"]').isVisible(), true);
            assert.equal(await page.locator('.box').last().locator('[data-field="manual"]').evaluate(input => {
                const control = input.getBoundingClientRect();
                const scroll = document.querySelector('.wb-content').getBoundingClientRect();
                return control.top >= scroll.top - 1 && control.bottom <= scroll.bottom + 1;
            }), true, `Control clipped at ${width}x${height}`);
            console.log(`OK  Tile bounds, readable text and reachable controls: ${width}x${height}`);
        }
        await page.setViewportSize({width: 960, height: 320});
        assert.equal(await page.locator('.device-details').first().getAttribute('open'), null);
        await page.locator('.device-details summary').first().click();
        assert.equal(await page.locator('[data-field="energy"]').first().isVisible(), true);
        await page.setViewportSize({width: 1280, height: 720});
        // Resizing preserves the user's detail selection; other wallboxes stay concise.
        assert.equal(await page.locator('.device-details').last().getAttribute('open'), null);
        assert.equal(await page.locator('[data-field="energy"]').first().isVisible(), true);
        await page.locator('#manager').uncheck();
        await page.locator('#mode').selectOption('1');
        assert.deepEqual(await page.evaluate(() => actions.slice(-2)), [
            {ident: 'ManagerEnabled', value: false}, {ident: 'DistributionMode', value: 1}
        ]);
        console.log('OK  Compact details, resize and device actions');
        await page.evaluate(() => {
            document.documentElement.style.setProperty('--content-color', '#24262b');
            document.documentElement.style.setProperty('--card-color', '#ffffff');
            document.documentElement.style.setProperty('--accent-color', '#00796b');
        });
        await page.waitForFunction(() => document.documentElement.dataset.wbTheme === 'light');
        colors = await palette(page);
        assert.equal(colors.text, 'rgb(36, 38, 43)');
        assert.equal(colors.accent, '#00796b');
        await page.evaluate(() => {
            document.documentElement.style.setProperty('--content-color', '#f0f0f3');
            document.documentElement.style.setProperty('--card-color', '#1c1c21');
        });
        await page.waitForFunction(() => document.documentElement.dataset.wbTheme === 'dark');
        assert.equal((await palette(page)).text, 'rgb(240, 240, 243)');
        console.log('OK  Live Symcon color changes override the browser theme');
        if (process.env.WBM_SCREENSHOT_DIR) {
            fs.mkdirSync(process.env.WBM_SCREENSHOT_DIR, {recursive: true});
            await page.evaluate(value => window.handleMessage(JSON.stringify(value)), data);
            await page.screenshot({path: path.join(process.env.WBM_SCREENSHOT_DIR, 'wallbox-dark.png')});
        }
        await page.setContent(html('textcolor=%2324262b&backgroundColor=%23ffffff'));
        assert.equal((await palette(page)).theme, 'light');
        assert.equal((await palette(page)).background, 'rgb(255, 255, 255)');
        await page.emulateMedia({colorScheme: 'dark'});
        assert.equal((await palette(page)).theme, 'light');
        console.log('OK  Symcon light mode overrides a dark browser preference');
        if (process.env.WBM_SCREENSHOT_DIR) await page.screenshot({path: path.join(process.env.WBM_SCREENSHOT_DIR, 'wallbox-light.png')});
        const actionCount = await page.evaluate(() => actions.length);
        await page.locator('#energy-open').click();
        assert.equal(await page.locator('#energy-dashboard').isVisible(), true);
        assert.equal(await page.locator('#tile-view').isVisible(), false);
        assert.equal(await page.locator('#dashboard-total').textContent(), '2,41 kWh');
        assert.equal(await page.locator('.energy-segment:visible').count(), 2);
        assert.equal(await page.locator('.energy-row').first().innerText(), 'Wallbox 1\n1,68 kWh\n69,54 % · berechnet');
        await page.evaluate(() => window.firstEnergySegment = document.querySelector('.energy-segment'));
        const chart = await page.locator('#energy-chart').boundingBox();
        const angle = data.boxes[1].energy / data.energy * Math.PI;
        await page.mouse.move(chart.x + (200 + 145 * Math.sin(angle)) / 400 * chart.width,
            chart.y + (200 - 145 * Math.cos(angle)) / 400 * chart.height);
        assert.equal(await page.locator('#energy-tooltip').isVisible(), true);
        assert.equal(await page.locator('#energy-tooltip').innerText(), 'Wallbox 1: 1,68 kWh\n69,54 %');
        assert.equal(await page.locator('.energy-segment.is-active').getAttribute('data-energy-key'), '1');
        if (process.env.WBM_SCREENSHOT_DIR) await page.screenshot({path: path.join(process.env.WBM_SCREENSHOT_DIR, 'energy-dashboard-light.png')});
        await page.locator('.legend-button').last().hover();
        assert.equal(await page.locator('#energy-tooltip').innerText(), 'Wallbox 2: 0,73 kWh\n30,46 %');
        await page.locator('.energy-row').first().focus();
        assert.equal(await page.locator('.energy-segment.is-active').getAttribute('data-energy-key'), '1');
        const live = structuredClone(data);
        live.energy = 2.6; live.boxes[1].energy = 1.8; live.boxes[2].energy = .8;
        await page.evaluate(value => window.handleMessage(value), live);
        assert.equal(await page.locator('#dashboard-total').textContent(), '2,60 kWh');
        assert.equal(await page.locator('#energy-tooltip').innerText(), 'Wallbox 1: 1,80 kWh\n69,23 %');
        assert.equal(await page.evaluate(() => firstEnergySegment === document.querySelector('.energy-segment')), true);
        assert.equal(await page.locator('.energy-row').first().evaluate(node => document.activeElement === node), true);
        console.log('OK  Dashboard mouse hover, legend, keyboard focus and live energy updates');
        for (const [width, height] of [[1280, 720], [800, 600], [960, 320], [360, 740], [240, 260]]) {
            await page.setViewportSize({width, height});
            // ResizeObserver repositions an already visible tooltip before the next paint.
            await page.waitForFunction(() => {
                const scroller = document.querySelector('.dashboard-scroll');
                return scroller.scrollWidth <= scroller.clientWidth + 1;
            });
            const bounds = await page.locator('#energy-dashboard').evaluate(node => {
                const scroller = node.querySelector('.dashboard-scroll');
                const frame = node.getBoundingClientRect();
                return {width: frame.width, height: frame.height, scrollWidth: scroller.scrollWidth, contentWidth: scroller.clientWidth,
                    documentWidth: document.documentElement.scrollWidth, documentHeight: document.documentElement.scrollHeight};
            });
            assert.ok(bounds.scrollWidth <= bounds.contentWidth + 1, JSON.stringify(bounds));
            assert.equal(bounds.width, width); assert.equal(bounds.height, height);
            assert.equal(bounds.documentWidth, width); assert.equal(bounds.documentHeight, height);
            await page.locator('.energy-row').last().scrollIntoViewIfNeeded();
            assert.equal(await page.locator('.energy-row').last().isVisible(), true);
            await page.locator('.energy-row').last().click();
            assert.equal(await page.locator('#energy-tooltip').isVisible(), true);
        }
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('#energy-dashboard').isVisible(), false);
        assert.equal(await page.locator('#energy-open').evaluate(node => document.activeElement === node), true);
        assert.equal(await page.evaluate(() => actions.length), actionCount);
        console.log('OK  Responsive dashboard, touch-style selection, Escape and no device actions');
        await page.setViewportSize({width: 1280, height: 720});
        const historic = structuredClone(data);
        historic.boxes[1].archivedEnergy = 2; historic.boxes[2].enabled = false; historic.energy = 4.41;
        await page.evaluate(value => window.handleMessage(value), historic);
        await page.locator('#energy-open').click();
        assert.ok((await page.locator('.energy-row').first().innerText()).includes('3,68 kWh'));
        assert.ok((await page.locator('.energy-row').last().innerText()).includes('deaktiviert'));
        assert.ok((await page.locator('#energy-note').innerText()).includes('Frühere Geräte'));
        const old = structuredClone(data); old.energy = 3.41;
        await page.evaluate(value => window.handleMessage(value), old);
        assert.equal(await page.locator('.energy-row').count(), 3);
        assert.ok((await page.locator('.energy-row').last().innerText()).includes('Weitere / archivierte Energie'));
        const zero = structuredClone(data);
        zero.energy = 0; zero.boxes[1].energy = 0; zero.boxes[2].energy = 0;
        await page.evaluate(value => window.handleMessage(value), zero);
        assert.equal(await page.locator('#dashboard-total').textContent(), '0,00 kWh');
        assert.equal(await page.locator('.energy-segment:visible').count(), 0);
        assert.equal(await page.locator('#energy-empty').isVisible(), true);
        await page.locator('.energy-row').first().click();
        assert.ok((await page.locator('#energy-tooltip').innerText()).includes('0 %'));
        const single = structuredClone(data);
        single.boxes[2].enabled = false; single.boxes[2].energy = 0; single.energy = single.boxes[1].energy;
        await page.evaluate(value => window.handleMessage(value), single);
        assert.equal(await page.locator('.energy-segment:visible').count(), 1);
        assert.ok((await page.locator('.energy-row').innerText()).includes('100 %'));
        console.log('OK  Archived and disabled devices, older telemetry, zero energy and a full ring');
        const unsafe = structuredClone(data);
        unsafe.boxes[1].name = '<img src=x onerror="throw new Error(\'Unsafe name\')">';
        await page.evaluate(value => window.handleMessage(value), unsafe);
        assert.equal(await page.locator('.energy-row img').count(), 0);
        assert.ok((await page.locator('.energy-row').first().innerText()).includes('<img src=x'));
        await page.evaluate(value => window.handleMessage(value), data);
        await page.evaluate(() => {
            document.documentElement.style.setProperty('--content-color', '#f0f0f3');
            document.documentElement.style.setProperty('--card-color', '#1c1c21');
        });
        await page.waitForFunction(() => document.documentElement.dataset.wbTheme === 'dark');
        assert.equal(await page.locator('#energy-dashboard').evaluate(node => getComputedStyle(node).backgroundColor), 'rgb(28, 28, 33)');
        if (process.env.WBM_SCREENSHOT_DIR) await page.screenshot({path: path.join(process.env.WBM_SCREENSHOT_DIR, 'energy-dashboard-dark.png')});
        // Trap keyboard focus within the open dashboard.
        await page.locator('#energy-close').focus();
        if (await page.locator('#energy-fullscreen').isVisible()) await page.locator('#energy-fullscreen').focus();
        await page.keyboard.press('Shift+Tab');
        assert.equal(await page.locator('.energy-row').last().evaluate(node => document.activeElement === node), true);
        await page.keyboard.press('Tab');
        assert.equal(await page.evaluate(() => document.activeElement.id), await page.locator('#energy-fullscreen').isVisible() ? 'energy-fullscreen' : 'energy-close');
        if (await page.locator('#energy-fullscreen').isVisible()) {
            await page.locator('#energy-fullscreen').click();
            await page.waitForFunction(() => document.fullscreenElement === document.getElementById('wbm'));
        }
        await page.locator('#energy-close').click();
        await page.waitForFunction(() => !document.fullscreenElement);
        console.log('OK  Dashboard dark mode, safe names, focus trap and fullscreen');
        await page.setContent(html());
        assert.equal((await palette(page)).theme, 'dark');
        assert.equal((await palette(page)).accent, '#00b5ad');
        await page.emulateMedia({colorScheme: 'light'});
        await page.waitForFunction(() => document.documentElement.dataset.wbTheme === 'light');
        console.log('OK  Standalone browser theme fallback');
        const extended = structuredClone(data);
        extended.batteryConfigured = true;
        extended.boxes[3] = {...extended.boxes[1], id: 3, name: 'Sehr langer Wallboxname im Außenbereich / Stellplatz 3'};
        extended.boxes[4] = {...extended.boxes[2], id: 4, name: 'Wallbox 4'};
        extended.errors = ['Geräteverbindung unterbrochen: ' + 'x'.repeat(180)];
        await page.evaluate(value => window.handleMessage(JSON.stringify(value)), extended);
        for (const [width, height] of [[1280, 720], [320, 360]]) {
            await page.setViewportSize({width, height});
            await layout(page);
        }
        assert.equal(await page.locator('#battery').isVisible(), true);
        assert.equal(await page.locator('#errors').isVisible(), true);
        extended.energy = Object.values(extended.boxes).reduce((sum, box) => sum + box.energy, 0);
        await page.evaluate(value => window.handleMessage(value), extended);
        await page.locator('#energy-open').click();
        assert.equal(await page.locator('.energy-segment:visible').count(), 4);
        assert.equal(await page.locator('.energy-row').count(), 4);
        assert.equal(await page.locator('#dashboard-total').textContent(), '4,82 kWh');
        assert.equal(await page.locator('.energy-row').nth(2).locator('.energy-row-name').innerText(), extended.boxes[3].name);
        assert.equal(await page.locator('.dashboard-scroll').evaluate(node => node.scrollWidth <= node.clientWidth + 1), true);
        await page.locator('#energy-close').click();
        assert.equal(errors.length, 0, errors.join('\n'));
        console.log('OK  Four wallboxes, storage and long error messages');
        const touchContext = await browser.newContext({hasTouch: true, viewport: {width: 390, height: 844}});
        const touchPage = await touchContext.newPage();
        await touchPage.setContent(html());
        await touchPage.locator('#energy-open').tap();
        const touchChart = await touchPage.locator('#energy-chart').boundingBox();
        await touchPage.touchscreen.tap(touchChart.x + (200 + 145 * Math.sin(angle)) / 400 * touchChart.width,
            touchChart.y + (200 - 145 * Math.cos(angle)) / 400 * touchChart.height);
        assert.equal(await touchPage.locator('#energy-tooltip').innerText(), 'Wallbox 1: 1,68 kWh\n69,54 %');
        assert.equal(await touchPage.locator('#energy-tooltip').isVisible(), true);
        await touchPage.locator('.energy-row').last().tap();
        assert.ok((await touchPage.locator('#energy-tooltip').innerText()).startsWith('Wallbox 2:'));
        await touchPage.locator('#energy-close').tap();
        assert.equal(await touchPage.locator('#tile-view').isVisible(), true);
        assert.equal(await touchPage.evaluate(() => actions.length), 1); // Initial Refresh only.
        await touchContext.close();
        console.log('OK  Touch events select ring segments and energy rows without device actions');
        await page.setViewportSize({width: 800, height: 600});
        await page.setContent(html());
        await page.evaluate(() => {
            const heading = document.createElement('header');
            heading.textContent = 'Wallbox Manager';
            heading.style.cssText = 'height:48px;box-sizing:border-box;flex-shrink:0;padding:12px;';
            document.body.prepend(heading);
        });
        const embedded = await page.locator('#wbm').boundingBox();
        assert.equal(embedded.y, 48);
        assert.equal(embedded.height, 552);
        assert.equal(await page.evaluate(() => document.documentElement.scrollHeight), 600);
        await page.setViewportSize({width: 800, height: 500});
        await page.waitForFunction(() => document.getElementById('wbm').dataset.compact === 'true');
        await page.setViewportSize({width: 800, height: 600});
        await page.waitForFunction(() => document.getElementById('wbm').dataset.compact === 'false');
        await page.evaluate(() => {
            document.body.firstElementChild.remove();
            document.body.style.paddingTop = '48px';
        });
        const padded = await page.locator('#wbm').boundingBox();
        assert.equal(padded.y, 48);
        assert.equal(padded.height, 552);
        assert.equal(errors.length, 0, errors.join('\n'));
        console.log('OK  Host title and reserved SDK header space');
        await page.setViewportSize({width: 800, height: 600});
        await page.setContent(html('textcolor=%2324262b&backgroundColor=%23ffffff'));
        const billed = structuredClone(data);
        // Browser checks exercise transport/download; actual PDF bytes are validated in PHP and a PDF reader.
        const documentBytes = Buffer.from('%PDF-1.4\n% Browser download transport fixture\n%%EOF\n');
        const receipt = {id: 'abc123', started: 1791838054, ended: 1791840054,
            pvEnergy: 1.25, normalEnergy: 2.5, totalEnergy: 3.75, pvCost: .13, normalCost: .8, totalCost: .93,
            unassignedEnergy: 0, warnings: [], fileName: 'RE-2026-000123.pdf', mediaID: 12345, invoice: {number: 'RE-2026-000123'}};
        billed.boxes[1].billing = {enabled: true, active: receipt, last: receipt,
            pvPrice: .1, normalPrice: .32, mode: 'pv', idleSince: null, idleSeconds: 600,
            pending: 0, error: '', pdf: documentBytes.toString('base64')};
        billed.boxes[2].billing = {enabled: false};
        await page.evaluate(value => window.handleMessage(value), billed);
        assert.equal(await page.locator('.billing-details').last().isVisible(), false);
        await page.locator('.billing-details summary').first().click();
        assert.equal(await page.locator('[data-field="billingPV"]').first().innerText(), '1,25 kWh · 0,13 €');
        assert.equal(await page.locator('[data-field="billingNormal"]').first().innerText(), '2,50 kWh · 0,80 €');
        assert.equal(await page.locator('[data-field="billingCost"]').first().innerText(), '0,93 €');
        assert.ok((await page.locator('[data-field="billingRates"]').first().innerText()).includes('PV-Überschussladen'));
        assert.equal(await page.locator('[data-field="billingPDF"]').first().isEnabled(), true);
        assert.equal(await page.locator('[data-field="billingPDF"]').first().innerText(), 'Rechnung RE-2026-000123 als PDF');
        const actionsBeforePDF = await page.evaluate(() => actions.length);
        const downloadEvent = page.waitForEvent('download');
        await page.locator('[data-field="billingPDF"]').first().click();
        const download = await downloadEvent;
        assert.equal(download.suggestedFilename(), 'RE-2026-000123.pdf');
        const stream = await download.createReadStream(); const chunks = [];
        for await (const chunk of stream) chunks.push(chunk);
        assert.deepEqual(Buffer.concat(chunks), documentBytes);
        assert.equal(await page.evaluate(() => actions.length), actionsBeforePDF);
        billed.boxes[1].billing.active = null;
        billed.boxes[1].billing.last.unassignedEnergy = .25;
        billed.boxes[1].billing.last.warnings = ['Messlücke'];
        billed.boxes[1].billing.error = 'PDF-Erstellung fehlgeschlagen; erneuter Versuch folgt';
        billed.boxes[1].billing.pdf = '';
        await page.evaluate(value => window.handleMessage(value), billed);
        assert.ok((await page.locator('[data-field="billingPeriod"]').first().innerText()).startsWith('Letzter Ladegang'));
        assert.equal(await page.locator('[data-field="billingUnknown"]').first().isVisible(), true);
        assert.ok((await page.locator('[data-field="billingState"]').first().innerText()).includes('PDF-Erstellung fehlgeschlagen'));
        assert.equal(await page.locator('[data-field="billingPDF"]').first().isEnabled(), false);
        for (const [width, height] of [[800, 600], [320, 360], [240, 260]]) {
            await page.setViewportSize({width, height});
            await layout(page);
            await page.locator('[data-field="billingPDF"]').first().scrollIntoViewIfNeeded();
            assert.equal(await page.locator('[data-field="billingPDF"]').first().isVisible(), true);
        }
        await page.emulateMedia({colorScheme: 'dark'});
        await page.evaluate(() => {
            document.documentElement.style.setProperty('--content-color', '#f0f0f3');
            document.documentElement.style.setProperty('--card-color', '#1c1c21');
        });
        await page.waitForFunction(() => document.documentElement.dataset.wbTheme === 'dark');
        assert.equal(await page.locator('.billing-details').first().evaluate(node => getComputedStyle(node).color), 'rgb(240, 240, 243)');
        if (process.env.WBM_SCREENSHOT_DIR) {
            await page.setViewportSize({width: 800, height: 600});
            billed.boxes[1].billing.error = ''; billed.boxes[1].billing.last.unassignedEnergy = 0;
            billed.boxes[1].billing.last.warnings = []; billed.boxes[1].billing.pdf = documentBytes.toString('base64');
            await page.evaluate(value => window.handleMessage(value), billed);
            await page.screenshot({path: path.join(process.env.WBM_SCREENSHOT_DIR, 'wallbox-billing-dark.png')});
        }
        assert.equal(errors.length, 0, errors.join('\n'));
        console.log('OK  Billing tariffs, session/cost details, PDF download, pending errors and responsive dark theme');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
