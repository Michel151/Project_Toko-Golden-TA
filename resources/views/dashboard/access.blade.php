@extends('layouts.app')
@section('title', 'Dashboard')
@section('page-title', 'Dashboard')
@section('content')
<h5 class="mb-4">Selamat bekerja, {{ auth()->user()->name }}</h5>
<div class="list-group">
    @foreach(\App\Support\AccessPermissions::features() as $feature => $definition)
    @if(auth()->user()->hasPermission($feature.'.view'))
    <a class="list-group-item list-group-item-action d-flex align-items-center gap-2" href="{{ route(str_starts_with($feature, 'report.') ? $feature : $feature.'.index') }}">
        <i class="bi bi-{{ $definition['icon'] }}"></i><span>{{ $definition['label'] }}</span><i class="bi bi-chevron-right ms-auto"></i>
    </a>
    @endif
    @endforeach
</div>
@endsection
