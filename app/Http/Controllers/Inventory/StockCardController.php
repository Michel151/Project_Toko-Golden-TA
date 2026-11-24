<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockTransfer;
use App\Models\User;
use App\Exports\ReportExport;
use App\Services\StockCardPurchaseHistory;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class StockCardController extends Controller
{
    public function __construct(private StockCardPurchaseHistory $purchaseHistory) {}

    // Menampilkan kartu stok beserta link dokumen yang boleh dibuka pengguna.
    public function show(Request $request, Product $product)
    {
        $data = $this->cardData($request, $product);
        $this->addReferenceLinks($data['movements']->getCollection(), $request->user());

        return view('inventory.stock-card.show', $data);
    }

    // Memasang link setelah memeriksa keberadaan dokumen dan izin pengguna.
    private function addReferenceLinks(Collection $rows, User $user): void
    {
        $routes = [
            PurchaseReceipt::class => 'purchase.receipts.show',
            PurchaseReturn::class => 'purchase.returns.show',
            Sale::class => 'sale.sales.show',
            StockTransfer::class => 'inventory.transfers.show',
            StockOpname::class => 'inventory.opnames.show',
        ];

        $groups = $rows->filter(fn ($row) => $row->reference_type && $row->reference_id && $row->reference_number)
            ->groupBy(fn ($row) => Relation::getMorphedModel($row->reference_type) ?? $row->reference_type);

        foreach ($groups as $model => $movements) {
            $route = $routes[$model] ?? null;
            if (! $route || ! $user->canAccessRoute($route)) {
                continue;
            }

            $columns = $model === Sale::class ? ['id', 'created_by'] : ['id'];
            $documents = $model::query()->whereKey($movements->pluck('reference_id')->unique()->all())->get($columns)->keyBy('id');

            foreach ($movements as $movement) {
                $document = $documents->get($movement->reference_id);
                if ($document && (! ($document instanceof Sale) || $document->isAccessibleBy($user))) {
                    $movement->card_reference_url = route($route, $document);
                }
            }
        }
    }

    // Membuat PDF riwayat sesuai filter tanpa batas 25 baris.
    public function pdf(Request $request, Product $product)
    {
        $data = $this->cardData($request, $product, false);
        $pdf = Pdf::loadView('inventory.stock-card.pdf', $data)->setPaper('a4', 'landscape');
        return $pdf->stream('Kartu-Stok-' . str($product->name)->slug() . '-' . date('Y-m-d') . '.pdf');
    }

    // Mengunduh riwayat sesuai filter dalam file Excel.
    public function excel(Request $request, Product $product)
    {
        $data = $this->cardData($request, $product, false);
        // Mengubah setiap mutasi menjadi delapan kolom Excel.
        $rows = $data['movements']->map(function ($movement) use ($product) {
            $quantity = (float) $movement->quantity;
            return [$movement->movement_date->format('d/m/Y'), $movement->reference_number ?? '-',
                $movement->card_type_label.($movement->notes ? ' - '.$movement->notes : ''), $movement->location?->name ?? '-', $movement->createdBy->name ?? 'Sistem',
                $quantity > 0 ? $product->displayStock($quantity) : '-',
                $quantity < 0 ? $product->displayStock(abs($quantity)) : '-',
                $product->displayStock((float) $movement->card_balance)];
        })->all();
        if (! $rows) $rows[] = ['Tidak ada mutasi sesuai filter.', null, null, null, null, null, null, null];
        // Total memakai hasil perhitungan saldo, bukan penjumlahan teks satuan di Excel.
        $rows[] = ['TOTAL', null, 'Saldo Akhir', null, null, $product->displayStock($data['totalIn']),
            $product->displayStock($data['totalOut']), $product->displayStock($data['closingBalance'])];
        $location = $data['locationId'] ? $data['locations']->firstWhere('id', $data['locationId'])?->name : 'Semua Lokasi';
        $metadata = ['Barang: '.$product->name, 'Kategori: '.($product->category?->name ?? '-'), 'Lokasi: '.$location,
            'Saldo Awal: '.$product->displayStock($data['openingBalance']), 'Barang Masuk: '.$product->displayStock($data['totalIn']),
            'Barang Keluar: '.$product->displayStock($data['totalOut']), 'Saldo Akhir: '.$product->displayStock($data['closingBalance'])];
        if ($request->filled('movement_type') || $request->filled('direction')) {
            $metadata[] = 'Filter transaksi: '.($data['movementTypes'][$request->movement_type] ?? 'Semua Jenis');
            $metadata[] = 'Arah: '.match ($request->direction) { 'in' => 'Masuk', 'out' => 'Keluar', default => 'Masuk & Keluar' };
            $metadata[] = 'Ringkasan dan saldo mencakup seluruh transaksi pada lokasi dan periode terpilih.';
        }
        // Baris TOTAL sudah disiapkan, sehingga total otomatis dinonaktifkan.
        return Excel::download(new ReportExport('Kartu Stok Barang',
            ['Tanggal','Referensi','Keterangan','Lokasi','Petugas','Masuk','Keluar','Saldo'], $rows, [], [6,7,8], 'Kartu Stok', $metadata,
            showGridlines: true, reportPeriod: 'Periode: '.$data['from'].' s.d. '.$data['to'], includeTotal: false),
            'Kartu-Stok-'.str($product->name)->slug().'-'.$data['from'].'-sd-'.$data['to'].'.xlsx');
    }

    // Menyiapkan filter, riwayat, dan saldo untuk halaman kartu stok maupun ekspor.
    private function cardData(Request $request, Product $product, bool $paginate = true): array
    {
        $movementTypes = [
            'purchase' => 'Penerimaan',
            'return_dispatch' => 'Penyerahan Retur',
            'sale' => 'Penjualan',
            'sale_edit_restore' => 'Koreksi Penjualan',
            'sale_cancelled' => 'Pembatalan Penjualan',
            'transfer_in' => 'Transfer Masuk',
            'transfer_out' => 'Transfer Keluar',
            'opname_adjustment' => 'Stock Opname',
            'initial_import' => 'Stok Awal Impor',
        ];
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'location_id' => 'nullable|exists:locations,id',
            'movement_type' => ['nullable', 'string', Rule::in(array_keys($movementTypes))],
            'direction' => 'nullable|in:in,out',
        ]);
        $product->load([
            'buyUnit',
            'retailUnit',
            'category',
            'stocks',
            'productLocations.location',
            'productUnits.unit',
            'stockBatches.location',
            'stockBatches.purchaseReceiptItem.purchaseReceipt.purchaseOrder',
        ]);
        $from = $request->date('from')?->toDateString() ?? now()->startOfMonth()->toDateString();
        $to = $request->date('to')?->toDateString() ?? now()->toDateString();
        $locationId = $request->filled('location_id') ? (int) $request->location_id : null;

        // Mengambil seluruh mutasi lokasi dan periode untuk menghitung saldo.
        $periodMovements = StockMovement::with(['location', 'createdBy'])
            ->where('product_id', $product->id)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->whereDate('movement_date', '>=', $from)
            ->whereDate('movement_date', '<=', $to)
            ->orderBy('movement_date')->orderBy('id')->get();
        // Mulai dari stok fisik saat ini, lalu keluarkan perubahan setelah akhir periode.
        $currentBalance = $locationId
            ? (float) ($product->stocks->firstWhere('location_id', $locationId)?->quantity ?? 0)
            : (float) $product->stocks->sum('quantity');
        $movementsAfterPeriod = (float) StockMovement::where('product_id', $product->id)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->whereDate('movement_date', '>', $to)->sum('quantity');
        $closingBalance = $currentBalance - $movementsAfterPeriod;
        $netMovement = (float) $periodMovements->sum('quantity');
        // Saldo awal adalah saldo akhir dikurangi perubahan selama periode.
        $openingBalance = $closingBalance - $netMovement;
        $totalIn = (float) $periodMovements->where('quantity', '>', 0)->sum('quantity');
        $totalOut = abs((float) $periodMovements->where('quantity', '<', 0)->sum('quantity'));

        // Melengkapi mutasi dengan informasi pemeriksaan penerimaan dan penyerahan retur.
        $purchaseNotes = $this->purchaseHistory->rows($product, $periodMovements, $from, $to, $locationId);
        $cardRows = $periodMovements->toBase()->concat($purchaseNotes)->sortBy([
            fn ($a, $b) => $a->movement_date->timestamp <=> $b->movement_date->timestamp,
            fn ($a, $b) => $a->created_at?->timestamp <=> $b->created_at?->timestamp,
            fn ($a, $b) => $b->exists <=> $a->exists,
            fn ($a, $b) => $a->id <=> $b->id,
        ]);
        // Menghitung saldo dari baris paling awal; catatan nol tidak mengubah saldo.
        $runningBalance = $openingBalance;
        foreach ($cardRows as $movement) {
            $runningBalance += (float) $movement->quantity;
            $movement->card_balance = $runningBalance;
        }

        // Filter jenis dan arah menyaring baris setelah seluruh saldo dihitung.
        $movements = $cardRows
            ->when($request->filled('movement_type'), fn ($rows) => $rows->where('movement_type', $request->movement_type))
            ->when($request->direction === 'in', fn ($rows) => $rows->where('quantity', '>', 0))
            ->when($request->direction === 'out', fn ($rows) => $rows->where('quantity', '<', 0))
            ->values();
        // Browser menampilkan 25 baris terbaru; ekspor mengambil semua baris.
        if ($paginate) {
            $page = LengthAwarePaginator::resolveCurrentPage();
            $movements = (new LengthAwarePaginator($movements->reverse()->forPage($page, 25)->values(),
                $movements->count(), 25, $page, ['path' => $request->url()]))->withQueryString();
        }

        return compact(
            'product', 'movements', 'from', 'to', 'locationId', 'openingBalance',
            'closingBalance', 'totalIn', 'totalOut', 'movementTypes'
        ) + [
            'locations' => Location::orderBy('name')->get(),
        ];
    }
}
