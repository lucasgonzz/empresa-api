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

		$function = $this->user->article_ticket_print_function;
		if ($function == 'golonorte') {

			$helper = new Golonorte($this, $this->articles);
			$helper->print();

		} else {
			$this->default_print();
		}
	}

	function default_print() {

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
				$this->Cell(30, 8, $info, 0, 0, 'L');
			} else {
				$this->x += 30;
			}

			$this->price($article);

			$this->x = $this->start_x;
			$this->SetFont('Arial', '', 12);
			$this->Cell(70, 5, $this->user->company_name, 1, 1, 'L');

			$this->fecha_impresion();

			$this->x = $this->start_x;
			$this->x += 70;
		}

	}

	function fecha_impresion() {
		if (UserHelper::hasExtencion('fecha_impresion_en_article_tickets')) {
			$this->x = $this->start_x;
			$this->x += 50;
			$this->y -= 5;
			$this->Cell(20, 5, Carbon::now()->format('dmy'), 1, 1, 'R');
		}
	}

	function price($article) {

		$border = 0;

		$this->SetFont('Arial', 'B', 27);
		$this->Cell(40, 16, '$'.Numbers::price($article->final_price), 'RTB', 1, 'R');
	}

}