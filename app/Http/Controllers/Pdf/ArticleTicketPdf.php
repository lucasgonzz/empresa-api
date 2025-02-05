<?php

namespace App\Http\Controllers\Pdf; 

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\CommonLaravel\Helpers\StringHelper;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\ImageHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class ArticleTicketPdf extends fpdf {

	function __construct($ids) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;

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
		$this->y = 0;
		$this->x = 0;
		foreach ($this->articles as $article) {
			
			if ($this->x == 0) {
				$this->start_x = 0;
			} else if ($this->x == 70) {
				$this->start_x = 70;
				$this->y -= 29;
			} else if ($this->x == 140) {
				$this->start_x = 140;
				$this->y -= 29;
			} else if ($this->x == 210) {
				$this->start_x = 0;
			}

			$this->printArticle($article);

			$this->lineas();
		}
	}

	function lineas() {

		// Derecha
		$this->Line($this->x, $this->y - 29, $this->x, $this->y);
	}

	function printArticle($article) {

		if ($this->y >= 270) {
			$this->AddPage();
			$this->y = 0;
			$this->x = 0;
		}

		if (!is_null($article)) {
			$this->x = $this->start_x;
			$this->SetFont('Arial', 'B', 9);
			$this->Cell(70, 8, StringHelper::short($article->name, 35), 1, 1, 'L');
			
			$info = null;
			$this->x = $this->start_x;
			if (!is_null($this->user->article_ticket_info)) {
				if ($this->user->article_ticket_info->name == 'Codigo de barras') {
					$info = $article->bar_code;
				} else if ($this->user->article_ticket_info->name == 'Codigo de proveedor') {
					$info = $article->provider_code;
				}
			}
			if (!is_null($info)) {
				$this->SetFont('Arial', '', 10);
				$this->Cell(30, 16, $info, 'LTB', 0, 'L');
			} else {
				$this->x += 30;
			}

			$this->price($article);

			$this->x = $this->start_x;
			$this->SetFont('Arial', '', 12);
			$this->Cell(70, 5, $this->user->company_name, 1, 1, 'L');

			$this->x = $this->start_x;
			$this->x += 70;
		}

	}

	function price($article) {

		$border = 0;

		if (UserHelper::hasExtencion('lista_de_precios_por_rango_de_cantidad_vendida')) {
			
			$start_y = $this->y;

			if (
				!is_null($article->sub_category)
				&& count($article->sub_category->category_price_type_ranges) >= 1
			) {

				foreach ($article->sub_category->category_price_type_ranges as $range) {

					$this->print_price_ranges($article, $range, $border);
				}

			} else if (
				!is_null($article->category)
				&& count($article->category->category_price_type_ranges) >= 1
			) {

				// if ($article->name == 'Mate Torpedo') {
					// dd($article->category->category_price_type_ranges);
				// }

				foreach ($article->category->category_price_type_ranges as $range) {

					if (is_null($range->sub_category_id)) {

						$this->print_price_ranges($article, $range, $border);
					}

				}
			}

			$y_donde_deberia = $start_y + 16;

			if ($y_donde_deberia > $this->y) {

				$this->y = $y_donde_deberia;
			}

		} else {

			$this->SetFont('Arial', 'B', 27);
			$this->Cell(40, 16, '$'.Numbers::price($article->final_price), 'RTB', 1, 'R');
		}
	}

	function print_price_ranges($article, $range, $border) {

			
		$article_price_type = $article->price_types->firstWhere('id', $range->price_type_id);

		$this->x = $this->start_x + 30;

		$ancho = 40;
		
		if (
			!is_null($range->min)
			&& $range->min > 1
		) {

			$this->SetFont('Arial', '', 8);
			$this->Cell(20, 5, 'A partir de '.$range->min, $border, 0, 'R');
			
			$ancho = 20;

		} else {

			$this->x += 20;
		}
		
		$this->SetFont('Arial', 'B', 10);
		
		$this->Cell(20, 5, '$'.Numbers::price($article_price_type->pivot->price), $border, 1, 'L');
	}

}