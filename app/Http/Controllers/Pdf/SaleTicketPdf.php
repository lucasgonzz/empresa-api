<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Pdf\AfipQrPdf;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class SaleTicketPdf extends fpdf {

	function __construct($sale) {
		$this->line_height = 5;
		$this->user = UserHelper::getFullModel();
		$this->sale = $sale;

		$this->ancho = $this->user->sale_ticket_width;
		$this->cell_ancho = $this->ancho - 4;

		parent::__construct('P', 'mm', [$this->ancho, $this->getPdfHeight()]);
		$this->SetAutoPageBreak(false);
		$this->b = 0;

		$this->AddPage();
		$this->items();
        $this->Output();
        exit;
	}

	function Header() {
		$this->logo();
		$this->afipInformation();
		$this->clientInfo();
		$this->num();
		$this->date();
		$this->address();
	}

	function afipInformation() {
		if (!is_null($this->sale->afip_information) && !is_null($this->sale->afip_ticket)) {
			$this->SetFont('Arial', '', 10);
			$this->x = 2;
			$this->Cell($this->cell_ancho, 5, 'IVA: '.$this->user->afip_information->iva_condition->name, $this->b, 1, 'L');
			$this->x = 2;
			$this->Cell($this->cell_ancho, 5, 'Cuit: '.$this->user->afip_information->cuit, $this->b, 1, 'L');

			$this->x = 2;
			$this->Cell($this->cell_ancho, 5, 'Razon social: '.$this->user->afip_information->razon_social, $this->b, 1, 'L');
			
			$this->SetFont('Arial', 'B', 10);
			$this->x = 2;
			$this->Cell($this->cell_ancho, 5, 'Punto de venta: '.$this->sale->afip_information->punto_venta, $this->b, 1, 'L');
			$this->x = 2;
			$this->Cell($this->cell_ancho, 5, 'N° comprobante: '.$this->sale->afip_ticket->cbte_numero, $this->b, 1, 'L');
			$this->x = 2;
			$this->Cell($this->cell_ancho, 5, 'CAE: '.$this->sale->afip_ticket->cae, $this->b, 1, 'L');
			$this->x = 2;
			$this->Cell($this->cell_ancho, 5, 'Vto cae: '.$this->getCaeExpiredAt(), $this->b, 1, 'L');

			$this->tipo_de_comprobante();

		}
	}

	function tipo_de_comprobante() {

		$this->SetFillColor(0,0,0);
		$this->SetTextColor(255,255,255);
		$this->SetFont('Arial', '', 10);

		$this->x = 2;
		$this->Cell($this->cell_ancho, 6, 'Tipo comprobante: '.$this->sale->afip_ticket->cbte_letra, $this->b, 1, 'C', 1);

		$this->SetTextColor(0,0,0);
	}

	function clientInfo() {
		$this->x = 2;
		$this->SetFont('Arial', '', 8);
		if ($this->sale->client) {
			$this->Cell($this->cell_ancho, 5, 'Cliente: '.$this->sale->client->name, $this->b, 1, 'L');

			if (
				$this->sale->afip_ticket
				&& $this->sale->afip_ticket->iva_cliente != ''
			) {
				$this->x = 2;
				$this->Cell($this->cell_ancho, 5, 'IVA '.$this->sale->afip_ticket->iva_cliente, $this->b, 1, 'L');
			} 

		} else if (is_null($this->sale->client) && $this->sale->afip_ticket) {
			$this->Cell($this->cell_ancho, 5, 'Cliente: Consumidor final', $this->b, 1, 'L');
		}
	}

	function getCaeExpiredAt() {
		$date = $this->sale->afip_ticket->cae_expired_at;
		return substr($date, 0, 11);
		return substr($date, 0, 4).'/'.substr($this->sale->afip_ticket->cae_expired_at, 4, 2).'/'.substr($date, 6, 8);
	}

	function Footer() {

		$this->total_sale = SaleHelper::getTotalSale($this->sale, false, false);

		$this->total_sin_des_rec();

		$this->discounts();

		$this->surchages();

		$this->payment_method_discounts();

		$this->total();

		$this->ticket_description();
		
		$this->qr();

		// $this->comerciocityInfo();
	}

	function logo() {
        // Logo
        if (!is_null($this->user->image_url)) {
        	$image_width = 25;
        	$sobrante = $this->ancho - $image_width;
        	if (env('APP_ENV') == 'local') {
        		$this->Image('https://img.freepik.com/vector-gratis/fondo-plantilla-logo_1390-55.jpg', $sobrante / 2, 0, 0, $image_width);
        	} else {
	        	$this->Image($this->user->image_url, $sobrante / 2, 0, 0, 25);
        	}
        }
		
        // Company name
		$this->SetFont('Arial', 'B', 14);
		$this->x = 2;	
		$this->y = 25;
		$this->Cell($this->cell_ancho, 10, $this->user->company_name, $this->b, 0, 'C');
		$this->y += 10;
	}

	function items() {
		$this->x = 2;
		$this->y += 2;

		$ancho_description = 60 * $this->cell_ancho / 100; 

		$ancho_price = $this->cell_ancho - $ancho_description; 
		
		if ($this->ancho > 60) {

			$ancho_price = $ancho_price / 2; 
		}



		foreach ($this->sale->combos as $combo) {
			$this->SetFont('Arial', '', 9);

			$y_1 = $this->y;
			$this->MultiCell($ancho_description, $this->line_height, $combo->name.' ('.$combo->pivot->amount.')', 'LTB', 'L', 0);
			$y_2 = $this->y;
			$this->x = $ancho_description + 2;
			$this->y = $y_1;
			
			$this->SetFont('Arial', 'B', 9);
			$this->Cell($ancho_price, $y_2 - $y_1, '$'.Numbers::Price($combo->pivot->price * $combo->pivot->amount), 'RTB', 0, 'R');

			$this->y = $y_2;
			
			$this->comboArticles($combo);
			$this->x = 2;
		}

		foreach ($this->sale->articles as $article) {
			$this->SetFont('Arial', '', 9);
			$y_1 = $this->y;
			$this->MultiCell($ancho_description, $this->line_height, $article->name." ({$article->pivot->amount})", 'BT', 'L', 0);
			$y_2 = $this->y;

			$this->x = $ancho_description + 2;
			$this->y = $y_1;

			$this->SetFont('Arial', 'B', 9);

			if ($this->ancho > 60) {

				$this->Cell($ancho_price, $y_2 - $y_1, '$'.Numbers::Price($article->pivot->price), 'BT', 0, 'R');
			}

			$this->Cell($ancho_price, $y_2 - $y_1, $this->totalItem($article), 'BT', 0, 'R');
			
			$this->x = 2;
			$this->y = $y_2;
		}
	}

	function totalItem($item) {
		$total = $item->pivot->price * $item->pivot->amount;
		// foreach ($this->sale->discounts as $discount) {
		// 	$total -= $total * $discount->pivot->percentage / 100; 
		// }
		// foreach ($this->sale->surchages as $surchage) {
		// 	$total += $total * $surchage->pivot->percentage / 100; 
		// }
		return '$'.Numbers::Price($total);
	}

	function comboArticles($combo) {
		$this->SetFont('Arial', '', 9);

		foreach ($combo->articles as $article) {
			$this->x = 6;
			$this->MultiCell(50, $this->line_height, $article->name.' ('.$article->pivot->amount.')', 'LR', 'L', 0);
		}
	}

	function total_sin_des_rec() {
		if (count($this->sale->discounts) >= 1 || count($this->sale->surchages) >= 1) {
		    $this->x = 2;
		    $this->SetFont('Arial', 'B', 10);
			$this->Cell($this->cell_ancho, 7, 'Total $'.Numbers::price($this->total_sale), 'B', 1, 'R');
		}
	}

	function discounts() {
	    $this->SetFont('Arial', 'B', 10);
	    foreach ($this->sale->discounts as $discount) {
	    	$this->x = 2;
	    	$this->total_sale -= $this->total_sale * (float)$discount->pivot->percentage / 100;
			$this->Cell($this->cell_ancho / 2, 7, 'Desc '. $discount->pivot->percentage.'%', 'B', 0, 'L');
			$this->Cell($this->cell_ancho / 2, 7, '$'.Numbers::price($this->total_sale), 'B', 1, 'R');
	    }
	}

	function payment_method_discounts() {
	    $this->SetFont('Arial', 'B', 10);
	    foreach ($this->sale->current_acount_payment_methods as $payment_method) {
	    	if (!is_null($payment_method->pivot->discount_amount)) {
		    	$this->x = 2;
		    	$this->total_sale -= (float)$payment_method->pivot->discount_amount;
				$this->Cell($this->cell_ancho / 2, 7, 'Desc $'. Numbers::price($payment_method->pivot->discount_amount).' '.substr($payment_method->name, 0, 3), 'B', 0, 'L');
				$this->Cell($this->cell_ancho / 2, 7, '$'.Numbers::price($this->total_sale), 'B', 1, 'R');
	    	}
	    }
	}

	function surchages() {
	    $this->x = 2;
	    $this->SetFont('Arial', 'B', 10);
	    foreach ($this->sale->surchages as $surchage) {
	    	$this->total_sale += $this->total_sale * (float)$surchage->pivot->percentage / 100;
			$this->Cell($this->cell_ancho / 2, 7, 'Rec '. $surchage->pivot->percentage.'%', 'B', 0, 'L');
			$this->Cell($this->cell_ancho / 2, 7, '$'.Numbers::price($this->total_sale), 'B', 1, 'R');
	    }
	}

	function total() {
		$total_sale = $this->total_sale;

		if (!is_null($this->sale->total)) {

			$total_sale = $this->sale->total;
		}
		   

		if (!is_null($this->sale->total) && $this->sale->total != $this->total_sale) {

			$this->SetFont('Arial', 'B', 10);
		    $this->x = 2;
			$this->Cell($this->cell_ancho, 10, 'Total sin descuentos: $'. Numbers::price($this->total_sale), 0, 0, 'L');
			$this->y += 10;
		}

		$this->iva_discriminado();


		$this->SetFont('Arial', 'B', 12);
	    $this->x = 2;
		$this->Cell($this->cell_ancho, 10, 'Total: $'. Numbers::price($total_sale), 1, 0, 'C');
		$this->y += 10;
	}

	function iva_discriminado() {

		if (!is_null($this->sale->afip_ticket)) {

			$this->SetFont('Arial', '', 10);

			$this->y += 5;
        	
        	$afip_helper = new AfipHelper($this->sale);

			$importes = $afip_helper->getImportes();

		    $this->x = 2;
			$this->Cell(60, 5, 'Imp Neto Gravado: $'.Numbers::price($importes['gravado']), 0, 1, 'L');

			foreach ($importes['ivas'] as $iva => $importe) {
				if ($importe['Importe'] > 0) {
		    		$this->x = 2;
					$this->Cell(40, 5, 'IVA '.$iva.'%: $'.Numbers::price($importe['Importe']), 0, 1, 'L');
				}
			}
			$this->y += 5;
		}
	}

	function qr() {
		if (!is_null($this->sale->afip_ticket)) {
			$pdf = new AfipQrPdf($this, $this->sale, true);
			$pdf->printQr();
		}
	}

	function ticket_description() {

		if (!is_null($this->user->sale_ticket_description)) {
		    $this->x = 2;
		    $this->y += 2;
		    $this->SetFont('Arial', '', 10);

			$this->MultiCell($this->cell_ancho, 5, $this->user->sale_ticket_description, 0, 'L');
		}
	}

	function num() {
		if (is_null($this->sale->afip_ticket)) {
		    $this->x = 2;
		    $this->SetFont('Arial', '', 9);
			$this->Cell($this->cell_ancho, 5, 'Venta N° '.$this->sale->num, $this->b, 0, 'L');
			$this->y += 5;
		}
	}

	function date() {
		$date = date_format($this->sale->created_at, 'd/m/Y H:i');
	    $this->x = 2;
	    $this->SetFont('Arial', '', 9);
		$this->Cell($this->cell_ancho, 5, $date, $this->b, 0, 'L');
		$this->y += 5;
	}
	
	function address() {
		if (!is_null($this->sale->address)) {
			$address = $this->sale->address->street.' '.$this->sale->address->street_number;
		    $this->x = 2;
		    $this->SetFont('Arial', '', 9);
			$this->Cell($this->cell_ancho, 5, $address, $this->b, 1, 'L');
		}
	}

	function comerciocityInfo() {
	    $this->y = 290;
	    $this->x = 5;
	    $this->SetFont('Arial', '', 10);
		$this->Cell(200, 5, 'Comprobante creado con el sistema de control de stock ComercioCity - comerciocity.com', $this->b, 0, 'C');
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
			$height += $this->getHeight($article, 15);
		}
		// $height += 
		return $height;
	}

}