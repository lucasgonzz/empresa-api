<?php

namespace App\Http\Controllers\Pdf; 

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\ImageHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Models\User;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class BudgetPdf extends fpdf {

	function __construct($budget, $with_prices, $with_images) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;
		
		$this->user = User::where('id', $budget->user_id)
							->withAll()
							->first();
							
		$this->budget = $budget;
		$this->with_prices = $with_prices;
		$this->with_images = $with_images;

		$this->total_original = 0;

		$this->AddPage();
		$this->items();
        $this->Output();
        exit;
	}

	function getFields() {
		if ($this->with_prices) {
			if ($this->with_images) {
				return [
					'Imagen'	=> 40,
					'Producto' 	=> 70,
					'Precio' 	=> 30,
					'Cant' 		=> 15,
					'Bonif' 	=> 15,
					'Importe' 	=> 30,
				];
			} else {
				return [
					'Producto' 	=> 100,
					'Precio' 	=> 30,
					'Cant' 		=> 15,
					'Bonif' 	=> 15,
					'Importe' 	=> 40,
				];
			}
		} else {
			return [
				'Codigo' 	=> 30,
				'Producto' 	=> 140,
				'Cant' 		=> 30,
			];
		}
	}

	function getModelProps() {
		return [
			[
				'text' 	=> 'Cliente',
				'key'	=> 'name',
			],
			[
				'text' 	=> 'Localidad',
				'key'	=> 'location.name',
			],
			[
				'text' 	=> 'Direccion',
				'key'	=> 'address',
			],
		];
	}

	function Header() {
		$address = $this->user->afip_information ? $this->user->afip_information->domicilio_comercial : null;
		$data = [
			'num' 				=> $this->budget->num,
			'date'				=> $this->budget->created_at,
			'address'			=> $address,
			'title' 			=> 'Presupuesto',
			'model_info'		=> $this->budget->client,
			'model_props' 		=> $this->getModelProps(),
			'fields' 			=> $this->getFields(),
		];
		PdfHelper::header($this, $data);
	}

	function Footer() {
		// $y = 230;
		// $this->SetLineWidth(.4);
		$this->observations();
		$this->discountsSurchages();
		$this->total();
		// PdfHelper::comerciocityInfo($this, $this->y);
	}

	function logo() {
        // Logo
        if (!is_null($this->user->image_url)) {
        	if (env('APP_ENV') == 'local') {
        		$this->Image('https://img.freepik.com/vector-gratis/fondo-plantilla-logo_1390-55.jpg', 17, 0, 0, 25);
        	} else {
	        	$this->Image($this->user->image_url, 17, 0, 0, 25);
        	}
        }
		
        // Company name
		$this->SetFont('Arial', 'B', 10);
		$this->x = 5;
		$this->y = 30;
		$this->Cell(100, 5, $this->user->company_name, $this->b, 0, 'C');

		// Info
		$this->SetFont('Arial', '', 10);
		$address = null;
		if (count($this->user->addresses) >= 1) {
			$address = $this->user->addresses[0];
		}
		$info = $this->user->afip_information->razon_social;
		$info .= ' / '. $this->user->afip_information->iva_condition->name;
		if (!is_null($address)) {
			$info .= ' / '. $address->street.' N° '.$address->street_number;
			$info .= ' / '. $address->city.' / '.$address->province;
		}
		$info .= ' / '. $this->user->phone;
		$info .= ' / '. $this->user->email;
		$info .= ' / '. $this->user->online;
		$this->x = 5;
		$this->y += 5;
	    $this->MultiCell(100, 5, $info, $this->b, 'L', false);
	    // $this->lineInfo();
	}

	function budgetDates() {
		if (!is_null($this->budget->start_at) && !is_null($this->budget->finish_at)) {
			$this->SetFont('Arial', '', 10);
			$this->x = 105;
			$this->y = 58;
			$this->Cell(100, 5, 'Fecha de entrega', $this->b, 0, 'L');
			$this->y += 5;
			$this->x = 105;
			$date = 'Entre el '.date_format($this->budget->start_at, 'd/m/Y').' y el '.date_format($this->budget->finish_at, 'd/m/Y');
			$this->Cell(100, 5, $date, $this->b, 0, 'L');
			// $this->lineDates();
		}
	}

	function items() {
		$this->SetFont('Arial', '', 10);
		$this->x = 5;
		
		// Articulos
		foreach ($this->budget->articles as $article) {
			if ($this->y < 210) {
				$this->printArticle($article);
			} else {
				$this->AddPage();
				$this->x = 5;
				$this->y = 55;
				$this->printArticle($article);
			}
		}

		// Promociones vinotecas
		foreach ($this->budget->promocion_vinotecas as $promo) {
			if ($this->y < 210) {
				$this->printArticle($promo);
			} else {
				$this->AddPage();
				$this->x = 5;
				$this->y = 55;
				$this->printArticle($promo);
			}
		}

		// Servicios
		foreach ($this->budget->services as $service) {
			if ($this->y < 210) {
				$this->printArticle($service);
			} else {
				$this->AddPage();
				$this->x = 5;
				$this->y = 55;
				$this->printArticle($service);
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

		$image_height = 0;

		if (
			$this->with_images
		) {

			if (
				isset($article->images) 
				&& count($article->images) >= 1
			) {

	            $url = $article->images[0]['hosting_url'];

	            if (env('APP_ENV') == 'local') {

	                $url = 'https://api-colman-prueba.comerciocity.com/public/storage/171699179550596.webp';
	            }


	            $img_url = GeneralHelper::getJpgImage($url);

		        $this->Image($img_url, 5, $this->y+1, 40, 40);

		        $image_height = 35;
			}

	    	
	    	$this->x += 40;
		}
	    

		// $this->Cell($this->getFields()['Codigo'], $this->line_height, $article->bar_code, $this->b, 0, 'L');
		$y_1 = $this->y;
		$this->MultiCell($this->getFields()['Producto'], $this->line_height, $article->name, $this->b, 'L', false);
		
		$this->x = PdfHelper::getWidthUntil('Producto', $this->getFields());
	    $y_2 = $this->y;
		$this->y = $y_1;
		
		if ($this->with_prices) {
			$this->Cell($this->getFields()['Precio'], $this->line_height, '$'.Numbers::price($article->pivot->price), $this->b, 0, 'L');
		}
		$this->Cell($this->getFields()['Cant'], $this->line_height, Numbers::price($article->pivot->amount), $this->b, 0, 'L');
		if ($this->with_prices) {
			$this->Cell($this->getFields()['Bonif'], $this->line_height, $this->getBonus($article), $this->b, 0, 'L');

			$total_article = BudgetHelper::totalArticle($article);
			$this->total_original += BudgetHelper::totalArticle($article, false);

			$this->Cell($this->getFields()['Importe'], $this->line_height, '$'.Numbers::price($total_article), $this->b, 0, 'R');
		}
		$this->y = $y_2;

		$this->y += $image_height;

		$this->Line(5, $this->y, 205, $this->y);
	}

	function getDeliveredArticles() {
		$articles = [];
		foreach ($this->budget->articles as $article) {
			if (count($article->pivot->deliveries) >= 1) {
				$articles[] = $article;
			}
		}
		return $products;
	}

	function getTotalDeliveries($product) {
		$total = 0;
		foreach ($product->deliveries as $delivery) {
			$total += $delivery->amount;
		}
		return $total;
	}

	function observations() {
		// $this->SetLineWidth(.2);
		if ($this->budget->observations != '') {
		    $this->x = 5;
		    $this->y += 5;
	    	$this->SetFont('Arial', 'B', 10);
			$this->Cell(100, $this->line_height, 'Observaciones', 0, 1, 'L');
		    $this->x = 5;
	    	$this->SetFont('Arial', '', 10);
	    	$this->MultiCell(200, $this->line_height, $this->budget->observations, $this->b, 'LTB', false);
	    	$this->x = 5;
		}
	}

	function getBonus($article) {
		if (!is_null($article->pivot->bonus)) {
			return Numbers::price($article->pivot->bonus).'%';
		}
		return '';
	}

	function discountsSurchages() {

		if ($this->with_prices) {

		    if ($this->total_original > $this->budget->total) {

		    	$this->SetFont('Arial', 'B', 12);
		    	$this->y += 5;
		    	$this->x = 5;

		    	$text = 'Sub Total sin descuentos: $'.Numbers::price($this->total_original);

		    	$diferencia = $this->total_original - $this->budget->total;

		    	// $text .= ' (Ahorro $' . Numbers::price($diferencia) . ')';

				$this->Cell(100, 7, $text, 0, 1, 'L');
		    }

		    $this->SetFont('Arial', '', 10);

		    foreach ($this->budget->discounts as $discount) {
				
		    	$this->x = 5;
				$this->Cell(100, 7, '- '.$discount->pivot->percentage.'% '.$discount->name, 0, 1, 'L');
		    }
		    foreach ($this->budget->surchages as $surchage) {

		    	$this->x = 5;
				$this->Cell(100, 7, '+ '.$surchage->pivot->percentage.'% '.$surchage->name, 0, 1, 'L');
		    }

		}


	}

	function total() {
		if ($this->with_prices) {
		    $this->x = 5;
		    $this->SetFont('Arial', 'B', 14);
			$this->Cell(200, 10, 'Total: $'. Numbers::price($this->budget->total), 0, 1, 'R');
			// $this->Cell(100, 10, 'Total: $'. Numbers::price(BudgetHelper::getTotal($this->budget)), 0, 1, 'L');
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