@extends('layouts.app')
@section('title', $tax->exists ? 'Ubah Pencatatan Pajak' : 'Tambah Pajak')
@section('page-title', $tax->exists ? 'Ubah Pencatatan Pajak' : 'Tambah Pajak')
@section('content')
<div class="inventory-heading mb-3"><h5 class="mb-0"><i class="bi bi-receipt me-2"></i>{{ $tax->status === 'confirmed' ? 'Koreksi Beban Pajak' : 'Draf Beban Pajak' }}</h5></div>
@if($tax->status === 'confirmed')<div class="alert alert-warning">Koreksi akan mengembalikan catatan menjadi draf. Laba bersih periode terkait belum lengkap sampai pajak dikonfirmasi kembali.</div>@endif
<form method="POST" enctype="multipart/form-data" action="{{ $tax->exists ? route('taxes.update',$tax) : route('taxes.store') }}" class="bg-white border p-3">
@csrf
@if($tax->exists) @method('PUT')<input type="hidden" name="version" value="{{ old('version',$tax->version) }}">@endif
<div class="row g-3">
<div class="col-md-6"><label for="month" class="form-label">Periode Pajak</label>@if($tax->exists)<div class="form-control bg-light">{{ \Carbon\Carbon::createFromFormat('!Y-m',$tax->month)->locale('id')->translatedFormat('F Y') }}</div>@else<input id="month" type="month" name="month" class="form-control" value="{{ old('month',$tax->month) }}" required>@endif</div>
<div class="col-md-6"><label for="amount" class="form-label">Beban Pajak Penghasilan (Rp)</label><input id="amount" name="amount" type="text" inputmode="decimal" min="0" max="999999999999.99" step="0.01" class="form-control js-decimal-currency" value="{{ old('amount',$tax->amount) }}" required></div>
<div class="col-12"><label for="description" class="form-label">Keterangan / Dasar Pencatatan</label><textarea id="description" name="description" class="form-control" rows="3" maxlength="500" required>{{ old('description',$tax->description) }}</textarea></div>
@if($tax->exists)<div class="col-12"><label for="reason" class="form-label">Alasan Koreksi {{ $tax->status === 'confirmed' ? '(wajib)' : '(opsional)' }}</label><textarea id="reason" name="correction_reason" class="form-control" maxlength="500" rows="2" @required($tax->status === 'confirmed')>{{ old('correction_reason') }}</textarea></div>@endif
<div class="col-12"><label for="proof" class="form-label">Dokumen Pendukung (PDF, JPG, PNG; maksimal 5 MB)</label><input id="proof" name="proof" type="file" class="form-control" accept="application/pdf,image/jpeg,image/png">@if($tax->proof_path)<a href="{{ route('taxes.proof',$tax) }}" class="small">Unduh dokumen tersimpan</a>@endif</div>
</div><div class="d-flex flex-wrap gap-2 mt-3"><button class="btn btn-success"><i class="bi bi-save me-1"></i>Simpan Draf</button><x-back-link :fallback="$tax->exists ? route('taxes.show',$tax) : route('taxes.index')" label="Batal" :small="false" /></div>
</form>
@endsection
