<?php

namespace App\Http\Controllers\Pdf;

use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class SuperBudgetPdf extends fpdf {

	function __construct($model) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 10);
		$this->b = 0;
		$this->line_height = 7;
		
		$this->model = $model;

		$this->SetLineWidth(.5);
		$this->AddPage();
		$this->printClient();
		$this->printTitles();
		$this->printFeatures();
		$this->printResumen();
		$this->printOfferValidity();
		$this->printTiempoEntrega();
		$this->printPlazosDePago();
        $this->Output();
        exit;
	}

	function Header() {
		$this->logo();
		$this->fecha();
		$this->printLine();
	}

	function logo() {
        // Logo
        $this->Image(public_path().'/storage/logo.png', 10, 5, 0, 27);
		$this->SetFont('Arial', '', 9);
		$line_height = 7;
        $this->y = 9;
        $this->x = 70;
        $this->Cell(80, $line_height, 'Email: lucasgonzalez5500@gmail.com', $this->b, 0, 'L');

        $this->y += $line_height;
        $this->x = 70;
        $this->Cell(80, $line_height, 'Telefono: 3444622139', $this->b, 0, 'L');

        $this->y += $line_height;
        $this->x = 70;
        $this->Cell(80, $line_height, 'Rosario, Santa Fe', $this->b, 0, 'L');
	}

	function fecha() {
		$this->SetFont('Arial', '', 18);
        $this->x = 150;
        $this->y = 13;
		$this->Cell(50, 10, date_format($this->model->created_at, 'd/m/Y'), $this->b, 0, 'R');
		$this->y = 30;
	}

	function printClient() {
		$this->x = 10;
		$this->SetFont('Arial', '', 30);
		$line_height = 20;
		$this->Cell(190, $line_height, 'Cliente: '.$this->model->client, $this->b, 0, 'L');
		$this->y += $line_height;
	}

	function printTitles() {
		$this->x = 10;
		$this->SetFont('Arial', '', 12);
		foreach ($this->model->super_budget_titles as $title) {
			$this->MultiCell(190, 5, $title->text, $this->b, 'L',);
		}
		$this->y += 5;
	}

	function printFeatures() {
		$this->SetFont('Arial', '', 18);
		$this->printLine();
		$this->x = 10;
		$line_height = 15;
		$this->Cell(95, $line_height, 'Modificaciones a realizar', $this->b, 0, 'L');

		$this->SetFont('Arial', '', 10);
		$this->Cell(95, $line_height, 'Costo estimado por hora de trabajo: $'.$this->model->hour_price, $this->b, 0, 'R');
		$this->y += $line_height;
		$this->printLine();

		foreach ($this->model->super_budget_features as $feature) {

			// TITULO
			$line_height = 10;
			$this->SetFont('Arial', '', 16);
			$this->x = 10;
			$this->MultiCell(190, 7, $feature->title, $this->b, 'L', false);
			// $this->y += $line_height;

			// DESCRIPCION
			$line_height = 5;
			if ($feature->description != '') {
				$this->SetFont('Arial', '', 10);
				$this->x = 10;
				$this->MultiCell(190, $line_height, $feature->description, $this->b, 'L', false);
			}

			// ITEMS
			$line_height = 5;
			if (count($feature->super_budget_feature_items) >= 1) {
				foreach ($feature->super_budget_feature_items as $item) {
					$this->SetFont('Arial', '', 10);
					$this->x = 15;
					$this->MultiCell(185, $line_height, '* '.$item->text, $this->b, 'L', false);
				}
			}

			// TIEMPO DE DESARROLLO
			$this->y += 2;
			$this->x = 10;
			$this->SetFont('Arial', 'B', 10);
			$this->Cell(50, $line_height, 'Tiempo de desarrollo: ', $this->b, 0, 'L');
			$this->SetFont('Arial', '', 10);
			$this->Cell(140, $line_height, $feature->development_time.'hs', $this->b, 0, 'R');
			$this->y += $line_height;

			// TOTAL
			$this->y += 2;
			$this->x = 10;
			$this->SetFont('Arial', 'B', 10);
			$this->Cell(50, $line_height, 'Total: ', $this->b, 0, 'L');
			$this->SetFont('Arial', '', 10);
			$this->Cell(140, $line_height, '$'.$this->getTotal($feature), $this->b, 0, 'R');
			$this->y += $line_height;

			// $this->y += 2;
			$this->printLine();
			// $this->y += 2;
		}
	}

	function printResumen() {
		$this->SetFont('Arial', '', 20);
		$line_height = 10;
		$this->x = 10;
		$this->Cell(190, $line_height, 'Resumen', $this->b, 0, 'L');
		
		$this->y += $line_height;
		$total = 0;

		$line_height = 7;
		$this->SetFont('Arial', '', 10);
		foreach ($this->model->super_budget_features as $feature) {
			$this->x = 10;
			$this->Cell(95, $line_height, $feature->title, $this->b, 0, 'L');
			$this->Cell(95, $line_height, '$'.$this->getTotal($feature), $this->b, 0, 'R');
			$this->y += $line_height;
			$total += $this->getTotal($feature);
		}

		$this->x = 10;
		$this->SetFont('Arial', 'B', 14);
		$this->Cell(95, $line_height, 'Total:', $this->b, 0, 'L');
		$this->Cell(95, $line_height, '$'.$total, $this->b, 0, 'R');
		$this->y += $line_height;
		$this->printLine();
	}

	function getTotal($feature) {
		return $feature->development_time * $this->model->hour_price;
	}

	function printTiempoEntrega() {
		$this->SetFont('Arial', '', 20);
		$line_height = 10;
		$this->x = 10;
		$this->Cell(190, $line_height, 'Tiempo de Entrega', $this->b, 0, 'L');
		
		$this->y += $line_height;

		$line_height = 7;
		$this->SetFont('Arial', '', 10);

		$this->x = 10;
		$this->MultiCell(190, $line_height, $this->model->delivery_time, $this->b, 'L', false);
		$this->printLine();
	}

	function printPlazosDePago() {
		$this->SetFont('Arial', '', 20);
		$line_height = 10;
		$this->x = 10;
		$this->Cell(190, $line_height, 'Plazos de pago', $this->b, 0, 'L');
		
		$this->y += $line_height;

		$line_height = 7;
		$this->SetFont('Arial', 'B', 10);

		$plazo_de_pago = '50% para comenzar y 50% luego del chequeo por parte del cliente.';

		$this->x = 10;
		$this->Cell(190, $line_height, $plazo_de_pago, $this->b, 0, 'L');
		$this->y += $line_height;
		$this->printLine();
	}

	function printOfferValidity() {
		$this->SetFont('Arial', 'B', 14);
		$line_height = 10;
		$this->x = 10;
		$this->Cell(95, $line_height, 'Validez de la oferta: ', $this->b, 0, 'L');
		$this->Cell(95, $line_height, date_format($this->model->offer_validity, 'd/m/Y'), $this->b, 0, 'R');
		$this->y += $line_height;
		$this->printLine();
	}

	function printLine() {
		$this->y += 2;
		$this->Line(10, $this->y, 200, $this->y);
		$this->y += 2;
	}

}