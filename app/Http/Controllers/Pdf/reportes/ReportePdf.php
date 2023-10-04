<?php

namespace App\Http\Controllers\Pdf\Reportes;

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\CajaChartsHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\User;
use Carbon\Carbon;
use App\Http\Controllers\CommonLaravel\Helpers\StringHelper;

require(__DIR__.'/../../CommonLaravel/fpdf-charts/diagrams.php');
use PDF_Diag;

class ReportePdf extends PDF_Diag {

	function __construct($company_name, $title, $periodo) {
		set_time_limit(999999);

		$this->pdf = new PDF_Diag();
		$this->pdf->SetAutoPageBreak(true, 1);

		$this->user = User::where('company_name', $company_name)
							->first();
		$this->title = $title;
		$this->periodo = $periodo;

		$this->b = 0;
		$this->setDates();

		$this->setColors();

		$this->setData();	
	}

	function _output() {
		$this->pdf->Output();
	}

	function print($title, $sub_title, $data_prop, $pie_full_width = false, $is_price = false, $name_width = null) {
		$this->pdf->AddPage();
		$this->Header();

		$this->pdf->y += 20;

		//Pie chart
        $this->pdf->Image('https://api.comerciocity.com/public/storage/piechart.png', 10, $this->pdf->y, 15, 15);
		
		$this->pdf->SetFont('Arial', 'B', 18);
		$this->pdf->x = 27;
		$this->pdf->Cell(120, 10, $title, $this->b, 1, 'L');
		
		if (!is_null($sub_title)) {
			$this->pdf->SetFont('Arial', '', 14);
			$this->pdf->x = 27;
			$this->pdf->Cell(120, 5, $sub_title, $this->b, 1, 'L');
		}
		$this->pdf->Ln(8);

		$this->pdf->SetFont('Arial', '', 10);
		$valX = $this->pdf->GetX();
		$valY = $this->pdf->GetY();

		$colors = [];
		$r = 00;
		$g = 00;
		$b = 255;
		$index = 1;
		if (is_null($name_width)) {
			if ($pie_full_width) {
				$name_width = 80;
			} else {
				$name_width = 50;
			}
		}
		foreach ($this->data[$data_prop] as $_data) {
			$this->pdf->Cell($name_width, 7, $index.'- '.StringHelper::short($_data['name'], 70));

			if ($is_price) {
				$amount = '$'.Numbers::price($_data['amount']);
			} else {
				$amount = $_data['amount'];
			}
			$this->pdf->Cell(15, 7, $amount, 0, 0, 'R');
			$this->pdf->Ln();

			$index++;

			$colors[] = $this->colors[$index];
		}


		if ($pie_full_width) {
			$pie_width = 200;
			$pie_height = 100;
			$this->pdf->SetXY(10, $valY+90);
		} else {
			$this->pdf->SetXY(80, $valY);
			// $this->pdf->SetXY(80, $valY+20);
			$pie_width = 100;
			$pie_height = 200;
			// $pie_height = count($this->data[$data_prop]) * 20;
		}

		$this->pdf->PieChart($pie_width, $pie_height, $this->getChartData($this->data[$data_prop]), '%l (%p)', $colors, $is_price);
		$this->pdf->SetXY($valX, $valY + 40);

        // bar char
        if ($pie_full_width) {
        	$this->pdf->AddPage();
			$this->pdf->y = 30;
        } else {
			$this->pdf->y = 170;
        }

        $this->pdf->Image('https://api.comerciocity.com/public/storage/barchart.png', 10, $this->pdf->y, 15, 15);

		$this->pdf->SetFont('Arial', 'B', 16);
		$this->pdf->x = 27;
		$this->pdf->Cell(0, 15, 'Diagrama detallado para un mejor analisis', 0, 1);
		$this->pdf->Ln(8);
		$valX = $this->pdf->GetX();
		$valY = $this->pdf->GetY();
		$this->pdf->BarDiagram(190, 70, $this->getChartData($this->data[$data_prop]), '%l : %v (%p)', [13,131,0], 0, 4, $is_price);
		$this->pdf->SetXY($valX, $valY + 80);
	}

	function setColors() {
		$this->colors = [
			[],
			[236, 112, 99],
			[175, 122, 197],
			[84, 153, 199 ],
			[72, 201, 176 ],
			[39, 174, 96 ],
			[241, 196, 15],
			[243, 156, 18],
			[211, 84, 0],
			[236, 240, 241 ],
			[131, 145, 146 ],
			[112, 123, 124 ],
		];
	}

	function Header() {

		$this->pdf->x = 10;
		$this->pdf->y = 10;

		$this->pdf->SetFont('Arial', 'B', 16);
        $this->pdf->Image('https://api.comerciocity.com/public/storage/company.png', 10, 12, 10, 10);
		$this->pdf->x = 23;
		$this->pdf->Cell(120, 13, $this->user->company_name, $this->b, 1, 'L');
		
        $this->pdf->Image('https://api.comerciocity.com/public/storage/clientes.png', 10, 24, 10, 10);
		$this->pdf->x = 23;
		$this->pdf->Cell(120, 13, $this->title, $this->b, 1, 'L');

		$this->pdf->SetFont('Arial', '', 13);
		$this->pdf->x = 10;
        $this->pdf->Image('https://api.comerciocity.com/public/storage/calendar.png', 10, 38, 10, 10);
		$this->pdf->x = 23;
		$text = 'Periodo desde '.date_format($this->from_date, 'd/m/y').' hasta '.date_format($this->until_date, 'd/m/y');
		// if ($this->periodo == 'semanal') {
		// 	$text .= '. Semana '.$this->from_date->weekOfMonth();
		// } 
		$this->pdf->Cell(120, 13, $text, $this->b, 1, 'L');



        if (!is_null($this->user->image_url) || env('APP_ENV') == 'local') {
        	if (env('APP_ENV') == 'local') {
        		// $this->pdf->Image('https://fenix-mayorista.com.ar/img/icon.c48d046f.png', 160, 10, 40, 40);
	        	$this->pdf->Image($this->user->image_url, 160, 10, 40, 40);
        	} else {
	        	$this->pdf->Image($this->user->image_url, 160, 10, 40, 40);
        	}
        }

        $this->pdf->Line(10, 55, 200, 55);
	}

	function getChartData($models) {
		$data = [];
		foreach ($models as $model) {
			$data[$model['name']] = $model['amount'];
		}
		return $data;
	}

	function setDates() {
		if ($this->periodo == 'semanal') {
			$this->from_date = Carbon::now()->startOfWeek();
			$this->until_date = Carbon::now()->endOfWeek();
		} else if ($this->periodo == 'mensual') {
			$this->from_date = Carbon::now()->subMonth()->startOfMonth();
			$this->until_date = Carbon::now()->subMonth()->endOfMonth();
			// $this->until_date = Carbon::now()->subMonth()->endOfMonth();
		}
	}

	function setData() {
		$this->data = CajaChartsHelper::charts(null, $this->from_date, $this->until_date, $this->user->id);
		$this->data['article'] = array_slice($this->data['article'], 0, 10); 
		$this->data['category'] = array_slice($this->data['category'], 0, 10); 
		$this->data['sub_category'] = array_slice($this->data['sub_category'], 0, 10); 
		$this->data['metodos_de_pago'] = array_slice($this->data['metodos_de_pago'], 0, 10); 
		$this->data['clientes_cantidad_ventas'] = array_slice($this->data['clientes_cantidad_ventas'], 0, 10); 
		$this->data['clientes_monto_gastado'] = array_slice($this->data['clientes_monto_gastado'], 0, 10); 
		return;

		$this->data = [
			'article' => [
		        [
		          'name' =>  'Llavero personaje gde',
		          'amount' => 29,
		        ],
		        [
		          'name' =>  'Luz alambre multicolor',
		          'amount' => 20,
		        ],
		        [
		          'name' =>  'Peluche Sonic grande',
		          'amount' => 12,
		        ],
		        [
		          'name' =>  'Gancho color med',
		          'amount' => 12,
		        ],
		        [
		          'name' =>  'Regla 20cm Dura',
		          'amount' => 12,
		        ],
		        [
		          'name' =>  'Pop sh',
		          'amount' => 12,
		        ],
		        [
		          'name' =>  'Carta poker',
		          'amount' => 8,
		        ],
		        [
		          'name' =>  'Lego Sh',
		          'amount' => 8,
		        ],
		        [
		          'name' =>  'Labial matte tejar',
		          'amount' => 7,
		        ],
		        [
		          'name' =>  'Peluche Pokemon',
		          'amount' => 7,
		        ],
			],

		  	'category' => [
		        [
		          'name' => 'juguete comun',
		          'amount' => 177,
		        ],
		        [
		          'name' => 'personaje',
		          'amount' => 146,
		        ],
		        [
		          'name' => 'varios importador',
		          'amount' => 47,
		        ],
		        [
		          'name' => 'varios importado',
		          'amount' => 47,
		        ],
		        [
		          'name' => 'maquillaje',
		          'amount' => 33,
		        ],
		  	],
		  	'sub_category' => [
		        [
		          'name' => 'peluche (personaje)',
		          'amount' => 45,
		        ],
		        [
		          'name' => 'accesorios (varios importado)',
		          'amount' => 19,
		        ],
		        [
		          'name' => 'pop (personaje)',
		          'amount' => 19,
		        ],
		        [
		          'name' => 'lego (personaje)',
		          'amount' => 17,
		        ],
		        [
		          'name' => 'personaje nene (personaje)',
		          'amount' => 15,
		        ],
		        [
		          'name' => 'maquillaje tiny (juguete comun)',
		          'amount' => 14,
		        ],
		        [
		          'name' => 'Burbujeros (juguete comun)',
		          'amount' => 14,
		        ],
		        [
		          'name' => 'autos.moto.camion (juguete comun)',
		          'amount' => 13,
		        ],
		        [
		          'name' => 'libreria (varios importador)',
		          'amount' => 12,
		        ],
		        [
		          'name' => 'mate.termo.bombilla.acc (varios importador)',
		          'amount' => 11, 
		        ],
		  	],
			'clientes_cantidad_ventas' => [
				[
				  'name' => 'GABINO CURUCHET',
				  'amount' => 1,
				],
				[
				  'name' => 'PARRA RICARDO',
				  'amount' => 1,
				],
				[
				  'name' => 'CEJAS CARLOS',
				  'amount' => 1,
				],
				[
				  'name' => 'VARIOS',
				  'amount' => 7,
				],
				[
				  'name' => 'CARLOS DANIEL VALDEZ',
				  'amount' => 1,
				],
				[
				  'name' => 'GERMAN FLORES',
				  'amount' => 1,
				],
				[
				  'name' => 'GUZMAN VICTOR',
				  'amount' => 1,
				],
				[
				  'name' => 'LENARDIS SERGIO EMMANUEL',
				  'amount' => 1,
				],
				[
				  'name' => 'RAUL HETZER FRIO LITORAL',
				  'amount' => 1,
				],
				[
				  'name' => 'JUAN DEMARCHI',
				  'amount' => 2,
				],
			],
			'clientes_monto_gastado' => 	[
				[
			      'name' => 'GABINO CURUCHET',
			      'amount' => 11717.947,
				],
			  	[
			      'name' => 'PARRA RICARDO',
			      'amount' => 14424.721,
			  	],
			  	[
			      'name' => 'CEJAS CARLOS',
			      'amount' => 11006.5225,
			  	],
			  	[
			      'name' => 'VARIOS',
			      'amount' => 62376.907,
			  	],
			  	[
			      'name' => 'CARLOS DANIEL VALDEZ',
			      'amount' => 188884.6420032,
			  	],
			  	[
			      'name' => 'GERMAN FLORES',
			      'amount' => 5869.539,
			  	],
			  	[
			      'name' => 'GUZMAN VICTOR',
			      'amount' => 8927.28,
			  	],
			  	[
			      'name' => 'LENARDIS SERGIO EMMANUEL',
			      'amount' => 0,
			  	],
			  	[
			      'name' => 'RAUL HETZER FRIO LITORAL',
			      'amount' => 295598.7584308,
			  	],
			  	[
			      'name' => 'JUAN DEMARCHI',
			      'amount' => 8922.535,
			  	],
			],
			'metodos_de_pago' => [
				[
				  'name' => 'Efectivo',
				  'amount' => 1491931.31,
				],
				[
				  'name' => 'Debito',
				  'amount' => 0,
				],
				[
				  'name' => 'Cheque',
				  'amount' => 0,
				],
			],
		];
	}

}