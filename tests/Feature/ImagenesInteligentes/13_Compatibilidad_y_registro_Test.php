<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Models\ImageServiceCall;
use App\Models\User;
use App\Services\BusquedaPorCodigoDeBarrasService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Compatibilidad y registro, ajustes de la revisión independiente (plan §13):
 *
 *   - C1: la SPA vieja cacheada que pide el resumen por uuid recibe el shape de siempre, armado
 *     desde la asignación nueva.
 *   - A4: las búsquedas de Google de la búsqueda por código del asistente quedan en el registro de
 *     consultas.
 */
class Compatibilidad_y_registro_Test extends ImagenesInteligentesTestCase
{
    /** Un EAN-13 de fábrica válido. */
    const CODIGO_REAL = '7791234567898';

    /**
     * Una asignación con estado puesto a mano.
     *
     * @param  array $datos
     * @return \App\Models\ImageAssignmentRun
     */
    protected function corrida(array $datos)
    {
        return ImageAssignmentRun::create(array_merge([
            'user_id'         => $this->owner->id,
            'uuid'            => (string) Str::uuid(),
            'origen'          => ImageAssignmentRun::ORIGEN_SELECCION,
            'proveedor'       => ImageAssignmentRun::PROVEEDOR_SERPER,
            'status'          => ImageAssignmentRun::STATUS_TERMINADA,
            'total_articulos' => 1,
        ], $datos));
    }

    /**
     * Un item de una asignación.
     *
     * @param  \App\Models\ImageAssignmentRun $run
     * @param  \App\Models\Article            $articulo
     * @param  array                          $datos
     * @return \App\Models\ImageAssignmentItem
     */
    protected function item_de($run, $articulo, array $datos)
    {
        return ImageAssignmentItem::create(array_merge([
            'run_id'       => $run->id,
            'user_id'      => $this->owner->id,
            'article_id'   => $articulo->id,
            'article_name' => $articulo->name,
            'orden'        => 1,
        ], $datos));
    }

    /**
     * C1: una SPA vieja cacheada pide GET article-image-search-attempts/summary/{uuid} con el uuid de
     * una asignación nueva: recibe el shape de siempre, con las "a revisar" dentro de skipped_items y
     * needs_review en 0. Un uuid ajeno sigue siendo 404.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function el_resumen_viejo_se_arma_desde_la_asignacion_nueva()
    {
        $run = $this->corrida(['total_articulos' => 5]);

        $asignado  = $this->nuevo_articulo('Asignado');
        $revisar   = $this->nuevo_articulo('A revisar');
        $sin       = $this->nuevo_articulo('Sin imagen');
        $sin_cupo  = $this->nuevo_articulo('Sin cupo');
        $pendiente = $this->nuevo_articulo('Pendiente');

        $this->item_de($run, $asignado, ['status' => ImageAssignmentItem::STATUS_ASIGNADA, 'orden' => 1]);
        $this->item_de($run, $revisar, ['status' => ImageAssignmentItem::STATUS_A_REVISAR, 'motivo' => 'confianza_media', 'orden' => 2]);
        $this->item_de($run, $sin, ['status' => ImageAssignmentItem::STATUS_NO_ASIGNADA, 'motivo' => 'sin_resultados', 'motivo_detalle' => 'Por código de barras: la búsqueda no trajo ninguna imagen.', 'orden' => 3]);
        $this->item_de($run, $sin_cupo, ['status' => ImageAssignmentItem::STATUS_SIN_PROCESAR, 'motivo' => 'sin_cupo', 'orden' => 4]);
        $this->item_de($run, $pendiente, ['status' => ImageAssignmentItem::STATUS_PENDIENTE, 'orden' => 5]);

        $respuesta = $this->getJson('api/article-image-search-attempts/summary/'.$run->uuid)->assertStatus(200)->json();

        $this->assertSame([
            'batch_uuid', 'created_at', 'articles_count', 'processed', 'skipped', 'skipped_by_quota',
            'quota_reached', 'needs_review', 'skipped_items', 'skipped_names', 'needs_review_items',
            'skipped_by_quota_names',
        ], array_keys($respuesta));

        $this->assertSame($run->uuid, $respuesta['batch_uuid']);
        $this->assertSame(5, $respuesta['articles_count']);
        $this->assertSame(1, $respuesta['processed'], 'Solo la asignada: la SPA vieja no puede decir "se asignaron" de las que esperan revisión.');
        $this->assertSame(0, $respuesta['needs_review']);
        $this->assertSame([], $respuesta['needs_review_items']);
        $this->assertSame(2, $respuesta['skipped']);
        $this->assertSame(1, $respuesta['skipped_by_quota']);
        $this->assertTrue($respuesta['quota_reached']);
        $this->assertSame(['Sin cupo'], $respuesta['skipped_by_quota_names']);

        $this->assertSame([
            ['article_id' => (int) $revisar->id, 'name' => 'A revisar', 'summary' => 'Quedó esperando tu revisión en Alertas → Imágenes.'],
            ['article_id' => (int) $sin->id, 'name' => 'Sin imagen', 'summary' => 'Por código de barras: la búsqueda no trajo ninguna imagen.'],
        ], $respuesta['skipped_items']);
        $this->assertSame(['A revisar', 'Sin imagen'], $respuesta['skipped_names']);

        // Uno ajeno o inexistente: el 404 de siempre.
        $otro  = User::create(['name' => 'Otro', 'email' => 'otro-c1-'.uniqid().'@test.local', 'password' => Hash::make('secret')]);
        $ajena = $this->corrida(['user_id' => $otro->id]);

        $this->getJson('api/article-image-search-attempts/summary/'.$ajena->uuid)->assertStatus(404);
        $this->getJson('api/article-image-search-attempts/summary/'.(string) Str::uuid())->assertStatus(404);
    }

    /**
     * A4: las búsquedas de Google de la búsqueda por código de barras del asistente quedan en el
     * registro de consultas, con su origen propio, el estado HTTP y los resultados.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function las_busquedas_de_google_de_la_busqueda_por_codigo_quedan_registradas()
    {
        config(['services.google_search.api_key' => 'AIzaCLAVE-DE-GOOGLE-DE-PRUEBA']);

        Http::swap(new \Tests\Fakes\HttpFactorySinSalida(app('events')));
        Http::fake(function ($request) {
            if (strpos($request->url(), 'googleapis.com/customsearch') !== false) {
                // Por código: responde sin imágenes. Por nombre: la clave rechazada (403).
                if (strpos(urldecode($request->url()), 'q='.self::CODIGO_REAL) !== false) {
                    return Http::response(['searchInformation' => ['totalResults' => '0']], 200);
                }

                return Http::response(['error' => ['code' => 403, 'message' => 'Requests from referer <empty> are blocked.']], 403);
            }

            return Http::response('no', 404);
        });

        $servicio = new BusquedaPorCodigoDeBarrasService($this->owner);

        $this->assertNull($servicio->foto_de_google(self::CODIGO_REAL, 'Cera para pisos 450 ml'));

        $filas = ImageServiceCall::where('user_id', $this->owner->id)->orderBy('id')->get();

        $this->assertCount(2, $filas);

        foreach ($filas as $fila) {
            $this->assertSame(ImageServiceCall::ORIGEN_ASISTENTE_CODIGO_DE_BARRAS, $fila->origen);
            $this->assertSame(ImageServiceCall::TIPO_BUSQUEDA, $fila->tipo);
            $this->assertSame('google', $fila->proveedor);
            $this->assertNull($fila->run_id);
            $this->assertNull($fila->article_id);
            $this->assertSame('Cera para pisos 450 ml', $fila->article_name);
            $this->assertNotNull($fila->duracion_ms);
        }

        $this->assertSame('codigo_de_barras', $filas[0]->criterio);
        $this->assertSame(self::CODIGO_REAL, $filas[0]->consulta);
        $this->assertTrue($filas[0]->ok);
        $this->assertTrue($filas[0]->cobrada);
        $this->assertSame(200, $filas[0]->http_status);
        $this->assertSame(0, $filas[0]->resultados);
        $this->assertSame('Sin resultados', $filas[0]->resumen);

        $this->assertSame('nombre', $filas[1]->criterio);
        $this->assertFalse($filas[1]->ok);
        $this->assertFalse($filas[1]->cobrada);
        $this->assertSame(403, $filas[1]->http_status);
        $this->assertStringContainsString('referer <empty> are blocked', (string) $filas[1]->error);
        $this->assertStringNotContainsString('AIzaCLAVE-DE-GOOGLE-DE-PRUEBA', (string) $filas[1]->error);
    }
}
