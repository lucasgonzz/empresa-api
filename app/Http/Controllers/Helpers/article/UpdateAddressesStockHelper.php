<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\address\SucursalVigenteHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaStockIaHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Address;
use App\Models\Article;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Edición de stock por sucursal (`PUT api/article-update-addresses`): para cada renglón deja el stock
 * FINAL de esa sucursal (genera un movimiento por la diferencia contra la base) y escribe el mínimo
 * y el máximo.
 *
 * Misión stock-sucursal-desde-listado (14/10/2026), por el caso de trama: un empleado guardó el
 * listado con los números que tenía en pantalla desde antes de un traslado y el back, al calcular
 * la diferencia contra la base, deshizo el traslado. Desde esta misión:
 *
 *  - Solo se aplican los renglones de sucursales que la persona PUEDE editar
 *    (`PermisoStockPorSucursalHelper`); el resto se saltea sin error (una SPA vieja manda siempre
 *    todas las sucursales, y un 403 le rompería al empleado restringido el guardado de la suya).
 *  - Si el renglón trae `pivot.amount_original` (lo que el usuario tenía en pantalla al abrir la
 *    edición; lo manda la SPA nueva):
 *      · con `amount` igual a `amount_original`, no se toca el stock de esa sucursal (sí el mínimo
 *        y el máximo);
 *      · con `amount` distinto y la base ya distinta de `amount_original`, es un CONFLICTO
 *        (`conflictos()`): el controller contesta 409 sin aplicar ningún renglón.
 *  - Sin `amount_original` (SPA vieja, asistente IA) el stock se comporta como siempre.
 */
class UpdateAddressesStockHelper {

    /** Tolerancia para comparar cantidades de stock (el pivot es decimal(12,2)). */
    const TOLERANCIA = 0.0001;

    /** @var \App\Models\User|null Quién guarda. */
    protected $persona;

    /** @var array|null Renglones aplicables, calculados una sola vez (ver renglones_aplicables()). */
    protected $aplicables = null;

    /**
     * @param  int  $article_id
     * @param  array  $addresses  Renglones `{id, pivot: {amount, stock_min, stock_max, amount_original?}}`.
     * @param  \App\Models\User|null  $persona  Quién guarda. null = el usuario autenticado.
     */
    function __construct($article_id, $addresses, $persona = null) {

        $this->article = Article::find($article_id);

        $this->addresses = $addresses;

        $this->persona = is_null($persona) ? UserHelper::user(false) : $persona;
    }

    function update_addresses() {
        
        $segundos = 0;

        foreach ($this->renglones_aplicables() as $address) {

            // Un renglón sin `amount` no pide tocar el stock (antes reventaba con un índice indefinido).
            if (!array_key_exists('amount', $address['pivot'])) {
                continue;
            }

            // D4: lo que el usuario tenía en pantalla y no cambió no genera movimiento. Sin este corte,
            // un renglón que la SPA manda sin tocar "corregiría" la sucursal a su número viejo.
            if (
                $this->trae_amount_original($address)
                && self::iguales(self::cantidad($address['pivot']['amount']), self::cantidad($address['pivot']['amount_original']))
            ) {
                continue;
            }

            $this->address = $address;

            $article_address = $this->get_article_address();

            if (!is_null($article_address)) {

                $diferencia = (float)$address['pivot']['amount'] - $article_address->pivot->amount;
            } else {
                $diferencia = (float)$address['pivot']['amount'];
            }

            if (
                $diferencia != ''
                && $diferencia != 0
            ) {
                
                $this->guardar_stock_movement($diferencia, $segundos);

                $segundos += 5;
            }
        }
    }

    function set_stock_min_max() {
        foreach ($this->renglones_aplicables() as $address_data) {

            $address_id = $address_data['id'];
            $stock_min = isset($address_data['pivot']['stock_min']) ? $address_data['pivot']['stock_min'] : null;
            $stock_max = isset($address_data['pivot']['stock_max']) ? $address_data['pivot']['stock_max'] : null;

            if (
                is_null($stock_min)
                && is_null($stock_max)
            ) {
                continue; // si no se pasa valor, no hace nada
            }

            if ($this->article->addresses()->where('address_id', $address_id)->exists()) {
                $this->article->addresses()->updateExistingPivot($address_id, [
                    'stock_min' => $stock_min,
                    'stock_max' => $stock_max,
                ]);
            } else {
                $this->article->addresses()->attach($address_id, [
                    'stock_min' => $stock_min,
                    'stock_max' => $stock_max,
                    // 'amount' => 0, // por si es requerido por la DB
                ]);
            }
        }
    }

    /**
     * Los renglones cuyo stock cambió en la base mientras el usuario los editaba (D2 de la misión
     * stock-sucursal-desde-listado). Se mira ANTES de escribir nada: si la lista no está vacía, el
     * controller contesta 409 y no aplica ningún renglón.
     *
     * Entra un renglón aplicable (sucursal viva y que la persona puede editar) que trae la clave
     * `pivot.amount_original` y cuyo `amount` difiere de ella (o sea, que el usuario cambió). Es
     * conflicto si lo que hay HOY en la base para esa sucursal (sin fila = 0) no es lo que el usuario
     * tenía en pantalla. Se compara como float, con `''`/null = 0 y tolerancia de 0,0001.
     *
     * Lo de hoy se lee directo de `address_article`, no de la relación del modelo: una relación ya
     * cargada podría traer números de antes.
     *
     * @return array  Lista de `['address_id', 'sucursal', 'en_pantalla', 'actual']`.
     */
    function conflictos() {

        $conflictos = [];

        $actuales = null;

        foreach ($this->renglones_aplicables() as $address) {

            if (
                !$this->trae_amount_original($address)
                || !array_key_exists('amount', $address['pivot'])
            ) {
                continue;
            }

            $en_pantalla = self::cantidad($address['pivot']['amount_original']);

            // El usuario no cambió esta sucursal: no se toca (D4), así que no puede pisar nada.
            if (self::iguales(self::cantidad($address['pivot']['amount']), $en_pantalla)) {
                continue;
            }

            if (is_null($actuales)) {
                $actuales = $this->stock_actual_por_sucursal();
            }

            $address_id = (int) $address['id'];

            $actual = array_key_exists($address_id, $actuales) ? $actuales[$address_id] : 0.0;

            if (self::iguales($actual, $en_pantalla)) {
                continue;
            }

            $conflictos[] = [
                'address_id'  => $address_id,
                'sucursal'    => self::nombre_de_sucursal($address_id),
                'en_pantalla' => $en_pantalla,
                'actual'      => $actual,
            ];
        }

        return $conflictos;
    }

    /**
     * El `message` del 409: nombra cada sucursal en conflicto con lo que el usuario tenía en pantalla
     * y lo que hay ahora, y dice que no se guardó nada.
     *
     * @param  array  $conflictos  Lo que devuelve conflictos().
     * @return string
     */
    static function mensaje_de_conflictos($conflictos) {

        $cierre = ' No se guardó ningún cambio: revisá el stock actualizado y volvé a cargarlo.';

        $detalles = [];

        foreach ($conflictos as $conflicto) {

            $detalles[] = [
                'sucursal' => $conflicto['sucursal'],
                'numeros'  => '(tenías '.PropuestaStockIaHelper::numero_legible($conflicto['en_pantalla'])
                                .' en pantalla y ahora hay '.PropuestaStockIaHelper::numero_legible($conflicto['actual']).')',
            ];
        }

        if (count($detalles) == 1) {

            return 'El stock de '.$detalles[0]['sucursal'].' cambió mientras lo editabas '.$detalles[0]['numeros'].'.'.$cierre;
        }

        $partes = [];

        foreach ($detalles as $detalle) {
            $partes[] = $detalle['sucursal'].' '.$detalle['numeros'];
        }

        return 'El stock de estas sucursales cambió mientras lo editabas: '.implode(', ', $partes).'.'.$cierre;
    }

    /**
     * Los renglones del request que se aplican: sucursal viva (sucursal_viva()) Y que la persona puede
     * editar (puede_editar()). Es el filtro ÚNICO de update_addresses(), set_stock_min_max() y
     * conflictos(); se calcula una sola vez por guardado.
     *
     * Un renglón que no se aplica se SALTEA sin error, nunca 403/422: ver el docblock de la clase.
     *
     * @return array
     */
    function renglones_aplicables() {

        if (!is_null($this->aplicables)) {
            return $this->aplicables;
        }

        $this->aplicables = [];

        $renglones = is_array($this->addresses) ? $this->addresses : [];

        foreach ($renglones as $address) {

            if (
                !is_array($address)
                || !isset($address['pivot'])
                || !is_array($address['pivot'])
            ) {
                continue;
            }

            if (!$this->sucursal_viva($address)) {
                continue;
            }

            if (!$this->puede_editar($address)) {
                continue;
            }

            $this->aplicables[] = $address;
        }

        return $this->aplicables;
    }

    /**
     * ¿La persona que guarda puede editar el stock de la sucursal del renglón? (D3). Misma regla que
     * la celda del Listado (`PermisoStockPorSucursalHelper`).
     *
     * @param  array  $address  Renglón del request (`id`, `pivot`).
     * @return bool
     */
    function puede_editar($address) {

        $address_id = isset($address['id']) ? $address['id'] : null;

        if (PermisoStockPorSucursalHelper::puede_editar($this->persona, $address_id)) {
            return true;
        }

        Log::info('UpdateAddressesStockHelper: se saltea la sucursal '.$address_id.' del artículo '.$this->article->id
                    .': el usuario '.(is_null($this->persona) ? 'sin sesión' : $this->persona->id).' no puede editar su stock.');

        return false;
    }

    /**
     * ¿El renglón trae lo que el usuario tenía en pantalla? Lo manda solo la SPA nueva.
     *
     * @param  array  $address
     * @return bool
     */
    function trae_amount_original($address) {

        return array_key_exists('amount_original', $address['pivot']);
    }

    /**
     * Stock de hoy de cada sucursal del artículo, leído directo de `address_article` (sin pasar por la
     * relación, que puede estar cargada de antes). Si una sucursal tuviera más de una fila, queda la
     * de id mayor: la misma que toma get_article_address(), que se queda con la última.
     *
     * @return array<int, float>  [address_id => amount]
     */
    function stock_actual_por_sucursal() {

        $filas = DB::table('address_article')
                    ->where('article_id', $this->article->id)
                    ->orderBy('id')
                    ->get(['address_id', 'amount']);

        $actuales = [];

        foreach ($filas as $fila) {
            $actuales[(int) $fila->address_id] = self::cantidad($fila->amount);
        }

        return $actuales;
    }

    /**
     * Una cantidad del request o de la base como float: `''` y null valen 0.
     *
     * @param  mixed  $valor
     * @return float
     */
    static function cantidad($valor) {

        if (is_null($valor) || $valor === '') {
            return 0.0;
        }

        return (float) $valor;
    }

    /**
     * @param  float  $a
     * @param  float  $b
     * @return bool
     */
    static function iguales($a, $b) {

        return abs($a - $b) < self::TOLERANCIA;
    }

    /**
     * El nombre visible de la sucursal (el mismo que usa el asistente: `addresses.street`, o
     * "Sucursal N" si está vacío).
     *
     * @param  int  $address_id
     * @return string
     */
    static function nombre_de_sucursal($address_id) {

        $address = Address::find((int) $address_id);

        return is_null($address) ? 'Sucursal '.(int) $address_id : PropuestaStockIaHelper::nombre_de_deposito($address);
    }

    function guardar_stock_movement($amount, $segundos) {

        $ct_stock_movement = new StockMovementController();

        $data = [];

        $data['model_id'] = $this->article->id;

        $data['amount'] = $amount;

        $data['to_address_id'] = $this->address['id'];

        // $data['employee_id'] = UserHelper::user(false)->id;

        $article_address = $this->get_article_address();
        
        if (is_null($article_address)) {

            $data['concepto_stock_movement_name'] = 'Creacion de deposito';
        }  else {

            $data['concepto_stock_movement_name'] = 'Actualizacion de deposito';
        }

        
        $ct_stock_movement->crear($data, false, null, null, $segundos);
    }

    /**
     * ¿La sucursal del renglón sigue existiendo para el comercio del artículo? (misión
     * eliminar-sucursal-con-stock, 5/10/2026).
     *
     * La SPA arma la lista de sucursales de su store, y una pestaña abierta desde antes de que
     * alguien borrara una sucursal la sigue mandando. Sin esta guarda, `attach()` le abría al
     * artículo una fila para la sucursal borrada (stock fantasma). Se SALTEA sin error a propósito:
     * el resto de las sucursales del mismo guardado son válidas y el usuario no puede hacer nada con
     * un 422 por una fila que ni ve.
     *
     * Criterio del motor (existe_para_stock), no el estricto: la fila que un pedido de la tienda con
     * envío le dejó al domicilio del comprador aparece en la ficha del artículo y se tiene que poder
     * corregir desde ahí.
     *
     * @param  array  $address  Renglón del request (`id`, `pivot`).
     * @return bool
     */
    function sucursal_viva($address) {

        $address_id = isset($address['id']) ? $address['id'] : null;

        if (SucursalVigenteHelper::existe_para_stock($address_id, $this->article->user_id)) {
            return true;
        }

        Log::warning('UpdateAddressesStockHelper: se saltea la sucursal '.$address_id.' del artículo '.$this->article->id.': ya no existe.');

        return false;
    }

    function get_article_address() {

        $article_address = null;

        foreach ($this->article->addresses as $address) {
            
            if ($address->id == $this->address['id']) {

                $article_address = $address;
            }
        }

        return $article_address;
    }
}