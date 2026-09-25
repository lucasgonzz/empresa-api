<?php

namespace Tests\Import;

use App\Models\Article;
use App\Models\ImportHistory;
use Illuminate\Http\UploadedFile;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;

/**
 * La importación genera el slug de los artículos (decisión de Lucas, 24/9/2026, misión
 * `importacion-excel-motor-rapido`): "una importación no puede crear artículos sin slug o
 * actualizar el nombre de artículos y no actualizarles el slug".
 *
 * Hasta esta misión todo artículo importado nacía con slug NULL (el bloque que lo generaba
 * en ProcessRow estaba guardado por un isset() inalcanzable) y la tienda no lo podía abrir por
 * URL hasta que alguien lo editara a mano. Es un cambio de comportamiento observable, pedido.
 *
 * La regla es la misma de ArticleHelper::slug() del ABM: Str::slug(nombre) y, si ya está
 * tomado en la cuenta, base-1, base-2... Único por usuario, contando lo que ya existe en la
 * base (ArticleImport::slugs() lo trae por lote, sobre `articles_user_slug_idx`), lo insertado
 * por lotes anteriores de la misma importación y lo generado en el mismo lote.
 *
 * Caja negra: todo pasa por el endpoint real, como el resto de la suite, con fixtures que el
 * propio test escribe con OpenSpout (mismo molde que fixtures/generar.php).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class ImportacionGeneraSlugTest extends ImportTestCase
{
    /** @var array archivos temporales a borrar al terminar cada test */
    protected $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }

        $this->temporales = [];

        parent::tearDown();
    }

    /**
     * Escribe un .xlsx temporal con la cabecera de columnas() y las filas dadas.
     *
     * Orden de columnas (el de ImportTestCase::columnas()): bar_code, sku, provider_code,
     * nombre, costo, precio, stock, iva.
     *
     * @param  array $filas
     * @return string ruta absoluta
     */
    protected function xlsx(array $filas)
    {
        $ruta = sys_get_temp_dir() . '/' . uniqid('slug_') . '.xlsx';

        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($ruta);
        $writer->addRow(WriterEntityFactory::createRowFromArray([
            'codigo_de_barras', 'sku', 'codigo_de_proveedor', 'nombre', 'costo', 'precio', 'stock', 'iva',
        ]));

        foreach ($filas as $fila) {
            $writer->addRow(WriterEntityFactory::createRowFromArray($fila));
        }

        $writer->close();

        $this->temporales[] = $ruta;

        return $ruta;
    }

    /**
     * Una fila nueva (sin bar_code ni sku) con código de proveedor y nombre.
     *
     * @param  string $provider_code
     * @param  string $nombre
     * @param  float  $costo
     * @return array
     */
    protected function fila_nueva($provider_code, $nombre, $costo = 100.0)
    {
        return [null, null, $provider_code, $nombre, $costo, $costo * 2, 1.0, '21'];
    }

    /**
     * Importa un .xlsx por el endpoint real (mismo camino que ImportTestCase::importar(),
     * pero para un archivo fuera de fixtures/) y devuelve el ImportHistory.
     *
     * @param  string $ruta    ruta absoluta del .xlsx
     * @param  array  $config  overrides de configuración
     * @return \App\Models\ImportHistory
     */
    protected function importar_xlsx($ruta, array $config = [])
    {
        /* ArticleController@import mueve el archivo con storeAs(): se importa sobre una copia. */
        $copia = sys_get_temp_dir() . '/' . uniqid('slug_copia_') . '.xlsx';
        copy($ruta, $copia);

        $data = array_merge(
            [
                'models' => new UploadedFile(
                    $copia,
                    basename($ruta),
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    null,
                    true
                ),
                'start_row'   => 2,
                'finish_row'  => 99999,
                'provider_id' => null,
            ],
            self::config_por_defecto(),
            self::columnas(),
            $config
        );

        $this->postJson('/api/article/excel/import', $data)->assertStatus(200);

        $import = ImportHistory::where('user_id', $this->tenant->id)->orderBy('id', 'DESC')->first();

        $this->assertNotNull($import, 'La importación no dejó ImportHistory.');

        $this->assertInvariantesDeConteo($import);

        return $import;
    }

    /**
     * updated_props (el JSON que lee el rollback) de un artículo en los lotes de una
     * importación, fusionado lote a lote. Null si el artículo no fue actualizado.
     *
     * @param  \App\Models\ImportHistory $import
     * @param  int                       $article_id
     * @return array|null
     */
    protected function updated_props($import, $article_id)
    {
        $props = null;

        foreach ($import->chunks()->orderBy('chunk_number')->get() as $chunk) {
            $actualizado = $chunk->articulos_actualizados()->where('articles.id', $article_id)->first();

            if (is_null($actualizado)) {
                continue;
            }

            $decodificado = json_decode($actualizado->pivot->updated_props, true);

            if (is_array($decodificado)) {
                $props = is_null($props) ? $decodificado : array_merge($props, $decodificado);
            }
        }

        return $props;
    }

    /**
     * @param  string $provider_code
     * @return \App\Models\Article
     */
    protected function creado_con_codigo($provider_code)
    {
        $articulo = $this->articulos_creados()->firstWhere('provider_code', $provider_code);

        $this->assertNotNull($articulo, 'No se creó el artículo con código ' . $provider_code);

        return $articulo;
    }

    /**
     * @param  \App\Models\ImportHistory $import
     * @return void
     */
    protected function revertir($import)
    {
        $this->postJson('/api/import-history/rollback/' . $import->id)->assertStatus(202);
    }

    /* =====================================================================
     * Al crear
     * ================================================================== */

    /**
     * Dos filas nuevas con el mismo nombre en el mismo lote: la primera se lleva la base y
     * la segunda el sufijo. Ninguna queda sin slug.
     *
     * @return void
     */
    public function test_dos_filas_nuevas_con_el_mismo_nombre_reciben_la_base_y_el_sufijo_1()
    {
        $this->importar_xlsx($this->xlsx([
            $this->fila_nueva('PC-SLUG-1', 'Taladro'),
            $this->fila_nueva('PC-SLUG-2', 'Taladro'),
        ]));

        $this->assertSame('taladro', $this->creado_con_codigo('PC-SLUG-1')->slug);
        $this->assertSame('taladro-1', $this->creado_con_codigo('PC-SLUG-2')->slug);
    }

    /**
     * Un slug que ya existe en la cuenta no se repite: la fila nueva pasa a base-1, y si
     * base-1 también existe, a base-2.
     *
     * @return void
     */
    public function test_un_slug_ya_tomado_en_la_cuenta_manda_la_fila_nueva_al_sufijo_libre()
    {
        Article::where('id', $this->seed['A9']->id)->update(['slug' => 'taladro']);
        Article::where('id', $this->seed['A10']->id)->update(['slug' => 'taladro-1']);

        $this->importar_xlsx($this->xlsx([
            $this->fila_nueva('PC-SLUG-3', 'Taladro'),
        ]));

        $this->assertSame('taladro-2', $this->creado_con_codigo('PC-SLUG-3')->slug);
    }

    /**
     * Sin nombre no hay slug (mismo criterio que el ABM); con nombre, siempre lo hay.
     *
     * @return void
     */
    public function test_una_fila_sin_nombre_no_recibe_slug_y_una_con_nombre_siempre()
    {
        $this->importar_xlsx($this->xlsx([
            [null, null, 'PC-SLUG-SIN-NOMBRE', null, 100.0, 200.0, 1.0, '21'],
            $this->fila_nueva('PC-SLUG-CON-NOMBRE', 'Sierra circular 7 1/4"'),
        ]));

        $this->assertNull($this->creado_con_codigo('PC-SLUG-SIN-NOMBRE')->slug);
        $this->assertSame('sierra-circular-7-14', $this->creado_con_codigo('PC-SLUG-CON-NOMBRE')->slug);
    }

    /* =====================================================================
     * Al renombrar
     * ================================================================== */

    /**
     * Una fila que cambia el nombre de un artículo existente le regenera el slug y deja
     * `__diff__slug` en updated_props (que es lo que el rollback restaura).
     *
     * @return void
     */
    public function test_renombrar_un_articulo_existente_regenera_el_slug_y_deja_el_diff()
    {
        $a1 = $this->seed['A1'];

        Article::where('id', $a1->id)->update(['slug' => 'art-unico-prov-a']);

        $import = $this->importar_xlsx($this->xlsx([
            $this->fila_nueva('PC-100', 'Amoladora angular'),
        ]));

        $this->assertSame('Amoladora angular', $this->recargar('A1')->name);
        $this->assertSame('amoladora-angular', $this->recargar('A1')->slug);

        $props = $this->updated_props($import, $a1->id);

        $this->assertIsArray($props, 'A1 tenía que quedar registrado como actualizado.');
        $this->assertSame('amoladora-angular', $props['slug'] ?? null);
        $this->assertSame('art-unico-prov-a', $props['__diff__slug']['old'] ?? null, 'Falta el old de __diff__slug.');
        $this->assertSame('amoladora-angular', $props['__diff__slug']['new'] ?? null, 'Falta el new de __diff__slug.');
    }

    /**
     * Si el nombre no cambia, el slug no se toca — ni siquiera cuando hoy es NULL. La
     * importación corrige el slug al renombrar, no reescribe el catálogo entero.
     *
     * @return void
     */
    public function test_si_el_nombre_no_cambia_el_slug_queda_intacto_aunque_sea_null()
    {
        $a1 = $this->seed['A1'];

        $this->assertNull($this->recargar('A1')->slug, 'El escenario sembrado tiene que arrancar con slug NULL.');

        $import = $this->importar_xlsx($this->xlsx([
            /* Mismo nombre que el sembrado, otro costo: hay actualización, pero no de nombre. */
            [null, null, 'PC-100', $a1->name, 999.0, null, null, '21'],
        ]));

        $this->assertDecimal(999, $this->recargar('A1')->cost, 'La fila tenía que actualizar el costo.');
        $this->assertNull($this->recargar('A1')->slug);

        $props = $this->updated_props($import, $a1->id);

        $this->assertIsArray($props);
        $this->assertArrayNotHasKey('slug', $props);
        $this->assertArrayNotHasKey('__diff__slug', $props);
    }

    /**
     * Un artículo que se renombra a un nombre cuyo slug coincide con el suyo conserva su
     * slug: su propio slug no cuenta como "tomado" contra sí mismo.
     *
     * @return void
     */
    public function test_renombrar_a_un_nombre_con_el_mismo_slug_conserva_el_slug()
    {
        $a1 = $this->seed['A1'];

        Article::where('id', $a1->id)->update(['slug' => 'art-unico-prov-a']);

        $import = $this->importar_xlsx($this->xlsx([
            $this->fila_nueva('PC-100', 'ART UNICO PROV A'),
        ]));

        $this->assertSame('ART UNICO PROV A', $this->recargar('A1')->name, 'El nombre tenía que cambiar (mayúsculas).');
        $this->assertSame('art-unico-prov-a', $this->recargar('A1')->slug);

        $props = $this->updated_props($import, $a1->id);

        $this->assertIsArray($props);
        $this->assertArrayNotHasKey('__diff__slug', $props, 'Un slug que no cambia no tiene diff.');
    }

    /**
     * El slug que un artículo renombrado deja libre puede usarlo una fila nueva del mismo
     * lote, y el renombrado toma el sufijo libre si su nuevo nombre ya existía.
     *
     * @return void
     */
    public function test_el_slug_liberado_por_un_renombre_lo_puede_usar_una_fila_nueva_del_lote()
    {
        $a1 = $this->seed['A1'];

        Article::where('id', $a1->id)->update(['slug' => 'taladro']);
        Article::where('id', $this->seed['A9']->id)->update(['slug' => 'martillo']);

        $this->importar_xlsx($this->xlsx([
            $this->fila_nueva('PC-100', 'Martillo'),
            $this->fila_nueva('PC-SLUG-4', 'Taladro'),
        ]));

        $this->assertSame('martillo-1', $this->recargar('A1')->slug);
        $this->assertSame('taladro', $this->creado_con_codigo('PC-SLUG-4')->slug);
    }

    /* =====================================================================
     * Entre lotes
     * ================================================================== */

    /**
     * Nombres repetidos ENTRE lotes: el lote 2 ve lo que insertó el lote 1 (la consulta de
     * slugs corre por lote) y sigue la numeración en vez de repetirla.
     *
     * @return void
     */
    public function test_dos_lotes_con_nombres_repetidos_entre_lotes_dan_slugs_distintos()
    {
        config(['app.ARTICLE_EXCEL_CHUNK_SIZE' => 2]);

        $import = $this->importar_xlsx($this->xlsx([
            $this->fila_nueva('PC-LOTE-1', 'Sierra'),
            $this->fila_nueva('PC-LOTE-2', 'Sierra'),
            $this->fila_nueva('PC-LOTE-3', 'Sierra'),
            $this->fila_nueva('PC-LOTE-4', 'Sierra'),
        ]));

        $this->assertSame(2, (int) $import->total_chunks, 'El test necesita dos lotes.');

        $slugs = [];

        foreach (['PC-LOTE-1', 'PC-LOTE-2', 'PC-LOTE-3', 'PC-LOTE-4'] as $codigo) {
            $slugs[] = $this->creado_con_codigo($codigo)->slug;
        }

        $this->assertSame(['sierra', 'sierra-1', 'sierra-2', 'sierra-3'], $slugs);
    }

    /* =====================================================================
     * Rollback
     * ================================================================== */

    /**
     * El rollback devuelve el slug viejo del artículo renombrado (y el NULL, si era NULL).
     *
     * @return void
     */
    public function test_el_rollback_devuelve_el_slug_viejo()
    {
        $a1 = $this->seed['A1'];
        $a2 = $this->seed['A2'];

        Article::where('id', $a1->id)->update(['slug' => 'art-unico-prov-a']);

        $this->assertNull($this->recargar('A2')->slug);

        $import = $this->importar_xlsx($this->xlsx([
            $this->fila_nueva('PC-100', 'Amoladora angular'),
            $this->fila_nueva('PC-200', 'Pistola de calor'),
        ]), ['actualizar_articulos_de_otro_proveedor' => true]);

        $this->assertSame('amoladora-angular', $this->recargar('A1')->slug);
        $this->assertSame('pistola-de-calor', $this->recargar('A2')->slug);

        $this->revertir($import);

        $this->assertSame('art-unico-prov-a', $this->recargar('A1')->slug);
        $this->assertSame('Art unico prov A', $this->recargar('A1')->name);
        $this->assertNull($this->recargar('A2')->slug);
    }
}
