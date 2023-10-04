<?php

namespace App\Http\Controllers\Pdf\Reportes;

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\CajaChartsHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Pdf\Reportes\ReportePdf;
use App\Models\User;
use Carbon\Carbon;

class ClientesPdf {

	function __construct($company_name, $periodo) {
		$reporte_pdf = new ReportePdf($company_name, 'Principales consumidores', $periodo);

		$reporte_pdf->print('Metodos de pago mas utilizados', 'Reunidos de los métodos de pago informados en las cuentas corrientes', 'metodos_de_pago', false, true);

		// $reporte_pdf->print('Clientes destacados', 'clientes_cantidad_ventas', true);
		$reporte_pdf->print('Clientes destacados', 'Segun las cantidad de ventas', 'clientes_cantidad_ventas', true);

		$reporte_pdf->print('Clientes destacados', 'Segun los montos gastados', 'clientes_monto_gastado', true, true);

		$reporte_pdf->_output();
        exit;
	}

}