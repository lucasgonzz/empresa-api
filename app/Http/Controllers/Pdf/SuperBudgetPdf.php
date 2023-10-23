<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
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
		$this->printIncluye();
		// $this->printResumen();
		// $this->printOfferValidity();
		// $this->printTiempoEntrega();
		// $this->printPlazosDePago();
		// $this->printDescuentos();
		$this->printTrabajosRealizados();
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
        $this->Cell(80, $line_height, 'Email: contacto@comerciocity.com', $this->b, 0, 'L');

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
		foreach ($this->model->super_budget_titles as $title) {
			if (!is_null($title->title)) {
				$this->SetFont('Arial', 'B', 12);
				if (!is_null($title->text)) {
        			$this->Image('https://upload.wikimedia.org/wikipedia/commons/thumb/c/c6/Sign-check-icon.png/800px-Sign-check-icon.png', 10, $this->y+.5, 5, 5);
					$this->x += 5;
				}
				$this->MultiCell(190, 7, $title->title, $this->b, 'L',);
				// if (is_null($title->text)) {
				// 	$this->Line(10, $this->y, 200, $this->y);
				// }
			}
			if (!is_null($title->text)) {
				$this->SetFont('Arial', '', 12);
				$this->MultiCell(190, 7, $title->text, $this->b, 'L',);
			}
		}
		$this->y += 5;
	}

	function printFeatures() {
		$this->AddPage();
		$this->SetFont('Arial', '', 18);
		// $this->printLine();
		$this->x = 10;
		$line_height = 15;
		$this->Cell(95, $line_height, 'Funcionalidades a desarrollar', $this->b, 0, 'L');
		// $this->Cell(95, $line_height, 'Modificaciones a realizar', $this->b, 0, 'L');

		$this->SetFont('Arial', '', 10);
		// $this->Cell(95, $line_height, 'Costo estimado por hora de trabajo: $'.$this->model->hour_price, $this->b, 0, 'R');

		$this->y += $line_height;
		$this->printLine();

		foreach ($this->model->super_budget_features as $feature) {

			// TITULO
			$line_height = 10;
			$this->SetFont('Arial', '', 16);
			
        	$this->Image('https://icons.iconarchive.com/icons/google/noto-emoji-travel-places/512/42656-glowing-star-icon.png', 10, $this->y, 5, 5);
			
			$this->x = 15;
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

			if ($this->y >= 270) {
				$this->AddPage();
			}

			// TIEMPO DE DESARROLLO
			$this->y += 2;

        	$this->Image('https://icons.iconarchive.com/icons/flat-icons.com/flat/512/Clock-icon.png', 10, $this->y, 5, 5);

			$this->SetFont('Arial', 'B', 10);
			$this->x = 15;
			$this->Cell(50, $line_height, 'Tiempo de desarrollo: ', $this->b, 0, 'L');
			$this->SetFont('Arial', '', 10);
			$this->Cell(135, $line_height, $feature->development_time.'hs', $this->b, 0, 'R');
			$this->y += $line_height;

			// TOTAL
			// $this->y += 2;

        	// $this->Image('https://cdn-icons-png.flaticon.com/512/4021/4021642.png', 10, $this->y, 5, 5);

			// $this->x = 15;
			// $this->SetFont('Arial', 'B', 10);
			// $this->Cell(50, $line_height, 'Total: ', $this->b, 0, 'L');
			// $this->SetFont('Arial', '', 10);
			// $this->Cell(135, $line_height, '$'.$this->getTotal($feature), $this->b, 0, 'R');
			// $this->y += $line_height;

			$this->printLine();
		}
	}

	function printIncluye() {
		$this->SetFont('Arial', '', 18);
        
        $this->x = 10;
		$this->MultiCell(190, 15, 'Este presupuesto incluye las siguientes características:', $this->b, 'L', false);

		$this->SetFont('Arial', '', 14);
        
        $this->Image('https://cdn-icons-png.flaticon.com/512/5602/5602732.png', 10, $this->y, 10, 10);
        $this->x = 20;
		$this->MultiCell(190, 7, 'Dominio web (barberia-new-york.com.ar). Renovacion cada 1 año ($4000 ARS aproximadamente).', $this->b, 'L', false);
		$this->Ln();

        $this->Image('https://cdn-icons-png.flaticon.com/512/1320/1320467.png', 10, $this->y, 10, 10);
        $this->x = 20;
		$this->MultiCell(190, 7, 'Certificados SSL para una conexión segura y mayor confianza para los usuarios.', $this->b, 'L', false);
		$this->Ln();

        $this->Image('https://cdn-icons-png.flaticon.com/512/3962/3962020.png', 10, $this->y, 10, 10);
        $this->x = 20;
		$this->MultiCell(190, 7, 'Infraestructura de servidores para el funcionamiento del sitio. $3 USD por mes. Este monto se cobrara de manera trimestral, por adelantado y a la cotizacion del dolar blue al momento de hacer al pago.', $this->b, 'L', false);
		$this->Ln();

        $this->Image('https://upload.wikimedia.org/wikipedia/commons/thumb/c/c6/Sign-check-icon.png/800px-Sign-check-icon.png', 10, $this->y, 10, 10);
        $this->x = 20;
		$this->MultiCell(190, 7, 'Revisiones y modificaciones solicitadas por el cliente durante el proceso de desarrollo, para garantizar la conformidad del cliente con el producto final.', $this->b, 'L', false);
		$this->Ln();
		
		$this->printLine();
	}

	function printResumen() {
		$this->AddPage();
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

	function getTotalGlobal() {
		$total = 0;
		foreach ($this->model->super_budget_features as $feature) {
			$total += $this->getTotal($feature);
		}
		return $total;
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

		$plazo_de_pago = '30% para comenzar y 70% luego del chequeo por parte del cliente.';

		$this->x = 10;
		$this->Cell(190, $line_height, $plazo_de_pago, $this->b, 0, 'L');
		$this->y += $line_height;
		$this->printLine();
	}

	function printDescuentos() {
		$this->SetFont('Arial', '', 18);
		$this->x = 10;
		$this->Cell(190, 10, 'Descuentos por formas de pago', $this->b, 1, 'L');

		$this->SetFont('Arial', 'B', 10);

		$total = $this->getTotalGlobal();

		$this->x = 10;
		$this->Cell(190, 10, '10% de descuento por pago en efectivo o transferencia', $this->b, 1, 'L');

		$pago_efectivo = $total - ($total * 10 / 100);
		$this->x = 10;
		$this->SetFont('Arial', '', 14);
		$this->Cell(190, 10, '$'.Numbers::price($pago_efectivo), $this->b, 1, 'L');

		$this->x = 10;
		$this->SetFont('Arial', 'B', 10);
		$this->Cell(190, 10, '10% de descuento en caso de no necesitar factura', $this->b, 1, 'L');

		$sin_factura = $pago_efectivo - ($pago_efectivo * 10 / 100);
		$this->x = 10;
		$this->SetFont('Arial', '', 14);
		$this->Cell(190, 10, '$'.Numbers::price($sin_factura), $this->b, 1, 'L');
	}

	function printTrabajosRealizados() {
		$this->AddPage();	
		$this->SetFont('Arial', '', 18);
		$line_height = 10;
		$this->x = 10;
		$this->MultiCell(190, $line_height, 'Algunos de nuestros ultimos trabajos realizados, para garantizar la calidad de este proyecto.', $this->b, 'L', false);

		$this->y += 5;

		$this->SetFont('Arial', '', 12);

		$this->imgLink();
		$this->Cell(180, 15, 'https://comerciocity.com', $this->b, 1, 'L');
        
        $this->imgCheck();
		$this->MultiCell(180, 5, 'El proyecto mas ambicioso hasta el momento, consiste en un sistema CRM utilizado por mas de 20 negocios para administrar su inventario, clientes, proveedores y cuentas corrientes, ventas online, y mucho mas.', $this->b, 'L', false);

		$this->printLine();

		$this->imgLink('https://pinocholibreriayjugueteria.com/img/icon.0ec2ed5f.png');
		$this->Cell(180, 15, 'https://pinocholibreriayjugueteria.com', $this->b, 1, 'L');
        
        $this->imgCheck();
		$this->Cell(190, $line_height, 'Tienda online para un negocio de libreria y jugueteria en Granadero Baigorria.', $this->b, 1, 'L');

		$this->printLine();

		$this->imgLink('https://scrapfree.com.ar/img/logo.41ed89fc.png');
		$this->Cell(180, 15, 'https://scrapfree.com.ar', $this->b, 1, 'L');
        
        $this->imgCheck();
		$this->Cell(190, $line_height, 'Aplicacion web para empresa nacional liquidadora de seguros.', $this->b, 1, 'L');
		
		$this->printLine();

		$this->imgLink('https://juntosporeldesarrollo.com.ar/img/icon.0e66825d.png');
		$this->Cell(180, 15, 'https://juntosporeldesarrollo.com.ar', $this->b, 1, 'L');
        $this->imgCheck();
		$this->Cell(190, $line_height, 'Sitio web para el partido politico "Juntos por el desarrollo" de la provincia de Entre Rios.', $this->b, 1, 'L');

		$this->printLine();

		$this->imgLink('https://partidolibertarioentrerios.com/img/logo.f3b55c4e.png');
		$this->Cell(180, 15, 'https://partidolibertarioentrerios.com', $this->b, 1, 'L');
        $this->imgCheck();
		$this->Cell(190, $line_height, 'Sitio web para el partido politico "Partido libertario" de la provincia de Entre Rios.', $this->b, 1, 'L');

		$this->printLine();

		$this->AddPage();

		$this->imgLink('https://fenix-mayorista.com.ar/img/icon.c48d046f.png');
		$this->Cell(180, 15, 'https://fenix-mayorista.com.ar', $this->b, 1, 'L');
        
        $this->imgCheck();
		$this->MultiCell(175, 5, 'Tienda online para distribuidora mayorista en Rosario. (Precios solo para clientes registrados).', $this->b, 'L', false);

		$this->printLine();

		$this->imgLink('https://refrigeracion-colman.com.ar/img/icon.6a37d1b0.png');
		$this->Cell(180, 15, 'https://refrigeracion-colman.com.ar', $this->b, 1, 'L');
        
        $this->imgCheck();
		$this->MultiCell(175, 5, 'Tienda online para distribuidora mayorista en Gualeguay. (Precios solo para clientes registrados).', $this->b, 'L', false);

		$this->printLine();

	}

	function imgLink($url = 'https://cdn-icons-png.flaticon.com/512/5602/5602732.png') {
        $this->Image($url, 10, $this->y+.5, 15, 15);
        // $this->Image('https://cdn-icons-png.flaticon.com/512/5602/5602732.png', 10, $this->y+.5, 7, 7);
        $this->x = 30;
	}

	function imgCheck() {
		$this->y += 3;
        $this->Image('https://upload.wikimedia.org/wikipedia/commons/thumb/c/c6/Sign-check-icon.png/800px-Sign-check-icon.png', 14, $this->y+.5, 7, 7);
        $this->x = 30;
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
		$this->y += 4;
		$this->Line(10, $this->y, 200, $this->y);
		$this->y += 4;
	}

}