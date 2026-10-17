<?php

namespace App\Http\Controllers\MasterData;

use App\Exports\ProductImportTemplateExport;
use App\Http\Controllers\Controller;
use App\Services\ProductImportService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class ProductImportController extends Controller
{
    public function create()
    {
        return view('master.products.import');
    }

    public function template()
    {
        return Excel::download(new ProductImportTemplateExport, 'template-import-barang.xlsx');
    }

    public function store(Request $request, ProductImportService $service)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
            'import_stock' => 'nullable|boolean',
        ]);
        try {
            $sheets = Excel::toArray(new \stdClass, $request->file('file'));
        } catch (\Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['file' => 'File Excel tidak dapat dibaca. Unggah file sesuai template tanpa proteksi sandi.']);
        }
        $result = $service->import($sheets[0] ?? [], $request->boolean('import_stock'));

        return redirect()->route('master.products.index')->with('success',
            "Impor selesai: {$result['created']} barang baru, {$result['existing']} baris barang terdaftar, {$result['stocks']} stok lokasi disesuaikan.");
    }
}
