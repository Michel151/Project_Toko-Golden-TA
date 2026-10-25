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

// ── Redirect root ─────────────────────────────────────────────────────────────
Route::get('/', fn() => redirect()->route('login'));

// ── Auth ──────────────────────────────────────────────────────────────────────
Route::get('/login',  [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);

// ── Protected routes ──────────────────────────────────────────────────────────
Route::middleware(['auth', \App\Http\Middleware\EnsurePasswordIsCurrent::class])->group(function () {

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/account/password', [\App\Http\Controllers\AccountPasswordController::class, 'edit'])->name('account.password.edit');
    Route::put('/account/password', [\App\Http\Controllers\AccountPasswordController::class, 'update'])->middleware('throttle:6,1')->name('account.password.update');
    Route::middleware('role:owner')->prefix('inventory/costing')->name('inventory.costing.')->group(function () {
        Route::get('/', [\App\Http\Controllers\InventoryCostController::class, 'index'])->name('index');
        Route::get('/{product}', [\App\Http\Controllers\InventoryCostController::class, 'show'])->name('show');
    });

    Route::middleware('role:owner')->group(function () {
        Route::post('taxes/{tax}/confirm', [\App\Http\Controllers\MonthlyTaxController::class, 'confirm'])->name('taxes.confirm');
        Route::get('taxes/{tax}/proof', [\App\Http\Controllers\MonthlyTaxController::class, 'proof'])->name('taxes.proof');
        Route::resource('taxes', \App\Http\Controllers\MonthlyTaxController::class)->except('destroy');
        Route::post('expenses/{expense}/confirm', [\App\Http\Controllers\ExpenseController::class, 'confirm'])->name('expenses.confirm');
        Route::post('expenses/{expense}/pay', [\App\Http\Controllers\ExpenseController::class, 'pay'])->name('expenses.pay');
        Route::post('expenses/{expense}/void', [\App\Http\Controllers\ExpenseController::class, 'void'])->name('expenses.void');
        Route::get('expenses/{expense}/proof', [\App\Http\Controllers\ExpenseController::class, 'proof'])->name('expenses.proof');
        Route::resource('expenses', \App\Http\Controllers\ExpenseController::class)->except('destroy');
        Route::get('report/profit-loss', [\App\Http\Controllers\Report\ProfitLossReportController::class, 'index'])->name('report.profit-loss');
        Route::get('report/profit-loss/pdf', [\App\Http\Controllers\Report\ProfitLossReportController::class, 'pdf'])->name('report.profit-loss.pdf');
        Route::get('report/profit-loss/excel', [\App\Http\Controllers\Report\ProfitLossReportController::class, 'excel'])->name('report.profit-loss.excel');
    });

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/permissions', [\App\Http\Controllers\RolePermissionController::class, 'index'])
        ->middleware('role:owner')->name('permissions.index');
    Route::put('/permissions/{role}', [\App\Http\Controllers\RolePermissionController::class, 'update'])
        ->middleware('role:owner')->name('permissions.update');
    Route::get('/audit-logs', [AuditLogController::class, 'index'])
         ->middleware('role:owner')->name('audit-logs.index');

    Route::prefix('database-backups')->name('database-backups.')
        ->middleware('role:owner')->group(function () {
            Route::get('/', [DatabaseBackupController::class, 'index'])->name('index');
            Route::post('/', [DatabaseBackupController::class, 'store'])->name('store');
            Route::get('export-sql', [DatabaseBackupController::class, 'exportSql'])->name('export-sql');
            Route::post('import-sql', [DatabaseBackupController::class, 'importSql'])
                ->middleware('throttle:3,10')->name('import-sql');
            Route::get('sql/{filename}/download', [DatabaseBackupController::class, 'downloadSql'])->name('sql-download');
            Route::post('sql/{filename}/restore', [DatabaseBackupController::class, 'restoreSql'])
                ->middleware('throttle:3,10')->name('sql-restore');
            Route::delete('sql/{filename}', [DatabaseBackupController::class, 'destroySql'])->name('sql-destroy');
            Route::get('{filename}/download', [DatabaseBackupController::class, 'download'])->name('download');
            Route::post('restore-upload', [DatabaseBackupController::class, 'restoreUpload'])
                ->middleware('throttle:3,10')->name('restore-upload');
            Route::post('{filename}/restore', [DatabaseBackupController::class, 'restore'])
                ->middleware('throttle:3,10')->name('restore');
            Route::delete('{filename}', [DatabaseBackupController::class, 'destroy'])->name('destroy');
        });

    // ── Master Data (owner only) ───────────────────────────────────────────────
    Route::prefix('master')->name('master.')->group(function () {
        Route::resource('categories', CategoryController::class)->except(['show'])->middleware('permission:master.categories');
        Route::resource('units',      UnitController::class)->except(['show'])->middleware('permission:master.units');
        Route::resource('locations',  LocationController::class)->except(['show'])->middleware('permission:master.locations');
        Route::get('suppliers/import', [\App\Http\Controllers\MasterData\SupplierImportTrialController::class, 'create'])->middleware('permission:master.suppliers')->name('suppliers.import');
        Route::get('suppliers/import/template', [\App\Http\Controllers\MasterData\SupplierImportTrialController::class, 'template'])->middleware('permission:master.suppliers')->name('suppliers.import.template');
        Route::post('suppliers/import', [\App\Http\Controllers\MasterData\SupplierImportTrialController::class, 'preview'])->middleware('permission:master.suppliers')->name('suppliers.import.preview');
        Route::resource('suppliers',  SupplierController::class)->middleware('permission:master.suppliers');
        Route::get('users/password-reset-result', [UserController::class, 'resetResult'])->middleware('role:owner')->name('users.reset-password-result');
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware(['role:owner', 'throttle:10,1'])->name('users.reset-password');
        Route::resource('users',      UserController::class)->except(['show'])->middleware('role:owner');
        Route::get('products/import', [\App\Http\Controllers\MasterData\ProductImportTrialController::class, 'create'])->middleware('permission:master.products')->name('products.import');
        Route::get('products/import-trial', fn (\Illuminate\Http\Request $request) => redirect()->route('master.products.import', $request->only('preview')))->middleware('permission:master.products')->name('products.import-trial');
        Route::get('products/import-trial/template', fn () => redirect()->route('master.products.import.template'))->middleware('permission:master.products')->name('products.import-trial.template');
        Route::post('products/import-trial', [\App\Http\Controllers\MasterData\ProductImportTrialController::class, 'preview'])->middleware('permission:master.products')->name('products.import-trial.preview');
        Route::post('products/import-trial/store', [\App\Http\Controllers\MasterData\ProductImportTrialController::class, 'store'])->middleware('permission:master.products')->name('products.import-trial.store');
        Route::get('products/import/template', [\App\Http\Controllers\MasterData\ProductImportTrialController::class, 'template'])->middleware('permission:master.products')->name('products.import.template');
        Route::post('products/import', [\App\Http\Controllers\MasterData\ProductImportTrialController::class, 'preview'])->middleware('permission:master.products')->name('products.import.preview');
        Route::post('products/import/store', [\App\Http\Controllers\MasterData\ProductImportTrialController::class, 'store'])->middleware('permission:master.products')->name('products.import.store');
        Route::get('products/{product}/label', [ProductController::class, 'printLabel'])
             ->middleware('permission:master.products')->name('products.label');
        Route::resource('products',   ProductController::class)->middleware('permission:master.products');
    });

    // ── Pembelian (owner only) ─────────────────────────────────────────────────
    Route::prefix('purchase')->name('purchase.')->middleware('permission:purchase.orders')->group(function () {
        Route::get('orders/suppliers/{supplier}/products', [PurchaseOrderController::class, 'productsBySupplier'])
             ->name('orders.supplier-products');
        Route::resource('orders',   PurchaseOrderController::class);
        Route::get('orders/{order}/pdf', [PurchaseOrderController::class, 'generatePdf'])
             ->name('orders.pdf');
        Route::get('orders/{order}/excel', [PurchaseOrderController::class, 'generateExcel'])
             ->name('orders.excel');
    });

    Route::prefix('purchase')->name('purchase.')->middleware('permission:purchase.receipts')->group(function () {
        Route::get('receipts/orders/{order}/items', [PurchaseReceiptController::class, 'getOrderItems'])
             ->name('receipts.order-items');
        Route::resource('receipts', PurchaseReceiptController::class)->only(['index', 'create', 'store', 'show']);
    });

    // ── Penjualan (owner & kasir) ──────────────────────────────────────────────
    Route::prefix('purchase')->name('purchase.')->middleware('permission:purchase.returns')->group(function () {
        Route::post('returns/{return}/approve', [\App\Http\Controllers\Purchase\PurchaseReturnController::class, 'approve'])->name('returns.approve');
        Route::post('returns/{return}/reject', [\App\Http\Controllers\Purchase\PurchaseReturnController::class, 'reject'])->name('returns.reject');
        Route::post('returns/{return}/dispatch', [\App\Http\Controllers\Purchase\PurchaseReturnController::class, 'dispatch'])->name('returns.dispatch');
        Route::resource('returns', \App\Http\Controllers\Purchase\PurchaseReturnController::class)->only(['index', 'create', 'store', 'show']);
    });

    Route::get('sale/drafts', [\App\Http\Controllers\Sale\SaleDraftController::class, 'index'])->middleware('permission:sale.sales')->name('sale.drafts.index');
    Route::put('sale/drafts/{id}', [\App\Http\Controllers\Sale\SaleDraftController::class, 'update'])->middleware('permission:sale.sales')->name('sale.drafts.update');
    Route::prefix('sale')->name('sale.')->middleware('permission:sale.sales')->group(function () {
        Route::post('sales/{sale}/complete', [SaleController::class, 'complete'])->name('sales.complete');
        Route::post('sales/{sale}/cancel', [SaleController::class, 'cancel'])->name('sales.cancel');
        Route::resource('sales', SaleController::class);
        Route::get('sales/{sale}/print', [SaleController::class, 'printNota'])
             ->name('sales.print');

        // AJAX: ambil data produk untuk form penjualan
        Route::get('product/{product}', [SaleController::class, 'getProductData'])
             ->name('product.data');
    });

    // ── Inventori (owner & staf_gudang) ───────────────────────────────────────
    Route::prefix('inventory')->name('inventory.')->middleware('permission:inventory.stock')->group(function () {
        Route::get('stock', [StockController::class, 'index'])->name('stock.index');
        Route::get('stock-card/{product}', [StockCardController::class, 'show'])->name('stock-card.show');
        Route::get('stock-card/{product}/pdf', [StockCardController::class, 'pdf'])->name('stock-card.pdf');
    });

    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('transfers/availability', [TransferController::class, 'availability'])
             ->middleware('permission:inventory.transfers')->name('transfers.availability');
        Route::resource('transfers', TransferController::class)->middleware('permission:inventory.transfers');
        Route::get('opnames/movements', [StockMovementController::class, 'index'])
             ->middleware('permission:inventory.opnames')->name('opnames.movements');
        Route::resource('opnames',   OpnameController::class)->middleware('permission:inventory.opnames');
        Route::post('opnames/{opname}/complete', [OpnameController::class, 'complete'])
             ->middleware('permission:inventory.opnames')->name('opnames.complete');
    });

    // ── Laporan (owner only) ───────────────────────────────────────────────────
    Route::prefix('report')->name('report.')->group(function () {
        Route::get('stock',    [StockReportController::class,    'index'])->middleware('permission:report.stock')->name('stock');
        Route::get('purchase', [PurchaseReportController::class, 'index'])->middleware('permission:report.purchase')->name('purchase');
        Route::get('sale',     [SaleReportController::class,     'index'])->middleware('permission:report.sale')->name('sale');
        Route::get('transfer', [TransferReportController::class, 'index'])->middleware('permission:report.transfer')->name('transfer');

        // Export PDF
        Route::get('stock/pdf',    [StockReportController::class,    'pdf'])->middleware('permission:report.stock')->name('stock.pdf');
        Route::get('stock/excel',  [StockReportController::class,    'excel'])->middleware('permission:report.stock')->name('stock.excel');
        Route::get('purchase/pdf', [PurchaseReportController::class, 'pdf'])->middleware('permission:report.purchase')->name('purchase.pdf');
        Route::get('purchase/excel', [PurchaseReportController::class, 'excel'])->middleware('permission:report.purchase')->name('purchase.excel');
        Route::get('sale/pdf',     [SaleReportController::class,     'pdf'])->middleware('permission:report.sale')->name('sale.pdf');
        Route::get('sale/excel',   [SaleReportController::class,     'excel'])->middleware('permission:report.sale')->name('sale.excel');
        Route::get('transfer/pdf', [TransferReportController::class, 'pdf'])->middleware('permission:report.transfer')->name('transfer.pdf');
        Route::get('transfer/excel', [TransferReportController::class, 'excel'])->middleware('permission:report.transfer')->name('transfer.excel');
    });
});
