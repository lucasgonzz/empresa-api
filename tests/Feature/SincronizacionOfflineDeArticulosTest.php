<?php

namespace Tests\Feature;

use App\Models\Article;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión ingreso-sin-internet (23/10/2026): los dos endpoints con los que la SPA pone al día el
 * caché de artículos de VENDER sin conexión (`offline/sync_articles.js`).
 *
 *  - `GET api/article/index/from-status` devuelve `sincronizado_hasta`: la hora del SERVIDOR
 *    tomada antes de la consulta, que la SPA usa como `updated_after` de la próxima vuelta en lugar
 *    del reloj del equipo al terminar (que perdía artículos). `models` no cambia.
 *  - `GET api/article/index/eliminados` con `updated_after` trae, además de los borrados, los
 *    artículos vivos que dejaron de ser vendibles (inactivos o insumos) después de la marca: la SPA
 *    saca del caché todo lo que llega ahí, y esos quedaban para siempre. Sin `updated_after`, solo
 *    los borrados, como siempre.
 *
 * Ver `SincronizacionOfflineArticulosHelper`. Fixture de TestingFerreteriaSeeder, artículos propios
 * `zz` adentro de la transacción del test; los `updated_at` se fijan por DB para no depender del
 * reloj.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class SincronizacionOfflineDeArticulosTest extends EmpresaTestCase
{
    /**
     * Artículo propio del comercio del fixture, con `updated_at` fijado a mano.
     *
     * @param  string       $nombre
     * @param  array        $atributos
     * @param  \Carbon\Carbon  $updated_at
     * @return \App\Models\Article
     */
    protected function articulo_con_updated_at($nombre, $atributos, $updated_at)
    {
        $article = Article::create(array_merge([
            'name'        => $nombre,
            'user_id'     => auth()->user()->id,
            'final_price' => 100,
            'status'      => 'active',
        ], $atributos));

        DB::table('articles')->where('id', $article->id)->update(['updated_at' => $updated_at->format('Y-m-d H:i:s')]);

        return $article;
    }

    /**
     * Los ids que devuelve `eliminados` recorriendo TODAS las páginas, como hace la SPA.
     *
     * @param  array  $params
     * @return array
     */
    protected function ids_de_eliminados($params)
    {
        $ids  = [];
        $page = 1;

        do {
            $res = $this->getJson('api/article/index/eliminados?'.http_build_query(array_merge($params, [
                'page'     => $page,
                'per_page' => 2000,
            ])))->assertStatus(200);

            foreach ($res->json('models.data') as $fila) {
                $ids[] = (int) $fila['id'];
            }

            $last_page = (int) $res->json('models.last_page');
            $page++;

        } while ($page <= $last_page);

        return $ids;
    }

    /**
     * `from-status` trae `sincronizado_hasta` con formato `Y-m-d H:i:s`, en la zona de la app y
     * tomada antes de responder (un segundo para atrás de margen), y `models` sigue siendo el
     * paginador de siempre.
     *
     * @return void
     */
    public function test_from_status_trae_sincronizado_hasta_con_la_hora_del_servidor()
    {
        $antes = Carbon::now(config('app.timezone'))->subSeconds(2);

        $res = $this->getJson('api/article/index/from-status?per_page=5')->assertStatus(200);

        $despues = Carbon::now(config('app.timezone'));

        $marca = $res->json('sincronizado_hasta');

        $this->assertIsString($marca);
        $this->assertMatchesRegularExpression('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/', $marca);

        $leida = Carbon::createFromFormat('Y-m-d H:i:s', $marca, config('app.timezone'));

        $this->assertTrue($leida->between($antes, $despues), 'La marca tiene que ser la hora del servidor de este request (quedó '.$marca.').');
        $this->assertTrue($leida->lessThan($despues), 'La marca va un segundo para atrás de cuando se tomó: nunca puede ser posterior a la respuesta.');

        // `models` es el paginador de siempre.
        $this->assertNotNull($res->json('models.current_page'));
        $this->assertNotNull($res->json('models.last_page'));
        $this->assertIsArray($res->json('models.data'));
    }

    /**
     * Con `updated_after`, `eliminados` trae: el borrado después de la marca, el pasado a inactivo
     * después de la marca y el pasado a insumo después de la marca. No trae: el activo tocado
     * después de la marca (sigue siendo vendible), el inactivo tocado ANTES de la marca, ni el
     * borrado antes de la marca.
     *
     * @return void
     */
    public function test_eliminados_con_marca_trae_los_que_dejaron_de_ser_vendibles()
    {
        $marca = Carbon::now(config('app.timezone'))->subMinutes(10);

        $despues = $marca->copy()->addMinutes(5);
        $antes   = $marca->copy()->subHour();

        $inactivo       = $this->articulo_con_updated_at('zz Sync pasado a inactivo', ['status' => 'inactive'], $despues);
        $insumo         = $this->articulo_con_updated_at('zz Sync pasado a insumo', ['es_insumo' => 1], $despues);
        $de_pedido      = $this->articulo_con_updated_at('zz Sync de pedido a proveedor', ['status' => 'from_provider_order'], $despues);
        $activo         = $this->articulo_con_updated_at('zz Sync activo tocado', ['es_insumo' => 0], $despues);
        $activo_null    = $this->articulo_con_updated_at('zz Sync activo insumo null', [], $despues);
        $inactivo_viejo = $this->articulo_con_updated_at('zz Sync inactivo viejo', ['status' => 'inactive'], $antes);
        $borrado        = $this->articulo_con_updated_at('zz Sync borrado', [], $antes);
        $borrado_viejo  = $this->articulo_con_updated_at('zz Sync borrado viejo', [], $antes);

        DB::table('articles')->where('id', $borrado->id)->update(['deleted_at' => $despues->format('Y-m-d H:i:s')]);
        DB::table('articles')->where('id', $borrado_viejo->id)->update(['deleted_at' => $antes->format('Y-m-d H:i:s')]);

        $ids = $this->ids_de_eliminados(['updated_after' => $marca->format('Y-m-d H:i:s')]);

        $this->assertContains($inactivo->id, $ids, 'Un artículo pasado a inactivo después de la marca tiene que salir del caché.');
        $this->assertContains($insumo->id, $ids, 'Un artículo pasado a insumo después de la marca tiene que salir del caché.');
        $this->assertContains($de_pedido->id, $ids, 'Cualquier status que no sea active es no vendible para index().');
        $this->assertContains($borrado->id, $ids, 'Los borrados después de la marca siguen viniendo.');

        $this->assertNotContains($activo->id, $ids, 'Un artículo activo y no insumo sigue siendo vendible: no se saca del caché.');
        $this->assertNotContains($activo_null->id, $ids, 'es_insumo null es vendible para index(): no se saca del caché.');
        $this->assertNotContains($inactivo_viejo->id, $ids, 'Un inactivo tocado antes de la marca ya salió en una vuelta anterior.');
        $this->assertNotContains($borrado_viejo->id, $ids, 'Un borrado antes de la marca ya salió en una vuelta anterior.');

        $this->assertSame(count($ids), count(array_unique($ids)), 'El orden por id no puede repetir filas entre páginas.');
    }

    /**
     * Sin `updated_after` (primera sincronización), `eliminados` sigue trayendo SOLO los borrados:
     * los inactivos vivos no vienen.
     *
     * @return void
     */
    public function test_eliminados_sin_marca_trae_solo_los_borrados()
    {
        $ahora = Carbon::now(config('app.timezone'));

        $inactivo = $this->articulo_con_updated_at('zz Sync sin marca inactivo', ['status' => 'inactive'], $ahora);
        $borrado  = $this->articulo_con_updated_at('zz Sync sin marca borrado', [], $ahora);

        DB::table('articles')->where('id', $borrado->id)->update(['deleted_at' => $ahora->format('Y-m-d H:i:s')]);

        $ids = $this->ids_de_eliminados([]);

        $this->assertContains($borrado->id, $ids);
        $this->assertNotContains($inactivo->id, $ids, 'Sin marca el endpoint devuelve solo borrados, como siempre.');
    }
}
