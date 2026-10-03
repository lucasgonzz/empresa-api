<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Stock\StockMovementController;
use App\Models\DepositMovementModification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Lógica de los movimientos de depósito: los artículos del movimiento, el traslado de stock entre
 * los dos depósitos y, desde la misión movimientos-deposito-auditoria (3/10/2026), las reglas de
 * edición y el registro de modificaciones de artículos.
 *
 * 🔴 Cambio de comportamiento del 3/10/2026 (pedido de Lucas): el stock YA NO se mueve al pasar el
 * movimiento al estado "Recibido" (se borraron `check_status()` y `set_fecha_recibido()`). Se mueve
 * solo con el botón "Mover stock" (`mover_stock()`, llamado desde
 * `DepositMovementController::move_stock()`), que deja registrado quién y cuándo en
 * `stock_moved_user_id` / `stock_moved_at`. Desde ese momento los artículos y los depósitos del
 * movimiento quedan bloqueados.
 *
 * Los estados pasan a ser etiquetas configurables. `recibido_at` queda como columna vieja: el
 * sistema no la escribe más.
 *
 * PHP 7.4: sin match, str_contains, nullsafe (?->), argumentos nombrados ni union types.
 */
class DepositMovementHelper {

	/**
	 * Campos de "datos" del movimiento (todo lo que no son artículos). Cambiarlos pide el permiso
	 * `deposit_movement.update`.
	 */
	const CAMPOS_DE_DATOS = [
		'from_address_id',
		'to_address_id',
		'employee_id',
		'deposit_movement_status_id',
		'notes',
	];

	/**
	 * Los dos depósitos del traslado. Con el stock ya movido no se pueden cambiar: el traslado se
	 * hizo entre ESOS dos, y cambiarlos dejaría el registro mintiendo.
	 */
	const CAMPOS_DE_DEPOSITOS = [
		'from_address_id',
		'to_address_id',
	];

	public $deposit_movement;
	public $previus_articles;

	function __construct($deposit_movement) {

		$this->deposit_movement = $deposit_movement;

		$this->set_previus_articles();

	}

	function set_previus_articles() {

		$this->previus_articles = [];

		foreach ($this->deposit_movement->articles as $article) {

			$this->previus_articles[$article->id] = $article->pivot->amount;
		}
	}


	function attach_articles($articles) {

		$this->deposit_movement->articles()->sync([]);

		foreach ($articles as $article) {

			$this->deposit_movement->articles()->attach($article['id'], [
				'amount'				=> $article['pivot']['amount'],
				'article_variant_id'	=> $article['pivot']['article_variant_id'],
			]);
		}
	}

	/**
	 * ¿El stock de este movimiento ya se trasladó?
	 *
	 * @return bool
	 */
	function stock_movido() {

		return !is_null($this->deposit_movement->stock_moved_at);
	}

	/**
	 * Foto de los artículos del movimiento tal como están AHORA en el pivot
	 * (`article_deposit_movement`). Relee la relación de la base: sirve tanto para la foto de
	 * antes de una modificación como para la de después (con el pivot ya sincronizado).
	 *
	 * @return array  Lista de filas `['article_id' => int, 'article_variant_id' => int|null,
	 *                'amount' => string|null]`, una por renglón del movimiento.
	 */
	function foto_de_articulos() {

		$this->deposit_movement->load('articles');

		$filas = [];

		foreach ($this->deposit_movement->articles as $article) {

			$filas[] = [
				'article_id'			=> $article->id,
				'article_variant_id'	=> $article->pivot->article_variant_id,
				'amount'				=> $article->pivot->amount,
			];
		}

		return $filas;
	}

	/**
	 * Lleva una lista de renglones a una forma comparable: una lista ORDENADA de claves
	 * `"article_id|variant|amount"`, una por renglón.
	 *
	 * Acepta las dos formas en que llegan los renglones:
	 *  - la del request de la SPA: `['id' => ..., 'pivot' => ['amount' => ..., 'article_variant_id' => ...]]`;
	 *  - la de `foto_de_articulos()`: `['article_id' => ..., 'article_variant_id' => ..., 'amount' => ...]`.
	 *
	 * Normalización (para que el mismo movimiento reenviado tal cual NO cuente como cambio):
	 *  - variante: null, '' y 0 valen lo mismo (0); si no, entero;
	 *  - cantidad: `round((float), 2)` con dos decimales fijos ("4", 4 y "4.00" son iguales);
	 *  - el orden de los renglones no importa (se ordena la lista). Un renglón repetido sí cuenta
	 *    dos veces: se compara como multiconjunto.
	 *
	 * @param  array|null  $filas
	 * @return array
	 */
	function normalizar($filas) {

		$claves = [];

		if (!is_array($filas)) {
			return $claves;
		}

		foreach ($filas as $fila) {

			if (!is_array($fila)) {
				continue;
			}

			if (array_key_exists('article_id', $fila)) {

				// Fila de la foto del pivot.
				$article_id = $fila['article_id'];
				$variant_id = isset($fila['article_variant_id']) ? $fila['article_variant_id'] : null;
				$amount = isset($fila['amount']) ? $fila['amount'] : null;

			} else {

				// Fila del request de la SPA.
				$pivot = (isset($fila['pivot']) && is_array($fila['pivot'])) ? $fila['pivot'] : [];

				$article_id = isset($fila['id']) ? $fila['id'] : null;
				$variant_id = isset($pivot['article_variant_id']) ? $pivot['article_variant_id'] : null;
				$amount = isset($pivot['amount']) ? $pivot['amount'] : null;
			}

			$variant_normalizada = (is_null($variant_id) || $variant_id === '' || (int) $variant_id === 0)
									? 0
									: (int) $variant_id;

			$amount_normalizado = number_format(round((float) $amount, 2), 2, '.', '');

			$claves[] = (int) $article_id.'|'.$variant_normalizada.'|'.$amount_normalizado;
		}

		sort($claves, SORT_STRING);

		return $claves;
	}

	/**
	 * ¿Los artículos que manda el request son distintos de los que tiene guardados el movimiento?
	 *
	 * Si el request no trae `articles` (o no es una lista) se considera que NO cambiaron: un PUT
	 * que solo manda el estado no tiene que vaciar el movimiento.
	 *
	 * @param  array|null  $articles_del_request
	 * @return bool
	 */
	function articulos_cambiaron($articles_del_request) {

		if (!is_array($articles_del_request)) {
			return false;
		}

		return $this->normalizar($articles_del_request) !== $this->normalizar($this->foto_de_articulos());
	}

	/**
	 * Lleva el valor de un campo de datos a una forma comparable.
	 *
	 * Ids: entero, con null, '' y 0 equivalentes (todos 0). Notas: string (null = '').
	 *
	 * @param  string  $campo
	 * @param  mixed  $valor
	 * @return int|string
	 */
	function valor_normalizado($campo, $valor) {

		if ($campo == 'notes') {
			return (string) $valor;
		}

		if (is_null($valor) || $valor === '') {
			return 0;
		}

		return (int) $valor;
	}

	/**
	 * Campos de datos (estado, depósitos, empleado, notas) que vienen en el request con un valor
	 * DISTINTO del guardado. Los que no vienen en el request no cuentan.
	 *
	 * @param  array  $datos  `$request->all()`.
	 * @return array  Nombres de los campos que cambian.
	 */
	function campos_de_datos_que_cambian($datos) {

		$cambian = [];

		foreach (self::CAMPOS_DE_DATOS as $campo) {

			if (!array_key_exists($campo, $datos)) {
				continue;
			}

			$nuevo = $this->valor_normalizado($campo, $datos[$campo]);
			$actual = $this->valor_normalizado($campo, $this->deposit_movement->{$campo});

			if ($nuevo !== $actual) {
				$cambian[] = $campo;
			}
		}

		return $cambian;
	}

	/**
	 * Todas las reglas de una edición (PUT), ANTES de escribir nada.
	 *
	 * Orden de las reglas:
	 *  1. Si cambian los artículos: con el stock ya movido → 422; sin el permiso
	 *     `deposit_movement.update_articles` → 403.
	 *  2. Si cambia algún dato: sin el permiso `deposit_movement.update` → 403; con el stock ya
	 *     movido, si cambia el depósito de origen o el de destino → 422.
	 *
	 * El estado, el empleado y las notas siguen editables con el stock movido.
	 *
	 * @param  array  $datos  `$request->all()`.
	 * @param  bool  $articulos_cambiaron  Resultado de `articulos_cambiaron()`.
	 * @param  bool  $puede_datos  Permiso `deposit_movement.update` (o dueño/admin).
	 * @param  bool  $puede_articulos  Permiso `deposit_movement.update_articles` (o dueño/admin).
	 * @return array|null  `['status' => 403|422, 'message' => '...']`, o null si se puede guardar.
	 */
	function error_de_actualizacion($datos, $articulos_cambiaron, $puede_datos, $puede_articulos) {

		if ($articulos_cambiaron) {

			if ($this->stock_movido()) {
				return [
					'status'	=> 422,
					'message'	=> 'El stock de este movimiento ya se movió: los artículos no se pueden cambiar.',
				];
			}

			if (!$puede_articulos) {
				return [
					'status'	=> 403,
					'message'	=> 'No tenés permiso para cambiar los artículos de un movimiento de depósito.',
				];
			}
		}

		$cambian = $this->campos_de_datos_que_cambian($datos);

		if (count($cambian) > 0 && !$puede_datos) {
			return [
				'status'	=> 403,
				'message'	=> 'No tenés permiso para cambiar los datos del movimiento (estado, depósitos, empleado o notas).',
			];
		}

		if ($this->stock_movido() && count(array_intersect($cambian, self::CAMPOS_DE_DEPOSITOS)) > 0) {
			return [
				'status'	=> 422,
				'message'	=> 'El stock ya se movió entre estos depósitos: no se pueden cambiar.',
			];
		}

		return null;
	}

	/**
	 * Guarda los datos del movimiento que vienen en el request (solo los que vienen: un campo que
	 * no viaja no se pisa con null). Se llama solo con el permiso `deposit_movement.update`.
	 *
	 * `recibido_at` se acepta igual que antes: solo un valor explícito, nunca se pisa con null.
	 * El sistema ya no lo escribe (es una columna vieja), pero una SPA anterior todavía lo manda.
	 *
	 * @param  array  $datos  `$request->all()`.
	 * @return void
	 */
	function guardar_datos($datos) {

		foreach (self::CAMPOS_DE_DATOS as $campo) {

			if (array_key_exists($campo, $datos)) {
				$this->deposit_movement->{$campo} = $datos[$campo];
			}
		}

		if (isset($datos['recibido_at']) && !is_null($datos['recibido_at'])) {
			$this->deposit_movement->recibido_at = $datos['recibido_at'];
		}

		$this->deposit_movement->save();
	}

	/**
	 * Registra una modificación de artículos: quién y cuándo, con la foto de antes (la que se tomó
	 * antes de sincronizar) y la de después (leída del pivot ya sincronizado).
	 *
	 * @param  array  $foto_antes  Resultado de `foto_de_articulos()` tomado ANTES de `attach_articles()`.
	 * @param  int|null  $user_id  Quién hizo el cambio (el usuario autenticado, empleado o dueño).
	 * @return \App\Models\DepositMovementModification
	 */
	function registrar_modificacion($foto_antes, $user_id) {

		$modificacion = DepositMovementModification::create([
			'deposit_movement_id'	=> $this->deposit_movement->id,
			'user_id'				=> $user_id,
		]);

		foreach ($foto_antes as $fila) {
			$modificacion->articulos_antes()->attach($fila['article_id'], [
				'amount'				=> $fila['amount'],
				'article_variant_id'	=> $fila['article_variant_id'],
			]);
		}

		foreach ($this->foto_de_articulos() as $fila) {
			$modificacion->articulos_despues()->attach($fila['article_id'], [
				'amount'				=> $fila['amount'],
				'article_variant_id'	=> $fila['article_variant_id'],
			]);
		}

		return $modificacion;
	}

	/**
	 * Por qué NO se puede mover el stock de este movimiento ahora, si hay un motivo.
	 *
	 * @return array|null  `['status' => 422, 'message' => '...']`, o null si se puede mover.
	 */
	function error_para_mover_stock() {

		if ($this->stock_movido()) {
			return [
				'status'	=> 422,
				'message'	=> 'El stock de este movimiento ya se movió el '.Carbon::parse($this->deposit_movement->stock_moved_at)->format('d/m/Y H:i').'.',
			];
		}

		if ($this->deposit_movement->articles()->count() == 0) {
			return [
				'status'	=> 422,
				'message'	=> 'El movimiento no tiene artículos para mover.',
			];
		}

		if ((int) $this->deposit_movement->from_address_id === (int) $this->deposit_movement->to_address_id) {
			return [
				'status'	=> 422,
				'message'	=> 'El depósito de origen y el de destino son el mismo.',
			];
		}

		return null;
	}

	/**
	 * El botón "Mover stock": marca quién y cuándo, y traslada el stock de cada artículo del
	 * depósito de origen al de destino (un StockMovement "Mov entre depositos" por renglón).
	 *
	 * NO cambia el estado del movimiento. Las validaciones (`error_para_mover_stock()`) y el
	 * bloqueo de la fila los hace el controller antes de llamar a este método.
	 *
	 * @param  int|null  $user_id  Quién apretó el botón (el usuario autenticado).
	 * @return void
	 */
	function mover_stock($user_id) {

		$this->deposit_movement->stock_moved_at = Carbon::now();
		$this->deposit_movement->stock_moved_user_id = $user_id;
		$this->deposit_movement->save();

		$this->actualizar_stock();
	}

	function actualizar_stock() {

		$this->deposit_movement->load('articles');

		foreach ($this->deposit_movement->articles as $article) {

			$this->crear_stock_movement($article);
		}
	}

	function crear_stock_movement($article) {

		$ct_stock_movement = new StockMovementController();

		$data = [];

		$data['model_id'] = $article->id;
		$data['amount'] = $article->pivot->amount;

		if (!is_null($article->pivot->article_variant_id)
			&& $article->pivot->article_variant_id != 0) {

			$data['article_variant_id'] = $article->pivot->article_variant_id;
		}

		$data['deposit_movement_id'] = $this->deposit_movement->id;

		$data['from_address_id'] = $this->deposit_movement->from_address_id;
		$data['to_address_id'] = $this->deposit_movement->to_address_id;

		$data['employee_id'] = $this->deposit_movement->employee_id;
		$data['concepto_stock_movement_name'] = 'Mov entre depositos';

		Log::info('Se va a mandar a guardar stock_movement');

        $ct_stock_movement->crear($data);
	}

}
