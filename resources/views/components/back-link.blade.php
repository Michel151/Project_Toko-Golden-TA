@props(['fallback', 'label' => 'Kembali', 'small' => true])
<a href="{{ \App\Support\ReturnNavigation::back($fallback) }}" data-back-link {{ $attributes->class(['btn', 'btn-outline-secondary', 'btn-sm' => $small]) }}>@if(mb_strtolower(trim($label)) !== 'batal')<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>@endif{{ $label }}</a>
