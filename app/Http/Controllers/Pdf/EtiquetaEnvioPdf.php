<?php

namespace App\Http\Controllers\Pdf; 

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
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

class EtiquetaEnvioPdf extends fpdf {

	function __construct($sale) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 1;
		$this->line_height = 10;

		$this->sale = $sale;

		$this->user = UserHelper::getFullModel();
		$this->AddPage();

		$this->print();

        $this->Output();
        exit;
	}

	function print() {


		// Logo
		$logo = $this->user->image_url;

		if (env('APP_ENV') == 'local') {
			$logo = 'https://img.freepik.com/vector-gratis/fondo-plantilla-logo_1390-55.jpg';
		}

        $this->Image($logo, 10, 5, 70, 70);


		$this->SetFont('Arial', '', 12);

        $this->y = 20;

        $this->x = 105;
		$this->Cell(100, $this->line_height, 'Mail: 2r.racing.p@gmail.com', $this->b, 1, 'L');
		
        $this->x = 105;
		$this->Cell(100, $this->line_height, 'Cuit: 33716718919', $this->b, 1, 'L');
		
        $this->x = 105;
		$this->Cell(100, $this->line_height, 'Codigo Postal: 1846', $this->b, 1, 'L');
		
        $this->x = 105;
		$this->Cell(100, $this->line_height, 'Localidad: Adrogue', $this->b, 1, 'L');
		
		


		// Datos de envio

		// Izquierda
		$this->SetFont('Arial', 'B', 12);

		$this->y = 80;
		$this->x = 5;

		$this->Cell(100, $this->line_height, 'Nombre: '.explode(' ', $this->sale->client->name)[0], $this->b, 1, 'L');
		
		$this->x = 5;
		$this->Cell(100, $this->line_height, 'Apellido: '.explode(' ', $this->sale->client->name)[1], $this->b, 1, 'L');
		
		$this->x = 5;
		$this->Cell(100, $this->line_height, 'Tel/Cel: '.$this->sale->client->phone, $this->b, 1, 'L');
		
		$this->x = 5;
		$this->Cell(100, $this->line_height, 'DNI: '.$this->sale->client->dni ?? $this->sale->client->cuit, $this->b, 1, 'L');



		// Derecha

		$this->y = 80;
		$this->x = 105;

		$this->Cell(100, $this->line_height, 'Localidad: '.optional($this->sale->client->location)->name ?? '', $this->b, 1, 'L');
		



		$this->x = 105;

		$provincia = '';
		if (
			!is_null($this->sale->client->location)
			&& !is_null($this->sale->client->location->provincia)
		) {
			$provincia = $this->sale->client->location->provincia->name;
		}

		$this->Cell(100, $this->line_height, 'Provincia: '.$provincia, $this->b, 1, 'L');
		


		$this->x = 105;

		$codigo = '';

		if (
			!is_null($this->sale->client->location)
			&& !is_null($this->sale->client->location->codigo_postal)
		) {
			$codigo = $this->sale->client->location->codigo_postal;
		}
		$this->Cell(100, $this->line_height, 'Código Postal: '.$codigo, $this->b, 1, 'L');
		
		$this->x = 105;
		$this->Cell(100, $this->line_height, 'Mail: '.$this->sale->client->email, $this->b, 1, 'L');
	}

}