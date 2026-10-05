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
 * ArticleTablePdf, que hace `exit` después de mandar el PDF y corta el proceso de PHPUnit. Y el de
 * la papelera va anteúltimo: sin la guarda, su `while (true)` no termina. Si alguno de los dos se
 * rompe, la corrida no da un rojo prolijo: se corta o se cuelga. Es la señal.
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

        // Si NINGUNA prop del pedido es columna no hay dónde buscar el texto: no matchea nada (antes
        // el where vacío se descartaba y salía la lista entera del dueño).
        $respuesta = $this->postJson('api/search-from-modal/client', [
            'props_to_filter' => ['name)) OR ((name', 'no_es_columna'],
            'query_value'     => $texto,
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame([], $this->ids_de($respuesta), 'Con todas las props inválidas el modal no tiene que devolver nada.');
    }

    /** @test */
    public function el_excel_de_ventas_rechaza_el_key_inyectado()
    {
        $otro = $this->otro_dueno();
        $texto = 'ZZKEYVENTAS' . uniqid();

        foreach ([$this->dueno->id, $otro->id] as $user_id) {
            DB::table('sales')->insert([
                'user_id'      => $user_id,
                'observations' => $texto,
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        // Los dos botones de Excel de la pantalla de Ventas (POST api/sales/excel/*) pasan los filtros
        // de columna al mismo buscador cuando la pantalla está filtrada.
        foreach (['api/sales/excel/export', 'api/sales/excel/breakdown-export'] as $ruta) {
            foreach (self::KEYS_INYECTADOS as $key) {
                $respuesta = $this->postJson($ruta, [
                    'is_filtered' => true,
                    'filters'     => [$this->filtro_spa($key, 'text', ['que_contenga' => $texto])],
                ]);

                $this->assert_rechazo_de_la_guarda($respuesta, $ruta . ' con key "' . $key . '"');
            }
        }

        $respuesta = $this->postJson('api/sales/excel/export', [
            'is_filtered' => true,
            'filters'     => [$this->filtro_spa('observations', 'text', ['que_contenga' => $texto])],
        ]);

        $respuesta->assertStatus(200);
    }

    /** @test */
    public function una_masiva_no_corre_si_un_filtro_con_criterio_no_se_puede_aplicar()
    {
        Queue::fake();

        $otro = $this->otro_dueno();
        $texto = 'ZZKEYCERO' . uniqid();

        $propio = $this->articulo_de($this->dueno->id, $texto . ' propio');
        $ajeno = $this->articulo_de($otro->id, $texto . ' ajeno');

        /*
         * Un filtro válido más uno cuyo key no es columna PERO trae un criterio real ("igual a 0" en
         * texto, número o select; el checkbox en "desactivado"). Las ramas del helper aplican esos
         * valores sobre una columna, así que no pueden contar como vacíos: si el segundo filtro se
         * descartara en silencio, el borrado correría con el primero solo (DeleteController pide al
         * menos UN filtro efectivo, no que estén todos aplicados). Lo encontró el verificador
         * independiente el 5/10/2026.
         *
         * (Un '' no sirve para esto por HTTP: el middleware ConvertEmptyStringsToNull lo convierte en
         * null antes de llegar al helper, y con null la rama tampoco aplica nada.)
         */
        $criterios_reales = [
            $this->filtro_spa('no_es_columna', 'text', ['igual_que' => '0']),
            $this->filtro_spa('no_es_columna', 'number', ['igual_que' => '0']),
            $this->filtro_spa('no_es_columna', 'select', ['igual_que' => '0']),
            $this->filtro_spa('no_es_columna', 'checkbox', ['checkbox' => 0]),
        ];

        foreach ($criterios_reales as $criterio) {
            $respuesta = $this->putJson('api/delete/article', [
                'from_filter' => 1,
                'filter_form' => [$this->filtro_nombre_articulo('name', $texto), $criterio],
            ]);

            $this->assert_rechazo_de_la_guarda($respuesta, 'delete con ' . json_encode(array_intersect_key($criterio, array_flip(['type', 'igual_que', 'checkbox']))));
        }

        Queue::assertNothingPushed();
        $this->assertNotNull(Article::find($propio), 'El borrado corrió sin el filtro que no se podía aplicar.');
        $this->assertNotNull(Article::find($ajeno));

        // Y los vacíos de la SPA siguen siendo inertes: el mismo pedido con el segundo filtro sin
        // tocar (igual_que '' / 0, checkbox -1) no se rechaza.
        $inertes = [
            $this->filtro_spa('no_es_columna', 'text'),
            $this->filtro_spa('no_es_columna', 'select'),
            $this->filtro_spa('no_es_columna', 'checkbox'),
        ];

        $respuesta = $this->postJson('api/search/article', [
            'filters' => array_merge([$this->filtro_nombre_articulo('name', $texto)], $inertes),
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame([$propio], $this->ids_de($respuesta));
    }

    /** @test */
    public function el_orden_con_direccion_o_columna_de_relacion_hostiles_no_llega_al_sql()
    {
        $otro = $this->otro_dueno();
        $texto = 'ZZKEYORDEN' . uniqid();

        $propio = $this->articulo_de($this->dueno->id, $texto . ' propio');
        $ajeno = $this->articulo_de($otro->id, $texto . ' ajeno');

        // La dirección también sale del pedido: solo asc / desc.
        $respuesta = $this->postJson('api/search/article', [
            'filters' => [
                $this->filtro_nombre_articulo('name', $texto),
                $this->filtro_spa('name', 'textarea', ['ordenar_de' => 'ASC, (SELECT 1)']),
            ],
        ]);
        $this->assert_rechazo_de_la_guarda($respuesta, 'ordenar_de hostil');

        // La columna visible de la relación (order_relation_prop) tiene que ser una columna de la
        // tabla relacionada; si no, se ordena por el FK. Nunca entra al SQL.
        foreach (['name) OR (1=1', 'name`, (SELECT 1) AS `x', 'no_es_columna'] as $prop) {
            $respuesta = $this->postJson('api/search/article', [
                'filters' => [
                    $this->filtro_nombre_articulo('name', $texto),
                    $this->filtro_spa('category_id', 'search', ['ordenar_de' => 'DESC'], $prop),
                ],
            ]);

            $respuesta->assertStatus(200);
            $this->assertSame([$propio], $this->ids_de($respuesta), 'order_relation_prop "' . $prop . '"');
            $this->assertNotContains($ajeno, $this->ids_de($respuesta));
        }
    }

    /** @test */
    public function el_pdf_de_clientes_rechaza_filtros_mal_armados()
    {
        // La forma del pedido sigue siendo de ClientController::pdf (cada filtro, un objeto con su
        // key): el helper saltearía estos en silencio y saldría el PDF con todos los clientes.
        foreach (['[1]', '[{"type":"text","que_contenga":"x"}]', '[{"key":["name"],"type":"text"}]'] as $filtros) {
            $respuesta = $this->get('client/pdf?filters=' . urlencode($filtros));

            $this->assertSame(422, $respuesta->getStatusCode(), 'client/pdf con filters=' . $filtros);
        }

        // Y un key inyectado lo rechaza la guarda del helper, también en esta ruta de web.php.
        $filtros = json_encode([$this->filtro_nombre_cliente('1=1 OR name', 'x')]);
        $this->assert_rechazo_de_la_guarda($this->get('client/pdf?filters=' . urlencode($filtros)), 'client/pdf con key inyectado');
    }

    /**
     * Va anteúltimo, por lo mismo que el de table-pdf: sin la guarda, `restaurar_filtrados()` drena
     * la papelera con un `while (true)` sobre la página 1 y SALTEA (sin restaurar) lo que no es del
     * dueño; con el key inyectado la página 1 trae siempre filas ajenas y el bucle no termina.
     *
     * @test
     */
    public function zy_restaurar_filtrados_de_la_papelera_rechaza_el_key_inyectado()
    {
        $otro = $this->otro_dueno();
        $texto = 'ZZKEYPAPELERA' . uniqid();
        $borrado = date('Y-m-d H:i:s');

        $propio = $this->articulo_de($this->dueno->id, $texto . ' propio', ['deleted_at' => $borrado]);
        $ajeno = $this->articulo_de($otro->id, $texto . ' ajeno', ['deleted_at' => $borrado]);

        foreach (self::KEYS_INYECTADOS as $key) {
            $respuesta = $this->postJson('api/papelera/restaurar-filtrados/article', [
                'filters' => [$this->filtro_nombre_articulo($key, $texto)],
            ]);

            $this->assert_rechazo_de_la_guarda($respuesta, 'papelera con key "' . $key . '"');
        }

        // La operación no corrió: los dos siguen en la papelera.
        $this->assertNotNull(DB::table('articles')->where('id', $propio)->value('deleted_at'));
        $this->assertNotNull(DB::table('articles')->where('id', $ajeno)->value('deleted_at'));
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
