<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Helpers\ImageHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\UserHelper;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class SaleDeliveredArticlesPdf extends fpdf {

	function __construct($sale) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;
		
		$this->sale = $sale;

		$this->AddPage();
		$this->articles();
		PdfHelper::firma($this);
        $this->Output();
        exit;
	}


	function getModelProps() {
		return [
			[
				'text' 	=> 'Cliente',
				'key'	=> 'name',
			],
			[
				'text' 	=> 'Telefono',
				'key'	=> 'phone',
			],
			[
				'text' 	=> 'Localidad',
				'key'	=> 'location.name',
			],
		];
	}

	function Header() {
		$data = [
			'num' 			=> $this->sale->num_sale,
			'date'			=> $this->sale->created_at,
			'title' 		=> 'Articulos entregados',
			'model_info'	=> $this->sale->client,
			'model_props' 	=> $this->getModelProps(),
		];
		PdfHelper::header($this, $data);
	}

	function Footer() {
		// PdfHelper::comerciocityInfo($this, $this->y);
	}

	function getDeliveredArticles() {
		$articles = [];
		foreach ($this->sale->articles as $article) {
			if ($article->pivot->delivered_amount > 0) {
				$articles[] = $article;
			}
		}
		return $articles;
	}

	function articles() {
		$this->SetFont('Arial', 'B', 10);
		$this->x = 5;
		foreach ($this->getDeliveredArticles() as $article) {
			if ($this->y < 210) {
				$this->printArticle($article);
			} else {
				$this->AddPage();
				$this->x = 5;
				$this->y = 90;
				$this->printArticle($article);
			}
			$this->x = 5;
		}
	}

	function printArticle($article) {
		$y_1 = $this->y;
		$text = 'Se entregaron '.$article->pivot->delivered_amount.' unidades de '.$article->name;
		$this->MultiCell(200, 7, $text, 0, 'L', false);
		// $this->Line(5, $this->y, 205, $this->y);
	}

}