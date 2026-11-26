@extends('layouts.app')
@section('title', 'Riwayat Biaya Persediaan')
@section('page-title', 'Riwayat Biaya Persediaan')
@section('content')
<div class="inventory-heading mb-3">
    <h5 class="text-break">{{ $product->name }}</h5>
</div>
<x-back-link :fallback="route('inventory.costing.index')" class="mb-3" />
<form method="GET" action="{{ route('inventory.costing.show', $product) }}" class="app-unified-filter app-filter-bar mb-3">
    <div class="app-filter-field">
        <label for="costHistoryFrom">Dari Tanggal</label>
        <input id="costHistoryFrom" type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm">
    </div>
    <div class="app-filter-field">
        <label for="costHistoryTo">Sampai Tanggal</label>
        <input id="costHistoryTo" type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm">
    </div>
    <div class="app-filter-field">
        <label for="costHistoryKind">Jenis Transaksi</label>
        <select id="costHistoryKind" name="kind" class="form-select form-select-sm">
            <option value="">Semua Jenis</option>
            @foreach($kinds as $kind => $label)
                <option value="{{ $kind }}" @selected(request('kind') === $kind)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="app-filter-actions">
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Terapkan</button>
        <a href="{{ route('inventory.costing.show', $product) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</a>
    </div>
</form>
<div class="table-responsive bg-white">
    <table class="table report-table mb-0">
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Jenis</th>
                <th>Referensi</th>
                <th class="text-end">Perubahan Jumlah</th>
                <th class="text-end">Perubahan Nilai (Rp)</th>
                <th class="text-end">Saldo Jumlah</th>
                <th class="text-end">Saldo Nilai (Rp)</th>
                <th class="text-end">Rata-rata (Rp)</th>
                <th>Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @foreach($entries as $entry)
            <tr>
                <td class="text-nowrap">{{ \Carbon\Carbon::parse($entry->event_date)->format('d/m/Y') }}</td>
                <td>
                    {{ $kinds[$entry->kind] ?? $entry->kind }}
                </td>
                <td class="text-nowrap">
                    @if($entry->reference_url)
                    <a href="{{ $entry->reference_url }}">{{ $entry->reference_label }}</a>
                    @else
                    {{ $entry->reference_label }}
                    @endif
                </td>
                <td class="text-end">
                    {{ rtrim(rtrim(number_format($entry->quantity_change,4,',','.'), '0'), ',') }}
                </td>
                <td class="text-end">{{ number_format($entry->value_change,2,',','.') }}</td>
                <td class="text-end">
                    {{ rtrim(rtrim(number_format($entry->quantity_after,4,',','.'), '0'), ',') }}
                </td>
                <td class="text-end">{{ number_format($entry->value_after,2,',','.') }}</td>
                <td class="text-end">{{ number_format($entry->average_after,2,',','.') }}</td>
                <td class="text-break">{{ $entry->notes }}</td>
            </tr>
            @endforeach
            @if($entries->isEmpty())
            <tr>
                <td colspan="9" class="text-center text-muted p-4">{{ request()->filled('from') || request()->filled('to') || request()->filled('kind') ? 'Tidak ada riwayat biaya yang sesuai filter.' : 'Belum ada riwayat biaya.' }}</td>
            </tr>
            @endif
        </tbody>
    </table>
</div>
<div class="mt-3">{{ $entries->links() }}</div>
@endsection
