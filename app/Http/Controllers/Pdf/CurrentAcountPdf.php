<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\CommonLaravel\Helpers\StringHelper;
use App\Http\Controllers\Helpers\Numbers;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class CurrentAcountPdf extends fpdf {
    
    protected $printType = 'simple';

	function __construct($credit_account, $models, $printType = 'simple') {
		parent::__construct();
        $this->printType = $printType;
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;
		$this->line_height_sub_items = 4;
		$this->line_height_sub_title = 5;
        $this->line_height_empty = 2;

		$this->credit_account = $credit_account;
		$this->models = $models;

		$this->AddPage();
		$this->print();

		$this->saldo_actual();

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

	function saldo_actual() {
		if ($this->credit_account) {

			$this->y = 32;
			$this->x = 105;
			$this->SetFont('Arial', 'B', 16);

			$saldo = $this->models[count($this->models)-1]->saldo;
			// $saldo = $this->models[0]->saldo;
			$saldo = Numbers::price($saldo, true, $this->credit_account->moneda_id);

			$this->Cell(100, 15, 'Saldo actual: '.$saldo, 1, 0, 'C');
		}
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

	/**
	 * Prints a single CurrentAcount model's details as a row in the PDF.
	 * This method handles variable height rows by calculating the maximum height
	 * of multi-line cells and adjusting the Y position accordingly.
	 *
	 * @param \App\Models\CurrentAcount $model The CurrentAcount model to print.
	 */
	function printModel($model) {
		$this->x = 5;
		$this->Cell($this->getFields()['Fecha'], $this->line_height, date_format($model->created_at, 'd/m/y'), $this->b, 0, 'L');
		$detalle = $model->detalle; // Initialize the main detail text.
		$moneda_id = null;
		if ($this->credit_account) {
			$moneda_id = $this->credit_account->moneda_id;
		}

		if (!is_null($model->debe)) {
			if ($model->status != 'pagado') {
				if ($model->pagandose > 0) {
					$pagandose = Numbers::price($model->pagandose, true, $moneda_id);
					$detalle .= " ($pagandose)";
				} else {
					$detalle .= ' (Sin pagar)';
				}
			}
		}

		$y_1 = $this->y;
		
		// $detalle_col_x = 5 + $this->getFields()['Fecha'];
		// $this->SetX($detalle_col_x);

		$this->MultiCell($this->getFields()['Detalle'], $this->line_height, $detalle, $this->b, 'L', false);

        if ($this->printType == 'details') {
            $articles_to_show = [];
            if ($model->sale && $model->sale->articles->count() > 0) {
                $articles_to_show = $model->sale->articles;
            } else if ($model->articles->count() > 0) {
                $articles_to_show = $model->articles;
            }

            if (count($articles_to_show) > 0) {
				// $this->SetX($detalle_col_x);
				$this->x = 5;
                $this->Cell($this->getFields()['Detalle'], 5, '  Articulos:', $this->b, 1, 'L');

                foreach ($articles_to_show as $article) {
					// reseteo la posicion x para mantener los articulos alineados
					// $this->SetX($detalle_col_x);

					$width_name = $this->getFields()['Fecha'] +$this->getFields()['Detalle'] + $this->getFields()['Debe'] + $this->getFields()['Haber'];
                    
                    $name_with_amount = StringHelper::short($article->name, 60) . ' ('. $article->pivot->amount .')';

                    $this->Cell($width_name, 5, '    - '. $name_with_amount, $this->b, 0, 'L');

                    $unit_price = $article->pivot->price ? Numbers::price($article->pivot->price, true, $moneda_id) : '';
                    $this->Cell($this->getFields()['Saldo'], 5, $unit_price, $this->b, 0, 'L');

                    $total_price = '';
                    if ($article->pivot->price && !is_null($article->pivot->amount)) {
                        $total_price = Numbers::price($article->pivot->price * $article->pivot->amount, true, $moneda_id);
                    }
                    $this->Cell($this->getFields()['Descripcion'], 5, $total_price, $this->b, 1, 'L');
                }

                // linea vacia para dejar espacio entre el ultimo articulo y la linea 
                // $this->Cell($this->getFields()['Detalle'], 2, '', $this->b, 1, 'L');
            }

            $services_to_show = [];
            if ($model->sale && $model->sale->services->count() > 0) {
                $services_to_show = $model->sale->services;
            } else if ($model->services->count() > 0) {
                $services_to_show = $model->services;
            }

            if (count($services_to_show) > 0) {

				$this->x = 5;
                $this->Cell($this->getFields()['Detalle'], 5, '  Servicios:', $this->b, 1, 'L');

                foreach ($services_to_show as $service) {
					// reseteo la posicion x para mantener los articulos alineados
					$this->SetX($detalle_col_x);
                    
					$width_name = $this->getFields()['Fecha'] + $this->getFields()['Detalle'] + $this->getFields()['Debe'] + $this->getFields()['Haber'];
                    $name_with_amount = StringHelper::short($service->name, 60) . ' ('. $service->pivot->amount .')';

                    $this->Cell($width_name, 5, '    - '.$name_with_amount, $this->b, 0, 'L');

                    $unit_price = $service->pivot->price ? Numbers::price($service->pivot->price, true, $moneda_id) : '';
                    $this->Cell($this->getFields()['Saldo'], 5, $unit_price, $this->b, 0, 'L');

                    $total_price = '';
                    if ($service->pivot->price && !is_null($service->pivot->amount)) {
                        $total_price = Numbers::price($service->pivot->price * $service->pivot->amount, true, $moneda_id);
                    }
                    $this->Cell($this->getFields()['Descripcion'], 5, $total_price, $this->b, 1, 'L');
                }
                // linea vacia para dejar espacio entre el ultimo servicio y la linea 
                // $this->Cell($this->getFields()['Detalle'], 2, '', $this->b, 1, 'L');
            }
        }
		
		$y_2 = $this->y;
		$this->y = $y_1;

		$this->x = PdfHelper::getWidthUntil('Detalle', $this->getFields());

		$debe = $model->debe ? Numbers::price($model->debe, true, $moneda_id) : '';
		$haber = $model->haber ? Numbers::price($model->haber, true, $moneda_id) : '';
		$this->Cell($this->getFields()['Debe'], $this->line_height, $debe, $this->b, 0, 'L');
		$this->Cell($this->getFields()['Haber'], $this->line_height, $haber, $this->b, 0, 'L');
		$this->Cell($this->getFields()['Saldo'], $this->line_height, Numbers::price($model->saldo, true, $moneda_id), $this->b, 0, 'L');

		$this->MultiCell($this->getFields()['Descripcion'], $this->line_height, $model->description, $this->b, 'L', false);
		$y_3 = $this->y;

		// Setea Y para la proxima fila para estar debajo de la mas alta, detalle o descripcion
		if ($y_2 > $y_3) {
			$this->y = $y_2;
		} else {
			$this->y = $y_3;
		}

		$this->Line(5, $this->y, 205, $this->y);
	}

}