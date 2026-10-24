const fs = require('node:fs/promises');
const path = require('node:path');
const { createRequire } = require('node:module');
const { pathToFileURL } = require('node:url');
const bundled = createRequire('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/package.json');

(async () => {
    const { FileBlob, SpreadsheetFile } = await import(pathToFileURL(bundled.resolve('@oai/artifact-tool')).href);
    const directory = process.argv[2] ? path.resolve(process.argv[2]) : path.resolve(__dirname, '../storage/framework/testing/profit-loss');
    const book = await SpreadsheetFile.importXlsx(await FileBlob.load(path.join(directory, process.argv[3] || 'complete.xlsx')));
    const summary = await book.inspect({ kind: 'sheet', include: 'id,name', maxChars: 1000 });
    console.log(summary.ndjson);
    for (const [sheetName, filename] of [['Laba Rugi', 'workbook-profit-loss.png'], ['Rekonsiliasi Persediaan', 'workbook-reconciliation.png']]) {
        const preview = await book.render({ sheetName, autoCrop: 'all', scale: 1.5, format: 'png' });
        await fs.writeFile(path.join(directory, filename), new Uint8Array(await preview.arrayBuffer()));
    }
    console.log('PASS: both exported worksheets imported and rendered for visual review.');
})().catch(error => { console.error(error); process.exitCode = 1; });
