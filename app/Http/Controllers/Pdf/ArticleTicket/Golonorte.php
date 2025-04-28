<?php

namespace App\Http\Controllers\Pdf\ArticleTicket;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\CommonLaravel\Helpers\StringHelper;


class Golonorte {

	function __construct($instance, $articles) {
		$this->instance = $instance;
		$this->articles = $articles;

		$this->ancho_tarjeta = 105;

		$this->alto_tarjeta = 29;

		$this->alto_nombre = 8;

		$this->ancho_precio_principal = 70;

		$this->ancho_rango = 35;

		$this->border = 0;
	}

	function print() {

		foreach ($this->articles as $article) {
			
			if ($this->instance->x == 0) {
				$this->start_x = 0;

			} else if ($this->instance->x == 105) {
				$this->start_x = 105;

				$this->instance->y -= $this->alto_tarjeta;
			} else if ($this->instance->x == 210) {
				$this->start_x = 0;
			}

			$this->printArticle($article);

			$this->lineas();
		}
	}

	function lineas() {

		// Derecha
		$this->instance->Line(
			$this->instance->x, 
			$this->instance->y - $this->alto_tarjeta, 

			$this->instance->x, 
			$this->instance->y
		);

		// Abajo
		$this->instance->Line(
			$this->instance->x - $this->ancho_tarjeta,
			$this->instance->y, 
			$this->instance->x, 
			$this->instance->y
		);

	}

	function printArticle($article) {

		if ($this->instance->y >= 270) {
			$this->instance->AddPage();
			$this->instance->y = 0;
			$this->instance->x = 0;
		}

		if (!is_null($article)) {

			$this->start_y = $this->instance->y;

			$this->instance->x = $this->start_x;

			$this->nombre($article);
			
			$this->instance->x = $this->start_x;

			$this->precios($article);


			$this->instance->x = $this->start_x;
			$this->instance->x += $this->ancho_tarjeta;
			$this->instance->y = $this->start_y + $this->alto_tarjeta;

			$this->linea_precios();
		}

	}

	function nombre($article) {

		$this->instance->SetFont('Arial', 'B', 15);
		$this->instance->Cell($this->ancho_tarjeta, $this->alto_nombre, StringHelper::short($article->name, 38), 1, 1, 'L');
	}

	function linea_precios() {
		$this->instance->Line($this->instance->x - $this->ancho_rango, $this->instance->y - $this->alto_tarjeta + 8, $this->instance->x - $this->ancho_rango, $this->instance->y);
	}

	function precios($article) {

		$index = 1;

		if (
			!is_null($article->sub_category)
			&& count($article->sub_category->category_price_type_ranges) >= 1
		) {

			foreach ($article->sub_category->category_price_type_ranges as $range) {

				$this->print_price_ranges($article, $range, $index);
				$index++;
			}

		} else if (
			!is_null($article->category)
			&& count($article->category->category_price_type_ranges) >= 1
		) {

			foreach ($article->category->category_price_type_ranges as $range) {

				if (is_null($range->sub_category_id)) {

					$this->print_price_ranges($article, $range, $index);
					$index++;
				}

			}
		}

	}

	function print_price_ranges($article, $range, $es_el_primero) {
			
		$article_price_type = $article->price_types->firstWhere('id', $range->price_type_id);

		$this->instance->x = $this->start_x;

		
		if (
			!is_null($range->min)
			&& $range->min > 1
			&& $article_price_type->pivot->price 
			&& !$article_price_type->ocultar_al_publico
		) {

			$this->print_rango($article_price_type, $range);
			
		} 
		
		if ($es_el_primero == 1) {

			// Precio Principal
			
			// $alto = 21;
			// $this->instance->SetFont('Arial', 'B', 15);
			// $this->instance->x -= 1;
			// $this->instance->Cell(3, $alto/1.5, '$', $this->border, 0, 'L');

			// $this->instance->SetFont('Arial', 'B', 52);
			// $this->instance->Cell($this->ancho_precio_principal-3, $alto, Numbers::price($article_price_type->pivot->price), $this->border, 0, 'L');




			$this->instance->y += 3;
			
			$this->instance->SetFont('Arial', '', 10);
			$this->instance->x += $this->ancho_precio_principal;
			$this->instance->x += 2;


			$alto = 10;
			$this->instance->SetFont('Arial', 'B', 12);

			$this->instance->x -= 1;
			$this->instance->y -= 1;
			$this->instance->Cell(2, $alto/1.5, '$', $this->border, 0, 'L');
			$this->instance->y += 1;

			$this->instance->SetFont('Arial', 'B', 27);
			$this->instance->Cell($this->ancho_rango, $alto, Numbers::price($article_price_type->pivot->price), $this->border, 1, 'L');

			// $this->instance->y += 3;
		
		} 
		
	}

	function print_rango($article_price_type, $range) {

			
		$alto = 15;
		$this->instance->y = $this->start_y + $this->alto_nombre;

		$this->instance->SetFont('Arial', '', 12);
		
		$this->instance->Cell($this->ancho_precio_principal, 6, 'A partir de '.$range->min.' unid:', $this->border, 1, 'C');

		$this->instance->x = $this->start_x;

		$this->instance->Cell(3, $alto/1.5, '$', $this->border, 0, 'L');

		$this->instance->SetFont('Arial', 'B', 52);
		$this->instance->Cell($this->ancho_precio_principal-3, $alto, Numbers::price($article_price_type->pivot->price), $this->border, 0, 'L');




		// $this->instance->y += 3;
		
		// $this->instance->SetFont('Arial', '', 10);
		// $this->instance->x += $this->ancho_precio_principal;
		// $this->instance->x += 2;

		// $this->instance->Cell($this->ancho_rango, 6, 'A partir de: '.$range->min.' unid', $this->border, 1, 'LC');


		// $this->instance->x = $this->start_x + $this->ancho_precio_principal;
		// $this->instance->x += 2;
		

		// $alto = 10;
		// $this->instance->SetFont('Arial', 'B', 12);

		// $this->instance->x -= 1;
		// $this->instance->y -= 1;
		// $this->instance->Cell(2, $alto/1.5, '$', $this->border, 0, 'L');
		// $this->instance->y += 1;

		// $this->instance->SetFont('Arial', 'B', 27);
		// $this->instance->Cell($this->ancho_rango, $alto, Numbers::price($article_price_type->pivot->price), $this->border, 1, 'L');

		// $this->instance->y += 3;

	}

}