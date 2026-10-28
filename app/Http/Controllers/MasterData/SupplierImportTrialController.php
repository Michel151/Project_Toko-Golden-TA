<?php

namespace App\Http\Controllers\MasterData;

use App\Exports\SupplierImportTrialTemplateExport;
use App\Http\Controllers\Controller;
use App\Services\SupplierImportService;
use App\Models\SupplierImportRun;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Validation\ValidationException;
use App\Services\TrialWorkbookReader;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class SupplierImportTrialController extends Controller
{
    public function create(Request $request, SupplierImportService $service)
    {
        $token = $request->query('preview') ?: old('token');
        if ($token) {
            $run = SupplierImportRun::where('token', $token)->where('user_id', auth()->id())->where('status', 'pending')->first();
            if ($run && $run->payload && $run->expires_at->isFuture()) {
                $preview = $service->display($service->prepare($run->payload['sheets'], auth()->user()->hasPermission('master.products.view')));
                if ($preview['error_count']) $run = null;
                return view('master.suppliers.import', compact('preview', 'run'));
            }
            if ($request->query('preview')) abort(404);
        }
        return view('master.suppliers.import');
    }

    public function template()
    {
        return Excel::download(new SupplierImportTrialTemplateExport, 'template-import-supplier.xlsx');
    }

    public function preview(Request $request, TrialWorkbookReader $reader, SupplierImportService $service)
    {
        $request->validate(['file'=>['required','file','mimes:xlsx','max:5120']]);
        $sheets=$reader->read($request->file('file'),1,3);
        $plan = $service->prepare($sheets, auth()->user()->hasPermission('master.products.view'));
        $run = $plan['error_count'] ? null : $service->stage($sheets, $plan, auth()->id());
        return view('master.suppliers.import', ['preview'=>$service->display($plan), 'run'=>$run]);
    }

    public function store(Request $request, SupplierImportService $service)
    {
        $data = $request->validate(['token'=>'required|uuid', 'confirmed'=>'required|accepted']);
        try {
            $result = $service->commit($data['token'], auth()->id(), auth()->user()->hasPermission('master.products.view'));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['file'=>'Impor supplier lain sedang diproses. Tunggu sebentar lalu konfirmasi kembali.']);
        }
        return redirect()->route('master.suppliers.index')->with('success',
            "Import selesai: {$result['suppliers_created']} supplier baru, {$result['suppliers_reused']} supplier terdaftar digunakan kembali, {$result['links_created']} hubungan baru, {$result['links_skipped']} hubungan lama dipertahankan. Stok dan HPP tidak diubah.");
    }
}
