<?php

namespace App\Http\Controllers\Pdf; 

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\CommonLaravel\Helpers\StringHelper;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\ImageHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Pdf\ArticleTicket\Golonorte;
use App\Models\Article;
use Carbon\Carbon;
use Milon\Barcode\DNS1D;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');


// Etiquetas para gondolas
class ArticleTicketPdf extends fpdf {

	function __construct($ids) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;

		$this->margen = 5;

		$this->ticket_w = (210 - ($this->margen * 2)) / 3;
		$this->ticket_h = 40;

		$this->barcodeGenerator = new DNS1D();
		$this->bar_code_img_width = $this->ticket_w - 15;

		$this->setArticles($ids);
		
		$this->user = UserHelper::getFullModel();

		$this->AddPage();
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
		$this->y = $this->margen;
		$this->x = $this->margen;

		$function = $this->user->article_ticket_print_function;
		if ($function == 'golonorte') {

			$helper = new Golonorte($this, $this->articles);
			$helper->print();

		} else {
			$this->default_print();
		}
	}

	function default_print() {

		$this->start_y = 0;

		foreach ($this->articles as $article) {
			
			if ($this->x == $this->margen) {
				$this->start_x = $this->margen;
				$this->start_y = $this->margen;

			} else if ($this->x == $this->ticket_w + $this->margen) {

				$this->start_x = $this->ticket_w + $this->margen;
				$this->start_y = $this->y - $this->ticket_h;

			} else if ($this->x ==$this->margen +  $this->ticket_w*2) {

				$this->start_x = $this->margen + $this->ticket_w*2;
				$this->start_y = $this->y - $this->ticket_h;

			} else if ($this->x == $this->margen + $this->ticket_w*3) {

				$this->start_x = $this->margen;
				$this->start_y = $this->y;
			}

			$this->printArticle($article);

			$this->lineas();
		}
	}

	function lineas() {

		$x_inicio = $this->x - $this->ticket_w;
		$y_inicio = $this->y - $this->ticket_h;

		// Izquierda
		$this->Line($x_inicio, $y_inicio, $x_inicio, $this->y);

		// Derecha
		$this->Line($this->x, $y_inicio, $this->x, $this->y);

		// Arriba
		$this->Line($x_inicio, $y_inicio, $this->x, $y_inicio);

		// Abajo
		$this->Line($x_inicio, $this->y, $this->x, $this->y);
	}

	function printArticle($article) {

		if ($this->y >= 280) {
			$this->AddPage();
			$this->start_y = $this->margen;
			$this->start_x = $this->margen;
		}

		if (!is_null($article)) {

			$this->x = $this->start_x;
			$this->y = $this->start_y;


			// Precio
			$this->price($article);

			// Nombre
			$this->name($article);
			

			// Codigo
			$this->print_bar_code($article);


			$this->fecha_impresion();

			$this->x = $this->start_x;	
			$this->x += $this->ticket_w;

			$this->y = $this->start_y + $this->ticket_h;

		}

	}


	/*
		* Ocupo el height maximo del nombre siempre
	*/
	function name($article) {

		$this->x = $this->start_x;
		$this->SetFont('Arial', 'B', 12);

		$max_lineas = 3;
		$h = 5;
		$h_total = $h * $max_lineas;

		$start_y = $this->y;
	    $this->MultiCell( 
			$this->ticket_w,
			5, 
			StringHelper::short($article->name, 80),
	    	$this->b, 
	    	'L',
	    	false,
	    );

	    $this->y = $start_y;

	    $this->y += $h_total;
	}

	function fecha_impresion() {
		$this->x = $this->start_x + $this->bar_code_img_width;
		$w = $this->ticket_w - $this->bar_code_img_width;
		$this->Cell($w, 5, Carbon::now()->format('d/m/y'), $this->b, 1, 'C');
	}

	function price($article) {

		$border = 0;

		$width = $this->ticket_w;

		$this->y = $this->start_y;

		$this->SetFont('Arial', 'B', 33);
		$this->Cell($width, 13, '$'.Numbers::price($article->final_price), $this->b, 1, 'R');
	}

	function print_bar_code($article) {
		if ($article->bar_code) {

			$this->x = $this->start_x;

			$img_height = 6;

			$barcode = $this->barcodeGenerator->getBarcodePNG($article->bar_code, 'C128');
			$imgData = base64_decode($barcode);
			$file = 'temp_barcode'.str_replace('/', '_', $article->bar_code).'.png';
			file_put_contents($file, $imgData);


			$this->Image($file, $this->start_x+1, $this->y, $this->bar_code_img_width, $img_height);
			unlink($file);


			$this->x = $this->start_x;
			$this->y += $img_height;
			$this->SetFont('Arial', '', 8);
			$this->Cell($this->bar_code_img_width, 5, $article->bar_code, $this->b, 0, 'C');
			
		}
	}

}