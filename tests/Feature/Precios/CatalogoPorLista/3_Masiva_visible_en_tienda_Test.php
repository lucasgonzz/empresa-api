<?php

namespace Tests\Feature\Precios\CatalogoPorLista;

use App\Models\MasiveUpdate;
use Illuminate\Support\Facades\DB;

/**
 * "Visible en la tienda, lista X" en la actualización masiva de artículos (misión
 * catalogo-por-lista-tienda, 5/10/2026, contrato C2): `PUT api/update/article` con una entrada
 * `{type: checkbox, key: visible_en_tienda_lista_{id}, value: 0|1}` en `update_form`, y su
 * reversión por `POST api/masive-update/{id}/revert`.
 *
 * Lo que se fija, contra los endpoints reales (QUEUE sync: el job corre dentro del request):
 *  - Sí habilita (y ata la lista si el artículo no la tenía), No deshabilita.
 *  - El margen y el resto del pivote no se tocan.
 *  - 🔴 Revertir restaura el valor EXACTO de antes (NULL vuelve a NULL, no a 0). Sin la rama de
 *    reversión, revert_article_pivot_changes() hacía `$model->visible_en_tienda_lista_X = ...` y
 *    reventaba con "Unknown column".
 *  - Un "No" sobre un artículo que nunca se habilitó no es un cambio (NULL y 0 son lo mismo).
 *  - Una lista de otro comercio, o inexistente, se ignora sin romper la masiva.
 *  - La clave nueva convive con las genéricas en el mismo formulario.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group catalogo-por-lista
 */
class Masiva_visible_en_tienda_Test extends CatalogoPorListaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_comercios();
    }

    /**
     * Lanza la masiva por selección manual y devuelve la MasiveUpdate ya procesada.
     *
     * @param  int[] $ids
     * @param  array $update_form
     * @return \App\Models\MasiveUpdate
     */
    protected function masiva(array $ids, array $update_form)
    {
        $respuesta = $this->putJson('api/update/article', [
            'from_filter' => 0,
            'models_id'   => $ids,
            'update_form' => $update_form,
        ])->assertStatus(200);

        $masiva = MasiveUpdate::find($respuesta->json('masive_update_id'));

        $this->assertNotNull($masiva, 'No quedó registrada la masiva.');
        $this->assertSame('completed', $masiva->status, 'La masiva no terminó: ' . $masiva->error_message);

        return $masiva;
    }

    /**
     * La entrada del formulario de la masiva para una lista.
     *
     * @param  int $price_type_id
     * @param  int $valor
     * @return array
     */
    protected function clave($price_type_id, $valor)
    {
        return [
            'type'  => 'checkbox',
            'key'   => 'visible_en_tienda_lista_' . $price_type_id,
            'value' => $valor,
        ];
    }

    /**
     * Revierte una masiva y devuelve la original releída.
     *
     * @param  \App\Models\MasiveUpdate $masiva
     * @return \App\Models\MasiveUpdate
     */
    protected function revertir($masiva)
    {
        $this->postJson('api/masive-update/' . $masiva->id . '/revert')->assertStatus(200);

        $masiva = $masiva->fresh();

        $this->assertSame('reverted', $masiva->status, 'La reversión no terminó.');

        return $masiva;
    }

    /**
     * Sí: habilita a uno que estaba en NULL (sin tocarle el margen propio) y a uno que no tenía la
     * lista atada (se la ata). Revertir los deja como estaban: en NULL.
     *
     * @return void
     */
    public function test_si_habilita_y_revertir_vuelve_a_null()
    {
        $con_fila = $this->crear_articulo($this->dueno, ['cost' => 100]);
        $sin_fila = $this->crear_articulo($this->dueno, ['cost' => 100]);

        $this->atar($con_fila->id, $this->mayorista->id, null, 55);

        $masiva = $this->masiva([$con_fila->id, $sin_fila->id], [$this->clave($this->mayorista->id, 1)]);

        $this->assertSame(2, (int) $masiva->changes_count);
        $this->assertSame(1, $this->visible($con_fila->id, $this->mayorista->id));
        $this->assertSame(1, $this->visible($sin_fila->id, $this->mayorista->id), 'Sin la lista atada, se ata habilitada.');
        $this->assertEquals(55, (float) $this->filas($con_fila->id, $this->mayorista->id)->first()->percentage, 'El margen propio no se toca.');

        // El historial guarda el old exacto para revertir.
        $cambios = json_decode(DB::table('masive_update_article')->where('masive_update_id', $masiva->id)->where('article_id', $con_fila->id)->value('changes_json'), true);
        $this->assertArrayHasKey('visible_en_tienda_lista_' . $this->mayorista->id, $cambios);
        $this->assertNull($cambios['visible_en_tienda_lista_' . $this->mayorista->id]['old']);
        $this->assertSame(1, $cambios['visible_en_tienda_lista_' . $this->mayorista->id]['new']);

        $this->revertir($masiva);

        $this->assertNull($this->visible($con_fila->id, $this->mayorista->id), 'Revertir devuelve el NULL, no un 0.');
        $this->assertNull($this->visible($sin_fila->id, $this->mayorista->id));
        $this->assertEquals(55, (float) $this->filas($con_fila->id, $this->mayorista->id)->first()->percentage);
    }

    /**
     * No: deshabilita al habilitado; sobre uno en NULL no es un cambio. Revertir vuelve el 1.
     *
     * @return void
     */
    public function test_no_deshabilita_y_revertir_vuelve_a_uno()
    {
        $habilitado = $this->crear_articulo($this->dueno, ['cost' => 100]);
        $nunca      = $this->crear_articulo($this->dueno, ['cost' => 100]);

        $this->atar($habilitado->id, $this->mayorista->id, 1, 20);
        $this->atar($nunca->id, $this->mayorista->id, null, 20);

        $masiva = $this->masiva([$habilitado->id, $nunca->id], [$this->clave($this->mayorista->id, 0)]);

        $this->assertSame(1, (int) $masiva->changes_count, 'El "No" sobre un NULL no cuenta como cambio.');
        $this->assertSame(0, $this->visible($habilitado->id, $this->mayorista->id));
        $this->assertNull($this->visible($nunca->id, $this->mayorista->id), 'El NULL no se reescribe a 0.');

        $this->revertir($masiva);

        $this->assertSame(1, $this->visible($habilitado->id, $this->mayorista->id), 'Revertir vuelve a habilitarlo.');
        $this->assertNull($this->visible($nunca->id, $this->mayorista->id));
    }

    /**
     * Una lista de OTRO comercio, o una que no existe: no se escribe nada y la masiva termina bien.
     *
     * @return void
     */
    public function test_una_lista_ajena_o_inexistente_se_ignora()
    {
        $articulo = $this->crear_articulo($this->dueno, ['cost' => 100]);

        $inexistente = (int) DB::table('price_types')->max('id') + 1000;

        $masiva = $this->masiva([$articulo->id], [
            $this->clave($this->lista_ajena->id, 1),
            $this->clave($inexistente, 1),
        ]);

        $this->assertSame(0, (int) $masiva->changes_count);
        $this->assertCount(0, $this->filas($articulo->id, $this->lista_ajena->id), 'No se ata una lista ajena.');
        $this->assertCount(0, $this->filas($articulo->id, $inexistente));
    }

    /**
     * La clave nueva convive con una genérica (`online`, checkbox de `articles`) en el mismo
     * formulario: las dos se aplican y las dos se revierten.
     *
     * @return void
     */
    public function test_convive_con_las_claves_genericas_y_se_revierten_juntas()
    {
        $articulo = $this->crear_articulo($this->dueno, ['cost' => 100, 'online' => 0]);

        $this->atar($articulo->id, $this->mayorista->id, 0, 20);

        $masiva = $this->masiva([$articulo->id], [
            ['type' => 'checkbox', 'key' => 'online', 'value' => 1],
            $this->clave($this->mayorista->id, 1),
        ]);

        $this->assertSame(2, (int) $masiva->changes_count);
        $this->assertSame(1, (int) DB::table('articles')->where('id', $articulo->id)->value('online'));
        $this->assertSame(1, $this->visible($articulo->id, $this->mayorista->id));

        $this->revertir($masiva);

        $this->assertSame(0, (int) DB::table('articles')->where('id', $articulo->id)->value('online'));
        $this->assertSame(0, $this->visible($articulo->id, $this->mayorista->id), 'Vuelve al 0 que tenía, no a NULL.');
    }

    /**
     * Si el artículo tiene la lista atada DOS veces (el pivote no tiene índice único), la masiva
     * escribe las dos filas, y la reversión también.
     *
     * @return void
     */
    public function test_con_la_lista_atada_dos_veces_escribe_las_dos_filas()
    {
        $articulo = $this->crear_articulo($this->dueno, ['cost' => 100]);

        $this->atar($articulo->id, $this->mayorista->id, null, 20);
        $this->atar($articulo->id, $this->mayorista->id, null, 20);

        $masiva = $this->masiva([$articulo->id], [$this->clave($this->mayorista->id, 1)]);

        $valores = $this->filas($articulo->id, $this->mayorista->id)->pluck('visible_en_tienda')->map(function ($v) {
            return is_null($v) ? null : (int) $v;
        })->all();

        $this->assertSame([1, 1], $valores);

        $this->revertir($masiva);

        $valores = $this->filas($articulo->id, $this->mayorista->id)->pluck('visible_en_tienda')->all();

        $this->assertSame([null, null], $valores);
    }
}
