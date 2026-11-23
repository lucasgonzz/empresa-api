<?php

namespace Tests\Feature\HistorialPreciosProveedor;

use App\Models\Article;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión historial-precios-proveedor, ronda 2 (alineación con el plan §2.9): el origen de una oferta de
 * importación se rotula "Importación del dd/mm/aaaa" con la fecha de la importación (no "Importación #id"),
 * sin una consulta por fila.
 *
 * IMPORTANTE: PHP 7.4 estricto.
 */
class ProviderOfferReferenceLabelTest extends EmpresaTestCase
{
    /** @var \App\Models\User Dueño del fixture de testing. */
    protected $owner;

    /** @var \App\Models\Provider Proveedor que ofrece el artículo. */
    protected $rosario;

    /** @var \App\Models\Article */
    protected $articulo;

    /**
     * Arma un artículo del dueño.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->owner   = auth()->user();
        $this->rosario = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $articulo = new Article();

        $articulo->user_id       = $this->owner->id;
        $articulo->name          = 'Articulo rotulo de importacion';
        $articulo->bar_code      = '8803901';
        $articulo->cost          = 1000;
        $articulo->iva_id        = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA)->iva_id;
        $articulo->status        = 'active';
        $articulo->save();

        $this->articulo = $articulo->fresh();
    }

    /**
     * La oferta de una importación se rotula con la fecha de esa importación (import_histories.created_at).
     *
     * @return void
     */
    public function test_import_offer_label_uses_the_import_date()
    {
        $import_id = $this->crear_importacion('2026-03-07 15:30:00');

        $this->insertar_oferta(['referencia_id' => $import_id, 'fecha' => '2026-03-08']);

        $filas = $this->getJson('api/provider-price-offer/article/' . $this->articulo->id)
            ->assertStatus(200)->json('models');

        $this->assertCount(1, $filas);
        $this->assertSame('Importación del 07/03/2026', $filas[0]['reference_label']);
    }

    /**
     * Si la importación ya no existe, el rótulo usa la fecha de la oferta y no queda vacío.
     *
     * @return void
     */
    public function test_import_offer_label_falls_back_to_the_offer_date()
    {
        $this->insertar_oferta(['referencia_id' => 987654, 'fecha' => '2026-03-08']);

        $filas = $this->getJson('api/provider-price-offer/article/' . $this->articulo->id)
            ->assertStatus(200)->json('models');

        $this->assertSame('Importación del 08/03/2026', $filas[0]['reference_label']);
    }

    /**
     * Sin referencia no hay rótulo; la consulta de las fechas de importación es UNA, haya 2 o 12 filas.
     *
     * @return void
     */
    public function test_import_dates_are_one_query_regardless_of_row_count()
    {
        $this->insertar_oferta(['referencia_id' => null, 'fecha' => '2026-03-01']);

        $con_pocas = $this->consultas_a_import_histories();

        for ($i = 0; $i < 12; $i++) {
            $import_id = $this->crear_importacion('2026-04-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . ' 10:00:00');
            $this->insertar_oferta(['referencia_id' => $import_id, 'fecha' => '2026-04-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)]);
        }

        $con_muchas = $this->consultas_a_import_histories();

        $this->assertSame(0, $con_pocas, 'Sin ofertas de importación no se consulta import_histories.');
        $this->assertSame(1, $con_muchas, 'Con doce importaciones distintas, una sola consulta a import_histories.');
    }

    /* ==================================================================
     * Helpers
     * ================================================================== */

    /**
     * Cuenta las consultas a import_histories que dispara el GET del historial.
     *
     * @return int
     */
    protected function consultas_a_import_histories()
    {
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        $this->getJson('api/provider-price-offer/article/' . $this->articulo->id)->assertStatus(200);

        $log = DB::connection()->getQueryLog();

        DB::connection()->disableQueryLog();

        $cuenta = 0;

        foreach ($log as $consulta) {
            if (strpos($consulta['query'], 'import_histories') !== false) {
                $cuenta++;
            }
        }

        return $cuenta;
    }

    /**
     * Crea una importación del dueño con la fecha indicada y devuelve su id.
     *
     * @param  string $created_at
     * @return int
     */
    protected function crear_importacion($created_at)
    {
        return (int) DB::table('import_histories')->insertGetId([
            'user_id'    => $this->owner->id,
            'model_name' => 'article',
            'created_at' => $created_at,
            'updated_at' => $created_at,
        ]);
    }

    /**
     * Inserta una oferta de importación cruda.
     *
     * @param  array $datos overrides de las columnas
     * @return int
     */
    protected function insertar_oferta(array $datos)
    {
        return (int) DB::table('provider_price_offers')->insertGetId(array_merge([
            'user_id'       => $this->owner->id,
            'article_id'    => $this->articulo->id,
            'provider_id'   => $this->rosario->id,
            'provider_code' => 'R-77',
            'cost'          => 1200,
            'moneda_id'     => 1,
            'origen'        => 'importacion',
            'fecha'         => Carbon::today()->toDateString(),
            'referencia_id' => null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], $datos));
    }
}
