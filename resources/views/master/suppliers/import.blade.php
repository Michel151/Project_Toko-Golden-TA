@extends('layouts.app')
@section('title','Import Supplier')
@section('page-title','Import Supplier')
@push('styles')
<style>
.trial-section{background:#fff;border-top:2px solid var(--app-border);padding:1rem;margin-bottom:1rem;min-width:0}
.trial-section h6{font-weight:700;margin:0 0 .75rem}.trial-section .table{font-size:.82rem;margin:0;min-width:640px}
.trial-section td{max-width:320px;overflow-wrap:anywhere}.trial-scroll{max-height:440px;overflow:auto}
.trial-section th{position:sticky;top:0;background:#eef2ec;z-index:1;white-space:normal}
.trial-section[aria-labelledby="trialSupplierHeading"] .table{min-width:400px;table-layout:fixed}
.trial-section[aria-labelledby="trialSupplierLinksHeading"] .table{table-layout:fixed}
.supplier-upload{display:flex;flex-wrap:wrap;align-items:end;gap:.75rem}.supplier-upload>div{flex:1 1 240px;min-width:0}
.supplier-issues{max-height:300px;overflow:auto}.supplier-summary .card-body{overflow-wrap:anywhere}
</style>
@endpush
@section('content')
<div class="report-heading d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3"><div><h5 class="fw-bold mb-1">Import Supplier & Pemasok Barang</h5><div class="small text-white-50">Pencatatan supplier dan hubungan pemasok barang</div></div><span class="badge bg-warning text-dark">Pratinjau + Simpan</span></div>
<div class="d-flex flex-wrap gap-2 mb-3"><a href="{{ route('master.suppliers.import.template') }}" class="btn btn-sm btn-success"><i class="bi bi-download me-1"></i>Template Supplier</a><x-back-link :fallback="route('master.suppliers.index')" /></div>
@section('validation-heading','File belum dapat diperiksa. Tidak ada data yang disimpan.')
<section class="trial-section"><form method="POST" action="{{ route('master.suppliers.import.preview') }}" enctype="multipart/form-data" class="supplier-upload">@csrf<div><label for="supplierFile" class="form-label">File Excel (.xlsx)</label><input id="supplierFile" type="file" name="file" accept=".xlsx" class="form-control" required></div><button type="submit" class="btn btn-success"><i class="bi bi-search me-1"></i>Periksa & Pratinjau</button></form></section>
@isset($preview)
<div class="alert alert-{{ $preview['error_count'] ? 'warning' : 'success' }}" role="status"><strong>{{ $preview['error_count'] ? 'Pratinjau parsial: perbaiki kesalahan pada file.' : 'File valid dan siap dikonfirmasi.' }}</strong> Supplier dan hubungan barang belum disimpan.</div>
<div class="row g-3 supplier-summary mb-3">@foreach([['Supplier valid',$preview['supplier_count']],['Hubungan valid',$preview['supplier_link_count']],['Kesalahan',$preview['error_count']]] as [$label,$count])<div class="{{ $loop->last ? 'col-12 col-sm-4' : 'col-6 col-sm-4' }}"><div class="card h-100"><div class="card-body"><div class="small text-muted">{{ $label }}</div><div class="fw-bold fs-5">{{ $count }}</div></div></div></div>@endforeach</div>
@if($preview['errors'] || $preview['warnings'])<section class="trial-section"><h6>Hasil Pemeriksaan</h6><div class="supplier-issues"><ul class="mb-0">@foreach($preview['errors'] as $issue)<li class="text-danger mb-2"><strong>{{ $issue['sheet'] }}, baris {{ $issue['row'] }}:</strong> {{ $issue['message'] }}</li>@endforeach @foreach($preview['warnings'] as $issue)<li class="text-warning-emphasis mb-2"><strong>{{ $issue['sheet'] }}, baris {{ $issue['row'] }}:</strong> {{ $issue['message'] }}</li>@endforeach</ul></div>@if($preview['error_count']>200)<div class="small text-muted mt-2">Menampilkan 200 kesalahan pertama.</div>@endif</section>@endif
@include('master.import.supplier-preview')
@endisset
@if(isset($preview) && ($run ?? null))
<section class="trial-section" aria-labelledby="supplierImportConfirmHeading">
    <h6 id="supplierImportConfirmHeading">Konfirmasi Import Supplier</h6>
    <p class="small text-muted">Seluruh {{ $preview['supplier_count'] }} supplier dan {{ $preview['supplier_link_count'] }} hubungan akan diproses, termasuk yang tidak ditampilkan pada pratinjau. Supplier dan harga hubungan yang sudah terdaftar tidak ditimpa. Stok, Master Barang, dan HPP transaksi lama tidak diubah.</p>
    <p class="small text-muted">Berlaku sampai {{ $run->expires_at->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB.</p>
    <form method="POST" action="{{ route('master.suppliers.import.store') }}" data-confirm="Simpan supplier dan hubungan barang baru sesuai pratinjau? Data lama tetap dipertahankan." data-confirm-title="Konfirmasi Import Supplier" data-confirm-label="Konfirmasi & Simpan" data-confirm-tone="success">
        @csrf
        <input type="hidden" name="token" value="{{ $run->token }}">
        <div class="mb-3"><a href="{{ route('master.suppliers.import',['preview'=>$run->token]) }}" class="small text-decoration-none"><i class="bi bi-arrow-clockwise me-1"></i>Buka ulang pratinjau</a></div>
        <div class="form-check mb-3"><input id="supplierImportConfirmed" type="checkbox" name="confirmed" value="1" class="form-check-input" required><label for="supplierImportConfirmed" class="form-check-label">Saya sudah memeriksa supplier, hubungan barang, satuan, dan harga beli.</label></div>
        <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i>Konfirmasi &amp; Simpan</button>
    </form>
</section>
@endif
<section class="trial-section"><h6>Format Template</h6><div class="table-responsive"><table class="table"><thead><tr><th>Sheet</th><th>Isi</th></tr></thead><tbody>
<tr><td>Master Supplier</td><td>kode_supplier, nama_supplier, alamat, telepon, id_supplier. Supplier baru: nama wajib, ID kosong. Nama yang sama dengan supplier terdaftar digunakan kembali tanpa menimpa data lama. Jika nama tidak unik, gunakan SUP-ID atau isi ID yang tepat.</td></tr>
<tr><td>Supplier Barang (opsional)</td><td>barcode atau id_barang, kode_supplier, satuan_beli, harga_beli. Barang harus sudah terdaftar dan aktif. Jika barcode dan ID sama-sama diisi, keduanya harus menunjuk barang yang sama. Satu pasangan barang-supplier per baris.</td></tr>
<tr><td>Referensi Barang (opsional)</td><td>Daftar ID, barcode, nama, dan satuan yang tersedia untuk membantu pengisian. Sheet ini bukan sumber data impor dan boleh dihapus. Akun tanpa akses Master Barang dapat mencoba supplier saja.</td></tr>
</tbody></table></div><p class="small text-muted mt-3 mb-0">Harga pada hubungan baru disetarakan ke satuan pembelian default barang; harga hubungan lama tetap dipertahankan. Template berisi contoh DEMO dan referensi sesuai hak akses. Batas 5 MB dan 5.000 baris per sheet. Barcode dan telepon memakai format teks agar nol di depan tetap tersimpan. Gunakan angka tanpa Rp/pemisah ribuan dan nilai biasa tanpa rumus. Konfirmasi berlaku satu jam; konfirmasi atau import ulang tidak menggandakan data.</p></section>
@endsection
