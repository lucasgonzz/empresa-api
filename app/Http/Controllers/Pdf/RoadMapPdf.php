<?php

namespace App\Http\Controllers\Pdf; 

use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class RoadMapPdf extends fpdf {



	function __construct($model) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;
		
		$this->user = UserHelper::getFullModel();
		$this->road_map = $model;

		$this->fields = [

			'Producto' => 130,

			'Cantidad' => 30,

			'Precio' => 40,
		];

		$this->AddPage();
		$this->clientes();
        $this->Output();
        exit;
	}

	function Header() {


		// N° de Hoja de ruta
		$this->titulo();

		$this->fecha_entrega();

		$this->repartidor();
		
		$this->notas();
	}

	function titulo() {

		$this->SetFont('Arial', 'B', 22);

		$this->x = 5;
		$this->y = 5;
		
		$this->Cell(200, 10, 'Hoja de Ruta N° '.$this->road_map->num, $this->b, 1, 'L');
	}

	function fecha_entrega() {

		$this->SetFont('Arial', '', 16);

		$this->x = 5;
		
		$this->Cell(200, 10, 'Fecha de entrega: '.date_format($this->road_map->fecha_entrega, 'd/m/Y'), $this->b, 1, 'L');
	}

	function repartidor() {
		$this->SetFont('Arial', '', 16);
		$this->x = 5;
		$this->Cell(200, 10, 'Repartidor: '.$this->road_map->employee->name, $this->b, 1, 'L');
	}

	function notas() {
		if (!is_null($this->road_map->notes)) {

			$this->SetFont('Arial', 'B', 16);

			$this->x = 5;

			$this->Cell(200, 10, 'Notas: '.$this->road_map->notes, $this->b, 1, 'L');
		}
	}

	function Footer() {
		// $this->observations();
		// $this->total();
		// PdfHelper::comerciocityInfo($this, $this->y);
	}

	function clientes() {

		$this->y += 5;
		$this->x = 5;

		foreach ($this->road_map->clientes as $cliente) {
			if ($this->y < 210) {
				$this->print_cliente($cliente);
			} else {
				$this->AddPage();
				$this->x = 5;
				$this->y = 90;
				$this->print_cliente($cliente);
			}
		}
	}

	function print_cliente($cliente) {

		$this->Line(5, $this->y, 205, $this->y);

		$this->SetFont('Arial', 'B', 14);

		$this->x = 5;

		$this->Cell(200, 10, $cliente['client']->name, $this->b, 1, 'L');


		$this->SetFont('Arial', '', 12);

		$this->x = 5;
		$this->Cell(200, 7, $this->total_productos($cliente['sales']).' productos', $this->b, 1, 'L');

		$this->x = 5;
		$this->Cell(200, 7, 'Telefono: '.$cliente['client']->phone, $this->b, 1, 'L');

		$this->x = 5;
		$this->Cell(200, 7, 'Direccion: '.$cliente['client']->address, $this->b, 1, 'L');

		$this->x = 5;
		$this->Cell(200, 7, 'Total a cobrar: '.$this->total_a_cobrar($cliente['sales']), $this->b, 1, 'L');


		$this->productos($cliente['sales']);

		$this->y += 10;
		// $this->Line(5, $this->y, 205, $this->y);
		
	}

	function productos($sales) {

		$this->table_header();

		$this->SetFont('Arial', '', 12);

		foreach ($sales as $sale) {

			$this->articles($sale);

			$this->promocion_vinotecas($sale);
		}
	}

	function articles($sale) {
			
		foreach ($sale->articles as $article) {

			$this->x = 5;
			
			$this->Cell($this->fields['Producto'], 7, $article->name, 1, 0, 'L');

			$this->Cell($this->fields['Cantidad'], 7, $article->pivot->amount, 1, 0, 'L');
			$this->Cell($this->fields['Precio'], 7, '$'.Numbers::price($article->pivot->price), 1, 0, 'L');
			
			$this->y += 7;
			
		}

	}

	function promocion_vinotecas($sale) {
			
		foreach ($sale->promocion_vinotecas as $promo) {

			$this->x = 5;
			
			$this->Cell($this->fields['Producto'], 7, $promo->name, 1, 0, 'L');

			$this->Cell($this->fields['Cantidad'], 7, $promo->pivot->amount, 1, 0, 'L');
			$this->Cell($this->fields['Precio'], 7, '$'.Numbers::price($promo->pivot->price), 1, 0, 'L');
			
			$this->y += 7;
			
		}

	}

	function table_header() {

		$this->SetFont('Arial', 'B', 14);

		$this->x = 5;
		
		$header_height = 10;

		foreach ($this->fields as $title => $width) {
			$this->Cell($width, $header_height, $title, 1, 0, 'L');
		}

		$this->y += $header_height;	

	}

	function total_a_cobrar($sales) {
		$total = 0;
		foreach ($sales as $sale) {
			$total += $sale->total;
		}
		return '$'.Numbers::price($total);
	}

	function total_productos($sales) {
		$total = 0;

		foreach ($sales as $sale) {

			foreach ($sale->articles as $article) {

				$total += (float)$article->pivot->amount;
			}
			
			foreach ($sale->promocion_vinotecas as $promo) {

				$total += (float)$promo->pivot->amount;
			}
			
		}
		return $total;
	}
}