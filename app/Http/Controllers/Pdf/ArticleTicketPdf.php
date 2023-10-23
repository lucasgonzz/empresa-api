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
		}
	}

	function printArticle($article) {

		if ($this->y >= 270) {
			$this->AddPage();
			$this->y = 0;
			$this->x = 0;
		}

		$this->x = $this->start_x;
		$this->SetFont('Arial', 'B', 12);
		$this->Cell(70, 8, StringHelper::short($article->name, 30), 1, 1, 'L');
		
		$this->x = $this->start_x;
		$this->SetFont('Arial', '', 10);
		$this->Cell(30, 16, $article->provider_code, 'LTB', 0, 'L');

		$this->SetFont('Arial', 'B', 27);
		$this->Cell(40, 16, '$'.Numbers::price($article->final_price), 'RTB', 1, 'R');

		$this->x = $this->start_x;
		$this->SetFont('Arial', '', 12);
		$this->Cell(70, 5, $this->user->company_name, 1, 1, 'L');

		$this->x = $this->start_x;
		$this->x += 70;
		// dd($this->x);
	}

}