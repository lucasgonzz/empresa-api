<?php

namespace App\Http\Controllers\Pdf; 

use App\Http\Controllers\Helpers\SaleDeliveryInfoHelper;
use App\Http\Controllers\Helpers\UserHelper;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class EtiquetaEnvioPdf extends fpdf {

	/**
	 * @param \App\Models\Sale $sale Venta con cliente y datos de envío.
	 * @param \App\Models\SaleSenderInfo $sender Remitente (negocio) para la cabecera derecha.
	 */
	function __construct($sale, $sender) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 1;
		$this->line_height = 10;

		$this->sale = $sale;
		$this->sender = $sender;

		$this->user = UserHelper::getFullModel();
		$this->AddPage();

		$this->print();

        $this->Output();
        exit;
	}

	function print() {


		// Logo
		$logo = $this->user->image_url;

		if (config('app.APP_ENV') == 'local') {
			$logo = 'https://img.freepik.com/vector-gratis/fondo-plantilla-logo_1390-55.jpg';
		}

        $this->Image($logo, 10, 5, 70, 70);


		$this->SetFont('Arial', '', 12);

        $this->y = 20;

		// Cabecera derecha: datos del negocio (SaleSenderInfo); localidad y provincia son texto libre.
		$sender_locality = (string) ($this->sender->localidad ?? '');
		$sender_province = (string) ($this->sender->provincia ?? '');
		$sender_postal = (string) ($this->sender->postal_code ?? '');

        $this->x = 105;
		$this->Cell(100, $this->line_height, 'Nombre: '.$this->sender->name, $this->b, 1, 'L');

        $this->x = 105;
		$this->Cell(100, $this->line_height, 'Mail: '.($this->sender->mail ?? ''), $this->b, 1, 'L');

        $this->x = 105;
		$this->Cell(100, $this->line_height, 'Cuit: '.($this->sender->cuit ?? ''), $this->b, 1, 'L');

        $this->x = 105;
		$this->Cell(100, $this->line_height, 'Codigo Postal: '.$sender_postal, $this->b, 1, 'L');

        $this->x = 105;
		$this->Cell(100, $this->line_height, 'Localidad: '.$sender_locality, $this->b, 1, 'L');

		$this->x = 105;
		$this->Cell(100, $this->line_height, 'Provincia: '.$sender_province, $this->b, 1, 'L');
		
		


		// Datos de envío: cliente + overrides SaleDeliveryInfo (si existen).
		$delivery = SaleDeliveryInfoHelper::resolved_for_etiqueta_pdf($this->sale);

		// Izquierda
		$this->SetFont('Arial', 'B', 12);

		$this->y = 90;
		$this->x = 5;

		$this->Cell(100, $this->line_height, 'Nombre: '.$delivery['first_name'], $this->b, 1, 'L');

		$this->x = 5;
		$this->Cell(100, $this->line_height, 'Apellido: '.$delivery['last_name'], $this->b, 1, 'L');

		$this->x = 5;
		$this->Cell(100, $this->line_height, 'Tel/Cel: '.$delivery['phone'], $this->b, 1, 'L');

		$this->x = 5;
		$this->Cell(100, $this->line_height, 'DNI: '.$delivery['document'], $this->b, 1, 'L');

		// Derecha
		$this->y = 90;
		$this->x = 105;

		$this->Cell(100, $this->line_height, 'Localidad: '.$delivery['locality'], $this->b, 1, 'L');

		$this->x = 105;
		$this->Cell(100, $this->line_height, 'Provincia: '.$delivery['province'], $this->b, 1, 'L');

		$this->x = 105;
		$this->Cell(100, $this->line_height, 'Código Postal: '.$delivery['postal_code'], $this->b, 1, 'L');

		$this->x = 105;
		$this->Cell(100, $this->line_height, 'Mail: '.$delivery['email'], $this->b, 1, 'L');
	}

}