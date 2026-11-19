<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use fpdf;

require_once(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

/**
 * PDF de compra a proveedor (ProviderOrder): cabecera con datos del proveedor
 * y detalle de artículos pedidos (cantidad, costo, notas y totales por línea).
 */
class ProviderOrderPdf extends fpdf {

	/**
	 * Construye el PDF; el controlador entrega la respuesta HTTP.
	 *
	 * @param \App\Models\ProviderOrder $model Compra con relaciones cargadas (withAll)
	 */
	function __construct($model, array $resumen) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;

		$this->user = UserHelper::getFullModel();
		$this->model = $model;
		$this->resumen = $resumen;

		$this->AddPage();
		$this->print();
		$this->printResumen();
		$this->total();
	}

	/**
	 * Anchos de columnas del detalle de artículos.
	 *
	 * @return array<string, int>
	 */
	function getFields() {
		return [
			'C. Barras'  => 30,
			'Cód. Prov'  => 25,
			'Nombre'     => 40,
			'Costo'      => 25,
			'Cantidad'   => 20,
			'Notas'      => 25,
			'Total'      => 35,
		];
	}

	/**
	 * Propiedades del proveedor mostradas bajo el encabezado.
	 *
	 * @return array<int, array<string, string>>
	 */
	function getModelProps() {
		return [
			[
				'text' => 'Proveedor',
				'key'  => 'name',
			],
			[
				'text' => 'Telefono',
				'key'  => 'phone',
			],
			[
				'text' => 'Direccion',
				'key'  => 'address',
			],
		];
	}


	function Header() {
		$data = [
			'num'               => $this->model->num,
			'date'              => $this->model->created_at,
			'title'             => 'Compra',
			'title_font_size'   => 10,
			'titulo'            => $this->user->company_name,
			'model_info'        => $this->model->provider,
			'model_props'       => $this->getModelProps(),
			'fields'            => $this->getFields(),
			'user'              => $this->user,
			// 'extra_info'        => $this->getExtraInfo(),
		];
		PdfHelper::header($this, $data);
	}

	function Footer() {
	}

	/**
	 * Imprime todas las líneas de artículos de la compra.
	 */
	function print() {
		$this->SetFont('Arial', '', 10);
		$this->x = 5;
		$this->MultiCell(200, 6, 'Detalle de lo pedido (informativo). Los importes de este detalle no reemplazan el resumen registrado de la compra. Moneda de la compra: '.$this->resumen['moneda'].'.', 0, 'L');

		foreach ($this->model->articles as $article) {
			if ($this->y < 260) {
				$this->printArticle($article);
			} else {
				$this->AddPage();
				$this->x = 5;
				$this->printArticle($article);
			}
		}
	}

	/**
	 * Costo unitario de la línea (prioriza costo recibido si existe).
	 *
	 * @param \App\Models\Article $article
	 * @return float
	 */
	function getArticleUnitCost($article) {
		$cost = $article->pivot->cost;

		if (
			!is_null($article->pivot->received_cost)
			&& $article->pivot->received_cost !== ''
		) {
			$cost = $article->pivot->received_cost;
		}

		if ($cost === '' || is_null($cost)) {
			$cost = $article->pivot->price;
		}

		return (float) $cost;
	}

	/**
	 * Importe de la línea: costo unitario × cantidad pedida.
	 *
	 * @param \App\Models\Article $article
	 * @return float
	 */
	function getArticleLineTotal($article) {
		$unit_cost = $this->getArticleUnitCost($article);
		$amount = (float) $article->pivot->amount;

		return $unit_cost * $amount;
	}

	/**
	 * Texto del costo unitario para la celda (marca USD si aplica).
	 *
	 * @param \App\Models\Article $article
	 * @return string
	 */
	function getArticleCostText($article) {
		$unit_cost = $this->getArticleUnitCost($article);
		$text = '$'.Numbers::price($unit_cost);

		if ((boolean) $article->pivot->cost_in_dollars) {
			$text .= ' USD';
		}

		return $text;
	}

	/**
	 * Imprime una fila de artículo en el detalle.
	 *
	 * @param \App\Models\Article $article
	 */
	function printArticle($article) {
		$cantidad = 'Ped: '.$article->pivot->amount;
		if (!is_null($article->pivot->received) && $article->pivot->received !== '') {
			$cantidad .= ' / Rec: '.$article->pivot->received;
		}
		$alto = $this->line_height * max(
			$this->lineCount($this->getFields()['Nombre'], $article->name),
			$this->lineCount($this->getFields()['Notas'], $article->pivot->notes),
			$this->lineCount($this->getFields()['Cantidad'], $cantidad)
		);
		// No permitir que una columna cambie de página y después restaurar una Y de la anterior.
		if ($this->y + $alto > 265) {
			$this->AddPage();
			$this->SetFont('Arial', '', 10);
		}
		if ($alto > 200) {
			$this->x = 5;
			$this->MultiCell(200, 7, 'C. Barras: '.$article->bar_code.' | Cód. Prov: '.$article->provider_code.' | '.$article->name.' | '.$cantidad.' | Costo: '.$this->getArticleCostText($article).' | Importe pedido: '.Numbers::price($this->getArticleLineTotal($article)).($article->pivot->cost_in_dollars ? ' USD' : ' '.$this->resumen['moneda']).' | Notas: '.$article->pivot->notes, 0, 'L');
			return;
		}
		$this->x = 5;
		$y_1 = $this->y;

		$this->Cell($this->getFields()['C. Barras'], $this->line_height, $article->bar_code, $this->b, 0, 'L');
		$this->Cell($this->getFields()['Cód. Prov'], $this->line_height, $article->provider_code, $this->b, 0, 'L');

		$y_1 = $this->y;
		$this->MultiCell($this->getFields()['Nombre'], $this->line_height, $article->name, $this->b, 'L', false);
		$y_2 = $this->y;

		$this->x = $this->getFields()['C. Barras'] + $this->getFields()['Cód. Prov'] + $this->getFields()['Nombre'] + 5;
		$this->y = $y_1;

		$this->Cell($this->getFields()['Costo'], $this->line_height, $this->getArticleCostText($article), $this->b, 0, 'L');
		$this->SetFont('Arial', '', 8);
		$this->MultiCell($this->getFields()['Cantidad'], $this->line_height, $cantidad, $this->b, 'L');
		$y_cantidad = $this->y;
		$this->SetFont('Arial', '', 10);
		$this->x = 5 + $this->getFields()['C. Barras'] + $this->getFields()['Cód. Prov'] + $this->getFields()['Nombre'] + $this->getFields()['Costo'] + $this->getFields()['Cantidad'];
		$this->y = $y_1;

		$this->MultiCell($this->getFields()['Notas'], $this->line_height, $article->pivot->notes, $this->b, 'L', false);
		$y_3 = $this->y;

		$this->x = $this->getFields()['C. Barras']
			+ $this->getFields()['Cód. Prov']
			+ $this->getFields()['Nombre']
			+ $this->getFields()['Costo']
			+ $this->getFields()['Cantidad']
			+ $this->getFields()['Notas']
			+ 5;
		$this->y = $y_1;

		$line_total = $this->getArticleLineTotal($article);
		$moneda_linea = $article->pivot->cost_in_dollars ? ' USD' : ' '.$this->resumen['moneda'];
		$this->SetFont('Arial', '', 8);
		$this->Cell($this->getFields()['Total'], $this->line_height, Numbers::price($line_total).$moneda_linea, $this->b, 0, 'L');
		$this->SetFont('Arial', '', 10);

		if ($y_3 > $y_2) {
			$this->y = $y_3;
		} else {
			$this->y = $y_2;
		}
		$this->y = max($this->y, $y_cantidad);

		$this->Line(5, $this->y, 205, $this->y);
	}

	private function lineCount($ancho, $texto) {
		$texto = str_replace("\r", '', (string) $texto);
		$limite = ($ancho - 2 * $this->cMargin) * 1000 / $this->FontSize;
		$inicio = 0;
		$separador = -1;
		$largo = 0;
		$lineas = 1;
		for ($i = 0; $i < strlen($texto); $i++) {
			$caracter = $texto[$i];
			if ($caracter === "\n") {
				$lineas++;
				$inicio = $i + 1;
				$separador = -1;
				$largo = 0;
				continue;
			}
			if ($caracter === ' ') { $separador = $i; }
			$largo += $this->CurrentFont['cw'][$caracter] ?? 0;
			if ($largo > $limite) {
				if ($separador >= $inicio) { $i = $separador; }
				elseif ($i > $inicio) { $i--; }
				$inicio = $i + 1;
				$separador = -1;
				$largo = 0;
				$lineas++;
			}
		}
		return $lineas;
	}

	/**
	 * Total general de la compra impreso al final del documento.
	 */
	function total() {
		$this->x = 5;
		$this->y += 5;
		$this->SetFont('Arial', 'B', 14);
		$this->Cell(200, $this->line_height, 'Total compra: '.Numbers::price($this->resumen['total']).' '.$this->resumen['moneda'], $this->b, 1, 'R');
	}

	function printResumen() {
		$this->y += 6;
		$this->SetFont('Arial', 'B', 11);
		$this->x = 5;
		$this->MultiCell(200, 7, 'Resumen registrado de la compra', 0, 'L');
		$this->SetFont('Arial', '', 10);
		if ($this->resumen['de_facturas']) {
			$this->x = 5;
			$this->MultiCell(200, 6, 'El total se toma de los comprobantes, que pueden diferir del detalle pedido. Los descuentos de los artículos y de la compra no se vuelven a restar de las facturas.', 0, 'L');
		}
		foreach ($this->resumen['filas'] as $fila) {
			$this->x = 5;
			$texto = $fila[0];
			if (is_numeric($fila[1])) {
				$texto .= ': '.Numbers::price($fila[1]).' '.$this->resumen['moneda'];
			} elseif (!is_null($fila[1])) {
				$texto .= ': '.$fila[1];
			}
			$this->MultiCell(200, 6, $texto, 0, 'L');
		}
	}

}
