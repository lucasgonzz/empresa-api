<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Pdf\Reportes\ClientesPdf;
use App\Http\Controllers\Pdf\Reportes\InventarioPdf;
use Illuminate\Http\Request;

class ReporteController extends Controller
{
    function inventario($company_name, $periodo) {
        $pdf = new InventarioPdf($company_name, $periodo);
    }

    function clientes($company_name, $periodo) {
        $pdf = new ClientesPdf($company_name, $periodo);
    }
}
