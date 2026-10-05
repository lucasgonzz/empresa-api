<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\address\SucursalVigenteHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\DepositMovementModification;
use App\Models\DepositMovementStatus;
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
 * Los estados pasan a ser etiquetas configurables. `recibido_at` deja de venir del request y pasa a
 * ser la guarda COMPARTIDA con la versión anterior del sistema (ver `stock_movido()`): la llena
 * `mover_stock()` y nadie más.
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
	 * 🔴 Son DOS marcas, no una (ajuste del 3/10/2026, "dos frentes"): un cliente puede tener a la
	 * vez el frente nuevo y el frente viejo (código anterior) sobre la MISMA base. El código viejo
	 * traslada al pasar a "Recibido" si `recibido_at` está vacío y no conoce `stock_moved_at`. Por
	 * eso un movimiento cuenta como movido si tiene CUALQUIERA de las dos:
	 *  - `stock_moved_at`: lo movió el botón "Mover stock" (este código);
	 *  - `recibido_at`: lo movió una versión anterior del sistema.
	 * Y `mover_stock()` llena las dos, para que el frente viejo tampoco lo traslade otra vez.
	 *
	 * Lo usan move-stock, update y destroy (y `en_curso` repite el mismo criterio en SQL).
	 *
	 * @return bool
	 */
	function stock_movido() {

		return !is_null($this->deposit_movement->stock_moved_at)
			|| !is_null($this->deposit_movement->recibido_at);
	}

	/**
	 * ¿El estado existe y lo puede usar el dueño? Vale un estado FIJO del sistema (`user_id` NULL)
	 * o uno PROPIO de ese dueño; nunca el de otro comercio de la misma base.
	 *
	 * @param  int|string  $deposit_movement_status_id
	 * @param  int  $owner_id  Id del dueño del comercio.
	 * @return bool
	 */
	static function estado_valido($deposit_movement_status_id, $owner_id) {

		return DepositMovementStatus::delDuenoConGlobales($owner_id)
									->where('id', (int) $deposit_movement_status_id)
									->exists();
	}

	/**
	 * Error de validación del estado que manda el request, si lo hay.
	 *
	 * Solo se valida un estado que VIENE con valor (no null, '' ni 0): un request sin estado sigue
	 * igual que antes. En una edición, además, solo si CAMBIA respecto del guardado: reenviar el
	 * estado que el movimiento ya tiene no es elegir uno nuevo.
	 *
	 * @param  array  $datos  `$request->all()`.
	 * @param  int  $owner_id  Id del dueño del comercio.
	 * @param  int|null  $estado_actual  Estado guardado (null en un alta).
	 * @return array|null  `['status' => 422, 'message' => '...']`, o null si está bien.
	 */
	static function error_de_estado($datos, $owner_id, $estado_actual = null) {

		if (!array_key_exists('deposit_movement_status_id', $datos)) {
			return null;
		}

		$estado = $datos['deposit_movement_status_id'];

		if (is_null($estado) || $estado === '' || (int) $estado === 0) {
			return null;
		}

		if (!is_null($estado_actual) && (int) $estado === (int) $estado_actual) {
			return null;
		}

		if (!self::estado_valido($estado, $owner_id)) {
			return [
				'status'	=> 422,
				'message'	=> 'El estado elegido no existe.',
			];
		}

		return null;
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
	 *  2. Si cambia algún dato: sin el permiso `deposit_movement.update` → 403; un estado nuevo
	 *     que no es fijo ni del dueño → 422; con el stock ya movido, si cambia el depósito de
	 *     origen o el de destino → 422.
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

		$error_de_estado = self::error_de_estado(
			$datos,
			$this->deposit_movement->user_id,
			$this->deposit_movement->deposit_movement_status_id
		);

		if (!is_null($error_de_estado)) {
			return $error_de_estado;
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
	 * `recibido_at` NO se acepta del request (ajuste del 3/10/2026): es la marca de "stock movido"
	 * que comparte con la versión anterior del sistema, y solo la escribe `mover_stock()`. Si el
	 * request pudiera ponerla, un movimiento quedaría "movido" sin haber trasladado nada; si pudiera
	 * borrarla, el frente viejo lo volvería a trasladar.
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

		// Movido por el botón "Mover stock" de este código.
		if (!is_null($this->deposit_movement->stock_moved_at)) {
			return [
				'status'	=> 422,
				'message'	=> 'El stock de este movimiento ya se movió el '.Carbon::parse($this->deposit_movement->stock_moved_at)->format('d/m/Y H:i').'.',
			];
		}

		// Movido por una versión anterior del sistema (al pasar a "Recibido"): solo tiene recibido_at.
		if (!is_null($this->deposit_movement->recibido_at)) {
			return [
				'status'	=> 422,
				'message'	=> 'El stock de este movimiento ya se movió el '.Carbon::parse($this->deposit_movement->recibido_at)->format('d/m/Y H:i').' (desde una versión anterior del sistema).',
			];
		}

		if ($this->deposit_movement->articles()->count() == 0) {
			return [
				'status'	=> 422,
				'message'	=> 'El movimiento no tiene artículos para mover.',
			];
		}

		// Sin alguno de los dos depósitos no hay entre qué trasladar (null y 0 valen lo mismo).
		if ((int) $this->deposit_movement->from_address_id === 0 || (int) $this->deposit_movement->to_address_id === 0) {
			return [
				'status'	=> 422,
				'message'	=> 'Elegí el depósito de origen y el de destino antes de mover el stock.',
			];
		}

		if ((int) $this->deposit_movement->from_address_id === (int) $this->deposit_movement->to_address_id) {
			return [
				'status'	=> 422,
				'message'	=> 'El depósito de origen y el de destino son el mismo.',
			];
		}

		/*
		 * Un depósito que ya no existe (misión eliminar-sucursal-con-stock, 5/10/2026). La eliminación
		 * nueva no deja borrar una sucursal con traslados pendientes, pero un traslado cargado antes
		 * (o desde un frente viejo) puede apuntar a una sucursal ya borrada. Mover el stock ahí le
		 * abriría al artículo una fila fantasma (y el motor ya no lo deja: no movería nada y el
		 * traslado quedaría "movido" sin haber trasladado). Se frena con un mensaje que dice cuál.
		 * Criterio del motor (existe_para_stock): el mismo con el que crear() decide si mueve.
		 */
		$owner_id = $this->deposit_movement->user_id;

		if (!SucursalVigenteHelper::existe_para_stock($this->deposit_movement->from_address_id, $owner_id)) {
			return [
				'status'	=> 422,
				'message'	=> 'El depósito de origen ya no existe (se eliminó la sucursal). Elegí otro antes de mover el stock.',
			];
		}

		if (!SucursalVigenteHelper::existe_para_stock($this->deposit_movement->to_address_id, $owner_id)) {
			return [
				'status'	=> 422,
				'message'	=> 'El depósito de destino ya no existe (se eliminó la sucursal). Elegí otro antes de mover el stock.',
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
	 * También llena `recibido_at` si está vacío: es la marca que mira la versión anterior del
	 * sistema. Sin ella, si el cliente todavía tiene el frente viejo sobre la misma base y alguien
	 * pasa este movimiento a "Recibido" desde ahí, el código viejo trasladaría el stock otra vez.
	 *
	 * @param  int|null  $user_id  Quién apretó el botón (el usuario autenticado).
	 * @return void
	 */
	function mover_stock($user_id) {

		$ahora = Carbon::now();

		$this->deposit_movement->stock_moved_at = $ahora;
		$this->deposit_movement->stock_moved_user_id = $user_id;

		if (is_null($this->deposit_movement->recibido_at)) {
			$this->deposit_movement->recibido_at = $ahora;
		}

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
