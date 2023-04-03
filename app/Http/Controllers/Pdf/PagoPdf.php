<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\UserHelper;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class PagoPdf extends fpdf {

	function __construct($model) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;
		
		$this->model = $model;

		$this->AddPage();
		$this->printPago();
		$this->paymentMethods();
		$this->description();
		PdfHelper::firma($this);
		$this->pesos();
        $this->Output();
        exit;
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
			'title' 			=> 'Recibo de Pago',
			'model_info'		=> $this->model->client,
			'model_props' 		=> $this->getModelProps(),
		];
		PdfHelper::header($this, $data);
	}

	function printPago() {
		$this->x = 5;
		$this->y = 50;
		$this->SetFont('Arial', 'B', 11);
		$this->Cell(200, 7, 'Recibimos de '.$this->model->client->name, $this->b, 1, 'L');
		$this->x = 5;
		$this->Cell(200, 7, 'la cantidad de pesos '.Numbers::price($this->model->haber), $this->b, 1, 'L');
	}

	function paymentMethods() {
		if (!is_null($this->model->current_acount_payment_methods)) {
			foreach ($this->model->current_acount_payment_methods as $payment_method) {
				$this->x = 5;
				$this->SetFont('Arial', '', 11);
				$this->Cell(200, 7, 'Pago con '.$payment_method->name.' $'.Numbers::price($payment_method->pivot->amount), $this->b, 1, 'L');
			}
		}
	}

	function description() {
		if (!is_null($this->model->description)) {
			$this->x = 5;
			$this->SetFont('Arial', '', 11);
			$this->MultiCell(200, 7, 'Aclaraciones: '.$this->model->description, $this->b, 'L', false);
		}
	}

	function pesos() {
		$this->x = 155;
		$this->y -= 5;
		$this->SetFont('Arial', '', 11);
		$this->Cell(50, 7, 'Son $'.Numbers::price($this->model->haber), 1, 1, 'L');
	}

	function Footer() {
		PdfHelper::comerciocityInfo($this, $this->y);
	}

}