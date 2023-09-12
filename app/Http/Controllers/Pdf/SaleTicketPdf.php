<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\CommonLaravel\Helpers\UserHelper;
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
		if (!is_null($this->sale->afip_information)) {
			$this->SetFont('Arial', '', 8);
			$this->x = 2;
			$this->Cell($this->cell_ancho, 5, 'IVA: '.$this->user->afip_information->iva_condition->name, $this->b, 1, 'L');
			$this->x = 2;
			$this->Cell($this->cell_ancho, 5, 'Cuit: '.$this->user->afip_information->cuit, $this->b, 1, 'L');
			$this->x = 2;
			$this->SetFont('Arial', 'B', 8);
			$this->Cell($this->cell_ancho, 5, 'Punto de venta: '.$this->sale->afip_information->punto_venta, $this->b, 1, 'L');
			$this->x = 2;
			$this->Cell($this->cell_ancho, 5, 'CAE: '.$this->sale->afip_ticket->cae, $this->b, 1, 'L');
			$this->x = 2;
			$this->Cell($this->cell_ancho, 5, 'Vto cae: '.$this->getCaeExpiredAt(), $this->b, 1, 'L');
		}
	}

	function clientInfo() {
		$this->x = 2;
		$this->SetFont('Arial', '', 8);
		if ($this->sale->client) {
			$this->Cell($this->cell_ancho, 5, 'Cliente: '.$this->sale->client->name, $this->b, 1, 'L');
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
		$this->total();
		$this->thanks();
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
			$this->MultiCell($ancho_description, $this->line_height, $article->name." ({$article->pivot->amount})", 'TLB', 'L', 0);
			$y_2 = $this->y;

			$this->x = $ancho_description + 2;
			$this->y = $y_1;

			$this->SetFont('Arial', 'B', 9);
			$this->Cell($ancho_price, $y_2 - $y_1, '$'.Numbers::Price($article->pivot->price * $article->pivot->amount), 'TRB', 0, 'R');
			
			$this->x = 2;
			$this->y = $y_2;
		}
	}

	function comboArticles($combo) {
		$this->SetFont('Arial', '', 9);

		foreach ($combo->articles as $article) {
			$this->x = 6;
			$this->MultiCell(50, $this->line_height, $article->name.' ('.$article->pivot->amount.')', 'LR', 'L', 0);
		}
	}

	function total() {
	    $this->x = 2;
	    $this->SetFont('Arial', 'B', 12);
		$this->Cell($this->cell_ancho, 10, 'Total: $'. Numbers::price(SaleHelper::getTotalSale($this->sale)), 1, 0, 'C');
		$this->y += 10;
	}

	function thanks() {
	    $this->x = 2;
	    $this->SetFont('Arial', '', 10);
		$this->Cell($this->cell_ancho, 10, 'GRACIAS POR SU VISITA', 0, 0, 'C');
		// $this->y += 10;
	}

	function num() {
	    $this->x = 2;
	    $this->SetFont('Arial', '', 9);
		$this->Cell($this->cell_ancho, 5, 'Venta N° '.$this->sale->num, $this->b, 0, 'L');
		$this->y += 5;
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
		$height = 110;
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