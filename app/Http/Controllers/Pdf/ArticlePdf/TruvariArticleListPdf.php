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

        $this->Output();
        exit;
	}

	function setArticles($ids) {
		$this->articles = [];
		foreach (explode('-', $ids) as $id) {
			$this->articles[] = Article::find($id);
		}
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
		$this->logo();

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

		$this->y = 50;

		foreach ($observations as $observation) {

			$this->x = 5;
				
			if ($observation->color) {
				$codigo = explode('-', $observation->color);
				$this->SetTextColor($codigo[0], $codigo[1], $codigo[2]);
				$this->SetTextColor($codigo[0], $codigo[1], $codigo[2]);
			}

			if ($observation->background) {
				$codigo = explode('-', $observation->background);
				$this->SetFillColor($codigo[0], $codigo[1], $codigo[2]);
			}

			$this->Cell(200, 7, $observation->text, 1, 1, 'C', 1);
		}
	}

	function logo() {
		$image = $this->user->image_url;
		if (env('APP_ENV') == 'local') {
    		$image = 'https://api.freelogodesign.org/assets/thumb/logo/ad95beb06c4e4958a08bf8ca8a278bad_400.png';
    	}
		$this->Image($image, 80, 5, 50, 50);
	}

	function printItems() {

		$bodegas = Bodega::where('user_id', $this->user->id)
							->orderBy('name', 'ASC')
							->get();

		
		foreach ($bodegas as $bodega) {

			if (count($bodega->articles) == 0) continue;

			$this->y += 5;
			
			$this->SetFont('Arial', 'IB', 12);
			$this->SetFillColor(230, 230, 230);
			
			$this->x = 5;
			$this->Cell(200, 7, $bodega->name, 1, 1, 'C', 1);
		
			$this->table_header();

			$this->SetFont('Arial', 'B', 10);

			foreach ($bodega->articles as $article) {
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
		}
	}

	function Footer() {
		
		// PdfHelper::comerciocityInfo($this, $this->y);
	}

}