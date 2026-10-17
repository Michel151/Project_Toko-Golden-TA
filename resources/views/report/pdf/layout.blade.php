<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 32pt 32pt 46pt; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; line-height: 1.4; color: #111111; }
        h1 { margin: 0; font-size: 19px; letter-spacing: 0; }
        h2 { margin: 3px 0 6px; font-size: 14px; font-weight: normal; }
        .report-header { border-bottom: 1.5px solid #111111; padding-bottom: 10px; margin-bottom: 14px; }
        .meta, .muted { color: #555555; font-size: 9px; }
        .summary, .cards { width: 100%; border-collapse: collapse; margin: 0 0 15px; }
        .summary td, .cards td { padding: 6px 10px 6px 0; vertical-align: top; }
        .label { font-size: 8px; color: #555555; }
        .value { font-size: 12px; font-weight: bold; margin-top: 3px; }
        .notice { border: 1px solid #777777; padding: 8px 10px; margin-bottom: 12px; font-size: 10px; }
        .data { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .data thead { display: table-header-group; }
        .data th { text-align: left; font-size: 9px; padding: 8px 5px; border-top: 1px solid #111111; border-bottom: 1px solid #111111; vertical-align: bottom; }
        .data td { padding: 7px 5px; border-bottom: .5px solid #dddddd; vertical-align: top; word-wrap: break-word; }
        .data tr { page-break-inside: avoid; }
        .data tfoot { display: table-row-group; }
        .data tfoot td { border-top: 1.5px solid #111111; border-bottom: 2px double #111111; font-weight: bold; padding-top: 9px; padding-bottom: 9px; }
        .data .right, .right { text-align: right; }
        .data .center, .center { text-align: center; }
        .compact { font-size: 10px; }
        .low, .safe, .danger { color: #111111; font-weight: bold; }
        .summary-line { margin-bottom: 10px; font-size: 10px; }
        .footer { position: fixed; bottom: -30px; left: 0; right: 0; border-top: .5px solid #999999; padding-top: 5px; font-size: 8px; color: #555555; }
        .footer .page { float: right; }
        .page-number:before { content: counter(page); }
    </style>
</head>
<body>
    <div class="footer"><span class="page">Halaman <span class="page-number"></span></span>Toko Golden | Dicetak {{ now('Asia/Jakarta')->format('d/m/Y H:i:s') }} WIB</div>
    <div class="report-header">
        <h1>Toko Golden</h1>
        <h2>@yield('report-title')</h2>
        <div class="meta">@yield('report-period')</div>
        @foreach($reportMetadata ?? [] as $line)<div class="meta">{{ $line }}</div>@endforeach
    </div>
    @yield('report-body')
</body>
</html>
