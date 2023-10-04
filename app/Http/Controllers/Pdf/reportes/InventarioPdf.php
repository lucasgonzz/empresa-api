<?php

namespace App\Http\Controllers\Pdf\Reportes;

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\CajaChartsHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Pdf\Reportes\ReportePdf;
use App\Models\User;
use Carbon\Carbon;

class InventarioPdf {

	function __construct($company_name, $periodo) {
		$reporte_pdf = new ReportePdf($company_name, 'Reporte de Clientes', $periodo);

		$reporte_pdf->print('Articulos mas vendidos', null, 'article', true, false, 130);

		$reporte_pdf->print('Categorias mas vendidas', null, 'category', true);

		$reporte_pdf->print('Sub categorias mas vendidas', null, 'sub_category', true, false, 130);

		$reporte_pdf->_output();
        exit;
	}

}