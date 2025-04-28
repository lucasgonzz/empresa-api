<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\UserHelper;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class OrderPdf extends fpdf {

	function __construct($model) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;
		
		$this->user = UserHelper::getFullModel();
		$this->model = $model;	

		$this->total_order = 0;

		$this->AddPage();
		$this->print();
		$this->printDescription();
        $this->Output();
        exit;
	}

	function getFields() {
		return [
			'C. Barras' => 35,
			'Nombre' 	=> 50,
			'Precio' 	=> 30,
			'Cantidad'  => 25,
			'Notas'  	=> 30,
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
				'text' 	=> 'Direccion',
				'key'	=> 'address',
			],
		];
	}

	function Header() {
		$data = [
			'num' 				=> $this->model->num,
			'date'				=> $this->model->created_at,
			'title' 			=> 'Pedido Online',
			'model_info'		=> !is_null($this->model->buyer->client) ? $this->model->buyer->client : $this->model->buyer,
			'model_props' 		=> $this->getModelProps(),
			'fields' 			=> $this->getFields(),
		];
		PdfHelper::header($this, $data);
	}

	function Footer() {
		// PdfHelper::comerciocityInfo($this, $this->y);
	}

	function print() {
		$this->SetFont('Arial', '', 10);
		$this->x = 5;
		foreach ($this->model->articles as $article) {
			if ($this->y < 260) {
				$this->printModel($article);
			} else {
				$this->AddPage();
				$this->x = 5;
				$this->printModel($article);
			}
		}

		$this->total();
	}

	function total() {

		$this->x = 5;
		$this->y += 5;

		$this->SetFont('Arial', 'B', 14);

		$this->Cell(200, $this->line_height, 'Total: $'.Numbers::price($this->total_order), $this->b, 1, 'L');
	}

	function printDescription() {
		if (!is_null($this->model->description)) {
			$this->y += 10;
			$this->x = 5;

			$this->SetFont('Arial', 'B', 12);
			$this->Cell(200, $this->line_height, 'Notas del pedido:', $this->b, 1, 'L');
			
			$this->SetFont('Arial', '', 10);
			$this->x = 5;
			$this->MultiCell(200, $this->line_height, $this->model->description, $this->b, 'L', false);
		}
	}

	function printModel($model) {
		$this->x = 5;
		$y_1 = $this->y;
		$this->Cell($this->getFields()['C. Barras'], $this->line_height, $model->bar_code, $this->b, 0, 'L');

		$this->MultiCell($this->getFields()['Nombre'], $this->line_height, $model->name, $this->b, 'L', false);
	    $y_2 = $this->y;
		$this->x = $this->getFields()['C. Barras']+$this->getFields()['Nombre']+5;
		$this->y = $y_1;

		$this->Cell($this->getFields()['Precio'], $this->line_height, '$'.Numbers::price($model->pivot->price), $this->b, 0, 'L');
		$this->Cell($this->getFields()['Cantidad'], $this->line_height, $model->pivot->amount, $this->b, 0, 'L');


		$this->MultiCell($this->getFields()['Notas'], $this->line_height, $model->pivot->notes, $this->b, 'L', false);
	    $y_3 = $this->y;
		$this->x = $this->getFields()['C. Barras']+$this->getFields()['Nombre']+$this->getFields()['Precio']+$this->getFields()['Cantidad']+$this->getFields()['Notas']+5;
		// $this->x = $this->getFields()['C. Barras']+$this->getFields()['Nombre']+$this->getFields()['C. Barras']+$this->getFields()['Precio']+$this->getFields()['Cantidad']+$this->getFields()['Notas']+5;
		$this->y = $y_1;

		$total = (float)$model->pivot->price * (float)$model->pivot->amount;

		$this->Cell($this->getFields()['Total'], $this->line_height, '$'.Numbers::price($total), $this->b, 0, 'L');

		$this->total_order += $total;

		if ($y_3 > $y_2) {
			$this->y = $y_3;
		} else {
			$this->y = $y_2;
		}

		$this->Line(5, $this->y, 205, $this->y);
	}

}