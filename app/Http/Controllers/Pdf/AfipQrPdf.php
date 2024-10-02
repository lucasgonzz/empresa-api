<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\AfipHelper;

class AfipQrPdf {

	function __construct($instance, $sale, $from_ticket = false) {
		$this->instance = $instance;
		$this->sale = $sale;
		$this->from_ticket = $from_ticket;
		$this->img_width = 50;

    	if ($this->from_ticket) {

        	$this->afip_logo_width = 20;
        	$this->afip_logo_height = 5;
			$this->img_start_x = $this->img_centrada($this->instance, $this->img_width);
			$this->afip_img_start_x = $this->img_centrada($this->instance, $this->img_width);

    	} else {

        	$this->afip_logo_width = 40;
        	$this->afip_logo_height = 10;
			$this->img_start_x = 5;
			$this->afip_img_start_x = 60;
    	}

	}


	function printQR() {

		$this->qr();

		$this->logo_afip();

		$this->instance->y += 5;
	}

	function qr() {

		$start_y = $this->instance->y;

		$this->instance->y += 7;

		$data = [
			'ver' 			=> 1,
			'fecha' 		=> date_format($this->sale->afip_ticket->created_at, 'Y-m-d'),
			'cuit' 			=> $this->sale->afip_ticket->cuit_negocio,
			'ptoVta' 		=> $this->sale->afip_ticket->punto_venta,
			'tipoCmp' 		=> $this->sale->afip_ticket->cbte_tipo,
			'nroCmp' 		=> $this->sale->afip_ticket->cbte_numero,
			'importe' 		=> $this->sale->afip_ticket->importe_total,
			'moneda' 		=> $this->sale->afip_ticket->moneda_id,
			'ctz' 			=> 1,
			'tipoDocRec' 	=> AfipHelper::getDocType('Cuit'),
			'nroDocRec' 	=> $this->sale->afip_ticket->cuit_cliente,
			'codAut' 		=> $this->sale->afip_ticket->cae,
		];

		$afip_link = 'https://www.afip.gob.ar/fe/qr/?'.base64_encode(json_encode($data));
		 
		$url = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=$afip_link&format=jpeg#.jpg";

		if (GeneralHelper::file_exists_2($url)) {

        	$this->instance->Image($url, $this->img_start_x, $this->instance->y, $this->img_width);

		}
        
        if ($this->from_ticket) {
	        $x_position = 10;
	        $this->instance->y += 52;
        	$width = 50;

        	$x_afip_logo = 10;
        	$y_afip_logo = $start_y + 50;
        } else {
        	$width = 150;
	        $x_position = 45;
	        $this->instance->y += 20;
        	$x_afip_logo = 45;
        	$y_afip_logo = $start_y + 15;
        }

        $this->instance->x = $x_position;
	}

	function logo_afip() {

    	$img_url = public_path().'/afip/logo.png';
        
        $this->instance->Image($img_url, $this->afip_img_start_x, $this->instance->y, $this->afip_logo_width);

        $this->instance->y += $this->afip_logo_height;

        $this->instance->x = $this->afip_img_start_x;

        $this->instance->SetFont('Arial', 'BI', 10);
		$this->instance->Cell(100, 5, 'Comprobante Autorizado', 0, 0, 'L');
        $this->instance->SetFont('Arial', '', 7);

	}

	function img_centrada($instance) {
		return ($this->instance->ancho - $this->img_width) / 2;
	}

}