<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; font-size: 12px; color: #333; }
    .header { text-align: center; border-bottom: 2px solid #c8960c; padding-bottom: 10px; margin-bottom: 20px; }
    .header h2 { margin: 0; color: #c8960c; }
    .info-table { width: 100%; margin-bottom: 20px; }
    .info-table td { padding: 3px 8px; }
    .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    .items-table th { background: #1a2035; color: white; padding: 8px; text-align: left; }
    .items-table td { padding: 7px 8px; border-bottom: 1px solid #eee; }
    .items-table .text-right { text-align: right; }
    .total-row { font-weight: bold; background: #f8f9fa; }
    .footer { margin-top: 40px; display: flex; justify-content: space-between; }
    .sign-box { text-align: center; width: 200px; }
    .sign-line { border-top: 1px solid #333; margin-top: 60px; }
</style>
</head>
<body>
<div class="header">
    <h2>TOKO GOLDEN</h2>
    <div>Kota Weda, Halmahera Tengah</div>
    <h3 style="margin:10px 0 0">PURCHASE ORDER</h3>
</div>

<table class="info-table">
    <tr>
        <td width="50%">
            <strong>No. PO:</strong> {{ $order->po_number }}<br>
            <strong>Tanggal:</strong> {{ $order->order_date?->translatedFormat('d F Y') ?? '-' }}<br>
            <strong>Status:</strong> {{ ucfirst($order->status) }}
        </td>
        <td width="50%">
            <strong>Supplier:</strong> {{ $order->supplier?->name ?? '-' }}<br>
            <strong>Telepon:</strong> {{ $order->supplier?->phone ?? '-' }}<br>
            <strong>Alamat:</strong> {{ $order->supplier?->address ?? '-' }}
        </td>
    </tr>
</table>

<table class="items-table">
    <thead>
        <tr>
            <th width="5%">#</th>
            <th width="40%">Nama Barang</th>
            <th width="15%">Satuan</th>
            <th width="15%" class="text-right">Jumlah</th>
            <th width="25%" class="text-right">Harga Satuan</th>
            <th width="25%" class="text-right">Subtotal</th>
        </tr>
    </thead>
    <tbody>
        @php $total = 0; @endphp
        @foreach($order->items as $i => $item)
        @php $subtotal = $item->quantity_ordered * $item->buy_price; $total += $subtotal; @endphp
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>{{ $item->product->name }}</td>
            <td>{{ $item->productUnit->unit->name ?? $item->product->buyUnit->name }}</td>
            <td class="text-right">{{ number_format($item->quantity_ordered, 2) }}</td>
            <td class="text-right">Rp {{ number_format($item->buy_price, 0, ',', '.') }}</td>
            <td class="text-right">Rp {{ number_format($subtotal, 0, ',', '.') }}</td>
        </tr>
        @endforeach
        <tr class="total-row">
            <td colspan="5" class="text-right">TOTAL</td>
            <td class="text-right">Rp {{ number_format($total, 0, ',', '.') }}</td>
        </tr>
    </tbody>
</table>

<div class="footer">
    <div class="sign-box">
        <div>Hormat Kami,</div>
        <div class="sign-line">{{ $order->createdBy->name ?? 'Owner' }}</div>
    </div>
    <div class="sign-box">
        <div>Diterima Oleh,</div>
        <div class="sign-line">( ........................ )</div>
    </div>
</div>
</body>
</html>
