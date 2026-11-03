<?php

namespace Tests\Feature\VentasEnDolares;

use App\Http\Controllers\Helpers\sale\CotizacionPorArticuloHelper;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Sin transacciones: el DDL de MySQL confirma transacciones implícitamente. */
class Cotizacion_por_articulo_esquema_Test extends TestCase
{
    public function test_api_sin_columna_opcional_sigue_leyendo_ventas_y_no_habilita_contrato()
    {
        $this->assertMatchesRegularExpression('/_testing_s[0-9]+$/', DB::connection()->getDatabaseName());
        $this->actingAs(User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first(), 'web');
        DB::statement('ALTER TABLE article_sale RENAME COLUMN cotizacion_aplicada TO cotizacion_aplicada_ticket_40_temporal');
        try {
            CotizacionPorArticuloHelper::limpiar_cache();
            $this->getJson('api/vender/cotizaciones-articulos')->assertOk()->assertJsonPath('supported', false);
            $this->assertSame(['price', 'cost'], CotizacionPorArticuloHelper::columnas(['price', 'cost'], 'article_sale'));
            $venta = Sale::where('user_id', auth()->id())->first() ?: new Sale();
            $venta->load('articles');
            $this->assertNotNull($venta->articles);
        } finally {
            DB::statement('ALTER TABLE article_sale RENAME COLUMN cotizacion_aplicada_ticket_40_temporal TO cotizacion_aplicada');
            CotizacionPorArticuloHelper::limpiar_cache();
        }
    }
}
