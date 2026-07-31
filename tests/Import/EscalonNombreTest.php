<?php

namespace Tests\Import;

use App\Models\Article;
use App\Models\ImportConflict;

/**
 * El ultimo escalon de la cadena de identificacion: `name` (grupo 287, prompt 03).
 *
 * `find_with_index()` solo llega a este escalon cuando la fila no matcheo nada por
 * id/bar_code/sku/provider_code Y, ademas, ninguno de esos escalones cerro la busqueda
 * de forma terminal antes de llegar aca (el caso mas importante: un provider_code que
 * no matchea nada corta la cadena en su propio bloque con `return con_escalon(null, null)`
 * -- nunca consulta el nombre, ver ArticleIndexCache::find_with_index() paso 4).
 *
 * Fixture propio: 10_escalon_nombre.xlsx (5 filas, F2-F6), contra el escenario sembrado
 * por ImportTestSeeder (A9 = 'Art solo por nombre', sin ningun codigo; A10/A11 = 'Art
 * nombre repetido', nombre duplicado en base) mas un articulo extra creado en setUp()
 * (ver abajo). Cada fila ejercita una rama distinta:
 *
 *   F2  sin ningun codigo, nombre 'Art solo por nombre' -> matchea A9 unico (primera
 *       actualizacion de costo, 911).
 *   F3  sin ningun codigo, nombre 'Art nombre repetido' -> matchea A10 Y A11 (ambiguo
 *       contra la base, no toca ninguno de los dos).
 *   F4  bar_code nuevo (7799301, no matchea nada por si mismo) + nombre 'Art solo por
 *       nombre' -> matchea A9 unico otra vez (segunda actualizacion de costo, 913) y
 *       le hereda el bar_code pendiente (cascada llegando hasta el nombre).
 *   F5  provider_code 'PC-N-999' (no existe) + nombre 'Art solo por nombre' -> el
 *       escalon provider_code corta la cadena ANTES de llegar al nombre: se crea un
 *       articulo nuevo, A9 no recibe nada de esta fila.
 *   F6  sin ningun codigo, nombre '  ART NORMALIZACION TEST  ' (con espacios y
 *       mayusculas) -> matchea al articulo T-NORM via normalize_name_for_match().
 *
 * OJO AL LEER LOS TESTS: las 5 filas comparten UNA sola importacion por test (cada
 * metodo la dispara de nuevo via importar_escalon_nombre(), pero siempre sobre el mismo
 * fixture completo), asi que A9 termina con el costo de F4 (913), la ultima fila que lo
 * toca -- F2 lo actualiza primero con 911 y F4 lo pisa despues.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promocion de constructor, readonly, enum ni #[...].
 */
class EscalonNombreTest extends ImportTestCase
{
    const ARCHIVO = '10_escalon_nombre.xlsx';

    /**
     * Articulo que el fixture necesita y que ImportTestSeeder no trae: sin ningun
     * codigo, solo alcanzable por el escalon name, para fijar
     * normalize_name_for_match() (test_el_nombre_se_normaliza_para_matchear).
     *
     * @var \App\Models\Article
     */
    protected $t_norm;

    /**
     * setUp de la clase base (tenant, providers, escenario A1..A15) mas el articulo
     * T-NORM propio de este archivo.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Creado directo con Eloquent (mismo patron que ImportTestSeeder::crear_article()),
        // para no depender de $fillable ni de un helper de creacion que pueda descartar
        // algun campo en silencio.
        $articulo = new Article();

        $articulo->user_id       = $this->tenant->id;
        $articulo->provider_id   = $this->providers['A']->id;
        $articulo->provider_code = null;
        $articulo->sku           = null;
        $articulo->bar_code      = null;
        $articulo->name          = 'Art normalizacion test';
        $articulo->cost          = 100;
        $articulo->final_price   = 200;
        $articulo->stock         = 0;
        $articulo->iva_id        = 2;
        $articulo->status        = 'active';
        $articulo->online        = 1;

        $articulo->save();

        $this->t_norm = $articulo;
    }

    /**
     * Dispara la importacion del fixture de este archivo con la configuracion por
     * defecto (ninguna de las 5 filas necesita overrides especiales).
     *
     * @return \App\Models\ImportHistory
     */
    protected function importar_escalon_nombre()
    {
        return $this->importar(self::ARCHIVO, [
            'provider_id' => $this->providers['A']->id,
        ]);
    }

    /**
     * Recarga T-NORM desde la base (no esta en $this->seed, asi que no puede pasar
     * por recargar()).
     *
     * @return \App\Models\Article
     */
    protected function recargar_t_norm()
    {
        return Article::find($this->t_norm->id);
    }

    /* ------------------------------------------------------------------
     * F2 - match unico por nombre
     * ------------------------------------------------------------------ */

    /**
     * F2: sin ningun codigo, nombre 'Art solo por nombre' matchea A9 unico (el
     * unico sembrado sin bar_code/sku/provider_code). A9 termina con el costo de
     * F4 (913), que lo pisa despues en el mismo import -- ver
     * test_la_cascada_llega_hasta_el_nombre_y_hereda.
     *
     * @return void
     */
    public function test_nombre_unico_matchea_y_actualiza()
    {
        $this->importar_escalon_nombre();

        $a9 = $this->recargar('A9');

        $this->assertDecimal(913, $a9->cost, 'A9 tiene que quedar con el costo de F4 (la ultima fila que lo toca en este import), no el de F2.');
    }

    /* ------------------------------------------------------------------
     * F3 - nombre repetido en base: ambiguo, no toca nada
     * ------------------------------------------------------------------ */

    /**
     * F3: sin ningun codigo, nombre 'Art nombre repetido' matchea DOS articulos
     * sembrados (A10 y A11, mismo nombre). El escalon name no tiene bandera de
     * configuracion: siempre da ambiguo con mas de un candidato y no toca ninguno
     * de los dos. Complementa el test de Servian (nombre repetido intra-archivo);
     * este es contra la base.
     *
     * @return void
     */
    public function test_nombre_repetido_en_base_es_ambiguo_y_no_toca_nada()
    {
        $import = $this->importar_escalon_nombre();

        $a10 = $this->recargar('A10');
        $a11 = $this->recargar('A11');

        $this->assertDecimal(1000, $a10->cost, 'A10 no se tiene que tocar: el match de F3 fue ambiguo.');
        $this->assertDecimal(1100, $a11->cost, 'A11 no se tiene que tocar: el match de F3 fue ambiguo.');

        $conflicto = ImportConflict::where('import_history_id', $import->id)
            ->where('tipo', 'ambiguo')
            ->where('campo', 'name')
            ->first();

        $this->assertNotNull($conflicto, 'Tiene que quedar registrado el conflicto ambiguo de F3.');

        $article_ids = $conflicto->article_ids;
        $this->assertIsArray($article_ids, 'El conflicto tiene que guardar los ids de los articulos candidatos.');
        $this->assertContains($a10->id, $article_ids, 'El conflicto tiene que mencionar a A10.');
        $this->assertContains($a11->id, $article_ids, 'El conflicto tiene que mencionar a A11.');
    }

    /* ------------------------------------------------------------------
     * F4 - la cascada de pendientes llega hasta el nombre y hereda
     * ------------------------------------------------------------------ */

    /**
     * F4: bar_code nuevo (7799301) no matchea nada por si mismo; el nombre 'Art
     * solo por nombre' matchea A9 unico (el mismo articulo que F2). El bar_code
     * pendiente se le hereda a A9 -- find_with_index() acumula los pendientes en
     * el bloque bar_code/sku y el match por nombre los recibe igual que cualquier
     * otro escalon.
     *
     * @return void
     */
    public function test_la_cascada_llega_hasta_el_nombre_y_hereda()
    {
        $this->importar_escalon_nombre();

        $a9 = $this->recargar('A9');

        $this->assertSame('7799301', $a9->bar_code, 'A9 tiene que heredar el bar_code pendiente de F4.');
        $this->assertDecimal(913, $a9->cost, 'A9 se tiene que actualizar con el resto de los datos de F4.');
    }

    /* ------------------------------------------------------------------
     * F5 - provider_code sin match corta la cadena antes del nombre
     * ------------------------------------------------------------------ */

    /**
     * F5: provider_code 'PC-N-999' (no existe) + el nombre EXACTO de A9 ('Art solo
     * por nombre'). Esto NO llega al escalon name: es el
     * `return self::con_escalon(null, null)` del final del escalon 4 (provider_code)
     * en find_with_index() -- una fila con provider_code es terminal, nunca
     * consulta el nombre. Por eso se crea un articulo NUEVO (con ese provider_code
     * y ese nombre) y A9 no recibe nada de esta fila.
     *
     * Comportamiento a fijar: si algun dia se decide que un provider_code sin
     * match SI caiga al nombre, este test tiene que fallar y forzar la
     * conversacion.
     *
     * @return void
     */
    public function test_provider_code_sin_match_corta_la_cadena_antes_del_nombre()
    {
        $this->importar_escalon_nombre();

        $creados = $this->articulos_creados();

        $this->assertCount(1, $creados, 'F5 tiene que crear exactamente un articulo nuevo: el provider_code corta la cadena antes de consultar el nombre.');
        $this->assertSame('PC-N-999', $creados->first()->provider_code, 'El articulo creado tiene que llevar el provider_code de F5.');

        $a9 = $this->recargar('A9');

        $this->assertNotSame('PC-N-999', $a9->provider_code, 'A9 no tiene que recibir el provider_code de F5: nunca llego a matchear por nombre.');
    }

    /* ------------------------------------------------------------------
     * F6 - normalizacion de nombre
     * ------------------------------------------------------------------ */

    /**
     * F6: nombre '  ART NORMALIZACION TEST  ' (mayusculas y espacios de sobra)
     * matchea a T-NORM ('Art normalizacion test') via
     * normalize_name_for_match() -- mb_strtolower + colapso de espacios, sin
     * sacar acentos ni puntuacion. No se crea ningun articulo nuevo con ese
     * nombre.
     *
     * @return void
     */
    public function test_el_nombre_se_normaliza_para_matchear()
    {
        $this->importar_escalon_nombre();

        $t_norm = $this->recargar_t_norm();

        $this->assertDecimal(915, $t_norm->cost, 'T-NORM se tiene que actualizar con los datos de F6.');

        $creados = $this->articulos_creados();

        $this->assertCount(1, $creados, 'Solo F5 crea un articulo nuevo en este fixture; F6 matchea a T-NORM y no crea otro.');
        $this->assertSame(
            'PC-N-999',
            $creados->first()->provider_code,
            'El unico articulo creado tiene que ser el de F5 (via provider_code), no uno nuevo con el nombre de F6.'
        );
    }

    /* ------------------------------------------------------------------
     * Vista completa de la importacion
     * ------------------------------------------------------------------ */

    /**
     * Buckets de matching de las 5 filas del fixture: name (F2, F4 y F6, las tres
     * matchean por nombre), ambiguo (F3) y creado_nuevo (F5, cortada por
     * provider_code antes de llegar al nombre).
     *
     * @return void
     */
    public function test_buckets_de_la_importacion_completa()
    {
        $import = $this->importar_escalon_nombre();

        $this->assertBuckets($import, [
            'name'         => 3,
            'ambiguo'      => 1,
            'creado_nuevo' => 1,
        ]);
    }
}
