@extends('layouts.app')
@section('title', 'Import Barang')
@section('page-title', 'Import Barang')

@section('content')
<div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
    <h5 class="mb-0">Import Barang dari Excel</h5>
    <div class="d-flex flex-wrap gap-2">
        @if(auth()->user()->canAccessRoute('master.products.import-trial'))<a href="{{ route('master.products.import-trial') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-table me-1"></i>Import Multi Satuan</a>@endif
        @if(auth()->user()->canAccessRoute('master.products.import.template')) <a href="{{ route('master.products.import.template') }}" class="btn btn-outline-success btn-sm"><i class="bi bi-download me-1"></i> Unduh Template</a> @endif
        <a href="{{ route('master.products.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
    </div>
</div>
@section('validation-heading','Impor dibatalkan. Tidak ada data yang disimpan.')
@if(auth()->user()->canAccessRoute('master.products.import.store')) <form method="POST" action="{{ route('master.products.import.store') }}" enctype="multipart/form-data" class="mb-4">
    @csrf
    <div class="mb-3">
        <label for="file" class="form-label">File Excel (.xlsx / .xls)</label>
        <input id="file" name="file" type="file" accept=".xlsx,.xls" class="form-control" required>
        <div class="form-text">Maksimal 10 MB dan 5.000 baris. Data dibaca dari lembar pertama.</div>
    </div>
    <div class="form-check mb-3">
        <input id="import-stock" name="import_stock" value="1" type="checkbox" class="form-check-input" @checked(old('import_stock'))>
        <label for="import-stock" class="form-check-label">Sesuaikan stok dengan kolom stok_aktual, termasuk stok barang yang sudah ada.</label>
    </div>
    <button class="btn btn-primary"><i class="bi bi-upload me-1"></i> Import Barang</button>
</form> @endif
<div class="alert alert-warning">
    Stok aktual merupakan jumlah akhir per lokasi dalam satuan dasar. Nilai 0 mengosongkan stok; sel kosong mempertahankan stok. Perubahan stok dicatat dalam riwayat mutasi.
</div>
<p>Barang baru menggunakan satu satuan dasar. Kategori, satuan, dan lokasi yang belum tersedia dibuat otomatis. Untuk barang terdaftar, gunakan barcode yang tersimpan atau nama yang unik; harga, kategori, satuan, dan stok minimum tetap mengikuti data sistem. Konfigurasi dus/pack dan kedaluwarsa dapat dilengkapi melalui Edit Barang.</p>
<p>Supplier yang diisi langsung dihubungkan dengan barang. Nama supplier baru dibuat otomatis; alamat dan telepon dapat dilengkapi di Master Supplier. Harga hubungan baru mengikuti harga beli satuan pembelian barang yang tersimpan. Hubungan dan harga supplier yang sudah ada tetap dipertahankan.</p>
<div class="table-responsive">
<table class="table table-sm">
    <thead><tr><th>Kolom</th><th>Isi</th><th>Contoh</th></tr></thead>
    <tbody>
        <tr><td>nama_barang</td><td>Wajib, nama barang</td><td>Air Mineral 600 ml</td></tr>
        <tr><td>barcode</td><td>Opsional untuk barang baru; gunakan format sel Teks agar nol di depan tetap tersimpan</td><td>0012345678901</td></tr>
        <tr><td>kategori</td><td>Wajib</td><td>Minuman</td></tr>
        <tr><td>satuan_dasar</td><td>Wajib, satuan terkecil yang menyimpan stok</td><td>Pcs</td></tr>
        <tr><td>harga_beli / harga_jual</td><td>Wajib, angka per satuan dasar tanpa Rp atau pemisah ribuan</td><td>3000 / 4000</td></tr>
        <tr><td>lokasi</td><td>Wajib; ulangi barang pada baris lain untuk lokasi berbeda</td><td>Gudang</td></tr>
        <tr><td>stok_aktual</td><td>Opsional, jumlah akhir dalam satuan dasar</td><td>77</td></tr>
        <tr><td>stok_minimum</td><td>Opsional, minimum total barang baru dalam satuan dasar</td><td>10</td></tr>
        <tr><td>supplier</td><td>Opsional; isi nama supplier, pisahkan dengan titik koma untuk beberapa supplier. Kosong mempertahankan hubungan yang ada.</td><td>PT Sumber Makmur; CV Jaya Abadi</td></tr>
    </tbody>
</table>
</div>
@endsection
