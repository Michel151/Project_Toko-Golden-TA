@extends('layouts.app')
@section('title', $expense->exists ? 'Ubah Draf Beban' : 'Tambah Beban')
@section('page-title', $expense->exists ? 'Ubah Draf Beban' : 'Tambah Beban')
@section('content')
<div class="inventory-heading mb-3"><h5><i class="bi bi-journal-minus me-2"></i>{{ $expense->exists ? 'Ubah Draf Beban' : 'Tambah Beban' }}</h5></div>
<form method="POST" enctype="multipart/form-data" action="{{ $expense->exists ? route('expenses.update',$expense) : route('expenses.store') }}" class="bg-white border p-3">
@csrf
@if($expense->exists) @method('PUT') <input type="hidden" name="version" value="{{ old('version',$expense->version) }}"> @else <input type="hidden" name="submission_token" value="{{ old('submission_token',$token) }}"> @endif
<div class="row g-3">
    <div class="col-md-6"><label for="recognizedDate" class="form-label">Tanggal Pengakuan Beban <span class="text-danger">*</span></label><input type="date" id="recognizedDate" name="recognized_date" class="form-control" value="{{ old('recognized_date',$expense->recognized_date?->format('Y-m-d') ?? now('Asia/Jakarta')->toDateString()) }}" required></div>
    <div class="col-md-6"><label for="category" class="form-label">Kategori <span class="text-danger">*</span></label><select id="category" name="category" class="form-select" required><option value="">Pilih kategori</option>@foreach(\App\Models\Expense::CATEGORIES as $key => $label)<option value="{{ $key }}" @selected(old('category',$expense->category) === $key)>{{ $label }}</option>@endforeach</select></div>
    <div class="col-md-6"><label for="amount" class="form-label">Nominal (Rp) <span class="text-danger">*</span></label><input id="amount" name="amount" type="text" inputmode="decimal" step="0.01" min="0.01" max="999999999999.99" class="form-control js-decimal-currency text-start" data-currency-align="left" value="{{ old('amount',$expense->amount) }}" required></div>
    <div class="col-md-6"><label for="paymentStatus" class="form-label">Status Pembayaran <span class="text-danger">*</span></label><select id="paymentStatus" name="payment_status" class="form-select" required><option value="unpaid" @selected(old('payment_status',$expense->paid_date ? 'paid' : 'unpaid') === 'unpaid')>Belum dibayar</option><option value="paid" @selected(old('payment_status',$expense->paid_date ? 'paid' : 'unpaid') === 'paid')>Sudah dibayar</option></select></div>
    <div class="col-md-6"><label for="paidDate" class="form-label">Tanggal Pembayaran</label><input type="date" id="paidDate" name="paid_date" max="{{ now('Asia/Jakarta')->toDateString() }}" class="form-control" value="{{ old('paid_date',$expense->paid_date?->format('Y-m-d')) }}"></div>
    <div class="col-md-6"><label for="paymentMethod" class="form-label">Metode Pembayaran</label><select id="paymentMethod" name="payment_method" class="form-select"><option value="">Pilih metode</option><option value="cash" @selected(old('payment_method',$expense->payment_method) === 'cash')>Tunai</option><option value="transfer" @selected(old('payment_method',$expense->payment_method) === 'transfer')>Transfer</option></select></div>
    <div class="col-12"><label for="description" class="form-label">Keterangan <span class="text-danger">*</span></label><textarea id="description" name="description" maxlength="500" rows="2" class="form-control" required>{{ old('description',$expense->description) }}</textarea></div>
    <div class="col-12"><label for="proof" class="form-label">Bukti Transaksi (PDF, JPG, PNG; maksimal 5 MB)</label><input id="proof" name="proof" type="file" accept="application/pdf,image/jpeg,image/png" class="form-control">@if($expense->proof_path)<a class="small" href="{{ route('expenses.proof',$expense) }}">Unduh bukti tersimpan</a>@endif</div>
</div>
<div class="d-flex flex-wrap gap-2 mt-3"><button class="btn btn-success"><i class="bi bi-save me-1"></i>Simpan Draf</button><x-back-link :fallback="$expense->exists ? route('expenses.show',$expense) : route('expenses.index')" label="Batal" :small="false" /></div>
</form> 
@endsection
@push('scripts')
<script>
(() => {
    const status = document.getElementById('paymentStatus');
    const fields = [document.getElementById('paidDate'), document.getElementById('paymentMethod')];
    const refresh = () => fields.forEach(field => { field.disabled = status.value !== 'paid'; field.required = status.value === 'paid'; });
    status.addEventListener('change', refresh);
    refresh();
})();
</script>
@endpush
