@extends('layouts.app')
@section('title', 'Pencatatan Pajak')
@section('page-title', 'Pencatatan Pajak')
@section('content')
<div class="inventory-heading mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2"><h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Pencatatan Pajak</h5><a class="btn btn-success btn-sm" href="{{ route('taxes.create') }}"><i class="bi bi-plus-circle me-1"></i>Tambah Pajak</a></div>
@include('taxes._period')
<div class="small text-muted mb-3">{{ $periodLabel }} | Beban Pajak Penghasilan</div>
<div class="table-responsive bg-white border"><table class="table report-table mb-0"><thead><tr><th>Periode</th><th>Keterangan</th><th class="text-end">Beban Pajak (Rp)</th><th>Status</th><th>Dikonfirmasi Oleh</th><th class="text-center">Aksi</th></tr></thead><tbody>
@forelse($taxes as $tax)
<tr><td class="text-nowrap">{{ \Carbon\Carbon::createFromFormat('!Y-m',$tax->month)->locale('id')->translatedFormat('F Y') }}</td><td class="text-break">{{ $tax->description }}</td><td class="text-end text-nowrap">{{ number_format($tax->amount,2,',','.') }}</td><td><span class="badge {{ $tax->status === 'confirmed' ? 'bg-success' : 'bg-warning text-dark' }}">{{ $tax->status === 'confirmed' ? 'Dikonfirmasi' : 'Draf' }}</span></td><td>{{ $tax->confirmedBy?->name ?? '-' }}</td><td class="text-center"><a href="{{ route('taxes.show',$tax) }}" class="btn btn-sm btn-outline-success" title="Lihat pajak" aria-label="Lihat pajak"><i class="bi bi-eye"></i></a></td></tr>
@empty<tr><td colspan="6" class="text-center text-muted py-4">Belum ada catatan pajak pada periode ini.</td></tr>@endforelse
</tbody></table></div><div class="mt-3">{{ $taxes->links() }}</div>
@endsection
