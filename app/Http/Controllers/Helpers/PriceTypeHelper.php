<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\PriceUpdateRunHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Jobs\FinalizeSetFinalPrices;
use App\Jobs\ProcessChunkSetFinalPrices;
use App\Models\PriceType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dos cosas viven acá: el recálculo de precios cuando cambia una lista (los cuatro métodos de
 * arriba, que usa `PriceTypeController`) y, desde la misión vender-lista-obligatoria (17/9/2026),
 * la regla "toda venta y todo presupuesto llevan lista de precios" (los métodos de abajo, que usan
 * `SaleController` y `BudgetController` en el alta y en la edición). La SPA tiene su copia de esa
 * regla en `src/mixins/vender/price_types.js::requiere_lista_de_precios()`, y los dos lados
 * tienen que decir lo mismo.
 *
 * EL CASO REAL de la regla: Trama (`trama2`, v4.0.23), cuenta con `users.listas_de_precio = 1`
 * donde TODO el margen vive en las listas y el precio base del artículo es costo + IVA. Cuando la
 * SPA no lograba resolver la lista —catálogo de `price_type` que no llegó, `limpiar_vender()` que
 * la dejó en null—, mandaba `price_type_id: null`, el back lo guardaba tal cual y la venta salía a
 * costo, sin un solo error en ningún lado. Medido: 24 ventas de mostrador en 60 días, $77.809 de
 * margen perdido, ganancia ≈ $0.
 *
 * 🔴 POR QUÉ SE RECHAZA Y NO SE COMPLETA CON UNA LISTA POR DEFECTO — es lo primero que alguien va
 * a querer "simplificar", y no se puede: `SaleHelper::attachArticle()` persiste en
 * `article_sale.price` el `price_vender` que mandó el front, y `BudgetHelper::attachArticles()`
 * hace lo mismo con `budget_article.price`. Los renglones llegan YA PRECIADOS por la SPA; el back
 * no tiene ningún camino para volver a preciarlos con la lista que elegiría. Si acá se pusiera la
 * lista por defecto (la de mayor `position`, como hace `ArticlePricesHelper::resolver_precio_de_venta()`),
 * la venta quedaría diciendo "lista General" con renglones cobrados a precio base: peor que la
 * venta sin lista, porque además mentiría. Lo único que sí se completa es la lista del cliente,
 * porque es exactamente la que el front hubiera usado para preciar (presupuesto → cliente → mayor
 * position) y es lo que `SaleController::store()` ya hacía después del create.
 *
 * 🔴 POR QUÉ EL CERO ES NULL: golonorte (`listas_de_precio = 0`, extensión
 * `lista_de_precios_por_categoria`) manda `sales.price_type_id` en null o en 0 —2.068 y 309 ventas
 * en 30 días, respectivamente— y lleva la lista POR LÍNEA en `article_sale.price_type_personalizado_id`.
 * Un 0 no es una lista: es "ninguna" escrito de otra forma, y se lee así en el alta y en la
 * edición. Esas cuentas no llegan al rechazo porque la regla se ancla en `users.listas_de_precio`,
 * no en la extensión ni en que existan listas.
 *
 * 🔴 POR QUÉ LA EXTENSIÓN DE RANGOS QUEDA AFUERA: con `lista_de_precios_por_rango_de_cantidad_vendida`
 * la lista se decide por la cantidad vendida de cada renglón y el front NO setea lista de venta a
 * propósito (`price_types.js::setPriceType()` corta ahí). Exigirla sería rechazar todas las ventas
 * de esas cuentas.
 *
 * Y una cuenta con el flag prendido pero SIN ninguna `PriceType` cargada no tiene qué elegir:
 * tampoco se le exige (es el estado de una cuenta recién configurada, antes de cargar la primera).
 */
class PriceTypeHelper {

	/**
	 * Slug de la extensión que decide la lista por cantidad vendida. Mismo string que usa la SPA
	 * en `price_types.js` y `category\SetPriceTypesHelper` de este repo.
	 *
	 * @var string
	 */
	const EXTENCION_RANGOS = 'lista_de_precios_por_rango_de_cantidad_vendida';

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
	static function dispatch_recalculate_for_articles($article_ids, $user_id, $origen = 'listas_de_precio', $origen_detalle = null) {
		if (count($article_ids) == 0) {
			return;
		}

		/*
		 * Su propia corrida, para que el aviso salga con numeros y no en el aire. SIEMPRE
		 * una nueva: esto corre sincronico en el request web, asi que reusar la corrida
		 * abierta del usuario lo metia como segundo productor de una corrida que un job ya
		 * estaba llenando, y el primero que terminaba cerraba por el otro.
		 */
		$run = PriceUpdateRunHelper::abrir($user_id, $origen, $origen_detalle);

		// Procesa en lotes para evitar jobs grandes y mantener bajo consumo de memoria.
		$article_chunks = array_chunk($article_ids, 100);

		foreach ($article_chunks as $article_chunk) {
			dispatch(new ProcessChunkSetFinalPrices($article_chunk, $user_id, $run->id));
		}

		DB::table('price_update_runs')
			->where('id', $run->id)
			->update([
				'total_chunks'     => count($article_chunks),
				'chunks_encolados' => 1,
			]);

		Log::info('Se encolo el recalculo de precios de '.count($article_ids).' articulos');

		/*
		 * 🔴 Aca ya NO se notifica. Este helper avisaba "Precios actualizados" en el mismo
		 * momento de encolar, o sea antes de que se recalculara un solo articulo. Ahora
		 * notifica el finalizador, cuando los numeros son ciertos.
		 */
		dispatch(new FinalizeSetFinalPrices($user_id, $run->id));
	}

	/*
	 * ------------------------------------------------------------------------------------------
	 * Lista de precios obligatoria en ventas y presupuestos (misión vender-lista-obligatoria,
	 * 17/9/2026). El porqué de cada decisión está en el docblock de la clase.
	 * ------------------------------------------------------------------------------------------
	 */

	/**
	 * Si a esta cuenta hay que exigirle lista de precios en cada venta y presupuesto.
	 *
	 * Las tres condiciones, en orden de costo: el flag del dueño (sin query si el modelo ya
	 * vino), la extensión de rangos (una query sobre el pivote si la relación no está cargada) y
	 * que exista al menos una lista del dueño (una query). Cualquiera que falle corta.
	 *
	 * @param  \App\Models\User|null  $user  Dueño o empleado; se resuelve al dueño. Null = el autenticado.
	 * @return bool
	 */
	static function requiere_lista_de_precios($user = null) {

		$owner = self::owner_de($user);

		if (is_null($owner)) {
			return false;
		}

		if (!UserHelper::uses_listas_de_precio($owner)) {
			return false;
		}

		if (UserHelper::hasExtencion(self::EXTENCION_RANGOS, $owner)) {
			return false;
		}

		return PriceType::where('user_id', $owner->id)->exists();
	}

	/**
	 * Un `price_type_id` tal como llega del request, convertido a lo único que puede ser: un id
	 * entero positivo, o null.
	 *
	 * null, '' y 0/'0' son "ninguna lista" (ver el docblock de la clase por el 0 de golonorte);
	 * lo no numérico y lo negativo también, porque no hay lista que se llame así. No se tira
	 * ninguna excepción a propósito: el que llama decide si el null es un rechazo o un valor
	 * válido según la cuenta.
	 *
	 * @param  mixed  $valor
	 * @return int|null
	 */
	static function normalizar_price_type_id($valor) {

		if (is_null($valor) || is_bool($valor) || is_array($valor) || is_object($valor)) {
			return null;
		}

		if (!is_numeric($valor)) {
			return null;
		}

		$id = (int) $valor;

		if ($id <= 0) {
			return null;
		}

		return $id;
	}

	/**
	 * La lista con la que se guarda un alta: la del request, o la del cliente, o ninguna.
	 *
	 * La lista del cliente es el ÚNICO default que se aplica, y por qué se puede aplicar está en
	 * el docblock de la clase: es la que el front usa para preciar cuando hay cliente con lista,
	 * así que los renglones ya vienen cobrados con ella. Un cliente con `price_type_id` en 0
	 * cuenta como cliente sin lista.
	 *
	 * @param  mixed                    $price_type_id_del_request
	 * @param  \App\Models\Client|null  $client                     El cliente de la venta o del presupuesto, ya cargado; null si es de mostrador.
	 * @return int|null
	 */
	static function resolver_price_type_id_para_guardar($price_type_id_del_request, $client) {

		$price_type_id = self::normalizar_price_type_id($price_type_id_del_request);

		if (!is_null($price_type_id)) {
			return $price_type_id;
		}

		if (!is_null($client)) {
			return self::normalizar_price_type_id($client->price_type_id);
		}

		return null;
	}

	/**
	 * El texto del 422 para una venta. Es lo que ve el vendedor, también con la SPA anterior (que
	 * muestra `err.response.data.message` en su catch genérico): por eso dice "recargá la página",
	 * que es a la vez lo que destraba el catálogo de listas que no llegó y lo que trae el bundle
	 * nuevo.
	 *
	 * @return string
	 */
	static function mensaje_sin_lista() {
		return self::armar_mensaje_sin_lista('la venta');
	}

	/**
	 * El mismo texto, para un presupuesto.
	 *
	 * @return string
	 */
	static function mensaje_sin_lista_presupuesto() {
		return self::armar_mensaje_sin_lista('el presupuesto');
	}

	/**
	 * @param  string  $documento  'la venta' o 'el presupuesto'.
	 * @return string
	 */
	protected static function armar_mensaje_sin_lista($documento) {
		return 'Esta cuenta trabaja con listas de precios y '.$documento.' no tiene ninguna. '
			.'Elegí una lista de precios; si no aparece ninguna, recargá la página.';
	}

	/**
	 * El dueño de la cuenta a partir de cualquier usuario: el mismo criterio que
	 * `UserHelper::uses_listas_de_precio()`, porque el flag, las extensiones y las listas
	 * cuelgan todas del dueño y un empleado no tiene ninguna de las tres.
	 *
	 * @param  \App\Models\User|null  $user  Null = el autenticado, resuelto al dueño por UserHelper.
	 * @return \App\Models\User|null
	 */
	protected static function owner_de($user) {

		$candidate = $user ?? UserHelper::user(true);

		if (is_null($candidate)) {
			return null;
		}

		if ($candidate->owner_id) {

			$owner = $candidate->owner ?? User::find($candidate->owner_id);

			return $owner ? $owner : null;
		}

		return $candidate;
	}
}