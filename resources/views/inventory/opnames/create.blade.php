{{-- resources/views/inventory/opnames/create.blade.php --}}
@extends('layouts.app')
@section('title', 'Buat Stock Opname')
@section('page-title', 'Buat Stock Opname Baru')
@push('styles')
<style>
#opnameParticipants[hidden], #opnameParticipantsLoading[hidden] { display:none!important; }
#opnameParticipantsMenu .form-check-input:checked { background-color:var(--app-accent-2);border-color:var(--app-accent-2); }
</style>
@endpush

@section('content')
<div class="row justify-content-center">
    <div class="col-md-5">
        <div class="card table-card">
            <div class="card-body">
                <p class="text-muted small mb-3">Sistem akan membuat draf opname berisi semua barang di lokasi yang dipilih. Anda kemudian mengisi jumlah fisik setiap barang.</p>
                <form method="POST" action="{{ route('inventory.opnames.store') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="opnameLocation" class="form-label fw-semibold">Lokasi <span class="text-danger">*</span></label>
                        <select id="opnameLocation" name="location_id" class="form-select @error('location_id') is-invalid @enderror" required>
                            <option value="">-- Pilih Lokasi --</option>
                            @foreach($locations as $loc)
                            <option value="{{ $loc->id }}" @selected((string) old('location_id') === (string) $loc->id)>{{ $loc->name }}</option>
                            @endforeach
                        </select>
                        @error('location_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="opnameDate" class="form-label fw-semibold">Tanggal Opname <span class="text-danger">*</span></label>
                        <input id="opnameDate" type="date" name="opname_date" class="form-control" value="{{ old('opname_date', today()->toDateString()) }}" required>
                    </div>
                    <div class="mb-4">
                        @php $chosenParticipants = collect(old('participant_ids', [auth()->id()]))->filter(fn ($id) => is_scalar($id))->map(fn ($id) => (string) $id)->all(); @endphp
                        <label id="opnameParticipantsLabel" for="opnameParticipants" class="form-label fw-semibold">Petugas Opname <span class="text-danger">*</span></label>
                        <div id="opnameParticipantsLoading" class="form-select text-muted" role="status" aria-live="polite">Memuat pilihan petugas…</div>
                        <select id="opnameParticipants" name="participant_ids[]" multiple size="5" class="form-select @error('participant_ids') is-invalid @enderror @error('participant_ids.*') is-invalid @enderror" required hidden>
                            @foreach($users as $user)
                            <option value="{{ $user->id }}" @selected(in_array((string) $user->id, $chosenParticipants, true))>{{ $user->name }} ({{ ['owner'=>'Owner','kasir'=>'Kasir','staf_gudang'=>'Staf Gudang'][$user->role] ?? $user->role }})</option>
                            @endforeach
                        </select>
                        <noscript><style>#opnameParticipants[hidden] { display:block!important; } #opnameParticipantsLoading { display:none; }</style></noscript>
                        @error('participant_ids')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        @error('participant_ids.*')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-clipboard-plus me-1"></i>Buat Draf</button>
                        <x-back-link :fallback="route('inventory.opnames.index')" label="Batal" :small="false" />
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
@push('scripts')
@include('inventory.opnames.participant-scripts')
@endpush
