<?php

namespace Tests\Feature\Devoluciones;

use App\Http\Controllers\Helpers\CajaReportsHelper;
use App\Http\Controllers\Helpers\contabilidad\ContabilidadRepository;
use App\Models\Client;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Una nota de crédito a PROVEEDOR no es una devolución de venta (misión
 * devoluciones-compras-y-rediseno, 1/10/2026, §3 del plan).
 *
 * 🔴 Los reportes de "devoluciones" contaban cualquier `status = 'nota_credito'` como devolución
 * de un cliente. Con esta misión la NC a proveedor además adjunta artículos CON COSTO a
 * `article_current_acount`, y eso restaba del costo de mercadería vendida del estado de resultados
 * como si un cliente hubiera devuelto la mercadería. Se excluyen por `provider_id`.
 *
 * Todo por DIFERENCIA sobre el día de hoy (la base de testing puede tener otras NC de hoy), y con un
 * CONTROL: la NC de cliente hecha en el mismo test sí tiene que sumar. Sin el control, un reporte
 * que no midiera nada pasaría en verde.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group devoluciones
 */
class Nota_de_credito_a_proveedor_fuera_de_reportes_Test extends NotaCreditoProveedorTestCase
{
    /**
     * Las tres mediciones que se comparan: devoluciones y costo devuelto del estado de resultados,
     * y devoluciones del reporte de caja.
     *
     * @return array{devoluciones: float, costo_devuelto: float, caja: float}
     */
    protected function medir()
    {
        $user_id = $this->usuario()->id;
        $hoy = Carbon::now();

        // CajaReportsHelper recibe el controlador como "instancia" solo para leer el usuario.
        $instancia = new class($user_id) {
            private $user_id;

            public function __construct($user_id)
            {
                $this->user_id = $user_id;
            }

            public function userId()
            {
                return $this->user_id;
            }
        };

        return [
            'devoluciones'   => (float) ContabilidadRepository::devoluciones($user_id, $hoy, $hoy),
            'costo_devuelto' => (float) ContabilidadRepository::costo_mercaderia_devuelta($user_id, $hoy, $hoy),
            'caja'           => (float) CajaReportsHelper::devoluciones($instancia, $hoy->toDateString(), null),
        ];
    }

    /**
     * @test
     */
    public function una_nc_a_proveedor_no_suma_en_devoluciones_ni_en_costo_devuelto()
    {
        $articulo = $this->crear_articulo('zz NC proveedor reportes');
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 100, 4)]);

        $antes = $this->medir();

        // Con C/C y sin C/C: las dos formas de NC a proveedor.
        $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 121, 2)],
            242,
            ['generar_current_acount' => 1]
        ))->assertStatus(201);

        $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 121, 1)],
            121,
            ['generar_current_acount' => 0]
        ))->assertStatus(201);

        $despues_proveedor = $this->medir();

        $this->assertEqualsWithDelta($antes['devoluciones'], $despues_proveedor['devoluciones'], 0.01, 'La NC a proveedor no puede sumar como devolución de venta.');
        $this->assertEqualsWithDelta($antes['costo_devuelto'], $despues_proveedor['costo_devuelto'], 0.01, 'El costo de lo devuelto al proveedor no puede restar del costo de lo vendido.');
        $this->assertEqualsWithDelta($antes['caja'], $despues_proveedor['caja'], 0.01, 'La NC a proveedor no puede sumar en las devoluciones de caja.');

        // CONTROL: una devolución de cliente (sin venta, con un artículo con costo) SÍ suma.
        $cliente = Client::create([
            'name'    => 'zz Cliente control reportes',
            'user_id' => $this->usuario()->id,
        ]);

        $this->postJson('api/devoluciones/', [
            'sale_id'                   => null,
            'client_id'                 => $cliente->id,
            'generar_current_acount'    => false,
            'total_devolucion'          => 500,
            'observaciones'             => 'zz control',
            'items'                     => [[
                'id'                 => $articulo->id,
                'is_article'         => true,
                'name'               => $articulo->name,
                'price_vender'       => 500,
                'costo_real'         => 100,
                'discount'           => 0,
                'unidades_devueltas' => 1,
            ]],
            'descriptions'              => [],
            'discounts'                 => [],
            'surchages'                 => [],
            'regresar_stock'            => false,
            'update_unidades_devueltas' => false,
            'facturar_nota_credito'     => null,
        ])->assertStatus(201);

        $despues_cliente = $this->medir();

        $this->assertGreaterThan($despues_proveedor['devoluciones'], $despues_cliente['devoluciones'], 'El control no movió las devoluciones: el reporte no está midiendo nada.');
        $this->assertGreaterThan($despues_proveedor['costo_devuelto'], $despues_cliente['costo_devuelto'], 'El control no movió el costo devuelto: el reporte no está midiendo nada.');
        $this->assertEqualsWithDelta($despues_proveedor['caja'] + 500, $despues_cliente['caja'], 0.01);
    }
}
