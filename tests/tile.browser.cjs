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
        assert.equal(await page.locator('#support-metric').isVisible(), false);
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
        await page.setContent(html());
        assert.equal((await palette(page)).theme, 'dark');
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
        assert.equal(errors.length, 0, errors.join('\n'));
        console.log('OK  Four wallboxes, storage and long error messages');
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
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
