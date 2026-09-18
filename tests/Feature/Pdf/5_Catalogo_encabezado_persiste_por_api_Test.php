<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\CatalogHeaderLayoutHelper;
use App\Models\PdfColumnOption;
use App\Models\PdfColumnProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El diseño del encabezado del catálogo (pdf_column_profiles.catalog_header_layout) viaja por la
 * API real de perfiles de PDF, que es el único camino que usa la SPA. Cubre la clase de bug que
 * ya pasó con show_client_description y show_subtotal_in_footer (ver 4_Observaciones_del_cliente_
 * persisten_por_api_Test): un campo que está en la migración y en el cast del modelo pero que
 * store()/update() descartan en silencio, así que el guardado devuelve 200/201 y el valor nunca
 * llega a la base. También fija las reglas de normalize() (lo que persiste es el esquema exacto,
 * acotado, y una segunda normalización no cambia nada) y el contrato de
 * GET pdf-column-profiles/catalog-header-sources, que la SPA consume al abrir el diseñador.
 *
 * Misión catalogo-pdf-encabezado (18/9/2026).
 *
 * @group pdf-catalogo-encabezado
 */
class Catalogo_encabezado_persiste_por_api_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario autenticado de los tests de esta suite (mismo patrón que Preferencias).
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
     * Perfil de artículo mínimo, creado directo por Eloquent (para los tests del update).
     *
     * @param int $owner_id
     * @param array $overrides
     * @return \App\Models\PdfColumnProfile
     */
    protected function crear_perfil(int $owner_id, array $overrides = [])
    {
        return PdfColumnProfile::create(array_merge([
            'user_id' => $owner_id,
            'model_name' => 'article',
            'name' => 'zz Perfil de test encabezado catálogo',
            'paper_width_mm' => 210,
            'printable_width_mm' => 200,
            /** NOT NULL sin default en la tabla (columna JSON): hay que pasarla a mano. */
            'columns' => [],
        ], $overrides));
    }

    /**
     * Una opción de columna de artículo válida para el POST (la regla exists() la exige del mismo
     * model_name). La base de testing viene sembrada solo con opciones de venta, así que si no
     * hay ninguna de artículo se crea una dentro de la transacción del test.
     *
     * @return \App\Models\PdfColumnOption
     */
    protected function opcion_de_columna_de_articulo()
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
     * Un diseño completo tal como lo manda la SPA, con un uid de drag & drop que NO tiene que persistir.
     *
     * @return array
     */
    protected function layout_de_prueba()
    {
        return [
            'logo' => ['show' => true, 'pages' => 'first', 'size_mm' => 30],
            'company_name' => ['show' => false],
            'rows_pages' => 'all',
            'izquierda' => [
                ['uid' => 'abc', 'title' => 'Teléfono', 'value' => 'lo que había guardado', 'source' => 'telefono'],
                ['uid' => 'def', 'title' => 'Horario', 'value' => 'Lun a Vie 9 a 18', 'source' => null],
            ],
            'derecha' => [
                ['uid' => 'ghi', 'title' => 'CUIT', 'value' => '', 'source' => 'cuit'],
            ],
        ];
    }

    /**
     * Lo que normalize() tiene que dejar del layout de prueba: sin uid, con el esquema exacto.
     *
     * @return array
     */
    protected function layout_de_prueba_normalizado()
    {
        return [
            'logo' => ['show' => true, 'pages' => 'first', 'size_mm' => 30],
            'company_name' => ['show' => false],
            'rows_pages' => 'all',
            'izquierda' => [
                ['title' => 'Teléfono', 'value' => 'lo que había guardado', 'source' => 'telefono'],
                ['title' => 'Horario', 'value' => 'Lun a Vie 9 a 18', 'source' => null],
            ],
            'derecha' => [
                ['title' => 'CUIT', 'value' => '', 'source' => 'cuit'],
            ],
        ];
    }

    /**
     * El POST real de la SPA con catalog_header_layout crea el perfil (201) y lo que se relee de
     * la base es el layout normalizado (sin los uid del drag & drop). Si store() no lo pasa al
     * create(), el POST igual da 201 y la columna queda null: es exactamente el bug que hubo con
     * show_client_description.
     *
     * @test
     */
    public function el_store_persiste_el_layout_normalizado()
    {
        $owner = $this->autenticar();
        $option = $this->opcion_de_columna_de_articulo();

        $response = $this->postJson('api/pdf-column-profiles', [
            'model_name' => 'article',
            'name' => 'zz Catálogo con encabezado (store)',
            'paper_width_mm' => 210,
            'printable_width_mm' => 200,
            'margin_mm' => 5,
            /** Pivot completo, como lo manda la SPA: wrap_content es NOT NULL en la tabla pivot. */
            'pdf_column_options' => [
                ['id' => $option->id, 'pivot' => ['visible' => true, 'order' => 0, 'width' => 100, 'wrap_content' => false]],
            ],
            'catalog_header_layout' => $this->layout_de_prueba(),
        ]);

        $response->assertStatus(201);

        $perfil = PdfColumnProfile::where('user_id', $owner->id)
            ->where('name', 'zz Catálogo con encabezado (store)')
            ->first();

        $this->assertNotNull($perfil, 'El POST devolvió 201 pero el perfil no está en la base.');
        $this->assertEquals(
            $this->layout_de_prueba_normalizado(),
            $perfil->catalog_header_layout,
            'store() tiene que persistir catalog_header_layout normalizado (sin uid, esquema exacto).'
        );
        $this->assertEquals($this->layout_de_prueba_normalizado(), $response->json('model.catalog_header_layout'));
    }

    /** @test */
    public function el_update_persiste_un_layout_nuevo()
    {
        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id);

        $this->assertNull($perfil->fresh()->catalog_header_layout);

        $response = $this->putJson('api/pdf-column-profiles/'.$perfil->id, [
            'catalog_header_layout' => $this->layout_de_prueba(),
        ]);

        $response->assertStatus(200);
        $this->assertEquals(
            $this->layout_de_prueba_normalizado(),
            $perfil->fresh()->catalog_header_layout,
            'El update tiene que persistir catalog_header_layout; si no está en el $request->only() '
                .'de update(), el PUT devuelve 200 pero el valor queda intacto en la base.'
        );
        $this->assertEquals($this->layout_de_prueba_normalizado(), $response->json('model.catalog_header_layout'));
    }

    /**
     * $request->only([...]) es "sometimes": un PUT que solo cambia el nombre no tiene que tocar el
     * diseño. Si algún día se lo reescribe como "null si no viene", editar cualquier otro campo
     * del perfil borraría el encabezado sin que nadie lo haya pedido.
     *
     * @test
     */
    public function un_update_que_no_menciona_el_layout_no_lo_toca()
    {
        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id, [
            'catalog_header_layout' => $this->layout_de_prueba_normalizado(),
        ]);

        $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['name' => 'zz Perfil renombrado'])
            ->assertStatus(200);

        $this->assertEquals('zz Perfil renombrado', $perfil->fresh()->name);
        $this->assertEquals(
            $this->layout_de_prueba_normalizado(),
            $perfil->fresh()->catalog_header_layout,
            'Un update parcial (sin catalog_header_layout en el body) no puede cambiar el diseño.'
        );
    }

    /** @test */
    public function un_update_con_null_borra_el_layout()
    {
        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id, [
            'catalog_header_layout' => $this->layout_de_prueba_normalizado(),
        ]);

        $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['catalog_header_layout' => null])
            ->assertStatus(200);

        $this->assertNull(
            $perfil->fresh()->catalog_header_layout,
            'catalog_header_layout = null en el PUT tiene que dejar el perfil sin diseño (render legacy).'
        );
    }

    /**
     * Reglas de normalize(): lo que la SPA (o cualquiera) mande fuera del esquema se acota o se
     * descarta, y lo que persiste es siempre el esquema exacto.
     *
     * @test
     */
    public function normalize_acota_y_limpia_el_esquema()
    {
        $this->assertNull(CatalogHeaderLayoutHelper::normalize(null));
        $this->assertNull(CatalogHeaderLayoutHelper::normalize(''));
        $this->assertNull(CatalogHeaderLayoutHelper::normalize('esto no es JSON'));
        $this->assertNull(CatalogHeaderLayoutHelper::normalize('"un string JSON válido"'));
        $this->assertNull(CatalogHeaderLayoutHelper::normalize(['otra_cosa' => 1]), 'Un array sin claves conocidas no es un layout.');

        $normalizado = CatalogHeaderLayoutHelper::normalize([
            'logo' => ['show' => true, 'pages' => 'cualquiera', 'size_mm' => 200],
            'company_name' => ['show' => true],
            'rows_pages' => 'first',
            'izquierda' => [
                ['title' => '  Fuente desconocida  ', 'value' => 'x', 'source' => 'inventada', 'uid' => 'zz'],
                ['title' => '', 'value' => '', 'source' => null],
                ['title' => '', 'value' => '   ', 'source' => ''],
            ],
            'derecha' => [
                ['title' => str_repeat('T', 80), 'value' => str_repeat('v', 250), 'source' => 'email'],
            ],
        ]);

        $this->assertEquals('all', $normalizado['logo']['pages'], "pages inválido cae a 'all'.");
        $this->assertEquals(CatalogHeaderLayoutHelper::LOGO_SIZE_MM_MAX, $normalizado['logo']['size_mm'], 'size_mm 200 se acota a 60.');
        $this->assertEquals('first', $normalizado['rows_pages']);

        $this->assertCount(1, $normalizado['izquierda'], 'Las filas sin source y sin título ni valor se descartan.');
        $this->assertEquals(
            ['title' => 'Fuente desconocida', 'value' => 'x', 'source' => null],
            $normalizado['izquierda'][0],
            'Un source desconocido queda en null, el título se recorta y el uid no persiste.'
        );

        $this->assertEquals(CatalogHeaderLayoutHelper::MAX_TITLE_LENGTH, mb_strlen($normalizado['derecha'][0]['title']));
        $this->assertEquals(CatalogHeaderLayoutHelper::MAX_VALUE_LENGTH, mb_strlen($normalizado['derecha'][0]['value']));
        $this->assertEquals('email', $normalizado['derecha'][0]['source']);

        $this->assertSame(
            ['logo', 'company_name', 'rows_pages', 'izquierda', 'derecha'],
            array_keys($normalizado),
            'El esquema persistido tiene exactamente esas claves, en ese orden.'
        );

        $chico = CatalogHeaderLayoutHelper::normalize(['logo' => ['size_mm' => 3, 'show' => false]]);
        $this->assertEquals(CatalogHeaderLayoutHelper::LOGO_SIZE_MM_MIN, $chico['logo']['size_mm'], 'size_mm 3 se acota a 10.');
        $this->assertFalse($chico['logo']['show']);
        $this->assertTrue($chico['company_name']['show'], 'company_name.show default true.');
        $this->assertEquals('all', $chico['logo']['pages']);
        $this->assertEquals('all', $chico['rows_pages']);
        $this->assertSame([], $chico['izquierda']);
        $this->assertSame([], $chico['derecha']);

        $sin_tamano = CatalogHeaderLayoutHelper::normalize(['logo' => []]);
        $this->assertEquals(CatalogHeaderLayoutHelper::LOGO_SIZE_MM_DEFAULT, $sin_tamano['logo']['size_mm'], 'size_mm ausente => 25.');
        $this->assertTrue($sin_tamano['logo']['show'], 'logo.show default true.');

        $muchas = [];
        for ($i = 1; $i <= 20; $i++) {
            $muchas[] = ['title' => 'Fila '.$i, 'value' => (string) $i, 'source' => null];
        }
        $tope = CatalogHeaderLayoutHelper::normalize(['izquierda' => $muchas]);
        $this->assertCount(CatalogHeaderLayoutHelper::MAX_ROWS_PER_COLUMN, $tope['izquierda'], 'Más de 15 filas por columna se descartan.');
        $this->assertEquals('Fila 15', $tope['izquierda'][14]['title'], 'Se conservan las primeras 15.');
    }

    /** @test */
    public function normalize_decodifica_un_string_json()
    {
        $normalizado = CatalogHeaderLayoutHelper::normalize(json_encode($this->layout_de_prueba()));

        $this->assertEquals($this->layout_de_prueba_normalizado(), $normalizado);
    }

    /**
     * normalize(normalize($x)) === normalize($x): lo que se guardó una vez se puede volver a
     * mandar tal cual (la SPA relee el perfil y lo reenvía) sin que cambie nada.
     *
     * @test
     */
    public function normalize_es_idempotente()
    {
        $casos = [
            $this->layout_de_prueba(),
            [
                'logo' => ['show' => 'false', 'pages' => 'first', 'size_mm' => '99'],
                'rows_pages' => 'nada',
                'izquierda' => [['title' => '  '.str_repeat('a', 59).' b', 'value' => "  con espacios  ", 'source' => 'telefono']],
                'derecha' => [['title' => '', 'value' => str_repeat('v', 199).' x', 'source' => null]],
            ],
            ['company_name' => ['show' => 0]],
        ];

        foreach ($casos as $caso) {
            $una_vez = CatalogHeaderLayoutHelper::normalize($caso);
            $dos_veces = CatalogHeaderLayoutHelper::normalize($una_vez);
            $this->assertSame($una_vez, $dos_veces, 'normalize() tiene que ser idempotente: '.json_encode($caso));
        }
    }

    /**
     * Contrato del endpoint que abre el diseñador: las 8 fuentes en el orden de SOURCE_LABELS con
     * el valor actual del negocio, el logo y el nombre para la previsualización, y el diseño por
     * defecto con teléfono y email solo si el negocio los tiene.
     *
     * @test
     */
    public function el_endpoint_de_sources_devuelve_las_fuentes_y_el_default()
    {
        $owner = $this->autenticar();

        $response = $this->getJson('api/pdf-column-profiles/catalog-header-sources');

        $response->assertStatus(200);

        $sources = $response->json('sources');
        $this->assertSame(
            array_keys(CatalogHeaderLayoutHelper::SOURCE_LABELS),
            array_column($sources, 'key'),
            'Las 8 fuentes salen en el orden de SOURCE_LABELS.'
        );
        $this->assertSame(
            array_values(CatalogHeaderLayoutHelper::SOURCE_LABELS),
            array_column($sources, 'label')
        );

        $telefono = $sources[0];
        $this->assertEquals('telefono', $telefono['key']);
        $this->assertEquals(trim((string) $owner->phone), $telefono['value'], 'telefono trae el phone del usuario 500.');

        $email = $sources[1];
        $this->assertEquals(trim((string) $owner->email), $email['value']);

        $this->assertEquals($owner->image_url ?: null, $response->json('logo_url'));
        $this->assertEquals((string) $owner->company_name, $response->json('company_name'));

        $default = $response->json('default_layout');
        $this->assertEquals(['show' => true, 'pages' => 'all', 'size_mm' => 25], $default['logo']);
        $this->assertEquals(['show' => true], $default['company_name']);
        $this->assertEquals('all', $default['rows_pages']);
        $this->assertSame([], $default['derecha']);

        $esperadas = [];
        foreach (['telefono', 'email'] as $key) {
            $valor = $key === 'telefono' ? trim((string) $owner->phone) : trim((string) $owner->email);
            if ($valor !== '') {
                $esperadas[] = ['title' => CatalogHeaderLayoutHelper::SOURCE_LABELS[$key], 'value' => $valor, 'source' => $key];
            }
        }
        $this->assertEquals($esperadas, $default['izquierda'], 'El default trae telefono y email solo si tienen valor.');

        $this->assertEquals($default, CatalogHeaderLayoutHelper::normalize($default), 'El default ya viene normalizado.');
    }

    /**
     * La ruta va declarada antes del resource: si la capturara show/{id}, respondería 404.
     *
     * @test
     */
    public function la_ruta_de_sources_no_la_captura_el_show_del_resource()
    {
        $this->autenticar();

        $this->getJson('api/pdf-column-profiles/catalog-header-sources')
            ->assertStatus(200)
            ->assertJsonStructure(['sources', 'logo_url', 'company_name', 'default_layout']);
    }
}
