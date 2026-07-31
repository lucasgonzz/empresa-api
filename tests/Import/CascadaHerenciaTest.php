<?php

namespace Tests\Import;

use App\Models\Article;
use App\Models\ImportConflict;

/**
 * Cascada de herencia de identificadores pendientes (grupo 287, prompt 02;
 * corregido por el correctivo del grupo 290, prompt 01).
 *
 * Cuando una fila trae un bar_code y/o sku que NO matchean nada por su propio
 * escalón, pero la fila igual termina matcheando un artículo más abajo en la
 * cascada (sku o provider_code), ese identificador "pendiente" se le hereda al
 * artículo matcheado (regla de Lucas, 30/7/2026, prompt 08 grupo 265) -- siempre
 * que el match sea único y que ningún otro artículo de este mismo chunk ya se
 * haya quedado con ese mismo valor (identificadores_asignados_en_chunk).
 *
 * Fixture propio: 09_cascada_herencia.xlsx (6 filas, F2-F7), contra el escenario
 * sembrado por ImportTestSeeder más un artículo extra creado en setUp() (ver
 * abajo). Cada fila ejercita una rama distinta:
 *
 *   F2  bar_code nuevo (7799201) + sku existente (SKU-001, matchea A1 único) ->
 *       hereda el bar_code a A1.
 *   F3  bar_code nuevo (7799202) + sku nuevo (SKU-N-300) + provider_code
 *       PC-T-UNICO (matchea único al artículo creado en setUp) -> hereda AMBOS
 *       identificadores al mismo artículo.
 *   F4  bar_code nuevo (7799203) + provider_code PC-DUP (matchea A3 Y A4,
 *       permitir_provider_code_repetido = true) -> match múltiple real: el
 *       bar_code pendiente NO se puede asignar a ninguno de los dos, queda un
 *       conflicto.
 *   F5  sku existente (SKU-003, matchea A3 único) + bar_code nuevo (7799204) ->
 *       hereda el bar_code a A3 (primera asignación de ese valor en el chunk).
 *   F6  sku existente (SKU-004, matchea A4 único) + el MISMO bar_code nuevo
 *       (7799204) -> ya está asignado a A3 en este chunk: la guarda
 *       identificadores_asignados_en_chunk lo bloquea y registra otro conflicto.
 *   F7  bar_code existente (7790002, matchea A2 DIRECTO -- el propio escalón
 *       bar_code, sin cascada) + sku nuevo (SKU-N-777). A2 es de otro proveedor
 *       (B); con actualizar_articulos_de_otro_proveedor = true el update se
 *       aplica igual. El sku entra como campo normal (get_modified_fields()),
 *       no por la cascada de identificadores pendientes.
 *
 * OJO AL LEER LOS TESTS: las 6 filas comparten UNA sola importación por test
 * (cada método la dispara de nuevo vía importar_cascada(), pero siempre sobre
 * el mismo fixture completo), así que F5 y F6 -- que procesan DESPUES de F4 --
 * pisan el bar_code y el costo que F4 dejó en A3 y A4. Cada test assertea
 * solamente lo que queda ESTABLE al final del import completo; ver el comentario
 * de cada método para el detalle.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class CascadaHerenciaTest extends ImportTestCase
{
    const ARCHIVO = '09_cascada_herencia.xlsx';

    /**
     * Artículo que el fixture necesita y que ImportTestSeeder no trae: matchea
     * F3 por provider_code, sin sku ni bar_code propios, para que la cascada
     * tenga que heredarle los dos (test_herencia_doble_via_provider_code_unico).
     *
     * @var \App\Models\Article
     */
    protected $pc_t_unico;

    /**
     * setUp de la clase base (tenant, providers, escenario A1..A15) más el
     * artículo PC-T-UNICO propio de este archivo.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Creado directo con Eloquent (mismo patrón que ImportTestSeeder::crear_article()),
        // para no depender de $fillable ni de un helper de creación que pueda
        // descartar algún campo en silencio.
        $articulo = new Article();

        $articulo->user_id       = $this->tenant->id;
        $articulo->provider_id   = $this->providers['A']->id;
        $articulo->provider_code = 'PC-T-UNICO';
        $articulo->sku           = null;
        $articulo->bar_code      = null;
        $articulo->name          = 'Art target unico';
        $articulo->cost          = 500;
        $articulo->final_price   = 1000;
        $articulo->stock         = 0;
        $articulo->iva_id        = 2;
        $articulo->status        = 'active';
        $articulo->online        = 1;

        $articulo->save();

        $this->pc_t_unico = $articulo;
    }

    /**
     * Dispara la importación del fixture de este archivo con la configuración
     * común a los 6 tests: permitir_provider_code_repetido = true (sin esto F4
     * daría ambiguo en vez de multi-match) y actualizar_articulos_de_otro_proveedor
     * = true (sin esto F7 no podría actualizar a A2, que es del proveedor B).
     *
     * Verificado que este segundo flag no cambia el resultado de ningún otro
     * test: A2 es el único artículo de otro proveedor que este fixture toca.
     *
     * @return \App\Models\ImportHistory
     */
    protected function importar_cascada()
    {
        return $this->importar(self::ARCHIVO, [
            'provider_id'                            => $this->providers['A']->id,
            'permitir_provider_code_repetido'         => true,
            'actualizar_articulos_de_otro_proveedor'  => true,
        ]);
    }

    /**
     * Recarga el artículo PC-T-UNICO desde la base (no está en $this->seed,
     * así que no puede pasar por recargar()).
     *
     * @return \App\Models\Article
     */
    protected function recargar_pc_t_unico()
    {
        return Article::find($this->pc_t_unico->id);
    }

    /* ------------------------------------------------------------------
     * F2 - herencia simple via sku
     * ------------------------------------------------------------------ */

    /**
     * F2: bar_code nuevo (7799201) no matchea nada por sí mismo; el sku
     * (SKU-001) matchea a A1 único. El bar_code pendiente se le hereda a A1.
     *
     * @return void
     */
    public function test_bar_code_nuevo_hereda_via_sku_existente()
    {
        $this->importar_cascada();

        $a1 = $this->recargar('A1');

        $this->assertSame('7799201', $a1->bar_code, 'A1 tiene que heredar el bar_code pendiente de F2.');
        $this->assertDecimal(111, $a1->cost, 'A1 se tiene que actualizar con el resto de los datos de F2.');
    }

    /* ------------------------------------------------------------------
     * F3 - herencia doble via provider_code
     * ------------------------------------------------------------------ */

    /**
     * F3: bar_code nuevo (7799202) y sku nuevo (SKU-N-300), ninguno matchea
     * nada por sí mismo; el provider_code (PC-T-UNICO) matchea único al
     * artículo creado en setUp(). Los DOS identificadores pendientes se le
     * heredan al mismo artículo.
     *
     * @return void
     */
    public function test_herencia_doble_via_provider_code_unico()
    {
        $this->importar_cascada();

        $articulo = $this->recargar_pc_t_unico();

        $this->assertSame('7799202', $articulo->bar_code, 'El artículo PC-T-UNICO tiene que heredar el bar_code pendiente de F3.');
        $this->assertSame('SKU-N-300', $articulo->sku, 'El artículo PC-T-UNICO tiene que heredar el sku pendiente de F3.');
        $this->assertDecimal(222, $articulo->cost, 'Se tiene que actualizar con el resto de los datos de F3.');
    }

    /* ------------------------------------------------------------------
     * F4 - match multiple real: identificador pendiente no se asigna
     * ------------------------------------------------------------------ */

    /**
     * F4: bar_code nuevo (7799203) + provider_code PC-DUP, que matchea DOS
     * artículos (A3 y A4, permitir_provider_code_repetido = true). El
     * invariante de Lucas (prompt 08 grupo 265) prohíbe asignar un
     * identificador único a más de un artículo: el bar_code pendiente se
     * descarta en los dos y queda un import_conflict identificador_sin_asignar
     * con valor '7799203' (distinto del que deja F6, valor '7799204' -- se
     * filtra por valor, no por el total del tipo, mismo criterio que usa
     * MatchUnicoProviderCodeTest).
     *
     * OJO AL LEER: no se assertea bar_code ni cost de A3 en este test, ni cost
     * de A4. Las 6 filas comparten un solo import; F5 y F6 procesan DESPUÉS de
     * F4 y pisan el bar_code y el costo que F4 dejó en A3 y A4 (ver
     * test_dos_filas_no_pueden_heredar_el_mismo_valor_en_el_mismo_chunk, que
     * fija el estado final real de esos dos campos). Lo único estable de F4 al
     * final del import es el conflicto que registró y el bar_code de A4, que
     * NUNCA llega a cambiar en todo el chunk: ni F4 (match múltiple) ni F6
     * (bloqueado por la guarda intra-chunk) logran asignárselo.
     *
     * @return void
     */
    public function test_bar_code_pendiente_sobre_provider_code_repetido_no_se_asigna()
    {
        $import = $this->importar_cascada();

        $a4 = $this->recargar('A4');

        $conflictos = ImportConflict::where('import_history_id', $import->id)
            ->where('tipo', 'identificador_sin_asignar')
            ->where('campo', 'bar_code')
            ->where('valor', '7799203')
            ->get();

        $this->assertCount(1, $conflictos, 'Tiene que quedar exactamente un conflicto de bar_code sin asignar para el valor de F4.');

        $this->assertSame(
            '7790004',
            $a4->bar_code,
            'A4 nunca llega a recibir un bar_code nuevo: ni F4 (PC-DUP repetido, match múltiple) ni F6 (bloqueado por la guarda intra-chunk) logran asignárselo.'
        );
    }

    /* ------------------------------------------------------------------
     * F5/F6 - guarda intra-chunk: dos filas no pueden heredar el mismo valor
     * ------------------------------------------------------------------ */

    /**
     * F5 (sku SKU-003 matchea A3 único; bar_code pendiente 7799204 se le
     * asigna -- primera asignación de ese valor en el chunk) y F6 (sku SKU-004
     * matchea A4 único; el MISMO bar_code pendiente 7799204 ya está asignado a
     * A3 en este chunk): la guarda identificadores_asignados_en_chunk
     * (ProcessRow, prompt 08 grupo 265) impide que dos filas del mismo chunk
     * hereden el mismo valor único a artículos distintos.
     *
     * OJO al orden: F5 procesa antes que F6, así que A3 gana la asignación y
     * A4 se queda con su bar_code original del seeder. El costo de los dos SÍ
     * se actualiza en cada caso (no es un identificador único, no tiene
     * guarda): A3 = 444 (de F5), A4 = 555 (de F6, que actualiza el costo aunque
     * no gane el bar_code).
     *
     * @return void
     */
    public function test_dos_filas_no_pueden_heredar_el_mismo_valor_en_el_mismo_chunk()
    {
        $import = $this->importar_cascada();

        $a3 = $this->recargar('A3');
        $a4 = $this->recargar('A4');

        $this->assertSame('7799204', $a3->bar_code, 'A3 (F5, primera en procesar) tiene que quedar con el bar_code pendiente.');
        $this->assertSame('7790004', $a4->bar_code, 'A4 (F6, segunda) no puede recibir el mismo bar_code: ya lo tiene A3 en este chunk.');

        $this->assertDecimal(444, $a3->cost, 'A3 se actualiza con los datos de F5.');
        $this->assertDecimal(555, $a4->cost, 'A4 se actualiza con los datos de F6, aunque no gane el bar_code.');

        $conflicto = ImportConflict::where('import_history_id', $import->id)
            ->where('tipo', 'identificador_sin_asignar')
            ->where('campo', 'bar_code')
            ->where('valor', '7799204')
            ->first();

        $this->assertNotNull($conflicto, 'Tiene que quedar registrado el conflicto de F6 sobre el bar_code ya asignado en el chunk.');

        $article_ids = $conflicto->article_ids;
        $this->assertIsArray($article_ids, 'El conflicto tiene que guardar los ids de los artículos involucrados.');
        $this->assertContains($a3->id, $article_ids, 'El conflicto tiene que mencionar a A3 (quien ya tenía el valor asignado).');
        $this->assertContains($a4->id, $article_ids, 'El conflicto tiene que mencionar a A4 (quien lo intentó y no pudo).');
    }

    /* ------------------------------------------------------------------
     * F7 - match directo por bar_code: sku como campo normal
     * ------------------------------------------------------------------ */

    /**
     * F7: bar_code 7790002 matchea DIRECTO a A2 (proveedor B) -- match en el
     * PRIMER escalón, no hay cascada de identificadores pendientes para esta
     * fila. Con actualizar_articulos_de_otro_proveedor = true (parte de la
     * configuración común, ver importar_cascada()), omitir_por_pertencer_a_otro_proveedor()
     * no bloquea el update aunque A2 sea de otro proveedor.
     *
     * Esto NO es la cascada: el sku (SKU-N-777) entra como un campo normal vía
     * get_modified_fields(), no vía identificadores_pendientes -- no hay
     * ninguna asignación "heredada" acá, solo un update común de un artículo
     * ya matcheado por su propio bar_code. El bar_code de A2 no cambia porque
     * get_modified_fields() ignora bar_code cuando la fila no trae un id
     * explícito (bar_code_identity guard).
     *
     * @return void
     */
    public function test_match_directo_por_bar_code_actualiza_el_sku_como_campo_normal()
    {
        $import = $this->importar_cascada();

        $a2 = $this->recargar('A2');

        $this->assertSame('SKU-N-777', $a2->sku, 'A2 tiene que quedar con el sku nuevo de F7.');
        $this->assertDecimal(666, $a2->cost, 'A2 se tiene que actualizar con el resto de los datos de F7.');
        $this->assertSame('7790002', $a2->bar_code, 'El bar_code de A2 no cambia: es el mismo valor que ya matcheó.');

        $this->assertSame(
            0,
            ImportConflict::where('import_history_id', $import->id)
                ->where('tipo', 'identificador_sin_asignar')
                ->where('valor', 'SKU-N-777')
                ->count(),
            'No tiene que quedar ningún conflicto sobre el sku de F7: el match fue directo, no hubo cascada.'
        );
    }

    /* ------------------------------------------------------------------
     * Vista completa de la importación
     * ------------------------------------------------------------------ */

    /**
     * Buckets de matching de las 6 filas del fixture: bar_code (F7 -> A2),
     * sku (F2 -> A1, F5 -> A3, F6 -> A4), provider_code (F3 -> PC-T-UNICO,
     * F4 -> A3/A4 en un solo bucket aunque matchee dos artículos). Y el
     * conteo de artículos creados por la IMPORTACIÓN: ninguna de las 6 filas
     * crea un artículo nuevo, pero articulos_creados() cuenta cualquier
     * artículo del tenant que no esté en $this->seed -- y PC-T-UNICO, creado
     * en setUp() antes de importar, no está ahí. El número real es 1, no 0.
     *
     * @return void
     */
    public function test_buckets_de_la_importacion_completa()
    {
        $import = $this->importar_cascada();

        $this->assertBuckets($import, [
            'bar_code'      => 1,
            'sku'           => 3,
            'provider_code' => 2,
        ]);

        $this->assertCount(
            1,
            $this->articulos_creados(),
            'La importación no crea ningún artículo nuevo: el único "no sembrado" es PC-T-UNICO, creado en setUp() antes de importar.'
        );
    }
}
