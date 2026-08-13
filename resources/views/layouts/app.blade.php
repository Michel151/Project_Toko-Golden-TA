<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Toko Golden') — Sistem Inventori</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --sidebar-width: 250px;
            --sidebar-collapsed-width: 76px;
            --golden: #c8960c;
            --golden-dark: #a07808;
        }

        body { background-color: #f4f6f9; font-size: 0.9rem; }

        /* Sidebar */
        #sidebar {
            width: var(--sidebar-width);
            min-height: 100vh;
            background: #1a2035;
            position: fixed;
            top: 0; left: 0;
            z-index: 1000;
            transition: all 0.3s;
            overflow-y: auto;
            box-shadow: 4px 0 18px rgba(10, 15, 30, 0.18);
            scrollbar-width: thin;
            scrollbar-color: rgba(255,255,255,0.12) transparent;
        }

        #sidebar .sidebar-brand {
            min-height: 68px;
            padding: 1.1rem 1rem;
            background: #141929;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }

        #sidebar .sidebar-brand span {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--golden);
            white-space: nowrap;
            overflow: hidden;
            transition: opacity 0.2s;
        }

        #sidebar .nav-link {
            color: #adb5bd;
            min-height: 42px;
            padding: 0.6rem 0.9rem;
            border-radius: 8px;
            margin: 3px 10px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border-left: 3px solid transparent;
        }

        #sidebar .nav-link i {
            width: 20px;
            color: #8490ad;
            text-align: center;
            transition: color 0.2s;
        }

        #sidebar .nav-link:hover,
        #sidebar .nav-link.active {
            color: #fff;
            background: rgba(200, 150, 12, 0.2);
            border-left: 3px solid var(--golden);
        }

        #sidebar .nav-link:hover i,
        #sidebar .nav-link.active i { color: var(--golden); }

        #sidebar .nav-link.active {
            background: linear-gradient(90deg, rgba(200,150,12,0.25), rgba(200,150,12,0.10));
            box-shadow: inset 0 0 0 1px rgba(200,150,12,0.10);
        }

        #sidebar .nav-section {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #8490ad;
            padding: 0.7rem 0.85rem;
            font-weight: 600;
        }

        #sidebar .nav-section-toggle {
            width: calc(100% - 20px);
            margin: 4px 10px 2px;
            border: 0;
            background: transparent;
            display: flex;
            align-items: center;
            justify-content: space-between;
            text-align: left;
            border-radius: 8px;
            transition: color 0.2s, background-color 0.2s;
        }

        #sidebar .nav-section-toggle:hover {
            color: #d9deea;
            background: rgba(255,255,255,0.04);
        }

        #sidebar .nav-section-toggle[aria-expanded="true"] {
            color: var(--golden);
            background: rgba(200,150,12,0.07);
        }

        #sidebar .section-label {
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }

        #sidebar .section-label i {
            width: 18px;
            font-size: 0.85rem;
            text-align: center;
        }

        #sidebar .nav-section-toggle .bi-chevron-down {
            font-size: 0.65rem;
            transition: transform 0.25s;
        }
        #sidebar .nav-section-toggle[aria-expanded="true"] .bi-chevron-down { transform: rotate(180deg); }

        #sidebar .nav-submenu {
            position: relative;
            margin: 2px 10px 8px;
            padding: 3px 0 4px 8px;
            border-left: 1px solid rgba(200,150,12,0.22);
        }

        #sidebar .nav-submenu .nav-link {
            min-height: 38px;
            margin: 2px 0 2px 5px;
            padding: 0.5rem 0.75rem;
            font-size: 0.84rem;
        }

        /* Main content */
        #main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: all 0.3s;
        }

        @media (min-width: 769px) {
            body.sidebar-collapsed #sidebar { width: var(--sidebar-collapsed-width); }
            body.sidebar-collapsed #main-content { margin-left: var(--sidebar-collapsed-width); }
            body.sidebar-collapsed #sidebar .sidebar-brand { justify-content: center; }
            body.sidebar-collapsed #sidebar .sidebar-brand span { display: none; }
            body.sidebar-collapsed #sidebar .nav-link {
                justify-content: center;
                gap: 0;
                font-size: 0;
                padding-left: 0.75rem;
                padding-right: 0.75rem;
            }
            body.sidebar-collapsed #sidebar .nav-link i {
                width: auto;
                font-size: 1.05rem;
            }
            body.sidebar-collapsed #sidebar .nav-section-toggle {
                justify-content: center;
                padding-left: 0;
                padding-right: 0;
            }
            body.sidebar-collapsed #sidebar .section-label { font-size: 0; }
            body.sidebar-collapsed #sidebar .section-label i { font-size: 1rem; }
            body.sidebar-collapsed #sidebar .nav-section-toggle > .bi-chevron-down,
            body.sidebar-collapsed #sidebar .nav-submenu { display: none; }
        }

        /* Topbar */
        #topbar {
            background: #fff;
            border-bottom: 1px solid #e9ecef;
            padding: 0.75rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 999;
        }

        .notification-hover { position: relative; }
        .notification-preview {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: min(360px, calc(100vw - 2rem));
            background: #fff;
            border: 1px solid #e7eaf0;
            border-radius: 12px;
            box-shadow: 0 12px 32px rgba(23, 32, 57, .18);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-6px);
            transition: opacity .18s ease, transform .18s ease, visibility .18s;
            overflow: hidden;
            z-index: 1050;
        }
        .notification-hover:hover .notification-preview,
        .notification-hover:focus-within .notification-preview {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        .notification-preview::before {
            content: '';
            position: absolute;
            top: -6px;
            right: 14px;
            width: 12px;
            height: 12px;
            background: #fff;
            border-top: 1px solid #e7eaf0;
            border-left: 1px solid #e7eaf0;
            transform: rotate(45deg);
        }
        .notification-preview-header { padding: .85rem 1rem; border-bottom: 1px solid #edf0f4; }
        .notification-preview-list { max-height: 320px; overflow-y: auto; }
        .notification-preview-item { padding: .8rem 1rem; border-bottom: 1px solid #f0f2f5; }
        .notification-preview-item:last-child { border-bottom: 0; }
        .notification-preview-item:hover { background: #fafbfc; }
        .notification-preview-footer { display: block; padding: .75rem 1rem; text-align: center; background: #fafbfc; }

        .page-content { padding: 1.5rem; }

        /* Cards */
        .stat-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
        }

        .stat-card .card-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
        }

        /* Report pages */
        .report-heading {
            padding: 1.25rem 1.4rem;
            border-radius: 14px;
            background: linear-gradient(120deg, #172039, #27324a);
            color: #fff;
            box-shadow: 0 4px 16px rgba(23,32,57,.14);
        }
        .report-heading .report-icon { width:44px; height:44px; display:grid; place-items:center; border-radius:12px; background:rgba(200,150,12,.18); color:var(--golden); font-size:1.25rem; }
        .report-toolbar { background:#fff; border:1px solid #e8ebf1; border-radius:12px; padding:.85rem 1rem; box-shadow:0 2px 10px rgba(25,35,55,.04); }
        .report-toolbar label { font-size:.7rem; color:#7b8498; font-weight:600; margin-bottom:.2rem; text-transform:uppercase; letter-spacing:.04em; }
        .report-stat { border:0; border-radius:13px; box-shadow:0 3px 14px rgba(25,35,55,.06); overflow:hidden; }
        .report-stat::before { content:''; display:block; height:3px; background:var(--stat-color, var(--golden)); }
        .report-stat .stat-symbol { width:38px; height:38px; display:grid; place-items:center; border-radius:10px; background:color-mix(in srgb, var(--stat-color, #c8960c) 12%, transparent); color:var(--stat-color, #c8960c); font-size:1rem; }
        .report-table thead th { padding:.75rem .7rem; white-space:nowrap; }
        .report-table tbody td { padding:.7rem; vertical-align:middle; }
        .report-empty { padding:3rem 1rem !important; text-align:center; color:#8b93a5; }
        @media (max-width:768px) { .report-toolbar form { width:100%; } }

        /* Purchase order and receipt pages */
        .procurement-heading { background:linear-gradient(120deg,#172039,#27324a);color:#fff;border-radius:14px;padding:1.15rem 1.3rem;box-shadow:0 4px 16px rgba(23,32,57,.14); }
        .procurement-heading .heading-icon { width:44px;height:44px;display:grid;place-items:center;border-radius:12px;background:rgba(200,150,12,.18);color:#ffc107;font-size:1.2rem; }
        .procurement-stat { border:0;border-radius:13px;box-shadow:0 3px 14px rgba(25,35,55,.06);overflow:hidden; }
        .procurement-stat::before { content:'';display:block;height:3px;background:var(--accent,#c8960c); }
        .procurement-filter { background:#fff;border:1px solid #e8ebf1;border-radius:12px;padding:.85rem 1rem;box-shadow:0 2px 10px rgba(25,35,55,.04); }
        .procurement-filter label { display:block;font-size:.68rem;color:#7b8498;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.2rem; }
        .procurement-table th { white-space:nowrap;padding:.75rem .7rem; }.procurement-table td { vertical-align:middle;padding:.72rem .7rem; }

        /* Tables */
        .table-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            overflow: hidden;
        }

        .table thead th {
            background: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            font-weight: 600;
        }

        /* Badges role */
        .badge-owner     { background: #6f42c1; }
        .badge-kasir     { background: #0d6efd; }
        .badge-staf      { background: #20c997; }

        /* Responsive */
        @media (max-width: 768px) {
            #sidebar { left: -250px; }
            #sidebar.show { left: 0; }
            #main-content { margin-left: 0; }
            #sidebarBackdrop {
                position: fixed;
                inset: 0;
                z-index: 999;
                background: rgba(10, 15, 30, 0.48);
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.3s, visibility 0.3s;
            }
            #sidebarBackdrop.show { opacity: 1; visibility: visible; }
        }

    </style>

    @stack('styles')
</head>
<body>

{{-- SIDEBAR --}}
<nav id="sidebar">
    <div class="sidebar-brand">
        <i class="bi bi-shop fs-4" style="color: var(--golden)"></i>
        <span>Toko Golden</span>
    </div>

    <ul class="nav flex-column mt-2 pb-4">

        {{-- Dashboard (semua role) --}}
        <li class="nav-item">
            <a href="{{ route('dashboard') }}"
               class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
        </li>

        {{-- Master Data (owner) --}}
        @if(auth()->user()->isOwner())
        <li>
            <button class="nav-section nav-section-toggle" type="button" data-bs-toggle="collapse"
                    data-bs-target="#masterMenu" aria-controls="masterMenu"
                    aria-expanded="{{ request()->routeIs('master.*', 'audit-logs.*') ? 'true' : 'false' }}">
                <span class="section-label"><i class="bi bi-grid"></i>Master Data</span><i class="bi bi-chevron-down"></i>
            </button>
        </li>
        <li class="list-unstyled"><ul id="masterMenu" class="nav-submenu collapse list-unstyled {{ request()->routeIs('master.*', 'audit-logs.*') ? 'show' : '' }}">
        <li>
            <a href="{{ route('master.products.index') }}"
               class="nav-link {{ request()->routeIs('master.products.*') ? 'active' : '' }}">
                <i class="bi bi-box-seam"></i> Barang
            </a>
        </li>
        <li>
            <a href="{{ route('master.categories.index') }}"
               class="nav-link {{ request()->routeIs('master.categories.*') ? 'active' : '' }}">
                <i class="bi bi-tags"></i> Kategori
            </a>
        </li>
        <li>
            <a href="{{ route('master.units.index') }}"
               class="nav-link {{ request()->routeIs('master.units.*') ? 'active' : '' }}">
                <i class="bi bi-rulers"></i> Satuan
            </a>
        </li>
        <li>
            <a href="{{ route('master.locations.index') }}"
               class="nav-link {{ request()->routeIs('master.locations.*') ? 'active' : '' }}">
                <i class="bi bi-geo-alt"></i> Lokasi/Gudang
            </a>
        </li>
        <li>
            <a href="{{ route('master.suppliers.index') }}"
               class="nav-link {{ request()->routeIs('master.suppliers.*') ? 'active' : '' }}">
                <i class="bi bi-truck"></i> Supplier
            </a>
        </li>
        <li>
            <a href="{{ route('master.users.index') }}"
               class="nav-link {{ request()->routeIs('master.users.*') ? 'active' : '' }}">
                <i class="bi bi-people"></i> Pengguna
            </a>
        </li>
        <li>
            <a href="{{ route('audit-logs.index') }}"
               class="nav-link {{ request()->routeIs('audit-logs.*') ? 'active' : '' }}">
                <i class="bi bi-shield-lock"></i> Audit Log
            </a>
        </li>
        </ul></li>
        @endif

        {{-- Pembelian (owner) --}}
        @if(auth()->user()->isOwner())
        <li>
            <button class="nav-section nav-section-toggle" type="button" data-bs-toggle="collapse"
                    data-bs-target="#purchaseMenu" aria-controls="purchaseMenu"
                    aria-expanded="{{ request()->routeIs('purchase.*') ? 'true' : 'false' }}">
                <span class="section-label"><i class="bi bi-bag-check"></i>Pembelian</span><i class="bi bi-chevron-down"></i>
            </button>
        </li>
        <li class="list-unstyled"><ul id="purchaseMenu" class="nav-submenu collapse list-unstyled {{ request()->routeIs('purchase.*') ? 'show' : '' }}">
        <li>
            <a href="{{ route('purchase.orders.index') }}"
               class="nav-link {{ request()->routeIs('purchase.orders.*') ? 'active' : '' }}">
                <i class="bi bi-cart-plus"></i> Purchase Order
            </a>
        </li>
        <li>
            <a href="{{ route('purchase.receipts.index') }}"
               class="nav-link {{ request()->routeIs('purchase.receipts.*') ? 'active' : '' }}">
                <i class="bi bi-receipt"></i> Penerimaan Barang
            </a>
        </li>
        </ul></li>
        @endif

        {{-- Penjualan (owner & kasir) --}}
        @if(auth()->user()->isOwner() || auth()->user()->isKasir())
        <li>
            <button class="nav-section nav-section-toggle" type="button" data-bs-toggle="collapse"
                    data-bs-target="#salesMenu" aria-controls="salesMenu"
                    aria-expanded="{{ request()->routeIs('sale.*') ? 'true' : 'false' }}">
                <span class="section-label"><i class="bi bi-cash-stack"></i>Penjualan</span><i class="bi bi-chevron-down"></i>
            </button>
        </li>
        <li class="list-unstyled"><ul id="salesMenu" class="nav-submenu collapse list-unstyled {{ request()->routeIs('sale.*') ? 'show' : '' }}">
        <li>
            <a href="{{ route('sale.sales.index') }}"
               class="nav-link {{ request()->routeIs('sale.sales.*') ? 'active' : '' }}">
                <i class="bi bi-cash-register"></i> Transaksi Penjualan
            </a>
        </li>
        </ul></li>
        @endif

        {{-- Inventori (owner & staf_gudang) --}}
        @if(auth()->user()->isOwner() || auth()->user()->isStafGudang())
        <li>
            <button class="nav-section nav-section-toggle" type="button" data-bs-toggle="collapse"
                    data-bs-target="#inventoryMenu" aria-controls="inventoryMenu"
                    aria-expanded="{{ request()->routeIs('inventory.*') ? 'true' : 'false' }}">
                <span class="section-label"><i class="bi bi-boxes"></i>Inventori</span><i class="bi bi-chevron-down"></i>
            </button>
        </li>
        <li class="list-unstyled"><ul id="inventoryMenu" class="nav-submenu collapse list-unstyled {{ request()->routeIs('inventory.*') ? 'show' : '' }}">
        <li>
            <a href="{{ route('inventory.stock.index') }}"
               class="nav-link {{ request()->routeIs('inventory.stock.*') ? 'active' : '' }}">
                <i class="bi bi-clipboard-data"></i> Monitoring Stok
            </a>
        </li>
        <li>
            <a href="{{ route('inventory.transfers.index') }}"
               class="nav-link {{ request()->routeIs('inventory.transfers.*') ? 'active' : '' }}">
                <i class="bi bi-arrow-left-right"></i> Transfer Stok
            </a>
        </li>
        <li>
            <a href="{{ route('inventory.opnames.index') }}"
               class="nav-link {{ request()->routeIs('inventory.opnames.*') ? 'active' : '' }}">
                <i class="bi bi-clipboard-check"></i> Stock Opname
            </a>
        </li>
        </ul></li>
        @endif

        {{-- Laporan (owner) --}}
        @if(auth()->user()->isOwner())
        <li>
            <button class="nav-section nav-section-toggle" type="button" data-bs-toggle="collapse"
                    data-bs-target="#reportMenu" aria-controls="reportMenu"
                    aria-expanded="{{ request()->routeIs('report.*') ? 'true' : 'false' }}">
                <span class="section-label"><i class="bi bi-bar-chart-line"></i>Laporan</span><i class="bi bi-chevron-down"></i>
            </button>
        </li>
        <li class="list-unstyled"><ul id="reportMenu" class="nav-submenu collapse list-unstyled {{ request()->routeIs('report.*') ? 'show' : '' }}">
        <li>
            <a href="{{ route('report.stock') }}"
               class="nav-link {{ request()->routeIs('report.stock*') ? 'active' : '' }}">
                <i class="bi bi-bar-chart"></i> Laporan Stok
            </a>
        </li>
        <li>
            <a href="{{ route('report.sale') }}"
               class="nav-link {{ request()->routeIs('report.sale*') ? 'active' : '' }}">
                <i class="bi bi-graph-up"></i> Laporan Penjualan
            </a>
        </li>
        <li>
            <a href="{{ route('report.gross-profit') }}"
               class="nav-link {{ request()->routeIs('report.gross-profit*') ? 'active' : '' }}">
                <i class="bi bi-currency-dollar"></i> Laba Kotor
            </a>
        </li>
        <li>
            <a href="{{ route('report.purchase') }}"
               class="nav-link {{ request()->routeIs('report.purchase*') ? 'active' : '' }}">
                <i class="bi bi-file-earmark-text"></i> Laporan Pembelian
            </a>
        </li>
        <li>
            <a href="{{ route('report.transfer') }}"
               class="nav-link {{ request()->routeIs('report.transfer*') ? 'active' : '' }}">
                <i class="bi bi-arrow-repeat"></i> Laporan Transfer
            </a>
        </li>
        </ul></li>
        @endif

        {{-- Sistem (owner) --}}
        @if(auth()->user()->isOwner())
        <li>
            <button class="nav-section nav-section-toggle" type="button" data-bs-toggle="collapse"
                    data-bs-target="#systemMenu" aria-controls="systemMenu"
                    aria-expanded="{{ request()->routeIs('database-backups.*') ? 'true' : 'false' }}">
                <span class="section-label"><i class="bi bi-gear"></i>Sistem</span><i class="bi bi-chevron-down"></i>
            </button>
        </li>
        <li class="list-unstyled"><ul id="systemMenu" class="nav-submenu collapse list-unstyled {{ request()->routeIs('database-backups.*') ? 'show' : '' }}">
        <li>
            <a href="{{ route('database-backups.index') }}"
               class="nav-link {{ request()->routeIs('database-backups.*') ? 'active' : '' }}">
                <i class="bi bi-database-check"></i> Backup Database
            </a>
        </li>
        </ul></li>
        @endif

    </ul>
</nav>
<div id="sidebarBackdrop" aria-hidden="true"></div>

{{-- MAIN CONTENT --}}
<div id="main-content">

    {{-- TOPBAR --}}
    <div id="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-sm btn-outline-secondary" id="sidebarToggle"
                    type="button" aria-controls="sidebar" aria-expanded="true" title="Ciutkan sidebar">
                <i class="bi bi-list"></i>
            </button>
            <h6 class="mb-0 fw-semibold text-muted">@yield('page-title', 'Dashboard')</h6>
        </div>
        <div class="d-flex align-items-center gap-3">
            {{-- Notifikasi stok --}}
            @if(auth()->user()->isOwner() || auth()->user()->isStafGudang())
            @php
                $topbarNotifications = app(\App\Services\NotificationService::class)->checkAllProducts();
                $notifCount = count($topbarNotifications);
            @endphp
            @if($notifCount > 0)
            <div class="notification-hover">
                <a href="{{ route('dashboard') }}#notifications"
                   class="btn btn-sm btn-outline-warning position-relative"
                   aria-label="{{ $notifCount }} notifikasi stok"
                   aria-describedby="notificationPreview">
                    <i class="bi bi-bell"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                        {{ $notifCount }}
                    </span>
                </a>
                <div class="notification-preview" id="notificationPreview" role="tooltip">
                    <div class="notification-preview-header d-flex justify-content-between align-items-center">
                        <span class="fw-semibold"><i class="bi bi-bell-fill text-warning me-2"></i>Perlu Perhatian</span>
                        <span class="badge bg-danger">{{ $notifCount }}</span>
                    </div>
                    <div class="notification-preview-list">
                        @foreach(array_slice($topbarNotifications, 0, 5) as $notif)
                        <div class="notification-preview-item">
                            <div class="d-flex justify-content-between gap-2 mb-1">
                                <div class="fw-semibold text-dark text-truncate">{{ $notif['product_name'] }}</div>
                                <span class="badge bg-{{ $notif['type'] === 'order' ? 'danger' : 'warning text-dark' }} align-self-start">
                                    {{ $notif['type'] === 'order' ? 'Order' : 'Transfer' }}
                                </span>
                            </div>
                            <div class="small text-muted mb-1"><i class="bi bi-geo-alt me-1"></i>{{ $notif['location_name'] }}</div>
                            <div class="small text-dark">{{ $notif['message'] }}</div>
                        </div>
                        @endforeach
                    </div>
                    <a href="{{ route('dashboard') }}#notifications" class="notification-preview-footer text-decoration-none">
                        Lihat semua notifikasi <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
            @endif
            @endif

            {{-- User dropdown --}}
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="bi bi-person-circle me-1"></i>
                    {{ auth()->user()->name }}
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <span class="dropdown-item-text text-muted small">
                            @php
                                $roleLabels = ['owner' => 'Owner', 'kasir' => 'Kasir', 'staf_gudang' => 'Staf Gudang'];
                            @endphp
                            {{ $roleLabels[auth()->user()->role] ?? auth()->user()->role }}
                        </span>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="dropdown-item text-danger">
                                <i class="bi bi-box-arrow-right me-1"></i> Logout
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    {{-- PAGE CONTENT --}}
    <div class="page-content">

        {{-- Flash messages --}}
        @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        @endif

        @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-circle me-2"></i>{{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        @endif

        @yield('content')
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');
    const desktopMedia = window.matchMedia('(min-width: 769px)');

    function updateSidebarToggle() {
        const collapsed = desktopMedia.matches
            ? document.body.classList.contains('sidebar-collapsed')
            : !sidebar.classList.contains('show');

        sidebarToggle?.setAttribute('aria-expanded', String(!collapsed));
        sidebarToggle?.setAttribute('title', collapsed ? 'Buka sidebar' : 'Ciutkan sidebar');
        sidebarToggle?.querySelector('i')?.classList.toggle('bi-list', collapsed || !desktopMedia.matches);
        sidebarToggle?.querySelector('i')?.classList.toggle('bi-layout-sidebar-inset', !collapsed && desktopMedia.matches);
    }

    function closeMobileSidebar() {
        sidebar.classList.remove('show');
        sidebarBackdrop.classList.remove('show');
        updateSidebarToggle();
    }

    if (desktopMedia.matches && localStorage.getItem('sidebar-collapsed') === 'true') {
        document.body.classList.add('sidebar-collapsed');
    }

    sidebarToggle?.addEventListener('click', () => {
        if (desktopMedia.matches) {
            document.body.classList.toggle('sidebar-collapsed');
            localStorage.setItem('sidebar-collapsed', document.body.classList.contains('sidebar-collapsed'));
        } else {
            sidebar.classList.toggle('show');
            sidebarBackdrop.classList.toggle('show', sidebar.classList.contains('show'));
        }
        updateSidebarToggle();
    });

    sidebarBackdrop?.addEventListener('click', closeMobileSidebar);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !desktopMedia.matches) closeMobileSidebar();
    });

    document.addEventListener('click', (event) => {
        const printLink = event.target.closest('.js-print-popup');
        if (!printLink) return;

        event.preventDefault();
        const popup = window.open(
            printLink.href,
            'printNota',
            'width=420,height=650,menubar=no,toolbar=no,location=no,status=no,scrollbars=yes,resizable=yes'
        );

        if (popup) {
            popup.focus();
        } else {
            alert('Popup cetak diblokir browser. Izinkan popup untuk mencetak nota.');
        }
    });

    desktopMedia.addEventListener('change', () => {
        closeMobileSidebar();
        document.body.classList.toggle(
            'sidebar-collapsed',
            desktopMedia.matches && localStorage.getItem('sidebar-collapsed') === 'true'
        );
        updateSidebarToggle();
    });

    updateSidebarToggle();

    function normalizeCurrencyValue(value) {
        return String(value ?? '').replace(/[^\d]/g, '');
    }

    function formatCurrencyValue(value) {
        const digits = normalizeCurrencyValue(value);
        return digits ? new Intl.NumberFormat('id-ID').format(Number(digits)) : '';
    }

    function initializeCurrencyInputs(container = document) {
        container.querySelectorAll('.js-currency-input').forEach((input) => {
            input.value = formatCurrencyValue(input.value);

            input.addEventListener('input', function() {
                const cursorAtEnd = this.selectionStart === this.value.length;
                this.value = formatCurrencyValue(this.value);
                if (cursorAtEnd) {
                    this.setSelectionRange(this.value.length, this.value.length);
                }
            });
        });
    }

    document.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', () => {
            form.querySelectorAll('.js-currency-input').forEach((input) => {
                input.value = normalizeCurrencyValue(input.value);
            });
        });
    });

    initializeCurrencyInputs();
</script>
@stack('scripts')
</body>
</html>
