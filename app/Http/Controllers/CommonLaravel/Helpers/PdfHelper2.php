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

class PdfHelper2 {

	function __construct($instance) {

		$this->instance = $instance;
		$this->b = 0;
		$this->margen = 5;
		$this->default_logo_size = 30;
		
		// Estas 2 no se tocan, se setean luego de dibujar los headers
		$this->header_right_finish_y = 0;
		$this->header_left_finish_y = 0;
	}

	function header($data) {
		
		$this->header_left($data);

		$this->header_right($data);

		$this->set_y();
	}

	function set_y() {
		if ($this->header_right_finish_y > $this->header_left_finish_y) {
			$this->instance->y = $this->header_right_finish_y;
		} else {
			$this->instance->y = $this->header_left_finish_y;
		}
		$this->instance->y += $this->margen;
	}

	function header_right($data) {

		if (!isset($data['header_right'])) return;

		if (isset($data['header_right']['logo'])) {

			$image_url = $data['header_right']['logo']['url'];

			$image_size = $this->default_logo_size; 

			if (isset($data['header_right']['logo']['size'])) {
				$image_size = $data['header_right']['logo']['size'];
			}

			$start_x = 297 - $image_size - $this->margen;

			if (config('app.APP_ENV') == 'local') {
	    		$this->instance->Image('https://api.freelogodesign.org/assets/thumb/logo/ad95beb06c4e4958a08bf8ca8a278bad_400.png', $start_x, $this->margen, $image_size, $image_size);
	    	} else {
	    		if (!is_null($image_url) && GeneralHelper::file_exists_2($image_url)) {
	    			$this->instance->Image($image_url, $start_x, $this->margen, $image_size, $image_size);
	    		}
	    	}

	    	$this->header_right_finish_y = $this->margen + $image_size;
		} 
	}

	function header_left($data) {

		if (!isset($data['header_left'])) return;

		$this->instance->x = 5;
		$this->instance->y = 5;

		$header_left = $data['header_left'];

		// Titulos principales: negrita 11
		$this->instance->SetFont('Arial', 'B', 11);
		
		$width = 148.5 - $this->margen;
		foreach ($header_left['titulos_principales'] as $titulo) {
			$this->instance->Cell($width, 5, $titulo, $this->b, 1, 'L');
			$this->instance->x = 5;
		}


		// Titulos principales: normal 10
		$this->instance->SetFont('Arial', '', 10);
		
		foreach ($header_left['titulos_secundarios'] as $titulo) {
			$this->instance->Cell($width, 5, $titulo, $this->b, 1, 'L');
			$this->instance->x = 5;
		}

	    $this->header_left_finish_y = $this->instance->y;
	}





	// Table

	function table($data) {
		
		$this->table_header($data);
		$this->table_rows($data);
	}

	function table_header($data) {

		if (!isset($data['header'])) return;

		$this->instance->x = 5;	

		$font_size = 10;
		if (isset($data['header_font_size'])) {
			$font_size = $data['header_font_size'];
		}
		
		$this->instance->SetFont('Arial', 'B', $font_size);
		foreach ($data['header'] as $th) {
			$this->instance->Cell($th['size'], 5, $th['title'], 'B', 0, 'L');
		}



		$this->instance->y += 5;	
		$this->instance->x = 5;	
	}

	function table_rows($data)
	{
	    if (!isset($data['rows']) || !is_array($data['rows'])) return;
	    if (!isset($data['header']) || !is_array($data['header'])) return;


		$font_size = 10;
		if (isset($data['body_font_size'])) {
			$font_size = $data['body_font_size'];
		}
		
	    $this->instance->SetFont('Arial', '', $font_size);

	    foreach ($data['rows'] as $row) {

	        // Si por algún motivo no es array, lo fuerzo a string
	        if (!is_array($row)) {
	            $row = ['__value' => $row];
	        }

	        if (isset($row['font'])) {
	    		$this->instance->SetFont('Arial', $row['font']['type'], $row['font']['size']);
	        } else {
	    		$this->instance->SetFont('Arial', '', $font_size);
	        }

	        foreach ($data['header'] as $th) {
	            $title = $th['title'] ?? '';
	            $size  = $th['size'] ?? 10;

	            $value = isset($row[$title]) ? $row[$title] : '';

	            $this->instance->Cell($size, 5, $this->cell_text($value), $this->b, 0, 'L');
	        }

	        $this->instance->Ln(5);
	        $this->instance->x = 5;
	    }
	}

	/**
	 * FPDF clásico trabaja en ISO-8859-1 / Windows-1252 (no UTF-8).
	 * Esta función:
	 * - garantiza string
	 * - convierte desde UTF-8 a Windows-1252 para evitar caracteres raros
	 */
	function cell_text($value)
	{
	    if (is_null($value)) {
	        $value = '';
	    } else if (is_bool($value)) {
	        $value = $value ? '1' : '0';
	    } else if (is_array($value) || is_object($value)) {
	        $value = json_encode($value);
	    } else {
	        $value = (string) $value;
	    }

	    // Convertir UTF-8 -> Windows-1252 (FPDF)
	    if (mb_check_encoding($value, 'UTF-8')) {
	        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
	        if ($converted !== false) {
	            return $converted;
	        }
	    }

	    return $value;
	}

	function get_row_size($data, $title) {

		foreach ($data['header'] as $th) {
			if ($th['title'] == $title) {
				return $th['size'];
			}
		}
	}

}