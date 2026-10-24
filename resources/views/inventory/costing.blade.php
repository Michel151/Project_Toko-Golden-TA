@extends('layouts.app')
@section('title', 'Penilaian Persediaan')
@section('page-title', 'Penilaian Persediaan')
@section('content')
<div class="inventory-heading d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h5><i class="bi bi-calculator me-2"></i>HPP Moving Average</h5>
    <span class="badge bg-success">Otomatis dari Stok Nol</span>
</div>
<div class="alert alert-light border">Nilai persediaan dihitung dari penerimaan barang. Pengeluaran fisik tetap FEFO. Tidak diperlukan input saldo awal manual.</div>
<div class="alert alert-warning">Barang yang masih memiliki stok lama tetap menggunakan metode sebelumnya sampai seluruh stok milik toko habis, termasuk reservasi penjualan. Data dan HPP transaksi lama tidak dihitung ulang.</div>
<form class="d-flex flex-wrap gap-2 mb-3" method="GET">
    <label for="costSearch" class="visually-hidden">Cari barang</label>
    <input id="costSearch" class="form-control form-control-sm" style="max-width:320px" name="q" value="{{ request('q') }}" placeholder="Cari barang">
    <button class="btn btn-sm btn-success"><i class="bi bi-search me-1"></i>Cari</button>
</form>
<div class="table-responsive bg-white">
<table class="table report-table mb-0" style="min-width:1000px">
    <thead><tr><th style="min-width:180px">Barang</th><th class="text-end">Stok Milik Toko</th><th class="text-end">Nilai Moving Average (Rp)</th><th class="text-end">Rata-rata / Satuan Dasar (Rp)</th><th>Status HPP</th><th class="text-center">Riwayat</th></tr></thead>
    <tbody>
    @forelse($products as $product)
        @php
            $balance = $product->costBalance;
            $tracked = (bool) $balance?->activated_at;
            $ready = isset($owned[$product->id]) && (float) $owned[$product->id] === 0.0;
            $baseUnit = $product->baseProductUnit?->unit?->name ?? $product->buyUnit?->name;
        @endphp
        <tr>
            <td class="text-break fw-semibold">{{ $product->name }}</td>
            <td class="text-end text-nowrap">{{ isset($owned[$product->id]) ? rtrim(rtrim(number_format($owned[$product->id],4,',','.'), '0'), ',').' '.$baseUnit : '-' }}</td>
            <td class="text-end text-nowrap">{{ $tracked ? number_format($balance->inventory_value,2,',','.') : ($ready ? '0,00' : '-') }}</td>
            <td class="text-end text-nowrap">{{ $tracked ? number_format($balance->average_cost,2,',','.') : '-' }}</td>
            <td>
                @if(isset($issues[$product->id]))
                    <span class="text-danger small">{{ $issues[$product->id] }}</span>
                @elseif($tracked)
                    <span class="badge bg-success">Moving Average</span>
                @elseif($ready)
                    <span class="badge bg-light text-dark border">Menunggu Penerimaan</span>
                @else
                    <span class="badge bg-warning text-dark">Stok Lama / Metode Sebelumnya</span>
                @endif
            </td>
            <td class="text-center"><a href="{{ route('inventory.costing.show',$product) }}" class="btn btn-sm btn-outline-success" title="Riwayat biaya" aria-label="Riwayat biaya"><i class="bi bi-clock-history"></i></a></td>
        </tr>
    @empty
        <tr><td colspan="6" class="text-center text-muted p-4">Barang tidak ditemukan.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-3">{{ $products->links() }}</div>
@endsection
