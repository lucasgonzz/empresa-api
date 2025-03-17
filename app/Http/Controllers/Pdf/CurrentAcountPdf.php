<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class CurrentAcountPdf extends fpdf {

	function __construct($models) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;
		
		$this->models = $models;

		$this->AddPage();
		$this->print();
        $this->Output();
        exit;
	}

	function getFields() {
		return [
			'Fecha' 		=> 20,
			'Detalle' 		=> 50,
			'Debe' 			=> 30,
			'Haber' 		=> 30,
			'Saldo' 		=> 30,
			'Descripcion' 	=> 40,
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
			// 'num' 				=> $this->budget->num,
			// 'date'				=> $this->models[]->created_at,
			'date_formated'		=> date('d/m/y'),
			'title' 			=> 'Cuenta corriente',
			'model_info'		=> $this->models[0]->client,
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
		foreach ($this->models as $model) {
			if ($this->y < 280) {
				$this->printModel($model);
			} else {
				$this->AddPage();
				$this->printModel($model);
			}
		}
	}

	function printModel($model) {
		$this->x = 5;
		$this->Cell($this->getFields()['Fecha'], $this->line_height, date_format($model->created_at, 'd/m/y'), $this->b, 0, 'L');
		
		$y_1 = $this->y;
		$this->MultiCell($this->getFields()['Detalle'], $this->line_height, $model->detalle, $this->b, 'L', false);
		$y_2 = $this->y;
		$this->y = $y_1;

		$this->x = PdfHelper::getWidthUntil('Detalle', $this->getFields());

		$this->Cell($this->getFields()['Debe'], $this->line_height, '$'.Numbers::price($model->debe), $this->b, 0, 'L');
		$this->Cell($this->getFields()['Haber'], $this->line_height, '$'.Numbers::price($model->haber), $this->b, 0, 'L');
		$this->Cell($this->getFields()['Saldo'], $this->line_height, '$'.Numbers::price($model->saldo), $this->b, 0, 'L');

		$this->MultiCell($this->getFields()['Descripcion'], $this->line_height, $model->description, $this->b, 'L', false);
		$y_3 = $this->y;

		if ($y_2 > $y_3) {
			$this->y = $y_2;
		} else {
			$this->y = $y_3;
		}

		$this->Line(5, $this->y, 205, $this->y);
	}

}