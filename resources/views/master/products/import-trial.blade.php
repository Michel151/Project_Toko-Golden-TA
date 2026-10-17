@extends('layouts.app')
@section('title','Import Barang')
@section('page-title','Import Barang')
@push('styles')
<style>
.trial-section{background:#fff;border-top:2px solid var(--app-border);padding:1rem;margin-bottom:1rem;min-width:0}
.trial-section h6{font-weight:700;margin:0 0 .75rem}.trial-section .table{font-size:.82rem;margin:0;min-width:640px}
.trial-upload{display:flex;flex-wrap:wrap;align-items:end;gap:.75rem}.trial-upload>div{flex:1 1 240px;min-width:0}
.trial-problem-list{max-height:300px;overflow:auto}.trial-unit-row{display:flex;justify-content:space-between;gap:1rem;padding:.4rem 0;border-bottom:1px solid var(--app-border)}
.trial-number{font-weight:700;font-size:1.1rem}.trial-scroll{max-height:440px;overflow:auto}.trial-section th{position:sticky;top:0;background:#eef2ec;z-index:1}
.trial-summary .card-body{overflow-wrap:anywhere}
.trial-section td{max-width:320px;overflow-wrap:anywhere}
.trial-section[aria-labelledby="trialSupplierHeading"] .table{min-width:400px;table-layout:fixed}
.trial-section[aria-labelledby="trialSupplierLinksHeading"] .table{table-layout:fixed}
.trial-section[aria-labelledby="trialSupplierLinksHeading"] th{white-space:normal}
</style>
@endpush
@section('content')
<div class="report-heading d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3"><div><h5 class="fw-bold mb-1">Import Barang</h5><div class="small text-white-50">Pratinjau terlebih dahulu, lalu konfirmasi untuk membuat barang baru dan stok awal</div></div><span class="badge bg-warning text-dark">Pratinjau + Simpan</span></div>
<div class="d-flex flex-wrap gap-2 mb-3"><a href="{{ route('master.products.import.template') }}" class="btn btn-sm btn-success"><i class="bi bi-download me-1"></i>Template Excel (3 Sheet)</a><a href="{{ route('master.products.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a></div>
@section('validation-heading','File belum dapat diperiksa. Tidak ada data yang disimpan.')
<section class="trial-section"><form method="POST" action="{{ route('master.products.import.preview') }}" enctype="multipart/form-data" class="trial-upload">@csrf<div><label for="trialFile" class="form-label">File Excel (.xlsx)</label><input id="trialFile" type="file" name="file" accept=".xlsx" class="form-control" required></div><button type="submit" class="btn btn-success"><i class="bi bi-search me-1"></i>Periksa & Pratinjau</button></form></section>
@isset($preview)
<div class="alert alert-{{ $preview['error_count'] ? 'warning' : 'success' }}" role="status"><strong>{{ $preview['error_count'] ? 'Pratinjau parsial: perbaiki kesalahan pada file.' : 'Struktur file valid. Periksa hasil sebelum menyimpan.' }}</strong> Tidak ada data yang disimpan atau diubah.</div>
<div class="row g-3 trial-summary mb-3">@foreach([['Barang valid',$preview['product_count']],['Rincian Stok',$preview['stock_count']],['Kesalahan',$preview['error_count']]] as [$label,$count])<div class="{{ $loop->last ? 'col-12 col-lg' : 'col-6 col-lg' }}"><div class="card h-100"><div class="card-body"><div class="small text-muted">{{ $label }}</div><div class="trial-number">{{ $count }}</div></div></div></div>@endforeach</div>
@if($preview['errors'] || $preview['warnings'])<section class="trial-section"><h6>Hasil Pemeriksaan</h6><div class="trial-problem-list"><ul class="mb-0">@foreach($preview['errors'] as $issue)<li class="text-danger mb-2"><strong>{{ $issue['sheet'] }}, baris {{ $issue['row'] }}:</strong> {{ $issue['message'] }}</li>@endforeach @foreach($preview['warnings'] as $issue)<li class="text-warning-emphasis mb-2"><strong>{{ $issue['sheet'] }}, baris {{ $issue['row'] }}:</strong> {{ $issue['message'] }}</li>@endforeach</ul></div>@if($preview['error_count']>200)<div class="small text-muted mt-2">Menampilkan 200 kesalahan pertama.</div>@endif</section>@endif
<section class="trial-section"><h6>Konversi & Harga per Barang</h6><div class="table-responsive trial-scroll" tabindex="0" aria-label="Konversi satuan hasil pratinjau"><table class="table table-hover align-middle"><thead><tr><th>Barang</th><th>Satuan</th><th>Hubungan</th><th>Setara Satuan Dasar</th><th class="text-end">Harga Beli</th><th class="text-end">Harga Jual</th></tr></thead><tbody>
@forelse($preview['products'] as $product) @foreach($product['units'] as $unit)<tr>@if($loop->first)<td rowspan="{{ count($product['units']) }}"><strong>{{ $product['nama_barang'] }}</strong><div class="small text-muted">{{ $product['kode_barang'] }} - Beli: {{ $product['satuan_beli'] }} / Jual: {{ $product['satuan_jual'] }}</div><div class="small text-muted">{{ $product['kategori'] }} - {{ $product['barcode'] ?: 'Tanpa barcode' }}</div><div class="small text-muted">Dasar: {{ $product['base'] }} - Minimum: {{ $product['stok_minimum'] ?: 0 }} - Kedaluwarsa: {{ $product['kedaluwarsa'] }}</div></td>@endif<td>{{ $unit['satuan'] }}</td><td>1 {{ $unit['satuan'] }} = {{ rtrim(rtrim(number_format($unit['isi'],4,',','.'),'0'),',') }} {{ $unit['satuan_isi'] }}</td><td>{{ rtrim(rtrim(number_format($unit['conversion'],4,',','.'),'0'),',') }} {{ $product['base'] }}</td><td class="text-end text-nowrap">Rp {{ number_format($unit['harga_beli'],2,',','.') }}</td><td class="text-end text-nowrap">Rp {{ number_format($unit['harga_jual'],2,',','.') }}</td></tr>@endforeach @empty<tr><td colspan="6" class="text-muted text-center py-3">Belum ada konfigurasi barang yang valid.</td></tr>@endforelse
</tbody></table></div>@if($preview['product_count']>100)<div class="small text-muted mt-2">Menampilkan 100 barang pertama.</div>@endif</section>
<section class="trial-section"><h6>Stok Fisik & Layak Jual per Lokasi / Kedaluwarsa</h6><div class="table-responsive trial-scroll" tabindex="0" aria-label="Stok hasil pratinjau"><table class="table table-hover align-middle"><thead><tr><th>Barang / Lokasi</th><th>Rincian Input</th><th>Kedaluwarsa</th><th class="text-end">Stok Fisik</th><th class="text-end">Layak Jual</th></tr></thead><tbody>
@forelse($preview['stocks'] as $stock)<tr><td><strong>{{ $stock['name'] }}</strong><div>{{ $stock['location'] }}</div><div class="small text-muted">{{ $stock['code'] }}</div></td><td>@foreach(array_slice($stock['parts'],0,10) as $part)<div>{{ rtrim(rtrim(number_format($part['quantity'],4,',','.'),'0'),',') }} {{ $part['unit'] }} <span class="small text-muted">(baris {{ $part['row'] }})</span></div>@endforeach @if(count($stock['parts'])>10)<div class="small text-muted">+ {{ count($stock['parts'])-10 }} baris lainnya</div>@endif</td><td>{{ $stock['expiry'] ? \Carbon\Carbon::parse($stock['expiry'])->format('d/m/Y') : '-' }}</td><td class="text-end text-nowrap fw-bold">{{ rtrim(rtrim(number_format($stock['quantity'],4,',','.'),'0'),',') }} {{ $stock['base'] }}</td><td class="text-end text-nowrap">{{ rtrim(rtrim(number_format($stock['sellable'],4,',','.'),'0'),',') }} {{ $stock['base'] }}</td></tr>@empty<tr><td colspan="5" class="text-muted text-center py-3">Tidak ada stok awal valid. Sheet Stok Awal boleh hanya berisi judul kolom.</td></tr>@endforelse
</tbody></table></div>@if($preview['stock_count']>200)<div class="small text-muted mt-2">Menampilkan 200 kelompok stok pertama.</div>@endif</section>
@if($preview['supplier_count'] || $preview['supplier_link_count'])
<div class="alert alert-info">Data supplier dari template lama hanya dipratinjau. Pengisian supplier selanjutnya tersedia di menu Import Supplier.</div>
@include('master.import.supplier-preview')
@endif
@endisset
@if(isset($preview) && ($run ?? null))
<section class="trial-section" aria-labelledby="multiImportConfirmHeading">
    <h6 id="multiImportConfirmHeading">Simpan Hasil Impor</h6>
    <p class="small text-muted">Seluruh {{ $preview['product_count'] }} barang dan {{ $preview['stock_count'] }} kelompok stok akan diproses, termasuk yang tidak ditampilkan karena batas pratinjau. Berlaku sampai {{ $run->expires_at->timezone('Asia/Jakarta')->format('H:i') }} WIB.</p>
    <form id="multiImportForm" method="POST" action="{{ route('master.products.import.store') }}" data-confirm="Buat barang baru beserta satuan dan stok awal sesuai hasil pratinjau ini? Data akan disimpan ke database." data-confirm-title="Simpan Impor Multi Satuan?" data-confirm-label="Ya, Simpan Impor" data-confirm-tone="success">
        @csrf
        <input type="hidden" name="token" value="{{ $run->token }}">
        <div class="mb-3"><a href="{{ route('master.products.import',['preview'=>$run->token]) }}" class="small text-decoration-none"><i class="bi bi-arrow-clockwise me-1"></i>Buka ulang pratinjau tersimpan</a></div>
        <div class="form-check mb-3"><input id="multiImportConfirmed" type="checkbox" name="confirmed" value="1" class="form-check-input" required><label for="multiImportConfirmed" class="form-check-label">Saya sudah memeriksa satuan, harga, lokasi, dan stok awal.</label></div>
        <button id="multiImportSave" type="submit" class="btn btn-success" disabled><i class="bi bi-check-circle me-1"></i>Simpan Impor</button>
    </form>
</section>
@elseif(isset($preview) && !($canStore ?? true))
<div class="alert alert-info">File lima sheet lama masih dapat dipratinjau, tetapi tidak disimpan. Gunakan template tiga sheet barang dan menu Import Supplier yang terpisah.</div>
@endif
<section class="trial-section"><h6>Format Template</h6><div class="table-responsive"><table class="table"><thead><tr><th>Sheet</th><th>Isi</th></tr></thead><tbody><tr><td>Barang</td><td>Satu baris per kode barang: nama, barcode, kategori, satuan beli/jual, stok minimum dalam satuan dasar, kedaluwarsa ya/tidak.</td></tr><tr><td>Satuan</td><td>Satu baris per satuan. Contoh: Dus berisi 5 Bal; Bal berisi 10 Slop; Slop berisi 10 Bks; Bks berisi 1 Bks sebagai dasar. Harga berlaku per satuan pada baris tersebut.</td></tr><tr><td>Stok Awal</td><td>Satu baris per barang, lokasi, satuan, dan tanggal kedaluwarsa. Stok campuran dengan lokasi dan tanggal yang sama dijumlahkan; tanggal berbeda dicatat terpisah. Isi tanggal_kedaluwarsa jika kedaluwarsa barang adalah ya; kosongkan jika tidak.</td></tr></tbody></table></div><p class="small text-muted mt-3 mb-0">Kode barang hanya menghubungkan sheet dalam file, bukan penentu barang lama di database. Data supplier dan hubungan pemasok diisi terpisah melalui Master Supplier - Import Supplier. Template lima sheet lama tetap dapat dipratinjau, tetapi template baru khusus barang memakai tiga sheet. Template berisi contoh DEMO yang boleh dihapus. Batas 5 MB dan 5.000 baris per sheet. Gunakan angka tanpa Rp/pemisah ribuan, tanggal YYYY-MM-DD atau tanggal Excel, serta nilai biasa tanpa rumus. Impor multi satuan hanya untuk barang baru. Barang terdaftar tidak ditimpa; perubahan stok berikutnya melalui penerimaan barang atau stock opname. Pratinjau belum menyimpan barang atau stok. Konfirmasi berlaku satu jam dan tidak menggandakan stok jika dikirim ulang.</p></section>
@endsection
@push('scripts')
<script>
const multiImportConfirmed=document.getElementById('multiImportConfirmed');
if(multiImportConfirmed) multiImportConfirmed.addEventListener('change',()=>document.getElementById('multiImportSave').disabled=!multiImportConfirmed.checked);
</script>
@endpush
