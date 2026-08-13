<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><style>
@page{margin:28px}body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#27324a}h1{margin:0;color:#c8960c;font-size:18px}.meta{color:#6b7280;margin:4px 0 16px}table{width:100%;border-collapse:collapse}th{background:#27324a;color:#fff;padding:7px}td{padding:6px;border-bottom:1px solid #ddd}.right{text-align:right}.center{text-align:center}.from{color:#dc3545}.to{color:#198754}
</style></head><body>
<h1>Toko Golden</h1><div class="meta">Laporan Transfer Stok · Periode {{ date('d/m/Y',strtotime($from)) }} s.d. {{ date('d/m/Y',strtotime($to)) }}</div>
<table><thead><tr><th>No. Transfer</th><th>Tanggal</th><th>Barang</th><th>Dari</th><th>Ke</th><th class="right">Jumlah</th><th>Petugas</th></tr></thead><tbody>
@forelse($transfers as $transfer)<tr><td>{{ $transfer->transfer_number }}</td><td>{{ $transfer->transfer_date->format('d/m/Y') }}</td><td>{{ $transfer->product->name }}</td><td class="from">{{ $transfer->fromLocation->name }}</td><td class="to">{{ $transfer->toLocation->name }}</td><td class="right">{{ $transfer->product->displayStock((float)$transfer->quantity) }}</td><td>{{ $transfer->createdBy->name ?? '-' }}</td></tr>@empty<tr><td colspan="7" class="center">Tidak ada data</td></tr>@endforelse
</tbody></table>
</body></html>
