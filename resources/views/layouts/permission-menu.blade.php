@php
    $menuGroups = [];
    foreach (\App\Support\AccessPermissions::features() as $feature => $definition) {
        if (auth()->user()->hasPermission($feature.'.view')) {
            $menuGroups[$definition['group']][] = [
                'route' => str_starts_with($feature, 'report.') ? $feature : $feature.'.index',
                'pattern' => $feature.'*', 'label' => $definition['label'], 'icon' => $definition['icon'],
            ];
        }
    }
    if (auth()->user()->isOwner()) {
        $menuGroups['Master Data'][] = ['route' => 'master.users.index', 'pattern' => 'master.users.*', 'label' => 'Pengguna', 'icon' => 'people'];
        $menuGroups['Sistem'] = [
            ['route' => 'audit-logs.index', 'pattern' => 'audit-logs.*', 'label' => 'Audit Log', 'icon' => 'shield-lock'],
            ['route' => 'permissions.index', 'pattern' => 'permissions.*', 'label' => 'Hak Akses', 'icon' => 'shield-check'],
            ['route' => 'database-backups.index', 'pattern' => 'database-backups.*', 'label' => 'Backup Database', 'icon' => 'database-check'],
        ];
    }
    $groupIcons = ['Master Data' => 'grid', 'Pembelian' => 'bag-check', 'Penjualan' => 'cash-stack', 'Inventori' => 'boxes', 'Laporan' => 'bar-chart-line', 'Sistem' => 'gear'];
@endphp
@foreach($menuGroups as $group => $items)
@if(auth()->user()->isKasir() || auth()->user()->isStafGudang())
    @foreach($items as $item)
        @php $active = request()->routeIs($item['pattern']) || ($item['route'] === 'inventory.stock.index' && request()->routeIs('inventory.stock-card.*')); @endphp
        <li><a href="{{ route($item['route']) }}" class="nav-link {{ $active ? 'active' : '' }}" @if($active) aria-current="page" @endif><i class="bi bi-{{ $item['icon'] }}"></i>{{ $item['label'] }}</a></li>
    @endforeach
@else
@php
    $menuId = 'permission-menu-'.$loop->index;
    $expanded = collect($items)->contains(fn ($item) => request()->routeIs($item['pattern']))
        || ($group === 'Inventori' && request()->routeIs('inventory.stock-card.*'));
@endphp
<li>
    <button class="nav-section nav-section-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $menuId }}" aria-controls="{{ $menuId }}" aria-expanded="{{ $expanded ? 'true' : 'false' }}">
        <span class="section-label"><i class="bi bi-{{ $groupIcons[$group] }}"></i>{{ $group }}</span><i class="bi bi-chevron-down"></i>
    </button>
</li>
<li class="list-unstyled"><ul id="{{ $menuId }}" class="nav-submenu collapse list-unstyled {{ $expanded ? 'show' : '' }}">
    @foreach($items as $item)
    <li><a href="{{ route($item['route']) }}" class="nav-link {{ request()->routeIs($item['pattern']) ? 'active' : '' }}"><i class="bi bi-{{ $item['icon'] }}"></i>{{ $item['label'] }}</a></li>
    @endforeach
</ul></li>
@endif
@endforeach
