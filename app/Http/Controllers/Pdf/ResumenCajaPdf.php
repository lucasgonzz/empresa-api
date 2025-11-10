<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\sale\SalePdfHelper;
use App\Http\Controllers\Pdf\AfipQrPdf;
use App\Models\AfipInformation;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class ResumenCajaPdf extends fpdf {

	function __construct($resumen_caja) {
		$this->line_height = 5;
		$this->user = UserHelper::getFullModel();
		$this->resumen_caja = $resumen_caja;

		$this->x_incial = 4;

		$this->ancho = $this->user->sale_ticket_width;
		$this->cell_ancho = $this->ancho - 8;

		parent::__construct('P', 'mm', [$this->ancho, $this->getPdfHeight()]);
		$this->SetAutoPageBreak(false);
		$this->b = 0;

		$this->AddPage();

		$this->titulo();


		$this->info_resumen();

		$this->info_ingresos();

		$this->info_saldos_finales();


        $this->Output();
        exit;
	}

	function titulo() {

		$this->SetFont('Arial', 'B', 12);


		// Sucursal
		$this->x = $this->x_incial;
		$this->SetFont('Arial', 'B', 14);
		$this->Cell($this->cell_ancho, 10, 'RESUMEN DE CAJA', 0, 1);
	}

	function info_resumen() {

		$this->y += 3;
		
		$this->SetFont('Arial', 'BI', 14);

		// Sucursal
		$this->x = $this->x_incial;
		$this->Cell($this->cell_ancho, 10, $this->resumen_caja->address->street, 0, 1);



		// Resumen
		$this->SetFont('Arial', 'B', 12);
		$this->x = $this->x_incial;
		$this->Cell($this->cell_ancho, 10, 'Resumen N° '.$this->resumen_caja->id, 1, 1);


		// Turno
		$this->SetFont('Arial', 'B', 12);
		$this->x = $this->x_incial;
		$this->Cell($this->cell_ancho, 10, 'Turno: '.$this->resumen_caja->turno_caja->name, 1, 1);



		// Turno
		$this->SetFont('Arial', 'B', 12);
		$this->x = $this->x_incial;
		$this->Cell($this->cell_ancho, 10, $this->resumen_caja->employee->name, 1, 1);


		$this->SetFont('Arial', '', 10);
		// Fecha
		$this->x = $this->x_incial;
		$this->Cell($this->cell_ancho, 10, $this->resumen_caja->created_at->format('d/m/Y H:i'), 0, 1);
	}




	function info_saldos_finales() {

		$this->y += 10;
		$this->SetFont('Arial', 'B', 14);
		
		$this->x = $this->x_incial;
		$this->Cell($this->cell_ancho, 10, 'Saldos finales', 1, 1);

		$this->SetFont('Arial', '', 12);
		
		foreach ($this->resumen_caja->cajas as $caja) {

			$this->x = $this->x_incial;
			
			$this->SetFont('Arial', 'B', 12);
			$this->Cell($this->cell_ancho /2, 10, $caja->name, 'TBL', 0, 'L');

			$this->SetFont('Arial', '', 12);
			$this->Cell($this->cell_ancho /2, 10, Numbers::price($caja->pivot->saldo_cierre, true), 'TBR', 1, 'R');
		}


		$this->x = $this->x_incial;
		$this->SetFont('Arial', 'B', 12);
		$this->Cell($this->cell_ancho /2, 10, 'TOTAL', 'TBL', 0, 'L');

		$this->Cell($this->cell_ancho /2, 10, Numbers::price($this->resumen_caja->saldo_cierre, true), 'TBR', 1, 'R');
	}


	function info_ingresos() {

		$this->y += 10;
		$this->SetFont('Arial', 'B', 14);
		
		$this->x = $this->x_incial;
		$this->Cell($this->cell_ancho, 10, 'Total Ingresos', 1, 1);

		$this->SetFont('Arial', '', 12);
		
		foreach ($this->resumen_caja->cajas as $caja) {

			$this->x = $this->x_incial;

			$this->SetFont('Arial', 'B', 12);
			$this->Cell($this->cell_ancho/2, 10, $caja->name, 'TBL', 0, 'L');

			$this->SetFont('Arial', '', 12);
			$this->Cell($this->cell_ancho/2, 10, Numbers::price($caja->pivot->total_ingresos, true), 'TBR', 1, 'R');
		}


		// Cuenta corriete
		$this->x = $this->x_incial;
		$this->SetFont('Arial', 'B', 12);
		$this->Cell($this->cell_ancho/2, 10, 'CTAS CTES', 'TBL', 0, 'L');

		$this->SetFont('Arial', '', 12);
		$this->Cell($this->cell_ancho/2, 10, Numbers::price($this->resumen_caja->saldo_cuenta_corriente, true), 'TBR', 1, 'R');


		$this->x = $this->x_incial;
		$this->SetFont('Arial', 'B', 12);
		$this->Cell($this->cell_ancho /2, 10, 'TOTAL', 'TBL', 0, 'L');

		$this->Cell($this->cell_ancho /2, 10, Numbers::price($this->resumen_caja->total_ingresos, true), 'TBR', 1, 'R');

	}

	function getHeight($item, $maximo_letas) {
    	$lines = 1;
    	$letras = strlen($item->name);
    	while ($letras > $maximo_letas) {
    		$lines++;
    		$letras -= $maximo_letas;
    	}
    	return $this->line_height * $lines;
	}

	function getPdfHeight() {
		$height = 120;
		return 300;
		if (!is_null($this->sale->afip_ticket)) {
			$height += 90;
		}
		foreach ($this->sale->combos as $combo) {
			$height += $this->getHeight($combo, 20);
			foreach ($combo->articles as $article) {
				$height += $this->getHeight($article, 20);
			}
		}
		foreach ($this->sale->articles as $article) {
			$height += $this->getHeight($article, 8);
		}
		// $height += 
		return $height;
	}

}