<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\DesglosePrecioHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Models\ArticleDiscount;
use App\Models\ArticleDiscountBlanco;
use App\Models\ArticleSurchage;
use App\Models\ArticleSurchageBlanco;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\CurrentAcountPaymentMethodDiscount;
use App\Models\Cuota;
use App\Models\PriceType;
use App\Models\SaleTax;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ArticlePricesHelper {

    /**
     * Cache en memoria (por request/job) de los sale_taxes activos de cada usuario, para evitar
     * repetir la misma consulta cuando este helper se llama en loop masivo sobre muchos artículos
     * (ej. recalculo global de precios). Se indexa por user_id.
     *
     * @var array<int, \Illuminate\Support\Collection>
     */
    static $sale_taxes_cache = [];

    /**
     * Cache en memoria (por request/job) de la configuracion de recargo por tarjeta de cada usuario
     * (Capa 3, Prompt 263), para no repetir la consulta de reglas por cada articulo cuando se listan
     * o buscan muchos articulos en un mismo request (ej. busqueda en Vender). Se indexa por user_id.
     * Valor `null` = el flag `precio_base_incluye_tarjeta` esta apagado o no hay reglas de recargo
     * configuradas (el helper no tiene efecto para ese usuario).
     *
     * @var array<int, array{multiplicador_max: float, metodos: \Illuminate\Support\Collection}|null>
     */
    static $payment_method_layer3_cache = [];

    /**
     * EL punto unico donde se decide cuanto sale un articulo. Tarea 7, 11/8/2026.
     *
     * Por que existe: en una cuenta con listas de precio, articles.final_price es un numero que
     * NO se le cobra a nadie. setFinalPrice() le pasa a las listas la base de
     * calcular_base_antes_de_listas() (costo real + margen general del usuario) y recien despues
     * aplica el margen del proveedor, el de la categoria y el percentage_gain del articulo, que
     * van SOLO al final_price unico -- el comentario de setFinalPrice lo dice con todas las
     * letras. Cada venta de esas cuentas sale por alguna lista, asi que mostrar el final_price
     * como "el precio" es mostrar un numero que nadie paga.
     *
     * 🔴 Ningun consumidor puede reimplementar esta cascada. Repetirla es exactamente como se
     * desincronizaron los cuatro caminos de precios que arreglan los grupos 379 y 382: cada copia
     * envejece por su lado y nadie se entera hasta que un cliente ve dos precios distintos para
     * el mismo articulo en dos pantallas.
     *
     * La cascada:
     *   1. La cuenta no usa listas          -> articles.final_price, igual que siempre.
     *   2. Viene un price_type_id explicito -> el final_price del pivote de esa lista.
     *   3. Hay un cliente con lista         -> el llamador pasa su price_type_id (caso 2).
     *   4. No hay ninguna                   -> la lista por defecto de la cuenta.
     *
     * SOBRE EL PUNTO 4, que es una decision de producto: en price_types NO existe ninguna columna
     * de "lista por defecto" (mirar la migracion: hay num, name, percentage, position,
     * ocultar_al_publico, incluir_en_lista_de_precios_de_excel, setear_precio_final,
     * se_usa_en_tienda_nube). Pero el criterio SI existe en el sistema, implementado en el front:
     * empresa-spa/src/mixins/vender/price_types.js elige, cuando no hay presupuesto ni cliente con
     * lista, la de POSITION MAS ALTA. Este resolvedor usa el mismo criterio a proposito. Si eligiera
     * otro, el front mostraria un precio y el back calcularia otro, que es el problema que esta
     * misma funcion viene a cerrar. Queda anotado como decision pendiente: si algun dia se agrega
     * una columna de lista por defecto, se cambia ACA y en price_types.js, y en ningun otro lado.
     *
     * @param mixed $article Articulo con la relacion price_types cargada (o cargable).
     * @param mixed $user Dueño de la cuenta, para saber si usa listas.
     * @param int|null $price_type_id Lista explicita (la del cliente, la del presupuesto, la elegida).
     * @return array {
     *     @var float|null $final_price Precio resuelto.
     *     @var int|null $price_type_id Lista de la que salio, null si salio del final_price unico.
     *     @var string|null $price_type_name Nombre de esa lista, para mostrarlo sin volver a buscarlo.
     *     @var string $origen 'final_price' | 'lista' | 'lista_por_defecto'
     * }
     */
    static function resolver_precio_de_venta($article, $user, $price_type_id = null)
    {
        $desde_el_articulo = [
            'final_price'     => is_null($article) ? null : $article->final_price,
            'price_type_id'   => null,
            'price_type_name' => null,
            'origen'          => 'final_price',
        ];

        if (is_null($article)) {
            return $desde_el_articulo;
        }

        /**
         * Rama 1: la mayoria de las cuentas. Sin listas no hay nada que resolver y este helper
         * tiene que ser transparente: mismo numero que antes de la tarea 7.
         */
        if (!UserHelper::uses_listas_de_precio($user)) {
            return $desde_el_articulo;
        }

        $price_types = $article->price_types;

        if (is_null($price_types) || count($price_types) === 0) {
            /**
             * Cuenta con listas pero articulo sin pivotes: pasa con articulos creados antes de
             * activar las listas, o mientras el recalculo global todavia no llego. Cae al
             * final_price, que es lo unico que hay, en vez de devolver null y dejar la pantalla
             * o el Excel con un precio vacio.
             */
            return $desde_el_articulo;
        }

        $elegida = null;

        /**
         * Rama 2 (y 3, que llega hasta aca con el price_type_id del cliente ya resuelto por el
         * llamador: el resolvedor no conoce clientes a proposito, para no atarse a Vender).
         */
        if (!is_null($price_type_id) && (int) $price_type_id > 0) {
            foreach ($price_types as $price_type) {
                if ((int) $price_type->id === (int) $price_type_id) {
                    $elegida = $price_type;
                    break;
                }
            }
        }

        $origen = 'lista';

        /**
         * Rama 4: la lista por defecto. Ver el bloque grande de arriba sobre por que es la de
         * position mas alta y no otra cosa.
         */
        if (is_null($elegida)) {
            $origen = 'lista_por_defecto';
            $mayor_position = null;

            foreach ($price_types as $price_type) {
                $position = is_null($price_type->position) ? 0 : (int) $price_type->position;

                if (is_null($mayor_position) || $position > $mayor_position) {
                    $mayor_position = $position;
                    $elegida = $price_type;
                    continue;
                }

                /**
                 * Desempate por id mas alto. price_types no tiene indice unico en
                 * (user_id, position), asi que dos listas pueden compartir position; sin este
                 * desempate el precio dependeria del orden en que Eloquent devolvio la relacion,
                 * que ningun orderBy fija. Un precio que cambia entre dos corridas identicas es
                 * lo mas caro de diagnosticar que hay.
                 */
                if ($position === $mayor_position
                    && !is_null($elegida)
                    && (int) $price_type->id > (int) $elegida->id) {

                    $elegida = $price_type;
                }
            }
        }

        if (is_null($elegida)
            || is_null($elegida->pivot)
            || is_null($elegida->pivot->final_price)) {
            /**
             * La lista existe pero su pivote no tiene precio calculado todavia. Mismo criterio
             * que arriba: mejor el final_price del articulo que un precio vacio.
             */
            return $desde_el_articulo;
        }

        return [
            'final_price'     => $elegida->pivot->final_price,
            'price_type_id'   => $elegida->id,
            'price_type_name' => $elegida->name,
            'origen'          => $origen,
        ];
    }

    static function aplicar_category_percentage_gain($article, $price, $des) {

        if (
            $article->category
            && $article->category->percentage_gain
        ) {
            // Log::info('La categoria tiene margen de ganancia del '.$article->category->percentage_gain);
            // Log::info('price: '.$price);
            $price += $price * $article->category->percentage_gain / 100;
            $des[] = DesglosePrecioHelper::linea(
                DesglosePrecioHelper::MARGEN,
                'Margen de la categoría',
                $article->category->percentage_gain.'%',
                Numbers::price($price, true),
                'Mas margen de la categoria del '.$article->category->percentage_gain.'% = '.Numbers::price($price, true)
            );
            // Log::info('price luego: '.$price);
        } else {
            // Log::info('La categoria NO tiene margen de ganancia');
        }
        return [
            'price' => $price,
            'des'   => $des,
        ];
    }


    // Extencion de golo norte
    static function aplicar_precios_segun_listas_de_precios_y_categorias($article, $cost, $user) {

        $price_types = null;

        $sub_category = $article->sub_category;
        $category = $article->category;

        // Priorizar los tipos de precios de la subcategoría si existen y tienen porcentaje válido
        if (!is_null($sub_category)) {
            $price_types = $sub_category->price_types()
                ->whereNotNull('price_type_sub_category.percentage') // Asegurar que el porcentaje no sea nulo
                ->where('price_type_sub_category.percentage', '!=', '') // Asegurar que no sea un string vacío
                ->get();
        }

        // Si no hay tipos de precios válidos en la subcategoría, buscar en la categoría
        if (is_null($price_types) || $price_types->isEmpty()) {
            if (!is_null($category)) {
                $price_types = $category->price_types()
                    // ->withPivot('percentage')
                    ->get();
                
                // Log::info('price_types de '.$category->name);
                // Log::info($price_types);
            }
        }



        if (!is_null($price_types)) {

            Log::info('Va a usar price types de la categoria');

            // Recorrer cada tipo de precio para calcular el precio final
            foreach ($price_types as $price_type) {

                $percentage = $price_type->pivot->percentage; // Porcentaje de ganancia

                if ($percentage) {

                    // Calcular el precio final

                    $price = $cost + ($cost * $percentage / 100);

                    // Capa 2 (Prompt 261): sale_taxes con formula de division, despues del margen
                    // y antes del IVA de venta.
                    $res = Self::aplicar_sale_taxes($article, $price, $user, []);
                    $price = $res['price'];

                    // Prompt 379/03: mismo criterio y mismo lugar que el camino principal
                    // (aplicar_precios_segun_listas_de_precios(), mas abajo en este archivo): el IVA
                    // se suma despues del margen y de sale_taxes, solo si iva_va_al_costo($user) da
                    // false. Antes esta llamada estaba comentada y las listas de esta extension
                    // salian netas para una cuenta Responsable Inscripto, mientras el camino
                    // principal si les sumaba el IVA -- dos precios de lista distintos para la misma
                    // cuenta segun por que extension pasara el articulo.
                    if (!Self::iva_va_al_costo($user)) {

                        $res = Self::aplicar_iva($article, $price, $user, []);
                        $price = $res['price'];
                    }

                    $article->price_types()->syncWithoutDetaching($price_type->id);

                    // Prompt 379/03: se saca el redondeo (antes ArticleHelper::redondear() aca). El
                    // camino principal no redondea el precio de la lista en ningun punto -- el
                    // redondeo es del precio FINAL del articulo y lo hace setFinalPrice() (llama a
                    // Self::redondear() al final de su propio pipeline). Redondear aca ademas hacia
                    // que dos cuentas con la misma configuracion de redondeo obtuvieran precios de
                    // lista distintos segun por que camino (categorias o principal) pasara el articulo.
                    $final_price = $price;

                    // Prompt 379/03: mismas dos columnas que persiste el camino principal
                    // (precio_luego_de_recargos y monto_ganancia), para que el desglose del boton "?"
                    // de la tarjeta de esta lista en el modal del articulo (grupo 357) tambien
                    // funcione para las cuentas con esta extension -- antes quedaba incompleto.
                    // $article y $user van para que monto_ganancia salga neto de IVA y de impuestos
                    // sobre ventas, igual que en el camino principal (tarea 9).
                    $res = Self::aplicar_price_type_surchages($price_type, $final_price, $cost, null, $article, $user);

                    $article->price_types()->updateExistingPivot($price_type->id, [
                        'percentage'                => $percentage,
                        'price'                     => $price,
                        'final_price'               => $final_price,
                        'precio_luego_de_recargos'  => $res['precio_luego_de_recargos'],
                        'monto_ganancia'            => $res['monto_ganancia'],
                    ]);
                } else {

                    // Categoria/subcategoria sin porcentaje: las cinco columnas en null, sin restos
                    // de un calculo anterior (un precio_luego_de_recargos viejo junto a un final_price
                    // en null seria peor que ninguno de los dos).
                    $article->price_types()->updateExistingPivot($price_type->id, [
                        'percentage'                => null,
                        'price'                     => null,
                        'final_price'               => null,
                        'precio_luego_de_recargos'  => null,
                        'monto_ganancia'            => null,
                    ]);
                }

            }
        }
        
    }

    /**
     * Calcula y persiste el precio de cada lista de precio del usuario para este articulo.
     *
     * @param $price_type_id_para_descripcion int|null Si viene cargado, ademas de calcular se arma
     *        el desglose paso a paso de ESA lista y se devuelve como array de strings. Es lo que
     *        alimenta el boton "?" de cada tarjeta de lista en el modal del articulo (prompt 357/01).
     *        Con null se devuelve un array vacio y el comportamiento es identico al historico: este
     *        parametro NO toca el calculo, solo describe lo que ya pasa.
     * @return array Lineas del desglose de la lista pedida. Vacio si no se pidio ninguna.
     */
    static function aplicar_precios_segun_listas_de_precios($article, $cost, $user, $price_types = null, $price_type_id_para_descripcion = null) {

        $des_lista = [];

        if (is_null($price_types)) {
            $price_types = PriceType::where('user_id', $user->id)
                                    ->orderBy('position', 'ASC')
                                    ->get();
        }
                                
        // Log::info('aplicar_precios_segun_listas_de_precios, price_types: '.count($price_types));

        foreach ($price_types as $price_type) {

            $percentage = $price_type->percentage;

            $final_price = null;

            $relation = $article->price_types()->find($price_type->id);

            $previus_final_price = null;

            // Solo se describe la lista que pidio el front. El resto del loop corre igual que siempre.
            $describir = !is_null($price_type_id_para_descripcion)
                        && $price_type->id == $price_type_id_para_descripcion;

            if ($describir) {
                // El front ya NO decide por mayusculas cual renglon es titulo. Esa heuristica
                // (PriceDescription.vue comparando des === des.toUpperCase()) fallaba con las listas
                // de nombre acentuado: 'LISTA PúBLICO' no es igual a su propio toUpperCase() en JS, y
                // el encabezado se pintaba como un renglon mas del desglose (hallazgo
                // 20260805-desglose-por-lista-margen-propio-y-acentos, punto 2). Ahora la
                // clasificacion viene por `tipo`: esta entrada es una SECCION y lo dice explicito,
                // sin que nadie tenga que adivinarlo del texto.
                //
                // El mb_strtoupper se conserva UNICAMENTE porque `texto` tiene que quedar identico al
                // string historico, caracter por caracter: de ahi sale la clave `description` que
                // siguen leyendo el front viejo y los desgloses ya guardados en base. La `etiqueta`,
                // que es lo que se muestra, va con el nombre de la lista tal cual lo escribio la
                // persona.
                $des_lista[] = DesglosePrecioHelper::seccion(
                    DesglosePrecioHelper::CLAVE_LISTA,
                    'Precio de la lista '.$price_type->name,
                    'CALCULO DEL PRECIO DE LA LISTA '.mb_strtoupper($price_type->name, 'UTF-8')
                );
            }


            /* 
                Calculo el precio final, o el porcentaje, en base a la relacion ya existente
            */
            if (!is_null($relation)) {

                if ($relation->pivot->setear_precio_final) {

                    if (!is_null($relation->pivot->final_price)) {

                        $final_price = $relation->pivot->final_price;
                    }

                } else {

                    if (!is_null($relation->pivot->percentage)) {

                        $percentage = $relation->pivot->percentage;
                    }

                    if ($previus_final_price != $relation->pivot->final_price) {
                        $previus_final_price = $relation->pivot->final_price;
                    }
                }
            }



            /*
                Si esta seteado el precio final, calculo el procentaje que deberia de tener para 
                llegar a ese precio final

                Sino, calculo el precio final en base al porcentaje
            */

            $cost = (float) $cost;

            // Normalizo porcentaje por si viene null/string
            $percentage = is_null($percentage) ? 0 : (float) $percentage;

            if ($describir) {
                $des_lista[] = DesglosePrecioHelper::linea(
                    DesglosePrecioHelper::COSTO,
                    'Costo de partida',
                    'el costo real ya calculado arriba',
                    Numbers::price($cost, true),
                    'Costo de partida (costo real ya calculado arriba) = '.Numbers::price($cost, true)
                );
            }

            if ($cost !== 0.0) {

                if (!is_null($final_price)) {

                    /**
                     * El precio lo fijo una persona a mano, asi que el margen se deriva de el. Pero
                     * hay que compararlo contra el costo en la MISMA magnitud: el precio final tiene
                     * IVA e impuestos sobre ventas adentro y el costo no. Restarlos asi nomas daba
                     * un numero que no significa nada (con costo 1000, IVA 21% y precio 3000: 200%,
                     * cuando el margen real es 147,93%).
                     *
                     * quitar_iva_y_sale_taxes() deshace exactamente los dos pasos que el camino
                     * normal aplica despues del margen, en orden inverso. Ver su comentario: es un
                     * par acoplado con aplicar_sale_taxes() y aplicar_iva().
                     */
                    $base_del_margen = Self::quitar_iva_y_sale_taxes($article, $final_price, $user);

                    $percentage = ($base_del_margen - $cost) / $cost * 100;

                    if ($describir) {
                        // El precio lo fijo el usuario a mano (setear_precio_final en el pivot): no se
                        // aplica ningun margen, el porcentaje se calcula al reves, a partir del precio.
                        $des_lista[] = DesglosePrecioHelper::linea(
                            DesglosePrecioHelper::PRECIO_MANUAL,
                            'Precio fijado a mano',
                            'lo fijaste vos para esta lista',
                            Numbers::price($final_price, true),
                            'El precio de esta lista lo fijaste vos a mano = '.Numbers::price($final_price, true)
                        );

                        if (abs($base_del_margen - (float) $final_price) > 0.00001) {
                            $des_lista[] = DesglosePrecioHelper::linea(
                                DesglosePrecioHelper::NOTA,
                                'Neto de IVA e impuestos sobre ventas',
                                'es lo comparable con el costo: '.Numbers::price($base_del_margen, true),
                                null,
                                'Sacandole el IVA y los impuestos sobre ventas queda '.Numbers::price($base_del_margen, true).', que es lo comparable con el costo'
                            );
                        }

                        $des_lista[] = DesglosePrecioHelper::linea(
                            DesglosePrecioHelper::PRECIO_MANUAL,
                            'Margen resultante',
                            'sale de ese precio contra el costo, no al revés',
                            round($percentage, 2).'%',
                            'El margen de '.round($percentage, 2).'% es el que resulta de ese precio contra el costo, no al reves'
                        );
                    }

                } else {

                    if ($describir) {
                        /*
                            Se compara el margen EFECTIVO contra el default de la lista, y NO si el
                            pivote tiene percentage cargado.

                            El motivo (hallazgo 20260805-desglose-por-lista-margen-propio-y-acentos):
                            esta misma funcion escribe percentage en el pivote con
                            updateExistingPivot() en cada corrida, mas abajo. O sea que cualquier
                            articulo guardado una vez ya tiene el pivote cargado, y con la condicion
                            anterior el renglon decia "Margen propio" SIEMPRE, aunque el valor fuera
                            literalmente el default de la lista.

                            El numero nunca estuvo mal: lo que mentia era la explicacion. Y este
                            renglon existe justamente para auditar el calculo, asi que en el caso mas
                            comun estaba diciendo lo contrario de lo que pasaba.

                            La comparacion es por diferencia y no por !=, porque los dos valores
                            vienen de columnas decimales y pasan por (float): 10 y 10.00 tienen que
                            contar como el mismo margen.
                        */
                        $percentage_por_defecto = is_null($price_type->percentage) ? 0 : (float) $price_type->percentage;

                        if (abs($percentage - $percentage_por_defecto) > 0.00001) {
                            $des_lista[] = DesglosePrecioHelper::linea(
                                DesglosePrecioHelper::MARGEN,
                                'Margen',
                                'propio del artículo en esta lista',
                                $percentage.'%',
                                'Margen propio del articulo en esta lista: '.$percentage.'%'
                            );
                        } else {
                            $des_lista[] = DesglosePrecioHelper::linea(
                                DesglosePrecioHelper::MARGEN,
                                'Margen',
                                'por defecto de la lista',
                                $percentage.'%',
                                'Margen por defecto de la lista: '.$percentage.'%'
                            );
                        }
                    }

                    $final_price = $cost + ($cost * (float)$percentage / 100);

                    if ($describir) {
                        $des_lista[] = DesglosePrecioHelper::linea(
                            DesglosePrecioHelper::MARGEN,
                            'Precio con el margen aplicado',
                            null,
                            Numbers::price($final_price, true),
                            'Precio con el margen aplicado = '.Numbers::price($final_price, true)
                        );
                    }

                    // Capa 2 (Prompt 261): sale_taxes con formula de division, despues del margen
                    // (y de los price_type_surchages, que se preservan sin tocar mas abajo) y
                    // antes del IVA de venta.
                    $res = ArticlePricesHelper::aplicar_sale_taxes($article, $final_price, $user, []);
                    $final_price = $res['price'];

                    if ($describir) {
                        $des_lista = array_merge($des_lista, $res['des']);
                    }

                    if (!Self::iva_va_al_costo($user)) {

                        $res = ArticlePricesHelper::aplicar_iva($article, $final_price, $user, []);
                        $final_price = $res['price'];
                        // $des   = $res['des'];

                        if ($describir) {
                            $des_lista = array_merge($des_lista, $res['des']);
                        }

                    } else if ($describir) {

                        // El IVA no se suma aca porque ya viene adentro del costo real. Distinguir los
                        // dos motivos NO es un detalle: el 5/8/2026 se diagnostico como bug ("el IVA se
                        // suma antes del margen") lo que en realidad era una cuenta sin migrar, con la
                        // tilde vieja prendida. Si este renglon no separa los dos casos, el diagnostico
                        // equivocado vuelve.
                        if ($user->usar_condicion_fiscal_en_costeo) {
                            $des_lista[] = DesglosePrecioHelper::linea(
                                DesglosePrecioHelper::NOTA,
                                'Acá no se suma IVA',
                                'sos Monotributista: el IVA no se recupera y ya viene incluido dentro del costo real',
                                null,
                                'No se suma IVA aca: sos Monotributista, asi que el IVA no se recupera y ya viene incluido dentro del costo real'
                            );
                        } else {
                            $des_lista[] = DesglosePrecioHelper::linea(
                                DesglosePrecioHelper::NOTA,
                                'Acá no se suma IVA',
                                'esta cuenta tiene prendida la configuración vieja "aplicar IVA al costo", así que el IVA ya viene incluido dentro del costo real (no depende de tu condición fiscal)',
                                null,
                                'No se suma IVA aca: esta cuenta tiene la configuracion vieja "aplicar IVA al costo" prendida, asi que el IVA ya viene incluido dentro del costo real (no depende de tu condicion fiscal)'
                            );
                        }
                    }

                }
            } else if ($describir) {

                $des_lista[] = DesglosePrecioHelper::linea(
                    DesglosePrecioHelper::NOTA,
                    'Sin margen',
                    'el costo es cero, así que no se calcula ningún margen para esta lista',
                    null,
                    'El costo es cero, asi que no se calcula ningun margen para esta lista'
                );
            }


            // $article y $user van para que monto_ganancia salga neto de IVA y de impuestos sobre
            // ventas: la ganancia es lo que queda para el negocio, no lo que se le debe a AFIP.
            $res = Self::aplicar_price_type_surchages($price_type, $final_price, $cost, $describir ? [] : null, $article, $user);

            if ($describir) {
                $des_lista = array_merge($des_lista, $res['des']);
                $des_lista[] = DesglosePrecioHelper::linea(
                    DesglosePrecioHelper::TOTAL,
                    'Precio final de la lista',
                    null,
                    Numbers::price($res['precio_luego_de_recargos'], true),
                    'Precio final de la lista = '.Numbers::price($res['precio_luego_de_recargos'], true)
                );
                $des_lista[] = DesglosePrecioHelper::linea(
                    DesglosePrecioHelper::MARGEN,
                    'Ganancia sobre el costo',
                    'neta de IVA y de impuestos sobre ventas',
                    Numbers::price($res['monto_ganancia'], true),
                    'Ganancia sobre el costo = '.Numbers::price($res['monto_ganancia'], true)
                );
            }



            $article->price_types()->syncWithoutDetaching($price_type->id);

            $article->price_types()->updateExistingPivot($price_type->id, [
                'percentage'            => $percentage,
                // 'price'                 => $price,
                'final_price'           => $final_price,
                'previus_final_price'   => $previus_final_price,
                'precio_luego_de_recargos'  => $res['precio_luego_de_recargos'],
                'monto_ganancia'  => $res['monto_ganancia'],
            ]);

            Log::info('Seteando price_type '.$price_type->name.' para article num: '.$article->id.' con percentage '.$percentage.'% y final_price de '.$final_price);

        }

        return $des_lista;
    }

    /**
     * El ESPEJO de aplicar_sale_taxes() + aplicar_iva(): dado un precio final, devuelve la base
     * de la que ese precio saldria por el camino normal. Tarea 9, 11/8/2026.
     *
     * Para que sirve: cuando el precio de una lista lo fijo una persona a mano (setear_precio_final
     * en el pivote), el margen y la ganancia se derivan de ese precio. Derivarlos contra el precio
     * CON IVA compara dos magnitudes distintas -- un precio con impuestos contra un costo sin ellos
     * -- y da numeros que no significan nada: con costo 1000 e IVA 21%, un precio de 3000 mostraba
     * margen 200% y ganancia 2000, cuando el margen real es 147,93% y la ganancia 1479,34. Los 520
     * de diferencia son IVA: plata que se le debe a AFIP, no ganancia de nadie.
     *
     * 🔴 ES UN PAR ACOPLADO. El camino forward de aplicar_precios_segun_listas_de_precios() es
     * costo -> margen -> aplicar_sale_taxes() -> aplicar_iva(), y esta funcion lo deshace en el
     * orden inverso, reusando las MISMAS decisiones (iva_va_al_costo, es_monotributista_para_costeo,
     * hasIva, get_sale_taxes_para_articulo) en vez de reimplementar las condiciones. Si alguna de
     * esas dos cambia, esta tiene que cambiar con ellas o se desincronizan sin que nada avise. El
     * test de ida y vuelta de tests/Feature/Costeo es el que lo denuncia.
     *
     * @param mixed $article
     * @param float $price Precio final (con IVA y con impuestos sobre ventas, si correspondian).
     * @param mixed $user
     * @return float La base antes de esos dos pasos.
     */
    static function quitar_iva_y_sale_taxes($article, $price, $user)
    {
        $price = (float) $price;

        /**
         * 1. Deshacer el IVA. Misma condicion que usa el camino forward para sumarlo: solo si el
         * IVA no va al costo, y solo si el articulo lo tiene aplicable.
         */
        /*
         * Simetrico exacto del camino forward: si el IVA no participa del precio, no hay nada que
         * deshacer. Si esta condicion no acompaniara a la de aplicar_iva(), a un Monotributista se
         * le restaria un IVA que nunca se le sumo y el precio quedaria 21% abajo.
         */
        if (!Self::iva_va_al_costo($user) && Self::el_iva_participa_del_precio($user)) {

            $es_monotributista = Self::es_monotributista_para_costeo($user);

            if ($article->aplicar_iva || $es_monotributista) {

                $article->load('iva');

                if (Self::hasIva($article)) {

                    $divisor = 1 + ((float) $article->iva->percentage / 100);

                    if ($divisor != 0) {
                        $price = $price / $divisor;
                    }
                }
            }
        }

        /**
         * 2. Deshacer los impuestos sobre ventas.
         *
         * Se recorren en orden inverso para que se lea como el espejo de aplicar_sale_taxes(), y
         * NO porque el resultado dependa del orden: el forward es un producto de escalares
         * 1 / (1 - t) y la multiplicacion conmuta, asi que con dos impuestos o con veinte el
         * numero es el mismo se recorran como se recorran. Queda escrito para que nadie "arregle"
         * un bug de orden que no existe, ni confie en el reverse para uno que si existiria si
         * algun dia un sale_tax dejara de ser un factor (por ejemplo, un monto fijo).
         */
        $sale_taxes = Self::get_sale_taxes_para_articulo($article, $user);

        $en_orden_inverso = array_reverse(is_array($sale_taxes) ? $sale_taxes : $sale_taxes->all());

        foreach ($en_orden_inverso as $sale_tax) {
            $price = $price * (1 - ((float) $sale_tax->percentage / 100));
        }

        return $price;
    }

    /**
     * @param $des array|null Con un array se arma el desglose de los recargos y se devuelve en 'des'.
     *        Con null (el default historico) 'des' vuelve vacio y no cambia nada.
     * @param $article mixed|null Con $article y $user, monto_ganancia sale NETO de IVA y de impuestos
     *        sobre ventas (tarea 9). Sin ellos se mantiene la cuenta vieja, para no romper a ningun
     *        llamador que todavia no los pase.
     * @param $user mixed|null
     */
    static function aplicar_price_type_surchages($price_type, $final_price, $cost, $des = null, $article = null, $user = null) {

        $describir = !is_null($des);

        if (!$describir) {
            $des = [];
        }

        $precio_luego_de_recargos = $final_price;

        foreach ($price_type->price_type_surchages as $price_type_surchage) {

            if (!is_null($price_type_surchage->percentage)) {

                $precio_luego_de_recargos -= $precio_luego_de_recargos * $price_type_surchage->percentage / 100;

                if ($describir) {
                    // Se llaman "recargos" pero RESTAN: el codigo hace -=, y es intencional
                    // (decision de Lucas del 4/7, ver refactor_empresa/precios_costos.md). El texto
                    // tiene que decir que resta, o el desglose miente.
                    //
                    // 🔴 Por eso el tipo es DEDUCCION y no RECARGO, aunque el nombre del modelo diga
                    // recargo: RECARGO se dibuja con un icono de signo mas, que es correcto para los
                    // recargos del articulo (esos SI suman) y seria exactamente lo contrario de lo
                    // que hace esta cuenta. En el texto viejo lo primero que se leia era "Menos ";
                    // en el layout nuevo lo primero que entra por el ojo es el icono, asi que es el
                    // icono el que tiene que decir la verdad. El nombre que cargo el usuario va de
                    // etiqueta, que es lo que identifica de que recargo se trata.
                    $des[] = DesglosePrecioHelper::linea(
                        DesglosePrecioHelper::DEDUCCION,
                        $price_type_surchage->name,
                        'Recargo de la lista: resta '.$price_type_surchage->percentage.'%',
                        Numbers::price($precio_luego_de_recargos, true),
                        'Menos '.$price_type_surchage->name.' ('.$price_type_surchage->percentage.'%) = '.Numbers::price($precio_luego_de_recargos, true)
                    );
                }

            } else if (!is_null($price_type_surchage->amount)) {

                $precio_luego_de_recargos -= $price_type_surchage->amount;

                if ($describir) {
                    // Mismo motivo que el de arriba: resta, asi que va DEDUCCION.
                    $des[] = DesglosePrecioHelper::linea(
                        DesglosePrecioHelper::DEDUCCION,
                        $price_type_surchage->name,
                        'Recargo de la lista: resta '.Numbers::price($price_type_surchage->amount, true),
                        Numbers::price($precio_luego_de_recargos, true),
                        'Menos '.$price_type_surchage->name.' ('.Numbers::price($price_type_surchage->amount, true).') = '.Numbers::price($precio_luego_de_recargos, true)
                    );
                }

            }
        }

        /**
         * La ganancia es lo que queda para el negocio, asi que se mide contra el precio NETO de
         * IVA y de impuestos sobre ventas (tarea 9, aprobado por Lucas el 10/8/2026). Con la
         * cuenta vieja, una lista al 40% sobre un costo de 1000 mostraba $694 de ganancia cuando
         * la real es $400: los $294 de diferencia eran IVA.
         *
         * precio_luego_de_recargos NO se toca y se sigue devolviendo CON IVA: es el precio que se
         * le cobra a la persona. Lo que cambia es contra que se lo compara.
         */
        $base_para_la_ganancia = $precio_luego_de_recargos;

        if (!is_null($article)) {
            $base_para_la_ganancia = Self::quitar_iva_y_sale_taxes($article, $precio_luego_de_recargos, $user);
        }

        return [
            'precio_luego_de_recargos'  => $precio_luego_de_recargos,
            'monto_ganancia'            => $base_para_la_ganancia - $cost,
            'des'                       => $des,
        ];


    }

    static function aplicar_iva($article, $price, $user, $des = []) {

        $precio_con_iva = $price;

        /*
         * 🔴 Si el IVA no participa del precio de esta cuenta, no se suma en ningun punto y se sale
         * antes de mirar cualquier otra cosa. Hoy eso pasa con un Monotributista migrado: su costo
         * ya es lo que paga, y no le cobra IVA a nadie.
         *
         * Este guard es lo que hace que sacar el IVA del costo (iva_va_al_costo -> false) no lo
         * MUDE al precio de venta: sin el, todos los call sites del pipeline hacen
         * `if (!iva_va_al_costo(...)) aplicar_iva(...)` y el MT terminaria cobrando un IVA que no
         * cobra. Ver el test el_iva_no_se_suma_al_precio_de_venta().
         */
        if (!Self::el_iva_participa_del_precio($user)) {

            return [
                'price'   => $price,
                'des'     => $des,
            ];
        }

        /*
         * Prompt 609: para una cuenta LEGACY (sin migrar) un Monotributista sigue incorporando el
         * IVA sin importar `articles.aplicar_iva`, porque ese control queda oculto para MT en el
         * listado (prompt 612) y si el calculo dependiera de el, una cuenta migrada de RRII a MT se
         * hundiria en silencio. Para una cuenta migrada este renglon ya no se alcanza con MT: lo
         * corta el guard de arriba.
         */
        $es_monotributista = Self::es_monotributista_para_costeo($user);

        if ($article->aplicar_iva || $es_monotributista) {

            $article->load('iva');

            if (Self::hasIva($article)) {

                // Log::info('iva: '.$article->iva->percentage);

                $importe_iva = $price * $article->iva->percentage / 100;

                $precio_con_iva += $importe_iva;

                $des[] = DesglosePrecioHelper::linea(
                    DesglosePrecioHelper::IVA,
                    'IVA',
                    $article->iva->percentage.'%',
                    Numbers::price($precio_con_iva, true),
                    'Mas IVA de '.$article->iva->percentage.'% = '.Numbers::price($precio_con_iva, true)
                );
            }
        }

        return [
            'price'   => $precio_con_iva,
            'des'     => $des,
        ];
    }

    static function hasIva($article) {
        return !is_null($article->iva) && $article->iva->percentage != '0' && $article->iva->percentage != 'Exento' && $article->iva->percentage != 'No Gravado';
    }

    /**
     * Prompt 609 — determina si, para efectos de costeo (compra a proveedor y costo real de
     * catálogo), el usuario dueño de la compra está en condición Monotributista.
     *
     * Grupo 231, prompt 01: `condicion_iva_precios` se movió de `UserConfiguration` a `User`
     * (misma fila que dispara el recálculo de precios en `UserController::update()`). Si no hay
     * usuario, se asume Responsable Inscripto — mismo criterio conservador de siempre (las cuentas
     * existentes no cambian de comportamiento si el campo todavía no está seteado).
     *
     * @param  \App\Models\User|null $user
     * @return bool
     */
    static function es_monotributista_para_costeo($user) {

        if (is_null($user)) {
            return false;
        }

        return $user->condicion_iva_precios == User::CONDICION_MT;
    }

    /**
     * Misión `costo-bruto-por-condicion-fiscal` (20/8/2026) — Resolvedor ÚNICO de la pregunta "el
     * costo que se acaba de cargar, ¿es bruto (con IVA) o neto?".
     *
     * Lo consultan las DOS vías donde el número siempre es uno recién cargado: la compra a
     * proveedor y el import de Excel.
     *
     * 🔴 El ABM del listado NO lo usa, y no es un olvido. Ese formulario manda el modelo entero en
     * cada guardado, así que un guardado que no toca el costo llega con el `cost` que devolvió el
     * servidor, que ya es NETO. Si ahí se forzara "bruto" por condición fiscal, ese guardado le
     * sacaría el IVA a un número que ya no lo tiene: 1000 → 826,45 → 683,01, medido por el checker
     * de la Fase 5. En el ABM manda el formulario, que declara siempre; que el Monotributista no
     * elija nada se resuelve mostrándole un solo campo. Ver ArticleController::set_costo_desde_request().
     *
     * 🔴 **El Monotributista no configura nada de IVA** (decisión de Lucas, 20/8/2026): todo lo que
     * carga es bruto POR DEFINICIÓN, en las tres vías. No es una preferencia ni un default que se
     * pueda cambiar — recibe Factura B, donde el IVA no viene discriminado y el neto no figura en
     * ningún lado. Pedirle que declare cuál de los dos está cargando es pedirle un dato que su
     * comprobante no tiene. Por eso tampoco se le muestra el flag de la compra ni el de
     * `aplicar_iva`: no hay nada que elegir.
     *
     * El Responsable Inscripto sí elige, porque su Factura A le discrimina el neto: en el listado lo
     * dice el input en el que tipeó, y en la compra y el import, el flag de esa carga.
     *
     * @param  \App\Models\User|null $user      Dueño del artículo.
     * @param  mixed                 $declarado Lo que declaró esta carga puntual (el input del ABM,
     *                                          `precios_incluyen_iva` de la compra o de la planilla).
     * @return bool                             true si hay que sacarle el IVA antes de guardar.
     */
    static function el_costo_cargado_es_bruto($user, $declarado)
    {
        /*
         * 🔴 Para un Monotributista migrado NUNCA se descompone, y se ignora lo que declare la
         * carga. Misión `iva-fuera-del-costeo-monotributista` (21/8/2026): el costo que carga es el
         * que paga, punto — no existe la distinción bruto/neto para él, así que no hay nada que
         * sacarle.
         *
         * Se ignora el `$declarado` a propósito, en vez de confiar en que el formulario mande
         * false: por la API o por una pantalla vieja puede llegar un `true`, y creerle le hundiría
         * el costo un 21% en silencio. La decisión es de la condición fiscal, no del que llama.
         *
         * Hasta esta misión esta misma rama devolvía `true` (el MT cargaba bruto y se descomponía).
         * Las dos versiones dan los mismos precios finales —el ida y vuelta se cancelaba—, pero
         * aquella dejaba guardado en `articles.cost` un número que la persona nunca tipeó.
         */
        if (Self::es_monotributista_para_costeo($user)
            && $user
            && $user->usar_condicion_fiscal_en_costeo
        ) {
            return false;
        }

        if (Self::es_monotributista_para_costeo($user)) {
            // Cuenta legacy: se conserva el comportamiento historico (el MT carga bruto).
            return true;
        }

        return filter_var($declarado, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Misión `iva-fuera-del-costeo-monotributista` (21/8/2026) — ¿El IVA participa del precio de
     * esta cuenta, en cualquier punto del pipeline?
     *
     * 🔴 Para un **Monotributista migrado, NO participa en ningún lado**: ni entra al costo ni se
     * suma a la venta. Decisión de Lucas: *"el monotributista no tiene que configurar nada de IVA
     * (...) el IVA no tiene que cambiar nada del precio en un monotributista"*. Carga el costo que
     * le pasa su proveedor, ese ES su costo, y sobre ese costo va el margen.
     *
     * Antes de esta misión el sistema le hacía un ida y vuelta —le sacaba el IVA al guardar y se lo
     * volvía a sumar al costear— que se cancelaba y daba los precios bien, pero dejaba en
     * `articles.cost` un número que la persona nunca tipeó (826,45 sobre un costo cargado de 1000).
     * Ese número se filtraba a la columna "Costo base" del listado y al export a Excel.
     *
     * 🔴 Este predicado y `iva_va_al_costo()` NO son lo mismo, y confundirlos es un error de plata:
     * `iva_va_al_costo()` responde DÓNDE se suma el IVA (antes o después del margen). Este responde
     * SI se suma. Si sólo se pone `iva_va_al_costo()` en false para el MT, el IVA no desaparece: se
     * muda al precio de venta —todos los call sites hacen `if (!iva_va_al_costo(...)) aplicar_iva()`—
     * y el monotributista termina cobrando un IVA que no cobra.
     *
     * Las cuentas **no migradas** (`usar_condicion_fiscal_en_costeo` apagado) no cambian nada: ahí
     * manda la tilde histórica y la condición fiscal se ignora por completo. Es el interruptor con
     * el que quedan afuera los clientes que ya están usando el sistema.
     *
     * @param  \App\Models\User|null $user
     * @return bool true si el IVA tiene que participar del precio (comportamiento histórico).
     */
    static function el_iva_participa_del_precio($user) {

        if (is_null($user)) {
            // Sin usuario no se puede resolver la condicion fiscal: se conserva el comportamiento
            // historico, que es el lado seguro (no dejar de aplicar un IVA que corresponde).
            return true;
        }

        if (!$user->usar_condicion_fiscal_en_costeo) {
            // Cuenta legacy: nada de esta mision le aplica.
            return true;
        }

        return !Self::es_monotributista_para_costeo($user);
    }

    /**
     * Prompt 231/02 — Resolver unico de "el IVA entra al costo o despues del margen".
     *
     * Unico lugar del sistema que sabe de la existencia de la bandera de transicion
     * `usar_condicion_fiscal_en_costeo`. Los cuatro call sites del pipeline de costeo/precios
     * (ArticleHelper::aplicar_descuentos_e_iva, ArticleHelper:352, ArticlePricesHelper:218 y
     * NewProviderOrderHelper:268) llaman a este metodo en vez de leer `aplicar_iva_al_costo`
     * directo, para que el dia que todas las cuentas esten migradas alcance con borrar el `if` de
     * transicion en un solo lugar.
     *
     * @param \App\Models\User|null $user Usuario cuya condicion fiscal se quiere resolver.
     * @return bool true si el IVA debe sumarse al costo (antes del margen), false si se aplica
     *              despues del margen (venta).
     */
    static function iva_va_al_costo($user) {

        if (is_null($user)) {
            // Sin usuario no se puede resolver la condicion fiscal real: lado seguro es no
            // meter el IVA al costo (mismo comportamiento que ya tenia el pipeline sin este campo).
            return false;
        }

        if (!$user->usar_condicion_fiscal_en_costeo) {
            // Cuenta legacy (todavia no migrada): sigue mandando la tilde manual, comportamiento
            // identico al historico.
            return (bool) $user->aplicar_iva_al_costo;
        }

        /*
         * Cuenta migrada: el IVA NO entra al costo, para ninguna condicion fiscal.
         *
         * - Responsable Inscripto: lo recupera como credito fiscal, asi que se suma al vender.
         * - Monotributista: desde la mision `iva-fuera-del-costeo-monotributista` (21/8/2026) el
         *   costo que carga YA es el que paga (se guarda tal cual, sin back-out), asi que no hay
         *   ningun IVA que agregarle. Y tampoco se le suma al vender: eso lo corta
         *   el_iva_participa_del_precio(), que es el predicado que hay que mirar para saber SI el
         *   IVA participa. Este metodo solo responde DONDE.
         *
         * Hasta esta mision devolvia true para MT, que es la mitad "se lo vuelvo a sumar" del ida y
         * vuelta que la mision saco.
         */
        return false;
    }

    /**
     * Prompt 514 — "Back-out" de IVA sobre un costo BRUTO, para dejarlo NETO.
     *
     * Convención del sistema (decisión de Lucas, 18/7): `articles.cost` y `article_provider.cost`
     * son SIEMPRE netos (sin IVA). Cuando el número que cargó una persona viene CON IVA incluido,
     * hay que sacárselo ANTES de escribir el costo, usando la alícuota propia del artículo — no una
     * alícuota global — para no inflar el costeo.
     *
     * Fórmula: neto = bruto / (1 + alicuota/100).
     *
     * 🔴 QUIÉN DECIDE que este número es bruto: el que lo carga, y nadie más (decisión de Lucas,
     * 20/8/2026). El ABM del listado lo dice con el input en el que se tipeó —hay uno para el neto
     * y otro para el bruto—; la compra, con `provider_orders.precios_incluyen_iva`; el import, con
     * el control de la planilla. **Este método no decide nada**: recibe un número que ya fue
     * declarado bruto y le saca el IVA.
     *
     * Por eso ya NO mira `articles.aplicar_iva` ni la condición fiscal de la cuenta, y sacar esos
     * dos guards fue el cambio deliberado del 20/8:
     *
     *   - `aplicar_iva` es una decisión sobre la VENTA (si al precio se le suma IVA encima). Es
     *     ortogonal a si el número que alguien acaba de tipear trae el IVA adentro. Mientras lo
     *     miró, el mismo importe costeaba distinto según por dónde entrara — el error del que sale
     *     esta misión — y un Responsable Inscripto que declaraba "esto viene con IVA" veía que no
     *     pasaba nada, en silencio.
     *   - La condición fiscal tampoco: el prompt 609 hacía que un Monotributista descompusiera
     *     SIEMPRE, ignorando el flag de la compra. Ahora manda el flag, para todos. 🔴 Consecuencia
     *     declarada en el informe: si un MT carga una compra y no lo tilda, su costo se toma como
     *     neto y queda 21% abajo. La mitigación propuesta es pre-tildarlo para cuentas MT.
     *
     * Lo único que sigue mandando es hasIva(): un artículo Exento, No Gravado o al 0% no tiene IVA
     * adentro por más que alguien lo afirme, así que no hay nada que sacar.
     *
     * @param  \App\Models\Article $article    Artículo cuya alícuota se usa para el back-out.
     * @param  float|string        $cost_bruto Costo con IVA incluido, tal cual lo cargó la persona.
     * @return float                           Costo neto (sin IVA). Si el artículo no tiene alícuota
     *                                          aplicable (sin IVA, 0%, Exento o No Gravado), devuelve
     *                                          el bruto sin tocar.
     */
    static function back_out_iva($article, $cost_bruto) {

        $cost_bruto = (float)$cost_bruto;

        if (is_null($article)) {
            return $cost_bruto;
        }

        // Se fuerza recarga (no loadMissing) por si el artículo trae la relación `iva` cacheada de
        // ANTES de que update_iva() le cambiara el iva_id en memoria dentro de este mismo request
        // (mismo criterio que usa aplicar_iva()): sin esto, el back-out podría calcular con una
        // alícuota vieja.
        $article->load('iva');

        if (!Self::hasIva($article)) {
            return $cost_bruto;
        }

        // Back-out: neto = bruto / (1 + alicuota/100).
        $alicuota = (float)$article->iva->percentage;

        return $cost_bruto / (1 + ($alicuota / 100));
    }

    static function aplicar_descuentos($article, $price, $des = []) {

        if (count($article->article_discounts) >= 1) {
            foreach ($article->article_discounts as $discount) {

                if (!is_null($discount->percentage)) {
                    $price -= $price * $discount->percentage / 100;
                    $des[] = DesglosePrecioHelper::linea(
                        DesglosePrecioHelper::DESCUENTO,
                        'Descuento',
                        $discount->percentage.'%',
                        Numbers::price($price, true),
                        'Menos descuento de '.$discount->percentage.'% = '.Numbers::price($price, true)
                    );
                } else if (!is_null($discount->amount)) {
                    $price -= $discount->amount;
                    $des[] = DesglosePrecioHelper::linea(
                        DesglosePrecioHelper::DESCUENTO,
                        'Descuento',
                        // Formateado con Numbers::price y no con el '$'.$amount crudo del texto
                        // historico: en el layout nuevo este numero queda al lado de un monto que si
                        // esta formateado, y "$1500.00" contra "$8.500,00" se lee como un error. El
                        // `texto` de abajo sigue con el crudo, que es lo que no se puede tocar.
                        Numbers::price($discount->amount, true),
                        Numbers::price($price, true),
                        'Menos descuento de $'.$discount->amount.' = '.Numbers::price($price, true)
                    );
                }

            }
        }
        return [
            'price'   => $price,
            'des'     => $des,
        ];
    }

    /**
     * Obtiene los sale_taxes activos que aplican a un artículo puntual: los de `apply_to_all = true`
     * del usuario, más los vinculados específicamente al artículo vía el pivot `article_sale_tax`
     * (Prompt 260/261 — Capa 2 del motor de precios).
     *
     * La consulta de sale_taxes del usuario se cachea en memoria (Self::$sale_taxes_cache) para
     * no repetirla en cada artículo cuando este helper corre en un recalculo masivo.
     *
     * @param \App\Models\Article $article
     * @param \App\Models\User $user
     * @return \Illuminate\Support\Collection Colección de SaleTax aplicables al artículo.
     */
    static function get_sale_taxes_para_articulo($article, $user) {

        if (!isset(Self::$sale_taxes_cache[$user->id])) {
            Self::$sale_taxes_cache[$user->id] = SaleTax::where('user_id', $user->id)
                                                            ->where('activo', 1)
                                                            ->get();
        }

        $sale_taxes_usuario = Self::$sale_taxes_cache[$user->id];

        // Filtro los que aplican a este articulo puntual: apply_to_all, o vinculados por pivot
        $aplicables = [];

        foreach ($sale_taxes_usuario as $sale_tax) {

            if ($sale_tax->apply_to_all) {

                $aplicables[] = $sale_tax;

            } else {

                foreach ($article->sale_taxes as $article_sale_tax) {
                    if ($article_sale_tax->id == $sale_tax->id) {
                        $aplicables[] = $sale_tax;
                        break;
                    }
                }
            }
        }

        return $aplicables;
    }

    /**
     * Aplica los impuestos sobre ventas (sale_taxes, ej. IIBB) al precio, con la fórmula
     * contablemente correcta de división (el precio ya "incluiría" el impuesto al despejarlo):
     * precio_final_neto = precio_con_margen / (1 - alicuota).
     *
     * Capa 2 del motor de precios (Prompt 261). Corre después del margen (y de los
     * price_type_surchages, que no se tocan) y antes del IVA de venta.
     *
     * @param \App\Models\Article $article
     * @param float $price Precio antes de aplicar los sale_taxes.
     * @param \App\Models\User $user
     * @param array $des Descripciones acumuladas de cada paso del cálculo (auditoría).
     * @return array{price:float,des:array}
     */
    static function aplicar_sale_taxes($article, $price, $user, $des = []) {

        $sale_taxes_aplicables = Self::get_sale_taxes_para_articulo($article, $user);

        foreach ($sale_taxes_aplicables as $sale_tax) {

            // Formula de division: se despeja el precio neto sabiendo que el impuesto se traslada
            // sobre el precio final (no es un descuento sobre el costo, es un impuesto sobre la venta)
            $price = $price / (1 - $sale_tax->percentage / 100);
            $des[] = DesglosePrecioHelper::linea(
                DesglosePrecioHelper::IMPUESTO,
                'Impuesto sobre ventas',
                $sale_tax->name.' ('.$sale_tax->percentage.'%), por división',
                Numbers::price($price, true),
                'Mas '.$sale_tax->name.' ('.$sale_tax->percentage.'%) por division = '.Numbers::price($price, true)
            );
        }

        return [
            'price'   => $price,
            'des'     => $des,
        ];
    }

    /**
     * Construye (y cachea por request) la configuracion de recargo por tarjeta del usuario, usada por
     * `calcular_precios_por_metodo_pago_con_tarjeta_incluida()` (Capa 3, Prompt 263).
     *
     * El "recargo maximo" (la tarjeta mas cara que el precio de etiqueta ya incluye) se busca entre
     * TODAS las reglas configuradas por el usuario: las genericas por metodo de pago
     * (`current_acount_payment_method_discounts`, sin cuotas especificas) Y las reglas por cantidad de
     * cuotas (`cuotas`, genericas o por metodo) — porque el recargo mas alto suele ser una cuota
     * especifica (ej. "credito 6 cuotas +10%"), no la regla generica del metodo. El desglose por
     * metodo que se le muestra al SPA, en cambio, usa el catalogo completo de metodos de pago
     * (`current_acount_payment_methods`, global) con su regla generica (o 0% si no tiene ninguna
     * configurada), ya que al mostrar el precio de etiqueta todavia no se eligio cantidad de cuotas.
     *
     * @param int $user_id Id del owner (dueño de cuenta).
     * @return array{multiplicador_max: float, metodos: \Illuminate\Support\Collection}|null Null si el
     *         flag `precio_base_incluye_tarjeta` esta apagado o no hay ningun recargo configurado
     *         (el helper no tiene efecto).
     */
    static function build_payment_method_layer3_cache($user_id) {

        $user = User::find($user_id);

        if (!$user || !$user->precio_base_incluye_tarjeta) {
            return null;
        }

        // Reglas genericas por metodo de pago (sin cantidad de cuotas especifica)
        $reglas_metodo = CurrentAcountPaymentMethodDiscount::where('user_id', $user_id)
                            ->whereNull('cuotas')
                            ->get();

        // Reglas por cantidad de cuotas (genericas o por metodo especifico)
        $reglas_cuotas = Cuota::where('user_id', $user_id)->get();

        // Multiplicador de cada regla sobre el monto: 1 - porcentaje/100
        // (porcentaje positivo = descuento, reduce el multiplicador; negativo = recargo, lo aumenta)
        $multiplicador_max = 1;

        foreach ($reglas_metodo as $regla) {
            $multiplicador = 1 - ((float)$regla->discount_percentage / 100);
            if ($multiplicador > $multiplicador_max) {
                $multiplicador_max = $multiplicador;
            }
        }

        foreach ($reglas_cuotas as $regla) {
            $porcentaje = (float)$regla->descuento - (float)$regla->recargo;
            $multiplicador = 1 - ($porcentaje / 100);
            if ($multiplicador > $multiplicador_max) {
                $multiplicador_max = $multiplicador;
            }
        }

        if ($multiplicador_max <= 1) {
            // Ningun recargo real configurado (todas son descuentos, neutras, o no hay reglas): sin efecto.
            return null;
        }

        // Catalogo completo de metodos de pago (global), con su regla generica de recargo/descuento
        // (0% si el metodo no tiene ninguna regla configurada, ej. "Efectivo" sin fila propia).
        $metodos = CurrentAcountPaymentMethod::all()->map(function ($metodo) use ($reglas_metodo) {

            $regla = $reglas_metodo->firstWhere('current_acount_payment_method_id', $metodo->id);

            return (object)[
                'current_acount_payment_method_id' => $metodo->id,
                'discount_percentage'               => $regla ? (float)$regla->discount_percentage : 0,
            ];
        });

        return [
            'multiplicador_max' => $multiplicador_max,
            'metodos'           => $metodos,
        ];
    }

    /**
     * Capa 3 del motor de precios (Prompt 263), caso `precio_base_incluye_tarjeta` activo: dado que
     * el precio de etiqueta del articulo ($price, ya calculado por las capas anteriores) representa el
     * precio CON el recargo del metodo de pago mas caro incluido, calcula el precio equivalente para
     * cada metodo de pago con regla generica configurada, para que el SPA (prompt 266) pueda mostrar
     * por ejemplo "Efectivo: $X (−Y%)" respecto del precio de etiqueta.
     *
     * Formula (despejando el precio base sin recargo y volviendo a aplicar el recargo del metodo):
     *   precio_metodo = precio_etiqueta * multiplicador_metodo / multiplicador_max
     * donde multiplicador_x = 1 - discount_percentage_x/100.
     *
     * Con el flag apagado (o sin reglas de recargo configuradas) devuelve null: el comportamiento
     * actual (precio base + recargo al elegir el metodo) queda intacto.
     *
     * @param float $price Precio de etiqueta del articulo (ya calculado, incluye el recargo maximo).
     * @param int $user_id Id del owner (dueño de cuenta) dueño del articulo.
     * @return array{recargo_max_percentage: float, precios_por_metodo: array}|null
     */
    static function calcular_precios_por_metodo_pago_con_tarjeta_incluida($price, $user_id) {

        if (is_null($price) || is_null($user_id)) {
            return null;
        }

        if (!array_key_exists($user_id, Self::$payment_method_layer3_cache)) {
            Self::$payment_method_layer3_cache[$user_id] = Self::build_payment_method_layer3_cache($user_id);
        }

        $cache = Self::$payment_method_layer3_cache[$user_id];

        if (is_null($cache)) {
            return null;
        }

        $precios_por_metodo = [];

        foreach ($cache['metodos'] as $metodo) {

            $multiplicador = 1 - ((float)$metodo->discount_percentage / 100);
            $price_metodo = $price * $multiplicador / $cache['multiplicador_max'];

            $precios_por_metodo[] = [
                'current_acount_payment_method_id' => $metodo->current_acount_payment_method_id,
                'price'                             => round($price_metodo, 2),
                // Porcentaje de descuento respecto del precio de etiqueta (positivo = mas barato que la etiqueta)
                'discount_percentage_vs_etiqueta'   => $price > 0 ? round((($price - $price_metodo) / $price) * 100, 2) : 0,
            ];
        }

        return [
            'recargo_max_percentage' => round((($cache['multiplicador_max'] - 1) * 100), 2),
            'precios_por_metodo'     => $precios_por_metodo,
        ];
    }

    static function aplicar_recargos($article, $price, $luego_del_precio_final = false, $des = []) {

        if (count($article->article_surchages) >= 1) {

            foreach ($article->article_surchages as $surchage) {

                if (
                    (
                        $surchage->luego_del_precio_final == 0
                        && !$luego_del_precio_final
                    )
                    || 
                    (
                        $surchage->luego_del_precio_final == 1
                        && $luego_del_precio_final
                    )
                ) {
                    // Log::info('Aplicando recargo luego_del_precio_final: '.$surchage->luego_del_precio_final);
                    // Log::info('luego_del_precio_final param: '.$luego_del_precio_final);
                    if (!is_null($surchage->percentage)) {
                        $price += $price * $surchage->percentage / 100;
                        $des[] = DesglosePrecioHelper::linea(
                            DesglosePrecioHelper::RECARGO,
                            'Recargo del artículo',
                            $surchage->percentage.'%',
                            Numbers::price($price, true),
                            'Mas recargo de '.$surchage->percentage.'% = '.Numbers::price($price, true)
                        );
                    } else if (!is_null($surchage->amount)) {
                        $price += $surchage->amount;
                        $des[] = DesglosePrecioHelper::linea(
                            DesglosePrecioHelper::RECARGO,
                            'Recargo del artículo',
                            Numbers::price($surchage->amount, true),
                            Numbers::price($price, true),
                            'Mas recargo de '.Numbers::price($surchage->amount, true).' = '.Numbers::price($price, true)
                        );
                    }
                } 


            }
        }
        return [
            'price'   => $price,
            'des'     => $des,
        ];
    }

    static function set_precios_en_blanco($article, $user = null) {

        // Log::info('set_precios_en_blanco para '.$article->name);

        $cost = $article->cost;

        // Log::info('cost: '.$cost);

        $cost = Self::aplicar_descuentos_blanco($article, $cost);

        $cost = Self::aplicar_recargos_blanco($article, $cost);


        if (!is_null($article->percentage_gain_blanco)) {

            $cost += $cost * $article->percentage_gain_blanco / 100;
            // Log::info('Poniendo marguen del '.$article->percentage_gain_blanco.', quedo en '.$cost);
        }

        // Capa 2 (Prompt 261): las sale_taxes son impuestos sobre la venta, independientes del IVA,
        // por lo que tambien aplican sobre el precio en blanco (que es la variante SIN IVA).
        if (!is_null($user)) {

            $res = Self::aplicar_sale_taxes($article, $cost, $user, []);
            $cost = $res['price'];
        }


        if (env('REDONDEAR_PRECIOS_EN_CENTAVOS', false)) {
            $cost = round($cost);
        }

        $article->final_price_blanco = $cost;

        return $article;
    }

    static function aplicar_descuentos_blanco($article, $cost) {

        foreach ($article->article_discounts_blanco as $discount) {
            
            $cost -= $cost * $discount->percentage / 100;
            // Log::info('descontando el '.$discount->percentage.', quedo en '.$cost);
        }

        return $cost;
    }

    static function aplicar_recargos_blanco($article, $cost) {

        foreach ($article->article_surchages_blanco as $surchage) {
            
            $cost += $cost * $surchage->percentage / 100;
            // Log::info('aumentando el '.$surchage->percentage.', quedo en '.$cost);
        }

        return $cost;
    }


    static function adjuntar_descuentos($article, $article_discounts) {
        
        // Borro los descuentos actuales para aplicar todos desde 0
        ArticleDiscount::where('article_id', $article->id)
                        ->delete();

        if ($article_discounts) {
            
            foreach ($article_discounts as $discount) {
                $discount = (object) $discount;
                if ($discount->percentage != '') {
                    ArticleDiscount::create([
                        'percentage' => $discount->percentage,
                        'article_id' => $article->id,
                        // Tipo del descuento si viene en el origen (Prompt 260); si no, queda null
                        'tipo' => isset($discount->tipo) ? $discount->tipo : null,
                    ]);
                    // Log::info('Se creo descuento de '.$discount->percentage);
                }
            }
        }
    }


    static function adjuntar_descuentos_en_blanco($article, $article_discounts) {
        
        // Borro los descuentos actuales para aplicar todos desde 0
        ArticleDiscountBlanco::where('article_id', $article->id)
                        ->delete();

        if ($article_discounts) {
            
            foreach ($article_discounts as $discount) {
                $discount = (object) $discount;
                if ($discount->percentage != '') {
                    ArticleDiscountBlanco::create([
                        'percentage' => $discount->percentage,
                        'article_id' => $article->id,
                        // Propago el tipo del descuento original (Prompt 260, simetría con la tabla normal)
                        'tipo' => isset($discount->tipo) ? $discount->tipo : null,
                    ]);
                    // Log::info('Se creo descuento en blanco de '.$discount->percentage.' para article_id: '.$article->id);
                }
            }
        }
    }


    static function adjuntar_recargos($article, $article_surchages) {
        
        // Borro los recargos actuales para aplicar todos desde 0
        ArticleSurchage::where('article_id', $article->id)
                        ->delete();

        if ($article_surchages) {
            
            foreach ($article_surchages as $surchage) {
                $surchage = (object) $surchage;
                if ($surchage->percentage != '') {
                    ArticleSurchage::create([
                        'percentage' => $surchage->percentage,
                        'article_id' => $article->id,
                        // Tipo del recargo si viene en el origen (Prompt 260); si no, queda null
                        'tipo' => isset($surchage->tipo) ? $surchage->tipo : null,
                    ]);
                    // Log::info('Se creo recargo de '.$surchage->percentage);
                }
            }
        }
    }


    static function adjuntar_recargos_en_blanco($article, $article_surchages) {
        
        // Borro los recargos actuales para aplicar todos desde 0
        ArticleSurchageBlanco::where('article_id', $article->id)
                        ->delete();

        if ($article_surchages) {
            
            foreach ($article_surchages as $surchage) {
                $surchage = (object) $surchage;
                if ($surchage->percentage != '') {
                    ArticleSurchageBlanco::create([
                        'percentage' => $surchage->percentage,
                        'article_id' => $article->id,
                        // Propago el tipo del recargo original (Prompt 260, simetría con la tabla normal)
                        'tipo' => isset($surchage->tipo) ? $surchage->tipo : null,
                    ]);
                    // Log::info('Se creo recargo en blanco de '.$surchage->percentage);
                }
            }
        }
    }
}