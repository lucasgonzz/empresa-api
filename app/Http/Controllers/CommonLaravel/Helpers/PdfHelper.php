<?php

namespace App\Http\Controllers\CommonLaravel\Helpers;

use App\Article;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PdfHelper {

	static function header($instance, $data) {
		Self::logo($instance);
		Self::title($instance, $data['title']);
		Self::numeroFecha($instance, $data);
		Self::commerceInfo($instance, $data);
		
		Self::commerceInfoLine($instance);

		Self::modelInfo($instance, $data);
		if (isset($data['current_acount'])) {
			Self::currentAcountInfo($instance, $data['current_acount'], $data['client_id'], $data['compra_actual']);
		}
		if (isset($data['fields'])) {
			Self::tableHeader($instance, $data['fields']);
		}
	}

	function simpleHeader($instance, $data) {
        $user = UserHelper::getFullModel();

		if ($user->header_articulos_pdf) {

			if (env('APP_ENV') == 'local') {
	    		$instance->Image('https://api.freelogodesign.org/assets/thumb/logo/ad95beb06c4e4958a08bf8ca8a278bad_400.png', 2, 2, 45, 45);
	    	} else {
	    		if (!is_null($user->image_url) && GeneralHelper::file_exists_2($user->image_url)) {
	    			$instance->Image($user->image_url, 2, 2, 45, 45);
	    		}
	    	}

			$instance->SetFont('Arial', 'B', 18);

			$instance->x = 50;
			$instance->y = 20;
			$instance->Cell(70, 8, $user->company_name, $instance->b, 0, 'L');	
			
			$instance->SetFont('Arial', '', 14);
			$instance->Cell(80, 8, date('d/m/Y'), $instance->b, 0, 'R');

			$instance->y = 50;
		} else {
			$instance->y = 2;
		}

		Self::tableHeader($instance, $data['fields']);
	}

	static function logo($instance) {
        // Logo
        $logo_url = UserHelper::getFullModel()->image_url;
        if (!is_null($logo_url)) {
        	if (env('APP_ENV') == 'local') {
        		$instance->Image('https://img.freepik.com/vector-gratis/fondo-plantilla-logo_1390-55.jpg', 5, 5, 40, 25);
        	} else if (GeneralHelper::file_exists_2($logo_url)) {
	        	$instance->Image($logo_url, 5, 5, 25, 25);
        	}
        }
		
		$instance->SetFont('Arial', 'B', 9);

		$user = UserHelper::getFullModel();
		// Razon social
		$instance->y = 5;
		
		$instance->x = 35;
		$instance->Cell(40, 5, $user->company_name, $instance->b, 1, 'L');	

		// Condicion IVA
		if (!is_null($user->afip_information) && !is_null($user->afip_information->iva_condition)) {
			$instance->x = 35;
			$instance->Cell(40, 5, $user->afip_information->iva_condition->name, $instance->b, 1, 'L');

			$instance->x = 35;
			$instance->Cell(40, 5, 'CUIT: '.$user->afip_information->cuit, $instance->b, 1, 'L');
		}

		// Sitio Web
		if (!is_null($user->online)) {
			$instance->x = 35;
			$instance->SetFont('Arial', 'B', 10);
			$instance->Cell(10, 5, 'Web: ', $instance->b, 0, 'L');

			$instance->SetFont('Arial', '', 10);
			$instance->Cell(50, 5, Self::getWebUrl($user->online), $instance->b, 1, 'L');
		} else {
			$instance->y += 5;
		}

		// Telefono
		if (!is_null($user->phone)) {
			$instance->x = 35;
			$instance->SetFont('Arial', 'B', 10);
			$instance->Cell(8, 5, 'Tel: ', $instance->b, 0, 'L');

			$instance->SetFont('Arial', '', 10);
			// $instance->Cell(50, 5, $user->phone, $instance->b, 1, 'L');

		    $instance->MultiCell(
				60, 
				5, 
				$user->phone, 
		    	$instance->b, 
		    	'L', 
		    	false
		    );
		}
		// $instance->Line(5, 5, 101, 5);
		// $instance->Line(101, 5, 101, 45);
		// $instance->Line(101, 45, 5, 45);
		// $instance->Line(5, 45, 5, 5);
	}

	static function firma($instance) {
		$instance->x = 75;
		$instance->y += 10;
		$instance->SetFont('Arial', '', 11);
		$instance->Cell(50, 7, 'Firma', 'T', 1, 'C');
	}

	static function numeroFecha($instance, $data) {
		$instance->SetFont('Arial', 'B', 14);
		$start_y = 5;
		$instance->y = 5;
		$instance->x = 120;
		// Numero
		if (isset($data['num'])) {
			$instance->Cell(40, 10, 'N° '.$data['num'], $instance->b, 0, 'L');
		}
		if (isset($data['date'])) {
			$instance->x = 160;
			$instance->Cell(45, 10, date_format($data['date'], 'd/m/Y'), $instance->b, 0, 'R');
		}
		if (isset($data['date_formated'])) {
			$instance->x = 160;
			$instance->Cell(45, 10, $data['date_formated'], $instance->b, 0, 'R');
		}
		$instance->y += 10;
		
		// Num pag
		$instance->SetFont('Arial', '', 10);
		$instance->x = 120;
		$instance->Cell(85, 5, 'Pag '.$instance->PageNo(), $instance->b, 0, 'R');
	}

	static function title($instance, $title) {
		
		if (is_null($title)) {
			$title = 'X';
			$instance->SetFont('Arial', 'B', 30);
			$height = 15;
		} else {
			$instance->SetFont('Arial', 'B', 12);
			$height = 5;
		}

		$start_y = 5;
		$start_x = 90;
		$width = 30;
		$instance->y = $start_y;
		$instance->x = $start_x;
	    $instance->MultiCell(
			$width, 
			$height, 
			$title, 
	    	$instance->b, 
	    	'C', 
	    	false
	    );
	    $finish_x = $start_x + $width;
	    $finish_y = $start_y + 15;
		$instance->Line($start_x, $start_y, $finish_x, $start_y);
		$instance->Line($finish_x, $start_y, $finish_x, $finish_y);
		$instance->Line($finish_x, $finish_y, $start_x, $finish_y);
		$instance->Line($start_x, $finish_y, $start_x, $start_y);
	}

	static function commerceInfo($instance, $data) {
		$user = UserHelper::getFullModel();
		$instance->y += 5;
		$start_y = $instance->y;

		// Direccion
		if (isset($data['address'])) {

			$instance->x = 105;
			$instance->SetFont('Arial', 'B', 10);
			$instance->Cell(12, 5, 'Direc: ', $instance->b, 0, 'L');

			$address_text = $data['address'];
			$instance->SetFont('Arial', '', 10);
			$instance->Cell(88, 5, $address_text, $instance->b, 0, 'L');

		} else if (count($user->addresses) >= 1) {
			$address = $user->addresses[0];
			$instance->x = 105;
			$instance->SetFont('Arial', 'B', 10);
			$instance->Cell(12, 5, 'Direc: ', $instance->b, 0, 'L');

			$address_text = "{$address->street} {$address->street_number}, {$address->city}, {$address->province}";
			$instance->SetFont('Arial', '', 10);
			$instance->Cell(88, 5, $address_text, $instance->b, 0, 'L');
		}

		// Correo
		$instance->x = 105;
		$instance->y += 5;
		$instance->SetFont('Arial', 'B', 10);
		$instance->Cell(12, 5, 'Email:', $instance->b, 0, 'L');
		$instance->SetFont('Arial', '', 10);
		$instance->Cell(88, 5, $user->email, $instance->b, 1, 'L');

		$instance->y += 2;
	}

	static function commerceInfoLine($instance) {
		$instance->Line(5, 5, 205, 5);
		$instance->Line(205, 5, 205, 30);
		$instance->Line(205, 30, 5, 30);
		$instance->Line(5, 30, 5, 5);
		$instance->Line(105, 20, 105, 30);
	}

	static function modelInfo($instance, $data) {
		if (isset($data['model_info']) && !is_null($data['model_info'])) {
		    $instance->x = 5;
		    $start_y = $instance->y;
		    $index = 1;
		    foreach ($data['model_props'] as $prop) {
		    	if ($index == 1) {
		    		$instance->SetFont('Arial', 'B', 10);
		    	} else {
		    		$instance->SetFont('Arial', '', 10);
		    	}

		    	if ($index > 4) {

		    		$instance->x = 55;
		    		$instance->y = $start_y + 5;
		    	} else {

		    		$instance->x = 5;
		    	}

		    	$index++;
				
				$instance->Cell(100, 5, $prop['text'].': '.Self::getPropValue($data['model_info'], $prop), $instance->b, 1, 'L');

				if ($index < 6) {

					$y_final = $instance->y; 
				}
		    }

		    $instance->y = $y_final;
		    
		    $instance->Line(5, $start_y, 105, $start_y);
		    $instance->Line(105, $start_y, 105, $y_final);
		    $instance->Line(105, $y_final, 5, $y_final);
		    $instance->Line(5, $y_final, 5, $start_y);
		}
	}

	static function getPropValue($model, $prop) {
		if (str_contains($prop['key'], '.')) {
			$relation = substr($prop['key'], 0, strpos($prop['key'], '.'));
			$key = substr($prop['key'], strpos($prop['key'], '.')+1, strlen($prop['key']));
			if (!is_null($model->{$relation})) {
				return $model->{$relation}->{$key};
			} 
			return '';
		}
		return $model->{$prop['key']};
	}

	static function comerciocityInfo($instance, $y = 290) {
	    $instance->y = $y;
	    $instance->y += 5;
	    $instance->x = 5;
	    $instance->SetFont('Arial', 'B', 8);

        $instance->Image(public_path().'/storage/logo.png', 175, $instance->y - 5, 23, 20);

		$instance->Cell(200, 5, 'Creado con la plataforma de gestion y automatizacion ComercioCity | ERP | Pagina web | MercadoLibre | comerciocity.com', $instance->b, 1, 'L');
	    
	    $instance->x = 5;
		$instance->Cell(200, 5, '¡Descuentos para los clientes de nuestros clientes!', $instance->b, 0, 'C');

		// Arriba
		$instance->Line(5, $instance->y-5, 205, $instance->y-5);
		// Derecha
		$instance->Line(205, $instance->y-5, 205, $instance->y+5);
		// Abjao
		$instance->Line(5, $instance->y+5, 205, $instance->y+5);
		// Izquierda
		$instance->Line(5, $instance->y+5, 5, $instance->y-5);
	}

	static function total($instance, $total) {
	    $instance->x = 155;
	    $instance->SetFont('Arial', 'B', 12);
		$instance->Cell(50, 10, 'Total: $'.Numbers::price($total), $instance->b, 1, 'R');
	}

	static function getWidthUntil($until_field, $fields, $start = 5) {
		foreach ($fields as $key => $value) {
			$start += $value;
			if ($key == $until_field) {
				break;
			}
		}
		return $start;
	}

	static function tableHeader($instance, $fields, $size = 12, $margen = 2) {
		$instance->SetFont('Arial', 'B', $size);
		$instance->x = 5;
		$instance->y += $margen;
		$instance->SetLineWidth(.4);
		foreach ($fields as $text => $width) {
			$instance->Cell($width, 7, $text, 1, 0, 'C');
		}
		$instance->y += 7;
		$instance->x = 5;
	}

	static function clientInfo($instance, $client) {
		if ($client) {
			$instance->SetFont('Arial', '', 10);
			$instance->x = 5;
			$instance->SetFont('Arial', 'B', 10);
			$instance->Cell(20, 5, 'Cliente:', $instance->b, 0, 'L');
			$instance->SetFont('Arial', '', 10);
			$instance->Cell(85, 5, $client->name, $instance->b, 0, 'L');
			$instance->y += 5;

			if ($client->address != '') {
				$instance->x = 5;
				$instance->SetFont('Arial', 'B', 10);
				$instance->Cell(20, 5, 'Direccion:', $instance->b, 0, 'L');
				$instance->SetFont('Arial', '', 10);
				$instance->Cell(80, 5, $client->address, $instance->b, 0, 'L');
			} 

			if ($client->phone != '') {
				$instance->y += 5;
				$instance->x = 5;
				$instance->SetFont('Arial', 'B', 10);
				$instance->Cell(20, 5, 'Telefono:', $instance->b, 0, 'L');
				$instance->SetFont('Arial', '', 10);
				$instance->Cell(80, 5, $client->phone, $instance->b, 0, 'L');
			} 

			if (!is_null($client->location)) {
				$instance->y += 5;
				$instance->x = 5;
				$instance->SetFont('Arial', 'B', 10);
				$instance->Cell(20, 5, 'Localidad:', $instance->b, 0, 'L');
				$instance->SetFont('Arial', '', 10);
				$instance->Cell(88, 5, $client->location->name, $instance->b, 0, 'L');
			}
		}
	}

	static function currentAcountInfo($instance, $current_acount, $client_id, $compra_actual, $start_y = 32){
		$saldo_anterior = CurrentAcountHelper::getSaldo('client', $client_id, $current_acount);
		$instance->y = $start_y;
		$instance->x = 105;
		$instance->SetFont('Arial', 'B', 10);
		$instance->Cell(30, 5, 'Saldo anterior:', $instance->b, 0, 'L');
		$instance->SetFont('Arial', '', 10);
		$instance->Cell(30, 5, '$'.Numbers::price($saldo_anterior), $instance->b, 1, 'L');

		$instance->x = 105;
		$instance->SetFont('Arial', 'B', 10);
		$instance->Cell(30, 5, 'Compra actual:', $instance->b, 0, 'L');
		$instance->SetFont('Arial', '', 10);
		$instance->Cell(30, 5, '$'.Numbers::price($compra_actual), $instance->b, 1, 'L');

		$instance->x = 105;
		$instance->SetFont('Arial', 'B', 10);
		$instance->Cell(30, 5, 'Saldo:', $instance->b, 0, 'L');
		$instance->SetFont('Arial', '', 10);
		$instance->Cell(30, 5, '$'.Numbers::price($saldo_anterior + $compra_actual), $instance->b, 1, 'L');

		if (!is_null($instance->sale->employee)) {
			$vendedor = $instance->sale->employee->name;
		} else {
			$vendedor = UserHelper::getFullModel()->name;
		}
		$instance->x = 105;
		$instance->SetFont('Arial', 'B', 10);
		$instance->Cell(30, 5, 'Vendedor:', $instance->b, 0, 'L');
		$instance->SetFont('Arial', '', 10);
		$instance->Cell(30, 5, $vendedor, $instance->b, 1, 'L');

		$instance->Line(105, $start_y, 205, $start_y);
		$instance->Line(205, $start_y, 205, $instance->y);
		$instance->Line(205, $instance->y, 105, $instance->y);
		// $instance->Line(105, $instance->y, 105, $start_y);
		// $instance->y += 50;
	}

	static function getWebUrl($url) {
		if (substr($url, 0, 8) == 'https://') {
			return substr($url, 8);
		}
		if (substr($url, 0, 7) == 'http://') {
			return substr($url, 7);
		}
		return $url;
	}



	/*
		Calculo el ancho maximo de la imagen, respetando sus dimensiones originales
	*/
	static function coordenadas_y_ancho_de_imagen($image_url, $max_width = 190) {

		// Obtener tamaño original de la imagen en píxeles
		list($widthPx, $heightPx) = getimagesize($image_url);

		// Resolución estándar de FPDF: 72 DPI (puntos por pulgada)
		// Pero como FPDF usa mm, y la mayoría de imágenes tienen 96 DPI, convertimos a mm con un factor:
		$ppi = 96; // píxeles por pulgada
		$mmPerInch = 25.4;
		$widthMm = ($widthPx / $ppi) * $mmPerInch;
		$heightMm = ($heightPx / $ppi) * $mmPerInch;

		// Escalar para que el ancho máximo sea 190 mm
		if ($widthMm > $max_width) {
		    $scale = $max_width / $widthMm;
		    $widthMm = $max_width;
		    $heightMm *= $scale;
		}

		$x = (210 - $widthMm) / 2;

		return [
			'x'			=> $x,
			'width'		=> $widthMm,
			'height'	=> $heightMm,
		];

	}

}