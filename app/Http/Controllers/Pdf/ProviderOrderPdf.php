<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use fpdf;

require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

/**
 * PDF de compra a proveedor (ProviderOrder): cabecera con datos del proveedor
 * y detalle de artículos pedidos (cantidad, costo, notas y totales por línea).
 */
class ProviderOrderPdf extends fpdf {

	/**
	 * Genera el PDF de la compra y lo envía al navegador.
	 *
	 * @param \App\Models\ProviderOrder $model Compra con relaciones cargadas (withAll)
	 */
	function __construct($model) {
		parent::__construct();
		$this->SetAutoPageBreak(true, 1);
		$this->b = 0;
		$this->line_height = 7;

		$this->user = UserHelper::getFullModel();
		$this->model = $model;
		$this->total_order = 0;
		$this->subtotal_articles = 0;

		$this->AddPage();
		$this->print();
		$this->printExtraCosts();
		$this->printDiscounts();
		$this->total();
		$this->Output();
		exit;
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
			'Nombre'     => 45,
			'Costo'      => 25,
			'Cantidad'   => 20,
			'Notas'      => 25,
			'Total'      => 30,
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
		$this->Cell($this->getFields()['Cantidad'], $this->line_height, $article->pivot->amount, $this->b, 0, 'L');

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
		$this->Cell($this->getFields()['Total'], $this->line_height, '$'.Numbers::price($line_total), $this->b, 0, 'L');

		$this->subtotal_articles += $line_total;
		$this->total_order += $line_total;

		if ($y_3 > $y_2) {
			$this->y = $y_3;
		} else {
			$this->y = $y_2;
		}

		$this->Line(5, $this->y, 205, $this->y);
	}

	/**
	 * Lista costos extra sumados al total.
	 */
	function printExtraCosts() {
		if (count($this->model->provider_order_extra_costs) < 1) {
			return;
		}

		$this->y += 5;
		$this->x = 5;
		$this->SetFont('Arial', 'B', 11);
		$this->Cell(200, $this->line_height, 'Costos extra:', $this->b, 1, 'L');

		$this->SetFont('Arial', '', 10);

		foreach ($this->model->provider_order_extra_costs as $extra_cost) {
			$this->x = 5;
			$text = '- '.$extra_cost->description.': $'.Numbers::price($extra_cost->value);
			$this->Cell(200, $this->line_height, $text, $this->b, 1, 'R');
			$this->total_order += (float) $extra_cost->value;
		}
	}

	/**
	 * Lista descuentos aplicados (informativo; el monto fijo se resta del total).
	 */
	function printDiscounts() {
		if (count($this->model->provider_order_discounts) < 1) {
			return;
		}

		$this->y += 5;
		$this->x = 5;
		$this->SetFont('Arial', 'B', 11);
		$this->Cell(200, $this->line_height, 'Descuentos:', $this->b, 1, 'L');

		$this->SetFont('Arial', '', 10);

		foreach ($this->model->provider_order_discounts as $discount) {
			$this->x = 5;
			$text = '- '.$discount->description;

			if (!is_null($discount->percentage) && (float) $discount->percentage > 0) {
				$discount_amount = $this->subtotal_articles * (float) $discount->percentage / 100;
				$text .= ' ('.Numbers::price($discount->percentage).'%) -$'.Numbers::price($discount_amount);
				$this->total_order -= $discount_amount;
			} elseif (!is_null($discount->monto) && (float) $discount->monto > 0) {
				$text .= ' -$'.Numbers::price($discount->monto);
				$this->total_order -= (float) $discount->monto;
			}

			$this->Cell(200, $this->line_height, $text, $this->b, 1, 'R');
		}
	}

	/**
	 * Total general de la compra impreso al final del documento.
	 */
	function total() {
		$this->x = 5;
		$this->y += 5;
		$this->SetFont('Arial', 'B', 14);
		$this->Cell(200, $this->line_height, 'Total: $'.Numbers::price($this->total_order), $this->b, 1, 'R');
	}

}
