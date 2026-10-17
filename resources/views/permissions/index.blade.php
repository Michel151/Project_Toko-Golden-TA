@extends('layouts.app')
@section('title', 'Hak Akses')
@section('page-title', 'Hak Akses')

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
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAll"><i class="bi bi-check2-square me-1"></i>Pilih Semua</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="clearAll"><i class="bi bi-square me-1"></i>Kosongkan</button>
        </div>
    </div>
    <div class="table-responsive bg-white border rounded">
        <table class="table table-hover align-middle mb-0" style="min-width:720px">
            <thead><tr><th scope="col">Fitur</th>@foreach($actionLabels as $label)<th scope="col" class="text-center" style="width:15%">{{ $label }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach(collect($features)->groupBy('group', preserveKeys: true) as $group => $items)
                <tr class="table-light"><th colspan="{{ count($actionLabels) + 1 }}" scope="rowgroup">{{ $group }}</th></tr>
                @foreach($items as $feature => $definition)
                <tr data-feature="{{ $feature }}">
                    <th scope="row" class="fw-normal"><i class="bi bi-{{ $definition['icon'] }} me-2 text-muted"></i>{{ $definition['label'] }}</th>
                    @foreach($actionLabels as $action => $label)
                    <td class="text-center">
                        @if(in_array($action, $definition['actions'], true))
                        <input class="form-check-input permission-check" type="checkbox" name="permissions[]" value="{{ $feature.'.'.$action }}" data-action="{{ $action }}" aria-label="{{ $label }} {{ $definition['label'] }}" @checked(in_array($feature.'.'.$action, $chosen, true))>
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
    </div>
    <div class="d-flex justify-content-end mt-3">
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
@endpush
