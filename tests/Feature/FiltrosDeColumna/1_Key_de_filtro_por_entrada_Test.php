<?php

namespace Tests\Feature\FiltrosDeColumna;

use App\Http\Controllers\Helpers\Excel\Article\ArticleExportStreamer;
use App\Models\Article;
use App\Models\ExportHistory;
use App\Models\MasiveUpdate;
use App\Models\PdfColumnProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Un key de filtro inyectado se rechaza en CADA entrada que llega a ColumnFiltersHelper, y la
 * operación no corre (misión filtros-key-sin-inyeccion, 5/10/2026).
 *
 * En cada test hay un segundo dueño real con una fila que EXISTE y que matchea el mismo texto que
 * la del dueño logueado: sin la guarda, `whereRaw('1=1 OR name LIKE ?')` la traía (search,
 * global-search, Excel, PDF), la borraba (`PUT api/delete` con from_filter) o la modificaba
 * (`PUT api/update` con from_filter).
 *
 * Junto a cada rechazo va el mismo pedido con el key legítimo (`name`), que tiene que seguir
 * andando y traer solo lo propio.
 *
 * ⚠️ El de `article/table-pdf` va ÚLTIMO a propósito: sin la guarda, ese pedido llega a
 * ArticleTablePdf, que hace `exit` después de mandar el PDF y corta el proceso de PHPUnit.
 *
 * @group filtros_key_sin_inyeccion
 */
class Key_de_filtro_por_entrada_Test extends FiltrosDeColumnaTestCase
{
    /**
     * El filtro "Nombre que contenga" de la tabla de clientes (models/client.js: name, text).
     *
     * @param  string  $key
     * @param  string  $texto
     * @return array
     */
    protected function filtro_nombre_cliente($key, $texto)
    {
        return $this->filtro_spa($key, 'text', ['que_contenga' => $texto]);
    }

    /**
     * El filtro "Nombre que contenga" de la tabla de artículos (models/article.js: name, textarea).
     *
     * @param  string  $key
     * @param  string  $texto
     * @return array
     */
    protected function filtro_nombre_articulo($key, $texto)
    {
        return $this->filtro_spa($key, 'textarea', ['que_contenga' => $texto]);
    }

    /** @test */
    public function search_rechaza_el_key_inyectado_y_con_name_trae_solo_el_cliente_propio()
    {
        $otro = $this->otro_dueno();
        $texto = 'ZZKEYSEARCH' . uniqid();

        $propio = $this->cliente_de($this->dueno->id, $texto . ' propio');
        $ajeno = $this->cliente_de($otro->id, $texto . ' ajeno');

        foreach (self::KEYS_INYECTADOS as $key) {
            $respuesta = $this->postJson('api/search/client', [
                'filters' => [$this->filtro_nombre_cliente($key, $texto)],
            ]);

            $this->assert_rechazo_de_la_guarda($respuesta, 'search con key "' . $key . '"');
            $this->assertStringNotContainsString($texto, (string) $respuesta->getContent());
        }

        $respuesta = $this->postJson('api/search/client', [
            'filters' => [$this->filtro_nombre_cliente('name', $texto)],
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame([(int) $propio->id], $this->ids_de($respuesta));
        $this->assertNotContains((int) $ajeno->id, $this->ids_de($respuesta));
    }

    /** @test */
    public function global_search_rechaza_el_key_inyectado_y_con_name_trae_solo_el_cliente_propio()
    {
        $otro = $this->otro_dueno();
        $texto = 'ZZKEYGLOBAL' . uniqid();

        $propio = $this->cliente_de($this->dueno->id, $texto . ' propio');
        $ajeno = $this->cliente_de($otro->id, $texto . ' ajeno');

        foreach (self::KEYS_INYECTADOS as $key) {
            $respuesta = $this->postJson('api/global-search/client', [
                'query_value'    => '',
                'props'          => [],
                'relation_props' => [],
                'extra_filters'  => [],
                'filters'        => [$this->filtro_nombre_cliente($key, $texto)],
                'conector'       => 'or',
            ]);

            $this->assert_rechazo_de_la_guarda($respuesta, 'global-search con key "' . $key . '"');
            $this->assertStringNotContainsString($texto, (string) $respuesta->getContent());
        }

        $respuesta = $this->postJson('api/global-search/client', [
            'query_value'    => '',
            'props'          => [],
            'relation_props' => [],
            'extra_filters'  => [],
            'filters'        => [$this->filtro_nombre_cliente('name', $texto)],
            'conector'       => 'or',
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame([(int) $propio->id], $this->ids_de($respuesta));
        $this->assertNotContains((int) $ajeno->id, $this->ids_de($respuesta));
    }

    /** @test */
    public function la_exportacion_a_excel_rechaza_el_key_inyectado_sin_encolar_ni_dejar_historial()
    {
        Queue::fake();

        $otro = $this->otro_dueno();
        $texto = 'ZZKEYEXCEL' . uniqid();

        $propio = $this->articulo_de($this->dueno->id, $texto . ' propio');
        $this->articulo_de($otro->id, $texto . ' ajeno');

        $historiales_antes = ExportHistory::count();

        foreach (self::KEYS_INYECTADOS as $key) {
            $filtros = [$this->filtro_nombre_articulo($key, $texto)];

            $respuesta = $this->getJson('api/article/excel/export?filters=' . urlencode(json_encode($filtros)));

            $this->assert_rechazo_de_la_guarda($respuesta, 'exportación con key "' . $key . '"');
        }

        $this->assertSame($historiales_antes, ExportHistory::count(), 'Un pedido rechazado no puede dejar un historial de exportación pendiente.');
        Queue::assertNothingPushed();

        // El mismo filtro con el key legítimo cuenta solo el artículo propio (es lo que el job
        // reaplica al armar el Excel).
        $filtrado = ArticleExportStreamer::consulta_de_filtros($this->dueno->id, [$this->filtro_nombre_articulo('name', $texto)]);

        $this->assertSame([$propio], array_map('intval', $filtrado['models']->pluck('id')->all()));
    }

    /** @test */
    public function el_borrado_por_filtro_rechaza_el_key_inyectado_y_no_borra_nada()
    {
        Queue::fake();

        $otro = $this->otro_dueno();
        $texto = 'ZZKEYDELETE' . uniqid();

        $propio = $this->articulo_de($this->dueno->id, $texto . ' propio');
        $ajeno = $this->articulo_de($otro->id, $texto . ' ajeno');

        foreach (self::KEYS_INYECTADOS as $key) {
            $respuesta = $this->putJson('api/delete/article', [
                'from_filter' => 1,
                'filter_form' => [$this->filtro_nombre_articulo($key, $texto)],
            ]);

            $this->assert_rechazo_de_la_guarda($respuesta, 'delete por filtro con key "' . $key . '"');
        }

        Queue::assertNothingPushed();

        // Ni el ajeno ni el propio: la operación no corrió.
        $this->assertNotNull(Article::find($ajeno), 'El artículo del otro comercio fue borrado.');
        $this->assertNotNull(Article::find($propio), 'La operación rechazada borró igual el artículo propio.');
        $this->assertSame('active', DB::table('articles')->where('id', $ajeno)->value('status'));
    }

    /** @test */
    public function la_actualizacion_por_filtro_rechaza_el_key_inyectado_y_no_encola_nada()
    {
        Queue::fake();

        $otro = $this->otro_dueno();
        $texto = 'ZZKEYUPDATE' . uniqid();

        $this->articulo_de($this->dueno->id, $texto . ' propio', ['cost' => 100]);
        $ajeno = $this->articulo_de($otro->id, $texto . ' ajeno', ['cost' => 100]);

        $masivas_antes = MasiveUpdate::count();

        foreach (self::KEYS_INYECTADOS as $key) {
            $respuesta = $this->putJson('api/update/article', [
                'from_filter' => 1,
                'filter_form' => [$this->filtro_nombre_articulo($key, $texto)],
                'update_form' => [
                    ['type' => 'number', 'key' => 'increment_cost', 'value' => 5],
                ],
                'models_id'   => [],
            ]);

            $this->assert_rechazo_de_la_guarda($respuesta, 'update por filtro con key "' . $key . '"');
        }

        Queue::assertNothingPushed();
        $this->assertSame($masivas_antes, MasiveUpdate::count(), 'Un pedido rechazado no puede dejar una masiva pendiente.');
        $this->assertEquals(100, (float) DB::table('articles')->where('id', $ajeno)->value('cost'), 'El costo del artículo ajeno cambió.');
    }

    /** @test */
    public function el_buscador_del_modal_ignora_una_prop_inyectada_y_con_name_encuentra_el_propio()
    {
        $otro = $this->otro_dueno();
        $texto = 'ZZKEYMODAL' . uniqid();

        $propio = $this->cliente_de($this->dueno->id, $texto . ' propio');
        // El ajeno se crea último: con el orden del modal (id DESC) queda primero en la página 1.
        $ajeno = $this->cliente_de($otro->id, $texto . ' ajeno');

        /*
         * Cada prop iba concatenada en `whereRaw(prop.' LIKE ?')` adentro de un where(closure):
         * "name)) OR ((name" cierra los paréntesis del closure y deja la condición de dueño afuera;
         * "name)) OR ((1=1" (el del plan) con el criterio "1" deja `(1=1) LIKE '%1%'`, verdadero
         * para toda fila.
         */
        $pedidos = [
            ['props_to_filter' => ['name)) OR ((name'], 'query_value' => $texto],
            ['props_to_filter' => ['name)) OR ((1=1'], 'query_value' => '1'],
        ];

        foreach ($pedidos as $pedido) {
            $respuesta = $this->postJson('api/search-from-modal/client', $pedido);

            $respuesta->assertStatus(200);
            $this->assertNotContains((int) $ajeno->id, $this->ids_de($respuesta), 'search-from-modal con la prop ' . $pedido['props_to_filter'][0] . ' devolvió el cliente de otro comercio.');
        }

        $respuesta = $this->postJson('api/search-from-modal/client', [
            'props_to_filter' => ['name'],
            'query_value'     => $texto,
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame([(int) $propio->id], $this->ids_de($respuesta));
    }

    /**
     * Va último: ver el docblock de la clase.
     *
     * @test
     */
    public function zz_el_pdf_del_catalogo_rechaza_el_key_inyectado_table_pdf()
    {
        $otro = $this->otro_dueno();
        $texto = 'ZZKEYPDF' . uniqid();

        $this->articulo_de($this->dueno->id, $texto . ' propio');
        $this->articulo_de($otro->id, $texto . ' ajeno');

        // tablePdf() exige un perfil de columnas de artículo del dueño antes de mirar los filtros.
        $perfil = PdfColumnProfile::create([
            'user_id'    => $this->dueno->id,
            'model_name' => 'article',
            'name'       => 'Perfil filtros key',
            'columns'    => [],
        ]);

        foreach (self::KEYS_INYECTADOS as $key) {
            $filtros = [$this->filtro_nombre_articulo($key, $texto)];

            // Ruta de web.php, sin Accept: application/json (la SPA la abre en una pestaña): el 422
            // tiene que llegar igual en JSON, no como un redirect.
            $respuesta = $this->get('article/table-pdf?pdf_column_profile_id=' . $perfil->id . '&filters=' . urlencode(json_encode($filtros)));

            $this->assert_rechazo_de_la_guarda($respuesta, 'table-pdf con key "' . $key . '"');
            $this->assertStringNotContainsString('%PDF', (string) $respuesta->getContent());
        }
    }
}
