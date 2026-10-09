<?php

namespace App\Http\Controllers\Pdf; 

use App\Http\Controllers\Helpers\SaleDeliveryInfoHelper;
use App\Http\Controllers\Helpers\UserHelper;
use fpdf;
/*
	| require_once y no require: el test de renglones_de_direccion() carga esta clase en un proceso
	| de phpunit donde otro PDF ya pudo declarar FPDF, y un require pelado volvería a ejecutar
	| fpdf.php y cortaría el proceso con "Cannot declare class FPDF". En producción cada request
	| arma un solo PDF y no cambia nada.
*/
require_once(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

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

		$this->SetFont('Arial', 'B', 12);

		// Dirección del destinatario (calle y número): a todo el ancho, arriba de las dos columnas.
		// Si no entra en un renglón se parte por palabras, hasta 3 renglones. Sin dirección sale el
		// rótulo solo, como el resto de los campos, para completarla a mano.
		$this->y = 90;
		foreach ($this->renglones_de_direccion($delivery['address']) as $renglon) {
			$this->x = 5;
			$this->Cell(200, $this->line_height, $renglon, $this->b, 1, 'L');
		}

		// Las dos columnas arrancan debajo de la dirección (que ocupa 1, 2 o 3 renglones).
		$y_columnas = $this->y;

		// Izquierda
		$this->y = $y_columnas;
		$this->x = 5;

		$this->Cell(100, $this->line_height, 'Nombre: '.$delivery['first_name'], $this->b, 1, 'L');

		$this->x = 5;
		$this->Cell(100, $this->line_height, 'Apellido: '.$delivery['last_name'], $this->b, 1, 'L');

		$this->x = 5;
		$this->Cell(100, $this->line_height, 'Tel/Cel: '.$delivery['phone'], $this->b, 1, 'L');

		// El rótulo dice qué documento es: DNI, CUIT, o DNI/CUIT si no hay ninguno.
		$this->x = 5;
		$this->Cell(100, $this->line_height, $delivery['document_label'].': '.$delivery['document'], $this->b, 1, 'L');

		// Derecha
		$this->y = $y_columnas;
		$this->x = 105;

		$this->Cell(100, $this->line_height, 'Localidad: '.$delivery['locality'], $this->b, 1, 'L');

		$this->x = 105;
		$this->Cell(100, $this->line_height, 'Provincia: '.$delivery['province'], $this->b, 1, 'L');

		$this->x = 105;
		$this->Cell(100, $this->line_height, 'Código Postal: '.$delivery['postal_code'], $this->b, 1, 'L');

		$this->x = 105;
		$this->Cell(100, $this->line_height, 'Mail: '.$delivery['email'], $this->b, 1, 'L');
	}

	/**
	 * Parte "Dirección: <calle y número>" en renglones que entren en la celda de 200 mm (el ancho
	 * útil es 200 menos el margen interno de la celda a cada lado), cortando por palabras.
	 *
	 * No se usa MultiCell: parte el string UTF-8 por bytes y puede cortar un acento al medio. Acá se
	 * mide con GetStringWidth(utf8_decode(...)), lo mismo que después imprime Cell (que ya hace el
	 * utf8_decode), y cada renglón sale en UTF-8.
	 *
	 * Tope de 3 renglones: lo que sobre se corta y el tercero termina en "...". Una palabra sola
	 * más ancha que el renglón (sin espacios) se corta por caracteres. Si la PRIMERA palabra no
	 * entra entera al lado del rótulo, se corta al ancho que queda en el primer renglón: si pasara
	 * entera al segundo, el primero quedaría con el rótulo solo y gastaría uno de los tres.
	 *
	 * @param string $address Dirección ya resuelta, en una sola línea (espacios colapsados).
	 * @return array<int, string> Entre 1 y 3 renglones.
	 */
	function renglones_de_direccion($address) {
		$ancho_util = 200 - 2 * $this->cMargin;
		$max_renglones = 3;

		$address = trim((string) $address);
		if ($address === '') {
			return ['Dirección: '];
		}

		$renglones = [];
		$actual = 'Dirección:';
		$es_la_primera = true;
		foreach (explode(' ', $address) as $palabra) {
			if ($palabra === '') {
				continue;
			}

			// $actual nunca queda vacío: arranca con el rótulo y después siempre es una palabra (o
			// lo que queda de una) con al menos un caracter.
			$candidato = $actual.' '.$palabra;
			if ($this->ancho_en_pdf($candidato) <= $ancho_util) {
				$actual = $candidato;
				$es_la_primera = false;
				continue;
			}

			if ($es_la_primera) {
				// La primera palabra no entra entera al lado del rótulo: se corta al ancho que queda
				// en el primer renglón, para que no quede con el rótulo solo.
				$pedazo = $this->cortar_al_ancho($palabra, $ancho_util - $this->ancho_en_pdf($actual.' '));
				$renglones[] = $actual.' '.$pedazo;
				$palabra = mb_substr($palabra, mb_strlen($pedazo, 'UTF-8'), null, 'UTF-8');
			} else {
				// No entra: se cierra el renglón actual y la palabra arranca el siguiente.
				$renglones[] = $actual;
			}
			$es_la_primera = false;

			// Lo que queda de una palabra más ancha que un renglón entero se corta por caracteres.
			while ($this->ancho_en_pdf($palabra) > $ancho_util) {
				$pedazo = $this->cortar_al_ancho($palabra, $ancho_util);
				$renglones[] = $pedazo;
				$palabra = mb_substr($palabra, mb_strlen($pedazo, 'UTF-8'), null, 'UTF-8');
			}

			$actual = $palabra;
		}

		$renglones[] = $actual;

		if (count($renglones) <= $max_renglones) {
			return $renglones;
		}

		// Sobra texto: quedan los tres primeros renglones y el último termina en "...".
		$renglones = array_slice($renglones, 0, $max_renglones);
		$ultimo = $renglones[$max_renglones - 1];
		while ($ultimo !== '' && $this->ancho_en_pdf($ultimo.'...') > $ancho_util) {
			$ultimo = mb_substr($ultimo, 0, mb_strlen($ultimo, 'UTF-8') - 1, 'UTF-8');
		}
		$renglones[$max_renglones - 1] = rtrim($ultimo).'...';

		return $renglones;
	}

	/**
	 * Ancho en mm que ocupa un texto UTF-8 con la fuente actual, medido como lo imprime Cell.
	 *
	 * @param string $texto Texto en UTF-8.
	 * @return float
	 */
	function ancho_en_pdf($texto) {
		return $this->GetStringWidth(utf8_decode($texto));
	}

	/**
	 * El prefijo más largo (por caracteres, no por bytes) de un texto que entra en el ancho dado.
	 * Devuelve al menos un caracter, para que el corte siempre avance.
	 *
	 * @param string $texto Texto en UTF-8.
	 * @param float $ancho Ancho disponible en mm.
	 * @return string
	 */
	function cortar_al_ancho($texto, $ancho) {
		$largo = mb_strlen($texto, 'UTF-8');
		$entra = 1;
		for ($i = 2; $i <= $largo; $i++) {
			if ($this->ancho_en_pdf(mb_substr($texto, 0, $i, 'UTF-8')) > $ancho) {
				break;
			}
			$entra = $i;
		}

		return mb_substr($texto, 0, $entra, 'UTF-8');
	}

}