const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

(async () => {
    const source = readFileSync(join(__dirname, '../resources/views/sale/form.blade.php'), 'utf8');
    const positioning = source.slice(source.indexOf('function positionProductResults('), source.indexOf('function addItem('));
    const browser = await chromium.launch({ channel: 'msedge', headless: true });
    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 800 } });
            await page.setContent(`<style>
                *{box-sizing:border-box}body{margin:0}.cart{margin:160px 16px 0;height:600px;overflow:auto}
                .input-group{height:48px;margin-bottom:8px}input{width:100%;height:100%}
                .results{z-index:1055;overflow:auto;background:white;border:1px solid #ccc}
                button{display:block;width:100%;height:50px}
            </style><div class="cart">${Array.from({ length: 14 }, () => '<div class="input-group"><input></div>').join('')}</div><div class="results" hidden>${Array.from({ length: 8 }, (_, i) => `<button>Barang ${i + 1}</button>`).join('')}</div>`);
            await page.addScriptTag({ content: positioning });
            for (const last of [false, true]) {
                const geometry = await page.evaluate(last => {
                    const cart = document.querySelector('.cart');
                    cart.scrollTop = last ? cart.scrollHeight : 0;
                    const inputs = document.querySelectorAll('input');
                    const input = inputs[last ? inputs.length - 1 : 0];
                    const results = document.querySelector('.results');
                    input.focus();
                    results.hidden = false;
                    positionProductResults(input, results);
                    const rect = results.getBoundingClientRect();
                    const anchor = input.getBoundingClientRect();
                    return { top: rect.top, bottom: rect.bottom, left: rect.left, right: rect.right, anchorTop: anchor.top, anchorBottom: anchor.bottom };
                }, last);
                assert(geometry.top >= 12 && geometry.bottom <= 788, JSON.stringify(geometry));
                assert(geometry.left >= 12 && geometry.right <= width - 12);
                assert(last ? geometry.bottom < geometry.anchorTop : geometry.top > geometry.anchorBottom);
                await page.locator('.results button').first().click();
                await page.locator('.results').evaluate(el => { el.scrollTop = el.scrollHeight; });
                await page.locator('.results button').last().click();
            }
            await page.close();
            console.log(`PASS ${width}px: 14 rows, dropdown above/below stays visible and all results reachable`);
        }
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
