<?php

namespace App\Http\Controllers;

use App\Exports\ArticleSalesExport;
use App\Http\Controllers\Pdf\Reportes\ClientesPdf;
use App\Http\Controllers\Pdf\Reportes\InventarioPdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ReporteController extends Controller
{
    function inventario($company_name, $periodo) {
        $pdf = new InventarioPdf($company_name, $periodo);
    }

    function clientes($company_name, $periodo) {
        $pdf = new ClientesPdf($company_name, $periodo);
    }

    function excel_articulos($company_name, $mes) {
        return Excel::download(new ArticleSalesExport($company_name), 'cc-articulos-ventas-'.$mes.'.xlsx');
    }
}
