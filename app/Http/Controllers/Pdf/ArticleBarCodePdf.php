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
		
		// $this->user = UserHelper::getFullModel();

        $this->barcodeGenerator = new DNS1D();

        $this->code_width = 42;

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
		$this->y = 2;
		$this->x = 0;

		$this->SetFont('Arial', '', 8);

		foreach ($this->articles as $article) {
			
			if ($this->x > 168) {
				$this->start_x = 0;
				$this->x = 0;
				$this->y += 19;
			} else {

				$this->start_x = $this->x;
			}

			$this->printArticle($article);
		}
	}

	function printArticle($article) {

		if ($this->y >= 270) {
			$this->AddPage();
			$this->y = 2;
			$this->x = 0;
		}

		if (!is_null($article) && (
				!is_null($article->bar_code)
				|| !is_null($article->provider_code)
			)) {
 
			$code = null;

			if (!is_null($article->bar_code)) {
				$code = $article->bar_code;

			} else if (!is_null($article->provider_code)) {

				$code = $article->provider_code;
			}

			$this->print_bar_code($code);

			$this->y += 9;

			$this->Cell($this->code_width, 3, $article->num, $this->b, 1, 'C');
			$this->x = $this->start_x;

			$this->Cell($this->code_width, 3, StringHelper::short($article->name, 24), $this->b, 0, 'C');
			
			$this->y -= 12;
			// dd($this->y);
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

        $this->Image($file, $start_x, $this->y, $img_width, 7);

        unlink($file);
    }

}