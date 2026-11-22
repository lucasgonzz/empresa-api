<?php

namespace Tests\Feature\HistorialPreciosProveedor;

use App\Models\Article;
use App\Services\Compras\OfertasDeProveedorService;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\EmpresaTestCase;

/**
 * Misión historial-precios-proveedor: reglas de escritura de
 * `OfertasDeProveedorService::registrar_lote()` con las marcas de uso (CA6, parte de servicio).
 *
 * Qué se mide, contra la base real (sin mocks):
 *   - Una oferta que SE USÓ para actualizar el costo se escribe aunque el costo del proveedor no haya
 *     cambiado y la fila previa sea reciente (hoy se descartaba: no quedaba rastro).
 *   - Si no se usó y no cambió nada, no se inserta fila, pero la fila vigente queda con
 *     `last_seen_at` / `last_seen_reference_id` ("visto por última vez").
 *   - Dos llamadas el mismo día/origen caen en la misma fila (unique sin cambios) y la segunda NUNCA
 *     borra la marca de uso ni el vínculo con el cambio de precio de la primera.
 *   - Una llamada vieja (sin las claves nuevas ni el quinto parámetro) sigue andando: la fila queda
 *     con "no se usó" (0, nunca null) y el titular anterior resuelto desde `articles`.
 *
 * 🔴 Rojo esperado ANTES de construir la misión: las filas "usadas" no se escriben, `last_seen_*` y las
 * columnas de uso no existen, y `registrar_lote()` no acepta el quinto parámetro (PHP lo ignora: no es
 * un error de sintaxis, es que el vínculo nunca se guarda).
 *
 * IMPORTANTE: PHP 7.4 estricto (ver las restricciones de la misión: nada de sintaxis de PHP 8).
 */
class RecordBatchUsageTest extends EmpresaTestCase
{
    /** Columnas que agrega la migración de la misión a provider_price_offers. */
    const COLUMNAS_DE_USO = [
        'updated_article_cost',
        'previous_article_cost',
        'price_change_id',
        'changed_article_provider',
        'previous_article_provider_id',
        'last_seen_at',
        'last_seen_reference_id',
    ];

    /** @var \App\Models\User Dueño del fixture de testing. */
    protected $owner;

    /** @var \App\Models\Provider Titular del Taladro. */
    protected $ba;

    /** @var \App\Models\Provider Proveedor que ofrece el Taladro. */
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
        $taladro->name          = 'Taladro historial servicio';
        $taladro->bar_code      = '8801001';
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
     * CA6 (servicio): con una fila previa del mismo costo hecha hace 3 días (dentro de la ventana de
     * reconfirmación), una oferta marcada como USADA se escribe igual: quedan 2 filas, la nueva con la
     * marca de uso. Sin la marca (control), no se inserta nada.
     *
     * @return void
     */
    public function test_used_offer_is_written_even_with_same_cost()
    {
        $this->insertar_previa(1200, 3);

        // Control: mismo costo, sin uso, fila reciente: no se inserta (comportamiento de hoy).
        OfertasDeProveedorService::registrar_lote(
            $this->buffer(['cost' => 1200]),
            $this->owner->id,
            'importacion',
            50
        );

        $this->assertCount(1, $this->filas(), 'Sin uso y sin cambio de costo no se inserta una fila nueva.');

        // La oferta se usó para actualizar el costo: se escribe aunque el costo del proveedor sea el mismo.
        OfertasDeProveedorService::registrar_lote(
            $this->buffer([
                'cost'                         => 1200,
                'updated_article_cost'         => 1,
                'previous_article_cost'        => 1000,
                'changed_article_provider'     => 0,
                'previous_article_provider_id' => $this->ba->id,
            ]),
            $this->owner->id,
            'importacion',
            51
        );

        $this->assertCount(2, $this->filas(), 'Una oferta usada se escribe aunque el costo no haya cambiado.');

        $this->assert_columnas_de_uso();

        $nueva = $this->filas()[1];

        $this->assertSame(Carbon::today()->toDateString(), $nueva->fecha);
        $this->assertSame(1, (int) $nueva->updated_article_cost);
        $this->assertEquals(1000.0, (float) $nueva->previous_article_cost);
        $this->assertSame(0, (int) $nueva->changed_article_provider);
        $this->assertSame($this->ba->id, (int) $nueva->previous_article_provider_id);
        $this->assertSame(51, (int) $nueva->referencia_id);
    }

    /**
     * CA6 (servicio): si no se inserta fila, la vigente queda con "visto por última vez" y la
     * referencia de la importación que la volvió a ver.
     *
     * @return void
     */
    public function test_unchanged_cost_touches_last_seen_without_new_row()
    {
        $this->insertar_previa(1200, 3);

        OfertasDeProveedorService::registrar_lote(
            $this->buffer(['cost' => 1200]),
            $this->owner->id,
            'importacion',
            77
        );

        $this->assertCount(1, $this->filas(), 'Mismo costo, sin uso y dentro de la ventana: no se inserta.');

        $this->assert_columnas_de_uso();

        $fila = $this->filas()[0];

        $this->assertNotNull($fila->last_seen_at, 'La fila vigente anota cuándo se volvió a ver el precio.');
        $this->assertSame(77, (int) $fila->last_seen_reference_id);
    }

    /**
     * CA6 (servicio): dos llamadas el mismo día y origen dejan UNA fila con el último costo, pero la
     * segunda (sin uso, otro costo) no borra la marca de uso, el costo anterior, el cambio de titular
     * ni el vínculo con el cambio de precio que dejó la primera.
     *
     * @return void
     */
    public function test_same_day_upsert_never_erases_usage_or_link()
    {
        OfertasDeProveedorService::registrar_lote(
            $this->buffer([
                'cost'                         => 1200,
                'updated_article_cost'         => 1,
                'previous_article_cost'        => 1000,
                'changed_article_provider'     => 1,
                'previous_article_provider_id' => $this->ba->id,
            ]),
            $this->owner->id,
            'importacion',
            60,
            [$this->taladro->id => 7]
        );

        OfertasDeProveedorService::registrar_lote(
            $this->buffer([
                'cost'                         => 1250,
                'updated_article_cost'         => 0,
                'previous_article_cost'        => null,
                'changed_article_provider'     => 0,
                'previous_article_provider_id' => $this->rosario->id,
            ]),
            $this->owner->id,
            'importacion',
            61
        );

        $filas = $this->filas();

        $this->assertCount(1, $filas, 'Mismo día y mismo origen: una sola fila (unique sin cambios).');
        $this->assertEquals(1250.0, (float) $filas[0]->cost, 'Queda el último costo.');
        $this->assertSame(61, (int) $filas[0]->referencia_id);

        $this->assert_columnas_de_uso();

        $this->assertSame(1, (int) $filas[0]->updated_article_cost, 'La segunda llamada no borra la marca de uso.');
        $this->assertSame(1, (int) $filas[0]->changed_article_provider, 'Ni el cambio de proveedor.');
        $this->assertEquals(1000.0, (float) $filas[0]->previous_article_cost, 'Queda el costo anterior del primer uso del día.');
        $this->assertSame($this->ba->id, (int) $filas[0]->previous_article_provider_id, 'Queda el titular anterior del primer uso del día.');
        $this->assertSame(7, (int) $filas[0]->price_change_id, 'Ni el vínculo con el cambio de precio.');
    }

    /**
     * CA6 (servicio): una llamada vieja (cuatro parámetros, entrada sin las claves nuevas) sobre la
     * tabla vacía escribe la fila con "no se usó" (0, nunca null) y resuelve el titular anterior
     * leyéndolo de `articles` (el Taladro es de BA aunque la oferta sea de Rosario).
     *
     * @return void
     */
    public function test_legacy_call_resolves_previous_provider_and_marks_unused()
    {
        $this->assertCount(0, $this->filas(), 'Premisa: la tabla arranca vacía para este artículo.');

        OfertasDeProveedorService::registrar_lote(
            $this->buffer(['cost' => 1200]),
            $this->owner->id,
            'importacion',
            70
        );

        $filas = $this->filas();

        $this->assertCount(1, $filas, 'La llamada vieja sigue escribiendo la fila.');

        $this->assert_columnas_de_uso();

        $this->assertSame(0, (int) $filas[0]->updated_article_cost);
        $this->assertNotNull($filas[0]->updated_article_cost, 'Fila nueva: 0, nunca null.');
        $this->assertSame(0, (int) $filas[0]->changed_article_provider);
        $this->assertNotNull($filas[0]->changed_article_provider);
        $this->assertSame($this->ba->id, (int) $filas[0]->previous_article_provider_id, 'El titular se lee de articles.provider_id.');
        $this->assertNull($filas[0]->price_change_id);
    }

    /* ==================================================================
     * Helpers
     * ================================================================== */

    /**
     * Falla con un mensaje claro si la migración de la misión todavía no corrió.
     *
     * @return void
     */
    protected function assert_columnas_de_uso()
    {
        foreach (self::COLUMNAS_DE_USO as $columna) {
            $this->assertTrue(
                Schema::hasColumn('provider_price_offers', $columna),
                'Falta la columna provider_price_offers.' . $columna . ' (la migración de la misión no existe todavía).'
            );
        }
    }

    /**
     * Buffer de una oferta de Rosario sobre el Taladro, con los datos que se le pasen.
     *
     * @param  array $datos  cost y, opcionalmente, las claves de uso
     * @return array         [article_id => [provider_id => datos]]
     */
    protected function buffer(array $datos)
    {
        return [
            $this->taladro->id => [
                $this->rosario->id => array_merge([
                    'provider_code' => 'R-77',
                    'moneda_id'     => 1,
                ], $datos),
            ],
        ];
    }

    /**
     * Inserta una fila previa de Rosario con la fecha de hace $dias días (sin las columnas nuevas,
     * para que valga también antes de la migración).
     *
     * @param  float $cost
     * @param  int   $dias
     * @return void
     */
    protected function insertar_previa($cost, $dias)
    {
        $fecha = Carbon::today()->subDays($dias)->toDateString();

        DB::table('provider_price_offers')->insert([
            'user_id'       => $this->owner->id,
            'article_id'    => $this->taladro->id,
            'provider_id'   => $this->rosario->id,
            'provider_code' => 'R-77',
            'cost'          => $cost,
            'moneda_id'     => 1,
            'origen'        => 'importacion',
            'fecha'         => $fecha,
            'referencia_id' => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    /**
     * Filas de Rosario del Taladro, de la más vieja a la más nueva.
     *
     * @return array de stdClass (fila cruda)
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
