<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DatabaseBackupController;
use App\Http\Controllers\MasterData\CategoryController;
use App\Http\Controllers\MasterData\UnitController;
use App\Http\Controllers\MasterData\LocationController;
use App\Http\Controllers\MasterData\SupplierController;
use App\Http\Controllers\MasterData\UserController;
use App\Http\Controllers\MasterData\ProductController;
use App\Http\Controllers\Purchase\PurchaseOrderController;
use App\Http\Controllers\Purchase\PurchaseReceiptController;
use App\Http\Controllers\Sale\SaleController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Inventory\TransferController;
use App\Http\Controllers\Inventory\OpnameController;
use App\Http\Controllers\Inventory\StockMovementController;
use App\Http\Controllers\Inventory\StockCardController;
use App\Http\Controllers\Report\StockReportController;
use App\Http\Controllers\Report\PurchaseReportController;
use App\Http\Controllers\Report\SaleReportController;
use App\Http\Controllers\Report\TransferReportController;
use App\Http\Controllers\Report\GrossProfitReportController;

// ── Redirect root ─────────────────────────────────────────────────────────────
Route::get('/', fn() => redirect()->route('login'));

// ── Auth ──────────────────────────────────────────────────────────────────────
Route::get('/login',  [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);

// ── Protected routes ──────────────────────────────────────────────────────────
Route::middleware('auth')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/audit-logs', [AuditLogController::class, 'index'])
         ->middleware('role:owner')->name('audit-logs.index');

    Route::prefix('database-backups')->name('database-backups.')
        ->middleware('role:owner')->group(function () {
            Route::get('/', [DatabaseBackupController::class, 'index'])->name('index');
            Route::post('/', [DatabaseBackupController::class, 'store'])->name('store');
            Route::get('{filename}/download', [DatabaseBackupController::class, 'download'])->name('download');
            Route::post('{filename}/restore', [DatabaseBackupController::class, 'restore'])->name('restore');
            Route::delete('{filename}', [DatabaseBackupController::class, 'destroy'])->name('destroy');
        });

    // ── Master Data (owner only) ───────────────────────────────────────────────
    Route::prefix('master')->name('master.')->middleware('role:owner')->group(function () {
        Route::resource('categories', CategoryController::class)->except(['show']);
        Route::resource('units',      UnitController::class)->except(['show']);
        Route::resource('locations',  LocationController::class)->except(['show']);
        Route::resource('suppliers',  SupplierController::class);
        Route::resource('users',      UserController::class)->except(['show']);
        Route::get('products/{product}/label', [ProductController::class, 'printLabel'])
             ->name('products.label');
        Route::resource('products',   ProductController::class);
    });

    // ── Pembelian (owner only) ─────────────────────────────────────────────────
    Route::prefix('purchase')->name('purchase.')->middleware('role:owner')->group(function () {
        Route::get('orders/suppliers/{supplier}/products', [PurchaseOrderController::class, 'productsBySupplier'])
             ->name('orders.supplier-products');
        Route::resource('orders',   PurchaseOrderController::class);
        Route::get('orders/{order}/pdf', [PurchaseOrderController::class, 'generatePdf'])
             ->name('orders.pdf');

        Route::resource('receipts', PurchaseReceiptController::class);
    });

    // ── Penjualan (owner & kasir) ──────────────────────────────────────────────
    Route::prefix('sale')->name('sale.')->middleware('role:owner,kasir')->group(function () {
        Route::resource('sales', SaleController::class);
        Route::get('sales/{sale}/print', [SaleController::class, 'printNota'])
             ->name('sales.print');

        // AJAX: ambil data produk untuk form penjualan
        Route::get('product/{product}', [SaleController::class, 'getProductData'])
             ->name('product.data');
    });

    // ── Inventori (owner & staf_gudang) ───────────────────────────────────────
    Route::prefix('inventory')->name('inventory.')->middleware('role:owner,staf_gudang')->group(function () {
        Route::get('stock', [StockController::class, 'index'])->name('stock.index');
        Route::get('stock-card/{product}', [StockCardController::class, 'show'])->name('stock-card.show');
        Route::get('stock-card/{product}/pdf', [StockCardController::class, 'pdf'])->name('stock-card.pdf');

        Route::resource('transfers', TransferController::class);
        Route::get('opnames/movements', [StockMovementController::class, 'index'])
             ->name('opnames.movements');
        Route::resource('opnames',   OpnameController::class);
        Route::post('opnames/{opname}/complete', [OpnameController::class, 'complete'])
             ->name('opnames.complete');
    });

    // ── Laporan (owner only) ───────────────────────────────────────────────────
    Route::prefix('report')->name('report.')->middleware('role:owner')->group(function () {
        Route::get('stock',    [StockReportController::class,    'index'])->name('stock');
        Route::get('purchase', [PurchaseReportController::class, 'index'])->name('purchase');
        Route::get('sale',     [SaleReportController::class,     'index'])->name('sale');
        Route::get('transfer', [TransferReportController::class, 'index'])->name('transfer');
        Route::get('gross-profit', [GrossProfitReportController::class, 'index'])->name('gross-profit');

        // Export PDF
        Route::get('stock/pdf',    [StockReportController::class,    'pdf'])->name('stock.pdf');
        Route::get('stock/excel',  [StockReportController::class,    'excel'])->name('stock.excel');
        Route::get('purchase/pdf', [PurchaseReportController::class, 'pdf'])->name('purchase.pdf');
        Route::get('purchase/excel', [PurchaseReportController::class, 'excel'])->name('purchase.excel');
        Route::get('sale/pdf',     [SaleReportController::class,     'pdf'])->name('sale.pdf');
        Route::get('sale/excel',   [SaleReportController::class,     'excel'])->name('sale.excel');
        Route::get('transfer/pdf', [TransferReportController::class, 'pdf'])->name('transfer.pdf');
        Route::get('transfer/excel', [TransferReportController::class, 'excel'])->name('transfer.excel');
        Route::get('gross-profit/pdf', [GrossProfitReportController::class, 'pdf'])->name('gross-profit.pdf');
        Route::get('gross-profit/excel', [GrossProfitReportController::class, 'excel'])->name('gross-profit.excel');
    });
});
