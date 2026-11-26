<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\ReportExport;
use Maatwebsite\Excel\Facades\Excel;

class StockReportController extends Controller
{
    // Pilihan kode dan nama filter kedaluwarsa untuk halaman serta ekspor.
    private const EXPIRY_FILTERS = [
        'tracked' => 'Barang Berkedaluwarsa',
        'expired' => 'Sudah Kedaluwarsa',
        'expiry_today' => 'Kedaluwarsa Hari Ini',
        'expiry_urgent' => 'Segera Kedaluwarsa (1-30 Hari)',
        'expiring_soon' => 'Mendekati Kedaluwarsa (31-90 Hari)',
        'safe' => 'Aman dari Kedaluwarsa',
    ];

    // Menampilkan stok saat ini dan ringkasan seluruh produk sesuai filter.
    public function index(Request $request)
    {
        $filters = $request->validate(['per_page'=>'nullable|integer|in:25,50,100']);
        $locations  = Location::orderBy('name')->get();
        $categories = Category::orderBy('name')->get();

        $query = Product::with(['costBalance', 'buyUnit', 'retailUnit', 'category', 'stocks.location', 'stockBatches', 'productLocations.location', 'productUnits.unit']);

        $this->applyFilters($query, $request);

        $products = $query->orderBy('name')->orderBy('id')->get();
        $expiryFilters = self::EXPIRY_FILTERS;
        $expiryFilterLabel = $this->expiryFilterLabel($request);
        // Menghitung ringkasan sebelum produk dibagi per halaman.
        $stockSummary = ['products'=>$products->count(), 'value'=>0.0, 'low'=>0, 'empty'=>0];
        foreach ($products as $product) {
            $availability = $product->stockAvailability();
            $stockSummary['value'] += $product->inventoryValue();
            if ($availability['sellable'] <= 0) {
                $stockSummary['empty']++;
            } elseif ($availability['sellable'] <= (float) $product->min_stock_total) {
                $stockSummary['low']++;
            }
        }

        // Memotong daftar untuk halaman aktif; total nilai tetap mencakup seluruh hasil filter.
        $perPage = (int) ($filters['per_page'] ?? 25);
        $page = LengthAwarePaginator::resolveCurrentPage();
        $products = (new LengthAwarePaginator(
            $products->forPage($page, $perPage)->values(), $products->count(), $perPage, $page,
            ['path'=>LengthAwarePaginator::resolveCurrentPath()]
        ))->withQueryString();

        return view('report.stock', compact('products', 'locations', 'categories', 'expiryFilters', 'expiryFilterLabel', 'stockSummary'));
    }

    // Mengekspor seluruh produk sesuai filter menjadi PDF A4 mendatar.
    public function pdf(Request $request)
    {
        $locations = Location::orderBy('name')->get();
        $query = Product::with(['costBalance', 'buyUnit', 'retailUnit', 'category', 'stocks.location', 'stockBatches', 'productUnits.unit']);
        $this->applyFilters($query, $request);
        $products = $query->orderBy('name')->get();
        $reportTitle = $this->reportTitle($request);
        $expiryFilterLabel = $this->expiryFilterLabel($request);
        $reportMetadata = $this->exportMetadata($request);
        $pdf = Pdf::loadView('report.pdf.stock', compact('products', 'locations', 'reportTitle', 'expiryFilterLabel', 'reportMetadata'))
                  ->setPaper('a4', 'landscape');
        return $pdf->stream($this->fileName($request, 'pdf'));
    }

    // Mengekspor seluruh produk sesuai filter ke satu sheet Excel bernama Stok.
    public function excel(Request $request)
    {
        $locations = Location::orderBy('name')->get();
        $query = Product::with(['costBalance', 'buyUnit', 'retailUnit', 'category', 'stocks', 'stockBatches', 'productUnits.unit']);
        $this->applyFilters($query, $request);
        $products = $query->orderBy('name')->get();

        // Menambahkan kolom setiap lokasi dengan urutan yang sama seperti isi baris.
        $headings = ['Barang', 'Kategori', 'Satuan Dasar', ...$locations->pluck('name')->all(), 'Total Fisik', 'Layak Jual', 'Kedaluwarsa', 'Min. Stok', 'Nilai Stok', 'Status'];
        // Mengubah setiap produk menjadi baris; jumlah memakai teks satuan dan nilai stok tetap angka.
        $rows = $products->map(function ($product) use ($locations) {
            $total = (float) $product->stocks->sum('quantity');
            $sellable = $product->is_expirable
                ? (float) $product->stockBatches
                    ->filter(fn ($batch) => (float) $batch->quantity_remaining > 0 && (! $batch->expired_date || $batch->expired_date->gte(today())))
                    ->sum(fn ($batch) => (float) $batch->quantity_remaining)
                : $total;
            $expired = $product->is_expirable
                ? (float) $product->stockBatches
                    ->filter(fn ($batch) => (float) $batch->quantity_remaining > 0 && $batch->expired_date && $batch->expired_date->lt(today()))
                    ->sum(fn ($batch) => (float) $batch->quantity_remaining)
                : 0;
            $value = $product->inventoryValue();
            $locationStocks = $locations->map(fn ($location) => $product->stockAvailability($location->id)['sellable']);

            return [
                $product->name,
                $product->category->name ?? '-',
                $product->productUnits->firstWhere('is_base_unit', true)?->unit?->name
                    ?? ($product->can_retail ? ($product->retailUnit->name ?? $product->buyUnit->name) : $product->buyUnit->name),
                ...$locationStocks->map(fn ($quantity) => $product->displayStock($quantity))->all(),
                $product->displayStock($total),
                $product->displayStock($sellable),
                $product->displayStock($expired),
                $product->displayStock((float) $product->min_stock_total),
                $value,
                $sellable <= (float) $product->min_stock_total ? 'Rendah' : 'Aman',
            ];
        })->all();

        // Kolom nilai stok selalu kedua dari belakang dan akan dijumlahkan oleh ReportExport.
        $valueColumn = count($headings) - 1;
        return Excel::download(
            new ReportExport($this->reportTitle($request), $headings, $rows, [$valueColumn], [], 'Stok', $this->exportMetadata($request), showGridlines: true, reportPeriod: 'Posisi stok per '.now('Asia/Jakarta')->format('d/m/Y H:i').' WIB'),
            $this->fileName($request, 'xlsx')
        );
    }

    // Memilih produk berdasarkan kategori, lokasi, dan kondisi batch kedaluwarsa.
    private function applyFilters($query, Request $request): void
    {
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Lokasi hanya memilih produk; jumlah dan nilai yang ditampilkan tetap seluruh lokasi.
        if ($request->filled('location_id')) {
            $query->whereHas('stocks', fn ($q) => $q->where('location_id', $request->location_id));
        }

        // Filter aman menolak batch tersisa yang kedaluwarsa atau berada dalam batas 90 hari.
        if ($request->input('expiry_status') === 'safe') {
            $query->whereDoesntHave('stockBatches', fn ($q) => $q
                ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->location_id))
                ->where('quantity_remaining', '>', 0)
                ->whereDate('expired_date', '<=', today()->addDays(\App\Support\ExpiryStatus::WARNING_DAYS)));
        }

        // Memeriksa batch tersisa pada lokasi pilihan; filter aman juga memerlukan batch di atas 90 hari.
        match ($request->input('expiry_status')) {
            'tracked' => $query->where('is_expirable', true)
                ->whereHas('stockBatches', fn ($q) => $q
                    ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->location_id))
                    ->where('quantity_remaining', '>', 0)
                    ->whereNotNull('expired_date')),
            'expired' => $query->where('is_expirable', true)
                ->whereHas('stockBatches', fn ($q) => $q
                    ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->location_id))
                    ->where('quantity_remaining', '>', 0)
                    ->whereDate('expired_date', '<', today())),
            'expiry_today' => $query->where('is_expirable', true)
                ->whereHas('stockBatches', fn ($q) => $q
                    ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->location_id))
                    ->where('quantity_remaining', '>', 0)
                    ->whereDate('expired_date', today())),
            'expiry_urgent' => $query->where('is_expirable', true)
                ->whereHas('stockBatches', fn ($q) => $q
                    ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->location_id))
                    ->where('quantity_remaining', '>', 0)
                    ->whereBetween('expired_date', [today()->addDay(), today()->addDays(\App\Support\ExpiryStatus::URGENT_DAYS)])),
            'expiring_soon' => $query->where('is_expirable', true)
                ->whereHas('stockBatches', fn ($q) => $q
                    ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->location_id))
                    ->where('quantity_remaining', '>', 0)
                    ->whereBetween('expired_date', [today()->addDays(\App\Support\ExpiryStatus::URGENT_DAYS + 1), today()->addDays(\App\Support\ExpiryStatus::WARNING_DAYS)])),
            'safe' => $query->where('is_expirable', true)
                ->whereHas('stockBatches', fn ($q) => $q
                    ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->location_id))
                    ->where('quantity_remaining', '>', 0)
                    ->whereDate('expired_date', '>', today()->addDays(\App\Support\ExpiryStatus::WARNING_DAYS))),
            default => null,
        };
    }

    // Menjelaskan filter dan dasar penilaian agar cakupan file ekspor mudah dipahami.
    private function exportMetadata(Request $request): array
    {
        return array_values(array_filter([
            'Nilai: moving average otomatis dari stok nol (termasuk reservasi); stok lama memakai metode sebelumnya sampai habis.',
            $request->filled('category_id') ? 'Kategori: '.(Category::find($request->category_id)?->name ?? '-') : null,
            $request->filled('location_id') ? 'Seleksi barang di lokasi: '.(Location::find($request->location_id)?->name ?? '-').' (jumlah stok seluruh lokasi)' : 'Cakupan: seluruh lokasi',
            $this->expiryFilterLabel($request) ? 'Kedaluwarsa: '.$this->expiryFilterLabel($request) : null,
        ]));
    }

    // Mengambil nama filter kedaluwarsa atau null jika tidak dipilih atau tidak dikenali.
    private function expiryFilterLabel(Request $request): ?string
    {
        return self::EXPIRY_FILTERS[$request->input('expiry_status')] ?? null;
    }

    // Menggunakan judul laporan kedaluwarsa ketika filter kedaluwarsa dikenali.
    private function reportTitle(Request $request): string
    {
        return $this->expiryFilterLabel($request)
            ? 'Laporan Kedaluwarsa Barang Toko Golden'
            : 'Laporan Stok Toko Golden';
    }

    // Membuat nama file dari jenis laporan, tanggal ekspor, dan ekstensi.
    private function fileName(Request $request, string $extension): string
    {
        $prefix = $this->expiryFilterLabel($request) ? 'Laporan-Kedaluwarsa-Barang' : 'Laporan-Stok';

        return $prefix . '-' . now()->format('Y-m-d') . '.' . $extension;
    }

}
