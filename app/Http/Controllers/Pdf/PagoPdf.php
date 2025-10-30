<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\UserHelper;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class PagoPdf extends fpdf {

	function __construct($model, $model_name) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;
		
		$this->model = $model;
		$this->model_name = $model_name;

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
		if ($this->model_name == 'client') {
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
		} else {
			return [
				[
					'text' 	=> 'Proveedor',
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
	}

	function Header() {
		if ($this->model_name == 'client') {
			$model_info = $this->model->client;
		} else {
			$model_info = $this->model->provider;
		}
		$data = [
			'num' 				=> $this->model->num_receipt,
			'date'				=> $this->model->created_at,
			'title' 			=> 'Recibo de Pago',
			'model_info'		=> $model_info,
			'model_props' 		=> $this->getModelProps(),
		];
		PdfHelper::header($this, $data);
	}

	function printPago() {
		$this->x = 5;
		$this->y = 50;
		$this->SetFont('Arial', 'B', 11);
		if ($this->model_name == 'client') {
			$this->Cell(200, 7, 'Recibimos de '.$this->model->client->name, $this->b, 1, 'L');
		} else {
			$this->Cell(200, 7, 'Recibimos de '.UserHelper::getFullModel()->company_name, $this->b, 1, 'L');
		}
		$this->x = 5;
		$this->Cell(200, 7, 'la cantidad de pesos '.Numbers::price($this->model->haber), $this->b, 1, 'L');
	}

	function paymentMethods() {
		if (!is_null($this->model->current_acount_payment_methods)) {
			foreach ($this->model->current_acount_payment_methods as $payment_method) {
				$this->x = 5;
				$this->SetFont('Arial', '', 11);
				$this->Cell(200, 7, 'Pago con '.$payment_method->name.' $'.Numbers::price($payment_method->pivot->amount), $this->b, 1, 'L');

				if (count($this->model->cheques) >= 1) {
					
					$this->SetFont('Arial', 'B', 12);
					
					$this->y += 10;
					$this->x = 5;
					
					$this->Cell(200, 7, 'Informacion del cheque:', $this->b, 1, 'L');

					$this->SetFont('Arial', '', 11);
					foreach ($this->model->cheques as $cheque) {
						
						$this->x = 5;
						
						if ($cheque->numero) {

							$this->Cell(200, 7, 'Numero: '. $cheque->numero, $this->b, 1, 'L');
							$this->x = 5;
						}
						if ($cheque->banco) {

							$this->Cell(200, 7, 'Banco: '. $cheque->banco, $this->b, 1, 'L');
							$this->x = 5;
						}
						if ($cheque->amount) {

							$this->Cell(200, 7, 'Monto: '. $cheque->amount, $this->b, 1, 'L');
							$this->x = 5;
						}
						if ($cheque->fecha_emision) {

							$this->Cell(200, 7, 'Fecha de emision: '. date_format($cheque->fecha_emision, 'd/m/Y'), $this->b, 1, 'L');
							$this->x = 5;
						}
						if ($cheque->fecha_pago) {

							$this->Cell(200, 7, 'Fecha de pago: '. date_format($cheque->fecha_pago, 'd/m/Y'), $this->b, 1, 'L');
							$this->x = 5;
						}
					}
					$this->y += 10;
				}
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
		// PdfHelper::comerciocityInfo($this, $this->y);
	}

}