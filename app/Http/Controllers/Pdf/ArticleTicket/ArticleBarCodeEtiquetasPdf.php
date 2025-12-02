<?php

namespace App\Http\Controllers\Pdf\ArticleTicket; 

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\CommonLaravel\Helpers\StringHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use Milon\Barcode\DNS1D;
use fpdf;
require(__DIR__.'/../../CommonLaravel/fpdf/fpdf.php');

class ArticleBarCodeEtiquetasPdf extends fpdf {

	function __construct($ids) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;

		$this->user = UserHelper::user();

		$this->setArticles($ids);
		$this->barcodeGenerator = new DNS1D();


		$this->etiqueta_width  = 100;
		$this->etiqueta_height  = 50;  
		$this->cant_article_x_etiqueta  = 1;  

		if ($this->user->article_etiqueta_width) {
			$this->etiqueta_width  = $this->user->article_etiqueta_width;
		}
		if ($this->user->article_etiqueta_height) {
			$this->etiqueta_height  = $this->user->article_etiqueta_height;
		}
		if ($this->user->cant_article_x_etiqueta) {
			$this->cant_article_x_etiqueta  = $this->user->cant_article_x_etiqueta;
		}


		// Nombre
		$this->height_nombre = 5;
		$this->width_nombre = 12; 

		// Precio
		$this->height_precio = 23;
		$this->width_precio = 40;

		// Codigo de barras
		$this->code_height = 10; // Alto del la imagen del codigo
		$this->code_width = 75;


		$this->print();
        $this->Output();
        exit;
	}

	function setArticles($ids) {
		$this->articles = [];
		foreach (explode('-', $ids) as $id) {
			$this->articles[] = Article::find($id);
		}
	}

	function print() {

		$prints_disponibles = $this->cant_article_x_etiqueta;
		$this->AddPage('L', [$this->etiqueta_width, $this->etiqueta_height]);
		$this->y = 0;


		foreach ($this->articles as $article) {

			if ($prints_disponibles == 0) {

				$this->AddPage('L', [$this->etiqueta_width, $this->etiqueta_height]);
				$prints_disponibles = $this->cant_article_x_etiqueta;
				$this->y = 0;
				
			} 

			$prints_disponibles--;

			$this->x = 0;

			$this->print_info($article);

		}
	}

	function print_info($article) {
		
		$this->y += 1;

		if ($article->bar_code) {
			$this->y += 3;
			$this->print_bar_code($article->bar_code);
		}

		$this->nombre_negocio();
		
		$this->nombre($article);
		
		// $this->precio($article);
		

	}

	function nombre($article) {
		$this->SetFont('Arial', '', $this->width_nombre);

		$this->x = 0;
		$this->y += 3;

	    $this->MultiCell( 
			$this->etiqueta_width,
			$this->height_nombre, 
			$article->name, 
	    	$this->b, 
	    	'C',
	    	false,
	    );

		// $this->Cell($this->etiqueta_width, $this->height_nombre, $article->name, $this->b, 1, 'C');
	}

	function precio($article) {
		$this->x = 0;
		$this->y += $this->code_height;
		$this->y += 2;
		$this->SetFont('Arial', '', $this->width_precio);
		$this->Cell($this->etiqueta_width, $this->height_precio, '$'.Numbers::price($article->final_price), $this->b, 1, 'C');
	}

	function nombre_negocio() {

		// Este incremento de y lo hacia en precio, pero como lo comente ahora lo hago aca
		$this->y += $this->code_height;
		$this->y += 5;

		$this->x = 0;
		$this->SetFont('Arial', '', 10);
		$this->Cell($this->etiqueta_width, 5, $this->user->company_name, $this->b, 1, 'C');
	}

	function print_bar_code($code) {
		$this->x = 0;
		$barcode = $this->barcodeGenerator->getBarcodePNG($code, 'C128');
		$imgData = base64_decode($barcode);
		$file = 'temp_barcode'.str_replace('/', '_', $code).'.png';
		file_put_contents($file, $imgData);

		$img_width = $this->code_width - 10;

		$start_x = ($this->etiqueta_width / 2) - ($img_width / 2);
		
		// $start_x = ($this->code_width - $img_width) / 2;
		// $start_x += 13;

		$this->Image($file, $start_x, $this->y, $img_width, $this->code_height);
		unlink($file);
	}
}
