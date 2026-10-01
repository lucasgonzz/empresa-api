<?php

namespace App\Http\Controllers\Helpers\combo;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Jobs\RecalcularCombosCalculados;
use App\Models\Combo;
use App\Models\PriceType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El combo que se calcula solo a partir de sus artículos (misión combos-calculados, 30/9/2026).
 *
 * Un combo con `calcular_desde_articulos = 1` no tiene costo ni precio "cargados": tiene la cuenta
 * de sus componentes. Este helper es EL lugar donde se hace esa cuenta y donde se escribe el
 * resultado (`combos.cost`, `combos.price` y `combo_price_type`). No hay otra implementación: ni el
 * SPA calcula el precio del combo (solo lo muestra), ni la tienda (solo lo lee), ni la venta (que
 * congela el costo por su cuenta, ver la Parte A2 de la misión).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  LA CUENTA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  COSTO del combo = Σ (costo unitario de cada componente x su cantidad), en pesos, redondeado a 2
 *  decimales. El costo unitario de un componente es LA MISMA regla que `SaleHelper::getCost()` usa
 *  cuando se vende un artículo (solo que sin una venta de por medio): `costo_real` (o `cost` si
 *  `costo_real` es NULL), cotizado a pesos con `ArticleHelper::cotizar()` si el artículo está en
 *  dólares, y dividido por `unidades_individuales` (el costo del artículo es por BULTO; el precio
 *  de venta, por unidad). Si mañana cambia una de esas reglas en la venta, tiene que cambiar acá.
 *
 *  PRECIO del combo en una lista = Σ (precio de cada componente en esa lista x su cantidad) y,
 *  después, el descuento del combo. El precio de cada componente NO se recalcula acá: lo resuelve
 *  `ArticlePricesHelper::resolver_precio_de_venta()`, que es el ÚNICO lugar que sabe "cuánto sale
 *  un artículo en esta lista" (con su cascada de lista pedida -> lista por defecto -> final_price).
 *  Reimplementarlo acá es exactamente cómo se termina con dos precios distintos para el mismo
 *  artículo en dos pantallas.
 *
 *  El DESCUENTO (`descuento_tipo` + `descuento_valor`) se aplica SOLO al precio, nunca al costo: el
 *  costo es lo que le cuesta al comercio, y un descuento comercial no lo baja. Con listas de
 *  precio, el mismo a cada lista (el % igual sobre cada precio; el monto restado a cada lista).
 *
 *  CUENTAS CON LISTAS: una fila de `combo_price_type` por cada lista del dueño (TODAS, también las
 *  ocultas al público: Vender y la tienda nueva eligen por fila), y `combos.price` = el precio de
 *  la lista por defecto PARA UNA LECTURA SIN LISTA (`lista_para_combos_price()`): la de mayor
 *  `position` (desempate por id más alto, el criterio de `resolver_precio_de_venta()`) ENTRE LAS
 *  QUE NO ESTÁN OCULTAS AL PÚBLICO. 🔴 Eso último no es adorno: es lo que hace que una tienda o un
 *  SPA que todavía no conoce `combo_price_type` siga funcionando leyendo `combos.price`, y una
 *  tienda vieja solo lee `combos.price`: si se copiara el precio de una lista oculta (un
 *  Mayorista, por ejemplo), le mostraría y le cobraría al público el precio de esa lista. Solo si
 *  TODAS están ocultas no hay una pública que elegir y se usa la de mayor `position`, como antes.
 *  CUENTAS SIN LISTAS: solo `combos.price`, y `combo_price_type` queda vacía para ese combo.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  CUÁNDO SE RECALCULA (los "disparadores")
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Cada vez que puede haber cambiado el costo o el precio de un componente: `recalcular_por_articulos()`
 *  lo llama `ArticleHelper::setFinalPrice()` (guardado de un artículo), las masivas, el cierre de
 *  las importaciones, el cierre de los recálculos por cola, la reversión de una importación, la
 *  sincronización de descuentos del proveedor, y el borrado/restauración de un artículo. Y el ABM
 *  del combo lo llama al guardar. Además hay una red de seguridad diaria (`combos:recalcular`),
 *  porque existen escrituras crudas (`DB::table('articles')->update`, cambios de cotización que no
 *  pasan por `setFinalPrice`) que ningún gancho ve.
 *
 *  Es IDEMPOTENTE: recalcular dos veces lo mismo no escribe nada la segunda vez (compara antes de
 *  escribir), y siempre lee de la base fresca, nunca del modelo que le pasó el llamador.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 ORDEN DE CANDADOS (el que rompe esto rompe la base con deadlocks)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `guardar()` toma el candado del combo (`SELECT ... FOR UPDATE`) ANTES de leer los artículos, y
 *  los artículos se leen SIN candado. El motor de precios en lote bloquea artículos por id
 *  ascendente y nunca toca combos; el recálculo de acá bloquea combos por id ascendente y nunca
 *  bloquea un artículo. Mientras nadie tome un artículo DESPUÉS de un combo dentro de la misma
 *  transacción, los dos órdenes no pueden esperarse en círculo. Por eso `recalcular_por_articulos()`
 *  recorre los combos en orden de id, y por eso este helper no hace ningún `lockForUpdate()` sobre
 *  `articles`. El candado tomado ANTES de leer también sirve para que la lectura sea fresca: en
 *  REPEATABLE READ el snapshot se arma en la primera lectura sin candado, y la primera sentencia
 *  de la transacción es la del candado.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  UNA FALLA DE ACÁ NUNCA TUMBA AL QUE LLAMÓ
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `recalcular_por_articulos()` y `recalcular_de_un_dueno()` atrapan las excepciones de cada combo
 *  y las dejan en el log. Los llaman el guardado de un artículo y el cierre de una importación de
 *  20.000 filas: que un combo mal cargado (o un deadlock) haga fallar eso sería cambiar un combo
 *  desactualizado por un artículo que no se puede guardar. La red de seguridad diaria lo corrige.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class ComboCalculadoHelper {

    const DESCUENTO_PORCENTAJE = 'porcentaje';
    const DESCUENTO_MONTO      = 'monto';

    /** Lo más grande que entra en `combos.descuento_valor` (DECIMAL(12,2)). */
    const DESCUENTO_MAXIMO = 9999999999.99;

    /** Ids por consulta en los `whereIn` (un recálculo masivo puede traer decenas de miles). */
    const TANDA_DE_IDS = 1000;

    /**
     * Hasta cuántos combos se recalculan EN LÍNEA (dentro del request que los disparó). Más que
     * eso se encola el job `RecalcularCombosCalculados` (F5): cada combo cuesta ~8-10 consultas y
     * hacer decenas dentro de un request de guardado de artículo o de edición de una lista es
     * hacer esperar a la persona por algo que no necesita ver.
     */
    const MAXIMO_EN_LINEA = 25;

    /**
     * Tipos de descuento válidos.
     *
     * @return array<int,string>
     */
    static function tipos_de_descuento() {
        return [self::DESCUENTO_PORCENTAJE, self::DESCUENTO_MONTO];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  El descuento
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Si el descuento que llegó en el request es inválido, el motivo; si está bien (o no hay), null.
     *
     * La usa el ABM para responder 422 con un mensaje legible en vez de guardar un descuento que
     * después el cálculo ignora en silencio. "Sin descuento" es válido de cualquier forma: tipo
     * vacío, o valor vacío/0.
     *
     * Reglas: el tipo es 'porcentaje' o 'monto'; el valor es un número mayor o igual a 0; un
     * porcentaje tiene que ser MENOR a 100 (un 100 % regala el combo, y eso no es un descuento, es
     * un error de tipeo o un combo que hay que bajar de la tienda).
     *
     * @param  mixed  $tipo
     * @param  mixed  $valor
     * @return string|null
     */
    static function validar_descuento($tipo, $valor) {

        if (is_null($tipo) || $tipo === '') {
            return null;
        }

        if (!in_array($tipo, self::tipos_de_descuento(), true)) {
            return 'El tipo de descuento del combo tiene que ser "porcentaje" o "monto".';
        }

        if (is_null($valor) || $valor === '') {
            return null;
        }

        if (!is_numeric($valor) || (float) $valor < 0) {
            return 'El valor del descuento del combo tiene que ser un número mayor o igual a 0.';
        }

        /*
         * 🔴 Se valida el valor YA REDONDEADO a 2 decimales (F4), que es el que `normalizar_descuento()`
         * guarda: un 99,996 % pasaba el "menor a 100", se guardaba como 100,00 y `aplicar_descuento()`
         * lo ignoraba (un 100 % no es un descuento): la persona creía tener un descuento que el
         * cálculo no aplicaba.
         */
        $redondeado = round((float) $valor, 2);

        if ($tipo === self::DESCUENTO_PORCENTAJE && $redondeado >= 100) {
            return 'El descuento en porcentaje del combo tiene que ser menor a 100.';
        }

        /* `combos.descuento_valor` es DECIMAL(12,2): un monto mayor sería un error 500 de la base, no un 422. */
        if ($redondeado > self::DESCUENTO_MAXIMO) {
            return 'El valor del descuento del combo no puede superar ' . number_format(self::DESCUENTO_MAXIMO, 2, ',', '.') . '.';
        }

        return null;
    }

    /**
     * El descuento tal como se guarda: tipo válido o NULL, valor numérico >= 0.
     *
     * Lo que no entra se guarda como "sin descuento" (NULL y 0) y no como un texto raro: la columna
     * `descuento_valor` es DECIMAL NOT NULL y un '' la rompe en MySQL estricto.
     *
     * @param  mixed  $tipo
     * @param  mixed  $valor
     * @return array  ['descuento_tipo' => string|null, 'descuento_valor' => float]
     */
    static function normalizar_descuento($tipo, $valor) {

        if (!in_array($tipo, self::tipos_de_descuento(), true)) {
            return ['descuento_tipo' => null, 'descuento_valor' => 0.0];
        }

        $valor = (is_numeric($valor) && (float) $valor > 0) ? round((float) $valor, 2) : 0.0;

        // Defensivo: un valor fuera de la columna (lo rechaza validar_descuento) no puede llegar al INSERT.
        $valor = min($valor, self::DESCUENTO_MAXIMO);

        return ['descuento_tipo' => $tipo, 'descuento_valor' => $valor];
    }

    /**
     * Aplica el descuento a un precio (sin redondear: el redondeo lo hace quien suma).
     *
     * 🔴 Defensivo a propósito, aunque `validar_descuento()` ya rechaza estos casos en el ABM: hay
     * combos que se cargaron por otro camino (el asistente de IA, una importación, un valor viejo).
     * Un porcentaje fuera de 0 < p < 100 se IGNORA (no se aplica 150 % ni -20 %: un precio negativo
     * o inflado por error es peor que un descuento que no se aplicó), y un monto nunca deja el
     * precio por debajo de 0.
     *
     * @param  float        $precio
     * @param  string|null  $tipo
     * @param  mixed        $valor
     * @return float
     */
    static function aplicar_descuento($precio, $tipo, $valor) {

        $valor = is_numeric($valor) ? (float) $valor : 0.0;

        if ($tipo === self::DESCUENTO_PORCENTAJE && $valor > 0 && $valor < 100) {
            return $precio * (1 - $valor / 100);
        }

        if ($tipo === self::DESCUENTO_MONTO && $valor > 0) {
            return max(0.0, $precio - $valor);
        }

        return $precio;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  La cuenta
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El costo unitario de un componente, en pesos: lo que costaría UNA unidad individual.
     *
     * Misma regla que `SaleHelper::getCost()` sin venta (ver el encabezado). Una diferencia
     * deliberada: la cotización usa `ArticleHelper::cotizar()` (dólar del proveedor o dólar global
     * del dueño, y solo si la cuenta cotiza en dólares) y no el `valor_dolar` de una venta, porque
     * acá no hay venta; es la misma cotización con la que se calculó el precio de venta del
     * artículo, así que costo y precio del combo hablan del mismo dólar.
     *
     * @param  \App\Models\Article  $article  Con `provider` cargado (lo lee la cotización).
     * @param  \App\Models\User     $owner    Dueño de la cuenta.
     * @return float
     */
    static function costo_unitario_de_articulo($article, $owner) {

        $costo = 0.0;

        /*
         * `costo_real` manda; `cost` solo si `costo_real` es NULL. Un costo_real de 0 NO cae a
         * `cost`: es la misma lectura que hace getCost() (isset), y un artículo con costo real 0
         * es un artículo que el dueño dice que no le cuesta.
         */
        if (!is_null($article->costo_real)) {
            $costo = (float) $article->costo_real;
        } else if (!is_null($article->cost)) {
            $costo = (float) $article->cost;
        }

        if ($costo > 0 && $article->cost_in_dollars) {
            $res   = ArticleHelper::cotizar($article, $owner, $costo, []);
            $costo = (float) $res['price'];
        }

        /*
         * El costo del artículo es por BULTO y el precio, por unidad individual: el combo lleva
         * unidades individuales, así que se divide. Sin esto un artículo de "caja x 12" costaría
         * 12 veces de más en el combo.
         */
        $unidades = (float) $article->unidades_individuales;

        if ($unidades > 0) {
            $costo /= $unidades;
        }

        return $costo;
    }

    /**
     * Calcula costo, precio y precios por lista de un combo. NO escribe nada.
     *
     * Lee los artículos de la base (con sus listas y su proveedor), incluidos los borrados: un
     * componente borrado sigue contando con los últimos valores que tuvo. Sacarlo de la cuenta
     * abarataría el combo en silencio; lo que corresponde es que el combo NO SE PUEDA VENDER, y
     * eso lo dice el stock (`ComboStockHelper`: componente borrado = 0), no el precio.
     *
     * Un componente sin precio (NULL) suma 0 al precio del combo.
     *
     * @param  \App\Models\Combo         $combo  Con `id`, `descuento_tipo` y `descuento_valor`.
     * @param  \App\Models\User|null     $user   Dueño (o empleado: se resuelve al dueño). Null = el del combo.
     * @return array  ['cost' => float, 'price' => float, 'precios_por_lista' => [price_type_id => float]]
     */
    static function calcular(Combo $combo, $user = null) {

        $owner = self::resolver_dueno($user, $combo->user_id);

        $articulos = $combo->articles()->with('price_types', 'provider')->get();

        $costo = 0.0;

        foreach ($articulos as $articulo) {
            $costo += self::costo_unitario_de_articulo($articulo, $owner) * (float) $articulo->pivot->amount;
        }

        $listas = [];

        if (UserHelper::uses_listas_de_precio($owner)) {
            $listas = PriceType::where('user_id', $owner->id)
                                ->orderBy('position', 'ASC')
                                ->orderBy('id', 'ASC')
                                ->get();
        }

        $precios_por_lista = [];
        $precio            = 0.0;

        if (count($listas) > 0) {

            foreach ($listas as $lista) {
                $precios_por_lista[(int) $lista->id] = self::precio_del_combo_en_lista($combo, $articulos, $owner, $lista->id);
            }

            // Las filas de `combo_price_type` cubren TODAS las listas; solo `combos.price` mira la visibilidad.
            $por_defecto = self::lista_para_combos_price($listas);

            $precio = $precios_por_lista[(int) $por_defecto->id];

        } else {

            $precio = self::precio_del_combo_en_lista($combo, $articulos, $owner, null);
        }

        return [
            'cost'              => round($costo, 2),
            'price'             => $precio,
            'precios_por_lista' => $precios_por_lista,
        ];
    }

    /**
     * El precio del combo en UNA lista (o en el precio único, con `$price_type_id` null), con el
     * descuento aplicado y redondeado a 2 decimales.
     *
     * @param  \App\Models\Combo  $combo
     * @param  iterable           $articulos
     * @param  \App\Models\User   $owner
     * @param  int|null           $price_type_id
     * @return float
     */
    protected static function precio_del_combo_en_lista(Combo $combo, $articulos, $owner, $price_type_id) {

        $subtotal = 0.0;

        foreach ($articulos as $articulo) {

            $res = ArticlePricesHelper::resolver_precio_de_venta($articulo, $owner, $price_type_id);

            $subtotal += (float) $res['final_price'] * (float) $articulo->pivot->amount;
        }

        return round(self::aplicar_descuento($subtotal, $combo->descuento_tipo, $combo->descuento_valor), 2);
    }

    /**
     * La lista por defecto: la de mayor `position`, y a igual `position` la de id más alto.
     *
     * Es el mismo criterio que `ArticlePricesHelper::resolver_precio_de_venta()` (rama 4) y que
     * `empresa-spa/src/mixins/vender/price_types.js`. Si algún día se agrega una columna de "lista
     * por defecto", se cambia en esos tres lugares y en ninguno más.
     *
     * @param  iterable  $listas  PriceType del dueño.
     * @return \App\Models\PriceType|null
     */
    static function lista_por_defecto($listas) {

        $elegida = null;

        foreach ($listas as $lista) {

            if (is_null($elegida)) {
                $elegida = $lista;
                continue;
            }

            $position         = is_null($lista->position) ? 0 : (int) $lista->position;
            $position_elegida = is_null($elegida->position) ? 0 : (int) $elegida->position;

            if ($position > $position_elegida
                || ($position === $position_elegida && (int) $lista->id > (int) $elegida->id)) {

                $elegida = $lista;
            }
        }

        return $elegida;
    }

    /**
     * La lista cuyo precio se copia a `combos.price`: la lista por defecto ENTRE LAS QUE NO ESTÁN
     * OCULTAS AL PÚBLICO (`ocultar_al_publico` distinto de 1); si TODAS están ocultas, la por
     * defecto de todas.
     *
     * `combos.price` es lo único que lee una tienda vieja (y un SPA viejo): elegir ahí una lista
     * oculta sería publicar al público el precio interno de esa lista. No se aplica a las filas de
     * `combo_price_type`, que siguen siendo una por lista: quien conoce las listas elige por fila.
     *
     * 🔴 A propósito no es lo mismo que `ArticlePricesHelper::resolver_precio_de_venta()` (rama
     * "lista_por_defecto"), que no mira la visibilidad: esa rama es para el ERP, donde el vendedor
     * sí puede vender con una lista oculta. Acá la pregunta es "qué ve alguien que no sabe de
     * listas".
     *
     * @param  iterable  $listas  PriceType del dueño.
     * @return \App\Models\PriceType|null
     */
    static function lista_para_combos_price($listas) {

        $publicas = [];

        foreach ($listas as $lista) {

            if ((int) $lista->ocultar_al_publico !== 1) {
                $publicas[] = $lista;
            }
        }

        return self::lista_por_defecto(count($publicas) > 0 ? $publicas : $listas);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Escribir el resultado
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Recalcula un combo calculado y escribe el resultado, si cambió.
     *
     * No hace nada (devuelve null) si el esquema no está migrado, si el combo no existe o está
     * borrado, o si NO está marcado como calculado. Que el combo se relea de la base adentro de la
     * transacción, con candado, es a propósito: el `$combo` que llega puede ser una copia vieja, y
     * el interruptor o el descuento pudieron cambiar desde que se cargó.
     *
     * 🔴 El modelo `$combo` que se pasa NO se actualiza: quien necesite los números nuevos tiene
     * que releerlo (los controladores devuelven `fullModel()`, que lo hace).
     *
     * @param  \App\Models\Combo      $combo
     * @param  \App\Models\User|null  $user   Dueño, ya resuelto por el llamador (evita un find por combo).
     * @return array|null  El cálculo (`cost`, `price`, `precios_por_lista`) o null si no correspondía.
     */
    static function guardar(Combo $combo, $user = null) {

        if (!ComboCalculadoEsquemaHelper::disponible()) {
            return null;
        }

        return DB::transaction(function () use ($combo, $user) {

            /* PRIMERA sentencia de la transacción: el candado. Ver "Orden de candados". */
            $fila = Combo::where('id', $combo->id)->lockForUpdate()->first();

            if (is_null($fila) || !(int) $fila->calcular_desde_articulos) {
                return null;
            }

            $calculo = self::calcular($fila, $user);

            $guardo_la_fila = false;

            if (!self::mismo_numero($fila->cost, $calculo['cost']) || !self::mismo_numero($fila->price, $calculo['price'])) {

                $fila->cost  = $calculo['cost'];
                $fila->price = $calculo['price'];
                $fila->save();

                $guardo_la_fila = true;
            }

            $cambiaron_las_listas = self::escribir_precios_por_lista($fila->id, $calculo['precios_por_lista']);

            /*
             * F6: si cambió el precio de UNA LISTA que no es la de `combos.price` (ni el costo), la
             * fila del combo no se guardó y su `updated_at` no se movió. Hay clientes que sincronizan
             * el catálogo por `updated_at`, y para ellos un combo con un precio por lista nuevo
             * tendría que verse como modificado. `touch()` solo mueve `updated_at` (no pasa por la
             * comparación de arriba, que a propósito evita reescribir lo que no cambió).
             */
            if ($cambiaron_las_listas && !$guardo_la_fila) {
                $fila->touch();
            }

            return $calculo;
        });
    }

    /**
     * Deja `combo_price_type` de un combo igual a `$precios` (price_type_id => precio), tocando la
     * base SOLO si algo cambió. Sin modelo Eloquent a propósito: es una tabla derivada, y una fila
     * de auditoría por cada lista de cada recálculo sería ruido puro (el cambio real ya queda
     * registrado en el propio combo).
     *
     * @param  int    $combo_id
     * @param  array  $precios
     * @return bool  true si escribió algo (cambió alguna fila), false si ya estaba igual.
     */
    protected static function escribir_precios_por_lista($combo_id, array $precios) {

        $existentes = DB::table('combo_price_type')->where('combo_id', $combo_id)->get(['price_type_id', 'price']);

        $iguales = count($existentes) === count($precios);

        if ($iguales) {
            foreach ($existentes as $fila) {
                $id = (int) $fila->price_type_id;

                if (!array_key_exists($id, $precios) || !self::mismo_numero($fila->price, $precios[$id])) {
                    $iguales = false;
                    break;
                }
            }
        }

        if ($iguales) {
            return false;
        }

        DB::table('combo_price_type')->where('combo_id', $combo_id)->delete();

        $ahora = Carbon::now();
        $filas = [];

        foreach ($precios as $price_type_id => $precio) {
            $filas[] = [
                'combo_id'      => $combo_id,
                'price_type_id' => $price_type_id,
                'price'         => $precio,
                'created_at'    => $ahora,
                'updated_at'    => $ahora,
            ];
        }

        if (count($filas) > 0) {
            DB::table('combo_price_type')->insert($filas);
        }

        return true;
    }

    /**
     * Borra los precios por lista de un combo. Se llama cuando el combo deja de ser calculado:
     * `combos.price` pasa a ser el número que cargó la persona, y las filas viejas de
     * `combo_price_type` (que una tienda nueva prefiere sobre `combos.price`) mostrarían un precio
     * que ya no es el del combo.
     *
     * @param  int  $combo_id
     * @return void
     */
    static function limpiar_precios_por_lista($combo_id) {

        if (!ComboCalculadoEsquemaHelper::disponible()) {
            return;
        }

        DB::table('combo_price_type')->where('combo_id', $combo_id)->delete();
    }

    /**
     * Borra de `combo_price_type` las filas de una lista que el dueño acaba de eliminar.
     *
     * El recálculo de los combos calculados ya las saca (reescribe sus filas con las listas que
     * quedan), pero este borrado directo no depende de que el recálculo corra ni de que el combo
     * siga siendo calculado: una fila huérfana es un precio por una lista que ya no existe y una
     * tienda nueva la mostraría. Es una tabla derivada, sin modelo ni auditoría.
     *
     * @param  int  $price_type_id
     * @return void
     */
    static function olvidar_lista($price_type_id) {

        if (!ComboCalculadoEsquemaHelper::disponible()) {
            return;
        }

        DB::table('combo_price_type')->where('price_type_id', $price_type_id)->delete();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Los disparadores
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Recalcula los combos calculados que incluyen alguno de estos artículos.
     *
     * Una sola consulta indexada (`article_combo.article_id` + el filtro del interruptor) decide si
     * hay algo que hacer: en la inmensa mayoría de los guardados de un artículo la respuesta es "no
     * está en ningún combo calculado" y esto cuesta esa consulta y nada más. Es el motivo por el que
     * se puede llamar desde `setFinalPrice()` sin miedo.
     *
     * Los combos se recorren en orden de id (ver "Orden de candados"). Una falla en uno se registra
     * y no corta a los demás ni al llamador.
     *
     * @param  array     $article_ids  Ids de artículos que pudieron cambiar de costo o de precio.
     * @param  int|null  $user_id      Si viene, solo los combos de ese dueño.
     * @return int  Cuántos combos se recalcularon (con o sin cambios).
     */
    static function recalcular_por_articulos(array $article_ids, $user_id = null) {

        if (!ComboCalculadoEsquemaHelper::disponible()) {
            return 0;
        }

        $ids = [];

        foreach ($article_ids as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }

        if (count($ids) === 0) {
            return 0;
        }

        /*
         * El corte barato (F5): una consulta EXISTS sobre `combos`, ANTES del JOIN con
         * `article_combo`. La enorme mayoría de las cuentas no tiene ningún combo calculado, y a
         * esas el guardado de un artículo no tiene que pagarles el JOIN ni el armado de los ids.
         */
        if (!self::hay_combos_calculados($user_id)) {
            return 0;
        }

        $combo_ids = self::combos_que_incluyen(array_values($ids), $user_id);

        if (is_null($combo_ids) || count($combo_ids) === 0) {
            return 0;
        }

        return self::recalcular_o_encolar($combo_ids, $user_id);
    }

    /**
     * ¿El dueño (o, sin `$user_id`, cualquiera) tiene algún combo calculado? Una sola consulta
     * EXISTS. Si la consulta falla devuelve true: el que sigue tiene su propio manejo de errores y
     * es mejor intentar que dejar de recalcular en silencio.
     *
     * @param  int|null  $user_id
     * @return bool
     */
    static function hay_combos_calculados($user_id = null) {

        try {

            $consulta = DB::table('combos')
                            ->where('calcular_desde_articulos', 1)
                            ->whereNull('deleted_at');

            if (!is_null($user_id)) {
                $consulta->where('user_id', self::id_del_dueno($user_id));
            }

            return $consulta->exists();

        } catch (\Throwable $e) {

            return true;
        }
    }

    /**
     * Los ids (ordenados) de los combos calculados que incluyen alguno de estos artículos, o null
     * si la búsqueda falló (queda en el log; la red de seguridad diaria lo corrige).
     *
     * @param  array     $article_ids  Ids ya limpios.
     * @param  int|null  $user_id      Si viene, solo los combos de ese dueño.
     * @return array|null
     */
    protected static function combos_que_incluyen(array $article_ids, $user_id = null) {

        $combo_ids = [];

        try {

            foreach (array_chunk($article_ids, self::TANDA_DE_IDS) as $tanda) {

                $consulta = DB::table('article_combo')
                                ->join('combos', 'combos.id', '=', 'article_combo.combo_id')
                                ->whereIn('article_combo.article_id', $tanda)
                                ->where('combos.calcular_desde_articulos', 1)
                                ->whereNull('combos.deleted_at');

                if (!is_null($user_id)) {
                    $consulta->where('combos.user_id', self::id_del_dueno($user_id));
                }

                foreach ($consulta->pluck('combos.id') as $combo_id) {
                    $combo_ids[(int) $combo_id] = (int) $combo_id;
                }
            }

        } catch (\Throwable $e) {

            Log::warning('ComboCalculadoHelper: no se pudo buscar los combos que incluyen estos artículos', [
                'articulos' => count($article_ids),
                'motivo'    => $e->getMessage(),
            ]);

            return null;
        }

        $combo_ids = array_values($combo_ids);

        sort($combo_ids);

        return $combo_ids;
    }

    /**
     * Recalcula estos combos: en línea si son pocos (`MAXIMO_EN_LINEA`), o encolando el job si son
     * más. Devuelve cuántos combos se recalcularon o quedaron encolados.
     *
     * Si encolar falla (la cola no responde) se deja en el log y NO se recalcula en línea: el
     * request que llegó hasta acá no puede pagar decenas de combos, y la red de seguridad diaria
     * (`combos:recalcular`) corrige lo que quedó viejo.
     *
     * @param  array     $combo_ids  Ids ordenados.
     * @param  int|null  $user_id
     * @return int
     */
    protected static function recalcular_o_encolar(array $combo_ids, $user_id = null) {

        if (count($combo_ids) <= self::MAXIMO_EN_LINEA) {
            return self::recalcular_combos($combo_ids);
        }

        try {

            RecalcularCombosCalculados::dispatch($user_id, $combo_ids);

            return count($combo_ids);

        } catch (\Throwable $e) {

            Log::warning('ComboCalculadoHelper: no se pudo encolar el recálculo de combos (lo corrige la red de seguridad diaria)', [
                'combos' => count($combo_ids),
                'motivo' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Recalcula exactamente estos combos (ya buscados), sin decidir nada más. Es lo que llama el
     * job `RecalcularCombosCalculados` en su modo "estos combos".
     *
     * @param  array  $combo_ids
     * @return int
     */
    static function recalcular_ids(array $combo_ids) {

        if (!ComboCalculadoEsquemaHelper::disponible()) {
            return 0;
        }

        sort($combo_ids);

        return self::recalcular_combos($combo_ids);
    }

    /**
     * Encola el recálculo de TODOS los combos calculados del dueño (si tiene alguno). Lo usan los
     * cierres de corridas masivas: se encola DESPUÉS de cerrar la corrida, para que un timeout o una
     * falla del recálculo de combos no pueda dejarla abierta. Devuelve true si encoló algo.
     *
     * Nunca tira: una falla al encolar queda en el log y la red de seguridad diaria la corrige.
     *
     * @param  int  $user_id
     * @return bool
     */
    static function encolar_recalculo_de_un_dueno($user_id) {

        if (!ComboCalculadoEsquemaHelper::disponible() || !self::hay_combos_calculados($user_id)) {
            return false;
        }

        try {

            RecalcularCombosCalculados::dispatch($user_id, null);

            return true;

        } catch (\Throwable $e) {

            Log::warning('ComboCalculadoHelper: no se pudo encolar el recálculo de los combos del dueño', [
                'user_id' => $user_id,
                'motivo'  => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Recalcula los combos calculados del dueño desde un request: en línea si son pocos
     * (`MAXIMO_EN_LINEA`), encolando el job si son más. Lo usan la edición y el borrado de una lista
     * de precios.
     *
     * @param  int  $user_id  Dueño (o un empleado: se resuelve al dueño).
     * @return int  Cuántos combos se recalcularon o quedaron encolados.
     */
    static function recalcular_de_un_dueno_o_encolar($user_id) {

        if (!ComboCalculadoEsquemaHelper::disponible()) {
            return 0;
        }

        try {

            $cantidad = Combo::where('user_id', self::id_del_dueno($user_id))
                                ->where('calcular_desde_articulos', 1)
                                ->count();

        } catch (\Throwable $e) {

            Log::warning('ComboCalculadoHelper: no se pudo contar los combos calculados del dueño', [
                'user_id' => $user_id,
                'motivo'  => $e->getMessage(),
            ]);

            return 0;
        }

        if ($cantidad === 0) {
            return 0;
        }

        if ($cantidad <= self::MAXIMO_EN_LINEA) {
            return self::recalcular_de_un_dueno($user_id);
        }

        return self::encolar_recalculo_de_un_dueno($user_id) ? $cantidad : 0;
    }

    /**
     * Recalcula TODOS los combos calculados de un dueño. Lo usan los cierres de los procesos
     * masivos (donde ya no se sabe qué artículos cambiaron) y la red de seguridad diaria.
     *
     * @param  int  $user_id  Dueño (o un empleado: se resuelve al dueño).
     * @return int  Cuántos combos se recalcularon.
     */
    static function recalcular_de_un_dueno($user_id) {

        if (!ComboCalculadoEsquemaHelper::disponible()) {
            return 0;
        }

        try {

            $combo_ids = Combo::where('user_id', self::id_del_dueno($user_id))
                                ->where('calcular_desde_articulos', 1)
                                ->orderBy('id', 'ASC')
                                ->pluck('id')
                                ->all();

        } catch (\Throwable $e) {

            Log::warning('ComboCalculadoHelper: no se pudo buscar los combos calculados del dueño', [
                'user_id' => $user_id,
                'motivo'  => $e->getMessage(),
            ]);

            return 0;
        }

        return self::recalcular_combos($combo_ids);
    }

    /**
     * Recalcula una lista de combos (ya ordenada por id), atrapando las fallas de cada uno.
     *
     * @param  array  $combo_ids
     * @return int
     */
    protected static function recalcular_combos(array $combo_ids) {

        $recalculados = 0;

        /* Un dueño por user_id, resuelto una sola vez por corrida. */
        $duenos = [];

        foreach ($combo_ids as $combo_id) {

            try {

                $combo = Combo::find($combo_id);

                if (is_null($combo)) {
                    continue;
                }

                if (!array_key_exists($combo->user_id, $duenos)) {
                    $duenos[$combo->user_id] = self::resolver_dueno(null, $combo->user_id);
                }

                $calculo = self::guardar($combo, $duenos[$combo->user_id]);

                /*
                 * F3: un recálculo de fondo NO rechaza nada (no hay a quién responderle 422, y el
                 * precio de un componente puede estar sin cargar solo un rato), pero un combo
                 * PUBLICADO que queda en precio <= 0 se deja dicho en el log para que alguien lo vea.
                 * La tienda ya oculta los combos con precio 0, así que no se vende regalado.
                 */
                if (!is_null($calculo) && (int) $combo->online === 1 && (float) $calculo['price'] <= 0) {

                    Log::warning('ComboCalculadoHelper: un combo publicado en la tienda quedó con precio 0 tras el recálculo', [
                        'combo_id' => $combo->id,
                        'user_id'  => $combo->user_id,
                    ]);
                }

                $recalculados++;

            } catch (\Throwable $e) {

                Log::warning('ComboCalculadoHelper: falló el recálculo de un combo (se sigue con los demás)', [
                    'combo_id' => $combo_id,
                    'motivo'   => $e->getMessage(),
                    'archivo'  => $e->getFile(),
                    'linea'    => $e->getLine(),
                ]);
            }
        }

        return $recalculados;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Auxiliares
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El dueño de la cuenta, como modelo. `$user` puede ser el dueño, un empleado o null (se usa
     * `$user_id_de_respaldo`, el `user_id` del combo).
     *
     * @param  \App\Models\User|null  $user
     * @param  int|null               $user_id_de_respaldo
     * @return \App\Models\User
     */
    protected static function resolver_dueno($user, $user_id_de_respaldo) {

        if (is_null($user)) {
            $user = User::find($user_id_de_respaldo);
        }

        if (!is_null($user) && !empty($user->owner_id)) {
            $user = User::find($user->owner_id);
        }

        if (is_null($user)) {
            throw new \RuntimeException('ComboCalculadoHelper: no se encontró el dueño de la cuenta (user_id '.var_export($user_id_de_respaldo, true).').');
        }

        return $user;
    }

    /**
     * El id del dueño a partir del id de un usuario cualquiera (dueño o empleado).
     *
     * @param  int  $user_id
     * @return int
     */
    protected static function id_del_dueno($user_id) {

        $owner_id = DB::table('users')->where('id', $user_id)->value('owner_id');

        return empty($owner_id) ? (int) $user_id : (int) $owner_id;
    }

    /**
     * Si dos importes son el mismo número al centavo. Los DECIMAL de la base vuelven como texto
     * ('12.50') y los cálculos como float: se compara redondeado a 2 decimales para que un
     * '12.50' contra 12.5 no cuente como cambio (y no reescriba la fila en cada recálculo).
     *
     * @param  mixed  $a
     * @param  mixed  $b
     * @return bool
     */
    protected static function mismo_numero($a, $b) {

        if (is_null($a) || is_null($b)) {
            return is_null($a) && is_null($b);
        }

        return round((float) $a, 2) === round((float) $b, 2);
    }
}
