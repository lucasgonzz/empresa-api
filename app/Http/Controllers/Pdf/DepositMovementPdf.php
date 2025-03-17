<?php

namespace App\Http\Controllers\Pdf; 

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\ImageHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\ArticleVariant;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class DepositMovementPdf extends fpdf {

	function __construct($deposit_movement) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;
		
		$this->user = UserHelper::user();
		$this->deposit_movement = $deposit_movement;

		$this->AddPage();

		$this->articles();

        $this->Output();
        exit;
	}

	function getFields() {
		return [
			'Num' 			=> 20,
			'Codigo Prov' 	=> 30,
			'Producto' 		=> 80,
			'Variante' 		=> 50,
			'Cant' 			=> 20,
		];
	}

	function Header() {
		$data = [
			'num' 				=> $this->deposit_movement->num,
			'date'				=> $this->deposit_movement->created_at,
			'title' 			=> 'Mov. Depositos',
			'fields' 			=> $this->getFields(),
		];
		PdfHelper::header($this, $data);
	}

	function Footer() {

		$this->y += 5;

		$this->employee();

		$this->deposito_origen();

		$this->deposito_destino();

		$this->notes();
	}

	function employee() {

		if (!is_null($this->deposit_movement->employee)) {

			$this->SetFont('Arial', 'B', 11);
			$this->x = 5;
			$this->Cell(100, 8, 'A cargo del empleado: '.$this->deposit_movement->employee->name, 0, 1, 'L');
		}
	}

	function deposito_origen() {

		if (!is_null($this->deposit_movement->from_address)) {

			$this->SetFont('Arial', 'B', 11);
			$this->x = 5;
			$this->Cell(100, 8, 'Deposito Origen: '.$this->deposit_movement->from_address->street, 0, 1, 'L');
		}
	}

	function deposito_destino() {
		
		if (!is_null($this->deposit_movement->to_address)) {

			$this->SetFont('Arial', 'B', 11);
			$this->x = 5;
			$this->Cell(100, 8, 'Deposito Destino: '.$this->deposit_movement->to_address->street, 0, 1, 'L');
		}
	}

	function articles() {
		$this->SetFont('Arial', '', 10);
		$this->x = 5;
		foreach ($this->deposit_movement->articles as $article) {
			if ($this->y < 210) {
				$this->printArticle($article);
			} else {
				$this->AddPage();
				$this->x = 5;
				$this->y = 55;
				$this->printArticle($article);
			}
		}
	}

	function printProductDelivered($product) {
		$this->Cell(20, $this->getHeight($product), $product->bar_code, 'T', 0, 'C');
		$this->Cell(20, $this->getHeight($product), $product->amount, 'T', 0, 'C');
		$this->MultiCell(80, $this->line_height, $product->name, 'T', 'C', false);
		$this->x = 125;
		$this->y -= $this->getHeight($product);
		$this->Cell(50, $this->getHeight($product), $this->getTotalDeliveries($product), 'T', 0, 'C');
	}

	function printArticle($article) {
		$this->x = 5;
		$this->Cell($this->getFields()['Num'], $this->line_height, $article->num, $this->b, 0, 'L');
		$this->Cell($this->getFields()['Codigo Prov'], $this->line_height, $article->provider_code, $this->b, 0, 'L');
		$y_1 = $this->y;
		$this->MultiCell($this->getFields()['Producto'], $this->line_height, $article->name, $this->b, 'L', false);
		
		$this->x = PdfHelper::getWidthUntil('Producto', $this->getFields());
	    $y_2 = $this->y;
		$this->y = $y_1;
		
		$this->Cell($this->getFields()['Variante'], $this->line_height, $this->get_variant($article), $this->b, 0, 'L');

		$this->Cell($this->getFields()['Cant'], $this->line_height, $article->pivot->amount, $this->b, 0, 'L');
		
		$this->y = $y_2;
		$this->Line(5, $this->y, 205, $this->y);
	}

	function get_variant($article) {

		if (!is_null($article->pivot->article_variant_id)) {

			$article_variant = ArticleVariant::find($article->pivot->article_variant_id);

			if (!is_null($article_variant)) {

				return $article_variant->variant_description;
			}
		}

		return null;
	}

	function notes() {
		if ($this->deposit_movement->notes != '') {
		    $this->x = 5;
		    $this->y += 5;
	    	$this->SetFont('Arial', 'B', 10);
			$this->Cell(100, $this->line_height, 'Observaciones', 0, 1, 'L');
		    $this->x = 5;
	    	$this->SetFont('Arial', '', 10);
	    	$this->MultiCell(200, $this->line_height, $this->deposit_movement->notes, $this->b, 'LTB', false);
	    	$this->x = 5;
		}
	}

	function getHeight($product) {
    	$lines = 1;
    	$letras = strlen($product->name);
    	while ($letras > 41) {
    		$lines++;
    		$letras -= 41;
    	}
    	return $this->line_height * $lines;
	}

}