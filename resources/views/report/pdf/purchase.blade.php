<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><style>
@page{margin:28px}body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#27324a}h1{margin:0;color:#c8960c;font-size:18px}.meta{color:#6b7280;margin:4px 0 16px}table{width:100%;border-collapse:collapse}th{background:#27324a;color:#fff;padding:7px}td{padding:6px;border-bottom:1px solid #ddd}.right{text-align:right}.center{text-align:center}tfoot td{font-weight:bold;background:#f3f4f6}.status{text-transform:capitalize}
</style></head><body>
<h1>Toko Golden</h1><div class="meta">Laporan Pembelian · Periode {{ date('d/m/Y',strtotime($from)) }} s.d. {{ date('d/m/Y',strtotime($to)) }}</div>
<table><thead><tr><th>No. PO</th><th>Tanggal</th><th>Supplier</th><th class="center">Jumlah Item</th><th class="center">Status</th><th class="right">Nilai PO</th></tr></thead><tbody>
@php $total=0; @endphp
@forelse($orders as $order)@php $value=$order->totalValue();$total+=$value; @endphp<tr><td>{{ $order->po_number }}</td><td>{{ $order->order_date->format('d/m/Y') }}</td><td>{{ $order->supplier->name }}</td><td class="center">{{ $order->items->count() }}</td><td class="center status">{{ $order->status }}</td><td class="right">Rp {{ number_format($value,0,',','.') }}</td></tr>@empty<tr><td colspan="6" class="center">Tidak ada data</td></tr>@endforelse
</tbody><tfoot><tr><td colspan="5">TOTAL PEMBELIAN</td><td class="right">Rp {{ number_format($total,0,',','.') }}</td></tr></tfoot></table>
</body></html>
