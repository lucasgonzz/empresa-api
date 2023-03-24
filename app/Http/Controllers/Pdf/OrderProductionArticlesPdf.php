<?php

namespace App\Http\Controllers\Pdf; 

use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class OrderProductionArticlesPdf extends fpdf {

	function __construct($order_production) {
		$this->line_height = 7;
		$this->user = UserHelper::getFullModel();
		$this->order_production = $order_production;

		parent::__construct();
		$this->SetAutoPageBreak(false);
		$this->b = 'LR';

		$this->AddPage();
		$this->articles();
        $this->Output();
        exit;
	}

	function articles() {
		$x = 0;
		$max_y = 1;
		$last_max_y = 1;
		$this->y = 1;
		foreach ($this->order_production->articles as $article) {
			for ($unidad=1; $unidad <= $article->pivot->amount ; $unidad++) { 
				$this->x = $x;

				$this->SetFont('Arial', '', 12);
				if (!is_null($this->order_production->client)) {
					$this->Cell(69, $this->line_height, $this->str($this->order_production->client->name), $this->b.'TB', 0, 'L');
				}

				$this->x = $x;
				$this->y += $this->line_height;
				$this->MultiCell(69, $this->line_height, $article->name, $this->b.'B', 'L', 0);
				
				$this->x = $x;
				if ($this->y > $max_y) {
					$max_y = $this->y;
				}

				$this->SetFont('Arial', '', 10);
				$this->Cell(20, $this->line_height, 'Codigo', 'L', 0, 'L');
				$this->Cell(49, $this->line_height, $this->str($article->bar_code), 'R', 0, 'R');

				$this->x = $x;
				$this->y += $this->line_height;
				$this->Cell(20, $this->line_height, 'Ubi', 'LB', 0, 'L');
				$this->Cell(49, $this->line_height, $this->str($article->pivot->location), 'RB', 0, 'R');

				if ($x < 140) {
					$x += 70;
					$this->y = $last_max_y;
				} else {
					if ($this->y >= 220) {
						$this->AddPage();
						$x = 0;
						$this->y = 1;
						$last_max_y = $this->y;
						$max_y = 0;
					} else {
						$x = 0;
						$max_y++;
						$this->y = $max_y + ($this->line_height * 2);
						$last_max_y = $this->y;
						$max_y = 0;
					}
				}
			}
		}
	}

	function str($string, $max = 35) {
		if (strlen($string) > $max) {
			return substr($string, 0, $max-2).'..';
		}
		return $string;
	}


}