<?php

namespace Tests\Feature\ListasSiempre;

use App\Models\PriceType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * D9 y D10 de la misión prueba-listas-siempre: la lista «Precio final» no se borra, el flag no se apaga
 * desde la configuración del usuario, y la cotización del dólar ya no pide la extensión
 * `costo_en_dolares`.
 */
class Reglas_de_la_lista_y_del_flag_Test extends ListasSiempreTestCase
{
    /** @test */
    public function la_lista_precio_final_no_se_puede_eliminar_pero_una_comun_si()
    {
        $dueno = $this->dueno_nuevo('destroy');
        list($dueno, $precio_final) = $this->con_precio_final($dueno);
        $mayorista = $this->otra_lista($dueno);

        $this->actingAs($dueno, 'web');

        $respuesta = $this->deleteJson('api/price-type/'.$precio_final->id);

        $respuesta->assertStatus(422);
        $this->assertTrue((bool) $respuesta->json('es_precio_final'));
        $this->assertNotNull(PriceType::find($precio_final->id));

        $this->deleteJson('api/price-type/'.$mayorista->id)->assertStatus(200);
        $this->assertNull(PriceType::find($mayorista->id));
    }

    /** @test */
    public function el_flag_no_baja_a_cero_desde_la_configuracion_del_usuario()
    {
        $dueno = $this->dueno_nuevo('flag');
        list($dueno, $lista) = $this->con_precio_final($dueno);

        $this->actingAs($dueno, 'web');

        $this->putJson('api/user/'.$dueno->id, array_merge(User::find($dueno->id)->toArray(), array(
            'listas_de_precio' => 0,
        )))->assertStatus(200);

        $this->assertSame(1, (int) DB::table('users')->where('id', $dueno->id)->value('listas_de_precio'), 'Una SPA vieja que manda 0 no apaga el flag.');
        $this->assertSame(1, DB::table('price_types')->where('user_id', $dueno->id)->count());
    }

    /** @test */
    public function el_flag_si_se_puede_prender_desde_la_configuracion()
    {
        $dueno = $this->dueno_nuevo('flag-prender');

        $this->actingAs($dueno, 'web');

        $this->putJson('api/user/'.$dueno->id, array_merge(User::find($dueno->id)->toArray(), array(
            'listas_de_precio' => 1,
        )))->assertStatus(200);

        $this->assertSame(1, (int) DB::table('users')->where('id', $dueno->id)->value('listas_de_precio'));
    }

    /** @test */
    public function la_cotizacion_del_dolar_no_pide_la_extension_costo_en_dolares()
    {
        $dueno = $this->dueno_nuevo('dolar');

        $this->assertFalse($dueno->extencions->contains('slug', 'costo_en_dolares'));

        $this->actingAs($dueno, 'web');

        $respuesta = $this->putJson('api/dolar-cotizacion/preferencias', array(
            'avisar_cambios'   => 1,
            'variacion_minima' => 2.5,
        ));

        $this->assertNotEquals(403, $respuesta->status(), 'Sin la extensión, el endpoint ya no devuelve 403.');
        $respuesta->assertStatus(200);
        $this->assertEqualsWithDelta(2.5, (float) DB::table('users')->where('id', $dueno->id)->value('dolar_variacion_minima'), 0.001);
    }
}
