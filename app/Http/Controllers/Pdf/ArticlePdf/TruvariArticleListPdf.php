<?php

namespace App\Http\Controllers\Pdf\ArticlePdf; 

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\CommonLaravel\Helpers\StringHelper;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\ImageHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\ArticlePdfObservation;
use App\Models\Bodega;
use App\Models\Category;
use Illuminate\Support\Facades\Log;
use fpdf;
require(__DIR__.'/../../CommonLaravel/fpdf/fpdf.php');

class TruvariArticleListPdf extends fpdf {

	function __construct($user) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;
		$this->user = $user;
		$this->total = 0;
		
		$this->AddPage();

		$this->_header();

		$this->printItems();

		$this->_Footer();

        $this->Output();
        exit;
	}

	function getFields() {
		return [
			'Nombre' 		=> 110,
			'Unid x Caja' 	=> 25,
			'$ x Botella' 	=> 30,
			'$ x Caja' 		=> 35,
		];
	}

	function _header() {
		// $this->logo();

		$this->observations();

		$this->SetTextColor(0,0,0);
	}

	function table_header() {
		PdfHelper::tableHeader($this, $this->getFields(), 10, 0);
	}

	function observations() {
		$observations = ArticlePdfObservation::where('user_id', $this->user->id)
										->orderBy('position', 'ASC')
										->get();

		$this->SetFont('Arial', 'B', 9);

		foreach ($observations as $observation) {


			if ($observation->image_url) {

				$image = $this->getJpgImage($observation->image_url);

		    	$res = PdfHelper::coordenadas_y_ancho_de_imagen($image, 200);

				$this->Image($image, $res['x'], $this->y, $res['width'], $res['height']);

				$this->y += $res['height'];
			}

			if ($observation->text) {
				$this->x = 5;
					
				if ($observation->color) {
					$codigo = explode('-', $observation->color);
					$this->SetTextColor($codigo[0], $codigo[1], $codigo[2]);
				}

				if ($observation->background) {
					$codigo = explode('-', $observation->background);
					$this->SetFillColor($codigo[0], $codigo[1], $codigo[2]);
				}

				$text = str_replace('__fecha__', date('d/m/Y'), $observation->text); 

				$this->Cell(200, 7, $text, 1, 1, 'C', 1);
			}
		}
	}

	function logo() {
		$image = $this->user->image_url;
		if (env('APP_ENV') == 'local') {
    		$image = 'https://api.freelogodesign.org/assets/thumb/logo/ad95beb06c4e4958a08bf8ca8a278bad_400.png';
    	}
    	if ($image) {
			$this->Image($image, 80, 5, 50, 50);
			$this->y = 55;
    	}
	}

	function printItems() {

		$bodegas = Bodega::where('user_id', $this->user->id)
							->orderBy('name', 'ASC')
							->get();

	
		foreach ($bodegas as $bodega) {

			if (count($bodega->articles) == 0) continue;

			$this->print_titulo($bodega->name);

			foreach ($bodega->articles as $article) {
				$this->print_article($article);
			}
		}




		$espumantes = Category::find(9);
		if (!$espumantes) return;

		$this->print_titulo($espumantes->name);

		foreach ($espumantes->articles as $article) {
			$this->print_article($article);
		}

	}

	function print_titulo($title) {

		$this->y += 5;
		
		$this->SetFont('Arial', 'IB', 12);
		$this->SetFillColor(230, 230, 230);
		
		$this->x = 5;
		$this->Cell(200, 7, $title, 1, 1, 'C', 1);
	
		$this->table_header();

		$this->SetFont('Arial', 'B', 10);

	}

	function print_article($article) {

			if ($article->omitir_en_lista_pdf) {
				return;
			}


			$this->x = 5;

			$this->Cell($this->getFields()['Nombre'], 7, $article->name, $this->b, 0, 'C');

			$this->Cell($this->getFields()['Unid x Caja'], 7, $article->presentacion, $this->b, 0, 'C');

			$precio_por_botella = null;
			if ($article->presentacion) {
				$precio_por_botella = '$'.Numbers::price($article->final_price / $article->presentacion);
			}

			$this->Cell($this->getFields()['$ x Botella'], 7, $precio_por_botella, $this->b, 0, 'C');

			$this->Cell($this->getFields()['$ x Caja'], 7, '$'.Numbers::price($article->final_price), $this->b, 0, 'C');
			$this->Line(5, $this->y, 205, $this->y);
			$this->y += 7;
	}

	function _Footer() {
		
		$this->x = 5;
		$this->y += 5;

		$text = '**LOS PRECIOS PUEDEN MODIFICARSE SIN PREVIO AVISO** '.date('d/m/Y');
		$this->SetTextColor(231, 33, 33);

		$this->SetFillColor(250, 250, 33);
		
		$this->SetFont('Arial', 'B', 12);
		
		$this->Cell(200, 7, $text, 1, 0, 'C', 1);
	}



	function getJpgImage($img_url) {

		if (!is_null($img_url)) {
	        $array = explode('/', $img_url); 
	        $array_name = end($array);
	        $name = explode('.', $array_name)[0];
	        $extension = strtolower(pathinfo($array_name, PATHINFO_EXTENSION));

	        if (env('APP_ENV') == 'local') {
	        	// $jpg_file_url = storage_path('app/' . $name . '.jpg');
	        	$jpg_file_url = storage_path('app/public/' . $name . '.jpg');
	        } else {
	        	$jpg_file_url = storage_path('app/public/' . $name . '.jpg');
	        }


	        // Convertir a JPG si la imagen es WEBP
	        if ($extension === 'webp') {
	            if (!file_exists($jpg_file_url)) {
	                try {
	                    $image = imagecreatefromwebp($img_url);
	                    if ($image !== false) {
	                        imagejpeg($image, $jpg_file_url, 100);
	                        imagedestroy($image);
	                    } else {
	                        throw new \Exception("No se pudo crear la imagen desde WEBP.");
	                    }
	                } catch (\Exception $e) {
	                    return $img_url; // Devuelve la URL original si falla la conversión
	                }
	            }
	            return $jpg_file_url;
	        }

	        // Si la imagen ya es JPG, simplemente devuelve la URL
	        if ($this->isJpg($img_url)) {
	            return $img_url;
	        }
		}
		return $img_url;
	}

	function isJpg($file) {
	    // Verifica que el archivo exista
	    if (!file_exists($file)) {
	        return false;
	    }
	    
	    // Obtiene información sobre la imagen
	    $imageInfo = getimagesize($file);
	    
	    // Verifica si el tipo MIME es 'image/jpeg'
	    if ($imageInfo && $imageInfo['mime'] == 'image/jpeg') {
	        return true;
	    }
	    
	    return false;
	}

}