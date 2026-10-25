@push('styles')
<style>
.transfer-items-scroll { max-height:50vh; overflow:auto; }
.transfer-items-scroll thead { position:sticky; top:0; background:#eef2ed; z-index:1; }
.transfer-items-table { table-layout:fixed; }
.transfer-items-table th:first-child { width:44px; }
.transfer-items-table th:last-child { width:32%; }
.transfer-items-table td { overflow-wrap:anywhere; }
</style>
@endpush
<div class="modal fade" id="transferItemsModal" tabindex="-1" aria-labelledby="transferItemsTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><div><h5 class="modal-title fs-6" id="transferItemsTitle">Rincian Barang</h5><div id="transferItemsContext" class="small text-muted mt-1"></div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
        <div class="modal-body">
            <label for="transferItemsSearch" class="form-label small">Cari Barang</label>
            <input type="search" class="form-control form-control-sm mb-2" id="transferItemsSearch" placeholder="Nama barang" autocomplete="off">
            <div id="transferItemsCount" class="small text-muted mb-2" aria-live="polite"></div>
            <div class="transfer-items-scroll"><table class="table table-sm align-middle transfer-items-table mb-0"><thead><tr><th>No.</th><th>Barang</th><th class="text-end">Jumlah</th></tr></thead><tbody id="transferItemsBody"></tbody></table></div>
            <div id="transferItemsEmpty" class="text-center text-muted py-3" hidden>Tidak ada barang yang sesuai pencarian.</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Tutup</button></div>
    </div></div>
</div>
@push('scripts')
@include('components.transfer-items-scripts')
@endpush
