<?php

namespace Tests\Feature\HistorialPreciosProveedor;

use App\Models\Article;
use App\Services\Compras\OfertasDeProveedorService;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión historial-precios-proveedor, ronda 3 (A2-2 y A2-3).
 *
 * A2-2: dos importaciones del mismo proveedor el mismo día caen en la misma fila (unique sin cambios).
 *   - Si la segunda trae un costo DISTINTO, la fila refleja ENTERA la última importación (costo, marca de
 *     uso, costo previo, vínculo con price_changes, cambio de titular, titular previo y referencia).
 *   - Si trae el MISMO costo, se conservan las marcas de uso ya guardadas y sólo se anota "visto por
 *     última vez".
 *   - Nunca queda una fila "usada" con un costo o un cambio de precio que no le corresponden.
 *
 * A2-3: si la fila cambió el titular con el mismo costo y eso generó un evento en price_changes, la
 * oferta queda vinculada a ese evento.
 *
 * IMPORTANTE: PHP 7.4 estricto.
 */
class SameDayOfferRulesTest extends EmpresaTestCase
{
    /** @var \App\Models\User */
    protected $owner;

    /** @var \App\Models\Provider Titular del Taladro. */
    protected $ba;

    /** @var \App\Models\Provider Proveedor que manda la lista. */
    protected $rosario;

    /** @var \App\Models\Article */
    protected $taladro;

    /**
     * Arma el Taladro (titular BA) sobre el fixture de testing.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->owner   = auth()->user();
        $this->ba      = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS);
        $this->rosario = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $taladro = new Article();

        $taladro->user_id       = $this->owner->id;
        $taladro->name          = 'Taladro mismo dia';
        $taladro->bar_code      = '8802001';
        $taladro->provider_id   = $this->ba->id;
        $taladro->provider_code = 'BA-1';
        $taladro->cost          = 1000;
        $taladro->iva_id        = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA)->iva_id;
        $taladro->status        = 'active';
        $taladro->save();

        $this->taladro = $taladro->fresh();
    }

    /**
     * Vuelve el reloj a la normalidad.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Costo distinto, usada -> no usada: la fila queda con TODO lo de la segunda (sin marcas de uso,
     * sin costo previo, sin vínculo, sin cambio de titular) y su referencia.
     *
     * @return void
     */
    public function test_different_cost_used_then_unused_row_reflects_last_import()
    {
        $this->registrar($this->oferta(1200, [
            'updated_article_cost'         => 1,
            'previous_article_cost'        => 1000,
            'changed_article_provider'     => 1,
            'previous_article_provider_id' => $this->ba->id,
        ]), 60, [$this->taladro->id => 7]);

        $this->registrar($this->oferta(1250, [
            'updated_article_cost'         => 0,
            'changed_article_provider'     => 0,
            'previous_article_provider_id' => $this->rosario->id,
        ]), 61);

        $filas = $this->filas();

        $this->assertCount(1, $filas, 'Mismo día y origen: una sola fila.');
        $this->assertEquals(1250.0, (float) $filas[0]->cost);
        $this->assertSame(0, (int) $filas[0]->updated_article_cost, 'La última no usó el costo: la fila no puede decir que sí.');
        $this->assertNull($filas[0]->previous_article_cost);
        $this->assertSame(0, (int) $filas[0]->changed_article_provider);
        $this->assertSame($this->rosario->id, (int) $filas[0]->previous_article_provider_id);
        $this->assertNull($filas[0]->price_change_id, 'El vínculo de la primera no corresponde al costo de la segunda.');
        $this->assertSame(61, (int) $filas[0]->referencia_id);
    }

    /**
     * Costo distinto, no usada -> usada: la fila queda con TODO lo de la segunda.
     *
     * @return void
     */
    public function test_different_cost_unused_then_used_row_reflects_last_import()
    {
        $this->registrar($this->oferta(1200, [
            'updated_article_cost'         => 0,
            'previous_article_provider_id' => $this->ba->id,
        ]), 60);

        $this->registrar($this->oferta(1250, [
            'updated_article_cost'         => 1,
            'previous_article_cost'        => 1000,
            'changed_article_provider'     => 1,
            'previous_article_provider_id' => $this->ba->id,
        ]), 61, [$this->taladro->id => 8]);

        $filas = $this->filas();

        $this->assertCount(1, $filas);
        $this->assertEquals(1250.0, (float) $filas[0]->cost);
        $this->assertSame(1, (int) $filas[0]->updated_article_cost);
        $this->assertEquals(1000.0, (float) $filas[0]->previous_article_cost);
        $this->assertSame(1, (int) $filas[0]->changed_article_provider);
        $this->assertSame($this->ba->id, (int) $filas[0]->previous_article_provider_id);
        $this->assertSame(8, (int) $filas[0]->price_change_id);
        $this->assertSame(61, (int) $filas[0]->referencia_id);
    }

    /**
     * Costo distinto, usada -> usada: manda la última (su costo previo, su vínculo, su referencia),
     * no una mezcla con los de la primera.
     *
     * @return void
     */
    public function test_different_cost_used_then_used_row_reflects_last_import()
    {
        $this->registrar($this->oferta(1200, [
            'updated_article_cost'         => 1,
            'previous_article_cost'        => 1000,
            'changed_article_provider'     => 1,
            'previous_article_provider_id' => $this->ba->id,
        ]), 60, [$this->taladro->id => 7]);

        $this->registrar($this->oferta(1300, [
            'updated_article_cost'         => 1,
            'previous_article_cost'        => 1200,
            'changed_article_provider'     => 0,
            'previous_article_provider_id' => $this->rosario->id,
        ]), 61, [$this->taladro->id => 9]);

        $filas = $this->filas();

        $this->assertCount(1, $filas);
        $this->assertEquals(1300.0, (float) $filas[0]->cost);
        $this->assertEquals(1200.0, (float) $filas[0]->previous_article_cost);
        $this->assertSame(0, (int) $filas[0]->changed_article_provider);
        $this->assertSame($this->rosario->id, (int) $filas[0]->previous_article_provider_id);
        $this->assertSame(9, (int) $filas[0]->price_change_id);
        $this->assertSame(61, (int) $filas[0]->referencia_id);
    }

    /**
     * Mismo costo, usada -> no usada: la marca de uso (con su costo previo, titular previo y vínculo)
     * se conserva y sólo se anota "visto por última vez".
     *
     * @return void
     */
    public function test_same_cost_used_then_unused_keeps_marks_and_touches_last_seen()
    {
        $this->registrar($this->oferta(1200, [
            'updated_article_cost'         => 1,
            'previous_article_cost'        => 1000,
            'changed_article_provider'     => 1,
            'previous_article_provider_id' => $this->ba->id,
        ]), 60, [$this->taladro->id => 7]);

        $this->registrar($this->oferta(1200, [
            'updated_article_cost'         => 0,
            'previous_article_provider_id' => $this->rosario->id,
        ]), 61);

        $filas = $this->filas();

        $this->assertCount(1, $filas);
        $this->assertSame(1, (int) $filas[0]->updated_article_cost);
        $this->assertEquals(1000.0, (float) $filas[0]->previous_article_cost);
        $this->assertSame(1, (int) $filas[0]->changed_article_provider);
        $this->assertSame($this->ba->id, (int) $filas[0]->previous_article_provider_id);
        $this->assertSame(7, (int) $filas[0]->price_change_id);
        $this->assertSame(60, (int) $filas[0]->referencia_id);
        $this->assertNotNull($filas[0]->last_seen_at);
        $this->assertSame(61, (int) $filas[0]->last_seen_reference_id);
    }

    /**
     * Mismo costo, no usada -> usada: la fila pasa a registrar el uso (marca, costo previo y vínculo de
     * la segunda), porque un uso es un hecho que no se pierde.
     *
     * @return void
     */
    public function test_same_cost_unused_then_used_records_the_usage()
    {
        $this->registrar($this->oferta(1200, [
            'updated_article_cost'         => 0,
            'previous_article_provider_id' => $this->ba->id,
        ]), 60);

        $this->registrar($this->oferta(1200, [
            'updated_article_cost'         => 1,
            'previous_article_cost'        => 1000,
            'previous_article_provider_id' => $this->ba->id,
        ]), 61, [$this->taladro->id => 8]);

        $filas = $this->filas();

        $this->assertCount(1, $filas);
        $this->assertSame(1, (int) $filas[0]->updated_article_cost);
        $this->assertEquals(1000.0, (float) $filas[0]->previous_article_cost);
        $this->assertSame(8, (int) $filas[0]->price_change_id);
        $this->assertEquals(1200.0, (float) $filas[0]->cost);
    }

    /**
     * Invariante general: tras cualquier secuencia de dos importaciones del día, una fila "usada"
     * para el costo siempre tiene el costo previo de ESA importación (no el de otra con otro costo).
     *
     * @return void
     */
    public function test_used_row_never_mixes_cost_of_one_import_with_link_of_another()
    {
        $this->registrar($this->oferta(1500, [
            'updated_article_cost'  => 1,
            'previous_article_cost' => 1000,
        ]), 60, [$this->taladro->id => 7]);

        $this->registrar($this->oferta(1100, [
            'updated_article_cost'  => 0,
        ]), 61);

        $fila = $this->filas()[0];

        $this->assertEquals(1100.0, (float) $fila->cost);

        if ((int) $fila->updated_article_cost === 1) {
            $this->fail('La fila dice que el costo se usó, pero el costo $1.100 no es el que se usó.');
        }

        $this->assertNull($fila->price_change_id);
        $this->assertNull($fila->previous_article_cost);
    }

    /**
     * A2-3: la fila cambia el titular con el MISMO costo del artículo (no se actualiza el costo) y la
     * operación generó un evento en price_changes: la oferta queda vinculada a ese evento.
     *
     * @return void
     */
    public function test_provider_change_with_same_cost_links_the_price_change_event()
    {
        $this->registrar($this->oferta(1000, [
            'updated_article_cost'         => 0,
            'changed_article_provider'     => 1,
            'previous_article_provider_id' => $this->ba->id,
        ]), 70, [$this->taladro->id => 19]);

        $filas = $this->filas();

        $this->assertCount(1, $filas);
        $this->assertSame(0, (int) $filas[0]->updated_article_cost);
        $this->assertSame(1, (int) $filas[0]->changed_article_provider);
        $this->assertSame(19, (int) $filas[0]->price_change_id, 'El cambio de titular que movió el precio de venta queda enlazado.');
    }

    /* ==================================================================
     * Helpers
     * ================================================================== */

    /**
     * Llama a registrar_lote() para la oferta de Rosario sobre el Taladro.
     *
     * @param  array    $oferta
     * @param  int      $referencia
     * @param  array    $price_change_ids [article_id => price_change_id]
     * @return void
     */
    protected function registrar(array $oferta, $referencia, array $price_change_ids = [])
    {
        OfertasDeProveedorService::registrar_lote(
            [$this->taladro->id => [$this->rosario->id => $oferta]],
            $this->owner->id,
            'importacion',
            $referencia,
            $price_change_ids
        );
    }

    /**
     * Oferta de Rosario con el costo y las claves de uso dadas.
     *
     * @param  float $cost
     * @param  array $extra
     * @return array
     */
    protected function oferta($cost, array $extra = [])
    {
        return array_merge([
            'cost'          => $cost,
            'provider_code' => 'R-77',
            'moneda_id'     => 1,
        ], $extra);
    }

    /**
     * Filas de Rosario del Taladro.
     *
     * @return array de stdClass
     */
    protected function filas()
    {
        return DB::table('provider_price_offers')
            ->where('article_id', $this->taladro->id)
            ->where('provider_id', $this->rosario->id)
            ->orderBy('fecha')
            ->orderBy('id')
            ->get()
            ->all();
    }
}
