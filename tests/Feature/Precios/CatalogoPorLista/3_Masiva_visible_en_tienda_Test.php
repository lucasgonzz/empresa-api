<?php

namespace Tests\Feature\Precios\CatalogoPorLista;

use App\Models\MasiveUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    /**
     * Cuenta, de acá en adelante, las consultas con las que el helper valida que una lista exista y
     * sea del dueño (`select exists(select * from price_types where id = ? and user_id = ?)`).
     *
     * @return \stdClass  Con la propiedad `cantidad`, que se puede volver a poner en 0.
     */
    protected function contador_de_validaciones_de_lista()
    {
        $contador           = new \stdClass();
        $contador->cantidad = 0;

        DB::listen(function ($consulta) use ($contador) {
            if (preg_match('/exists\(select \* from `price_types` where `id` = \? and `user_id` = \?/', $consulta->sql) === 1) {
                $contador->cantidad++;
            }
        });

        return $contador;
    }

    /**
     * Crea N artículos del dueño y devuelve sus ids.
     *
     * @param  int $cantidad
     * @return int[]
     */
    protected function crear_articulos($cantidad)
    {
        $ids = [];

        for ($i = 0; $i < $cantidad; $i++) {
            $ids[] = $this->crear_articulo($this->dueno, ['cost' => 100])->id;
        }

        return $ids;
    }

    /**
     * 🔴 B2 de la revisión independiente (6/10/2026): la lista se valida UNA vez por corrida y no una
     * vez por artículo. El docblock de MasiveUpdateHelper::process_update() insiste en no hacer
     * consultas por artículo (una masiva llega a 3000), y la validación hacía una por artículo y por
     * clave, sumada a las que ya necesita cada artículo (el valor viejo exacto para poder revertir).
     * Vale para aplicar y para revertir, y lo que se escribe no cambia.
     *
     * @return void
     */
    public function test_la_lista_se_valida_una_sola_vez_por_corrida_y_no_por_articulo()
    {
        $ids = $this->crear_articulos(5);

        $validaciones = $this->contador_de_validaciones_de_lista();

        $masiva = $this->masiva($ids, [$this->clave($this->mayorista->id, 1)]);

        $this->assertSame(1, $validaciones->cantidad, 'Aplicar: la lista se valida una vez, no cinco.');

        // Lo escrito es lo mismo de siempre: los cinco habilitados y los cinco cambios registrados.
        $this->assertSame(5, (int) $masiva->changes_count);
        foreach ($ids as $id) {
            $this->assertSame(1, $this->visible($id, $this->mayorista->id));
        }

        $validaciones->cantidad = 0;

        $this->revertir($masiva);

        $this->assertSame(1, $validaciones->cantidad, 'Revertir: la lista se valida una vez, no cinco.');

        foreach ($ids as $id) {
            $this->assertNull($this->visible($id, $this->mayorista->id), 'Revertir los deja como estaban: NULL.');
        }
    }

    /**
     * B2: una lista ajena deja UN aviso en el log por corrida, no uno por artículo. Con 3000
     * artículos eran 3000 líneas idénticas. Lo que se escribe no cambia: nada.
     *
     * @return void
     */
    public function test_una_lista_ajena_deja_un_solo_aviso_aunque_la_masiva_toque_varios_articulos()
    {
        $ids = $this->crear_articulos(4);

        Log::spy();

        $masiva = $this->masiva($ids, [$this->clave($this->lista_ajena->id, 1)]);

        $this->assertSame(0, (int) $masiva->changes_count);

        foreach ($ids as $id) {
            $this->assertCount(0, $this->filas($id, $this->lista_ajena->id), 'No se ata una lista ajena.');
        }

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($mensaje) {
                return strpos((string) $mensaje, 'CatalogoPorListaHelper') !== false
                    && strpos((string) $mensaje, 'no existe o no es del dueño') !== false;
            })
            ->once();
    }

    /**
     * B2, en la reversión: si la lista se borró entre la masiva y su reversión, no hay nada que
     * restaurar. La reversión termina bien, saltea la clave en todos los artículos y deja UN solo
     * aviso (con un registro por artículo eran tantas líneas como artículos).
     *
     * @return void
     */
    public function test_revertir_con_la_lista_borrada_entre_medio_termina_bien_con_un_solo_aviso()
    {
        $ids = $this->crear_articulos(3);

        $masiva = $this->masiva($ids, [$this->clave($this->mayorista->id, 1)]);

        $this->assertSame(3, (int) $masiva->changes_count);

        // La lista se borra (su destroy() también suelta sus filas del pivote).
        $this->deleteJson('api/price-type/' . $this->mayorista->id)->assertStatus(200);

        Log::spy();

        $this->revertir($masiva);

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($mensaje) {
                return strpos((string) $mensaje, 'CatalogoPorListaHelper') !== false
                    && strpos((string) $mensaje, 'no se revierte') !== false;
            })
            ->once();

        foreach ($ids as $id) {
            $this->assertCount(0, $this->filas($id, $this->mayorista->id), 'Sin lista no hay filas que restaurar.');
        }
    }

    /**
     * Un artículo de OTRO comercio en la selección manual (la masiva resuelve los ids sin mirar el
     * dueño) no se toca: no se le ata ni se le habilita la lista. El resto de la selección se aplica
     * igual, y el aviso del log sale una sola vez por corrida.
     *
     * @return void
     */
    public function test_un_articulo_de_otro_dueno_en_la_seleccion_no_se_toca()
    {
        $propio = $this->crear_articulo($this->dueno, ['cost' => 100]);
        $ajeno  = $this->crear_articulo($this->otro_dueno, ['cost' => 100]);
        $ajeno2 = $this->crear_articulo($this->otro_dueno, ['cost' => 100]);

        Log::spy();

        $masiva = $this->masiva([$propio->id, $ajeno->id, $ajeno2->id], [$this->clave($this->mayorista->id, 1)]);

        $this->assertSame(1, (int) $masiva->changes_count, 'Solo el artículo propio cambió.');
        $this->assertSame(1, $this->visible($propio->id, $this->mayorista->id));
        $this->assertCount(0, $this->filas($ajeno->id, $this->mayorista->id), 'Al artículo ajeno no se le ata la lista.');
        $this->assertCount(0, $this->filas($ajeno2->id, $this->mayorista->id));

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($mensaje) {
                return strpos((string) $mensaje, 'CatalogoPorListaHelper') !== false
                    && strpos((string) $mensaje, 'otro dueño') !== false;
            })
            ->once();
    }

    /**
     * Dos listas en la misma masiva: cada una se valida UNA vez (2 en total, no 2 por artículo), las
     * dos se escriben y las dos se revierten, cada una con su valor viejo exacto.
     *
     * @return void
     */
    public function test_dos_listas_en_la_misma_masiva_se_validan_una_vez_cada_una_y_se_revierten()
    {
        $ids = $this->crear_articulos(3);

        // Una de las dos arranca habilitada en 0 (no en NULL): el old exacto de cada lista es propio.
        $this->atar($ids[0], $this->minorista->id, 0, 30);

        $validaciones = $this->contador_de_validaciones_de_lista();

        $masiva = $this->masiva($ids, [
            $this->clave($this->mayorista->id, 1),
            $this->clave($this->minorista->id, 1),
        ]);

        $this->assertSame(2, $validaciones->cantidad, 'Una validación por lista, no por artículo.');
        $this->assertSame(6, (int) $masiva->changes_count);

        foreach ($ids as $id) {
            $this->assertSame(1, $this->visible($id, $this->mayorista->id));
            $this->assertSame(1, $this->visible($id, $this->minorista->id));
        }

        $this->revertir($masiva);

        foreach ($ids as $id) {
            $this->assertNull($this->visible($id, $this->mayorista->id), 'Mayorista vuelve a NULL.');
        }

        $this->assertSame(0, $this->visible($ids[0], $this->minorista->id), 'Minorista vuelve al 0 que tenía el primero.');
        $this->assertNull($this->visible($ids[1], $this->minorista->id));
        $this->assertNull($this->visible($ids[2], $this->minorista->id));
    }
}
