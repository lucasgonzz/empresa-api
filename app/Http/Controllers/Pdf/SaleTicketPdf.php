<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\sale\SalePdfHelper;
use App\Http\Controllers\Pdf\Afip\AfipPdfHelper;
use App\Http\Controllers\Pdf\AfipQrPdf;
use App\Models\AfipInformation;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class SaleTicketPdf extends fpdf {

	function __construct($sale, $afip_ticket = null) {
		$this->line_height = 5;
		$this->user = UserHelper::getFullModel();
		$this->sale = $sale;
		$this->afip_ticket = $afip_ticket;

		$this->x_incial = 4;

		$this->ancho = $this->user->sale_ticket_width;
		$this->cell_ancho = $this->ancho - 8;

		// Tamaño de fuente configurable del nombre del articulo, con fallback al default historico (12)
		$name_size = $this->user->sale_ticket_name_font_size ? (int) $this->user->sale_ticket_name_font_size : 12;
		// Tamaño de fuente configurable del precio (unitario y total), con fallback al default historico (10)
		$price_size = $this->user->sale_ticket_price_font_size ? (int) $this->user->sale_ticket_price_font_size : 10;
		// Clamp geometrico: evita que tamaños extremos rompan el layout del ticket angosto
		$this->name_font_size = max(8, min(16, $name_size));
		$this->price_font_size = max(8, min(14, $price_size));
		parent::__construct('P', 'mm', [$this->ancho, $this->getPdfHeight()]);
		$this->SetAutoPageBreak(false);
		$this->b = 0;

		$this->AddPage();
		$this->items();
        $this->Output();
        exit;
	}

	function Header() {
		$this->logo();
		$this->afipInformation();
		$this->clientInfo();
		$this->num();
		$this->date();
		$this->address();
	}

	function afipInformation() {

		if (!$this->afip_ticket) return;

		$afip_information = $this->afip_ticket->afip_information;

		if (!is_null($afip_information)) {
			$this->SetFont('Arial', '', 10);
			$this->x = $this->x_incial;
			$this->Cell($this->cell_ancho, 5, 'IVA: '.$afip_information->iva_condition->name, $this->b, 1, 'L');
			$this->x = $this->x_incial;
			$this->Cell($this->cell_ancho, 5, 'Cuit: '.$afip_information->cuit, $this->b, 1, 'L');

			$this->x = $this->x_incial;
			$this->Cell($this->cell_ancho, 5, 'Razon social: '.$afip_information->razon_social, $this->b, 1, 'L');
			
			$this->SetFont('Arial', 'B', 10);
			$this->x = $this->x_incial;
			$this->Cell($this->cell_ancho, 5, 'Punto de venta: '.$afip_information->punto_venta, $this->b, 1, 'L');
			$this->x = $this->x_incial;
			$this->Cell($this->cell_ancho, 5, 'N° comprobante: '.$this->afip_ticket->cbte_numero, $this->b, 1, 'L');
			$this->x = $this->x_incial;
			$this->Cell($this->cell_ancho, 5, 'CAE: '.$this->afip_ticket->cae, $this->b, 1, 'L');
			$this->x = $this->x_incial;
			$this->Cell($this->cell_ancho, 5, 'Vto cae: '.$this->getCaeExpiredAt(), $this->b, 1, 'L');

			$this->tipo_de_comprobante();

		}
	}

	function tipo_de_comprobante() {

		$this->SetFillColor(0,0,0);
		$this->SetTextColor(255,255,255);
		$this->SetFont('Arial', '', 10);

		$this->x = $this->x_incial;
		$this->Cell($this->cell_ancho, 6, 'Tipo comprobante: '.$this->afip_ticket->cbte_letra, $this->b, 1, 'C', 1);

		$this->SetTextColor(0,0,0);
	}

	function clientInfo() {

		// Sin cliente y sin factura no hay nada que mostrar en esta seccion (comportamiento historico).
		if (!$this->sale->client && !$this->afip_ticket) {
			return;
		}

		$this->x = $this->x_incial;
		$this->SetFont('Arial', 'B', 8);
		$this->Cell($this->cell_ancho, 4, 'CLIENTE', $this->b, 1, 'L');

		if ($this->sale->client) {
			$this->x = $this->x_incial;
			$this->SetFont('Arial', '', 10);
			$this->Cell($this->cell_ancho, 5, $this->sale->client->name, $this->b, 1, 'L');

			if (!is_null($this->sale->client->address)) {
				$this->x = $this->x_incial;
				$this->SetFont('Arial', '', 9);
				$this->MultiCell($this->cell_ancho, 5, $this->sale->client->address, $this->b, 'L', 0);
			}

			if (!is_null($this->sale->client->phone) && $this->sale->client->phone != '') {
				$this->x = $this->x_incial;
				$this->SetFont('Arial', '', 9);
				$this->Cell($this->cell_ancho, 5, 'Tel: '.$this->sale->client->phone, $this->b, 1, 'L');
			}

			if (
				$this->afip_ticket
				&& $this->afip_ticket->iva_cliente != ''
			) {
				$this->x = $this->x_incial;
				$this->SetFont('Arial', '', 9);
				$this->Cell($this->cell_ancho, 5, 'IVA '.$this->afip_ticket->iva_cliente, $this->b, 1, 'L');
			}

		} else {
			$this->x = $this->x_incial;
			$this->SetFont('Arial', '', 10);
			$this->Cell($this->cell_ancho, 5, 'Consumidor final', $this->b, 1, 'L');
		}

		$this->y += 1;
		$this->dividerLine();
		$this->y += 2;
	}

	function getCaeExpiredAt() {
		$date = $this->afip_ticket->cae_expired_at;
		return substr($date, 0, 11);
		return substr($date, 0, 4).'/'.substr($this->afip_ticket->cae_expired_at, 4, 2).'/'.substr($date, 6, 8);
	}

	function Footer() {

		$this->observations();

		$this->total_sale = $this->sale->sub_total;

		$this->total_sin_des_rec();

		$this->discounts();

		$this->surchages();

		$this->payment_method_discounts();

		$this->total();

		$this->ticket_description();
		
		$this->qr();

		// $this->comerciocityInfo();
	}

	function observations() {
		if ($this->sale->observations) {

			$this->y += 2;
			$this->SetFont('Arial', 'B', 10);

			$this->x = $this->x_incial;
			$this->Cell($this->cell_ancho, 5, 'Observaciones:', $this->b, 1, 'L');
	
			$this->SetFont('Arial', '', 10);
			$this->x = $this->x_incial;
			$this->MultiCell($this->cell_ancho, 5, $this->sale->observations, $this->b, 'L', 0);
		}
	}

	/**
	 * Header del ticket: el logo ocupa toda la primera fila, al 60% del ancho util y
	 * centrado horizontalmente. La info del negocio (nombre, domicilio, telefono) va
	 * debajo, centrada, y una linea fina cierra la seccion antes de los datos AFIP/cliente.
	 * @return void
	 */
	function logo() {
		$logo_w = $this->cell_ancho * 0.6;
		$logo_h = $this->getLogoHeight($logo_w);
		$logo_x = $this->x_incial + ($this->cell_ancho - $logo_w) / 2;

		// Logo de la sucursal de la venta, con caida al del negocio (tarea 17).
		$logo_url = AfipPdfHelper::resolve_logo_url($this->sale->address, $this->user);

		if (!is_null($logo_url)) {
			if (config('app.APP_ENV') == 'local') {
				$this->Image('https://img.freepik.com/vector-gratis/fondo-plantilla-logo_1390-55.jpg', $logo_x, $this->x_incial, $logo_w, $logo_h);
			} else {
				$this->Image($logo_url, $logo_x, $this->x_incial, $logo_w, $logo_h);
			}
			$this->y = $this->x_incial + $logo_h + 3;
		} else {
			$this->y = $this->x_incial;
		}

		$this->SetFont('Arial', 'B', 13);
		$this->x = $this->x_incial;
		$this->MultiCell($this->cell_ancho, 6, $this->user->company_name, $this->b, 'C', 0);

		$domicilio = null;
		if (
			$this->afip_ticket
			&& !is_null($this->afip_ticket->afip_information)
		) {
			$domicilio = $this->afip_ticket->afip_information->domicilio_comercial;
		} else {
			$punto_venta = AfipInformation::where('user_id', $this->user->id)
										->first();

			if ($punto_venta) {
				$domicilio = $punto_venta->domicilio_comercial;
			}
		}

		$this->SetFont('Arial', '', 9);
		if ($domicilio) {
			$this->x = $this->x_incial;
			$this->MultiCell($this->cell_ancho, 5, $domicilio, $this->b, 'C', 0);
		}

		$phone = $this->user->phone;
		if (
			$this->sale->address
			&& $this->sale->address->phone
		) {
			$phone = $this->sale->address->phone;
		}

		$this->x = $this->x_incial;
		$this->SetFont('Arial', '', 9);
		$this->Cell($this->cell_ancho, 5, 'Tel: '.$phone, $this->b, 1, 'C');

		$this->y += 2;
		$this->dividerLine();
		$this->y += 2;
	}

	/**
	 * Calcula el alto (en mm) que va a ocupar el logo, manteniendo su proporcion real.
	 * Usa getimagesize() sobre la imagen resuelta; si falla (URL no accesible, formato
	 * invalido, etc.) cae a un fallback cuadrado para no romper ni el render ni el
	 * calculo de altura del PDF (getPdfHeight).
	 * @param float $logo_w ancho en mm que va a ocupar el logo
	 * @return float alto estimado del logo en mm
	 */
	function getLogoHeight($logo_w) {
		// Fallback por default: logo cuadrado, igual que el comportamiento historico
		$logo_h = $logo_w;

		$logo_url = AfipPdfHelper::resolve_logo_url($this->sale->address, $this->user);

		if (!is_null($logo_url) && config('app.APP_ENV') != 'local') {
			// @ para no romper el render si la imagen remota no esta disponible
			$dim = @getimagesize($logo_url);

			if ($dim && !empty($dim[0]) && !empty($dim[1])) {
				$logo_h = $logo_w * $dim[1] / $dim[0];
			}
		}

		return $logo_h;
	}

	/**
	 * Linea fina de separacion entre secciones del ticket (header, cliente, items),
	 * usando el mismo ancho util que el resto del contenido.
	 * @return void
	 */
	function dividerLine() {
		$this->SetLineWidth(.2);
		$this->SetDrawColor(200, 200, 200);
		$this->Line($this->x_incial, $this->y, $this->x_incial + $this->cell_ancho, $this->y);
		$this->SetDrawColor(0, 0, 0);
	}

	function items() {
		$this->x = $this->x_incial;
		$this->y += 2;

		$ancho_description = 50 * $this->cell_ancho / 100; 

		$ancho_price = $this->cell_ancho - $ancho_description; 
		
		if ($this->ancho > 60) {

			$ancho_price = $ancho_price / 2;
		}

		// Fila de encabezado de columnas (Producto / Precio / Total), alineada con los anchos y x reales de los items
		$this->itemsHeader($ancho_description, $ancho_price);

		foreach ($this->sale->combos as $combo) {
			$this->SetFont('Arial', '', 9);

			$y_1 = $this->y;
			$this->MultiCell($ancho_description, $this->line_height, $combo->name.' ('.$combo->pivot->amount.')', 'LTB', 'L', 0);
			$y_2 = $this->y;
			$this->x = $ancho_description + 2;
			$this->y = $y_1;
			
			$this->SetFont('Arial', 'B', 9);
			$this->Cell($ancho_price, $y_2 - $y_1, '$'.Numbers::Price($combo->pivot->price * $combo->pivot->amount), 'RTB', 0, 'R');

			$this->y = $y_2;
			
			$this->comboArticles($combo);
			$this->x = $this->x_incial;
		}

		foreach ($this->sale->articles as $article) {
			$this->SetFont('Arial', '', $this->name_font_size);
			$y_1 = $this->y;
			$this->MultiCell($ancho_description, $this->line_height, GeneralHelper::article_name($article)." ({$article->pivot->amount})", 'BT', 'L', 0);
			$y_2 = $this->y;

			$this->x = $ancho_description + 2;
			$this->y = $y_1;

			$this->SetFont('Arial', 'B', $this->price_font_size);

			if ($this->ancho > 60) {


			$total_item = $article->pivot->price;

			if ($article->pivot->discount) {
				$total_item -= $total_item * (float)$article->pivot->discount / 100; 
			}

				$this->Cell($ancho_price, $y_2 - $y_1, '$'.Numbers::Price($total_item), 'BT', 0, 'R');
			}

			$this->Cell($ancho_price, $y_2 - $y_1, $this->totalItem($article), 'BT', 0, 'R');
			
			$this->x = $this->x_incial;
			$this->y = $y_2;
		}

		foreach ($this->sale->promocion_vinotecas as $promo) {
			$this->SetFont('Arial', '', $this->name_font_size);
			$y_1 = $this->y;
			$this->MultiCell($ancho_description, $this->line_height, $promo->name." ({$promo->pivot->amount})", 'BT', 'L', 0);
			$y_2 = $this->y;

			$this->x = $ancho_description + 2;
			$this->y = $y_1;

			$this->SetFont('Arial', 'B', $this->price_font_size);

			if ($this->ancho > 60) {

				$this->Cell($ancho_price, $y_2 - $y_1, '$'.Numbers::Price($promo->pivot->price), 'BT', 0, 'R');
			}

			$this->Cell($ancho_price, $y_2 - $y_1, $this->totalItem($promo), 'BT', 0, 'R');
			
			$this->x = $this->x_incial;
			$this->y = $y_2;
		}

		foreach ($this->sale->services as $service) {
			$this->SetFont('Arial', '', $this->name_font_size);
			$y_1 = $this->y;
			$this->MultiCell($ancho_description, $this->line_height, $service->name." ({$service->pivot->amount})", 'BT', 'L', 0);
			$y_2 = $this->y;

			$this->x = $ancho_description + 2;
			$this->y = $y_1;

			$this->SetFont('Arial', 'B', $this->price_font_size);

			if ($this->ancho > 60) {

				$this->Cell($ancho_price, $y_2 - $y_1, '$'.Numbers::Price($service->pivot->price), 'BT', 0, 'R');
			}

			$this->Cell($ancho_price, $y_2 - $y_1, $this->totalItem($service), 'BT', 0, 'R');
			
			$this->x = $this->x_incial;
			$this->y = $y_2;
		}
	}

	/**
	 * Dibuja la fila de encabezado de columnas (Producto / Precio / Total, o Producto / Total en tickets angostos)
	 * usando exactamente los mismos anchos y posiciones x que las filas de items, para que queden alineadas.
	 * @param float $ancho_description ancho de la columna "Producto"
	 * @param float $ancho_price ancho de cada columna de precio ("Precio" y/o "Total")
	 * @return void
	 */
	function itemsHeader($ancho_description, $ancho_price) {
		// Tamaño de fuente del encabezado: el de precio, pero acotado a 9 para que no compita visualmente con los items
		$this->SetFont('Arial', 'B', min($this->price_font_size, 9));
		// Fondo gris claro + borde arriba y abajo, a modo de banda de encabezado
		$this->SetFillColor(235, 235, 235);

		$this->x = $this->x_incial;
		$this->Cell($ancho_description, $this->line_height, 'Producto', 'TB', 0, 'L', 1);

		// Forzar posicionamiento absoluto de x antes de las celdas de precio/total, igual que hacen
		// los 3 loops de items() (articles, promocion_vinotecas, services), para que el encabezado
		// quede alineado con los valores y no dependa del avance natural de celdas de FPDF
		$this->x = $ancho_description + 2;

		if ($this->ancho > 60) {
			$this->Cell($ancho_price, $this->line_height, 'Precio', 'TB', 0, 'R', 1);
		}

		$this->Cell($ancho_price, $this->line_height, 'Total', 'TB', 1, 'R', 1);

		// Restaurar color de relleno y texto normal para que no afecte al resto del render
		$this->SetFillColor(255, 255, 255);
		$this->SetTextColor(0, 0, 0);
		$this->x = $this->x_incial;
	}

	function totalItem($item) {
		$total = $item->pivot->price * $item->pivot->amount;

		if ($item->pivot->discount) {
			$total -= $total * (float)$item->pivot->discount / 100; 
		}

		return '$'.Numbers::Price($total);
	}

	function comboArticles($combo) {
		$this->SetFont('Arial', '', 9);

		foreach ($combo->articles as $article) {
			$this->x = 6;
			$this->MultiCell(50, $this->line_height, GeneralHelper::article_name($article).' ('.$article->pivot->amount.')', 'LR', 'L', 0);
		}
	}

	function total_sin_des_rec() {
		if (count($this->sale->discounts) >= 1 || count($this->sale->surchages) >= 1) {
		    $this->x = $this->x_incial;
		    $this->SetFont('Arial', 'B', 10);
			$this->Cell($this->cell_ancho, 7, 'Total $'.Numbers::price($this->total_sale), 'B', 1, 'R');
		}
	}

	function discounts() {
	    $this->SetFont('Arial', 'B', 10);
	    foreach ($this->sale->discounts as $discount) {
	    	$this->x = $this->x_incial;
	    	$this->total_sale -= $this->total_sale * (float)$discount->pivot->percentage / 100;
			$this->Cell($this->cell_ancho / 2, 7, 'Desc '. $discount->pivot->percentage.'%', 'B', 0, 'L');
			$this->Cell($this->cell_ancho / 2, 7, '$'.Numbers::price($this->total_sale), 'B', 1, 'R');
	    }
	}

	function payment_method_discounts() {
	    $this->SetFont('Arial', 'B', 10);
	    foreach ($this->sale->current_acount_payment_methods as $payment_method) {
	    	if (!is_null($payment_method->pivot->discount_amount)) {
		    	$this->x = $this->x_incial;
		    	$this->total_sale -= (float)$payment_method->pivot->discount_amount;
				$this->Cell($this->cell_ancho / 2, 7, 'Desc $'. Numbers::price($payment_method->pivot->discount_amount).' '.substr($payment_method->name, 0, 3), 'B', 0, 'L');
				$this->Cell($this->cell_ancho / 2, 7, '$'.Numbers::price($this->total_sale), 'B', 1, 'R');
	    	}
	    }
	}

	function surchages() {
	    $this->SetFont('Arial', 'B', 10);
	    foreach ($this->sale->surchages as $surchage) {
	    	$this->total_sale += $this->total_sale * (float)$surchage->pivot->percentage / 100;
	    	
	    	$this->x = $this->x_incial;
			$this->Cell($this->cell_ancho / 2, 7, 'Rec '. $surchage->pivot->percentage.'%', 'B', 0, 'L');
			$this->Cell($this->cell_ancho / 2, 7, '$'.Numbers::price($this->total_sale), 'B', 1, 'R');
	    }
	}

	function total() {
		$total_sale = $this->total_sale;

		if (!is_null($this->sale->total)) {

			$total_sale = $this->sale->total;
		}

		if (!is_null($this->sale->total) && (int)$this->sale->total != (int)$this->total_sale) {

			$this->SetFont('Arial', '', 9);
		    $this->x = $this->x_incial;
			$this->Cell($this->cell_ancho, 6, 'Total sin descuentos: $'. Numbers::price($this->total_sale), 0, 1, 'R');
		}

		$this->iva_discriminado();

		$this->y += 2;

		// Banda destacada para el total final, en el mismo lenguaje visual que "Tipo comprobante"
		$this->SetFillColor(0, 0, 0);
		$this->SetTextColor(255, 255, 255);
		$this->SetFont('Arial', 'B', 14);
	    $this->x = $this->x_incial;
		$this->Cell($this->cell_ancho, 10, 'TOTAL: $'. Numbers::price($total_sale), $this->b, 0, 'C', 1);
		$this->SetTextColor(0, 0, 0);
		$this->y += 10;
	}

	function iva_discriminado() {

		if (!is_null($this->afip_ticket)) {

			$this->SetFont('Arial', '', 10);

			$this->y += 5;
        	
        	$afip_helper = new AfipHelper($this->afip_ticket);

			$importes = $afip_helper->getImportes();

		    $this->x = $this->x_incial;
			$this->Cell(60, 5, 'Imp Neto Gravado: $'.Numbers::price($importes['gravado']), 0, 1, 'L');

			foreach ($importes['ivas'] as $iva => $importe) {
				if ($importe['Importe'] > 0) {
		    		$this->x = $this->x_incial;
					$this->Cell(40, 5, 'IVA '.$iva.'%: $'.Numbers::price($importe['Importe']), 0, 1, 'L');
				}
			}
			$this->y += 5;
		}
	}

	function qr() {
		if (!is_null($this->afip_ticket)) {
			$pdf = new AfipQrPdf($this, $this->sale, true);
			$pdf->printQr();
		}
	}

	function ticket_description() {

		if (!is_null($this->user->sale_ticket_description)) {
		    $this->x = $this->x_incial;
		    $this->y += 2;
		    $this->SetFont('Arial', '', 10);

			$this->MultiCell($this->cell_ancho, 5, $this->user->sale_ticket_description, 0, 'L');
		}
	}

	function num() {
		if (is_null($this->afip_ticket)) {
		    $this->x = $this->x_incial;
		    $this->SetFont('Arial', '', 9);
			$this->Cell($this->cell_ancho, 5, 'Venta N° '.$this->sale->num, $this->b, 0, 'L');
			$this->y += 5;
		}
	}

	function date() {
		$date = date_format($this->sale->created_at, 'd/m/Y H:i');

		if ($this->afip_ticket) {
			$date = date_format($this->afip_ticket->created_at, 'd/m/Y H:i');
		}

	    $this->x = $this->x_incial;
	    $this->SetFont('Arial', '', 9);
		$this->Cell($this->cell_ancho, 5, $date, $this->b, 0, 'L');
		$this->y += 5;
	}
	
	function address() {
		if (!is_null($this->sale->address)) {
			$address = $this->sale->address->street.' '.$this->sale->address->street_number;
		    $this->x = $this->x_incial;
		    $this->SetFont('Arial', '', 9);
			$this->Cell($this->cell_ancho, 5, $address, $this->b, 1, 'L');
		}
	}

	function comerciocityInfo() {
	    $this->y = 290;
	    $this->x = 5;
	    $this->SetFont('Arial', '', 10);
		$this->Cell(200, 5, 'Comprobante creado con el sistema de control de stock ComercioCity - comerciocity.com', $this->b, 0, 'C');
	}

	function getHeight($item, $maximo_letas) {
    	$lines = 1;
    	$letras = strlen($item->name);
    	while ($letras > $maximo_letas) {
    		$lines++;
    		$letras -= $maximo_letas;
    	}
    	return $this->line_height * $lines;
	}

	function getPdfHeight() {
		$height = 120;
		if (!is_null($this->afip_ticket)) {
			$height += 120;
		}

		// El logo ocupa toda la primera fila (60% del ancho, centrado) y la info del negocio
		// va debajo: hay que reservar el alto del logo + un margen extra para esa info (~20mm)
		$height += $this->getLogoHeight($this->cell_ancho * 0.6) + 20;

		foreach ($this->sale->combos as $combo) {
			$height += $this->getHeight($combo, 20);
			foreach ($combo->articles as $article) {
				$height += $this->getHeight($article, 20);
			}
		}
		foreach ($this->sale->articles as $article) {
			// Se escala el aporte por linea segun la fuente configurada del nombre, para no cortar
			// contenido cuando el usuario sube el tamaño por encima del default historico (12)
			$height += $this->getHeight($article, 8) * ($this->name_font_size / 12);
		}

		if ($this->sale->observations) {
			$renglones = strlen($this->sale->observations) / 20;
			if ($renglones < 1) {
				$renglones = 1;
			}
			$height += $renglones * 5;
		}
		// $height +=
		return $height;
	}

}