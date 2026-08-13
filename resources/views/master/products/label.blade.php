<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Label {{ $product->name }}</title>
    <style>
        *{box-sizing:border-box}body{font-family:Arial,sans-serif;margin:0;background:#eef1f5;color:#111827}.toolbar{padding:14px;text-align:center}.toolbar button{border:0;border-radius:7px;background:#172039;color:#fff;padding:10px 18px;cursor:pointer}.sheet{width:70mm;min-height:38mm;margin:0 auto;background:#fff;padding:4mm;border:1px solid #ddd;text-align:center}.store{font-size:9pt;font-weight:700}.name{font-size:11pt;font-weight:700;margin:2mm 0 1mm}.category{font-size:8pt;color:#667085}.barcode{width:100%;height:18mm}.price{font-size:13pt;font-weight:800;margin-top:1mm}
        @page{size:70mm 38mm;margin:0}@media print{body{background:#fff}.toolbar{display:none}.sheet{border:0;margin:0;width:70mm;height:38mm;overflow:hidden}}
    </style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Cetak Label</button></div>
<div class="sheet"><div class="store">TOKO GOLDEN</div><div class="name">{{ $product->name }}</div><div class="category">{{ $product->category->name ?? 'Barang' }}</div><svg id="barcode" class="barcode"></svg><div class="price">Rp {{ number_format($product->sell_price_wholesale,0,',','.') }}</div></div>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>JsBarcode('#barcode', @json($product->barcode), {format:'CODE128',width:1.6,height:42,displayValue:true,fontSize:12,margin:2});</script>
</body></html>
