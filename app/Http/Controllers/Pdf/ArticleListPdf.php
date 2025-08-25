<?php

namespace App\Http\Controllers\Pdf; 

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\CommonLaravel\Helpers\StringHelper;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\ImageHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use Illuminate\Support\Facades\Log;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class ArticleListPdf extends fpdf {

	function __construct($ids) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;
		$this->total = 0;
		
		$this->setArticles($ids);

		$this->AddPage();
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
			'Codigo' 	=> 40,
			'Codigo Prov' 	=> 30,
			'Nombre' 	=> 80,
			'Precio' 	=> 30,
			'Stock' 	=> 20,
		];
	}

	function Header() {
		$data = [
			'fields' 				=> $this->getFields(),
		];
		PdfHelper::simpleHeader($this, $data);
	}

	function printItems() {
		$this->x = 5;
		$this->SetFont('Arial', '', 8);
		foreach ($this->articles as $article) {
			$this->Cell($this->getFields()['Codigo'], 5, $article->bar_code, $this->b, 0, 'C');
			$this->Cell($this->getFields()['Codigo Prov'], 5, $article->provider_code, $this->b, 0, 'C');

			$y_1 = $this->y;
		    $this->MultiCell( 
				$this->getFields()['Nombre'], 
				5, 
				$article->name, 
		    	$this->b, 
		    	'L', 
		    	false
		    );
		    $y_2 = $this->y;
		    $this->y = $y_1;
	    	$this->x = 5 + $this->getFields()['Codigo'] + $this->getFields()['Codigo Prov'] + $this->getFields()['Nombre'];
			$this->Cell($this->getFields()['Precio'], 5, '$'.Numbers::price($article->final_price), $this->b, 0, 'C');
			// $this->Cell($this->getFields()['Proveedor'], 5, !is_null($article->provider) ? $article->provider->name : null, $this->b, 0, 'C');
			$this->Cell($this->getFields()['Stock'], 5, $article->stock, $this->b, 0, 'C');
			$this->y = $y_2;
			$this->x = 5;
			$this->Line(5, $this->y, 205, $this->y);
		}
	}

	function Footer() {
		
		// PdfHelper::comerciocityInfo($this, $this->y);
	}

}