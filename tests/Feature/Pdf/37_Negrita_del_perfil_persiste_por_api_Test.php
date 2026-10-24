<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\CatalogHeaderLayoutHelper;
use App\Http\Controllers\Helpers\PdfNegritaHelper;
use App\Models\PdfColumnOption;
use App\Models\PdfColumnProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * La negrita que el usuario pide en el perfil de PDF viaja por la API real de perfiles (el único
 * camino que usa la SPA), en tres lugares (misión pdf-negrita-perfil, 20/10/2026):
 *
 *  A. `bold_columns`: ids de `pdf_column_options` cuyo VALOR va en negrita.
 *  B. `catalog_header_layout.izquierda|derecha[].bold`: el valor de un renglón del encabezado del catálogo.
 *  C. `header_layout.bold`: keys de chips del encabezado de venta / presupuesto / pedido.
 *
 * Cubre la clase de bug que ya pasó con show_client_description: un campo que está en la
 * migración y en el cast del modelo pero que store() / update() descartan en silencio (200 y el
 * valor nunca llega a la base), y su espejo, un update parcial que pisa lo guardado.
 *
 * @group pdf-negrita-perfil
 */
class Negrita_del_perfil_persiste_por_api_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario autenticado de la suite (mismo patrón que las suites del catálogo de artículos).
     *
     * @return \App\Models\User
     */
    protected function autenticar()
    {
        $owner = User::find(500);
        if (is_null($owner)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($owner, 'web');

        return $owner;
    }

    /**
     * Las opciones de columna de VENTA (la base de testing viene sembrada con ellas).
     *
     * @param int $cantidad
     * @return \Illuminate\Support\Collection
     */
    protected function opciones_de_venta($cantidad = 4)
    {
        $opciones = PdfColumnOption::where('model_name', 'sale')->orderBy('id')->take($cantidad)->get();

        $this->assertGreaterThanOrEqual($cantidad, $opciones->count(), 'La base de testing no tiene opciones de columna de venta.');

        return $opciones;
    }

    /**
     * Una opción de columna de OTRO tipo de diseño (artículo): un id válido pero ajeno a venta.
     * Si la base no tiene ninguna, se crea dentro de la transacción del test.
     *
     * @return \App\Models\PdfColumnOption
     */
    protected function opcion_ajena_de_articulo()
    {
        $option = PdfColumnOption::where('model_name', 'article')->first();

        if (! is_null($option)) {
            return $option;
        }

        return PdfColumnOption::create([
            'model_name' => 'article',
            'name' => 'zz nombre',
            'label' => 'Nombre',
            'value_resolver' => 'article_name',
            'default_width' => 120,
        ]);
    }

    /**
     * El cuerpo de un POST de perfil de venta, tal como lo manda la SPA.
     *
     * @param array $extra
     * @return array
     */
    protected function cuerpo_de_venta(array $extra = [])
    {
        $opciones = $this->opciones_de_venta(2);

        return array_merge([
            'model_name' => 'sale',
            'name' => 'zz Negrita '.uniqid(),
            'paper_width_mm' => 210,
            'printable_width_mm' => 210,
            'margin_mm' => 5,
            'pdf_column_options' => [
                ['id' => $opciones[0]->id, 'pivot' => ['visible' => true, 'order' => 0, 'width' => 50, 'wrap_content' => false]],
                ['id' => $opciones[1]->id, 'pivot' => ['visible' => true, 'order' => 1, 'width' => 50, 'wrap_content' => false]],
            ],
        ], $extra);
    }

    /**
     * Perfil de venta creado directo por Eloquent (para los tests del update y del duplicate).
     *
     * @param int   $owner_id
     * @param array $overrides
     * @return \App\Models\PdfColumnProfile
     */
    protected function crear_perfil($owner_id, array $overrides = [])
    {
        $perfil = PdfColumnProfile::create(array_merge([
            'user_id' => $owner_id,
            'model_name' => 'sale',
            'name' => 'zz Perfil negrita '.uniqid(),
            'paper_width_mm' => 210,
            'printable_width_mm' => 210,
            'columns' => [],
        ], $overrides));

        $opciones = $this->opciones_de_venta(2);
        foreach ($opciones as $orden => $opcion) {
            $perfil->pdf_column_options()->attach($opcion->id, [
                'visible' => true,
                'order' => $orden,
                'width' => 50,
                'wrap_content' => false,
            ]);
        }

        return $perfil->fresh();
    }

    // ── A. bold_columns ─────────────────────────────────────────────────────────────────────

    /**
     * El POST real con bold_columns crea el perfil y lo relee de la base ordenado y sin repetidos;
     * la respuesta (fullModel) incluye la clave: es lo que lee la SPA al reabrir el editor.
     *
     * @test
     */
    public function el_store_persiste_bold_columns_y_la_respuesta_lo_incluye()
    {
        $owner = $this->autenticar();
        $opciones = $this->opciones_de_venta(3);

        $response = $this->postJson('api/pdf-column-profiles', $this->cuerpo_de_venta([
            'name' => 'zz Negrita store',
            /** Desordenado y con un repetido: se guarda ordenado y único. */
            'bold_columns' => [$opciones[2]->id, $opciones[0]->id, $opciones[2]->id],
        ]));

        $response->assertStatus(201);

        $esperado = [$opciones[0]->id, $opciones[2]->id];
        sort($esperado);

        $perfil = PdfColumnProfile::where('user_id', $owner->id)->where('name', 'zz Negrita store')->first();

        $this->assertNotNull($perfil);
        $this->assertSame($esperado, $perfil->bold_columns, 'store() tiene que persistir bold_columns normalizado.');
        $this->assertSame($esperado, $response->json('model.bold_columns'), 'La respuesta de fullModel tiene que incluir bold_columns.');
    }

    /** @test */
    public function un_perfil_creado_sin_bold_columns_queda_en_null()
    {
        $owner = $this->autenticar();

        $response = $this->postJson('api/pdf-column-profiles', $this->cuerpo_de_venta(['name' => 'zz Negrita sin nada']));

        $response->assertStatus(201);
        $this->assertNull(PdfColumnProfile::where('user_id', $owner->id)->where('name', 'zz Negrita sin nada')->first()->bold_columns);
        $this->assertNull($response->json('model.bold_columns'));
    }

    /**
     * Ids de otro tipo de diseño, inexistentes, no numéricos y repetidos: se descartan sin dar 422.
     * Es el contrato con la SPA vieja y con la nueva: nunca rompe el guardado del perfil.
     *
     * @test
     */
    public function los_ids_ajenos_al_catalogo_del_perfil_se_descartan()
    {
        $this->autenticar();
        $opciones = $this->opciones_de_venta(2);
        $ajena = $this->opcion_ajena_de_articulo();

        $normalizado = PdfNegritaHelper::normalize_bold_columns([
            $opciones[1]->id,
            (string) $opciones[0]->id,        // un id como string numérico entra
            $ajena->id,                        // existe, pero es de artículo
            999999999,                         // no existe
            'abc',                             // no es un id
            -4,                                // no es un id
            null,
            [$opciones[0]->id],                // un array adentro no es un id
            $opciones[0]->id,                  // repetido
        ], 'sale');

        $esperado = [$opciones[0]->id, $opciones[1]->id];
        sort($esperado);

        $this->assertSame($esperado, $normalizado);
    }

    /** @test */
    public function el_store_con_ids_ajenos_los_descarta_y_no_da_422()
    {
        $owner = $this->autenticar();
        $opciones = $this->opciones_de_venta(2);
        $ajena = $this->opcion_ajena_de_articulo();

        $this->postJson('api/pdf-column-profiles', $this->cuerpo_de_venta([
            'name' => 'zz Negrita ajenos',
            'bold_columns' => [$ajena->id, $opciones[0]->id, 999999999],
        ]))->assertStatus(201);

        $this->assertSame(
            [$opciones[0]->id],
            PdfColumnProfile::where('user_id', $owner->id)->where('name', 'zz Negrita ajenos')->first()->bold_columns
        );
    }

    /**
     * Acepta un string JSON (algunos clientes mandan el cuerpo como form-data) y deja null lo que
     * no tiene forma de lista o queda vacío.
     *
     * @test
     */
    public function bold_columns_acepta_string_json_y_deja_null_lo_que_no_es_lista()
    {
        $this->autenticar();
        $opciones = $this->opciones_de_venta(2);

        $this->assertSame([$opciones[0]->id], PdfNegritaHelper::normalize_bold_columns('['.$opciones[0]->id.']', 'sale'));

        $this->assertNull(PdfNegritaHelper::normalize_bold_columns(null, 'sale'));
        $this->assertNull(PdfNegritaHelper::normalize_bold_columns('', 'sale'));
        $this->assertNull(PdfNegritaHelper::normalize_bold_columns([], 'sale'));
        $this->assertNull(PdfNegritaHelper::normalize_bold_columns('esto no es JSON', 'sale'));
        $this->assertNull(PdfNegritaHelper::normalize_bold_columns('"un string JSON"', 'sale'));
        $this->assertNull(PdfNegritaHelper::normalize_bold_columns(7, 'sale'));
        $this->assertNull(PdfNegritaHelper::normalize_bold_columns([999999999], 'sale'), 'Una lista donde ningún id existe queda en null.');
    }

    /** @test */
    public function el_update_persiste_bold_columns_y_la_respuesta_lo_incluye()
    {
        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id);
        $opciones = $this->opciones_de_venta(2);

        $this->assertNull($perfil->bold_columns);

        $response = $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['bold_columns' => [$opciones[1]->id]]);

        $response->assertStatus(200);
        $this->assertSame([$opciones[1]->id], $perfil->fresh()->bold_columns);
        $this->assertSame([$opciones[1]->id], $response->json('model.bold_columns'));

        /** Un segundo update REEMPLAZA la lista (no la suma). */
        $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['bold_columns' => [$opciones[0]->id]])->assertStatus(200);
        $this->assertSame([$opciones[0]->id], $perfil->fresh()->bold_columns);
    }

    /**
     * Un update parcial (sin la clave) no toca la negrita guardada: una SPA vieja, o cualquier PUT
     * que cambia el nombre o una columna, no puede borrarla sin que nadie lo haya pedido.
     *
     * @test
     */
    public function un_update_que_no_menciona_bold_columns_no_lo_toca()
    {
        $owner = $this->autenticar();
        $opciones = $this->opciones_de_venta(2);
        $perfil = $this->crear_perfil($owner->id, ['bold_columns' => [$opciones[1]->id]]);

        $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['name' => 'zz Renombrado'])->assertStatus(200);

        $this->assertSame('zz Renombrado', $perfil->fresh()->name);
        $this->assertSame([$opciones[1]->id], $perfil->fresh()->bold_columns, 'Un update sin bold_columns no puede cambiar la negrita.');

        /** Tampoco lo toca un update que reescribe las columnas (el pivot) sin mencionarlo. */
        $this->putJson('api/pdf-column-profiles/'.$perfil->id, [
            'pdf_column_options' => [
                ['id' => $opciones[0]->id, 'pivot' => ['visible' => true, 'order' => 0, 'width' => 60, 'wrap_content' => false]],
                ['id' => $opciones[1]->id, 'pivot' => ['visible' => true, 'order' => 1, 'width' => 60, 'wrap_content' => false]],
            ],
        ])->assertStatus(200);

        $this->assertSame([$opciones[1]->id], $perfil->fresh()->bold_columns);
    }

    /** @test */
    public function un_update_con_null_o_lista_vacia_borra_la_negrita()
    {
        $owner = $this->autenticar();
        $opciones = $this->opciones_de_venta(2);
        $perfil = $this->crear_perfil($owner->id, ['bold_columns' => [$opciones[0]->id, $opciones[1]->id]]);

        $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['bold_columns' => null])->assertStatus(200);
        $this->assertNull($perfil->fresh()->bold_columns);

        $perfil->update(['bold_columns' => [$opciones[0]->id]]);
        $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['bold_columns' => []])->assertStatus(200);
        $this->assertNull($perfil->fresh()->bold_columns, 'Una lista vacía se guarda como null (ninguna en negrita).');
    }

    /** @test */
    public function el_duplicate_hereda_bold_columns()
    {
        $owner = $this->autenticar();
        $opciones = $this->opciones_de_venta(2);
        $perfil = $this->crear_perfil($owner->id, ['bold_columns' => [$opciones[0]->id, $opciones[1]->id]]);

        $response = $this->postJson('api/pdf-column-profiles/'.$perfil->id.'/duplicate');

        $response->assertStatus(201);

        $copia = PdfColumnProfile::find($response->json('model.id'));

        $this->assertNotNull($copia);
        $this->assertNotSame($perfil->id, $copia->id);
        $this->assertSame($perfil->fresh()->bold_columns, $copia->bold_columns, 'La copia hereda la negrita del original.');
        $this->assertSame($perfil->fresh()->bold_columns, $response->json('model.bold_columns'));
    }

    /** @test */
    public function el_show_y_el_index_incluyen_bold_columns()
    {
        $owner = $this->autenticar();
        $opciones = $this->opciones_de_venta(2);
        $perfil = $this->crear_perfil($owner->id, ['bold_columns' => [$opciones[0]->id]]);

        $this->getJson('api/pdf-column-profiles/'.$perfil->id)
            ->assertStatus(200)
            ->assertJsonPath('model.bold_columns', [$opciones[0]->id]);

        $listados = collect($this->getJson('api/pdf-column-profiles?model_name=sale')->assertStatus(200)->json('models'));
        $este = $listados->firstWhere('id', $perfil->id);

        $this->assertNotNull($este);
        $this->assertSame([$opciones[0]->id], $este['bold_columns']);
    }

    // ── C. header_layout.bold ───────────────────────────────────────────────────────────────

    /**
     * header_layout.bold: lista de keys de chips del catálogo, únicas. Las ajenas (inventadas,
     * tipos que no son string) se descartan; si no queda ninguna, la clave se omite. El resto del
     * layout se conserva tal cual.
     *
     * @test
     */
    public function el_store_normaliza_header_layout_bold()
    {
        $owner = $this->autenticar();

        $response = $this->postJson('api/pdf-column-profiles', $this->cuerpo_de_venta([
            'name' => 'zz Negrita encabezado',
            'header_layout' => [
                'emisor' => ['izquierda' => ['razon_social', 'cuit'], 'derecha' => ['numero_comprobante']],
                'receptor' => ['izquierda' => ['cliente_nombre', 'cliente_cuit']],
                'bold' => ['cuit', 'cliente_nombre', 'cuit', 'clave_inventada', 5, ['cuit'], 'razon_social'],
            ],
        ]));

        $response->assertStatus(201);

        $perfil = PdfColumnProfile::where('user_id', $owner->id)->where('name', 'zz Negrita encabezado')->first();

        $this->assertSame(['cuit', 'cliente_nombre', 'razon_social'], $perfil->header_layout['bold']);
        $this->assertSame(['razon_social', 'cuit'], $perfil->header_layout['emisor']['izquierda'], 'El resto del layout no se toca.');
        $this->assertSame(['cliente_nombre', 'cliente_cuit'], $perfil->header_layout['receptor']['izquierda']);
        $this->assertSame(['cuit', 'cliente_nombre', 'razon_social'], $response->json('model.header_layout.bold'));
    }

    /** @test */
    public function header_layout_bold_vacia_o_sin_forma_de_lista_se_omite()
    {
        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id);

        foreach ([[], ['clave_inventada'], 'cuit', null, [[]]] as $bold) {
            $this->putJson('api/pdf-column-profiles/'.$perfil->id, [
                'header_layout' => [
                    'emisor' => ['izquierda' => ['razon_social'], 'derecha' => ['cuit']],
                    'bold' => $bold,
                ],
            ])->assertStatus(200);

            $guardado = $perfil->fresh()->header_layout;

            $this->assertArrayNotHasKey('bold', $guardado, 'Sin keys válidas la clave bold se omite: '.json_encode($bold));
            $this->assertEquals(['izquierda' => ['razon_social'], 'derecha' => ['cuit']], $guardado['emisor']);
        }
    }

    /** @test */
    public function el_update_acepta_header_layout_como_string_json_con_bold()
    {
        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id);

        $this->putJson('api/pdf-column-profiles/'.$perfil->id, [
            'header_layout' => json_encode([
                'emisor' => ['izquierda' => ['razon_social'], 'derecha' => ['cuit']],
                'bold' => ['razon_social', 'no_existe'],
            ]),
        ])->assertStatus(200);

        $this->assertSame(['razon_social'], $perfil->fresh()->header_layout['bold']);
    }

    /** @test */
    public function un_update_que_no_menciona_header_layout_no_toca_su_bold()
    {
        $owner = $this->autenticar();
        $layout = [
            'emisor' => ['izquierda' => ['razon_social'], 'derecha' => ['cuit']],
            'bold' => ['cuit'],
        ];
        $perfil = $this->crear_perfil($owner->id, ['header_layout' => $layout]);

        $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['name' => 'zz Otro nombre'])->assertStatus(200);

        /** assertEquals: el JSON de MySQL no conserva el orden de las claves de un objeto. */
        $this->assertEquals($layout, $perfil->fresh()->header_layout);
    }

    /**
     * Un header_layout de siempre (sin la clave bold) se guarda exactamente igual que antes de
     * existir la negrita: el contrato con la SPA vieja.
     *
     * @test
     */
    public function un_header_layout_sin_bold_se_guarda_igual_que_siempre()
    {
        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id);
        $layout = [
            'emisor' => ['izquierda' => ['razon_social', 'cuit'], 'derecha' => ['numero_comprobante']],
            'receptor' => ['izquierda' => ['cliente_nombre']],
        ];

        $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['header_layout' => $layout])->assertStatus(200);

        /** assertEquals: el JSON de MySQL no conserva el orden de las claves de un objeto. */
        $this->assertEquals($layout, $perfil->fresh()->header_layout);
    }

    // ── B. catalog_header_layout[].bold ─────────────────────────────────────────────────────

    /**
     * La negrita de un renglón del encabezado del catálogo viaja por el POST y el PUT: se conserva
     * como bool true, y un renglón sin negrita se guarda con las tres claves de siempre.
     *
     * @test
     */
    public function la_negrita_de_un_renglon_del_catalogo_se_persiste_como_bool()
    {
        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id, ['model_name' => 'article']);

        $this->putJson('api/pdf-column-profiles/'.$perfil->id, [
            'catalog_header_layout' => [
                'logo' => ['show' => true, 'pages' => 'all', 'size_mm' => 25],
                'company_name' => ['show' => true],
                'rows_pages' => 'all',
                'izquierda' => [
                    ['uid' => 'a', 'title' => 'Teléfono', 'value' => '', 'source' => 'telefono', 'bold' => true],
                    ['uid' => 'b', 'title' => 'Horario', 'value' => 'Lun a Vie', 'source' => null, 'bold' => false],
                    ['uid' => 'c', 'title' => 'Zona', 'value' => 'Centro', 'source' => null],
                ],
                'derecha' => [
                    ['uid' => 'd', 'title' => 'Web', 'value' => 'www.ejemplo.com', 'source' => null, 'bold' => 'true'],
                    ['uid' => 'e', 'title' => 'Mail', 'value' => 'a@b.com', 'source' => null, 'bold' => 0],
                ],
            ],
        ])->assertStatus(200);

        $guardado = $perfil->fresh()->catalog_header_layout;

        $this->assertEquals(['title' => 'Teléfono', 'value' => '', 'source' => 'telefono', 'bold' => true], $guardado['izquierda'][0]);
        $this->assertTrue($guardado['izquierda'][0]['bold'] === true, 'bold se guarda como bool, no como 1 ni "true".');
        $this->assertEquals(['title' => 'Horario', 'value' => 'Lun a Vie', 'source' => null], $guardado['izquierda'][1], 'bold false no se guarda.');
        $this->assertArrayNotHasKey('bold', $guardado['izquierda'][1]);
        $this->assertEquals(['title' => 'Zona', 'value' => 'Centro', 'source' => null], $guardado['izquierda'][2]);
        $this->assertArrayNotHasKey('bold', $guardado['izquierda'][2]);
        $this->assertEquals(['title' => 'Web', 'value' => 'www.ejemplo.com', 'source' => null, 'bold' => true], $guardado['derecha'][0], 'El string "true" es true.');
        $this->assertTrue($guardado['derecha'][0]['bold'] === true);
        $this->assertEquals(['title' => 'Mail', 'value' => 'a@b.com', 'source' => null], $guardado['derecha'][1], 'bold 0 no se guarda.');
        $this->assertArrayNotHasKey('bold', $guardado['derecha'][1]);

        /** Es idempotente: normalizar lo guardado no cambia nada. */
        $this->assertEquals($guardado, CatalogHeaderLayoutHelper::normalize($guardado));
    }

    /**
     * resolve_rows() devuelve la negrita del renglón (solo cuando es true) y el default por código
     * (default_for_user) no la trae.
     *
     * @test
     */
    public function resolve_rows_devuelve_bold_y_el_default_no_lo_trae()
    {
        $fuentes = [['key' => 'email', 'label' => 'Email', 'value' => 'ventas@negocio.com']];

        $resueltos = CatalogHeaderLayoutHelper::resolve_rows([
            ['title' => 'Email', 'value' => '', 'source' => 'email', 'bold' => true],
            ['title' => 'Horario', 'value' => 'Lun a Vie', 'source' => null, 'bold' => true],
            ['title' => 'Zona', 'value' => 'Centro', 'source' => null],
        ], null, $fuentes);

        $this->assertSame(true, $resueltos[0]['bold']);
        $this->assertSame('ventas@negocio.com', $resueltos[0]['value']);
        $this->assertSame(true, $resueltos[1]['bold']);
        $this->assertArrayNotHasKey('bold', $resueltos[2]);

        $owner = $this->autenticar();
        $default = CatalogHeaderLayoutHelper::default_for_user($owner);

        foreach (['izquierda', 'derecha'] as $columna) {
            foreach ($default[$columna] as $renglon) {
                $this->assertArrayNotHasKey('bold', $renglon, 'El diseño por defecto sale sin negrita.');
            }
        }
    }
}
