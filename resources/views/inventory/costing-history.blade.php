@extends('layouts.app')
@section('title', 'Riwayat Biaya Persediaan')
@section('page-title', 'Riwayat Biaya Persediaan')
@section('content')
<div class="inventory-heading mb-3"><h5 class="text-break">{{ $product->name }}</h5></div><x-back-link :fallback="route('inventory.costing.index')" class="mb-3" />
<div class="table-responsive bg-white"><table class="table report-table mb-0"><thead><tr><th>Tanggal</th><th>Jenis</th><th>Referensi</th><th class="text-end">Perubahan Jumlah</th><th class="text-end">Perubahan Nilai (Rp)</th><th class="text-end">Saldo Jumlah</th><th class="text-end">Saldo Nilai (Rp)</th><th class="text-end">Rata-rata (Rp)</th><th>Keterangan</th></tr></thead><tbody>
@foreach($entries as $entry)<tr><td class="text-nowrap">{{ \Carbon\Carbon::parse($entry->event_date)->format('d/m/Y') }}</td><td>{{ ['opening'=>'Verifikasi saldo awal lama','automatic_start'=>'Mulai otomatis dari nol','receipt'=>'Penerimaan','sale'=>'HPP penjualan','stock_loss'=>'Selisih stok berkurang','stock_gain'=>'Selisih stok bertambah'][$entry->kind] ?? $entry->kind }}</td><td class="text-nowrap">@if($entry->reference_url)<a href="{{ $entry->reference_url }}">{{ $entry->reference_label }}</a>@else{{ $entry->reference_label }}@endif</td><td class="text-end">{{ rtrim(rtrim(number_format($entry->quantity_change,4,',','.'), '0'), ',') }}</td><td class="text-end">{{ number_format($entry->value_change,2,',','.') }}</td><td class="text-end">{{ rtrim(rtrim(number_format($entry->quantity_after,4,',','.'), '0'), ',') }}</td><td class="text-end">{{ number_format($entry->value_after,2,',','.') }}</td><td class="text-end">{{ number_format($entry->average_after,2,',','.') }}</td><td class="text-break">{{ $entry->notes }}</td></tr>@endforeach
@if($entries->isEmpty())<tr><td colspan="9" class="text-center text-muted p-4">Belum ada riwayat biaya.</td></tr>@endif
</tbody></table></div><div class="mt-3">{{ $entries->links() }}</div>
@endsection
