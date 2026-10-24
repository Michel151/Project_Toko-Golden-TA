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
            height: 100vh;
            height: 100dvh;
            min-height: 0;
            background: #1a2035;
            position: fixed;
            top: 0; left: 0;
            z-index: 1000;
            transition: width 0.3s, left 0.3s;
            overflow-y: auto;
            overflow-x: hidden;
            overscroll-behavior-y: contain;
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
        .bg-expired { background-color: #6f42c1 !important; color: #fff; }

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
            background: var(--app-header);
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
        .procurement-heading { background:var(--app-header);color:#fff;border-radius:14px;padding:1.15rem 1.3rem;box-shadow:0 4px 16px rgba(23,32,57,.14); }
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
    <style>
        :root {
            --app-bg: #f6f7f4;
            --app-surface: #ffffff;
            --app-surface-2: #fbfcfa;
            --app-border: #dfe5dc;
            --app-text: #1f2933;
            --app-muted: #687582;
            --app-ink: #243228;
            --app-header: #243228;
            --app-accent: #b98405;
            --app-accent-2: #1f7a5a;
            --app-accent-3: #2563a8;
        }
        body {
            background:
                linear-gradient(180deg, rgba(246,247,244,.98), rgba(239,242,236,.98));
            color: var(--app-text);
            font-family: Inter, "Segoe UI", system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
        }
        #sidebar {
            background: #202a24;
            box-shadow: 0 0 0 1px rgba(255,255,255,.04), 12px 0 30px rgba(24,34,29,.14);
        }
        #sidebar .sidebar-brand {
            background: #18211c;
            border-bottom-color: rgba(255,255,255,.08);
        }
        #sidebar .sidebar-brand span { color: #f1c45c; letter-spacing: .01em; }
        #sidebar .nav-link {
            border-radius: 7px;
            border-left: 0;
            color: #c4ccbf;
        }
        #sidebar .nav-link i { color: #8fa295; }
        #sidebar .nav-link:hover,
        #sidebar .nav-link.active {
            color: #fff;
            background: rgba(255,255,255,.08);
            border-left: 0;
        }
        #sidebar .nav-link.active {
            background: #f1c45c;
            color: #1c251f;
            box-shadow: none;
        }
        #sidebar .nav-link.active i { color: #1c251f; }
        #sidebar .nav-section { color: #9eaca3; letter-spacing: .08em; }
        #sidebar .nav-section-toggle[aria-expanded="true"] {
            color: #f1c45c;
            background: rgba(241,196,92,.08);
        }
        #sidebar .nav-submenu { border-left-color: rgba(241,196,92,.24); }
        #topbar {
            background: rgba(255,255,255,.94);
            border-bottom: 1px solid var(--app-border);
            box-shadow: 0 1px 0 rgba(31,41,51,.03);
            backdrop-filter: blur(10px);
        }
        .page-content { padding: 1.35rem; }
        .card,
        .table-card,
        .stat-card,
        .insight-card,
        .procurement-stat,
        .report-stat,
        .pos-items-card,
        .pos-checkout {
            border: 1px solid var(--app-border) !important;
            border-radius: 8px !important;
            box-shadow: 0 8px 22px rgba(31,41,51,.055) !important;
        }
        .card-header {
            border-bottom: 1px solid var(--app-border) !important;
            background: var(--app-surface) !important;
        }
        .btn { border-radius: 7px; font-weight: 600; }
        .btn-primary, .btn-success {
            --bs-btn-bg: var(--app-accent-2);
            --bs-btn-border-color: var(--app-accent-2);
            --bs-btn-hover-bg: #176448;
            --bs-btn-hover-border-color: #176448;
            --bs-btn-active-bg: #176448;
            --bs-btn-active-border-color: #176448;
            --bs-btn-disabled-bg: var(--app-accent-2);
            --bs-btn-disabled-border-color: var(--app-accent-2);
            --bs-btn-focus-shadow-rgb: 31, 122, 90;
            background: var(--app-accent-2);
            border-color: var(--app-accent-2);
        }
        .btn-primary:hover,
        .btn-primary:focus, .btn-success:hover, .btn-success:focus {
            background: #176448;
            border-color: #176448;
        }
        .btn-outline-primary {
            color: var(--app-accent-2);
            border-color: #94bba9;
        }
        .btn-outline-primary:hover {
            background: var(--app-accent-2);
            border-color: var(--app-accent-2);
        }
        .form-control,
        .form-select,
        .input-group-text {
            border-color: #d9e0d6;
            border-radius: 7px;
        }
        .form-control:focus,
        .form-select:focus {
            border-color: #80ad99;
            box-shadow: 0 0 0 .18rem rgba(31,122,90,.15);
        }
        .table thead th {
            background: #eef2ec;
            border-bottom: 1px solid #d7ded5;
            color: #58645f;
            letter-spacing: .04em;
        }
        .table-hover tbody tr:hover { background-color: #fbf7ea; }
        .alert { border-radius: 8px; border-width: 1px; }
        .badge { border-radius: 6px; font-weight: 700; }
        .page-content .master-heading,
        .page-content .procurement-heading,
        .page-content .report-heading,
        .page-content .pos-heading,
        .page-content .inventory-heading,
        .page-content .stock-heading,
        .page-content .stock-card-heading,
        .page-content .audit-heading,
        .page-content .backup-hero,
        .page-content .cashier-hero,
        .page-content .warehouse-hero {
            background: var(--app-header);
            color: #fff;
            border-radius: 8px;
            padding: 1rem 1.25rem;
            box-shadow: none;
            border: 1px solid #18211c;
        }
        .page-content .product-hero { background:var(--app-header);border:1px solid #18211c;border-radius:8px;box-shadow:none; }
        .page-content .btn-sm { min-height:32px; }
        .page-content .app-icon-action { display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;min-width:32px;padding:0!important;font-size:0;vertical-align:middle; }
        .page-content .app-icon-action i { display:grid;place-items:center;font-size:14px;line-height:1;margin:0!important; }
        .page-content .app-icon-action i::before { display:block;line-height:1; }
        .page-content .app-icon-action:has(.bi-eye) { color:var(--app-accent-2);border-color:#94bba9;background:transparent; }
        .page-content .app-icon-action:has(.bi-eye):hover { background:var(--app-accent-2);color:#fff; }
        .app-pagination .page-link { color:var(--app-accent-2);min-width:32px;min-height:32px;display:flex;align-items:center;justify-content:center; }
        .app-pagination .active .page-link { background:var(--app-accent-2);border-color:var(--app-accent-2);color:#fff; }
        .app-pagination .disabled .page-link { color:#8a948e; }
        .app-filter-field { min-width:140px; }
        .app-filter-field label { display:block;font-size:12px;font-weight:600;color:var(--app-muted);margin-bottom:4px; }
        .app-filter-field .form-control { width:100%; }
        .app-filter-bar { display:flex;flex-wrap:wrap;align-items:end;gap:12px;background:#fff;border-bottom:1px solid var(--app-border);padding:16px; }
        .app-filter-bar .app-filter-field { flex:0 1 180px;min-width:140px; }
        .app-filter-bar .app-filter-search { flex-basis:260px; }
        .app-filter-bar .form-control,.app-filter-bar .form-select { width:100%;min-height:32px; }
        .app-filter-actions { display:flex;gap:8px;align-items:center; }
        .app-field-required:not(:has(.text-danger))::after { content:' *';color:#b02a37; }
        .app-detail-tools { display:flex;flex-wrap:wrap;align-items:end;justify-content:space-between;gap:12px;padding:12px 16px;border-top:1px solid var(--app-border); }
        .app-detail-tools .app-filter-field { flex:0 1 320px;max-width:100%; }
        .app-detail-scroll { max-height:55vh;overflow:auto; }
        .app-detail-scroll thead th { position:sticky;top:0;z-index:2; }
        .app-detail-scroll tr[hidden] { display:none; }
        @media(max-width:767px) {
            .app-filter-bar { display:grid;grid-template-columns:repeat(2,minmax(0,1fr)); }
            .app-filter-bar .app-filter-field { min-width:0; }
            .app-filter-bar .app-filter-search,.app-filter-bar .app-filter-actions { grid-column:1/-1; }
            .stock-stat .card-body,.report-stat .card-body { display:flex!important;flex-direction:column;align-items:flex-start!important;justify-content:flex-start;gap:10px!important; }
            .stock-stat .card-body>div,.report-stat .card-body>div { min-width:0;max-width:100%;overflow-wrap:anywhere; }
            .stock-stat-icon,.stat-symbol { flex-shrink:0; }
            .app-mobile-records { display:block;width:100%;min-width:0!important;table-layout:fixed; }
            .app-mobile-records thead { display:none; }
            .app-mobile-records tbody { display:block; }
            .app-mobile-records tbody tr { display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 16px;padding:14px 16px;border-bottom:1px solid var(--app-border); }
            .app-mobile-records tbody td { display:block;min-width:0;padding:0!important;border:0;text-align:left!important;white-space:normal!important;overflow-wrap:anywhere; }
            .app-mobile-records tbody td:first-child,.app-mobile-records tbody td[colspan] { grid-column:1/-1; }
            .app-mobile-records tbody td[data-label]::before { content:attr(data-label);display:block;color:var(--app-muted);font-size:11px;font-weight:600;margin-bottom:3px; }
            .app-mobile-records tfoot,.app-mobile-records tfoot tr { display:block; }
            .app-mobile-records tfoot td { display:block;padding:6px 16px;text-align:left!important; }
            .app-mobile-records tfoot td:empty { display:none; }
        }
        @media(max-width:359px) { .app-filter-bar { grid-template-columns:minmax(0,1fr); } }
        .app-table-scroll { max-height:65vh;overflow:auto; }
        .app-table-scroll>table>thead th { position:sticky;top:0;z-index:2; }
        .app-document-meta { display:flex;flex-wrap:wrap;gap:8px 28px;font-size:13px;color:var(--app-muted); }
        .app-document-meta strong { color:var(--app-text);font-weight:600; }
        .report-toolbar form, .procurement-filter form { flex-wrap:wrap; }
        .report-toolbar, .procurement-filter, .audit-filter { background:var(--app-surface);border:1px solid var(--app-border);border-radius:8px;box-shadow:none;padding:12px 16px; }
        .report-toolbar label, .procurement-filter label, .audit-filter label { color:var(--app-muted);font-size:12px;font-weight:600;letter-spacing:0;text-transform:none;margin-bottom:4px; }
        .report-kpis .card-body { min-width:0;overflow-wrap:anywhere; }
        .report-kpi-value { font-size:1.2rem;font-variant-numeric:tabular-nums;margin:3px 0; }
        @media(max-width:575px){.report-kpi-value{font-size:1.05rem}.report-kpis .card-body{padding:.85rem}}
        .page-content .master-heading::after,
        .page-content .cashier-hero::after,
        .page-content .warehouse-hero::after { display: none; }
        .page-content .master-heading-icon,
        .page-content .stock-heading-icon,
        .page-content .procurement-heading .heading-icon,
        .page-content .report-heading .report-icon {
            background: rgba(241,196,92,.12);
            border-color: rgba(241,196,92,.24);
            color: #f1c45c;
            border-radius: 8px;
        }
        .page-content .master-heading .master-label { color: #f1c45c; }
        .page-content .master-heading p { color: #c4ccbf; }
        .page-content .pos-total {
            background: var(--app-header);
            color: #fff;
        }
        .page-content .pos-total #grandTotal { color: #f1c45c; }
        .page-content .catalog-tabs .nav-link.active,
        .page-content .transfer-catalog-tabs .nav-link.active,
        .page-content .supplier-catalog-tabs .nav-link.active,
        .page-content .product-tabs .nav-link.active,
        .page-content .stock-card-tabs .nav-link.active,
        .page-content .nav-tabs .nav-link.active,
        .page-content .nav-pills .nav-link.active {
            background: var(--app-accent-2);
            border-color: var(--app-accent-2);
            color: #fff;
        }
        .inventory-heading { margin-bottom: 1rem; }
        .inventory-heading h5 { margin: 0; font-weight: 700; }
        .page-content > .d-flex { flex-wrap: wrap; gap: .75rem; }
        .page-content > .d-flex > form { flex-wrap: wrap; }
        @media (max-width: 768px) {
            .page-content { padding: 1rem; }
            #topbar { padding: .75rem 1rem; flex-wrap: wrap; gap: .5rem; }
            .page-content > .d-flex > form { width: 100%; }
            .page-content > .d-flex > form > .form-control,
            .page-content > .d-flex > form > .form-select,
            .page-content > .d-flex > form > .input-group { width: 100% !important; }
            .master-heading, .procurement-heading, .report-heading { flex-wrap: wrap; }
        }
    </style>
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

        @include('layouts.permission-menu')
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
            @if(auth()->user()->hasPermission('inventory.stock.view'))
            @php
                $topbarNotifications = app(\App\Services\NotificationService::class)->checkAllProducts();
                $notifCount = count($topbarNotifications);
                $showTopbarNotificationPreview = ! request()->routeIs('inventory.stock.*');
            @endphp
            @if($notifCount > 0)
            <div class="notification-hover">
                <a href="{{ route('dashboard') }}#notifications"
                   class="btn btn-sm btn-outline-warning position-relative"
                   aria-label="{{ $notifCount }} notifikasi stok"
                   @if($showTopbarNotificationPreview) aria-describedby="notificationPreview" @endif>
                    <i class="bi bi-bell"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                        {{ $notifCount }}
                    </span>
                </a>
                @if($showTopbarNotificationPreview)
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
                                <div class="d-flex flex-wrap justify-content-end gap-1 align-self-start">
                                    @foreach($notif['badges'] as $badge)
                                    <span class="badge bg-{{ $badge['class'] }}">{{ $badge['label'] }}</span>
                                    @endforeach
                                </div>
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
                @endif
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

        @if($errors->any())
        <div class="alert alert-danger" role="alert" tabindex="-1" id="validationErrors">
            <div class="fw-semibold mb-1">@yield('validation-heading','Data belum dapat disimpan.')</div>
            <ul class="mb-0">
                @foreach($errors->all() as $message)
                <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        @yield('content')
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<div class="modal" id="appDialog" tabindex="-1" aria-labelledby="appDialogTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:440px"><div class="modal-content" style="border-radius:8px">
        <div class="modal-header"><div class="d-flex align-items-center gap-2"><i data-dialog-icon aria-hidden="true"></i><h5 class="modal-title fs-5" id="appDialogTitle"></h5></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
        <div class="modal-body"><p data-dialog-text class="text-muted mb-0"></p><div data-dialog-details class="border-top mt-3 pt-2" hidden></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-dialog-cancel data-bs-dismiss="modal">Batal</button><button type="button" class="btn btn-primary" data-dialog-confirm></button></div>
    </div></div>
</div>
<script src="{{ asset('js/app-dialogs.js') }}?v=1"></script>
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

    sidebar.querySelectorAll('.nav-section-toggle').forEach((button) => {
        button.setAttribute('title', button.querySelector('.section-label').textContent.trim());
        button.addEventListener('click', (event) => {
            if (!desktopMedia.matches || !document.body.classList.contains('sidebar-collapsed')) return;
            event.stopPropagation();
            document.body.classList.remove('sidebar-collapsed');
            localStorage.setItem('sidebar-collapsed', 'false');
            const menu = document.querySelector(button.getAttribute('data-bs-target'));
            bootstrap.Collapse.getOrCreateInstance(menu, { toggle: false }).show();
            updateSidebarToggle();
        });
    });
    sidebar.querySelectorAll('.nav-link').forEach((link) => {
        link.setAttribute('title', link.textContent.trim());
    });

    const iconLabels = {
        'bi-eye': 'Lihat detail', 'bi-pencil': 'Ubah', 'bi-trash': 'Hapus', 'bi-printer': 'Cetak Nota',
        'bi-search': 'Terapkan filter', 'bi-funnel': 'Terapkan filter',
        'bi-arrow-counterclockwise': 'Reset filter', 'bi-x': 'Reset filter',
        'bi-file-pdf': 'Cetak PDF', 'bi-file-earmark-excel': 'Unduh Excel',
    };
    function labelIconControls(root) {
        root.querySelectorAll('button, a.btn').forEach((control) => {
            const actionIcon = control.querySelector('.bi-eye,.bi-pencil,.bi-trash,.bi-printer');
            if (control.matches('.btn') && control.closest('td') && actionIcon) {
                control.classList.add('app-icon-action');
                const actionLabel = control.getAttribute('title') || control.textContent.trim() || Object.entries(iconLabels).find(([className]) => actionIcon.classList.contains(className))?.[1];
                if (actionLabel) { control.setAttribute('title',actionLabel);control.setAttribute('aria-label',actionLabel); }
            }
            if (control.textContent.trim()) return;
            const icon = control.querySelector('i');
            const label = control.getAttribute('title') || Object.entries(iconLabels)
                .find(([className]) => icon?.classList.contains(className))?.[1];
            if (label) {
                control.setAttribute('title', label);
                control.setAttribute('aria-label', label);
            }
        });
    }
    labelIconControls(document);
    new MutationObserver((changes) => {
        changes.forEach((change) => change.addedNodes.forEach((node) => {
            if (node.nodeType === Node.ELEMENT_NODE) labelIconControls(node);
        }));
    }).observe(document.querySelector('.page-content'), { childList: true, subtree: true });
    document.getElementById('validationErrors')?.focus();

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
            appDialog.notice({ title: 'Cetak Nota Terhalang', text: 'Popup cetak diblokir browser. Izinkan popup untuk mencetak nota.' });
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
<script src="{{ asset('js/app-presentation.js') }}"></script>
@stack('scripts')
@if(session('completed_sale_draft'))
<script>
(() => {
    const id = @json(session('completed_sale_draft'));
    const prefix = 'golden-sale-draft:v1:' + @json(auth()->id()) + ':';
    try {
        Object.keys(localStorage).filter(key => key.startsWith(prefix)).forEach(key => {
            if (JSON.parse(localStorage.getItem(key))?.id === id) localStorage.removeItem(key);
        });
    } catch (_) {}
})();
</script>
@endif
</body>
</html>
