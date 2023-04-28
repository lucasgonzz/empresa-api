<?php

namespace App\Http\Controllers\Pdf; 

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\ImageHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class BudgetPdf extends fpdf {

	function __construct($budget, $with_prices) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;
		
		$this->user = UserHelper::getFullModel();
		$this->budget = $budget;
		$this->with_prices = $with_prices;

		$this->AddPage();
		$this->articles();
        $this->Output();
        exit;
	}

	function getFields() {
		if ($this->with_prices) {
			return [
				'Codigo' 	=> 20,
				'Producto' 	=> 80,
				'Precio' 	=> 30,
				'Cant' 		=> 20,
				'Bonif' 	=> 20,
				'Importe' 	=> 30,
			];
		} else {
			return [
				'Codigo' 	=> 20,
				'Producto' 	=> 150,
				'Cant' 		=> 30,
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
				'text' 	=> 'Cuit',
				'key'	=> 'cuit',
			],
		];
	}

	function Header() {
		$data = [
			'num' 				=> $this->budget->num,
			'date'				=> $this->budget->created_at,
			'title' 			=> 'Presupuesto',
			'model_info'		=> $this->budget->client,
			'model_props' 		=> $this->getModelProps(),
			'fields' 			=> $this->getFields(),
		];
		PdfHelper::header($this, $data);
	}

	function Footer() {
		// $y = 230;
		// $this->SetLineWidth(.4);
		$this->observations();
		$this->total();
		PdfHelper::comerciocityInfo($this, $this->y);
	}

	function logo() {
        // Logo
        if (!is_null($this->user->image_url)) {
        	if (env('APP_ENV') == 'local') {
        		$this->Image('https://img.freepik.com/vector-gratis/fondo-plantilla-logo_1390-55.jpg', 17, 0, 0, 25);
        	} else {
	        	$this->Image($this->user->image_url, 17, 0, 0, 25);
        	}
        }
		
        // Company name
		$this->SetFont('Arial', 'B', 10);
		$this->x = 5;
		$this->y = 30;
		$this->Cell(100, 5, $this->user->company_name, $this->b, 0, 'C');

		// Info
		$this->SetFont('Arial', '', 10);
		$address = null;
		if (count($this->user->addresses) >= 1) {
			$address = $this->user->addresses[0];
		}
		$info = $this->user->afip_information->razon_social;
		$info .= ' / '. $this->user->afip_information->iva_condition->name;
		if (!is_null($address)) {
			$info .= ' / '. $address->street.' N° '.$address->street_number;
			$info .= ' / '. $address->city.' / '.$address->province;
		}
		$info .= ' / '. $this->user->phone;
		$info .= ' / '. $this->user->email;
		$info .= ' / '. $this->user->online;
		$this->x = 5;
		$this->y += 5;
	    $this->MultiCell(100, 5, $info, $this->b, 'L', false);
	    // $this->lineInfo();
	}

	function budgetDates() {
		if (!is_null($this->budget->start_at) && !is_null($this->budget->finish_at)) {
			$this->SetFont('Arial', '', 10);
			$this->x = 105;
			$this->y = 58;
			$this->Cell(100, 5, 'Fecha de entrega', $this->b, 0, 'L');
			$this->y += 5;
			$this->x = 105;
			$date = 'Entre el '.date_format($this->budget->start_at, 'd/m/Y').' y el '.date_format($this->budget->finish_at, 'd/m/Y');
			$this->Cell(100, 5, $date, $this->b, 0, 'L');
			// $this->lineDates();
		}
	}

	function articles() {
		$this->SetFont('Arial', '', 10);
		$this->x = 5;
		foreach ($this->budget->articles as $article) {
			if ($this->y < 210) {
				$this->printArticle($article);
			} else {
				$this->AddPage();
				$this->x = 5;
				$this->y = 90;
				$this->printArticle($article);
			}
		}
	}

	function printProductDelivered($product) {
		$this->Cell(20, $this->getHeight($product), $product->bar_code, 'T', 0, 'C');
		$this->Cell(20, $this->getHeight($product), $product->amount, 'T', 0, 'C');
		$this->MultiCell(80, $this->line_height, $product->name, 'T', 'C', false);
		$this->x = 125;
		$this->y -= $this->getHeight($product);
		$this->Cell(50, $this->getHeight($product), $this->getTotalDeliveries($product), 'T', 0, 'C');
	}

	function printArticle($article) {
		$this->x = 5;
		$this->Cell($this->getFields()['Codigo'], $this->line_height, $article->bar_code, $this->b, 0, 'L');
		$y_1 = $this->y;
		$this->MultiCell($this->getFields()['Producto'], $this->line_height, $article->name, $this->b, 'L', false);
		
		$this->x = PdfHelper::getWidthUntil('Producto', $this->getFields());
	    $y_2 = $this->y;
		$this->y = $y_1;
		
		if ($this->with_prices) {
			$this->Cell($this->getFields()['Precio'], $this->line_height, '$'.Numbers::price($article->pivot->price), $this->b, 0, 'L');
		}
		$this->Cell($this->getFields()['Cant'], $this->line_height, $article->pivot->amount, $this->b, 0, 'L');
		if ($this->with_prices) {
			$this->Cell($this->getFields()['Bonif'], $this->line_height, $this->getBonus($article), $this->b, 0, 'L');
			$this->Cell($this->getFields()['Importe'], $this->line_height, '$'.Numbers::price(BudgetHelper::totalArticle($article)), $this->b, 0, 'L');
		}
		$this->y = $y_2;
		$this->Line(5, $this->y, 205, $this->y);
	}

	function getDeliveredArticles() {
		$articles = [];
		foreach ($this->budget->articles as $article) {
			if (count($article->pivot->deliveries) >= 1) {
				$articles[] = $article;
			}
		}
		return $products;
	}

	function getTotalDeliveries($product) {
		$total = 0;
		foreach ($product->deliveries as $delivery) {
			$total += $delivery->amount;
		}
		return $total;
	}

	function observations() {
		// $this->SetLineWidth(.2);
		if ($this->budget->observations != '') {
		    $this->x = 5;
		    $this->y += 5;
	    	$this->SetFont('Arial', 'B', 10);
			$this->Cell(100, $this->line_height, 'Observaciones', 0, 1, 'L');
		    $this->x = 5;
	    	$this->SetFont('Arial', '', 10);
	    	$this->MultiCell(200, $this->line_height, $this->budget->observations, $this->b, 'LTB', false);
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
			$this->Cell(100, 10, 'Total: $'. Numbers::price(BudgetHelper::getTotal($this->budget)), 0, 1, 'R');
		}
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