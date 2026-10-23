<?php

namespace Tests\Feature\SucursalSiempre;

use App\Http\Controllers\Helpers\address\EliminarSucursalHelper;
use App\Models\Address;
use Illuminate\Support\Facades\DB;

/**
 * D7 de la misión sucursal-siempre: la ÚLTIMA sucursal del comercio no se puede borrar, por ningún
 * camino (el ABM, el borrado masivo, la baja por el asistente: los tres terminan en
 * `AddressController@destroy`).
 */
class Ultima_sucursal_Test extends SucursalSiempreTestCase
{
    /**
     * Deja al dueño del fixture con UNA sola sucursal (la dada), en la transacción del test.
     *
     * @param  \App\Models\Address  $la_que_queda
     * @return void
     */
    protected function dejar_solo($la_que_queda)
    {
        DB::table('addresses')
            ->where('user_id', $this->usuario()->id)
            ->whereNull('buyer_id')
            ->where('id', '!=', $la_que_queda->id)
            ->delete();
    }

    /**
     * @test
     */
    public function borrar_la_ultima_responde_422_y_no_toca_nada()
    {
        $unica = $this->otra_sucursal('zz Ultima D7');
        $this->dejar_solo($unica);

        $articulo = $this->crear_articulo('zz Ultima D7 articulo');
        $this->con_fila($articulo, $unica->id, 8);

        $this->json('DELETE', 'api/address/'.$unica->id)
             ->assertStatus(422)
             ->assertJsonPath('message', EliminarSucursalHelper::MENSAJE_ULTIMA_SUCURSAL)
             ->assertJsonPath('requiere_decision', false)
             ->assertJsonPath('bloqueos.0.codigo', 'ultima_sucursal');

        $this->assertNotNull(Address::find($unica->id));
        $this->assertEquals(8.0, $this->stock_en_deposito($articulo, $unica->id));
        $this->assertEquals(8.0, $this->stock($articulo));
    }

    /**
     * El resumen que la SPA pide antes de borrar ya lo dice (para no ofrecer el botón).
     *
     * @test
     */
    public function el_resumen_de_la_ultima_trae_el_bloqueo()
    {
        $unica = $this->otra_sucursal('zz Ultima D7 resumen');
        $this->dejar_solo($unica);

        $this->getJson('api/address/'.$unica->id.'/eliminar-resumen')
             ->assertStatus(200)
             ->assertJsonPath('es_la_ultima', true)
             ->assertJsonPath('bloqueos.0.codigo', 'ultima_sucursal');
    }

    /**
     * Borrando de a una (lo que hace el borrado masivo, `PUT api/delete/address`, que respeta el
     * rechazo de destroy()): con dos se borra una, y la última queda.
     *
     * @test
     */
    public function borrar_una_tras_otra_deja_la_ultima()
    {
        $a = $this->otra_sucursal('zz Masivo A');
        $this->dejar_solo($a);
        $b = $this->otra_sucursal('zz Masivo B');

        $this->assertSame(2, Address::sucursales_de($this->usuario()->id)->count());

        EliminarSucursalHelper::eliminar($b, $this->usuario()->id, $this->usuario()->id, []);

        $this->assertSame(1, Address::sucursales_de($this->usuario()->id)->count(), 'Con dos, la primera se borra.');

        $resultado = EliminarSucursalHelper::eliminar($a->fresh(), $this->usuario()->id, $this->usuario()->id, []);

        $this->assertSame(422, $resultado['status']);
        $this->assertSame(1, Address::sucursales_de($this->usuario()->id)->count(), 'La última queda.');
    }

    /**
     * El borrado masivo de un registro (`PUT api/delete/address`) sobre la última: no la borra y la
     * devuelve en `not_deleted` con el motivo.
     *
     * @test
     */
    public function el_masivo_no_borra_la_ultima()
    {
        $unica = $this->otra_sucursal('zz Ultima masivo');
        $this->dejar_solo($unica);

        $respuesta = $this->putJson('api/delete/address', ['models_id' => [$unica->id]]);

        $respuesta->assertStatus(200);
        $this->assertSame([], $respuesta->json('models'));
        $this->assertSame($unica->id, (int) $respuesta->json('not_deleted.0.id'));
        $this->assertStringContainsString('al menos una sucursal', $respuesta->json('not_deleted.0.message'));
        $this->assertNotNull(Address::find($unica->id));
    }

    /**
     * Con dos sucursales, borrar una sigue andando como siempre.
     *
     * @test
     */
    public function con_dos_sucursales_se_borra_una()
    {
        $a = $this->otra_sucursal('zz Dos A');
        $this->dejar_solo($a);
        $b = $this->otra_sucursal('zz Dos B');

        $this->json('DELETE', 'api/address/'.$b->id)->assertStatus(200);

        $this->assertNull(Address::find($b->id));
    }
}
