<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\ImageHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class SalePdf extends fpdf {

	function __construct($sale, $user, $with_prices, $with_costs, $precios_netos, $save_doc_as = false) {
		parent::__construct();
		$this->SetAutoPageBreak(false);
		$this->start_x = 5;
		$this->b = 0;
		$this->line_height = 5;
		$this->table_header_line_height = 7;
		
		$this->sale = $sale;
		
		$this->user = $user;

        $this->afip_helper = new AfipHelper($this->sale, null, null, $user);

		$this->with_prices = $with_prices;
		$this->with_costs = $with_costs;
		$this->precios_netos = $precios_netos;
		$this->total_sale = 0;

		// Sumar descuentos y recargos
		// $this->total_sale = SaleHelper::getTotalSale($this->sale, false, false);
		$this->total_articles = 0;
		$this->total_services = 0;
		$this->total_combos = 0;
		$this->total_promocion_vinotecas = 0;

		$this->AddPage();
		$this->setTotales();
		$this->items();
		$this->observations();

		if ($save_doc_as) {
        	$this->Output('F', storage_path().'/app/public/oscar-pdf/'.$save_doc_as, true);
		} else {
        	$this->Output();
        	exit;
		}
	}


	function getFields() {
		$fields = [
			'#' 		=> 5,
			'Num' 		=> 15,
			'Codigo' 	=> 35,
			'Nombre' 	=> 55,
			'Cant' 		=> 15,
		];

		if ($this->with_costs) {
			$fields = array_merge($fields, [
				'Costo' 	=> 20,
			]);
			$fields['Nombre'] = 35;
		}

		if ($this->with_prices) {
			$fields = array_merge($fields, [
				'Precio' 	=> 22,
				'Des' 		=> 13,
				'T Des' 	=> 15,
				'Sub total' => 25,
			]);


			if ($this->user->id == 700) {
				$fields['Nombre'] = 35;
				$fields['Caja'] = 20;
				$fields['Sub total'] = 25;
			}

		}
		if (!$this->with_costs && !$this->with_prices) {
			$fields['Nombre'] = 120;
			$fields['Cant'] = 25;
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
			[
				'text' 	=> 'Cuit',
				'key'	=> 'cuit',
			],
		];
	}

	function get_title() {
		if ($this->precios_netos) {
			return 'Venta Pre netos';
		}
		return null;
	}

	function Header() {
		$data = [
			'num' 				=> $this->sale->num,
			'date'				=> $this->sale->created_at,
			'title' 			=> $this->get_title(),
			'model_info'		=> $this->sale->client,
			'model_props' 		=> $this->getModelProps(),
			'fields' 			=> $this->getFields(),
			'address'			=> $this->get_address(),
		];

		if (!is_null($this->sale->client) && !is_null($this->sale->client->description) && !$this->with_prices) {
			$data = array_merge($data, [
				'client_description' 	=> true,
				'client'			=> $this->sale->client,
			]);
		} else if (!is_null($this->sale->client) && $this->sale->save_current_acount && !is_null($this->sale->current_acount) && $this->with_prices) {
			$data = array_merge($data, [
				'current_acount' 	=> $this->sale->current_acount,
				'client_id'			=> $this->sale->client_id,
				'compra_actual'		=> SaleHelper::getTotalSale($this->sale),
			]);
		}


		if (
			UserHelper::hasExtencion('vendedor_en_sale_pdf')
			&& $this->sale->employee
		) {
			
			$data['extra_info'] = [
				'Vendedor'	=> $this->sale->employee->name
			];
		}

		PdfHelper::header($this, $data);
		return;
	}

	function get_address() {
		
		$address = $this->sale->address;
		$address_text = null;

		if (!is_null($address)) {

			$address_text = "{$address->street} {$address->street_number}, {$address->city}, {$address->province}";

		}

		return $address_text;
	}
 
	function Footer() {
		$this->print_numero_orden_de_compra();
		if ($this->with_prices) {
			$this->total();
			$this->totalCosts();
			
			$this->discounts();
			$this->surchages();
			
			$this->descuento();

			$this->saleType();
			if ($this->with_costs) {
				$this->commissions();
			}
			$this->totalFinal();


		} else {

			// $this->total_final();
		}
		// PdfHelper::comerciocityInfo($this, $this->y);
	}

	function firma_entrega_en_pdf_ventas() {
		if (UserHelper::hasExtencion('firma_entrega_en_pdf_ventas', $this->user)) {

			$this->SetFont('Arial', '', 11);

			$this->x = 5;

			$this->Cell(
				200, 
				10, 
				'Nombre y apellido: _______________________________________________________________', 
				$this->b, 
				1, 
				'L'
			);

			$this->x = 5;
			$this->Cell(
				200, 
				10, 
				'DNI: __________________________________________________________________________', 
				$this->b, 
				1, 
				'L'
			);

			$this->x = 5;
			$this->Cell(
				200, 
				10, 
				'Firma: _________________________________________________________________________', 
				$this->b, 
				1, 
				'L'
			);

			$this->x = 5;
			$this->Cell(
				200, 
				10, 
				'Fecha de entrega: ________________________________________________________________', 
				$this->b, 
				1, 
				'L'
			);
		}
	}

	function print_numero_orden_de_compra() {
		if (!is_null($this->sale->numero_orden_de_compra)) {

			$this->SetFont('Arial', 'B', 11);

			$this->Cell(
				200, 
				10, 
				'N° Orden de compra: '.$this->sale->numero_orden_de_compra, 
				$this->b, 
				1, 
				'L'
			);
		}
	}

	function items() {
		$this->SetFont('Arial', '', 10);
		$this->SetLineWidth(.1);
		$index = 1;
		
		foreach ($this->sale->articles as $article) {
			// $this->total_articles += $this->get_price($article);
			$this->printItem($index, $article);
			$this->total_sale += $this->sub_total($article);
			$index++;
		}
		
		foreach ($this->sale->combos as $combo) {
			$this->printItem($index, $combo);
			$this->total_sale += $this->sub_total($combo);
			$index++;
		}
		
		foreach ($this->sale->promocion_vinotecas as $promocion_vinoteca) {
			$this->printItem($index, $promocion_vinoteca);
			$this->total_sale += $this->sub_total($promocion_vinoteca);
			$index++;
		}
		
		foreach ($this->sale->services as $service) {
			// $this->total_services += $this->get_price($service);
			$this->printItem($index, $service);
			$this->total_sale += $this->sub_total($service);
			$index++;
		}
	}

	function observations() {
		if (!is_null($this->sale->observations)) {
			$this->SetFont('Arial', '', 14);
		    $this->y += 5;
		    $this->x = 5;

			$this->Cell(
				200, 
				7, 
				'Observaciones', 
				$this->b, 
				1, 
				'L'
			);

		    $this->y += 2;
			$this->SetFont('Arial', '', 10);
		    $this->x = 5;
		    $this->MultiCell( 
				200, 
				5, 
				$this->sale->observations, 
		    	$this->b, 
		    	'L', 
		    	false
		    );
		    $this->y += 2;
		}
	}

	function setTotales() {
		foreach ($this->sale->articles as $article) {
			$this->total_articles += $this->sub_total($article);
			// $this->tal_articles += SaleHelper::getTotalItem($article);
		}
		foreach ($this->sale->services as $service) {
			$this->total_services += $this->sub_total($service);
			// $this->total_services += SaleHelper::getTotalItem($service);
		}
		foreach ($this->sale->combos as $combo) {
			$this->total_combos += $this->sub_total($combo);
		}
		foreach ($this->sale->promocion_vinotecas as $promocion_vinoteca) {
			$this->total_promocion_vinotecas += $this->sub_total($promocion_vinoteca);
		}
	}

	function printItem($index, $item) {
		if ($this->y >= 230) {
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
			$item->id, 
			$this->b, 
			0, 
			'C'
		);

		$codigo = $item->bar_code;
		if (
			UserHelper::hasExtencion('no_usar_codigos_de_barra', $this->user)
			|| UserHelper::hasExtencion('codigo_proveedor_en_vender', $this->user)
		) {
			$codigo = $item->provider_code;
		}

		$this->Cell(
			$this->getFields()['Codigo'], 
			$this->line_height, 
			$codigo, 
			$this->b, 
			0, 
			'C'
		);

		$y_1 = $this->y;
	    $this->MultiCell( 
			$this->getFields()['Nombre'], 
			$this->line_height, 
			GeneralHelper::article_name($item), 
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
			Numbers::price($item->pivot->amount), 
			$this->b, 
			0, 
			'C'
		);
		if ($this->with_costs) {
			$this->Cell(
				$this->getFields()['Costo'], 
				$this->line_height, 
				'$'.$item->pivot->cost, 
				$this->b, 
				0, 
				'C'
			);
		}
		if ($this->with_prices) {
			$this->Cell(
				$this->getFields()['Precio'], 
				$this->line_height, 
				'$'.Numbers::price($this->get_price($item)), 
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

			$price_descuento = '';
			if ($item->pivot->discount) {
				$descuento = $item->pivot->price * $item->pivot->discount / 100;  
				$price_descuento = $descuento * $item->pivot->amount;
				$price_descuento = Numbers::price($price_descuento);
			}

			$this->Cell(
				$this->getFields()['T Des'], 
				$this->line_height, 
				$price_descuento, 
				$this->b, 
				0, 
				'C'
			);

			$this->Cell(
				$this->getFields()['Sub total'], 
				$this->line_height, 
				'$'.Numbers::price($this->sub_total($item)),
				$this->b, 
				0, 
				'C'
			);

			if (
				isset($this->getFields()['Caja'])
				&& $item->unidades_por_bulto
			) {
				$this->Cell(
					$this->getFields()['Caja'], 
					$this->line_height, 
					$this->truncar_a_dos_decimales($item->pivot->amount / $item->unidades_por_bulto),
					$this->b, 
					0, 
					'C'
				);	
			}
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

	function truncar_a_dos_decimales($numero) {
	    return floor($numero * 100) / 100;
	}

	function sub_total($item) {

        $amount = $item->pivot->amount;
        
        $total = $this->get_price($item) * $amount;
        if (!is_null($item->pivot->discount)) {
            $total -= $total * ($item->pivot->discount / 100);
        }
        return $total;
	}

	function get_price($item) {
		if ($this->precios_netos && $this->is_article($item)) {
			return $this->afip_helper->getArticlePrice($this->sale, $item, true);
		} else {
			return $item->pivot->price;
		}
	}

	function is_article($item) {
		return isset($item->bar_code);
	}

	function commissions() {
		if (count($this->sale->seller_commissions) >= 1) {
	    	$this->SetX(5);
	    	$this->SetFont('Arial', '', 9);
	    	$this->Cell(65,5,'Comisiones: ', 1, 1, 'L');
	    	foreach ($this->sale->seller_commissions as $commission) {
		    	$this->SetX(5);
		    	$this->Cell(40,5,$commission->seller->name . ' ' . $commission->percentage . '%', 1, 0, 'L');
		    	$this->Cell(25,5, '$'.Numbers::price($commission->debe), 1 ,1,'L');
	    	}
			// $this->Y += 5;
 			// $this->SetY($this->Y);
	    	// $this->SetX(5);
		    // 	$this->Cell(50,5, 'Total: ','B',0,'L');
		    // $this->Cell(50,5, '$'.$this->getTotalMenosComisiones() ,'B',0,'L');
		}
	}

	function saleType() {
		if (count($this->user->sale_types) >= 1 && !is_null($this->sale->sale_type)) {
	    	$this->SetX(5);
	    	$this->SetFont('Arial', '', 9);
	    	$this->Cell(65,5,'Tipo de venta: '.$this->sale->sale_type->name, 0, 1, 'L');
		}
	}

	function total() {
	    $this->x = $this->start_x;
	    // $this->y = 247;
	    $this->SetFont('Arial', 'B', 12);
		
		$text = 'Total: $'. Numbers::price(SaleHelper::getTotalSale($this->sale, false, false, false));
		
		if ($this->sale->moneda_id == 2) {
			$text .= ' (USD)';
		}

		$this->Cell(
			200,
			7,
			$text,

			/*
				* Se motraba el total que habia hasta esta pagina, no el total real de la venta.
				* Lo camio porque me pidio Oscar
			*/
			// 'Total: $'. Numbers::price($this->total_sale),
			$this->b,
			1,
			'R'
		);
	}

	function totalCosts() {
		if ($this->with_costs) {
		    $this->x = $this->start_x;
		    $this->SetFont('Arial', 'B', 12);
			$this->Cell(
				100,
				7,
				'Costos: $'. Numbers::price(SaleHelper::getTotalCostSale($this->sale)),
				$this->b,
				1,
				'L'
			);
		}
	}

	function descuento() {
		if (
			$this->sale->descuento
			&& $this->sale->descuento > 0
		) {
			$text = '- Descuento del '.$this->sale->descuento.'%';

		    $total_articles = $this->total_articles;
		    $total_services = $this->total_services;
		    $total_combos = $this->total_combos;
		    $total_promocion_vinotecas = $this->total_promocion_vinotecas;

	    	$total = $total_articles + $total_services + $total_combos + $total_promocion_vinotecas;
	    	$total -= $total * $this->sale->descuento / 100;

	    	$text .= ' = $'.Numbers::price($total);

	    	$this->x = 5;
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

	function discounts() {
		if (count($this->sale->discounts) >= 1) {
		    $this->SetFont('Arial', 'B', 11);

		    $total_descuento = 0;

		    foreach ($this->sale->discounts as $discount) {
		    	$this->x = $this->start_x;
		    	$text = '-'.$discount->pivot->percentage.'% '.$discount->name;
		    	
		    	$total_descuento += $this->total_articles * floatval($discount->pivot->percentage) / 100;

		    	$total_descuento += $this->total_combos * floatval($discount->pivot->percentage) / 100;

		    	$total_descuento += $this->total_promocion_vinotecas * floatval($discount->pivot->percentage) / 100;

		    	if ($this->sale->discounts_in_services) {
		    		
		    		$total_descuento += $this->total_services * floatval($discount->pivot->percentage) / 100;
		    	}

		    	// $total_with_discounts = $this->total_articles + $this->total_services + $this->total_combos + $this->total_promocion_vinotecas;

		    	$text .= ' = '.Numbers::price($total_descuento, true, $this->sale->moneda_id);

				$this->Cell(
					50, 
					5, 
					$text, 
					$this->b, 
					1, 
					'L'
				);
		    }
		    if (count($this->sale->services) > 0) {
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
		    // $total_articles = $this->total_articles;
		    // $total_services = $this->total_services;
		    // $total_combos = $this->total_combos;
		    // dd($total_combos);

		    $total_recargo = 0;

		    foreach ($this->sale->surchages as $surchage) {
		    	$this->x = $this->start_x;
		    	$text = '+'.$surchage->pivot->percentage.'% '.$surchage->name;

		    	
		    	$total_recargo += $this->total_articles * floatval($surchage->pivot->percentage) / 100;

		    	$total_recargo += $this->total_combos * floatval($surchage->pivot->percentage) / 100;

		    	$total_recargo += $this->total_promocion_vinotecas * floatval($surchage->pivot->percentage) / 100;

		    	if ($this->sale->discounts_in_services) {
		    		
		    		$total_recargo += $this->total_services * floatval($surchage->pivot->percentage) / 100;
		    	}

		    	// $total_with_discounts = $this->total_articles + $this->total_services + $this->total_combos + $this->total_promocion_vinotecas;

		    	$text .= ' = $'.Numbers::price($total_recargo);

				$this->Cell(
					50, 
					5, 
					$text, 
					$this->b, 
					1, 
					'L'
				);

		    }
		    if (count($this->sale->services) > 0) {
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

	function total_final() {

    	$this->SetFont('Arial', 'B', 12);
    	$this->x = 5;
    	$this->y += 2;
	    $this->Cell(
			50, 
			5, 
			'Total: $'.Numbers::price($this->sale->total), 
			$this->b, 
			1, 
			'L'
		);
	}

	function totalFinal() {
		if (
				(
					count($this->sale->discounts) >= 1 
					|| count($this->sale->surchages) >= 1 
					|| (
					    count($this->sale->seller_commissions) >= 1
					    && $this->with_costs
					)
					|| $this->sale->descuento > 0
				) 
				&& !$this->precios_netos
			) {

	    	$this->SetFont('Arial', 'B', 12);
	    	$this->x = 5;
	    	$this->y += 2;
		    $this->Cell(
				50, 
				5, 
				'Total final: $'.Numbers::price(SaleHelper::getTotalSale($this->sale, true, true, false), true, $this->sale->moneda_id), 
				$this->b, 
				1, 
				'L'
			);
			if ($this->with_costs && count($this->sale->seller_commissions) >= 1) {
	    		$this->x = 5;
			    $this->Cell(
					50, 
					5, 
					'Total menos comisiones: $'.Numbers::price(SaleHelper::getTotalSale($this->sale, true, true, true)), 
					$this->b, 
					1, 
					'L'
				);
			}
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