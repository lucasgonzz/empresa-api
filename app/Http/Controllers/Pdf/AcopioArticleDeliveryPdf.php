<?php

namespace App\Http\Controllers\Pdf; 

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\ImageHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class AcopioArticleDeliveryPdf extends fpdf {

	function __construct($model) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;
		
		$this->user = UserHelper::getFullModel();
		$this->model = $model;

		$this->AddPage();
		$this->items();
        $this->Output();
        exit;
	}

	function getFields() {
		return [
			// 'Codigo' 			=> 20,
			'Producto' 			=> 100,
			'Entrega' 		=> 30,
			'Total vendidas' 		=> 35,
			'Total entregadas' 		=> 35,
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
			'num' 				=> $this->model->id,
			'date'				=> $this->model->created_at,
			'title' 			=> 'Entrega',
			'model_info'		=> $this->model->sale->client,
			'model_props' 		=> $this->getModelProps(),
			'fields' 			=> $this->getFields(),
		];
		PdfHelper::header($this, $data);
	}


	function items() {
		$this->SetFont('Arial', '', 10);
		$this->x = 5;
		foreach ($this->model->articles as $article) {
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


	function printArticle($article) {
		$this->x = 5;
		// $this->Cell($this->getFields()['Codigo'], $this->line_height, $article->bar_code, $this->b, 0, 'L');
		
		$y_1 = $this->y;
		$this->MultiCell($this->getFields()['Producto'], $this->line_height, $article->name, $this->b, 'L', false);
		
		$this->x = PdfHelper::getWidthUntil('Producto', $this->getFields());
	    $y_2 = $this->y;
		$this->y = $y_1;
		
		$this->Cell($this->getFields()['Entrega'], $this->line_height, $article->pivot->amount, $this->b, 0, 'R');
		
		
		$article_sale = $this->model->sale->articles->find($article->id);

		$this->Cell($this->getFields()['Total vendidas'], $this->line_height, $article_sale->pivot->amount, $this->b, 0, 'R');
		$this->Cell($this->getFields()['Total entregadas'], $this->line_height, $article_sale->pivot->delivered_amount, $this->b, 0, 'R');
		
		$this->y = $y_2;
		$this->Line(5, $this->y, 205, $this->y);
	}

	

}