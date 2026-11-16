<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\PromocionVinoteca;

/**
 * Las promos de vinoteca como renglón de una venta del asistente (misión asistente-mejoras-4-dias,
 * 16/11/2026, rama A, P3 — hallazgos V7 y V19).
 *
 * 🔴 EL CASO REAL (Truvari, 13/11/2026, conversaciones 22 y 26). «Llevó una caja del Mix Blend. Pagó en
 * efectivo» → «No tengo una herramienta para cargar la venta de una promo de vinoteca». El dueño explicó
 * tres veces cómo se hace en Vender (el buscador «Promocion» del remito, una unidad por promo) y terminó
 * pidiéndolo a soporte. Antes, «MIX CLASICO Nº1» no aparecía porque la búsqueda solo miraba artículos.
 *
 * 🔴 EL RENGLÓN ES EL MISMO QUE ARMA VENDER, Y LO PROCESA EL MISMO store(). En la SPA la promo entra por
 * `PromocionVinoteca.vue` → `set_item_vender({...promo, is_promocion_vinoteca: true})`; su precio sale de
 * `getPriceVender()` por la rama normal (el `final_price` de la promo: `aplicar_tipos_de_precio` solo toca
 * artículos), con el descuento del método de pago renglón por renglón como cualquier ítem; y al guardar,
 * `SaleHelper::attachPromocionVinotecas()` la cuelga de la venta y
 * `sale\PromocionVinotecaHelper::discount_stock_promocion_vinoteca()` le descuenta el stock A LA PROMO
 * (las botellas ya salieron del stock de sus artículos cuando la promo se armó: PromocionVinotecaHelper
 * ::attach_articles). Acá no se descuenta nada a mano ni se calcula otra cosa: el renglón viaja con
 * `is_promocion_vinoteca` en el mismo POST de `PropuestaVentaIaHelper::payload()` y el resto lo hace
 * SaleController::store(), exactamente como desde la pantalla.
 *
 * 1 unidad = 1 promo, como dijo el dueño: «llevo dos mix tanto, ponés dos».
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class PromoDeVinotecaEnVentaIaHelper
{
    /** El módulo que tiene el botón Promos y el buscador «Promocion» del remito. */
    const EXTENSION = 'vinoteca';

    /** Tope de candidatos cuando un nombre es ambiguo. */
    const TOPE_CANDIDATOS = 10;

    /**
     * Separa los ítems de proponer_venta en [artículos, promos]. Un ítem es una promo si trae `promo` o
     * `promo_id`, o si trae un `articulo` por nombre que no encaja con ningún artículo activo y sí con
     * una promo (el dueño les dice «el Mix Blend», no «la promo Mix Blend»). Sin el módulo Vinoteca no
     * hay promos: todo queda como vino.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $items
     * @return array{0: mixed, 1: array}
     */
    public static function separar(ContextoDeCargaIa $contexto, $items)
    {
        if (!is_array($items) || !count($items) || !self::tiene_promos($contexto)) {
            return [$items, []];
        }

        $articulos = [];
        $promos = [];

        foreach (array_values($items) as $item) {

            $item = is_array($item) ? $item : [];

            $promo_id = EntradaDeCargaIa::valor($item, 'promo_id');
            $promo = EntradaDeCargaIa::texto($item, 'promo');

            if ((!EntradaDeCargaIa::vacio($promo_id) && is_numeric($promo_id)) || $promo !== '') {
                $promos[] = $item;
                continue;
            }

            $texto = EntradaDeCargaIa::texto($item, 'articulo');
            $articulo_id = EntradaDeCargaIa::valor($item, 'articulo_id');

            if ($texto !== '' && EntradaDeCargaIa::vacio($articulo_id) && !self::hay_articulo($contexto, $texto) && self::hay_promo($contexto, $texto)) {
                $item['promo'] = $texto;
                unset($item['articulo']);
                $promos[] = $item;
                continue;
            }

            $articulos[] = $item;
        }

        return [$articulos, $promos];
    }

    /**
     * true si el negocio vende promos de vinoteca.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @return bool
     */
    public static function tiene_promos(ContextoDeCargaIa $contexto)
    {
        return !is_null($contexto->owner) && UserHelper::hasExtencion(self::EXTENSION, $contexto->owner);
    }

    /**
     * Los renglones de promo: cada ítem resuelto a una promo del dueño, con su cantidad (entera) y su
     * precio unitario. Un ítem ambiguo se anota en $faltan (con sus opciones) y se sigue; uno que no
     * existe o una cantidad imposible corta.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $items
     * @param  float|null  $porcentaje_del_metodo  Positivo descuenta, negativo recarga, null nada.
     * @param  array  $faltan
     * @param  array  $opciones
     * @return array  Renglones, o la respuesta negativa.
     */
    public static function resolver(ContextoDeCargaIa $contexto, array $items, $porcentaje_del_metodo, array &$faltan, array &$opciones)
    {
        $renglones = [];

        foreach ($items as $item) {

            $cantidad = EntradaDeCargaIa::valor($item, 'cantidad');

            if (EntradaDeCargaIa::vacio($cantidad)) {
                $cantidad = 1;
            }

            /* El pivot y el descuento de stock de la promo son enteros (`(int) $promo['amount']`). */
            if (!is_numeric($cantidad) || (float) $cantidad <= 0 || (float) $cantidad != floor((float) $cantidad)) {
                return RespuestaDeCargaIa::error('La cantidad de una promo tiene que ser un número entero mayor a 0: una unidad es una promo.');
            }

            $cantidad = (int) $cantidad;

            $promo = self::resolver_promo($contexto, $item);

            if (RespuestaDeCargaIa::es_negativa($promo)) {

                if (!count($promo['faltan'])) {
                    return $promo;
                }

                foreach ($promo['faltan'] as $falta) {
                    $faltan[] = $falta;
                }

                foreach ($promo['opciones'] as $clave => $lista) {
                    $opciones[$clave] = isset($opciones[$clave]) && is_array($opciones[$clave]) ? array_merge($opciones[$clave], $lista) : $lista;
                }

                continue;
            }

            $dictado = EntradaDeCargaIa::valor($item, 'precio_unitario');
            $precio_dictado = null;

            if (!EntradaDeCargaIa::vacio($dictado)) {

                if (!is_numeric($dictado) || (float) $dictado <= 0) {
                    return RespuestaDeCargaIa::error('El precio unitario de ' . $promo->name . ' tiene que ser un número mayor a 0.');
                }

                $precio_dictado = round((float) $dictado, 2);
            }

            if (!is_null($precio_dictado)) {

                $precio = $precio_dictado;
                $origen = 'dictado';

            } else {

                if (is_null($promo->final_price) || (float) $promo->final_price <= 0) {
                    return RespuestaDeCargaIa::error(
                        'La promo ' . $promo->name . ' no tiene precio cargado. Decime a cuánto la vendés (precio_unitario) o cargale el precio desde Promos.'
                    );
                }

                // Lo mismo que getPriceVender() para un ítem que no es artículo: el final_price, con el método de pago.
                $precio = (float) $promo->final_price;

                if (!is_null($porcentaje_del_metodo) && (float) $porcentaje_del_metodo != 0) {
                    $precio = $precio - $precio * (float) $porcentaje_del_metodo / 100;
                }

                $precio = round($precio, 2);
                $origen = 'final_price';
            }

            $id = (int) $promo->id;

            if (isset($renglones[$id])) {

                $renglones[$id]['cantidad'] += $cantidad;

                if (!is_null($precio_dictado)) {
                    $renglones[$id]['precio'] = $precio;
                    $renglones[$id]['precio_dictado'] = $precio_dictado;
                    $renglones[$id]['origen'] = $origen;
                }

                continue;
            }

            $renglones[$id] = [
                'promo'          => $promo,
                'cantidad'       => $cantidad,
                'precio'         => $precio,
                'precio_dictado' => $precio_dictado,
                'origen'         => $origen,
            ];
        }

        return array_values($renglones);
    }

    /**
     * Una promo del dueño a partir de un ítem: por `promo_id` o por `promo` (texto). Exacta por nombre
     * gana; por palabras, una sola se toma; varias se preguntan; ninguna por LIKE busca parecidas por
     * sonido (NombreParecidoIaHelper) y las PREGUNTA, aunque sea una.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $item
     * @return \App\Models\PromocionVinoteca|array
     */
    public static function resolver_promo(ContextoDeCargaIa $contexto, array $item)
    {
        $base = PromocionVinoteca::where('user_id', $contexto->owner_id);

        $promo_id = EntradaDeCargaIa::valor($item, 'promo_id');

        if (!EntradaDeCargaIa::vacio($promo_id) && is_numeric($promo_id) && (int) $promo_id > 0) {

            $promo = (clone $base)->where('id', (int) $promo_id)->first();

            if (is_null($promo)) {
                return RespuestaDeCargaIa::error('Esa promo no existe entre las tuyas. Buscala por nombre.');
            }

            return $promo;
        }

        $texto = EntradaDeCargaIa::texto($item, 'promo');

        if ($texto === '') {
            return RespuestaDeCargaIa::faltan(['qué promo es']);
        }

        $candidatos = (clone $base)
            ->where(function ($sub) use ($texto) {
                BusquedaPorPalabrasIaHelper::filtrar($sub, $texto, ['name']);
            })
            ->orderBy('name')
            ->limit(self::TOPE_CANDIDATOS + 1)
            ->get();

        if (count($candidatos) === 0) {

            $parecidas = NombreParecidoIaHelper::candidatos($texto, (clone $base)->pluck('name', 'id')->all());

            if (!count($parecidas)) {
                return RespuestaDeCargaIa::error('No encontré ninguna promo ni ningún artículo que se llame "' . $texto . '". Buscalo con otra palabra.');
            }

            return RespuestaDeCargaIa::faltan(
                ['si la promo "' . $texto . '" es alguna de estas (los nombres se parecen; no elijo sola)'],
                ['promos' => self::opciones($parecidas, true)]
            );
        }

        $normalizado = NombreParecidoIaHelper::fonetico($texto);

        foreach ($candidatos as $candidato) {
            if (NombreParecidoIaHelper::fonetico((string) $candidato->name) === $normalizado) {
                return $candidato;
            }
        }

        $con_la_frase = BusquedaPorPalabrasIaHelper::por_frase($candidatos, $texto, ['name']);

        if (count($con_la_frase) === 1) {
            return $con_la_frase[0];
        }

        if (count($candidatos) === 1) {
            return $candidatos[0];
        }

        $filas = [];

        foreach ($candidatos->take(self::TOPE_CANDIDATOS) as $candidato) {
            $filas[] = ['id' => (int) $candidato->id, 'nombre' => (string) $candidato->name];
        }

        return RespuestaDeCargaIa::faltan(['cuál de estas promos es "' . $texto . '"'], ['promos' => self::opciones($filas, false)]);
    }

    /**
     * true si algún artículo activo del dueño encaja con el texto (la misma búsqueda que la venta).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $texto
     * @return bool
     */
    protected static function hay_articulo(ContextoDeCargaIa $contexto, $texto)
    {
        return Article::where('user_id', $contexto->owner_id)
            ->where('status', 'active')
            ->where(function ($sub) use ($texto) {
                BusquedaPorPalabrasIaHelper::filtrar($sub, $texto, ['name', 'bar_code', 'provider_code']);
            })
            ->exists();
    }

    /**
     * true si alguna promo del dueño encaja con el texto (por palabras o por sonido).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $texto
     * @return bool
     */
    protected static function hay_promo(ContextoDeCargaIa $contexto, $texto)
    {
        $base = PromocionVinoteca::where('user_id', $contexto->owner_id);

        $por_palabras = (clone $base)->where(function ($sub) use ($texto) {
            BusquedaPorPalabrasIaHelper::filtrar($sub, $texto, ['name']);
        })->exists();

        if ($por_palabras) {
            return true;
        }

        return count(NombreParecidoIaHelper::candidatos($texto, (clone $base)->pluck('name', 'id')->all(), 1)) > 0;
    }

    /**
     * Las opciones de un `faltan` de promos, con su precio y su stock.
     *
     * @param  array<int, array{id: int, nombre: string}>  $filas
     * @param  bool  $parecidas
     * @return array<int, array<string, mixed>>
     */
    protected static function opciones(array $filas, $parecidas)
    {
        $ids = array_map(function ($fila) {
            return (int) $fila['id'];
        }, $filas);

        $promos = PromocionVinoteca::whereIn('id', $ids)->get()->keyBy('id');

        $opciones = [];

        foreach ($filas as $fila) {

            $promo = $promos->get((int) $fila['id']);

            $opcion = [
                'promo_id' => (int) $fila['id'],
                'nombre'   => (string) $fila['nombre'],
                'precio'   => is_null($promo) || is_null($promo->final_price) ? null : round((float) $promo->final_price, 2),
                'stock'    => is_null($promo) || is_null($promo->stock) ? null : (int) $promo->stock,
            ];

            if ($parecidas) {
                $opcion['parecida'] = true;
            }

            $opciones[] = $opcion;
        }

        return $opciones;
    }

    /**
     * El renglón de `items` del POST de api/sale para una promo: las claves que lee
     * SaleHelper::attachPromocionVinotecas (`is_promocion_vinoteca`, `id`, `amount`, `price_vender`) y las
     * que la SPA manda del mismo modelo.
     *
     * @param  array  $renglon
     * @return array<string, mixed>
     */
    public static function item_del_payload(array $renglon)
    {
        $promo = $renglon['promo'];

        return [
            'id'                         => (int) $promo->id,
            'name'                       => (string) $promo->name,
            'is_promocion_vinoteca'      => true,
            'amount'                     => (int) $renglon['cantidad'],
            'price_vender'               => (float) $renglon['precio'],
            'price_vender_personalizado' => is_null($renglon['precio_dictado']) ? null : (float) $renglon['precio_dictado'],
            'final_price'                => is_null($promo->final_price) ? null : (float) $promo->final_price,
            'cost'                       => $promo->cost,
            'stock'                      => $promo->stock,
        ];
    }

    /**
     * «1 × Promo Mix Blend — $ 87.000 = $ 87.000».
     *
     * @param  array  $renglon
     * @return string
     */
    public static function renglon_de_promo(array $renglon)
    {
        $subtotal = round((float) $renglon['precio'] * (float) $renglon['cantidad'], 2);

        return (int) $renglon['cantidad'] . ' × Promo ' . $renglon['promo']->name
            . ' — ' . FormatoIaHelper::monto($renglon['precio'])
            . ' = ' . FormatoIaHelper::monto($subtotal);
    }

    /**
     * El aviso de stock de las promos (las que quedan en negativo), o null. Mismo criterio que
     * PropuestaVentaIaHelper::revisar_stock() con los artículos: una promo sin stock cargado no lo maneja.
     *
     * @param  array  $renglones
     * @return array<int, string>
     */
    public static function en_negativo(array $renglones)
    {
        $en_negativo = [];

        foreach ($renglones as $renglon) {

            $promo = $renglon['promo'];

            if (is_null($promo->stock)) {
                continue;
            }

            $queda = (int) $promo->stock - (int) $renglon['cantidad'];

            if ($queda < 0) {
                $en_negativo[] = 'la promo ' . $promo->name . ' (hay ' . (int) $promo->stock . ', quedaría en ' . $queda . ')';
            }
        }

        return $en_negativo;
    }
}
