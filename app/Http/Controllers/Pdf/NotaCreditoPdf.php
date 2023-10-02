<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\UserHelper;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class NotaCreditoPdf extends fpdf {

	function __construct($model) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;
		$this->total = 0;
		
		$this->model = $model;
		$this->AddPage();
		$this->printItems();
        $this->Output();
        exit;
	}

	function getFields() {
		return [
			'Codigo' 	=> 40,
			'Producto/Servicio' 	=> 90,
			'Precio' 	=> 20,
			'Cant' 		=> 20,
			'Total' 	=> 30,
		];
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
			'num' 				=> $this->model->num_receipt,
			'date'				=> $this->model->created_at,
			'title' 			=> 'Nota de Credito',
			'model_info'		=> $this->model->client,
			'model_props' 		=> $this->getModelProps(),
			// 'fields' 			=> $this->getFields(),
		];
		if (count($this->model->articles) >= 1) {
			$data['fields'] = $this->getFields();
		}
		PdfHelper::header($this, $data);
	}

	function printItems() {
		$this->x = 5;
		$this->SetFont('Arial', '', 8);
		if (count($this->model->articles) >= 1 || count($this->model->services) >= 1) {
			foreach ($this->model->articles as $article) {
				$this->Cell($this->getFields()['Codigo'], 5, $article->bar_code, $this->b, 0, 'C');

				$y_1 = $this->y;
			    $this->MultiCell( 
					$this->getFields()['Producto/Servicio'], 
					5, 
					$article->name, 
			    	$this->b, 
			    	'L', 
			    	false
			    );
			    $y_2 = $this->y;
			    $this->y = $y_1;
		    	$this->x = 5 + $this->getFields()['Codigo'] + $this->getFields()['Producto/Servicio'];
				$this->Cell($this->getFields()['Precio'], 5, '$'.Numbers::price($article->pivot->price), $this->b, 0, 'C');
				$this->Cell($this->getFields()['Cant'], 5, $article->pivot->amount, $this->b, 0, 'C');
				$this->Cell($this->getFields()['Total'], 5, $this->getTotal($article), $this->b, 0, 'C');
				$this->y = $y_2;
				$this->x = 5;
				$this->Line(5, $this->y, 205, $this->y);
			}
			foreach ($this->model->services as $service) {
				// $this->Cell($this->getFields()['Codigo'], 5, $service->bar_code, $this->b, 0, 'C');
				$this->x += $this->getFields()['Codigo'];
				$y_1 = $this->y;
			    $this->MultiCell( 
					$this->getFields()['Producto/Servicio'], 
					5, 
					$service->name, 
			    	$this->b, 
			    	'L', 
			    	false
			    );
			    $y_2 = $this->y;
			    $this->y = $y_1;
		    	$this->x = 5 + $this->getFields()['Codigo'] + $this->getFields()['Producto/Servicio'];
				$this->Cell($this->getFields()['Precio'], 5, '$'.Numbers::price($service->pivot->price), $this->b, 0, 'C');
				$this->Cell($this->getFields()['Cant'], 5, $service->pivot->amount, $this->b, 0, 'C');
				$this->Cell($this->getFields()['Total'], 5, $this->getTotal($service), $this->b, 0, 'C');
				$this->y = $y_2;
				$this->x = 5;
				$this->Line(5, $this->y, 205, $this->y);
			}
		} else {
			$this->SetFont('Arial', '', 12);
			$this->MultiCell( 
				200, 
				5, 
				$this->model->description, 
		    	$this->b, 
		    	'L', 
		    	false
		    );
		}
	}

	function getTotal($article) {
		$total = (float)$article->pivot->amount * $article->pivot->price;
		$this->total += $total;
		return '$'.Numbers::price($total);
	}

	function Footer() {
		if (!is_null($this->model->sale)) {
			foreach ($this->model->sale->discounts as $discount) {
				$this->total -= $this->total * $discount->pivot->percentage / 100;
			}
			foreach ($this->model->sale->surchages as $surchage) {
				$this->total += $this->total * $surchage->pivot->percentage / 100;
			}
		}
		if (count($this->model->articles) >= 1) {
			$total = $this->total;
		} else {
			$total = $this->model->haber;
		}
		PdfHelper::total($this, $total);
		PdfHelper::comerciocityInfo($this, $this->y);
	}

}