<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ArticleHelper;
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
            $des[] = 'Mas margen de la categoria del '.$article->category->percentage_gain.'% = '.Numbers::price($price, true);
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
                // mb_strtoupper y no strtoupper: strtoupper() no toca los acentos, asi que una lista
                // llamada "Publico" con tilde salia como 'LISTA PúBLICO'. No es solo cosmetico:
                // PriceDescription.vue decide que una linea es titulo comparando des === des.toUpperCase(),
                // y JS SI convierte la u con tilde, asi que la comparacion daba false y el encabezado se
                // renderizaba como un renglon mas del desglose (hallazgo
                // 20260805-desglose-por-lista-margen-propio-y-acentos, punto 2).
                $des_lista[] = 'CALCULO DEL PRECIO DE LA LISTA '.mb_strtoupper($price_type->name, 'UTF-8');
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
                $des_lista[] = 'Costo de partida (costo real ya calculado arriba) = '.Numbers::price($cost, true);
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
                        $des_lista[] = 'El precio de esta lista lo fijaste vos a mano = '.Numbers::price($final_price, true);

                        if (abs($base_del_margen - (float) $final_price) > 0.00001) {
                            $des_lista[] = 'Sacandole el IVA y los impuestos sobre ventas queda '.Numbers::price($base_del_margen, true).', que es lo comparable con el costo';
                        }

                        $des_lista[] = 'El margen de '.round($percentage, 2).'% es el que resulta de ese precio contra el costo, no al reves';
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
                            $des_lista[] = 'Margen propio del articulo en esta lista: '.$percentage.'%';
                        } else {
                            $des_lista[] = 'Margen por defecto de la lista: '.$percentage.'%';
                        }
                    }

                    $final_price = $cost + ($cost * (float)$percentage / 100);

                    if ($describir) {
                        $des_lista[] = 'Precio con el margen aplicado = '.Numbers::price($final_price, true);
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
                            $des_lista[] = 'No se suma IVA aca: sos Monotributista, asi que el IVA no se recupera y ya viene incluido dentro del costo real';
                        } else {
                            $des_lista[] = 'No se suma IVA aca: esta cuenta tiene la configuracion vieja "aplicar IVA al costo" prendida, asi que el IVA ya viene incluido dentro del costo real (no depende de tu condicion fiscal)';
                        }
                    }

                }
            } else if ($describir) {

                $des_lista[] = 'El costo es cero, asi que no se calcula ningun margen para esta lista';
            }


            // $article y $user van para que monto_ganancia salga neto de IVA y de impuestos sobre
            // ventas: la ganancia es lo que queda para el negocio, no lo que se le debe a AFIP.
            $res = Self::aplicar_price_type_surchages($price_type, $final_price, $cost, $describir ? [] : null, $article, $user);

            if ($describir) {
                $des_lista = array_merge($des_lista, $res['des']);
                $des_lista[] = 'Precio final de la lista = '.Numbers::price($res['precio_luego_de_recargos'], true);
                $des_lista[] = 'Ganancia sobre el costo = '.Numbers::price($res['monto_ganancia'], true);
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
        if (!Self::iva_va_al_costo($user)) {

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
                    $des[] = 'Menos '.$price_type_surchage->name.' ('.$price_type_surchage->percentage.'%) = '.Numbers::price($precio_luego_de_recargos, true);
                }

            } else if (!is_null($price_type_surchage->amount)) {

                $precio_luego_de_recargos -= $price_type_surchage->amount;

                if ($describir) {
                    $des[] = 'Menos '.$price_type_surchage->name.' ('.Numbers::price($price_type_surchage->amount, true).') = '.Numbers::price($precio_luego_de_recargos, true);
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

        // Prompt 609: un Monotributista no recupera IVA, así que el costo real (y cualquier precio
        // que pase por acá) SIEMPRE lo incorpora, sin importar el flag articles.aplicar_iva —
        // ese control queda oculto para MT en el listado (prompt 612), así que si el cálculo
        // dependiera de él y quedara en 0 (ej. cuenta que migró de RRII a MT), el costo se hundiría
        // en silencio.
        $es_monotributista = Self::es_monotributista_para_costeo($user);

        if ($article->aplicar_iva || $es_monotributista) {

            $article->load('iva');

            if (Self::hasIva($article)) {

                // Log::info('iva: '.$article->iva->percentage);

                $importe_iva = $price * $article->iva->percentage / 100;

                $precio_con_iva += $importe_iva;

                $des[] = 'Mas IVA de '.$article->iva->percentage.'% = '.Numbers::price($precio_con_iva, true);
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

        // Cuenta migrada a la dinamica contable real: manda la condicion fiscal.
        // Monotributista no recupera IVA, asi que el IVA es costo y entra antes del margen.
        // Responsable Inscripto lo recupera como credito fiscal, asi que el IVA se suma al vender.
        return Self::es_monotributista_para_costeo($user);
    }

    /**
     * Misión `costo-bruto-por-condicion-fiscal` (20/8/2026) — Resolvedor UNICO de la pregunta
     * "lo que el usuario acaba de tipear, ¿es un costo bruto (con IVA) o neto (sin IVA)?".
     *
     * Es la unica pieza del sistema que decide esto. Lo consultan las cuatro vias que escriben
     * `articles.cost` a partir de un numero que tipeo una persona: el ABM del listado
     * (ArticleController::store/update), el import de Excel (ProcessRow), el pre-import
     * (ArticlesPreImportController) y la compra a proveedor (NewProviderOrderHelper). Si el
     * criterio queda escrito en cada una por separado, se desincronizan sin que nada avise —
     * que es exactamente el estado del que sale esta mision: la compra hacia el back-out y las
     * otras tres no, asi que el mismo articulo costeaba distinto segun por donde entrara.
     *
     * 🔴 Monotributista SIEMPRE carga bruto, sin opcion. No es una preferencia: recibe Factura B
     * del proveedor, donde el IVA no viene discriminado y el neto no figura en ningun lado. Pedirle
     * el neto es pedirle un dato que su comprobante no tiene.
     *
     * Responsable Inscripto elige, porque su Factura A si le discrimina el neto: manda el default
     * de la cuenta (`users.costos_cargados_con_iva`), y el formulario puede pisarlo por carga con
     * `$cost_incluye_iva` — que es una decision DE ENTRADA ("lo que estoy tipeando ahora incluye
     * IVA"), no un estado que se guarde en el articulo. Sin usuario se devuelve false, mismo
     * criterio conservador que usan es_monotributista_para_costeo() e iva_va_al_costo().
     *
     * @param  \App\Models\User|null $user            Dueño del articulo.
     * @param  mixed                 $cost_incluye_iva Override puntual de esta carga (el toggle del
     *                                                 formulario). `null` = no vino, manda la cuenta.
     * @return bool                                    true si hay que descomponer el IVA antes de
     *                                                 escribir `articles.cost`.
     */
    static function costo_tipeado_es_bruto($user, $cost_incluye_iva = null) {

        if (is_null($user)) {
            return false;
        }

        /*
         * 🔴 El override explicito gana SIEMPRE, incluido Monotributista, y este orden no es
         * negociable.
         *
         * "El MT carga siempre en bruto" es una regla sobre lo que una PERSONA tipea, no sobre
         * cualquier numero que llegue en un request. El formulario del listado manda `cost` con el
         * NETO que devolvio el servidor cada vez que alguien guarda sin tocar el campo de costo
         * (cambiar el nombre, la categoria, el stock minimo), y lo declara con
         * `cost_incluye_iva = false`. Si esa declaracion no ganara, el back-out correria sobre un
         * numero que ya era neto y el costo se hundiria un 21% en cada guardado: 1000 -> 826,45 ->
         * 683,01. Medido por el checker de la Fase 5 antes de este cambio.
         *
         * El default por condicion fiscal sigue mandando cuando la clave NO viene, que es el caso
         * del import, del pre-import y de cualquier cliente de API que no la conozca: ahi el numero
         * si es lo que alguien tipeo en un Excel, y para un MT eso es bruto.
         */
        if (!is_null($cost_incluye_iva) && $cost_incluye_iva !== '') {
            return filter_var($cost_incluye_iva, FILTER_VALIDATE_BOOLEAN);
        }

        // Sin declaracion explicita manda la condicion fiscal: el MT no elige.
        if (Self::es_monotributista_para_costeo($user)) {
            return true;
        }

        return (bool) $user->costos_cargados_con_iva;
    }

    /**
     * Misión `costo-bruto-por-condicion-fiscal` (20/8/2026) — Dado lo que tipeó el usuario, devuelve
     * el par `cost` (neto, lo que se guarda) y `cost_bruto` (el dato de origen).
     *
     * 🔴 Existe para que el criterio de "cuándo se registra el bruto" viva en UN solo lugar. Lo
     * consumen las cuatro vías de escritura, y esta misión nació justamente de que un criterio de
     * costeo escrito por separado en cada vía se desincroniza sin que nada avise.
     *
     * La sutileza que lo hace necesario: que la cuenta cargue en bruto NO alcanza para que haya un
     * bruto que registrar. Un artículo Exento, No Gravado o con alícuota 0 no tiene IVA que sacar,
     * así que el número tipeado ES el neto y `cost_bruto` tiene que quedar en null — que es como el
     * resto del sistema lee "este costo se cargó sin IVA". Guardar ahí el mismo número haría que la
     * interfaz mostrara un "costo con IVA" que no tiene IVA adentro.
     *
     * @param  \App\Models\Article   $article  Artículo con su `iva_id` ya asignado.
     * @param  mixed                 $cost     Costo tal cual lo tipeó el usuario.
     * @param  \App\Models\User|null $user
     * @param  bool                  $es_bruto Resultado de costo_tipeado_es_bruto().
     * @return array{cost: mixed, cost_bruto: mixed}
     */
    static function resolver_costo_neto_y_bruto($article, $cost, $user, $es_bruto) {

        if (!$es_bruto || is_null($cost) || $cost === '' || is_null($article)) {
            return ['cost' => $cost, 'cost_bruto' => null];
        }

        $neto = Self::back_out_iva($article, $cost, $user);

        /*
         * 🔴 NO alcanza con comparar $neto contra $cost para concluir "lo tipeado ya era el neto".
         * back_out_iva() devuelve el bruto sin tocar en DOS situaciones que significan cosas
         * distintas, y tratarlas igual es un error de plata:
         *
         *   (a) El artículo no tiene alícuota aplicable (0 / Exento / No Gravado). Acá sí: no hay
         *       IVA adentro del número, lo tipeado ES el neto y `cost_bruto` va en null.
         *
         *   (b) El artículo tiene alícuota real pero `aplicar_iva` apagado, en una cuenta que no es
         *       Monotributista. Acá el número SÍ puede traer IVA adentro — la persona marcó "el
         *       costo que cargo incluye IVA" —, pero el sistema no se lo va a sacar nunca porque
         *       ese artículo no participa del IVA. El costo queda en bruto a propósito (para esa
         *       cuenta el IVA de compra de un artículo así es costo, no crédito recuperable), y por
         *       eso tampoco se registra `cost_bruto`: no hubo descomposición que reconstruir.
         *
         * La diferencia importa porque `cost_bruto` es lo que la interfaz muestra. Registrarlo en
         * el caso (b) haría que el campo dijera "Costo con IVA" sobre un número que el sistema
         * trata como neto, y cada apertura del formulario lo volvería a inflar. El front replica
         * este mismo criterio en la computed `hay_iva_aplicable` de CostInput.vue, para no prometer
         * en pantalla una descomposición que acá no va a pasar.
         */
        if ((float) $neto == (float) $cost) {
            return ['cost' => $cost, 'cost_bruto' => null];
        }

        return ['cost' => $neto, 'cost_bruto' => $cost];
    }

    /**
     * Prompt 514 — "Back-out" de IVA sobre un costo BRUTO, para dejarlo NETO.
     *
     * Convención del sistema (decisión de Lucas, 18/7): `articles.cost` y `article_provider.cost`
     * son SIEMPRE netos (sin IVA). Cuando el precio de origen (lista del proveedor, orden de
     * compra, import) viene CON IVA incluido, hay que sacárselo ANTES de escribir el costo, usando
     * la alícuota propia del artículo — no una alícuota global — para no inflar el costeo de un
     * Responsable Inscripto (para quien el IVA es crédito fiscal recuperable, no costo) ni duplicar
     * el IVA de un Monotributista, a quien el pipeline de precios (ArticleHelper::aplicar_iva_al_costo,
     * ArticlePricesHelper::aplicar_iva) ya se lo vuelve a sumar una sola vez sobre el costo neto.
     *
     * Fórmula: neto = bruto / (1 + alicuota/100).
     *
     * Reusa exactamente el mismo criterio de "el artículo tiene IVA aplicable" que aplicar_iva()
     * (aplicar_iva() del artículo + hasIva(), que descarta alícuota 0/Exento/No Gravado), para que
     * el back-out en la escritura sea simétrico con el IVA que se vuelve a sumar en la lectura.
     *
     * @param  \App\Models\Article $article      Artículo cuya alícuota de IVA se usa para el back-out.
     * @param  float|string        $cost_bruto   Costo con IVA incluido, tal cual vino del origen.
     * @param  \App\Models\User|null $user       Dueño de la compra (Prompt 609). Si es Monotributista,
     *                                            se descompone SIEMPRE, ignorando `articles.aplicar_iva`
     *                                            (ese control queda oculto para MT en el listado,
     *                                            prompt 612).
     * @return float                             Costo neto (sin IVA). Si el artículo no tiene IVA
     *                                            aplicable (sin alícuota, 0%, Exento o No Gravado), o
     *                                            tiene aplicar_iva desactivado y el usuario no es
     *                                            Monotributista, devuelve el bruto sin tocar.
     */
    static function back_out_iva($article, $cost_bruto, $user = null) {

        $cost_bruto = (float)$cost_bruto;

        if (is_null($article)) {
            return $cost_bruto;
        }

        // Prompt 609: un Monotributista siempre trata el costo tipeado como bruto y lo descompone,
        // sin importar el flag articles.aplicar_iva.
        $es_monotributista = Self::es_monotributista_para_costeo($user);

        // Misma condición que aplicar_iva(): el artículo tiene que tener aplicar_iva ON (o el
        // usuario ser Monotributista) y una alícuota cargada que no sea 0/Exento/No Gravado. Si no,
        // no hay IVA que sacar.
        if (!$article->aplicar_iva && !$es_monotributista) {
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
                    $des[] = 'Menos descuento de '.$discount->percentage.'% = '.Numbers::price($price, true);
                } else if (!is_null($discount->amount)) {
                    $price -= $discount->amount;
                    $des[] = 'Menos descuento de $'.$discount->amount.' = '.Numbers::price($price, true);
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
            $des[] = 'Mas '.$sale_tax->name.' ('.$sale_tax->percentage.'%) por division = '.Numbers::price($price, true);
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
                        $des[] = 'Mas recargo de '.$surchage->percentage.'% = '.Numbers::price($price, true);
                    } else if (!is_null($surchage->amount)) {
                        $price += $surchage->amount;
                        $des[] = 'Mas recargo de '.Numbers::price($surchage->amount, true).' = '.Numbers::price($price, true);
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