<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\Helpers\StringHelper;
use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\PdfArticleHelper;
use App\Http\Controllers\Helpers\PdfPrintArticles;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\Client;
use App\Models\Impression;
use App\Models\Sale;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

// Este se usa para las notas de credito
class AfipTicketPdf extends fpdf {

	function __construct($sale, $current_acount = null) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);

		if (is_null($sale)) {
			$this->model = $current_acount;
			$this->sale = $current_acount->sale;
			$this->is_nota_credito = true;
        	$this->afip_helper = new AfipHelper($this->sale, $current_acount->articles);
		} else {
			$this->model = $sale;
			$this->sale = $sale;
			$this->is_nota_credito = false;
        	$this->afip_helper = new AfipHelper($this->sale);
		}
		$this->afip_information = $this->sale->afip_information;

		$this->borders = 'B';
        $this->user = UserHelper::getFullModel();

		$widths = [];
		$widths['codigo'] = 23;
		$widths['producto'] = 66;
		// $widths['producto'] = 50;
		$widths['cantidad'] = 17;
		// $widths['unidad_medida'] = 16;
		$widths['bonif'] = 15;
		$widths['iva'] = 14;
		$widths['subtotal_con_iva'] = 25;
		$widths['subtotal_final'] = 45;

		if ($this->model->afip_ticket->cbte_letra == 'A') {
			$widths['precio_unitario'] = 20;
			$widths['subtotal'] = 20;
		} else {
			$widths['precio_unitario'] = 35;
			$widths['subtotal'] = 40;
		}

		$this->widths = $widths;

		// Se setean los magenes
		$this->margins = (210 - array_sum($widths)) / 2;

        $this->articulos_en_esta_pagina = 0;
        $this->suma_costos_pagina = 0;
        $this->suma_precios_pagina = 0;
        
        $this->articulos_en_esta_venta = 0;

        $this->cantidad_articulos_de_esta_venta = 0;

		$this->num_page = 0;
		$this->Y = 220;
		$this->printArticles();
	}

	function printArticles() {
		$user = Auth()->user();
    	$this->AddPage();

		$this->cantidad_articulos_de_esta_venta = count($this->model->articles);

        foreach ($this->model->articles as $article) {
            $this->addArticle($article);
        }	
		$this->printPieDePagina();
        
        $this->Output();
        exit;
	}

	function addArticle($article) {
		$this->sumarCostosYPrecios($article);
		$this->sumarCantidadDeArticulos();
        $this->printArticle($article);
	}

	function printPieDePagina() {
        $this->printDiscounts();
        $this->printPaymentMethodDiscounts();
        $this->printImportes();
        $this->printQR();
        $this->printAfipData();
	}

	function printPaymentMethodDiscounts() {
		foreach ($this->sale->current_acount_payment_methods as $payment_method) {
			if (!is_null($payment_method->pivot->discount_amount)) {

				$this->SetFont('Arial', 'I', 9);

				$this->x = 5;
				$text = 'Descuento '.$payment_method->name.' de $'.Numbers::price($payment_method->pivot->discount_amount);
				$this->Cell(100, 5, $text, 0, 1, 'L');

			}
		}
	}

	function printDiscounts() {
		if (count($this->sale->discounts) >= 1) {
			$this->setX(5);
			$this->y += 3;
			$aclaracion = 'En la columna subtotal de cada articulo, se estan aplicando los descuentos de la venta';
			$this->Cell(200, 7, $aclaracion, 1, 1, 'C');
			$this->y += 2;
			$this->setX(5);
			$this->SetFont('Arial', 'B', 9);
			$this->Cell(30, 5, 'Descuentos', 1, 0, 'L');
			foreach ($this->sale->discounts as $discount) {
				// $this->x += 20;
				$this->Cell(30, 5, $discount->name.' '.$discount->pivot->percentage.'%', 1, 0, 'L');
			}
			$this->y += 5;
		}
	}

	function printImportes() {
		$importes = $this->afip_helper->getImportes();
		$this->setX(5);
		$this->y += 5;
		$this->SetFont('Arial', 'B', 9);
		if ($this->model->afip_ticket->cbte_letra == 'A') {
			$this->Cell(60, 5, 'Importe Neto Gravado: $'.Numbers::price($importes['gravado']), 1, 0, 'L');

			foreach ($importes['ivas'] as $iva => $importe) {
				if ($importe['Importe'] > 0) {
					$this->Cell(40, 5, 'IVA '.$iva.'%: $'.Numbers::price($importe['Importe']), 1, 0, 'L');
				}
			}
		} 
		$this->Cell(50, 5, 'Importe Total: $'.Numbers::price($importes['total']), 1, 0, 'L');
	}

	function printAfipData() {
		// Page
		$this->y += 12;
		$this->x = 55;
		$this->Cell(100, 5, 'Pág. '.$this->PageNo(), 0, 0, 'C');
		// Cae
		$this->y += 5;
		$this->x = 105;
		$this->SetFont('Arial', 'B', 10);
		$this->Cell(50, 5, 'CAE N°:', 0, 0, 'R');
		$this->SetFont('Arial', '', 10);
		$this->Cell(50, 5, $this->model->afip_ticket->cae, 0, 0, 'L');
		// Cae vencimiento
		$this->y += 5;
		$this->x = 105;
		$this->SetFont('Arial', 'B', 10);
		$this->Cell(50, 5, 'Fecha de Vto. de CAE:', 0, 0, 'R');
		$this->SetFont('Arial', '', 10);
		$this->Cell(50, 5, $this->model->afip_ticket->cae_expired_at, 0, 0, 'L');
	}

	function printQR() {
		$start_y = $this->y;
		$this->y += 7;
		$data = [
			'ver' 			=> 1,
			'fecha' 		=> date_format($this->model->afip_ticket->created_at, 'Y-m-d'),
			'cuit' 			=> $this->model->afip_ticket->cuit_negocio,
			'ptoVta' 		=> $this->model->afip_ticket->punto_venta,
			'tipoCmp' 		=> $this->model->afip_ticket->cbte_tipo,
			'nroCmp' 		=> $this->model->afip_ticket->cbte_numero,
			'importe' 		=> $this->model->afip_ticket->importe_total,
			'moneda' 		=> $this->model->afip_ticket->moneda_id,
			'ctz' 			=> 1,
			'tipoDocRec' 	=> AfipHelper::getDocType('Cuit'),
			'nroDocRec' 	=> $this->model->afip_ticket->cuit_cliente,
			'codAut' 		=> $this->model->afip_ticket->cae,
		];
		$afip_link = 'https://www.afip.gob.ar/fe/qr/?'.base64_encode(json_encode($data));
		$url = "http://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=$afip_link&.png";
		if (GeneralHelper::file_exists_2($url)) {
        	$this->Image($url, 0, $this->y, 50);
		}

        // $start_y =+ 10;
        // dd($start_y);
        // $this->y = $start_y;
        $this->Image(public_path().'/afip/logo.png', 45, $start_y+15, 40);
        $this->x = 45;

        $this->y += 20;
        $this->SetFont('Arial', 'BI', 10);
		$this->Cell(50, 5, 'Comprobante Autorizado', 0, 0, 'L');
        $this->SetFont('Arial', '', 7);
        $this->x = 45;
		$this->y += 5;
		$this->Cell(150, 5, 'Esta Administración Federal no se responsabiliza por los datos ingresados en el detalle de la operación', 0, 0, 'L');
		$this->y = $start_y;
	}

	function getCost($article) {
		if (!is_null($article->pivot->cost)) {
			return $article->pivot->cost;
		}
		return $article->cost;
	}

	function sumarCostosYPrecios($article) {
        $this->suma_costos_pagina += $this->getCost($article) * $article->pivot->amount;
        $this->suma_precios_pagina += $article->pivot->price * $article->pivot->amount;
	}

	function sumarCantidadDeArticulos() {
        $this->articulos_en_esta_pagina++;
        $this->articulos_en_esta_venta++;
	}

	function setArticleConf() {
        $this->setFont('Arial', '', 12);
        $this->SetDrawColor(51,51,51);
		$this->SetLineWidth(.4);
        $this->SetX(5);
	}
	
	function printArticle($article) {
	    $this->SetArticleConf();
    	$this->setFont('Arial', '', 8);
        $this->Cell($this->widths['codigo'], 6, StringHelper::short($article->bar_code, 14), 0, 0, 'L');
        $this->Cell($this->widths['producto'], 6, StringHelper::short($article->name, 30), 0, 0, 'L');
        $this->Cell($this->widths['cantidad'], 6, $article->pivot->amount, 0, 0, 'R');
        // $this->Cell($this->widths['unidad_medida'], 6, 'unidad', 0, 0, 'C');
        $this->Cell($this->widths['precio_unitario'], 6, Numbers::price($this->afip_helper->getArticlePrice($this->sale, $article)), 0, 0, 'R');

        $this->Cell($this->widths['bonif'], 6, $article->pivot->discount, 0, 0, 'R');
        $this->Cell($this->widths['subtotal'], 6, Numbers::price($this->afip_helper->subTotal($article)), 0, 0, 'R');
		if ($this->model->afip_ticket->cbte_letra == 'A') {
        	$this->Cell($this->widths['iva'], 6, $article->iva->percentage, 0, 0, 'C');
        	$this->Cell($this->widths['subtotal_con_iva'], 6, $this->subtotalConIva($article), 0, 0, 'R');
		}
        $this->y += 6;
    }

	function Header() {
		$this->SetXY(5, 5);
		$this->printTicketCommerceInfo();
		$this->printClientInfo();
		$this->printTableHeader();
	}

	function subtotalConIva($article) {
		$this->afip_helper->article = $article;		
		return $this->afip_helper->getArticlePriceWithDiscounts() * $article->pivot->amount;
	}

	function printTicketCommerceInfo() {
		$this->SetFont('Arial', 'B', 14, 'C');
		$this->printCbteTipo();
		$this->printCommerceInfo();
		$this->printAfipTicketInfo();
		$this->printCommerceLines();
	}

	function printClientInfo() {
		// Cuit
		$this->SetY(43);
		$this->SetX(6);
		$this->SetFont('Arial', 'B', 8);
		$this->Cell(10, 5, 'DOC:',0,0,'L');
		$this->SetFont('Arial', '', 8);
		$this->Cell(20, 5, $this->model->afip_ticket->cuit_cliente, 0, 1, 'C');
		// Iva
		$this->SetX(6);
		$this->SetFont('Arial', 'B', 8);
		$this->Cell(37, 5, 'Condición frente al IVA:', 0, 0, 'L');
		$this->SetFont('Arial', '', 8);
		if ($this->model->afip_ticket->iva_cliente != '') {
			$this->Cell(50, 5, 'IVA '.$this->model->afip_ticket->iva_cliente, 0, 1, 'L');
		} else {
			$this->Cell(50, 5, 'IVA consumidor final', 0, 1, 'L');
		}

		$this->SetX(6);
		$this->SetFont('Arial', 'B', 8);
		$this->Cell(32, 5, 'Condición de venta:', 0, 0, 'L');
		$this->SetFont('Arial', '', 8);
		$this->Cell(50, 5,  $this->getPaymentMethod(), 0, 1, 'L');

		// Razon social
		if (!is_null($this->model->client)) {
			$this->SetY(43);
			$this->SetX(80);
			$this->SetFont('Arial', 'B', 8);
			$this->Cell(47, 5, 'Apellido y Nombre / Razón Social:', 0, 0, 'L');
			$this->SetFont('Arial', '', 8);
			$this->Cell(60, 5, $this->model->client->razon_social, 0, 1, 'L');
			$this->SetX(97);
			$this->SetFont('Arial', 'B', 8);
			$this->Cell(30, 5, 'Domicilio Comercial:', 0, 0, 'L');
			$this->SetFont('Arial', '', 8);
			$this->Cell(60, 5, $this->model->client->address, 0, 1, 'L');
		}
		$this->printClientLines();
	}

	function printCbteTipo() {
		$this->SetY(5);
		$this->SetX(97);
		$this->SetFont('Arial', 'B', 20);
		$this->Cell(16,16, $this->model->afip_ticket->cbte_letra,1,0,'C');
		$this->SetY(16);
		$this->SetX(97);
		$this->SetFont('Arial', 'B', 9);
		$this->Cell(16,5,'COD. '.$this->model->afip_ticket->cbte_tipo,0,0,'C');
	}

	function printCommerceInfo() {
		// Razon social
		$this->SetY(9);
		$this->SetX(6);
		// $this->SetFont('Arial', 'B', 9);
		// $this->Cell(23,12,'Razón Social:',0,0,'L');
		$this->SetFont('Arial', 'B', 20);
	    $this->MultiCell( 
			90, 
			6, 
			$this->afip_information->razon_social, 
	    	0, 
	    	'L', 
	    	false
	    );

		$this->SetY(22);
		// Domicilio
		$this->SetX(6);
		$this->SetFont('Arial', 'B', 9);
		$this->Cell(35,5,'Domicilio Comercial:',0,0,'L');
		$this->SetFont('Arial', '', 9);
		$this->Cell(50,5,$this->afip_information->domicilio_comercial,0,1,'L');
		// Iva
		$this->SetX(6);
		$this->SetFont('Arial', 'B', 9);
		$this->Cell(40,5,'Condición frente al IVA:',0,0,'L');
		$this->SetFont('Arial', 'B', 9);
		// $this->Cell(50,5,'IVA '.Auth()->user()->iva->name,0,0,'L');
		$this->Cell(50,5,'IVA '.$this->model->afip_ticket->iva_negocio,0,1,'L');
		
		// Inicio actividades
		if ($this->afip_information->inicio_actividades != '') {
			$this->SetX(6);
			$this->SetFont('Arial', 'B', 9);
			$this->Cell(52,5,'Fecha de Inicio de Actividades:', 0, 0,'L');
			$this->Cell(25,5,date_format($this->afip_information->inicio_actividades, 'd/m/Y'), 0, 1,'L');
		}
	}

	function printAfipTicketInfo() {
		// Titulo factura
		$this->SetY(7);
		$this->SetX(118);
		$this->SetFont('Arial', 'B', 18);
		if ($this->model->afip_ticket->cbte_tipo)
		
		$this->Cell(35, 10, $this->getTitle(), 0, 1, 'L');
		// Punto de venta y numero de cbte
		$this->SetX(118);
		$this->SetFont('Arial', 'B', 9);
		$this->Cell(27,5,'Punto de Venta:',0,0,'L');
		$this->Cell(15,5,$this->getPuntoVenta(),0,0,'L');
		$this->Cell(21,5,'Comp. Nro:',0,0,'L');
		$this->Cell(27,5,$this->getNumCbte(),0,1,'L');
		// Fecha 
		$this->SetX(118);
		$this->SetFont('Arial', 'B', 9);
		$this->Cell(32,5,'Fecha de Emisión:',0,0,'L');
		$this->Cell(20,5,date_format($this->model->afip_ticket->created_at, 'd/m/Y'),0,1,'L');
		// Cuit 
		$this->SetX(118);
		$this->SetFont('Arial', 'B', 9);
		$this->Cell(12,5,'CUIT:',0,0,'L');
		$this->Cell(25,5,$this->model->afip_ticket->cuit_negocio, 0, 1,'L');

		// Ingresos brutos 
		$this->SetX(118);
		$this->SetFont('Arial', 'B', 9);
		$this->Cell(30,5,'Ingresos Brutos:', 0, 0,'L');
		$this->Cell(25,5,$this->afip_information->ingresos_brutos, 0, 1,'L');
	}

	function printCommerceLines() {
		$this->SetLineWidth(.3);
		// Arriba
		$this->Line(5, 5, 205, 5);
		// Abajo
		$this->Line(5, 40, 205, 40);
		// Izquierda
		$this->Line(5, 5, 5, 40);
		// Derecha
		$this->Line(205, 5, 205, 40);
		// Centro
		$this->Line(105, 21, 105, 40);
	}

	function printClientLines() {
		$this->SetLineWidth(.3);
		// Arriba
		$this->Line(5, 42, 205, 42);
		// Abajo
		$this->Line(5, 58, 205, 58);
		// Izquierda
		$this->Line(5, 42, 5, 58);
		// Derecha
		$this->Line(205, 42, 205, 58);
	}

	function printTableHeader() {
		$this->SetY(60);
		$this->SetX(5);
		$this->SetFont('Arial', 'B', 9, 'L');
		$this->Cell($this->widths['codigo'], 5, 'Código', 1, 0, 'L');
		$this->Cell($this->widths['producto'], 5, 'Producto / Servicio', 1, 0, 'L');
		$this->Cell($this->widths['cantidad'], 5, 'Cantidad', 1, 0, 'C');
		// $this->Cell($this->widths['unidad_medida'], 5, 'U.medida', 1, 0, 'L');
		$this->Cell($this->widths['precio_unitario'], 5, 'Precio Unit', 1, 0, 'C');
		$this->Cell($this->widths['bonif'], 5, '% Bonif', 1, 0, 'L');
		$this->Cell($this->widths['subtotal'], 5, 'Subtotal', 1, 0, 'C');
		if ($this->model->afip_ticket->cbte_letra == 'A') {
			$this->Cell($this->widths['iva'], 5, 'IVA', 1, 0, 'C');
			$this->Cell($this->widths['subtotal_con_iva'], 5, 'Subtotal c/IVA', 1, 0, 'C');
		}

		// Se dibuja la linea celeste que separa el thead del tbody
		$this->SetLineWidth(.6);
		$this->y += 5;
	}

	function getPuntoVenta() {
		$letras_faltantes = 5 - strlen($this->model->afip_ticket->punto_venta);
		$punto_venta = '';
		for ($i=0; $i < $letras_faltantes; $i++) { 
			$punto_venta .= '0'; 
		}
		$punto_venta  .= $this->model->afip_ticket->punto_venta;
		return $punto_venta;
	}

	function getNumCbte() {
		$letras_faltantes = 8 - strlen($this->model->afip_ticket->cbte_numero);
		$cbte_numero = '';
		for ($i=0; $i < $letras_faltantes; $i++) { 
			$cbte_numero .= '0'; 
		}
		$cbte_numero  .= $this->model->afip_ticket->cbte_numero;
		return $cbte_numero;
	}

	function getPaymentMethod() {
		if (!is_null($this->model->current_acount_payment_method)) {
			return $this->model->current_acount_payment_method->name; 
		}
		return 'Contado';
	}

	function Footer() {
		$this->SetFont('Arial', '', 11);
		$this->AliasNbPages();
		$this->SetY(-30);
		// $this->Write(5,'Hoja '.$this->num_PageNo().'/{nb}');
	}

	function getTitle() {
		$title = '';
		if ($this->model->afip_ticket->cbte_tipo == 3 || $this->model->afip_ticket->cbte_tipo == 8 || $this->model->afip_ticket->cbte_tipo == 13) {
			$title = 'Nota de Credito';
		} else if ($this->model->afip_ticket->cbte_tipo == 203 || $this->model->afip_ticket->cbte_tipo == 208 || $this->model->afip_ticket->cbte_tipo == 213) {
			$title = 'Nota de Credito FCE';
		} else if ($this->model->afip_ticket->cbte_tipo == 1 || $this->model->afip_ticket->cbte_tipo == 6 || $this->model->afip_ticket->cbte_tipo == 11) {
			$title = 'Factura';
		} else if ($this->model->afip_ticket->cbte_tipo == 201 || $this->model->afip_ticket->cbte_tipo == 206 || $this->model->afip_ticket->cbte_tipo == 211) {
			$title = 'Factura FCE';
		} 
		return $title;
	}

}