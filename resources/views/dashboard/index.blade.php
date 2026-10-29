@extends('layouts.app')
@section('title', 'Dashboard Owner')
@section('page-title', 'Dashboard Owner')
@push('styles')
<style>
.dashboard-hero{background:var(--app-header);color:#fff;border-radius:8px}
.owner-section{background:#fff;border:1px solid var(--app-border);border-radius:8px;padding:1rem;min-width:0}
.owner-section h6{font-weight:700;margin:0}
.owner-heading{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:.75rem;margin-bottom:1rem}
.owner-metric .card-body{display:flex;gap:.75rem;align-items:flex-start}
.owner-metric .card-body>div{min-width:0;max-width:100%;overflow-wrap:anywhere}
.owner-icon{width:40px;height:40px;flex:none;display:grid;place-items:center;border-radius:8px;font-size:1.2rem}
.owner-value{font-size:1.2rem;font-weight:700;overflow-wrap:anywhere}
.owner-chart{height:260px;position:relative}
.owner-line{display:flex;justify-content:space-between;align-items:center;gap:.75rem;padding:.7rem 0;border-bottom:1px solid var(--app-border)}
.owner-line:last-child{border-bottom:0}.owner-line>div{min-width:0;overflow-wrap:anywhere}
.owner-action{display:grid;grid-template-columns:32px minmax(0,1fr) auto;gap:.65rem;align-items:center;color:inherit;text-decoration:none;padding:.65rem 0;border-bottom:1px solid var(--app-border)}
.owner-action:last-child{border-bottom:0}.owner-action:hover{background:#f3f7f4}
.owner-payment{height:154px;width:154px;position:relative;flex:none;margin:auto}
.owner-payment-center{position:absolute;inset:30%;display:flex;align-items:center;justify-content:center;flex-direction:column;pointer-events:none;font-size:.75rem}
.owner-swatch{width:10px;height:10px;border-radius:2px;display:inline-block;margin-right:.4rem}
.owner-empty{color:var(--app-muted);padding:1.2rem .25rem;text-align:center}
.owner-location-bar{height:5px;background:#eef2ec;margin-top:.5rem}.owner-location-bar span{display:block;height:100%;background:#1f7a5a}
.owner-stock-table{min-width:500px;font-size:.82rem}
.owner-critical-scroll{max-height:340px;overflow:auto}.owner-stock-table th{position:sticky;top:0;z-index:1;background:#eef2ec}
.owner-recent{max-height:340px;overflow:auto}
.owner-ranking-scroll [hidden]{display:none!important}
.owner-ranking-scroll{max-height:260px;overflow-y:auto;overscroll-behavior:contain;scrollbar-width:thin;padding-right:.4rem}
.owner-overview>div{min-width:0}
.owner-trend,.owner-attention{display:flex;flex-direction:column}
.owner-trend .owner-chart{flex:1;min-height:260px}
.owner-attention .owner-heading{margin-bottom:.65rem}
.owner-attention .dashboard-notification-summary{display:flex;flex-direction:column;min-height:0;flex:1}
.owner-attention .dashboard-notification-heading{align-items:center;flex:none;padding-bottom:.65rem;border-bottom:1px solid var(--app-border)}
.owner-attention .dashboard-notification-heading a{font-weight:600;text-decoration:none}
.owner-attention .dashboard-notification-list{min-height:0;overflow-y:auto;overscroll-behavior:contain;scrollbar-width:thin;padding-right:.4rem;max-height:300px}
.owner-attention .dashboard-notification-item{padding:.7rem .25rem!important}
.owner-attention .dashboard-notification-item:hover{background:#f3f7f4}
.owner-attention .dashboard-notification-item>div:first-child{display:block!important}
.owner-attention .dashboard-notification-item strong{display:block;overflow-wrap:anywhere;font-size:.84rem;line-height:1.4;margin-bottom:.35rem}
.owner-attention .dashboard-notification-item .badge{font-size:.65rem}
.owner-attention .dashboard-notification-item .small{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.5;font-size:.78rem}
.owner-operations{flex:none;border-top:1px solid var(--app-border);padding-top:.65rem;margin-top:.5rem}
.owner-operations .owner-action{grid-template-columns:24px minmax(0,1fr) auto;padding:.5rem 0;font-size:.82rem}
@media(min-width:1200px){.owner-overview>div{height:480px}.owner-attention .dashboard-notification-list{max-height:none}}
@media(max-width:575px){.owner-metric .card-body{flex-direction:column;padding:.85rem}.owner-value{font-size:1.05rem}.owner-section{padding:.85rem}}
</style>
@endpush
@section('content')
<div class="dashboard-hero p-3 mb-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div><div class="small text-white-50 mb-1">{{ now('Asia/Jakarta')->locale('id')->translatedFormat('l, d F Y') }}</div><h5 class="fw-bold mb-0">Selamat datang, {{ auth()->user()->name }}</h5></div>
    <div class="d-flex flex-wrap gap-2">
        @if(auth()->user()->canAccessRoute('sale.sales.create'))<a href="{{ route('sale.sales.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-circle me-1"></i>Penjualan</a>@endif
        @if(auth()->user()->canAccessRoute('purchase.orders.create'))<a href="{{ route('purchase.orders.create') }}" class="btn btn-sm btn-outline-light"><i class="bi bi-cart-plus me-1"></i>Buat PO</a>@endif
    </div>
</div>
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><div class="card owner-metric h-100"><div class="card-body"><div class="owner-icon bg-success bg-opacity-10 text-success"><i class="bi bi-cash-coin"></i></div><div><div class="small text-muted">Penjualan Lunas Hari Ini</div><div class="owner-value">Rp {{ number_format($salesToday,0,',','.') }}</div><div class="small text-muted">{{ $salesCount }} nota lunas</div>
        @if($dailyGrowth === null)<div class="small text-muted mt-1">Kemarin belum ada penjualan</div>@else<div class="small mt-1 text-{{ $dailyGrowth >= 0 ? 'success' : 'danger' }}">{{ $dailyGrowth > 0 ? '+' : '' }}{{ number_format($dailyGrowth,1,',','.') }}% dari kemarin</div>@endif
    </div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card owner-metric h-100"><div class="card-body"><div class="owner-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-graph-up-arrow"></i></div><div><div class="small text-muted">Penjualan Lunas Bulan Ini</div><div class="owner-value">Rp {{ number_format($salesMonth,0,',','.') }}</div><div class="small text-muted">{{ $salesMonthCount }} nota lunas</div>
        @if($monthlyGrowth === null)<div class="small text-muted mt-1">Periode pembanding belum ada penjualan</div>@else<div class="small mt-1 text-{{ $monthlyGrowth >= 0 ? 'success' : 'danger' }}">{{ $monthlyGrowth > 0 ? '+' : '' }}{{ number_format($monthlyGrowth,1,',','.') }}% dibanding tanggal setara bulan lalu</div>@endif
    </div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card owner-metric h-100"><div class="card-body"><div class="owner-icon bg-info bg-opacity-10 text-info"><i class="bi bi-calculator"></i></div><div><div class="small text-muted">Laba Kotor Bulan Ini</div><div class="owner-value">{{ ($missingCostCount || $salesWithoutItemsCount) ? 'Belum lengkap' : 'Rp '.number_format($grossProfit,0,',','.') }}</div><div class="small text-muted">{{ $salesWithoutItemsCount ? $salesWithoutItemsCount.' nota tanpa rincian item' : ($missingCostCount ? $missingCostCount.' item tanpa HPP positif' : 'Penjualan lunas dikurangi HPP tercatat') }}</div><div class="small text-muted mt-1">Belum dikurangi biaya operasional</div></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card owner-metric h-100"><div class="card-body"><div class="owner-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-hourglass-split"></i></div><div><div class="small text-muted">Nota Belum Dibayar</div><div class="owner-value">Rp {{ number_format($pendingSalesAmount,0,',','.') }}</div><a class="small text-decoration-none" href="{{ route('sale.sales.index',['status'=>'pending']) }}">{{ $pendingSalesCount }} nota menunggu pembayaran <i class="bi bi-arrow-right"></i></a></div></div></div></div>
</div>
<div class="row g-3 mb-3 owner-overview">
    <div class="col-xl-8"><section class="owner-section owner-trend h-100" aria-labelledby="trendHeading">
        <div class="owner-heading"><div><h6 id="trendHeading">Penjualan Lunas Harian</h6><div class="small text-muted">Total periode: <strong id="trendTotal"></strong></div></div><div class="btn-group btn-group-sm" role="group" aria-label="Periode penjualan"><button type="button" class="btn btn-outline-success active" data-trend-days="7" aria-pressed="true">7 hari</button><button type="button" class="btn btn-outline-success" data-trend-days="30" aria-pressed="false">30 hari</button></div></div>
        <div class="owner-chart"><canvas id="salesTrendChart" role="img" aria-label="Grafik penjualan lunas harian"></canvas></div>
        <div class="small text-muted mt-2">Rata-rata nota lunas bulan ini: <strong>Rp {{ number_format($averageTransaction,0,',','.') }}</strong></div>
    </section></div>
    <div class="col-xl-4"><section class="owner-section owner-attention h-100" id="notifications" aria-labelledby="actionHeading">
        <div class="owner-heading"><h6 id="actionHeading">Perlu Tindakan</h6><i class="bi bi-list-check text-muted"></i></div>
        @include('notifications.summary')
        <div class="owner-operations">
        <div class="small text-muted mb-1">Operasional lainnya</div>
        <a class="owner-action" href="{{ route('sale.sales.index',['status'=>'pending']) }}"><i class="bi bi-hourglass-split text-warning"></i><span>Nota belum dibayar<small class="d-block text-muted">{{ $pendingSalesCount }} nota</small></span><i class="bi bi-arrow-up-right"></i></a>
        <a class="owner-action" href="{{ route('purchase.orders.index') }}"><i class="bi bi-box-arrow-in-down text-primary"></i><span>Penerimaan belum lengkap<small class="d-block text-muted">{{ $pendingPO }} PO</small></span><i class="bi bi-arrow-up-right"></i></a>
        </div>
    </section></div>
</div>
<div class="row g-3 mb-3">
    <div class="col-lg-4"><section class="owner-section h-100" aria-labelledby="paymentHeading"><div class="owner-heading"><div><h6 id="paymentHeading">Pembayaran Bulan Ini</h6><div class="small text-muted">Berdasarkan nilai penjualan lunas</div></div></div>
        <div class="owner-payment"><canvas id="paymentChart" role="img" aria-label="Komposisi nominal penjualan lunas per metode pembayaran"></canvas><div class="owner-payment-center"><strong>{{ $salesMonthCount }}</strong><span>nota lunas</span></div></div>
        @foreach(['tunai'=>['Tunai','#1f7a5a'],'transfer'=>['Transfer','#3b82f6']] as $key=>[$label,$color])<div class="owner-line"><span><span class="owner-swatch" style="background:{{ $color }}"></span>{{ $label }}</span><div class="text-end"><strong>Rp {{ number_format($paymentMethods[$key] ?? 0,0,',','.') }}</strong><div class="small text-muted">{{ $salesMonth ? number_format(($paymentMethods[$key] ?? 0)/$salesMonth*100,1,',','.') : '0,0' }}%</div></div></div>@endforeach
        @if($salesMonthCount === 0)<div class="small text-muted mt-2">Belum ada penjualan lunas bulan ini.</div>@endif
    </section></div>
    <div class="col-lg-4"><section class="owner-section h-100" aria-labelledby="productHeading"><div class="owner-heading"><div><h6 id="productHeading">Produk Terlaris</h6><div class="small text-muted">Penjualan lunas 30 hari terakhir</div></div><div class="d-flex flex-wrap gap-2"><select id="rankingMode" class="form-select form-select-sm w-auto" aria-label="Urutan produk terlaris"><option value="revenue">Omzet</option><option value="quantity">Jumlah Kemasan</option></select><select id="rankingUnit" class="form-select form-select-sm w-auto" aria-label="Filter satuan kemasan" hidden><option value="">Semua satuan</option>@foreach($rankingUnits as $unit)<option value="{{ $unit['id'] }}">{{ $unit['name'] }}</option>@endforeach</select></div></div>
        <div data-ranking="revenue" class="owner-ranking-scroll" tabindex="0" role="region" aria-label="Produk terlaris berdasarkan omzet">
        @forelse($topProducts as $index=>$item)<div class="owner-line"><span class="small text-muted">{{ $index+1 }}</span><div class="flex-grow-1"><div class="fw-semibold">{{ $item->name }}</div><div class="small text-muted">Rp {{ number_format($item->revenue,0,',','.') }}</div></div></div>@empty<div class="owner-empty">Belum ada penjualan lunas.</div>@endforelse
        </div><div data-ranking="quantity" class="owner-ranking-scroll" tabindex="0" role="region" aria-label="Produk terlaris berdasarkan jumlah kemasan" hidden>@forelse($topQuantityProducts as $index=>$item)<div class="owner-line" data-package-unit="{{ $item->package_unit_id }}" @if($index >= 5) hidden @endif><span class="small text-muted" data-rank-number>{{ $index+1 }}</span><div class="flex-grow-1"><div class="fw-semibold">{{ $item->name }}</div><div class="small text-muted">{{ $item->display_quantity }}</div></div></div>@empty<div class="owner-empty">Belum ada penjualan bersatuan.</div>@endforelse</div>
    </section></div>
    <div class="col-lg-4"><section class="owner-section h-100" aria-labelledby="locationHeading"><div class="owner-heading"><div><h6 id="locationHeading">Persediaan per Lokasi</h6><div class="small text-muted">Nilai biaya tercatat; termasuk reservasi setelah moving average aktif</div></div></div><div class="owner-value mb-1">Rp {{ number_format($inventoryValue,0,',','.') }}</div><div class="small text-muted mb-2">{{ $totalProducts }} jenis barang aktif</div>
        @php $maxLocationValue=max(1,(float)($locationValues->max('value') ?? 0)); @endphp
        @forelse($locationValues as $location)<div class="py-2"><div class="d-flex flex-wrap justify-content-between gap-1"><a href="{{ route('inventory.stock.index',['location_id'=>$location->id]) }}" class="text-decoration-none">{{ $location->name }}</a><span class="small">Rp {{ number_format($location->value,0,',','.') }}</span></div><div class="owner-location-bar"><span style="width:{{ max(0,(float)$location->value)/$maxLocationValue*100 }}%"></span></div></div>@empty<div class="owner-empty">Belum ada lokasi.</div>@endforelse
    </section></div>
</div>
<div class="row g-3">
    <div class="col-xl-8"><section class="owner-section h-100" aria-labelledby="criticalHeading"><div class="owner-heading"><div><h6 id="criticalHeading">Stok Layak Jual Kritis</h6><div class="small text-muted">Barang aktif · stok kedaluwarsa tidak dihitung</div></div><a href="{{ route('inventory.stock.index') }}" class="small text-decoration-none">Monitoring Stok <i class="bi bi-arrow-right"></i></a></div>
        <div class="table-responsive owner-critical-scroll" tabindex="0" aria-label="Daftar stok layak jual kritis"><table class="table table-hover align-middle owner-stock-table mb-0"><thead><tr><th>Barang</th><th class="text-end">Layak Jual</th><th class="text-end">Minimum</th><th></th></tr></thead><tbody>
        @forelse($lowStockProducts as $product)<tr><td><div class="fw-semibold">{{ $product->name }}</div><div class="small text-muted">{{ $product->category?->name ?? '-' }}</div></td><td class="text-end fw-semibold text-danger">{{ $product->displayStock($sellableStock[$product->id]) }}</td><td class="text-end text-muted">{{ $product->displayStock((float)$product->min_stock_total) }}</td><td class="text-end"><a href="{{ route('inventory.stock.index',['search'=>$product->name]) }}" class="btn btn-sm btn-outline-secondary" title="Periksa stok {{ $product->name }}" aria-label="Periksa stok {{ $product->name }}"><i class="bi bi-arrow-up-right"></i></a></td></tr>@empty<tr><td colspan="4" class="owner-empty">Tidak ada stok kritis.</td></tr>@endforelse
        </tbody></table></div>
    </section></div>
    <div class="col-xl-4"><section class="owner-section h-100" aria-labelledby="recentHeading"><div class="owner-heading"><h6 id="recentHeading">Transaksi Terbaru</h6><a href="{{ route('sale.sales.index') }}" class="small text-decoration-none">Lihat Semua <i class="bi bi-arrow-right"></i></a></div><div class="owner-recent" tabindex="0" aria-label="Daftar transaksi terbaru">
        @forelse($recentSales as $sale)<div class="owner-line"><div><a class="fw-semibold text-decoration-none" href="{{ route('sale.sales.show',$sale) }}">{{ $sale->sale_number }}</a><div class="small text-muted">{{ $sale->customer_name ?: 'Pelanggan Umum' }}</div><div class="small text-muted">{{ $sale->sale_date->format('d/m/Y') }} · {{ $sale->transaction_time_label }} · {{ $sale->createdBy?->name ?? '-' }}</div></div><div class="text-end"><div class="fw-semibold">Rp {{ number_format($sale->total_amount,0,',','.') }}</div><span class="badge bg-{{ $sale->status==='paid' ? 'success' : ($sale->status==='pending' ? 'warning text-dark' : 'secondary') }}">{{ $sale->status==='paid' ? 'Lunas' : ($sale->status==='pending' ? 'Menunggu' : 'Dibatalkan') }}</span></div></div>@empty<div class="owner-empty">Belum ada transaksi.</div>@endforelse
    </div></section></div>
</div>
@endsection
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
(() => {
    const rankingMode=document.getElementById('rankingMode');
    const rankingUnit=document.getElementById('rankingUnit');
    const quantityList=document.querySelector('[data-ranking="quantity"]');
    const updateRanking=()=>{
        const quantity=rankingMode.value==='quantity';
        rankingUnit.hidden=!quantity;
        document.querySelectorAll('[data-ranking]').forEach(list=>list.hidden=list.dataset.ranking!==rankingMode.value);
        let rank=0;
        quantityList.querySelectorAll('[data-package-unit]').forEach(row=>{
            const matches=!rankingUnit.value || row.dataset.packageUnit===rankingUnit.value;
            row.hidden=!matches || rank>=5;
            if(!row.hidden) row.querySelector('[data-rank-number]').textContent=++rank;
        });
        quantityList.scrollTop=0;
    };
    rankingMode.addEventListener('change',updateRanking);
    rankingUnit.addEventListener('change',updateRanking);
    updateRanking();
    const currency=value=>'Rp '+new Intl.NumberFormat('id-ID').format(value);
    const rows=@json($salesChart);
    const controls=[...document.querySelectorAll('[data-trend-days]')];
    const periodRows=days=>rows.slice(-days);
    const updateTotal=days=>document.getElementById('trendTotal').textContent=currency(periodRows(days).reduce((sum,row)=>sum+row.total,0));
    updateTotal(7);
    if(typeof Chart==='undefined'){
        document.getElementById('salesTrendChart').replaceWith(Object.assign(document.createElement('p'),{className:'owner-empty',textContent:'Grafik belum dapat dimuat. Total periode tetap tersedia.'}));
        document.getElementById('paymentChart').hidden=true;
        controls.forEach(button=>button.addEventListener('click',()=>updateTotal(Number(button.dataset.trendDays))));
        return;
    }
    const initial=periodRows(7);
    const trend=new Chart(document.getElementById('salesTrendChart'),{
        type:'bar',data:{labels:initial.map(row=>row.label),datasets:[{data:initial.map(row=>row.total),backgroundColor:'#1f7a5a',hoverBackgroundColor:'#15573f',borderRadius:3,maxBarThickness:38}]},
        options:{responsive:true,maintainAspectRatio:false,animation:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:context=>currency(context.raw)}}},scales:{x:{grid:{display:false},ticks:{maxRotation:0,autoSkip:true,maxTicksLimit:10}},y:{beginAtZero:true,grid:{color:'#e8edeb'},ticks:{callback:value=>currency(value)}}}}
    });
    controls.forEach(button=>button.addEventListener('click',()=>{
        const days=Number(button.dataset.trendDays), selected=periodRows(days);
        controls.forEach(item=>{const active=item===button;item.classList.toggle('active',active);item.setAttribute('aria-pressed',String(active));});
        trend.data.labels=selected.map(row=>row.label);trend.data.datasets[0].data=selected.map(row=>row.total);trend.update();updateTotal(days);
    }));
    const amounts=[{{ (float)($paymentMethods['tunai'] ?? 0) }},{{ (float)($paymentMethods['transfer'] ?? 0) }}];
    const total=amounts.reduce((sum,value)=>sum+value,0);
    new Chart(document.getElementById('paymentChart'),{
        type:'doughnut',data:{labels:total ? ['Tunai','Transfer'] : ['Belum ada penjualan'],datasets:[{data:total ? amounts : [1],backgroundColor:total ? ['#1f7a5a','#3b82f6'] : ['#e8edeb'],borderWidth:0,hoverOffset:total ? 4 : 0}]},
        options:{responsive:true,maintainAspectRatio:false,animation:false,cutout:'72%',plugins:{legend:{display:false},tooltip:{enabled:total>0,callbacks:{label:context=>currency(context.raw)+' ('+new Intl.NumberFormat('id-ID',{minimumFractionDigits:1,maximumFractionDigits:1}).format(context.raw/total*100)+'%)'}}}}
    });
})();
</script>
@endpush
