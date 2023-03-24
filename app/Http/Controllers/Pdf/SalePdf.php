<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\ImageHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class SalePdf extends fpdf {

	function __construct($sale, $with_prices) {
		parent::__construct();
		$this->SetAutoPageBreak(false);
		$this->start_x = 5;
		$this->b = 0;
		$this->line_height = 5;
		$this->table_header_line_height = 7;
		
		$this->user = UserHelper::getFullModel();
		$this->sale = $sale;
		$this->with_prices = $with_prices;
		$this->total_sale = SaleHelper::getTotalSale($this->sale, false, false);
		$this->total_articles = 0;
		$this->total_services = 0;
		$this->AddPage();
		$this->items();

        $this->Output();
        exit;
	}


	function getFields() {
		$fields = [
			'#' 		=> 5,
			'Num' 		=> 15,
			'Codigo' 	=> 35,
			'Nombre' 	=> 65,
			'Cant' 		=> 15,
		];

		// dd($this->with_prices);
		if ($this->with_prices) {
			$fields = array_merge($fields, [
				'Precio' 	=> 25,
				'Des' 		=> 13,
				'Sub total' => 27,
			]);
		}
		return $fields;
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
				'text' 	=> 'Localidad',
				'key'	=> 'location.name',
			],
			[
				'text' 	=> 'Direccion',
				'key'	=> 'address',
			],
		];
	}

	function Header() {
		$data = [
			'num' 				=> $this->sale->num,
			'date'				=> $this->sale->created_at,
			'title' 			=> 'Venta',
			'model_info'		=> $this->sale->client,
			'model_props' 		=> $this->getModelProps(),
			'fields' 			=> $this->getFields(),
		];
		if (!is_null($this->sale->client) && $this->sale->save_current_acount && count($this->sale->current_acounts) >= 1) {
			$data = array_merge($data, [
				'current_acount' 	=> $this->sale->current_acounts[0],
				'client_id'			=> $this->sale->client_id,
				'compra_actual'		=> SaleHelper::getTotalSale($this->sale),
			]);
		}
		PdfHelper::header($this, $data);
		return;
	}

	function Footer() {
		if ($this->with_prices) {
			$this->total();
			$this->discounts();
			$this->surchages();
			$this->totalFinal();
		}
		PdfHelper::comerciocityInfo($this, $this->y);
	}

	function items() {
		$this->SetFont('Arial', '', 10);
		$this->SetLineWidth(.1);
		$index = 1;
		foreach ($this->sale->articles as $article) {
			$this->total_articles += SaleHelper::getTotalItem($article);
			$this->printItem($index, $article);
			$index++;
		}
		foreach ($this->sale->services as $service) {
			$this->total_services += SaleHelper::getTotalItem($service);
			$this->printItem($index, $service);
			$index++;
		}
	}

	function printItem($index, $item) {
		if ($this->y >= 245) {
			$this->AddPage();
		}
		$this->SetFont('Arial', '', 8);
		$this->x = 5;
		$this->Cell(
			$this->getFields()['#'], 
			$this->line_height, 
			$index, 
			$this->b, 
			0, 
			'C'
		);
		$this->Cell(
			$this->getFields()['Num'], 
			$this->line_height, 
			$item->num, 
			$this->b, 
			0, 
			'C'
		);
		$this->Cell(
			$this->getFields()['Codigo'], 
			$this->line_height, 
			$item->bar_code, 
			$this->b, 
			0, 
			'C'
		);
		$y_1 = $this->y;
	    $this->MultiCell( 
			$this->getFields()['Nombre'], 
			$this->line_height, 
			$item->name, 
	    	$this->b, 
	    	'L', 
	    	false
	    );
	    $y_2 = $this->y;
	    $this->y = $y_1;
	    $this->x = $this->start_x + $this->getFields()['#'] + $this->getFields()['Num'] + $this->getFields()['Codigo'] + $this->getFields()['Nombre'];
		$this->Cell(
			$this->getFields()['Cant'], 
			$this->line_height, 
			$item->pivot->amount, 
			$this->b, 
			0, 
			'C'
		);
		if ($this->with_prices) {
			$this->Cell(
				$this->getFields()['Precio'], 
				$this->line_height, 
				'$'.$item->pivot->price, 
				$this->b, 
				0, 
				'C'
			);
			$this->Cell(
				$this->getFields()['Des'], 
				$this->line_height, 
				$item->pivot->discount, 
				$this->b, 
				0, 
				'C'
			);
			$this->Cell(
				$this->getFields()['Sub total'], 
				$this->line_height, 
				'$'.SaleHelper::getTotalItem($item), 
				$this->b, 
				0, 
				'C'
			);
		}
		$this->x = $this->start_x;
		$this->y = $y_2;
		if ($this->with_prices) {
			$this->Line($this->start_x, $this->y, 210-$this->start_x, $this->y);
		} else {
			$width = 5 + $this->getFields()['#'] + $this->getFields()['Num'] + $this->getFields()['Codigo'] + $this->getFields()['Nombre'] + $this->getFields()['Cant'];
			$this->Line($this->start_x, $this->y, $width, $this->y);
		}
	}

	function tableHeader() {
		$this->x = $this->start_x;
		$this->y = 60;
		$this->SetFont('Arial', 'B', 12);
		$this->SetLineWidth(.5);
		$this->SetDrawColor(0,0,0);
		$this->Cell(
			$this->widths['#'], 
			$this->table_header_line_height, 
			'#', 
			'TB', 
			0, 
			'C'
		);
		$this->Cell(
			$this->widths['bar_code'], 
			$this->table_header_line_height, 
			'Codigo', 
			'TB', 
			0, 
			'C'
		);
		$this->Cell(
			$this->widths['name'], 
			$this->table_header_line_height, 
			'Nombre', 
			'TB', 
			0, 
			'C'
		);
		$this->Cell(
			$this->widths['price'], 
			$this->table_header_line_height, 
			'Precio', 
			'TB', 
			0, 
			'C'
		);
		$this->Cell(
			$this->widths['amount'], 
			$this->table_header_line_height, 
			'Cant', 
			'TB', 
			0, 
			'C'
		);
		$this->Cell(
			$this->widths['discount'], 
			$this->table_header_line_height, 
			'Des', 
			'TB', 
			0, 
			'C'
		);
		$this->Cell(
			$this->widths['sub_total'], 
			$this->table_header_line_height, 
			'SubTotal', 
			'TB', 
			0, 
			'C'
		);
		$this->x = $this->start_x;
		$this->y += $this->table_header_line_height;
	}

	function numSale() {
		$this->SetFont('Arial', 'B', 14);
		$this->x = 105;
		$this->y = 0;

		// Numero
		$this->Cell(50, 10, 'N° '.$this->sale->num_sale, $this->b, 0, 'L');
		$this->Cell(50, 10, date_format($this->sale->created_at, 'd/m/Y'), $this->b, 0, 'R');
		$this->y += 10;
	}

	function commerceInfo() {
		// Razon social
		if (!is_null($this->user->afip_information)) {
			if (!is_null($this->user->afip_information->razon_social)) {
				$this->x = 105;
				$this->SetFont('Arial', 'B', 10);
				$this->Cell(25, 5, 'Razon social: ', $this->b, 0, 'L');

				$this->SetFont('Arial', '', 10);
				$this->Cell(75, 5, $this->user->afip_information->razon_social, $this->b, 0, 'L');
			}
		}

		// Direccion
		$address = null;
		if (count($this->user->addresses) >= 1) {
			$address = $this->user->addresses[0];
		}
		if (!is_null($address)) {
			$this->x = 105;
			$this->y += 5;
			$this->SetFont('Arial', 'B', 10);
			$this->Cell(20, 5, 'Direccion: ', $this->b, 0, 'L');

			$address_text = "{$address->street} {$address->street_number}, {$address->city}, {$address->province}";
			$this->SetFont('Arial', '', 10);
			$this->Cell(80, 5, $address_text, $this->b, 0, 'L');
		}

		// Telefono
		if (!is_null($this->user->phone)) {
			$this->x = 105;
			$this->y += 5;
			$this->SetFont('Arial', 'B', 10);
			$this->Cell(18, 5, 'Telefono: ', $this->b, 0, 'L');

			$this->SetFont('Arial', '', 10);
			$this->Cell(82, 5, $this->user->phone, $this->b, 0, 'L');
		}

		// Sitio Web
		if (!is_null($this->user->online)) {
			$this->x = 105;
			$this->y += 5;
			$this->SetFont('Arial', 'B', 10);
			$this->Cell(20, 5, 'Sitio Web: ', $this->b, 0, 'L');

			$this->SetFont('Arial', '', 10);
			$this->Cell(80, 5, $this->user->online, $this->b, 0, 'L');
		}

		// Correo
		$this->x = 105;
		$this->y += 5;
		$this->SetFont('Arial', 'B', 10);
		$this->Cell(12, 5, 'Email:', $this->b, 0, 'L');
		$this->SetFont('Arial', '', 10);
		$this->Cell(88, 5, $this->user->email, $this->b, 0, 'L');
		$this->y += 10;
	}

	function total() {
	    $this->x = $this->start_x;
	    // $this->y = 247;
	    $this->SetFont('Arial', 'B', 12);
		$this->Cell(
			100,
			10,
			'Total: $'. Numbers::price($this->total_sale),
			$this->b,
			0,
			'L'
		);
		$this->y += 10;
	}

	function discounts() {
		if (count($this->sale->discounts) >= 1) {
		    $this->SetFont('Arial', '', 9);
		    $total_sale = $this->total_sale;
		    foreach ($this->sale->discounts as $discount) {
		    	$this->x = $this->start_x;
		    	$text = '-'.$discount->pivot->percentage.'% '.$discount->name;
		    	$this->total_articles -= $this->total_articles * floatval($discount->pivot->percentage) / 100;
		    	if ($this->sale->discounts_in_services) {
		    		$this->total_services -= $this->total_services * floatval($discount->pivot->percentage) / 100;
		    	}
		    	$total_with_discounts = $this->total_articles + $this->total_services;
		    	$text .= ' = $'.Numbers::price($total_with_discounts);
				$this->Cell(
					50, 
					5, 
					$text, 
					$this->b, 
					1, 
					'L'
				);
		    }
		    if ($this->total_services > 0) {
		    	$this->x = $this->start_x;
		    	if ($this->sale->discounts_in_services) {
		    		$text = 'Se aplican descuentos a los servicios';
		    	} else {
		    		$text = 'No se aplican descuentos a los servicios';
		    	}
	    		$this->Cell(
					50, 
					5, 
					$text, 
					$this->b, 
					1, 
					'L'
				);
		    } 
		}
	}

	function surchages() {
		if (count($this->sale->surchages) >= 1) {
		    $this->SetFont('Arial', '', 9);
		    $total_sale = SaleHelper::getTotalSale($this->sale, true, false);
		    foreach ($this->sale->surchages as $surchage) {
		    	$this->x = $this->start_x;
		    	$text = '+'.$surchage->pivot->percentage.'% '.$surchage->name;
		    	$this->total_articles += $this->total_articles * floatval($surchage->pivot->percentage) / 100;
		    	if ($this->sale->surchages_in_services) {
		    		$this->total_services += $this->total_services * floatval($surchage->pivot->percentage) / 100;
		    	}
		    	$total_with_surchages = $this->total_articles + $this->total_services;
		    	$text .= ' = $'.Numbers::price($total_with_surchages);
				$this->Cell(
					50, 
					5, 
					$text, 
					$this->b, 
					1, 
					'L'
				);
		    }
		    if ($this->total_services > 0) {
		    	$this->x = $this->start_x;
		    	if ($this->sale->surchages_in_services) {
		    		$text = 'Se aplican recargos a los servicios';
		    	} else {
		    		$text = 'No se aplican recargos a los servicios';
		    	}
	    		$this->Cell(
					50, 
					5, 
					$text, 
					$this->b, 
					1, 
					'L'
				);
		    } 
		}
	}

	function totalFinal() {
		if (count($this->sale->discounts) >= 1 || count($this->sale->surchages) >= 1) {
	    	$this->SetFont('Arial', 'B', 12);
	    	$this->x = 5;
		    $this->Cell(
				50, 
				10, 
				'Total: $'.Numbers::price(SaleHelper::getTotalSale($this->sale)), 
				$this->b, 
				1, 
				'L'
			);
		}
	}

	function afipTicket() {
		if (!is_null($this->sale->afip_ticket)) {
			dd($this->sale->afip_ticket);
		}
	}

	function comerciocityInfo() {
	    $this->y += 10;
	    $this->x = $this->start_x;
	    $this->SetFont('Arial', '', 8);
		$this->Cell(200, 5, 'Comprobante creado con el sistema de control de stock ComercioCity - comerciocity.com', $this->b, 0, 'C');
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