<?php

namespace Tests\Feature\Listado;

use App\Http\Controllers\Helpers\Excel\Article\ArticleExportStreamer;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\TipoEnvase;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;
use Tests\EmpresaTestCase;

/**
 * Propiedades de distribuidora del artículo (extensión articulos_con_propiedades_de_distribuidora):
 * U x Bulto, Contenido y Tipo de envase (misión guardar-unidades-por-bulto-y-tipo-envase, 10/10/2026).
 *
 * Lo que motivó la misión: en demo (4.3.8) se cargó U x Bulto = 12 y Contenido = "300 ml" en la ficha
 * del artículo 29, se apretó "Guardar y cerrar", y `GET api/article/29` devolvió `unidades_por_bulto:
 * null` y `contenido: "300 ml"`. `ArticleController::store()` y `update()` asignan campo por campo y
 * asignaban `contenido` (por el bloque de autopartes) pero no `unidades_por_bulto` ni `tipo_envase_id`.
 *
 * Lo que protege este archivo:
 *  - Alta y edición por el endpoint real guardan los TRES campos (en la base y en lo que devuelve la API).
 *  - Una segunda edición los puede cambiar y vaciar (no quedan pegados al primer valor).
 *  - La exportación a Excel trae las tres columnas, con cada valor debajo de su encabezado, cuando la
 *    cuenta tiene la extensión. Hasta esta misión las preguntas por la extensión usaban el slug
 *    `propiedades_de_distribuidora` (sin el prefijo `articulos_con_`), que no existe, y las tres
 *    columnas no salían nunca para nadie.
 *  - Sin la extensión, esas columnas no salen.
 *
 * Todo corre dentro de la transacción de EmpresaTestCase; la extensión se engancha al dueño de testing y
 * se desengancha en tearDown().
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Propiedades_De_Distribuidora_Test extends EmpresaTestCase
{
    /** Slug real de la extensión (el que usa el SPA y el catálogo). */
    const SLUG = 'articulos_con_propiedades_de_distribuidora';

    /** @var \App\Models\User */
    protected $dueno;

    /** @var \App\Models\ExtencionEmpresa|null Extensión enganchada por este archivo, para soltarla al terminar. */
    protected $extencion_enganchada;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = auth()->user();

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        if (!is_null($this->extencion_enganchada)) {
            $this->dueno->extencions()->detach($this->extencion_enganchada->id);
        }

        parent::tearDown();
    }

    /**
     * Prende la extensión para el dueño de testing (la fila del catálogo se crea si la base del slot no la trae).
     *
     * @return void
     */
    protected function prender_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate([
                'name' => 'Articulos con propiedades de distribuidoras',
                'slug' => self::SLUG,
            ]);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->dueno->unsetRelation('extencions');

        $this->extencion_enganchada = $extencion;
    }

    /**
     * @param  string $nombre
     * @return \App\Models\TipoEnvase
     */
    protected function crear_tipo_de_envase($nombre)
    {
        return TipoEnvase::create([
            'name'    => $nombre,
            'user_id' => $this->dueno->id,
        ]);
    }

    /**
     * Payload mínimo que acepta ArticleController: varias columnas de `articles` son NOT NULL sin default y
     * el controlador las asigna tal cual vienen del request, así que un body incompleto revienta antes de
     * llegar a lo que este test mide. Es el mismo que usa Precios\MargenCeroTest.
     *
     * @param  array $props
     * @return array
     */
    protected function payload(array $props = [])
    {
        return array_merge([
            'name'                           => 'ZZ Test distribuidora',
            'cost'                           => 100,
            'iva_id'                         => 2,
            'aplicar_iva'                    => 1,
            'apply_provider_percentage_gain' => 0,
            'cost_in_dollars'                => 0,
            'provider_cost_in_dollars'       => 0,
            'online'                         => 0,
            'in_offer'                       => 0,
            'precio_pausado'                 => 0,
            'default_in_vender'              => 0,
            'personalizar_price_en_vender'   => 0,
            'omitir_en_lista_pdf'            => 0,
            'mercado_libre'                  => 0,
            'disponible_tienda_nube'         => 0,
            'requires_shipping'              => 0,
            'free_shipping'                  => 0,
            'es_insumo'                      => 0,
            'featured'                       => 0,
            /* Los helpers de relaciones hacen foreach directo sobre estos: no pueden faltar. */
            'price_types'                    => [],
            'price_type_monedas'             => [],
            'tags'                           => [],
            'addresses'                      => [],
            'childrens'                      => [],
        ], $props);
    }

    /**
     * Los tres campos tal cual los devuelve la API (lo mismo que mide Lucas en la ficha: GET api/article/{id}).
     *
     * @param  int $id
     * @return array
     */
    protected function campos_por_la_api($id)
    {
        $respuesta = $this->getJson('/api/article/' . $id);

        $respuesta->assertStatus(200);

        return [
            'unidades_por_bulto' => $respuesta->json('model.unidades_por_bulto'),
            'contenido'          => $respuesta->json('model.contenido'),
            'tipo_envase_id'     => $respuesta->json('model.tipo_envase_id'),
        ];
    }

    /**
     * El alta guarda U x Bulto, Contenido y Tipo de envase.
     *
     * @test
     */
    public function el_alta_guarda_los_tres_campos()
    {
        $envase = $this->crear_tipo_de_envase('Retornable (test)');

        $respuesta = $this->postJson('/api/article', $this->payload([
            'unidades_por_bulto' => 12,
            'contenido'          => '300 ml',
            'tipo_envase_id'     => $envase->id,
        ]));

        $respuesta->assertStatus(201);

        $id = $respuesta->json('model.id');
        $this->assertNotNull($id, 'El alta no devolvió el artículo creado.');

        $articulo = Article::find($id);

        $this->assertSame(12, (int) $articulo->unidades_por_bulto, 'U x Bulto no quedó guardado en el alta.');
        $this->assertSame('300 ml', $articulo->contenido);
        $this->assertSame((string) $envase->id, (string) $articulo->tipo_envase_id, 'El tipo de envase no quedó guardado en el alta.');

        $por_la_api = $this->campos_por_la_api($id);

        $this->assertEquals(12, $por_la_api['unidades_por_bulto']);
        $this->assertSame('300 ml', $por_la_api['contenido']);
        $this->assertEquals($envase->id, $por_la_api['tipo_envase_id']);
    }

    /**
     * La edición guarda los tres campos, los puede cambiar y los puede vaciar.
     *
     * @test
     */
    public function la_edicion_guarda_cambia_y_vacia_los_tres_campos()
    {
        $retornable  = $this->crear_tipo_de_envase('Retornable (test)');
        $descartable = $this->crear_tipo_de_envase('Descartable (test)');

        $articulo = Article::create([
            'user_id' => $this->dueno->id,
            'name'    => 'ZZ Test distribuidora edicion',
            'cost'    => 100,
            'iva_id'  => 2,
            'status'  => 'active',
        ]);

        $this->assertNull($articulo->fresh()->unidades_por_bulto, 'El artículo de partida tiene que arrancar sin los campos.');

        /* ArticleController@update lee el id del BODY ($request->id), no del parámetro de ruta. */
        $primera = $this->putJson('/api/article/' . $articulo->id, $this->payload([
            'id'                 => $articulo->id,
            'name'               => $articulo->name,
            'unidades_por_bulto' => 6,
            'contenido'          => '1,5 L',
            'tipo_envase_id'     => $retornable->id,
        ]));

        $primera->assertStatus(200);

        $articulo->refresh();

        $this->assertSame(6, (int) $articulo->unidades_por_bulto, 'U x Bulto no quedó guardado en la edición.');
        $this->assertSame('1,5 L', $articulo->contenido);
        $this->assertSame((string) $retornable->id, (string) $articulo->tipo_envase_id, 'El tipo de envase no quedó guardado en la edición.');

        $por_la_api = $this->campos_por_la_api($articulo->id);
        $this->assertEquals(6, $por_la_api['unidades_por_bulto']);
        $this->assertSame('1,5 L', $por_la_api['contenido']);
        $this->assertEquals($retornable->id, $por_la_api['tipo_envase_id']);

        /* Cambiar los valores: no quedan pegados a los primeros. */
        $segunda = $this->putJson('/api/article/' . $articulo->id, $this->payload([
            'id'                 => $articulo->id,
            'name'               => $articulo->name,
            'unidades_por_bulto' => 24,
            'contenido'          => '500 ml',
            'tipo_envase_id'     => $descartable->id,
        ]));

        $segunda->assertStatus(200);

        $articulo->refresh();

        $this->assertSame(24, (int) $articulo->unidades_por_bulto);
        $this->assertSame('500 ml', $articulo->contenido);
        $this->assertSame((string) $descartable->id, (string) $articulo->tipo_envase_id);

        /* Vaciar los campos desde la ficha (la SPA manda null) los deja en null. */
        $tercera = $this->putJson('/api/article/' . $articulo->id, $this->payload([
            'id'                 => $articulo->id,
            'name'               => $articulo->name,
            'unidades_por_bulto' => null,
            'contenido'          => null,
            'tipo_envase_id'     => null,
        ]));

        $tercera->assertStatus(200);

        $articulo->refresh();

        $this->assertNull($articulo->unidades_por_bulto, 'Vaciar U x Bulto tiene que dejarlo en null.');
        $this->assertNull($articulo->contenido);
        $this->assertNull($articulo->tipo_envase_id, 'Quitar el tipo de envase tiene que dejarlo en null.');
    }

    /**
     * Un U x Bulto vaciado desde la ficha llega como cadena vacía en el JSON y se guarda como null (no como 0,
     * ni rompe contra la columna entera).
     *
     * @test
     */
    public function una_cadena_vacia_en_u_x_bulto_se_guarda_como_null()
    {
        $respuesta = $this->postJson('/api/article', $this->payload([
            'name'               => 'ZZ Test distribuidora vacio',
            'unidades_por_bulto' => '',
            'tipo_envase_id'     => '',
        ]));

        $respuesta->assertStatus(201);

        $articulo = Article::find($respuesta->json('model.id'));

        $this->assertNull($articulo->unidades_por_bulto);
        $this->assertNull($articulo->tipo_envase_id);
    }

    /**
     * Lee un .xlsx del disco de prueba como filas de valores.
     *
     * @param  string $ruta
     * @return array
     */
    protected function leer($ruta)
    {
        $reader = ReaderEntityFactory::createXLSXReader();
        $reader->setShouldPreserveEmptyRows(true);
        $reader->open($ruta);

        $filas = [];
        foreach ($reader->getSheetIterator() as $hoja) {
            foreach ($hoja->getRowIterator() as $row) {
                $filas[] = array_map(function ($cell) {
                    return $cell->getValue();
                }, $row->getCells());
            }
            break;
        }
        $reader->close();

        return $filas;
    }

    /**
     * Exporta un artículo por el camino real (el que usa el job: ArticleExportStreamer) y devuelve
     * [encabezados, celdas de su fila].
     *
     * @param  int $article_id
     * @return array
     */
    protected function exportar($article_id)
    {
        (new ArticleExportStreamer($this->dueno->id, [$article_id]))->guardar('distribuidora.xlsx');

        $filas = $this->leer(Storage::disk('local')->path('distribuidora.xlsx'));

        $this->assertCount(2, $filas, 'El Excel tiene que traer la fila de encabezados y la del artículo.');

        return $filas;
    }

    /**
     * Con la extensión prendida el Excel exportado trae Tipo envase, Contenido y Unidades por bulto, y cada
     * valor cae debajo de su encabezado.
     *
     * @test
     */
    public function la_exportacion_trae_las_tres_columnas_alineadas_con_la_extension()
    {
        $this->prender_extension();

        $envase = $this->crear_tipo_de_envase('Retornable (test)');

        $respuesta = $this->postJson('/api/article', $this->payload([
            'name'               => 'ZZ Test distribuidora export',
            'unidades_por_bulto' => 12,
            'contenido'          => '300 ml',
            'tipo_envase_id'     => $envase->id,
        ]));
        $respuesta->assertStatus(201);

        list($encabezados, $celdas) = $this->exportar($respuesta->json('model.id'));

        $posicion_envase    = array_search('Tipo envase', $encabezados, true);
        $posicion_contenido = array_search('Contenido', $encabezados, true);
        $posicion_bulto     = array_search('Unidades por bulto', $encabezados, true);

        $this->assertNotFalse($posicion_envase, 'Falta el encabezado "Tipo envase" en el Excel exportado.');
        $this->assertNotFalse($posicion_contenido, 'Falta el encabezado "Contenido" en el Excel exportado.');
        $this->assertNotFalse($posicion_bulto, 'Falta el encabezado "Unidades por bulto" en el Excel exportado.');

        $this->assertSame('Retornable (test)', $celdas[$posicion_envase], 'El tipo de envase no cayó debajo de su encabezado.');
        $this->assertSame('300 ml', $celdas[$posicion_contenido], 'El contenido no cayó debajo de su encabezado.');
        $this->assertEquals(12, $celdas[$posicion_bulto], 'U x Bulto no cayó debajo de su encabezado.');

        /* Las columnas de siempre siguen en su lugar: el nombre cae debajo de "Nombre". */
        $posicion_nombre = array_search('Nombre', $encabezados, true);
        $this->assertNotFalse($posicion_nombre);
        $this->assertSame('ZZ Test distribuidora export', $celdas[$posicion_nombre], 'Las tres columnas nuevas desalinearon las de siempre.');
    }

    /**
     * Sin la extensión, esas tres columnas no salen.
     *
     * @test
     */
    public function la_exportacion_no_trae_las_columnas_sin_la_extension()
    {
        $respuesta = $this->postJson('/api/article', $this->payload([
            'name'               => 'ZZ Test distribuidora sin extension',
            'unidades_por_bulto' => 12,
        ]));
        $respuesta->assertStatus(201);

        list($encabezados) = $this->exportar($respuesta->json('model.id'));

        $this->assertFalse(array_search('Tipo envase', $encabezados, true), 'Sin la extensión no tiene que salir "Tipo envase".');
        $this->assertFalse(array_search('Unidades por bulto', $encabezados, true), 'Sin la extensión no tiene que salir "Unidades por bulto".');
    }
}
