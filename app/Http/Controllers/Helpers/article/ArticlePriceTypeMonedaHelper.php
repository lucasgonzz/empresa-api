<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\DesglosePrecioHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\import\article\motor\PreciosEnLote;
use App\Models\Moneda;
use App\Models\PriceType;
use Illuminate\Support\Facades\Log;

class ArticlePriceTypeMonedaHelper {

    // Solo adjunto los datos que vienen del front, no calculo ningun precio ni porcentaje
    static function attach_price_type_monedas($article, $price_type_monedas, $user = null) {

        if (empty($price_type_monedas)) return;

        if (!$user) {
            $user = UserHelper::user();
        }

        // $cost = $article->cost;

        // $cost = ArticlePricesHelper::aplicar_iva($article, $cost, $user);
        
        foreach ($price_type_monedas as $ptm) {

            $moneda_id = $ptm['moneda_id'];
            
            $setear_precio_final = 0;

            if (
                isset($ptm['setear_precio_final'])
                && (
                    $ptm['setear_precio_final'] == 1
                    || $ptm['setear_precio_final'] == '1'
                )
            ) {

                $setear_precio_final = 1;
            }

            

            $cotizar_desde_otra_moneda = 0;

            if (
                isset($ptm['cotizar_desde_otra_moneda'])
                && (
                    $ptm['cotizar_desde_otra_moneda'] == 1
                    || $ptm['cotizar_desde_otra_moneda'] == '1'
                )
            ) {
                $cotizar_desde_otra_moneda = 1;
            }

            $percentage = (float)$ptm['percentage'];
            $final_price = (float)$ptm['final_price'];

            $article->price_type_monedas()->updateOrCreate(
                [
                    'price_type_id' => $ptm['price_type_id'],
                    'moneda_id'     => $moneda_id,
                ],
                [
                    'percentage'                     => $percentage,
                    'final_price'                    => $final_price,
                    'setear_precio_final'            => $setear_precio_final,
                    'cotizar_desde_otra_moneda'      => $cotizar_desde_otra_moneda,
                ]
            );
        }
    }
        
    /*
        Con las relaciones y valores ya almacenados por attach_price_type_monedas, calculo y guardo el precio final.
        Recibo el costo SEGUN LA CONDICION FISCAL de la cuenta, no "con IVA" (prompt 379/02: hasta ese
        prompt el docblock decia "con iva", literalmente falso desde el grupo 231 para las cuentas
        migradas). Ver ArticlePricesHelper::iva_va_al_costo(): para una cuenta MIGRADA (RRII o MT) el
        costo llega NETO de este paso; para una cuenta legacy con aplicar_iva_al_costo=1 llega CON el
        IVA ya adentro. Ojo: desde el 21/8/2026 el costo de un MT migrado ya trae el IVA que pago
        adentro por otro motivo -se guarda tal cual se cargo- pero el pipeline no le suma ninguno
        (se lo suma antes ArticleHelper::aplicar_descuentos_e_iva(), al calcular costo_real). Por eso
        el parametro se llama `$cost_base` (generico), no `$cost_sin_iva` ni `$cost_con_iva`:
        ninguno de esos dos nombres es cierto siempre, y un nombre que miente en la mitad de los
        casos es peor que uno generico.
        El IVA se aplica adentro, en calc_normal(), con el MISMO criterio que el otro camino de listas
        (ArticlePricesHelper::aplicar_precios_segun_listas_de_precios()): despues del margen, solo si
        iva_va_al_costo($user) da false, y solo para las entradas cuyo precio se calcula desde el costo
        (las de setear_precio_final = 1 ya traen el precio armado a mano, con el IVA que corresponda
        adentro, y no se tocan). Los dos caminos de listas TIENEN que decidir el IVA igual -- si se
        cambia el criterio de uno, cambiar el otro.

        Orden IVA vs. cotizacion (verificado, no es un supuesto): el IVA se aplica DESPUES de
        $get_cost_for_moneda() (que solo multiplica/divide por el tipo de cambio). Es indistinto
        matematicamente porque ninguna de las dos operaciones redondea -- $get_cost_for_moneda() es
        una multiplicacion/division pura y ArticlePricesHelper::aplicar_iva() tampoco llama a
        Numbers::redondear() ni a round() en ningun punto de esta cadena (el unico uso de Numbers aca
        es para texto de $des, que esta funcion ni arma). Sin redondeo intermedio, (costo x tipo de
        cambio) x (1 + iva) y (costo x (1 + iva)) x tipo de cambio dan bit a bit el mismo resultado.
    */
    /**
     * Recalcula los precios por lista y moneda, y despues ESPEJA el precio en pesos de cada lista
     * en la pivot `article_price_type`.
     *
     * 🔴 POR QUE EL ESPEJO, Y POR QUE ACA. `ArticleHelper` elige entre este helper y
     * `ArticlePricesHelper::aplicar_precios_segun_listas_de_precios()` con un if/else EXCLUYENTE
     * sobre la extension `ventas_en_dolares`. O sea que en una cuenta con la extension prendida la
     * pivot `article_price_type` no se volvia a escribir NUNCA — y esa pivot es la que lee la
     * TIENDA (`tienda-api`, `ArticleHelper::checkPriceTypes()`).
     *
     * Consecuencia, medida en pantalla el 30/8/2026 sobre la demo: se le subio el costo un 20% al
     * articulo 45, `price_type_monedas` paso de 10.116,80 a 12.140,15 (y el ERP mostro el numero
     * nuevo), la pivot quedo en 10.429,69, y **la tienda siguio publicando 10.429,69**. No es que
     * tardara en actualizarse: no se actualizaba nunca. Cualquier comercio con `ventas_en_dolares`
     * prendida estaba publicando en su tienda precios distintos de los de su sistema, sin ninguna
     * señal en pantalla.
     *
     * ⚠️ SE ARREGLA DE ESTE LADO Y NO EN LA TIENDA, a proposito. Las dos puntas nunca llegan a
     * produccion al mismo tiempo: `empresa` sale por release a los 40 clientes y cada `tienda` la
     * despliega Lucas a mano, cliente por cliente. Si el arreglo fuera "que la tienda lea
     * price_type_monedas", cada cliente seguiria publicando mal hasta que le toque su despliegue.
     * Espejando acá, **la tienda no se toca**: sigue leyendo exactamente la misma columna de
     * siempre, y empieza a ver el numero correcto apenas el comercio actualiza su ERP.
     *
     * El espejo va envolviendo al calculo y no adentro, porque el calculo tiene varios `return` y
     * `continue`: envolviendolo, ningun camino de salida se puede olvidar de espejar.
     *
     * @param mixed $article Articulo con `price_type_monedas` cargada.
     * @param mixed $_cost   Costo base, o null.
     * @param mixed $user    Dueño de la cuenta.
     *
     * @return void
     */
    public static function aplicar_precios_por_price_type_y_moneda($article, $_cost, $user)
    {
        self::calcular_precios_por_price_type_y_moneda($article, $_cost, $user);

        self::espejar_precio_en_pesos_en_la_pivot($article);
    }

    /**
     * Espeja en `article_price_type` el precio EN PESOS que quedo en `price_type_monedas`.
     *
     * La pivot ya es, para una cuenta sin `ventas_en_dolares`, el precio en pesos de la lista: acá
     * se le mantiene ese mismo significado en vez de inventarle uno nuevo. Quien la lee —la tienda,
     * los Excel para clientes, los PDF— sigue encontrando lo mismo que esperaba.
     *
     * ⚠️ Solo escribe la fila que YA existe (`updateExistingPivot`): si el articulo no esta atado a
     * esa lista, no se lo ata desde acá. Atar listas es otra decision y tiene su propio camino
     * (`ArticlePriceTypeHelper::attach_price_types`).
     *
     * @param mixed $article Articulo recien recalculado.
     *
     * @return void
     */
    private static function espejar_precio_en_pesos_en_la_pivot($article)
    {
        $ars_id = 1;

        if (PreciosEnLote::esta_activo()) {

            /*
             * Modo lote (misión recalculo-precios-motor-rapido, 28/9/2026): el cálculo de arriba no
             * guardó nada todavía —registró sus entradas en PreciosEnLote, que las escribe al
             * volcar—, así que releer de la base traería los precios VIEJOS. Las entradas en
             * memoria son los mismos objetos que el cálculo acaba de modificar, o sea exactamente
             * lo que save() hubiera escrito: se filtran igual que la consulta de abajo (moneda
             * pesos), en el mismo orden (por id, el de la relación cargada). Ver
             * PreciosEnLote::registrar_moneda() por qué el valor crudo en memoria deja en la
             * pivot el mismo número que el releído.
             */
            $entradas = $article->price_type_monedas->filter(function ($entrada) use ($ars_id) {
                return (int) $entrada->moneda_id === $ars_id;
            });

        } else {

            /* Se relee de la base: el calculo de arriba guardo fila por fila con `save()`, y la
             * coleccion que traia el articulo en memoria puede no reflejar lo ultimo. */
            $entradas = $article->price_type_monedas()
                ->where('moneda_id', $ars_id)
                ->get();
        }

        foreach ($entradas as $entrada) {

            if (is_null($entrada->final_price)) {
                continue;
            }

            /* En modo lote (importación, PreciosEnLote) la fila se lee de la relación cargada y el
             * espejo se registra para escribirlo en bloque; fuera de él, como siempre. Registrarlo
             * es además lo que hace que PriceChangeController::store() vea, en ese modo, el precio
             * en pesos recién espejado y no el que la relación cargada traía de antes. */
            $en_lote = PreciosEnLote::esta_activo();

            $relacion = $en_lote
                            ? PreciosEnLote::pivot_actual($article, $entrada->price_type_id)
                            : $article->price_types()->find($entrada->price_type_id);

            /* Sin fila en la pivot no hay nada que espejar. No se crea: ver el docblock. */
            if (is_null($relacion)) {
                continue;
            }

            $columnas_del_pivot = [
                'final_price' => $entrada->final_price,
                'percentage'  => $entrada->percentage,
            ];

            if ($en_lote) {
                PreciosEnLote::registrar_pivot($article, $entrada->price_type_id, $columnas_del_pivot, false);
                continue;
            }

            $article->price_types()->updateExistingPivot($entrada->price_type_id, $columnas_del_pivot);
        }
    }

    /**
     * Lleva el costo base a la moneda de una entrada: es la cuenta de `$get_cost_for_moneda` de
     * calcular_precios_por_price_type_y_moneda(), sacada a un metodo para que la explicacion del
     * precio (describir_lista_en_moneda()) use exactamente la misma formula que el calculo.
     *
     * - costo cargado en dolares y entrada en pesos: se multiplica por el dolar de la cuenta;
     * - costo cargado en pesos y entrada en dolares: se divide por el dolar de la cuenta (si es > 0);
     * - cualquier otro caso (misma moneda, u otra moneda distinta de pesos/dolares): sin cambio.
     *
     * @param mixed $article   Articulo (se mira `cost_in_dollars`).
     * @param mixed $cost_base Costo base segun la condicion fiscal de la cuenta.
     * @param int   $moneda_id Moneda de la entrada (1 pesos, 2 dolares).
     * @param float $rate      Dolar de la cuenta (`users.dollar`).
     *
     * @return float
     */
    public static function costo_en_moneda($article, $cost_base, $moneda_id, $rate)
    {
        $ars_id = 1;
        $usd_id = 2;

        $cost = (float) $cost_base;

        if ($article->cost_in_dollars && $moneda_id == $ars_id) {
            $cost *= $rate;

        } else if ((int)$article->cost_in_dollars === 0 && $moneda_id == $usd_id) {

            if ($rate > 0) {
                $cost /= $rate;
            }
        }

        return $cost;
    }

    /**
     * Desglose paso a paso del precio de UNA lista en UNA moneda, para el boton "?" de la tarjeta
     * del modal del articulo en una cuenta con `ventas_en_dolares`.
     *
     * En esas cuentas ArticleHelper::setFinalPrice() no pasa por
     * ArticlePricesHelper::aplicar_precios_segun_listas_de_precios() (que es el que describe la
     * lista en las cuentas sin dolares), asi que sin este metodo el "?" abria el calculo del precio
     * final unico y nada de la lista.
     *
     * 🔴 SOLO LEE. Se llama despues de aplicar_precios_por_price_type_y_moneda(), cuando las
     * entradas de `price_type_monedas` ya tienen el porcentaje y el precio finales, y narra de donde
     * salieron. No calcula ni guarda nada: el precio que muestra es el que quedo guardado, y los
     * pasos intermedios usan las mismas cuentas que calc_normal() (costo_en_moneda() y
     * ArticlePricesHelper::aplicar_iva()). Si algun dia se cambia calc_normal(), esto se cambia con el.
     *
     * Las ramas espejan las del calculo:
     *   - la moneda se cotiza desde la otra (y la otra no): precio de la otra x/÷ el dolar de la cuenta;
     *   - precio fijado a mano (`setear_precio_final`): se informa y se deriva el margen resultante;
     *   - normal: costo en esa moneda + margen (+ IVA si corresponde);
     *   - sin costo base: solo se puede explicar la cotizacion cruzada de un precio fijado a mano.
     *
     * @param mixed $article       Articulo con `price_type_monedas` cargada (la misma que el calculo).
     * @param mixed $_cost         Costo base, el mismo que recibio aplicar_precios_por_price_type_y_moneda().
     * @param mixed $user          Dueño de la cuenta.
     * @param int   $price_type_id Lista pedida.
     * @param int   $moneda_id     Moneda pedida.
     *
     * @return array Lineas de DesglosePrecioHelper; vacio si la lista, la moneda o la entrada no existen.
     */
    public static function describir_lista_en_moneda($article, $_cost, $user, $price_type_id, $moneda_id)
    {
        $price_type_id = (int) $price_type_id;
        $moneda_id     = (int) $moneda_id;

        $grupo   = $article->price_type_monedas->where('price_type_id', $price_type_id);
        $entrada = $grupo->firstWhere('moneda_id', $moneda_id);

        // La lista se acota a la cuenta: el id viene de la URL.
        $price_type = PriceType::where('id', $price_type_id)->where('user_id', $user->id)->first();
        $moneda     = Moneda::find($moneda_id);

        if (is_null($entrada) || is_null($price_type) || is_null($moneda)) {
            return [];
        }

        $rate      = (float) $user->dollar;
        $base_cost = (!is_null($_cost) && (float) $_cost > 0) ? (float) $_cost : null;

        $des = [];

        $des[] = DesglosePrecioHelper::seccion(
            DesglosePrecioHelper::CLAVE_LISTA,
            'Precio de la lista '.$price_type->name.' en '.$moneda->name,
            'CALCULO DEL PRECIO DE LA LISTA '.mb_strtoupper($price_type->name, 'UTF-8').' EN '.mb_strtoupper($moneda->name, 'UTF-8')
        );

        // La otra moneda del par pesos/dolares, que es con la que se cotiza.
        $otra = $grupo->first(function ($e) use ($moneda_id) {
            return (int) $e->moneda_id !== $moneda_id && in_array((int) $e->moneda_id, [1, 2]);
        });

        $cotiza      = (int) $entrada->cotizar_desde_otra_moneda === 1;
        $otra_cotiza = !is_null($otra) && (int) $otra->cotizar_desde_otra_moneda === 1;

        if ($cotiza && !is_null($otra) && !$otra_cotiza && in_array($moneda_id, [1, 2])) {

            // Moneda derivada: el precio sale del de la otra moneda, cotizado con el dolar de la cuenta.
            $nombre_otra = Moneda::find($otra->moneda_id);
            $nombre_otra = is_null($nombre_otra) ? 'la otra moneda' : $nombre_otra->name;

            if (!is_null($base_cost)) {

                $des = array_merge($des, self::lineas_de_calculo_normal($article, $user, $otra, $nombre_otra, $base_cost, $rate));

            } else {

                $des[] = DesglosePrecioHelper::linea(
                    DesglosePrecioHelper::PRECIO_MANUAL,
                    'Precio en '.$nombre_otra,
                    'fijado a mano',
                    Numbers::price($otra->final_price, true),
                    'El precio en '.$nombre_otra.' fijado a mano = '.Numbers::price($otra->final_price, true)
                );
            }

            $operacion = $moneda_id === 1 ? 'multiplicado por' : 'dividido por';

            $des[] = DesglosePrecioHelper::linea(
                DesglosePrecioHelper::COTIZACION,
                'Cotizado desde '.$nombre_otra,
                $operacion.' el dólar de la cuenta ('.Numbers::price($rate, true).')',
                Numbers::price($entrada->final_price, true),
                'Cotizado desde '.$nombre_otra.', '.$operacion.' el dolar de la cuenta ('.Numbers::price($rate, true).') = '.Numbers::price($entrada->final_price, true)
            );

            if (!is_null($base_cost)) {

                $des[] = DesglosePrecioHelper::linea(
                    DesglosePrecioHelper::MARGEN,
                    'Margen resultante',
                    'sale de ese precio contra el costo en '.$moneda->name,
                    round((float) $entrada->percentage, 2).'%',
                    'El margen de '.round((float) $entrada->percentage, 2).'% es el que resulta de ese precio contra el costo'
                );
            }

        } else if (!is_null($base_cost)) {

            $des = array_merge($des, self::lineas_de_calculo_normal($article, $user, $entrada, $moneda->name, $base_cost, $rate));

        } else {

            // Sin costo no hay cuenta que mostrar: el calculo no toca esta entrada.
            $des[] = DesglosePrecioHelper::linea(
                DesglosePrecioHelper::NOTA,
                'Sin costo para calcular',
                'el precio de esta moneda queda como está cargado',
                null,
                'Sin costo para calcular: el precio de esta moneda queda como esta cargado'
            );
        }

        $des[] = DesglosePrecioHelper::linea(
            DesglosePrecioHelper::TOTAL,
            'Precio final de la lista',
            'en '.$moneda->name,
            Numbers::price($entrada->final_price, true),
            'Precio final de la lista en '.$moneda->name.' = '.Numbers::price($entrada->final_price, true)
        );

        return $des;
    }

    /**
     * Renglones de una entrada que se calcula "normal": costo en su moneda y despues, segun venga,
     * el precio fijado a mano o el margen mas el IVA. Espeja calc_normal() de
     * calcular_precios_por_price_type_y_moneda(); ver describir_lista_en_moneda().
     *
     * @param mixed  $article       Articulo.
     * @param mixed  $user          Dueño de la cuenta.
     * @param mixed  $entrada       Entrada de `price_type_monedas` ya calculada.
     * @param string $nombre_moneda Nombre de la moneda de la entrada, para los textos.
     * @param float  $base_cost     Costo base (> 0).
     * @param float  $rate          Dolar de la cuenta.
     *
     * @return array Lineas de DesglosePrecioHelper (sin el total: lo agrega quien llama).
     */
    private static function lineas_de_calculo_normal($article, $user, $entrada, $nombre_moneda, $base_cost, $rate)
    {
        $des = [];

        $costo = self::costo_en_moneda($article, $base_cost, (int) $entrada->moneda_id, $rate);

        // Si la cuenta de arriba cambio la moneda del costo, se dice: es lo que explica por que el
        // numero no coincide con el costo real del articulo.
        $detalle_costo = 'el costo real ya calculado arriba';

        if (abs($costo - $base_cost) > 0.0000001) {
            $detalle_costo .= ', llevado a '.$nombre_moneda.' con el dólar de la cuenta ('.Numbers::price($rate, true).')';
        }

        $des[] = DesglosePrecioHelper::linea(
            DesglosePrecioHelper::COSTO,
            'Costo de partida',
            $detalle_costo,
            Numbers::price($costo, true),
            'Costo de partida en '.$nombre_moneda.' = '.Numbers::price($costo, true)
        );

        if ((int) $entrada->setear_precio_final === 1) {

            // Precio cargado a mano: el IVA que corresponda ya esta adentro, el margen se deriva.
            $des[] = DesglosePrecioHelper::linea(
                DesglosePrecioHelper::PRECIO_MANUAL,
                'Precio fijado a mano',
                'lo fijaste vos para esta lista en '.$nombre_moneda,
                Numbers::price($entrada->final_price, true),
                'El precio de esta lista en '.$nombre_moneda.' lo fijaste vos a mano = '.Numbers::price($entrada->final_price, true)
            );

            $des[] = DesglosePrecioHelper::linea(
                DesglosePrecioHelper::PRECIO_MANUAL,
                'Margen resultante',
                'sale de ese precio contra el costo, no al revés',
                round((float) $entrada->percentage, 2).'%',
                'El margen de '.round((float) $entrada->percentage, 2).'% es el que resulta de ese precio contra el costo, no al reves'
            );

            return $des;
        }

        $porcentaje = (float) $entrada->percentage;

        $des[] = DesglosePrecioHelper::linea(
            DesglosePrecioHelper::MARGEN,
            'Margen',
            'cargado en esta lista para '.$nombre_moneda,
            $porcentaje.'%',
            'Margen cargado en esta lista para '.$nombre_moneda.': '.$porcentaje.'%'
        );

        $precio = $costo + ($costo * $porcentaje / 100);

        $des[] = DesglosePrecioHelper::linea(
            DesglosePrecioHelper::MARGEN,
            'Precio con el margen aplicado',
            null,
            Numbers::price($precio, true),
            'Precio con el margen aplicado = '.Numbers::price($precio, true)
        );

        if (!ArticlePricesHelper::iva_va_al_costo($user)) {

            // Mismo criterio que calc_normal(): el IVA se suma DESPUES del margen y solo si no viene
            // ya adentro del costo real. aplicar_iva() emite sus propios renglones.
            $res = ArticlePricesHelper::aplicar_iva($article, $precio, $user, []);
            $des = array_merge($des, $res['des']);

        } else {

            $des[] = DesglosePrecioHelper::linea(
                DesglosePrecioHelper::NOTA,
                'Acá no se suma IVA',
                'esta cuenta tiene prendida la configuración vieja "aplicar IVA al costo", así que el IVA ya viene incluido dentro del costo real',
                null,
                'No se suma IVA aca: esta cuenta tiene la configuracion vieja "aplicar IVA al costo" prendida, asi que el IVA ya viene incluido dentro del costo real'
            );
        }

        return $des;
    }

    /**
     * Guarda una entrada de `price_type_monedas` recién calculada.
     *
     * Fuera del modo lote es el `save()` de siempre. En modo lote (PreciosEnLote: la importación y
     * el recálculo en segundo plano, misión recalculo-precios-motor-rapido, 28/9/2026) se registra
     * lo que ese save() hubiera escrito —columnas sucias más updated_at— y PreciosEnLote::volcar()
     * lo escribe en bloque al final de la tanda: un UPDATE por tanda en vez de uno por entrada.
     *
     * @param mixed $entrada ArticlePriceTypeMoneda del artículo, ya modificada.
     *
     * @return void
     */
    private static function guardar_entrada($entrada)
    {
        if (PreciosEnLote::esta_activo()) {
            PreciosEnLote::registrar_moneda($entrada);
            return;
        }

        $entrada->save();
    }

    /**
     * El calculo propiamente dicho. Era el cuerpo de
     * `aplicar_precios_por_price_type_y_moneda()` y no cambio: se separo para poder espejar en la
     * pivot despues de cualquiera de sus caminos de salida.
     *
     * @param mixed $article Articulo con `price_type_monedas` cargada.
     * @param mixed $_cost   Costo base, o null.
     * @param mixed $user    Dueño de la cuenta.
     *
     * @return void
     */
    private static function calcular_precios_por_price_type_y_moneda($article, $_cost, $user)
    {
        /*
         * Modo lote (PreciosEnLote: la importación y el recálculo en segundo plano, misión
         * recalculo-precios-motor-rapido, 28/9/2026): cada entrada se registra en PreciosEnLote en
         * vez de guardarse (ver guardar_entrada()), y no se loguea por artículo: el Log::info de
         * la colección entera la serializa aunque el nivel del log la descarte, en cada artículo.
         * Fuera del modo lote, todo igual que siempre.
         */
        $en_lote = PreciosEnLote::esta_activo();

        if (!$en_lote) {
            Log::info('entro 2');
        }
        $ars_id = 1;
        $usd_id = 2;

        $rate = (float) $user->dollar;

        // ✅ CAMBIO: si no viene _cost, NO inventamos costo base.
        // Solo permitimos cotización cruzada desde la moneda referencia con final fijo.
        $base_cost = null;

        if (!is_null($_cost) && (float)$_cost > 0) {
            $base_cost = (float) $_cost;
        }

        // La cuenta vive en `costo_en_moneda()`, que tambien usa describir_lista_en_moneda(): una sola
        // formula para el calculo y para su explicacion, que no pueden divergir.
        $get_cost_for_moneda = function ($cost_base, $moneda_id) use ($article, $rate) {

            return self::costo_en_moneda($article, $cost_base, $moneda_id, $rate);
        };

        $calc_normal = function ($entry, $cost_base) use ($get_cost_for_moneda, $article, $user) {

            $moneda_id = (int) $entry->moneda_id;

            $cost = $get_cost_for_moneda($cost_base, $moneda_id);

            $final_price = (float) $entry->final_price;
            $percentage  = (float) $entry->percentage;

            $setear_precio_final = (int) $entry->setear_precio_final;

            if ($setear_precio_final) {

                // Precio cargado a mano (prompt 379/02): el IVA que corresponda ya esta adentro
                // por definicion, igual que en el `else` de setFinalPrice() que respeta el precio
                // manual. No se le aplica IVA ni se recalcula nada mas que el percentage implicito.
                if ($cost > 0) {
                    $percentage = ($final_price - $cost) / $cost * 100;
                } else {
                    $percentage = 0;
                }

            } else {

                $final_price = $cost + ($cost * (float)$percentage / 100);

                // Mismo criterio que ArticlePricesHelper::aplicar_precios_segun_listas_de_precios():
                // el IVA se suma DESPUES del margen de la lista, y solo si iva_va_al_costo($user) da
                // false (para MT, o una cuenta legacy con aplicar_iva_al_costo prendido, el IVA ya
                // viene adentro del costo real y sumarlo aca lo duplicaria).
                if (!ArticlePricesHelper::iva_va_al_costo($user)) {
                    $res = ArticlePricesHelper::aplicar_iva($article, $final_price, $user, []);
                    $final_price = $res['price'];
                }
            }

            return [
                'cost'        => $cost,
                'percentage'  => $percentage,
                'final_price' => $final_price,
            ];
        };

        $groups = $article->price_type_monedas->groupBy('price_type_id');

        if (!$en_lote) {
            Log::info('groups');
            Log::info($groups);
        }

        /**
         * ✅ MODO SIN COSTO: solo cotización cruzada usando final_price fijo de la moneda referencia
         */
        if (is_null($base_cost) || (float)$base_cost <= 0) {

            if ($rate <= 0) {
                return;
            }

            foreach ($groups as $price_type_id => $group) {

                $ars_entry = $group->firstWhere('moneda_id', $ars_id);
                $usd_entry = $group->firstWhere('moneda_id', $usd_id);

                if (!$ars_entry || !$usd_entry) {
                    continue;
                }

                $ars_cotiza = (int)($ars_entry->cotizar_desde_otra_moneda ?? 0);
                $usd_cotiza = (int)($usd_entry->cotizar_desde_otra_moneda ?? 0);

                // Evitar loops
                if ($ars_cotiza && $usd_cotiza) {
                    continue;
                }

                // ARS derivado desde USD (USD referencia debe tener final fijo)
                if ($ars_cotiza) {

                    if ((int)$usd_entry->setear_precio_final === 1 && (float)$usd_entry->final_price > 0) {

                        $ars_final = (float)$usd_entry->final_price * $rate;

                        $ars_entry->final_price = $ars_final;

                        // Sin costo base NO recalculamos percentage
                        self::guardar_entrada($ars_entry);
                        if (!$en_lote) {
                            Log::Info('ENTRO ACA para pesos');
                        }
                    }

                    continue;
                }

                // USD derivado desde ARS (ARS referencia debe tener final fijo)
                if ($usd_cotiza) {

                    if ((int)$ars_entry->setear_precio_final === 1 && (float)$ars_entry->final_price > 0) {

                        $usd_final = (float)$ars_entry->final_price / $rate;

                        $usd_entry->final_price = $usd_final;

                        // Sin costo base NO recalculamos percentage
                        self::guardar_entrada($usd_entry);
                    }

                    continue;
                }
            }

            return;
        }

        /**
         * ✅ MODO CON COSTO: lógica completa (normal + link + recálculo de %)
         */
        foreach ($groups as $price_type_id => $group) {

            $ars_entry = $group->firstWhere('moneda_id', $ars_id);
            $usd_entry = $group->firstWhere('moneda_id', $usd_id);

            $ars_cotiza = $ars_entry ? (int)($ars_entry->cotizar_desde_otra_moneda ?? 0) : 0;
            $usd_cotiza = $usd_entry ? (int)($usd_entry->cotizar_desde_otra_moneda ?? 0) : 0;

            // Si ambos están marcados, fallback a modo normal
            if ($ars_cotiza && $usd_cotiza) {
                foreach ($group as $entry) {
                    $res = $calc_normal($entry, $base_cost);
                    $entry->percentage  = $res['percentage'];
                    $entry->final_price = $res['final_price'];
                    self::guardar_entrada($entry);
                }
                continue;
            }

            // ARS derivado desde USD
            if ($ars_entry && $usd_entry && $ars_cotiza) {

                $usd_res = $calc_normal($usd_entry, $base_cost);

                $usd_entry->percentage  = $usd_res['percentage'];
                $usd_entry->final_price = $usd_res['final_price'];
                self::guardar_entrada($usd_entry);

                $ars_final = $usd_res['final_price'] * $rate;

                $ars_cost = $get_cost_for_moneda($base_cost, $ars_id);

                $ars_percentage = 0;
                if ($ars_cost > 0) {
                    $ars_percentage = ($ars_final - $ars_cost) / $ars_cost * 100;
                }

                $ars_entry->final_price = $ars_final;
                $ars_entry->percentage  = $ars_percentage;
                self::guardar_entrada($ars_entry);

                foreach ($group as $entry) {
                    if ((int)$entry->moneda_id === $ars_id || (int)$entry->moneda_id === $usd_id) continue;
                    $res = $calc_normal($entry, $base_cost);
                    $entry->percentage  = $res['percentage'];
                    $entry->final_price = $res['final_price'];
                    self::guardar_entrada($entry);
                }

                continue;
            }

            // USD derivado desde ARS
            if ($ars_entry && $usd_entry && $usd_cotiza) {

                $ars_res = $calc_normal($ars_entry, $base_cost);

                $ars_entry->percentage  = $ars_res['percentage'];
                $ars_entry->final_price = $ars_res['final_price'];
                self::guardar_entrada($ars_entry);

                $usd_final = $rate > 0 ? ($ars_res['final_price'] / $rate) : 0;

                $usd_cost = $get_cost_for_moneda($base_cost, $usd_id);

                $usd_percentage = 0;
                if ($usd_cost > 0) {
                    $usd_percentage = ($usd_final - $usd_cost) / $usd_cost * 100;
                }

                $usd_entry->final_price = $usd_final;
                $usd_entry->percentage  = $usd_percentage;
                self::guardar_entrada($usd_entry);

                foreach ($group as $entry) {
                    if ((int)$entry->moneda_id === $ars_id || (int)$entry->moneda_id === $usd_id) continue;
                    $res = $calc_normal($entry, $base_cost);
                    $entry->percentage  = $res['percentage'];
                    $entry->final_price = $res['final_price'];
                    self::guardar_entrada($entry);
                }

                continue;
            }

            // Normal sin link
            foreach ($group as $entry) {
                $res = $calc_normal($entry, $base_cost);
                $entry->percentage  = $res['percentage'];
                $entry->final_price = $res['final_price'];
                self::guardar_entrada($entry);
            }
        }
    }
    
    // public static function aplicar_precios_por_price_type_y_moneda($article, $_cost, $user)
    // {

    //     if (
    //         $_cost 
    //         && $_cost > 0
    //     ) {

    //         foreach ($article->price_type_monedas as $price_type_moneda) {

    //             $cost = $_cost;
                
    //             $final_price = $price_type_moneda->final_price;
    //             $percentage = $price_type_moneda->percentage;
    //             $setear_precio_final = $price_type_moneda->setear_precio_final;
    //             $moneda_id = $price_type_moneda->moneda_id;

    //             if (
    //                 $article->cost_in_dollars
    //                 && $moneda_id == 1
    //             ) {
    //                 $cost *= $user->dollar;

    //             } else if (
    //                 $article->cost_in_dollars == 0
    //                 && $moneda_id == 2
    //             ) {

    //                 $cost /= $user->dollar;
    //             }




    //             if ($setear_precio_final) {

    //                 $percentage = ($final_price - $cost) / $cost * 100;

    //             } else {

    //                 // if (!$percentage) {
    //                 //     $percentage = 
    //                 // }

    //                 $final_price = $cost + ($cost * (float)$percentage / 100);

    //             }

    //             $price_type_moneda->percentage = $percentage;
    //             $price_type_moneda->final_price = $final_price;
    //             $price_type_moneda->save();
    //         }

    //     }

        
    // }



    public static function VIEJO_aplicar_precios_por_price_type_y_moneda($article, $user)
    {
        // Traemos las relaciones con la moneda para identificar USD/ARS sin "id mágicos"
        $entries = $article->price_type_monedas;

        if ($entries->isEmpty()) {
            return;
        }

        // Resolver IDs de USD y ARS por código o nombre (ajustá si tu tabla Moneda usa otros campos)
        // Asumimos columnas: monedas.code ('USD'/'ARS') o name ('Dólar','Peso', etc.)
        $usd_id = 2;

        $ars_id = 1;

        // Cotización
        $rate = (!is_null($article->provider) && !is_null($article->provider->dolar) && (float)$article->provider->dolar > 0)
                ? (float)$article->provider->dolar
                : (float)$user->dollar;

        // Agrupamos por price_type_id
        $groups = $entries->groupBy('price_type_id');

        foreach ($groups as $price_type_id => $group) {

            // Buscamos las dos monedas si existen
            $usd_entry = $usd_id ? $group->firstWhere('moneda_id', $usd_id) : null;
            $ars_entry = $ars_id ? $group->firstWhere('moneda_id', $ars_id) : null;

            // Helpers internos
            $calc_from_cost = function ($base_cost, $entry) use ($article, $user) {
                // Columns: percentage, final_price, setear_precio_final
                $final_price = null;
                $percentage  = null;

                if ($entry->setear_precio_final && $entry->final_price !== null && $entry->final_price !== '') {
                    // Usuario fijó precio final → recalculamos percentage
                    $final_price = (float)$entry->final_price;
                    // Costo con IVA para que el % sea consistente con tus otras rutinas
                    $costo_con_iva = ArticlePricesHelper::aplicar_iva($article, $base_cost, $user);
                    if ($costo_con_iva > 0) {
                        $percentage = ($final_price - $costo_con_iva) / $costo_con_iva * 100;
                    } else {
                        $percentage = 0;
                    }
                } else {
                    // Usuario indicó porcentaje → calculamos final
                    $percentage = $entry->percentage !== null && $entry->percentage !== '' ? (float)$entry->percentage : 0;
                    $price_wo_iva = $base_cost + ($base_cost * $percentage / 100);
                    $final_price  = ArticlePricesHelper::aplicar_iva($article, $price_wo_iva, $user);
                }

                return [$percentage, $final_price];
            };

            $save_entry = function ($entry, $percentage, $final_price) {
                $entry->percentage  = $percentage;
                $entry->final_price = $final_price; // <- cambiar por $entry->precio_final si tu columna se llama así
                $entry->save();
            };

            if ($article->cost_in_dollars) {
                // El costo viene en USD

                // 1) USD: aplicar margen o setear final (sobre costo USD)
                if ($usd_entry) {
                    $cost_usd = (float)$article->cost;
                    [$usd_percentage, $usd_final] = $calc_from_cost($cost_usd, $usd_entry);
                    $save_entry($usd_entry, $usd_percentage, $usd_final);

                    // 2) ARS: cotizar desde el final USD → ARS
                    if ($ars_entry && $usd_final !== null) {
                        $ars_final = $usd_final * $rate;
                        // En la consigna pedís “simplemente cotizar”, sin recalcular %,
                        // pero si querés podés recalcular % con respecto al costo ARS con IVA:
                        // $cost_ars = $cost_usd * $rate;
                        // $costo_con_iva_ars = ArticlePricesHelper::aplicar_iva($article, $cost_ars, $user);
                        // $ars_percentage = $costo_con_iva_ars > 0 ? (($ars_final - $costo_con_iva_ars)/$costo_con_iva_ars*100) : 0;
                        $ars_percentage = $ars_entry->percentage; // mantenemos lo que tenga o null
                        $save_entry($ars_entry, $ars_percentage, $ars_final);
                    }
                } else {
                    // Si no hay USD pero sí ARS, calculamos “normal” sobre ARS partiendo de costo*rate
                    if ($ars_entry) {
                        $cost_ars = (float)$article->cost * $rate;
                        [$ars_percentage, $ars_final] = $calc_from_cost($cost_ars, $ars_entry);
                        $save_entry($ars_entry, $ars_percentage, $ars_final);
                    }
                }

            } else {
                // El costo viene en ARS

                // 1) ARS: aplicar margen o setear final (sobre costo ARS)
                if ($ars_entry) {
                    $cost_ars = (float)$article->cost;
                    [$ars_percentage, $ars_final] = $calc_from_cost($cost_ars, $ars_entry);
                    $save_entry($ars_entry, $ars_percentage, $ars_final);

                    // 2) USD: convertir desde final ARS → USD
                    if ($usd_entry && $ars_final !== null && $rate > 0) {
                        $usd_final = $ars_final / $rate;
                        $usd_percentage = $usd_entry->percentage; // mantenemos lo que tenga o null
                        $save_entry($usd_entry, $usd_percentage, $usd_final);
                    }
                } else {
                    // Si no hay ARS pero sí USD, calculamos “normal” sobre USD partiendo de costo/rate
                    if ($usd_entry && $rate > 0) {
                        $cost_usd = (float)$article->cost / $rate;
                        [$usd_percentage, $usd_final] = $calc_from_cost($cost_usd, $usd_entry);
                        $save_entry($usd_entry, $usd_percentage, $usd_final);
                    }
                }
            }

        }
    }



    // static function aplicar_precios_por_price_type_y_moneda($article, $user) {

    //     $entries = $article->price_type_monedas; // colección pivot como PivotModel

    //     foreach ($entries as $entry) {

    //         $cost = $article->cost;

    //         if (
    //             $article->cost_in_dollars
    //             && $entry->moneda_id == 1
    //         ) {
    //             $rate = (!is_null($article->provider) && $article->provider->dolar)
    //                   ? $article->provider->dolar
    //                   : $user->dollar;
    //             $cost = $cost * $rate;
    //         }

    //         $final_price = null;

    //         if ($entry->setear_precio_final && !is_null($entry->precio_final)) {
    //             $final_price = $entry->precio_final;
    //             $percentage = ($final_price - $cost) / $cost * 100;
    //         } elseif (!is_null($entry->percentage)) {
    //             $percentage = $entry->percentage;
    //             $price_wo_iva = $cost + ($cost * $percentage / 100);
    //             $final_price = ArticlePricesHelper::aplicar_iva($article, $price_wo_iva, $user);
    //         } else {
    //             continue;
    //         }

    //         $entry->percentage = $percentage;
    //         $entry->final_price = $final_price;
    //         $entry->save();
    //     }
    // }

}