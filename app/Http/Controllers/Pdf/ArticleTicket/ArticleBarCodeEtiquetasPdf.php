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

		// Nombre
		$this->alto_nombre = 7;
		$this->size_nombre = 12; 

		// Precio
		$this->alto_precio = 23;
		$this->size_precio = 40;

		// Codigo de barras
		$this->code_width = 42;
		$this->code_height = 7; // Alto del la imagen del codigo


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

		foreach ($this->articles as $article) {
			$this->AddPage('L', [$this->etiqueta_width, $this->etiqueta_height]);

			$this->y = 0;
			$this->x = 0;

			$this->print_info($article);
		}
	}

	function print_info($article) {
		
		$this->nombre($article);

		if ($article->bar_code) {
			$this->print_bar_code($article->bar_code);
		}
		
		$this->precio($article);
		
		$this->nombre_negocio();

	}

	function nombre($article) {
		$this->SetFont('Arial', '', $this->size_nombre);
		$this->Cell($this->etiqueta_width, $this->alto_nombre, $article->name, $this->b, 1, 'L');
	}

	function precio($article) {
		$this->x = 0;
		$this->y += $this->code_height;
		$this->y += 2;
		$this->SetFont('Arial', '', $this->size_precio);
		$this->Cell($this->etiqueta_width, $this->alto_precio, '$'.Numbers::price($article->final_price), $this->b, 1, 'C');
	}

	function nombre_negocio() {
		$this->x = 0;
		$this->SetFont('Arial', '', 10);
		$this->Cell($this->etiqueta_width, 5, $this->user->company_name, $this->b, 1, 'R');
	}

	function print_bar_code($code) {
		$this->x = 0;
		$this->y += 5;
		$barcode = $this->barcodeGenerator->getBarcodePNG($code, 'C128');
		$imgData = base64_decode($barcode);
		$file = 'temp_barcode'.$code.'.png';
		file_put_contents($file, $imgData);

		$img_width = $this->code_width - 10;
		$start_x = ($this->code_width - $img_width) / 2;
		$start_x += $this->x;

		$this->Image($file, $start_x, $this->y, $img_width, $this->code_height);
		unlink($file);
	}
}
