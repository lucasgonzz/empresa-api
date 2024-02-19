<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Helpers\AfipHelper;

class AfipQrPdf {

	static function printQR($instance, $sale, $from_ticket = false) {
		$start_y = $instance->y;
		$instance->y += 7;
		$data = [
			'ver' 			=> 1,
			'fecha' 		=> date_format($sale->afip_ticket->created_at, 'Y-m-d'),
			'cuit' 			=> $sale->afip_ticket->cuit_negocio,
			'ptoVta' 		=> $sale->afip_ticket->punto_venta,
			'tipoCmp' 		=> $sale->afip_ticket->cbte_tipo,
			'nroCmp' 		=> $sale->afip_ticket->cbte_numero,
			'importe' 		=> $sale->afip_ticket->importe_total,
			'moneda' 		=> $sale->afip_ticket->moneda_id,
			'ctz' 			=> 1,
			'tipoDocRec' 	=> AfipHelper::getDocType('Cuit'),
			'nroDocRec' 	=> $sale->afip_ticket->cuit_cliente,
			'codAut' 		=> $sale->afip_ticket->cae,
		];
		$afip_link = 'https://www.afip.gob.ar/fe/qr/?'.base64_encode(json_encode($data));
		$url = "http://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=$afip_link&.png";
        $instance->Image($url, 0, $instance->y, 50);

        // $start_y =+ 10;
        // dd($start_y);
        // $instance->y = $start_y;
        
        if ($from_ticket) {
	        $x_position = 10;
	        $instance->y += 50;
        	$width = 50;

        	$x_afip_logo = 10;
        	$y_afip_logo = $start_y + 50;
        	$afip_logo_width = 20;
        } else {
        	$width = 150;
	        $x_position = 45;
	        $instance->y += 20;
        	$x_afip_logo = 45;
        	$y_afip_logo = $start_y + 15;
        	$afip_logo_width = 40;
        }

        $instance->x = $x_position;

        $instance->Image(public_path().'/afip/logo.png', $x_afip_logo, $y_afip_logo, $afip_logo_width);

        $instance->SetFont('Arial', 'BI', 10);
		$instance->Cell(50, 5, 'Comprobante Autorizado', 0, 0, 'L');
        $instance->SetFont('Arial', '', 7);
        $instance->x = $x_position;
		$instance->y += 5;
        if (!$from_ticket) {
			$instance->Cell($width, 5, 'Esta Administración Federal no se responsabiliza por los datos ingresados en el detalle de la operación', 0, 0, 'L');
			$instance->y = $start_y;
        } 
	}

}