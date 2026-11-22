<?php

namespace Tests\Import;

use App\Imports\ArticleImport;
use App\Jobs\ProcessArticleChunk;
use Illuminate\Support\Facades\DB;

/**
 * Misión historial-precios-proveedor, ronda 2 (ADENDA 1 del plan, REVISION-1-C-spa-contrato #1): bandera
 * propia del cruce por IDENTIFICACIÓN con artículos de otros proveedores.
 *
 * P3 aprobada: cada pregunta del importador decide SU caso. `update_other_provider_by_identification`
 * gobierna sólo los artículos de otro proveedor hallados por número interno, código de barras, SKU o
 * nombre; `update_other_provider_by_provider_code` sigue gobernando sólo el cruce por código de proveedor.
 * Ausente (null) = vale lo mismo que `actualizar_articulos_de_otro_proveedor` (comportamiento de hoy).
 *
 * Escenario (Rosario manda la lista, los dos artículos son de BA):
 *   - Taladro (barras 8800201, código BA-1, costo 1000): la fila de Rosario lo halla por CÓDIGO DE BARRAS.
 *   - Martillo (sin barras, código de proveedor 100, costo 500): la fila de Rosario lo halla por CÓDIGO DE
 *     PROVEEDOR.
 *
 * IMPORTANTE: PHP 7.4 estricto.
 */
class UpdateOtherProviderByIdentificationTest extends ProviderOfferImportTestCase
{
    /** @var \App\Models\Article Taladro de BA (se halla por código de barras). */
    protected $taladro;

    /** @var \App\Models\Article Martillo de BA (se halla por código de proveedor). */
    protected $martillo;

    /**
     * Siembra los dos artículos de BA.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->taladro = $this->crear_articulo([
            'name'            => 'Taladro por identificacion',
            'bar_code'        => '8800201',
            'provider_code'   => 'BA-1',
            'provider_id'     => $this->ba->id,
            'cost'            => 1000,
            'percentage_gain' => 30,
        ]);
        $this->taladro->providers()->attach($this->ba->id, ['provider_code' => 'BA-1', 'cost' => 1000]);

        $this->martillo = $this->crear_articulo([
            'name'            => 'Martillo por codigo de proveedor',
            'bar_code'        => null,
            'sku'             => null,
            'provider_code'   => '100',
            'provider_id'     => $this->ba->id,
            'cost'            => 500,
            'percentage_gain' => 30,
        ]);
        $this->martillo->providers()->attach($this->ba->id, ['provider_code' => '100', 'cost' => 500]);
    }

    /**
     * (a) Principal = 1, por código de proveedor = 1, por identificación = 0: el Taladro hallado por
     * código de barras NO cambia (ni costo, ni proveedor, ni código) y la oferta de Rosario queda
     * registrada como NO usada; el Martillo, que cruza por código de proveedor, SÍ se actualiza.
     *
     * @return void
     */
    public function test_identification_no_skips_barcode_match_but_provider_code_match_updates()
    {
        $this->importar_filas([
            'actualizar_articulos_de_otro_proveedor' => true,
            'update_other_provider_by_provider_code' => true,
            'update_other_provider_by_identification' => false,
        ]);

        $taladro = $this->taladro->fresh();

        $this->assertDecimal(1000, $taladro->cost, 'El Taladro (hallado por barras) conserva su costo: la pregunta de identificación dijo No.');
        $this->assertSame($this->ba->id, (int) $taladro->provider_id);
        $this->assertSame('BA-1', $taladro->provider_code);

        $ofertas = $this->ofertas($this->taladro->id, $this->rosario->id);

        $this->assertCount(1, $ofertas, 'La fila salteada deja igual la oferta de Rosario.');
        $this->assertDecimal(1200, $ofertas[0]->cost);
        $this->assertSame(0, (int) $ofertas[0]->updated_article_cost, 'Registrada como NO usada.');
        $this->assertNull($ofertas[0]->price_change_id);

        $this->assertDecimal(800, $this->martillo->fresh()->cost, 'El Martillo (hallado por código de proveedor) sí se actualiza.');

        $oferta_martillo = $this->ofertas($this->martillo->id, $this->rosario->id);

        $this->assertCount(1, $oferta_martillo);
        $this->assertSame(1, (int) $oferta_martillo[0]->updated_article_cost);
    }

    /**
     * (b) Principal = 1, por identificación = 1, por código de proveedor = 0: al revés. El Taladro toma
     * el costo de Rosario y el Martillo no se toca (queda su oferta como NO usada).
     *
     * @return void
     */
    public function test_identification_yes_updates_barcode_match_but_provider_code_no_skips()
    {
        $this->importar_filas([
            'actualizar_articulos_de_otro_proveedor' => true,
            'update_other_provider_by_provider_code' => false,
            'update_other_provider_by_identification' => true,
        ]);

        $taladro = $this->taladro->fresh();

        $this->assertDecimal(1200, $taladro->cost, 'El Taladro (hallado por barras) toma el costo de Rosario.');
        $this->assertSame($this->ba->id, (int) $taladro->provider_id, 'Sin "actualizar proveedor" sigue siendo de BA.');

        $ofertas = $this->ofertas($this->taladro->id, $this->rosario->id);

        $this->assertCount(1, $ofertas);
        $this->assertSame(1, (int) $ofertas[0]->updated_article_cost);

        $this->assertDecimal(500, $this->martillo->fresh()->cost, 'El Martillo (código de proveedor) no se toca: esa pregunta dijo No.');
        $this->assertSame($this->ba->id, (int) $this->martillo->fresh()->provider_id);
    }

    /**
     * (c) Sin el parámetro nuevo vale la bandera principal, igual que hoy: con la principal prendida el
     * Taladro se actualiza aunque el cruce por código de proveedor diga No (así se comportaba la SPA
     * vieja).
     *
     * @return void
     */
    public function test_missing_identification_flag_follows_the_main_flag()
    {
        $this->importar_filas([
            'actualizar_articulos_de_otro_proveedor' => true,
            'update_other_provider_by_provider_code' => false,
        ]);

        $this->assertDecimal(1200, $this->taladro->fresh()->cost, 'Sin el parámetro nuevo manda la bandera principal (hoy).');
        $this->assertDecimal(500, $this->martillo->fresh()->cost, 'El código de proveedor sigue con su bandera propia.');
    }

    /**
     * (c, bis) Sin ninguna de las dos banderas nuevas y con la principal apagada nada cambia.
     *
     * @return void
     */
    public function test_missing_both_new_flags_with_main_flag_off_changes_nothing()
    {
        $this->importar_filas(['actualizar_articulos_de_otro_proveedor' => false]);

        $this->assertDecimal(1000, $this->taladro->fresh()->cost);
        $this->assertDecimal(500, $this->martillo->fresh()->cost);
    }

    /**
     * (c, ter) Sin ninguna de las dos banderas nuevas y con la principal prendida cambian los dos: el
     * comportamiento de siempre.
     *
     * @return void
     */
    public function test_missing_both_new_flags_with_main_flag_on_updates_both()
    {
        $this->importar_filas(['actualizar_articulos_de_otro_proveedor' => true]);

        $this->assertDecimal(1200, $this->taladro->fresh()->cost);
        $this->assertDecimal(800, $this->martillo->fresh()->cost);
    }

    /**
     * Un valor null explícito en el request también cuenta como ausente.
     *
     * @return void
     */
    public function test_null_identification_flag_counts_as_missing()
    {
        $this->importar_filas([
            'actualizar_articulos_de_otro_proveedor'  => false,
            'update_other_provider_by_identification' => null,
        ]);

        $this->assertDecimal(1000, $this->taladro->fresh()->cost);
    }

    /**
     * Compatibilidad de jobs ya encolados: la bandera nueva va al final de los constructores, con
     * default null y propiedad con default null, para que un job serializado con la firma vieja siga
     * corriendo como siempre.
     *
     * @return void
     */
    public function test_new_flag_is_last_optional_argument_defaulting_to_null()
    {
        foreach ([ProcessArticleChunk::class, ArticleImport::class] as $clase) {
            $parametros = (new \ReflectionClass($clase))->getConstructor()->getParameters();
            $ultimo     = end($parametros);

            $this->assertSame('update_other_provider_by_identification', $ultimo->getName(), $clase);
            $this->assertTrue($ultimo->isDefaultValueAvailable(), $clase);
            $this->assertNull($ultimo->getDefaultValue(), $clase);
        }

        $propiedad = new \ReflectionProperty(ProcessArticleChunk::class, 'update_other_provider_by_identification');
        $propiedad->setAccessible(true);

        $job_viejo = (new \ReflectionClass(ProcessArticleChunk::class))->newInstanceWithoutConstructor();

        $this->assertNull($propiedad->getValue($job_viejo), 'Un job sin la propiedad serializada la hereda en null.');
    }

    /* ==================================================================
     * Helpers
     * ================================================================== */

    /**
     * Rosario manda dos filas: el Taladro por código de barras (1200) y el Martillo por código de
     * proveedor (800).
     *
     * @param  array $banderas overrides del request (las banderas de "otro proveedor")
     * @return void
     */
    protected function importar_filas(array $banderas)
    {
        $this->importar_por_ia([
            ['8800201', 'R-77', 'Taladro por identificacion', 1200],
            ['', '100', 'Pinza de Rosario', 800],
        ], $this->rosario->id, false, $banderas);
    }
}
