<?php

namespace App\Http\Controllers\MasterData;

use App\Exports\ProductImportTemplateExport;
use App\Http\Controllers\Controller;
use App\Models\ProductImportRun;
use App\Services\ProductImportPreviewService;
use App\Services\ProductImportService;
use App\Services\ImportWorkbookReader;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class ProductImportController extends Controller
{
    // Buka halaman impor atau bangun kembali pratinjau yang masih berlaku milik pengguna.
    public function create(Request $request, ProductImportService $service)
    {
        $token = $request->query('preview') ?: old('token');
        if ($token) {
            $run = ProductImportRun::where('token', $token)->where('user_id', auth()->id())->where('status', 'pending')->first();
            if ($run && $run->payload && $run->expires_at->isFuture()) {
                $preview = $service->prepare($run->payload);
                $preview['products'] = array_slice($preview['products'], 0, 100);
                $preview['stocks'] = array_slice($preview['stocks'], 0, 200);
                if ($preview['error_count']) {
                    $run = null;
                }

                return view('master.products.import', ['preview' => $preview, 'run' => $run, 'canStore' => true]);
            }
            if ($request->query('preview')) {
                abort(404);
            }
        }

        return view('master.products.import');
    }

    // Unduh template Excel untuk identitas barang, satuan, dan stok awal.
    public function template()
    {
        return Excel::download(new ProductImportTemplateExport, 'template-import-barang.xlsx');
    }

    // Validasi unggahan, baca workbook, lalu siapkan pratinjau dan token untuk rencana yang lolos.
    public function preview(Request $request, ProductImportService $service)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:5120']]);
        $sheets = app(ImportWorkbookReader::class)->read($request->file('file'), 3, 5);

        // Old five-sheet files remain preview-only; supplier import is now separate.
        $preview = app(ProductImportPreviewService::class)->preview($sheets, auth()->user()->hasPermission('master.suppliers.view'));
        $canStore = count($sheets) === 3;
        $run = null;
        if ($canStore) {
            $plan = $service->prepare($sheets);
            $preview['errors'] = $plan['errors'];
            $preview['error_count'] = $plan['error_count'];
            if (! $plan['error_count']) {
                $run = $service->stage($sheets, auth()->id());
            }
        }

        return view('master.products.import', compact('preview', 'run', 'canStore'));
    }

    // Validasi token dan konfirmasi sebelum meminta service menyimpan rencana impor.
    public function store(Request $request, ProductImportService $service)
    {
        $data = $request->validate(['token' => 'required|uuid', 'confirmed' => 'required|accepted']);
        try {
            $result = $service->commit($data['token'], auth()->id());
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['file' => 'Impor lain sedang diproses. Tunggu sebentar, lalu konfirmasi kembali; belum ada data dari impor ini yang disimpan.']);
        }

        return redirect()->route('master.products.index')->with('success',
            "Impor multi satuan selesai: {$result['created']} barang baru, {$result['units']} satuan, {$result['stocks']} stok lokasi, {$result['batches']} rincian kedaluwarsa. Konfirmasi ulang tidak menggandakan data.");
    }
}
