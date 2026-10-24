@extends('layouts.app')
@section('title', 'Hak Akses')
@section('page-title', 'Hak Akses')
@push('styles')
<style>
.permission-scroll { max-height:60vh;overflow:auto; }
.permission-table { min-width:1000px;table-layout:fixed; }
.permission-table thead th { position:sticky;top:0;z-index:3;vertical-align:middle;font-size:11px;overflow-wrap:anywhere; }
.permission-table th:first-child { width:200px; }
.permission-table [data-feature] th { position:sticky;left:0;background:#fff;z-index:1; }
.permission-table thead th:first-child { left:0;z-index:4; }
.permission-table .permission-group th { background:#edf3ee; }
.permission-table .permission-action { position:relative;padding:12px 8px; }
.permission-table .permission-check { width:18px;height:18px;cursor:pointer; }
.permission-table .permission-check:checked { background-color:var(--app-accent-2);border-color:var(--app-accent-2); }
.permission-table .permission-check:focus { border-color:#80ad99;box-shadow:0 0 0 .18rem rgba(31,122,90,.15); }
.permission-table .permission-action-label { display:none; }
.permission-table tr[hidden] { display:none!important; }
.permission-tools { display:flex;flex-wrap:wrap;justify-content:space-between;align-items:end;gap:12px;padding:14px 16px;border-bottom:1px solid var(--app-border); }
.permission-tools .app-filter-field { width:300px;max-width:100%; }
.permission-tools .permission-bulk { display:flex;flex-wrap:wrap;gap:8px; }
.permission-footer { display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;margin-top:16px; }
@media(max-width:767px) {
    .permission-scroll { max-height:65vh; }
    .permission-table,.permission-table tbody { display:block;width:100%;min-width:0; }
    .permission-table thead { display:none; }
    .permission-table tbody tr[data-feature] { display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 16px;padding:12px 16px;border-bottom:1px solid var(--app-border); }
    .permission-table [data-feature] th { position:static;grid-column:1/-1;width:auto;border:0;padding:0 0 4px;overflow-wrap:anywhere; }
    .permission-table .permission-action { display:flex;align-items:center;gap:8px;border:0;padding:0;text-align:left!important;min-width:0; }
    .permission-table .permission-action-label { display:block;font-size:12px;text-align:left;cursor:pointer;overflow-wrap:anywhere; }
    .permission-table .permission-check { flex:0 0 18px;margin:0; }
    .permission-table .permission-unavailable { display:none; }
    .permission-table .permission-group,.permission-table .permission-group th { display:block;width:100%; }
    .permission-table .permission-group th { padding:10px 16px;border:0; }
    .permission-tools .app-filter-field { width:100%; }
}
</style>
@endpush

@section('content')
<div class="inventory-heading d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><i class="bi bi-shield-lock me-2"></i>Hak Akses</h5>
    <span class="badge bg-success">Owner: akses penuh</span>
</div>
<ul class="nav nav-tabs mb-3">
    @foreach($roles as $key => $label)
    <li class="nav-item"><a class="nav-link {{ $role === $key ? 'active' : '' }}" href="{{ route('permissions.index', ['role' => $key]) }}" @if($role === $key) aria-current="page" @endif>{{ $label }}</a></li>
    @endforeach
</ul>
<form method="POST" action="{{ route('permissions.update', $role) }}" id="permissionForm">
    @csrf @method('PUT')
    <input type="hidden" name="submitted_role" value="{{ $role }}">
    @php
        $chosen = old('submitted_role') === $role ? old('permissions', []) : $selected;
        $actionLabels = ['view' => 'Melihat', 'create' => 'Menambah', 'edit' => 'Mengubah / Menyelesaikan', 'delete' => 'Menghapus / Membatalkan', 'import' => 'Impor', 'approve' => 'Menyetujui / Menolak', 'dispatch' => 'Menyerahkan Retur'];
    @endphp
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h6 class="mb-0">{{ $roles[$role] }}</h6>
        <span id="permissionSummary" class="small text-muted" aria-live="polite"></span>
    </div>
    <div class="bg-white border rounded">
        <div class="permission-tools">
            <div class="app-filter-field"><label for="permissionSearch">Cari Fitur</label><input type="search" class="form-control form-control-sm" id="permissionSearch" placeholder="Nama fitur atau kelompok..." autocomplete="off"></div>
            <div class="permission-bulk">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAll" title="Pilih seluruh izin, termasuk fitur di luar pencarian"><i class="bi bi-check2-square me-1"></i>Pilih Semua</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="clearAll" title="Kosongkan seluruh izin, termasuk fitur di luar pencarian"><i class="bi bi-square me-1"></i>Kosongkan</button>
            </div>
        </div>
        <div class="permission-scroll" tabindex="0" role="region" aria-label="Daftar izin {{ $roles[$role] }}">
        <table class="table table-hover align-middle permission-table mb-0">
            <thead><tr><th scope="col">Fitur</th>@foreach($actionLabels as $label)<th scope="col" class="text-center">{{ $label }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach(collect($features)->groupBy('group', preserveKeys: true) as $group => $items)
                <tr class="permission-group" data-permission-group="{{ $loop->index }}"><th colspan="{{ count($actionLabels) + 1 }}" scope="rowgroup">{{ $group }}</th></tr>
                @foreach($items as $feature => $definition)
                <tr data-feature="{{ $feature }}" data-group="{{ $loop->parent->index }}" data-search="{{ mb_strtolower($group.' '.$definition['label']) }}">
                    <th scope="row" class="fw-normal"><i class="bi bi-{{ $definition['icon'] }} me-2 text-muted"></i>{{ $definition['label'] }}</th>
                    @foreach($actionLabels as $action => $label)
                    <td class="text-center {{ in_array($action, $definition['actions'], true) ? 'permission-action' : 'permission-unavailable' }}">
                        @if(in_array($action, $definition['actions'], true))
                        <input id="permission-{{ str_replace('.', '-', $feature).'-'.$action }}" class="form-check-input permission-check" type="checkbox" name="permissions[]" value="{{ $feature.'.'.$action }}" data-action="{{ $action }}" aria-label="{{ $label }} {{ $definition['label'] }}" @checked(in_array($feature.'.'.$action, $chosen, true))>
                        <label class="permission-action-label" for="permission-{{ str_replace('.', '-', $feature).'-'.$action }}">{{ $label }}</label>
                        @else
                        <span class="text-muted" aria-label="Tidak tersedia">-</span>
                        @endif
                    </td>
                    @endforeach
                </tr>
                @endforeach
                @endforeach
            </tbody>
        </table>
        <div id="permissionEmpty" class="text-center text-muted py-4" hidden>Tidak ada fitur yang sesuai pencarian.</div>
        </div>
    </div>
    <div class="permission-footer">
        <span id="permissionVisibleCount" class="small text-muted" aria-live="polite"></span>
        <button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-1"></i>Simpan Hak Akses</button>
    </div>
</form>
@endsection

@push('scripts')
<script>
    const permissionForm = document.getElementById('permissionForm');
    permissionForm.addEventListener('change', (event) => {
        if (!event.target.matches('.permission-check')) return;
        const row = event.target.closest('tr');
        if (event.target.dataset.action === 'view' && !event.target.checked) {
            row.querySelectorAll('.permission-check').forEach(input => input.checked = false);
        } else if (event.target.checked) {
            row.querySelector('[data-action="view"]').checked = true;
        }
    });
    document.getElementById('selectAll').addEventListener('click', () => {
        permissionForm.querySelectorAll('.permission-check').forEach(input => input.checked = true);
    });
    document.getElementById('clearAll').addEventListener('click', () => {
        permissionForm.querySelectorAll('.permission-check').forEach(input => input.checked = false);
    });
</script>
<script src="{{ asset('js/permission-layout.js') }}"></script>
@endpush
