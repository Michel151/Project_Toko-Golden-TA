<?php

namespace App\Http\Controllers\MasterData;

use App\Exports\SupplierImportTrialTemplateExport;
use App\Http\Controllers\Controller;
use App\Services\SupplierImportTrialService;
use App\Services\TrialWorkbookReader;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class SupplierImportTrialController extends Controller
{
    public function create()
    {
        return view('master.suppliers.import');
    }

    public function template()
    {
        return Excel::download(new SupplierImportTrialTemplateExport, 'template-uji-import-supplier.xlsx');
    }

    public function preview(Request $request, TrialWorkbookReader $reader, SupplierImportTrialService $service)
    {
        $request->validate(['file'=>['required','file','mimes:xlsx','max:5120']]);
        $sheets=$reader->read($request->file('file'),1,3);
        return view('master.suppliers.import', ['preview'=>$service->preview($sheets, auth()->user()->hasPermission('master.products.view'))]);
    }
}
