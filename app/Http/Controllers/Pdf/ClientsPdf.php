<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\UserHelper;
use fpdf;
require_once(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

/**
 * PDF con el estado de cuenta de varios clientes a la vez: nombre, saldo, teléfono, vendedor y
 * descripción, una fila por cliente. Lo pide el listado de Clientes (embudo → "PDF") con los
 * clientes seleccionados o con los filtrados (`GET client/pdf`, ClientController::pdf()).
 *
 * 🔴 No imprime ni corta el proceso: generar() devuelve el binario y el controller lo responde.
 * Hasta el 5/10/2026 el constructor hacía `Output(); exit;` y además nunca le pasaba el dueño a
 * PdfHelper::header(), que lo necesita para el logo: el PDF daba 500 siempre ("Undefined index:
 * user", misión pdf-estados-de-cuenta-clientes).
 */
class ClientsPdf extends fpdf {

	/**
	 * @param  \Illuminate\Support\Collection  $clients  Los clientes a imprimir, YA acotados al dueño.
	 * @param  \App\Models\User  $user  El dueño de la cuenta (UserHelper::getFullModel()): logo,
	 *                                  nombre del negocio y extensiones.
	 */
	function __construct($clients, $user) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;

		$this->user = $user;
		$this->clients = $clients;

		// La columna "Saldo USD" sale con la misma regla que en la tabla de clientes de la SPA
		// (models/client.js): solo si el negocio vende en dólares.
		$this->con_saldo_en_dolares = UserHelper::hasExtencion('ventas_en_dolares', $user);
	}

	/**
	 * Dibuja el PDF y devuelve el binario.
	 *
	 * @return string
	 */
	function generar() {
		$this->AddPage();
		$this->clients();

		return $this->Output('S');
	}

	/**
	 * Las columnas de la tabla y su ancho en mm. Suman 200 en los dos casos: el ancho útil de la
	 * hoja A4 con 5 mm de margen de cada lado.
	 *
	 * @return array
	 */
	function getFields() {
		if ($this->con_saldo_en_dolares) {
			return [
				'Nombre' 		=> 60,
				'Saldo' 		=> 30,
				'Saldo USD' 	=> 30,
				'Telefono' 		=> 28,
				'Vendedor' 		=> 26,
				'Descripcion' 	=> 26,
			];
		}

		return [
			'Nombre' 		=> 70,
			'Saldo' 		=> 40,
			'Telefono' 		=> 30,
			'Vendedor' 		=> 30,
			'Descripcion' 	=> 30,
		];
	}

	function Header() {
		$data = [
			'title' 			=> 'Clientes',
			'fields' 			=> $this->getFields(),
			'user' 				=> $this->user,
		];
		PdfHelper::header($this, $data);
	}

	function Footer() {
	}

	/**
	 * Una fila por cliente. Al pasar el final de la hoja abre otra; el encabezado de la hoja nueva
	 * (Header(), que corre solo con el AddPage) deja el cursor debajo de la fila de títulos.
	 */
	function clients() {
		foreach ($this->clients as $client) {
			if ($this->y >= 280) {
				$this->AddPage();
			}
			$this->printClient($client);
		}
	}

	function printClient($client) {

		// El encabezado de cada hoja deja la fuente en negrita: cada fila la vuelve a la normal.
		$this->SetFont('Arial', '', 10);
		$fields = $this->getFields();

		$this->x = 5;
		$this->Cell($fields['Nombre'], $this->line_height, $this->recortar_al_ancho($client->name, $fields['Nombre']), $this->b, 0, 'L');

		// El saldo vivo es el de la cuenta corriente en pesos (`saldo_pesos`, lo sincroniza
		// CurrentAcountHelper desde credit_accounts), el mismo que muestra la tabla. La columna
		// `clients.saldo` es de antes de la multimoneda y no la mantiene nadie.
		$this->Cell($fields['Saldo'], $this->line_height, '$'.Numbers::price((float) $client->saldo_pesos), $this->b, 0, 'L');

		if ($this->con_saldo_en_dolares) {
			$this->Cell($fields['Saldo USD'], $this->line_height, 'USD '.Numbers::price((float) $client->saldo_dolares), $this->b, 0, 'L');
		}

		$this->Cell($fields['Telefono'], $this->line_height, $this->recortar_al_ancho($client->phone, $fields['Telefono']), $this->b, 0, 'L');

		$seller = null;
		if (!is_null($client->seller)) {
			$seller = $client->seller->name;
		}
		$this->Cell($fields['Vendedor'], $this->line_height, $this->recortar_al_ancho($seller, $fields['Vendedor']), $this->b, 0, 'L');

		// La descripción es la única que puede ocupar varios renglones.
		$this->MultiCell($fields['Descripcion'], $this->line_height, $client->description, $this->b, 'L', false);

		$this->Line(5, $this->y, 205, $this->y);
	}

	/**
	 * Acorta un texto para que no invada la columna de al lado (un Cell() no corta: el nombre largo
	 * se imprimía encima del saldo). Mide con el texto ya pasado a Latin-1, que es como lo imprime
	 * el Cell() del fpdf del proyecto, y corta por caracteres, no por bytes, para no partir una ñ.
	 *
	 * @param  string|null  $texto
	 * @param  int  $ancho  Ancho de la columna en mm.
	 * @return string
	 */
	function recortar_al_ancho($texto, $ancho) {
		$texto = (string) $texto;

		// 2 mm de aire para que el texto no quede pegado a la columna siguiente.
		$ancho_util = $ancho - 2;

		if ($this->GetStringWidth(utf8_decode($texto)) <= $ancho_util) {
			return $texto;
		}

		while (mb_strlen($texto) > 0 && $this->GetStringWidth(utf8_decode($texto.'...')) > $ancho_util) {
			$texto = mb_substr($texto, 0, -1);
		}

		return $texto.'...';
	}

}
