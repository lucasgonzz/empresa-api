<?php

namespace Tests\Feature\Precios\DescuentosYMasivasEnLote;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\article\ArticleProviderDiscountHelper;
use App\Models\Article;
use App\Models\ArticleDiscount;
use App\Models\ProviderDiscount;
use App\Models\ProviderOrderDiscount;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Precios\RecalculoEnLote\RecalculoEnLoteTestCase;

/**
 * Base de los tests del constructor B de la mision `recalculo-precios-motor-rapido` (28/9/2026):
 * la sincronizacion y la propagacion de descuentos del proveedor y la actualizacion masiva, que
 * pasaron de escribir articulo por articulo a escribir en bloque con el motor de precios
 * (RecalculoDePreciosEnLote).
 *
 * Extiende la base de los tests del motor (RecalculoEnLoteTestCase, del constructor A): de ahi salen
 * el armado de comercios y articulos, el reloj congelado y `foto()`, que junta todo lo que un
 * recalculo escribe (articles, article_price_type, article_price_type_monedas, price_changes y
 * price_change_price_type) para comparar dos caminos campo por campo.
 *
 * Lo que agrega:
 *
 *   - LAS REFERENCIAS: sincronizar_como_hoy(), aplicar_ficha_en_lote_como_hoy(),
 *     propagar_como_hoy() y create_tagged_discounts_como_hoy() son el codigo de
 *     ArticleProviderDiscountHelper TAL COMO ESTABA en develop antes de esta mision (commit base de
 *     la rama, 496acac0), copiado sin una linea de mas. Son la vara contra la que se mide el camino
 *     nuevo y no se "modernizan" nunca, porque dejarian de ser la referencia. El
 *     create_tagged_discounts() de la referencia es el de antes del refactor que saco
 *     normalizar_descuento_tagueado(): asi la referencia no depende de nada que esta mision toco.
 *   - foto_de_descuentos(): las filas de `article_discounts` de cada articulo, en orden de id, con
 *     todas sus columnas menos el id (el id no se compara: un rollback no devuelve los
 *     autoincrementales, pero el ORDEN si, y es lo que decide el orden de aplicacion).
 *   - comparar_caminos_de(): corre los dos caminos sobre la misma base, cada uno en su savepoint y
 *     con el reloj congelado, y afirma que dejan exactamente lo mismo.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
abstract class DescuentosYMasivasEnLoteTestCase extends RecalculoEnLoteTestCase
{
    /* ------------------------------------------------------------------------------------------
     * Armado de descuentos
     * ---------------------------------------------------------------------------------------- */

    /**
     * Un descuento en la FICHA del proveedor (provider_discounts).
     *
     * @param  \App\Models\Provider $provider
     * @param  float|null           $percentage
     * @param  string|null          $nombre
     * @return \App\Models\ProviderDiscount
     */
    protected function descuento_de_la_ficha($provider, $percentage, $nombre = null)
    {
        return ProviderDiscount::create([
            'provider_id' => $provider->id,
            'percentage'  => $percentage,
            'nombre'      => $nombre,
        ]);
    }

    /**
     * La copia de un descuento de la ficha en el articulo (lo que deja la preferencia al asignar el
     * proveedor, o una sincronizacion anterior).
     *
     * @param  \App\Models\Article  $article
     * @param  \App\Models\Provider $provider
     * @param  float                $percentage
     * @param  array                $extra  editado_a_mano, show_in_online, ...
     * @return \App\Models\ArticleDiscount
     */
    protected function copia_de_la_ficha($article, $provider, $percentage, array $extra = [])
    {
        return ArticleDiscount::create(array_merge([
            'article_id'  => $article->id,
            'provider_id' => $provider->id,
            'percentage'  => $percentage,
            'tipo'        => ArticleDiscount::TIPO_BONIFICACION_PROVEEDOR,
            'origen'      => ArticleDiscount::ORIGEN_FICHA_PROVEEDOR,
        ], $extra));
    }

    /**
     * La bonificacion negociada en una COMPRA, escrita por quien la escribe en produccion
     * (create_tagged_discounts() con un ProviderOrderDiscount de verdad).
     *
     * @param  \App\Models\Article  $article
     * @param  \App\Models\Provider $provider
     * @param  float|null           $percentage
     * @param  float|null           $monto
     * @return void
     */
    protected function descuento_de_compra($article, $provider, $percentage, $monto = null)
    {
        ArticleProviderDiscountHelper::create_tagged_discounts(
            $article,
            $provider->id,
            collect([new ProviderOrderDiscount(['percentage' => $percentage, 'monto' => $monto])]),
            0,
            ArticleDiscount::ORIGEN_COMPRA
        );
    }

    /**
     * Un descuento MANUAL (sin proveedor): ninguna sincronizacion ni propagacion lo toca.
     *
     * @param  \App\Models\Article $article
     * @param  float|null          $percentage
     * @param  float|null          $amount
     * @return \App\Models\ArticleDiscount
     */
    protected function descuento_manual($article, $percentage, $amount = null)
    {
        return ArticleDiscount::create([
            'article_id' => $article->id,
            'percentage' => $percentage,
            'amount'     => $amount,
            'origen'     => ArticleDiscount::ORIGEN_MANUAL,
        ]);
    }

    /**
     * Deja los precios de los articulos calculados con el camino de siempre (el estado de cualquier
     * articulo real antes de una sincronizacion).
     *
     * @param  array $ids
     * @param  int   $owner_id
     * @return void
     */
    protected function calentar(array $ids, $owner_id)
    {
        $this->recalcular_como_hoy($ids, $owner_id);
    }

    /* ------------------------------------------------------------------------------------------
     * Las referencias: el codigo de develop, sin una linea de mas
     * ---------------------------------------------------------------------------------------- */

    /**
     * 🔴 REFERENCIA: ArticleProviderDiscountHelper::create_tagged_discounts() de develop (antes del
     * refactor que saco normalizar_descuento_tagueado()). Unico cambio: self:: -> la clase.
     *
     * @return void
     */
    protected function create_tagged_discounts_como_hoy($article, $provider_id, $discounts, $show_in_online = 0, $origen = null)
    {
        if (is_null($article) || is_null($provider_id) || is_null($discounts)) {
            return;
        }

        if (is_array($discounts) || $discounts instanceof \Countable) {

            if (count($discounts) === 0) {
                return;
            }
        }

        foreach ($discounts as $discount_original) {

            $discount = (object) $discount_original;

            $percentage = isset($discount->percentage) ? $discount->percentage : null;

            $amount = isset($discount->amount)
                ? $discount->amount
                : (isset($discount->monto) ? $discount->monto : null);

            if (
                (is_null($percentage) || $percentage === '')
                && (is_null($amount) || $amount === '')
            ) {
                continue;
            }

            ArticleDiscount::create([
                'article_id'  => $article->id,
                'provider_id' => $provider_id,
                'percentage'  => (!is_null($percentage) && $percentage !== '') ? $percentage : null,
                'amount'      => (!is_null($amount) && $amount !== '') ? $amount : null,
                'tipo'        => ArticleDiscount::TIPO_BONIFICACION_PROVEEDOR,
                'show_in_online' => $show_in_online ? 1 : 0,
                'origen' => $origen,
                'provider_discount_id' => ArticleProviderDiscountHelper::leer_provider_discount_id($discount_original, $discount),
                'nombre'               => ArticleProviderDiscountHelper::leer_nombre_del_descuento($discount),
            ]);
        }
    }

    /**
     * 🔴 REFERENCIA: ArticleProviderDiscountHelper::aplicar_ficha_en_lote() de develop (el lote del
     * fix del 22/9/2026: whereIn por lote, transaccion por lote para los descuentos, y
     * setFinalPrice() por articulo afuera). Unicos cambios: self:: -> la clase o la referencia.
     *
     * @return array
     */
    protected function aplicar_ficha_en_lote_como_hoy($provider, array $items, $owner_user = null)
    {
        if (count($items) === 0) {
            return [];
        }

        $tocados = [];

        $tamano_lote = (int) config('app.SINCRONIZAR_DESCUENTOS_PROVEEDOR_LOTE', 200);

        if ($tamano_lote < 1) {
            $tamano_lote = 200;
        }

        foreach (array_chunk($items, $tamano_lote) as $lote) {

            $ids_del_lote = array_column($lote, 'article_id');

            $articulos = Article::whereIn('id', $ids_del_lote)->get()->keyBy('id');

            DB::transaction(function () use ($lote, $articulos, $provider) {

                foreach ($lote as $item) {

                    $article = $articulos->get($item['article_id']);

                    if (is_null($article)) {
                        continue;
                    }

                    if (count($item['ids_a_barrer'])) {
                        ArticleDiscount::whereIn('id', $item['ids_a_barrer'])->delete();
                    }

                    $this->create_tagged_discounts_como_hoy(
                        $article,
                        $provider->id,
                        $provider->provider_discounts,
                        $item['mostrar_en_online'],
                        ArticleDiscount::ORIGEN_FICHA_PROVEEDOR
                    );
                }
            });

            foreach ($lote as $item) {

                $article = $articulos->get($item['article_id']);

                if (is_null($article)) {
                    continue;
                }

                $article->unsetRelation('article_discounts');

                $user_para_precio = (!is_null($owner_user) && (int) $owner_user->id === (int) $article->user_id)
                    ? $owner_user
                    : null;

                ArticleHelper::setFinalPrice($article, $article->user_id, $user_para_precio);

                $tocados[] = $item['article_id'];
            }
        }

        return $tocados;
    }

    /**
     * 🔴 REFERENCIA: ArticleProviderDiscountHelper::sincronizar_a_articulos() de develop. Unicos
     * cambios: self:: -> la clase o la referencia.
     *
     * @return array
     */
    protected function sincronizar_como_hoy(
        $provider,
        $alcance = ArticleProviderDiscountHelper::ALCANCE_SOLO_CON_DESCUENTOS,
        $pisar_editados = false,
        $accion_sobre_compras = ArticleProviderDiscountHelper::ACCION_COMPRAS_SALTEAR
    ) {
        $resultado = [
            'total_articulos'      => 0,
            'creados'              => 0,
            'actualizados'         => 0,
            'respetados'           => 0,
            'al_dia'               => 0,
            'de_compra_salteados'  => 0,
            'de_compra_pisados'    => 0,
            'de_compra_agregados'  => 0,
        ];

        if (is_null($provider)) {
            return $resultado;
        }

        if (!ArticleProviderDiscountHelper::alcance_valido($alcance)) {
            $alcance = ArticleProviderDiscountHelper::ALCANCE_SOLO_CON_DESCUENTOS;
        }

        if (!ArticleProviderDiscountHelper::accion_sobre_compras_valida($accion_sobre_compras)) {
            $accion_sobre_compras = ArticleProviderDiscountHelper::ACCION_COMPRAS_SALTEAR;
        }

        $escaneo = ArticleProviderDiscountHelper::escanear_articulos_del_proveedor($provider);

        $resultado['total_articulos'] = $escaneo['total_articulos'];
        $resultado['al_dia']          = count($escaneo['al_dia']);

        if (!$escaneo['hay_descuentos_en_la_ficha']) {
            return $resultado;
        }

        $owner_user = User::find($provider->user_id);

        if ($alcance === ArticleProviderDiscountHelper::ALCANCE_TODOS) {

            $items = [];

            foreach ($escaneo['sin_descuentos'] as $article_id) {
                $items[] = ['article_id' => $article_id, 'ids_a_barrer' => [], 'mostrar_en_online' => 0];
            }

            $resultado['creados'] = count($this->aplicar_ficha_en_lote_como_hoy($provider, $items, $owner_user));
        }

        $items = [];

        foreach ($escaneo['desactualizados'] as $article_id) {
            $items[] = ArticleProviderDiscountHelper::preparar_item_de_sincronizacion($escaneo, $article_id, false);
        }

        $resultado['actualizados'] += count($this->aplicar_ficha_en_lote_como_hoy($provider, $items, $owner_user));

        if ($pisar_editados) {

            $items = [];

            foreach ($escaneo['editados_a_mano'] as $article_id) {
                $items[] = ArticleProviderDiscountHelper::preparar_item_de_sincronizacion($escaneo, $article_id, false);
            }

            $resultado['actualizados'] += count($this->aplicar_ficha_en_lote_como_hoy($provider, $items, $owner_user));

        } else {
            $resultado['respetados'] = count($escaneo['editados_a_mano']);
        }

        if ($accion_sobre_compras === ArticleProviderDiscountHelper::ACCION_COMPRAS_SALTEAR) {

            $resultado['de_compra_salteados'] = count($escaneo['con_descuentos_de_compra']);

        } else {

            $barrer_todo = ($accion_sobre_compras === ArticleProviderDiscountHelper::ACCION_COMPRAS_PISAR);

            $items = [];

            foreach ($escaneo['con_descuentos_de_compra'] as $article_id) {
                $items[] = ArticleProviderDiscountHelper::preparar_item_de_sincronizacion($escaneo, $article_id, $barrer_todo);
            }

            $tocados = $this->aplicar_ficha_en_lote_como_hoy($provider, $items, $owner_user);

            if ($barrer_todo) {
                $resultado['de_compra_pisados'] = count($tocados);
            } else {
                $resultado['de_compra_agregados'] = count($tocados);
            }
        }

        return $resultado;
    }

    /**
     * 🔴 REFERENCIA: ArticleProviderDiscountHelper::propagar_a_articulos() de develop. Unicos cambios:
     * self:: -> la clase o la referencia.
     *
     * @return array
     */
    protected function propagar_como_hoy($provider, $pisar_editados = false, $user = null)
    {
        $resultado = ['actualizados' => 0, 'respetados' => 0];

        if (is_null($provider) || !ArticleProviderDiscountHelper::debe_aplicar_al_asignar($user)) {
            return $resultado;
        }

        $percentages_actuales = [];

        foreach ($provider->provider_discounts as $provider_discount) {

            $actual = ArticleProviderDiscountHelper::normalizar_porcentaje($provider_discount->percentage);
            if (!is_null($actual)) {
                $percentages_actuales[] = $actual;
            }
        }

        if (count($percentages_actuales) === 0) {
            return $resultado;
        }

        $articulos = ArticleDiscount::where('provider_id', $provider->id)
                                        ->select(ArticleProviderDiscountHelper::COLUMNAS_PARA_CLASIFICAR)
                                        ->get()
                                        ->groupBy('article_id');

        foreach ($articulos as $article_id => $tagueados) {

            $clase = ArticleProviderDiscountHelper::clasificar_articulo($tagueados, $percentages_actuales);

            if ($clase === 'al_dia') {
                continue;
            }

            if ($clase === 'editado_a_mano' && !$pisar_editados) {
                $resultado['respetados']++;
                continue;
            }

            $article = Article::find($article_id);

            if (is_null($article)) {
                continue;
            }

            $gobernados = collect($tagueados)->filter(function ($descuento) {
                return ArticleProviderDiscountHelper::gobernado_por_la_ficha($descuento);
            });

            $mostrar_en_online = 0;

            foreach ($gobernados as $descuento) {
                if ($descuento->show_in_online) {
                    $mostrar_en_online = 1;
                }
            }

            $ids_a_barrer = $gobernados->pluck('id')->all();

            DB::transaction(function () use ($article, $provider, $ids_a_barrer, $mostrar_en_online) {

                if (!count($ids_a_barrer)) {
                    return;
                }

                ArticleDiscount::whereIn('id', $ids_a_barrer)->delete();

                $this->create_tagged_discounts_como_hoy(
                    $article,
                    $provider->id,
                    $provider->provider_discounts,
                    $mostrar_en_online,
                    ArticleDiscount::ORIGEN_FICHA_PROVEEDOR
                );
            });

            $article->unsetRelation('article_discounts');

            ArticleHelper::setFinalPrice($article, $article->user_id);

            $resultado['actualizados']++;
        }

        return $resultado;
    }

    /* ------------------------------------------------------------------------------------------
     * Fotos y comparacion
     * ---------------------------------------------------------------------------------------- */

    /**
     * Las filas de `article_discounts` de cada articulo, en orden de id y sin el id: todas las demas
     * columnas (porcentaje, monto, tipo, proveedor, origen, nombre, show_in_online, editado_a_mano,
     * timestamps...). Una fila de mas, de menos, con otro valor o en otro orden se ve.
     *
     * @param  array $ids
     * @return array [article_id => [fila, fila, ...]]
     */
    protected function foto_de_descuentos(array $ids)
    {
        $foto = [];

        foreach ($ids as $id) {
            $foto[(int) $id] = [];
        }

        $filas = DB::table('article_discounts')
                    ->whereIn('article_id', $ids)
                    ->orderBy('article_id')
                    ->orderBy('id')
                    ->get();

        foreach ($filas as $fila) {

            $fila = (array) $fila;
            unset($fila['id']);

            $foto[(int) $fila['article_id']][] = $fila;
        }

        ksort($foto);

        return $foto;
    }

    /**
     * La foto del motor (A) mas la de los descuentos.
     *
     * @param  array $ids
     * @param  int   $marca
     * @return array
     */
    protected function foto_completa(array $ids, $marca)
    {
        $foto = $this->foto($ids, $marca);

        $foto['descuentos'] = $this->foto_de_descuentos($ids);

        return $foto;
    }

    /**
     * Corre dos caminos sobre la misma base —cada uno en su savepoint, con el reloj congelado en el
     * mismo instante— y afirma que dejan exactamente lo mismo.
     *
     * @param  array    $ids     Articulos a fotografiar.
     * @param  callable $hoy     La referencia. Devuelve lo que devuelva el camino (contadores).
     * @param  callable $nuevo   El camino nuevo.
     * @param  string   $que     Para los mensajes.
     * @param  array    $extras  Funciones extra de foto: [nombre => callable(): mixed] (por ejemplo,
     *                           el historial de una masiva).
     * @return array ['hoy' => resultado, 'nuevo' => resultado, 'foto' => foto del camino nuevo]
     */
    protected function comparar_caminos_de(array $ids, callable $hoy, callable $nuevo, $que, array $extras = [])
    {
        $marca = (int) DB::table('price_changes')->max('id');

        /* La referencia. */
        DB::beginTransaction();

        Carbon::setTestNow(self::AHORA);

        $resultado_hoy = call_user_func($hoy);

        $foto_hoy = $this->foto_completa($ids, $marca);

        foreach ($extras as $nombre => $extra) {
            $foto_hoy[$nombre] = call_user_func($extra);
        }

        Carbon::setTestNow();
        DB::rollBack();

        $this->limpiar_estado_del_proceso();

        /* El camino nuevo. */
        DB::beginTransaction();

        Carbon::setTestNow(self::AHORA);

        $resultado_nuevo = call_user_func($nuevo);

        $foto_nuevo = $this->foto_completa($ids, $marca);

        foreach ($extras as $nombre => $extra) {
            $foto_nuevo[$nombre] = call_user_func($extra);
        }

        Carbon::setTestNow();
        DB::rollBack();

        $this->limpiar_estado_del_proceso();

        foreach (['descuentos', 'articles', 'pivots', 'monedas', 'cambios'] as $parte) {
            $this->assertEquals(
                $foto_hoy[$parte],
                $foto_nuevo[$parte],
                $que . ': el camino nuevo dejo en `' . $parte . '` algo distinto del camino de hoy.'
            );
        }

        foreach (array_keys($extras) as $nombre) {
            $this->assertEquals($foto_hoy[$nombre], $foto_nuevo[$nombre], $que . ': ' . $nombre . ' distinto.');
        }

        $this->assertEquals($foto_hoy, $foto_nuevo, $que . ': la foto completa no coincide.');

        $this->assertEquals($resultado_hoy, $resultado_nuevo, $que . ': los contadores no coinciden.');

        return [
            'hoy'   => $resultado_hoy,
            'nuevo' => $resultado_nuevo,
            'foto'  => $foto_nuevo,
        ];
    }
}
