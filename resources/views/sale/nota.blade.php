<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<style>
    @page {
        size: 80mm 297mm;
        margin: 0;
    }

    * { margin:0; padding:0; box-sizing:border-box; }
    html,
    body {
        width: 80mm;
        min-height: 0;
        background: #fff;
    }
    body {
        font-family: 'Courier New', monospace;
        font-size: 11px;
        padding: 4mm;
    }
    .center { text-align: center; }
    .right  { text-align: right; }
    .bold   { font-weight: bold; }
    .divider { border-top: 1px dashed #000; margin: 5px 0; }
    .header { margin-bottom: 8px; }
    table { width: 100%; }
    td { vertical-align: top; padding: 1px 2px; }
    .receipt-item {
        margin-bottom: 2mm;
        break-inside: avoid;
        page-break-inside: avoid;
    }
    .item-name {
        max-width: 140px;
        line-height: 1.35;
        overflow-wrap: anywhere;
        word-wrap: break-word;
    }
    .item-subtotal { white-space: nowrap; }
    .preview-toolbar {
        position: sticky;
        top: 0;
        display: flex;
        gap: 6px;
        justify-content: center;
        padding: 8px;
        margin-bottom: 10px;
        background: #f4f6f9;
        border-bottom: 1px solid #d8dde6;
        font-family: Arial, sans-serif;
    }
    .preview-toolbar button {
        border: 1px solid #adb5bd;
        border-radius: 4px;
        background: #fff;
        padding: 6px 10px;
        cursor: pointer;
        font-size: 12px;
    }
    .preview-toolbar .primary {
        background: #198754;
        border-color: #198754;
        color: #fff;
        font-weight: 700;
    }
    @media print {
        html,
        body {
            width: 80mm;
        }

        body {
            padding: 4mm;
        }

        .preview-toolbar { display: none; }
    }
</style>
</head>
<body>
@if($previewMode ?? false)
<div class="preview-toolbar">
    <button type="button" class="primary" onclick="window.print()">Print</button>
    <button type="button" onclick="window.close()">Tutup</button>
</div>
@endif
<div class="header center">
    <div class="bold" style="font-size:14px">TOKO GOLDEN</div>
    <div>Kota Weda, Halmahera Tengah</div>
</div>
<div class="divider"></div>
<table>
    <tr><td>No.</td><td>: {{ $sale->sale_number }}</td></tr>
    <tr><td>Tgl.</td><td>: {{ $sale->sale_date->format('d/m/Y') }}</td></tr>
    <tr><td>Jam</td><td>: {{ $sale->transaction_time_label }}</td></tr>
    <tr><td>Kasir</td><td>: {{ $sale->createdBy->name ?? '-' }}</td></tr>
    @if($sale->customer_name)
    <tr><td>Pelanggan</td><td>: {{ $sale->customer_name }}</td></tr>
    @endif
</table>
<div class="divider"></div>

@foreach($sale->items as $item)
<div class="receipt-item">
    <div class="item-name">{{ $item->product->name }}</div>
    <table>
        <tr>
            <td>{{ \App\Support\DisplayNumber::quantity($item->quantity) }} {{ $item->productUnit->unit->name ?? '' }} x Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
            <td class="right item-subtotal">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
        </tr>
    </table>
</div>
@endforeach

<div class="divider"></div>
<table>
    <tr><td class="bold">TOTAL</td><td class="right bold">Rp {{ number_format($sale->total_amount, 0, ',', '.') }}</td></tr>
    <tr><td>Bayar ({{ ucfirst($sale->payment_method) }})</td><td class="right">Rp {{ number_format($sale->amount_paid, 0, ',', '.') }}</td></tr>
    <tr><td>Kembalian</td><td class="right">Rp {{ number_format($sale->change_amount, 0, ',', '.') }}</td></tr>
</table>
<div class="divider"></div>
<div class="center" style="margin-top:8px">Terima kasih atas kunjungan Anda!</div>
</body>
</html>
