<?php

namespace App\Http\Controllers\Pdf; 

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class OrderProductionPdf extends fpdf {

	function __construct($order_production, $with_prices) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 6;
		
		$this->user = UserHelper::getFullModel();
		$this->order_production = $order_production;
		$this->with_prices = (boolean)$with_prices;

		$this->AddPage();
		$this->articles();
        $this->Output();
        exit;
	}

	function getFields() {
		if ($this->with_prices) {
			return [
				'Codigo'		=> 20,
				'Cant'			=> 20,
				'Producto'		=> 60,
				'Precio'		=> 30,
				'Bonif'			=> 20,
				'Importe'		=> 20,
				'U Entregadas'	=> 30,
			];
		} else {
			return [
				'Codigo'		=> 20,
				'Cant'			=> 20,
				'Producto'		=> 130,
				'U Entregadas'	=> 30,
			];
		}
	}

	function getModelProps() {
		return [
			[
				'text' 	=> 'Cliente',
				'key'	=> 'name',
			],
			[
				'text' 	=> 'Telefono',
				'key'	=> 'phone',
			],
			[
				'text' 	=> 'Localidad',
				'key'	=> 'location.name',
			],
			[
				'text' 	=> 'Cuit',
				'key'	=> 'cuit',
			],
		];
	}

	function Header() {
		$data = [
			'num' 			=> $this->order_production->num,
			'date'			=> $this->order_production->created_at,
			'title' 		=> 'Orden de produccion',
			'model_info'	=> $this->order_production->client,
			'model_props' 	=> $this->getModelProps(),
			'fields' 		=> $this->getFields(),
		];
		PdfHelper::header($this, $data);
	}


	function Footer() {
		$this->SetLineWidth(.4);
		$this->observations();
		$this->total();
		PdfHelper::comerciocityInfo($this, $this->y);
	}

	function articles() {
		$this->SetFont('Arial', '', 10);
		foreach ($this->order_production->articles as $article) {
			$this->x = 5;
			if ($this->y > 280) {
				$this->AddPage();
			} 
			$this->printArticle($article);
		}
	}

	function printArticle($article) {
		$this->x = 5;
		$this->Cell($this->getFields()['Codigo'], $this->line_height, $article->bar_code, 'T', 0, 'L');
		$this->Cell($this->getFields()['Cant'], $this->line_height, $article->pivot->amount, 'T', 0, 'L');
		$y_1 = $this->y;
		$this->MultiCell($this->getFields()['Producto'], $this->line_height, $article->name, 'T', 'L', false);
		
		$this->x = PdfHelper::getWidthUntil('Producto', $this->getFields());
	    $y_2 = $this->y;
		$this->y = $y_1;
		
		if ($this->with_prices) {
			$this->Cell($this->getFields()['Precio'], $this->line_height, '$'.Numbers::price($article->pivot->price), 'T', 0, 'L');
			$this->Cell($this->getFields()['Bonif'], $this->line_height, $this->getBonus($article), 'T', 0, 'L');
			$this->Cell($this->getFields()['Importe'], $this->line_height, '$'.Numbers::price(BudgetHelper::totalArticle($article)), 'T', 0, 'L');
		}
		$this->Cell($this->getFields()['U Entregadas'], $this->line_height, $article->pivot->delivered, 'T', 0, 'L');
		$this->y = $y_2;
	}

	function observations() {
		// $this->SetLineWidth(.2);
		if ($this->order_production->observations != '') {
		    $this->x = 5;
	    	$this->SetFont('Arial', 'B', 12);
			$this->Cell(100, 5, 'Observaciones', 0, 'L');
			$this->y += 5;
		    $this->x = 5;
	    	$this->SetFont('Arial', '', 10);
	    	$this->MultiCell(200, $this->line_height, $this->order_production->observations, $this->b, 'LTB', false);
	    	$this->x = 5;
		}
	}

	function getBonus($article) {
		if (!is_null($article->pivot->bonus)) {
			return $article->pivot->bonus.'%';
		}
		return '';
	}

	function total() {
		if ($this->with_prices) {
		    $this->x = 105;
		    $this->SetFont('Arial', 'B', 14);
			$this->Cell(100, 10, 'Total: $'. Numbers::price(BudgetHelper::getTotal($this->order_production)), 0, 1, 'R');
		}
	}

	function comerciocityInfo() {
	    $this->y = 290;
	    $this->x = 5;
	    $this->SetFont('Arial', '', 10);
		$this->Cell(200, 5, 'Comprobante creado con el sistema de control de stock ComercioCity - comerciocity.com', $this->b, 0, 'C');
	}

	function getHeight($product) {
    	$lines = 1;
    	$letras = strlen($product->name);
    	while ($letras > 41) {
    		$lines++;
    		$letras -= 41;
    	}
    	return $this->line_height * $lines;
	}

}