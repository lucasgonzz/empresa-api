<?php

namespace App\Http\Controllers\Pdf; 

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\CommonLaravel\Helpers\StringHelper;
use App\Models\Article;
use Milon\Barcode\DNS1D;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class ArticleBarCodePdf extends fpdf {

	function __construct($ids) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;

		$this->setArticles($ids);
		$this->barcodeGenerator = new DNS1D();

		$this->margen_superior = 5;

		$this->code_width = 42;
		$this->max_columns = 5;  // Máximo de artículos por fila
		$this->alto_maximo = 15;  
		$this->image_height = 7; // Alto del la imagen del codigo
		$this->alto_codigo_text = 4; // Alto con el que se imprime el text del codigo debajo de la imagen
		$this->espacio_entre_imagen_y_texto_del_codigo = 2;

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
		$this->y = $this->margen_superior;
		$this->x = 0;
		$this->column_count = 0;  // Contador de columnas en la fila actual

		$this->SetFont('Arial', '', 8);

		foreach ($this->articles as $article) {
			if (count($article->article_variants)) {
				foreach ($article->article_variants as $variant) {
					$this->print_info($article, $variant);
				}
			} else {
				$this->print_info($article);
			}
		}
	}

	function print_info($article, $variant = null) {
		// Ajustar a la siguiente fila si alcanza el máximo de columnas
		if ($this->column_count >= $this->max_columns) {
			$this->column_count = 0;
			$this->x = 0;
			$this->y += $this->alto_maximo;  // Espacio vertical entre filas
			$this->y += $this->margen_superior;
		}

		// Control de salto de página
		if ($this->y >= 270) {
			$this->AddPage();
			$this->y = $this->margen_superior;
			$this->x = 0;
		}

		$this->x_inicial = $this->x;
		$this->printArticle($article, $variant);

		// Mover a la siguiente columna
		// $this->x += $this->code_width;
		$this->column_count++;
	}

	function printArticle($article, $variant) {
		if (!is_null($article)) {

			$code = $article->bar_code;
			if (
				env('APP_URL') == 'https://api-feitoamao-beta.comerciocity.com'
				|| env('APP_URL') == 'https://api-feitoamao.comerciocity.com'
			) {

				$code = ''.$article->num;

				if (!is_null($variant)) {
					$code = '0'.$variant->id;
				}
			}

			if (
				is_null($code)
				|| $code == ''
			) {
				return;
			}

			$this->print_bar_code($code);

			$this->y += $this->image_height;
			$this->y += $this->espacio_entre_imagen_y_texto_del_codigo;

			$this->Cell($this->code_width, $this->alto_codigo_text, $code, $this->b, 1, 'C');

			$this->x = $this->x_inicial;

			// Ajustar la posición para el nombre del artículo
			$x_antes_de_multicell = $this->x;

			$y_antes_de_multicell = $this->y;

			$description = $article->name;

			if (!is_null($variant)) {
				$description .= ' '.$variant->variant_description;
			}
			$this->MultiCell($this->code_width, 3, $description, $this->b, 'L', false);
			$y_despues_de_multicell = $this->y;

			$alto_multicell = $y_despues_de_multicell - $y_antes_de_multicell;


			$alto_articulo_impreso = $alto_multicell + $this->alto_codigo_text + $this->image_height + $this->espacio_entre_imagen_y_texto_del_codigo;

			$this->y -= $alto_articulo_impreso;
			$this->x = $x_antes_de_multicell + $this->code_width;
			
			// if ($this->y > $this->alto_maximo) {
			if ($alto_articulo_impreso > $this->alto_maximo) {
				$this->alto_maximo = $alto_articulo_impreso;
			}
		}
	}

	function print_bar_code($code) {
		$barcode = $this->barcodeGenerator->getBarcodePNG($code, 'C128');
		$imgData = base64_decode($barcode);
		$file = 'temp_barcode'.$code.'.png';
		file_put_contents($file, $imgData);

		$img_width = $this->code_width - 10;
		$start_x = ($this->code_width - $img_width) / 2;
		$start_x += $this->x;

		$this->Image($file, $start_x, $this->y, $img_width, $this->image_height);
		unlink($file);
	}
}
