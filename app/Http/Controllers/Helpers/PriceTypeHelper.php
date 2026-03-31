<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\SetFinalPricesNotificationHelper;
use App\Jobs\ProcessChunkSetFinalPrices;
use App\Models\PriceType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PriceTypeHelper {
	
	/**
	 * Verifica cambios en recargos y dispara recálculo global cuando corresponde.
	 *
	 * @param PriceType $price_type
	 * @return void
	 */
	static function check_recargos($price_type) {

		// Bandera para identificar si hubo cambios relevantes en recargos.
		$hubo_cambios = false;

		foreach ($price_type->price_type_surchages as $price_type_surchage) {
			
			if ($price_type->updated_at <= Carbon::now()->subMinute()) {
				$hubo_cambios = true;
			}
		}

		if ($hubo_cambios) {
			ArticleHelper::setArticlesFinalPrice();
		}
	}

	/**
	 * Sincroniza el percentage del pivot article_price_type según modo elegido.
	 *
	 * @param PriceType $price_type
	 * @param mixed $old_percentage
	 * @param string $update_mode
	 * @return void
	 */
	static function sync_existing_articles_percentage($price_type, $old_percentage, $update_mode) {
		// Cuando no hay actualización solicitada, no se ejecutan cambios.

		Log::info('update_mode: '.$update_mode);

		if ($update_mode == 'none') {
			return;
		}

		// Query base de artículos vinculados al tipo de precio.
		$articles_query = $price_type->articles()->select('articles.id');

		// Filtra por porcentaje previo cuando se pide actualizar solo coincidencias.
		if ($update_mode == 'only_default_matches') {
			Log::info('only_default_matches');
			if (is_null($old_percentage) || $old_percentage === '') {
				$articles_query->wherePivotNull('percentage');
			} else {
				// Compara contra decimal normalizado para evitar fallos por precisión de float.
				$normalized = Self::normalize_decimal_percentage($old_percentage);
				$articles_query->wherePivot('percentage', $normalized);
			}
		}

		// Porcentaje nuevo por defecto a persistir en el pivot.
		$new_percentage = is_null($price_type->percentage) || $price_type->percentage === ''
			? null
			: Self::normalize_decimal_percentage($price_type->percentage);

		// No usar chunk() sobre esta query: al actualizar el pivot, las filas dejan de cumplir
		// wherePivot(...) y el siguiente chunk con OFFSET salta registros (ej. solo 200/250).
		$article_ids = $articles_query->pluck('id')->unique()->values()->all();

		// Actualiza pivots en lotes sobre IDs ya resueltos (orden de memoria acotado por batch).
		$batch_size = 200;
		for ($offset = 0; $offset < count($article_ids); $offset += $batch_size) {
			$article_id_chunk = array_slice($article_ids, $offset, $batch_size);
			foreach ($article_id_chunk as $article_id) {
				Log::info('Actualizado article_id '.$article_id.' con new_percentage: '.$new_percentage);
				$price_type->articles()->updateExistingPivot($article_id, [
					'percentage' => $new_percentage,
				]);
			}
		}

		// Recalcula precios finales de artículos afectados en segundo plano.
		Self::dispatch_recalculate_for_articles($article_ids, $price_type->user_id);
	}

	/**
	 * Normaliza el porcentaje al formato DECIMAL(12,2) usado en pivot.
	 *
	 * @param mixed $percentage
	 * @return string
	 */
	static function normalize_decimal_percentage($percentage) {
		// Convierte coma decimal a punto para compatibilidad con input de usuario.
		$percentage = str_replace(',', '.', (string) $percentage);

		// Fuerza dos decimales para comparar/guardar igual que en MySQL DECIMAL(12,2).
		return number_format((float) $percentage, 2, '.', '');
	}

	/**
	 * Encola recálculo por chunks para un conjunto de artículos.
	 *
	 * @param array $article_ids
	 * @param int $user_id
	 * @return void
	 */
	static function dispatch_recalculate_for_articles($article_ids, $user_id) {
		if (count($article_ids) == 0) {
			return;
		}

		// Procesa en lotes para evitar jobs grandes y mantener bajo consumo de memoria.
		$article_chunks = array_chunk($article_ids, 100);

		foreach ($article_chunks as $article_chunk) {
			dispatch(new ProcessChunkSetFinalPrices($article_chunk, $user_id));
		}

		Log::info('Se actualizaron los precios de '.count($article_ids).' articulos');

		// Misma notificación que ProcessSetFinalPrices tras encolar los chunks de recálculo.
		SetFinalPricesNotificationHelper::notify_prices_updated($user_id);
	}


}