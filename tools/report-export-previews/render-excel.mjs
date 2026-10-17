import fs from 'node:fs/promises';
import {SpreadsheetFile} from '@oai/artifact-tool';
for(const name of ['sale','purchase','stock','transfer','gross-profit']) {
    const path=new URL(name+'.xlsx',import.meta.url);
    const bytes=await fs.readFile(path);
    const book=await SpreadsheetFile.importXlsx(bytes.buffer.slice(bytes.byteOffset,bytes.byteOffset+bytes.byteLength));
    const last={sale:'F',purchase:'G',stock:'I',transfer:'I','gross-profit':'J'}[name];
    const sheet=book.worksheets.getItemAt(0);
    const png=await book.render({sheetName:sheet.name,range:`A1:${last}9`,scale:1.25,format:'png'});
    await fs.writeFile(new URL(name+'-excel.png',import.meta.url),new Uint8Array(await png.arrayBuffer()));
    console.log(name+' Excel rendered');
}
