<?php

namespace Tests\Feature\Precios\DescuentosYMasivasEnLote;

use App\Http\Controllers\Helpers\article\ArticleProviderDiscountHelper;
use App\Models\Article;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;

/**
 * Mision `recalculo-precios-motor-rapido` (28/9/2026) — la PROPAGACION de descuentos del proveedor
 * (`PUT provider/{id}/propagar-descuentos`, sincronica en el request), que paso a escribir en bloque
 * con el motor de precios en vez de un Article::find(), una transaccion y un setFinalPrice() por
 * articulo.
 *
 * 🔴 LO QUE FIJA ESTE ARCHIVO: sobre los mismos datos, el camino nuevo deja la base EXACTAMENTE igual
 * que el de develop (propagar_como_hoy(), copiado textual en la base de estos tests) y devuelve los
 * mismos `actualizados` / `respetados`, que es el contrato con la SPA. Con y sin pisar los editados a
 * mano, con listas de precio y con tandas chicas.
 *
 * El escenario incluye lo que la propagacion NO toca (manuales, descuentos de compra, otros
 * proveedores) y los casos raros: un articulo de otro dueño, uno borrado desactualizado (se saltea y
 * no cuenta) y uno borrado editado a mano (cuenta como respetado igual que antes, porque se cuenta
 * antes de mirar si existe).
 *
 * Los numeros son la especificacion. 🔴 Esta prohibido ajustar un valor esperado para que coincida
 * con lo que devuelve el sistema: si un test queda en rojo, se corrige el codigo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promocion de constructor, readonly, enum ni #[...].
 *
 * @group costeo-precios
 */
class Propagacion_en_bloque_Test extends DescuentosYMasivasEnLoteTestCase
{
    /**
     * Ficha del proveedor: 15% y 5%. La preferencia `aplicar_descuentos_proveedor_al_asignar`
     * prendida (con la preferencia apagada la propagacion no hace nada, en los dos caminos).
     *
     * @param  array $flags
     * @param  bool  $con_listas
     * @return array
     */
    private function escenario(array $flags = [], $con_listas = false)
    {
        $otro_dueno = $this->crear_dueno(['percentage_gain' => 20]);

        $dueno = $this->crear_dueno(array_merge(['aplicar_descuentos_proveedor_al_asignar' => 1], $flags));

        if ($con_listas) {
            $this->crear_lista($dueno, 'Mayorista', 10, 1);
            $this->crear_lista($dueno, 'Minorista', 25, 2, [['percentage' => 3]]);
        }

        $provider = $this->crear_proveedor($dueno, ['percentage_gain' => 30]);
        $otro     = $this->crear_proveedor($dueno, ['percentage_gain' => 10]);

        $this->descuento_de_la_ficha($provider, 15, 'Bonif general');
        $this->descuento_de_la_ficha($provider, 5, 'Pronto pago');

        $a = [];

        $desactualizado = $this->crear_articulo($dueno, ['cost' => 1234.56, 'provider_id' => $provider->id]);
        $this->copia_de_la_ficha($desactualizado, $provider, 10, ['show_in_online' => 1]);
        $a['desactualizado_en_la_tienda'] = $desactualizado->id;

        /* Con monto de compra: se conserva y queda PRIMERO (id viejo), la ficha se rehace despues. */
        $con_compra = $this->crear_articulo($dueno, ['cost' => 2000, 'provider_id' => $provider->id]);
        $this->descuento_de_compra($con_compra, $provider, null, 100);
        $this->copia_de_la_ficha($con_compra, $provider, 10);
        $a['desactualizado_con_compra'] = $con_compra->id;

        $con_manual = $this->crear_articulo($dueno, ['cost' => 500, 'provider_id' => $provider->id]);
        $this->descuento_manual($con_manual, 7);
        $this->copia_de_la_ficha($con_manual, $provider, 15);
        $a['desactualizado_con_manual'] = $con_manual->id;

        $editado = $this->crear_articulo($dueno, ['cost' => 900, 'provider_id' => $provider->id]);
        $this->copia_de_la_ficha($editado, $provider, 12, ['editado_a_mano' => 1]);
        $a['editado_a_mano'] = $editado->id;

        $al_dia = $this->crear_articulo($dueno, ['cost' => 800, 'provider_id' => $provider->id]);
        $this->copia_de_la_ficha($al_dia, $provider, 15);
        $this->copia_de_la_ficha($al_dia, $provider, 5);
        $a['al_dia'] = $al_dia->id;

        /* Del proveedor pero sin nada tagueado: la propagacion no lo ve. */
        $a['sin_descuentos'] = $this->crear_articulo($dueno, ['cost' => 1000, 'provider_id' => $provider->id])->id;

        $de_otro_proveedor = $this->crear_articulo($dueno, ['cost' => 600, 'provider_id' => $otro->id]);
        $this->copia_de_la_ficha($de_otro_proveedor, $provider, 10);
        $a['de_otro_proveedor'] = $de_otro_proveedor->id;

        $de_otro_dueno = $this->crear_articulo($otro_dueno, ['cost' => 950]);
        $this->copia_de_la_ficha($de_otro_dueno, $provider, 10);
        $a['de_otro_dueno'] = $de_otro_dueno->id;

        $borrado = $this->crear_articulo($dueno, ['cost' => 700, 'provider_id' => $provider->id]);
        $this->copia_de_la_ficha($borrado, $provider, 10);
        $a['borrado_desactualizado'] = $borrado->id;

        $borrado_editado = $this->crear_articulo($dueno, ['cost' => 710, 'provider_id' => $provider->id]);
        $this->copia_de_la_ficha($borrado_editado, $provider, 11, ['editado_a_mano' => 1]);
        $a['borrado_editado'] = $borrado_editado->id;

        $del_dueno = array_values(array_diff($a, [$a['de_otro_dueno']]));
        $this->calentar($del_dueno, $dueno->id);
        $this->calentar([$a['de_otro_dueno']], $otro_dueno->id);

        Article::find($a['borrado_desactualizado'])->delete();
        Article::find($a['borrado_editado'])->delete();

        return [
            'dueno'     => $dueno,
            'provider'  => $provider,
            'articulos' => $a,
            'ids'       => array_values($a),
        ];
    }

    /**
     * @param  array  $e
     * @param  bool   $pisar_editados
     * @param  string $que
     * @return array
     */
    private function comparar_propagacion(array $e, $pisar_editados, $que)
    {
        $provider_id = $e['provider']->id;

        return $this->comparar_caminos_de(
            $e['ids'],
            function () use ($provider_id, $pisar_editados) {
                return $this->propagar_como_hoy(Provider::find($provider_id), $pisar_editados);
            },
            function () use ($provider_id, $pisar_editados) {
                return ArticleProviderDiscountHelper::propagar_a_articulos(Provider::find($provider_id), $pisar_editados);
            },
            $que
        );
    }

    /**
     * @test
     */
    public function sin_pisar_editados_deja_la_misma_base_y_la_misma_respuesta_que_hoy()
    {
        $e = $this->escenario();

        $r = $this->comparar_propagacion($e, false, 'Sin pisar editados');

        $this->assertSame(
            ['actualizados' => 5, 'respetados' => 2],
            $r['nuevo'],
            'Los tres desactualizados del proveedor, el de otro proveedor y el de otro dueño; '.
            'respetados el editado y el editado borrado (se cuenta antes de mirar si existe).'
        );

        /* El descuento de compra se conserva y queda antes que los rehechos de la ficha. */
        $filas = $r['foto']['descuentos'][$e['articulos']['desactualizado_con_compra']];

        $this->assertSame('compra', $filas[0]['origen'], 'La bonificacion de la compra sigue y va primero.');
        $this->assertCount(3, $filas);
    }

    /**
     * @test
     */
    public function pisando_editados_deja_la_misma_base_y_la_misma_respuesta_que_hoy()
    {
        $e = $this->escenario();

        $r = $this->comparar_propagacion($e, true, 'Pisando editados');

        $this->assertSame(['actualizados' => 6, 'respetados' => 0], $r['nuevo']);
    }

    /**
     * @test
     */
    public function con_listas_de_precio_deja_la_misma_base_que_hoy()
    {
        $e = $this->escenario(['listas_de_precio' => 1], true);

        $r = $this->comparar_propagacion($e, false, 'Con listas');

        $this->assertNotEmpty($r['foto']['pivots']);
        $this->assertNotEmpty($r['foto']['cambios']);
    }

    /**
     * @test
     */
    public function con_tandas_de_a_dos_deja_la_misma_base_que_hoy()
    {
        config(['app.SINCRONIZAR_DESCUENTOS_PROVEEDOR_LOTE' => 2]);

        $e = $this->escenario(['redondear_de_a_50' => 1]);

        $this->comparar_propagacion($e, true, 'Tandas de a 2');
    }

    /**
     * 🔴 El request ya no hace un Article::find() por articulo: la existencia se resuelve con un
     * whereIn, y los articulos se leen UNA vez por tanda (la lectura del motor).
     *
     * @test
     */
    public function los_articulos_se_leen_por_tanda_y_no_de_a_uno()
    {
        config(['app.SINCRONIZAR_DESCUENTOS_PROVEEDOR_LOTE' => 2]);

        $dueno = $this->crear_dueno(['aplicar_descuentos_proveedor_al_asignar' => 1]);
        $provider = $this->crear_proveedor($dueno);
        $this->descuento_de_la_ficha($provider, 15);

        $ids = [];

        for ($i = 1; $i <= 5; $i++) {
            $article = $this->crear_articulo($dueno, ['cost' => 1000 + $i, 'provider_id' => $provider->id]);
            $this->copia_de_la_ficha($article, $provider, 10);
            $ids[] = $article->id;
        }

        $this->calentar($ids, $dueno->id);

        DB::enableQueryLog();

        $resultado = ArticleProviderDiscountHelper::propagar_a_articulos($provider->fresh());

        $consultas = DB::getQueryLog();

        DB::disableQueryLog();

        $this->assertSame(['actualizados' => 5, 'respetados' => 0], $resultado);

        $de_a_uno = array_filter($consultas, function ($q) {
            return stripos($q['query'], 'from `articles` where `articles`.`id` = ?') !== false;
        });

        $en_bloque = array_filter($consultas, function ($q) {
            return stripos($q['query'], 'from `articles`') !== false && strpos($q['query'], ' in (') !== false;
        });

        $this->assertCount(0, $de_a_uno, 'Ningun Article::find() por articulo.');
        $this->assertCount(4, $en_bloque, 'Una consulta de existencia (hasta 1.000 ids) y una lectura del motor por cada una de las 3 tandas.');
    }
}
