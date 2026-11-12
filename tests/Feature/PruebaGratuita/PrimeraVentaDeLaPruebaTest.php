<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\caja\CajaAperturaHelper;
use App\Http\Controllers\Helpers\PrimeraVentaDeLaPruebaHelper;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\SemillaRegistroHelper;
use App\Models\Address;
use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\DefaultPaymentMethodCaja;
use App\Models\PruebaConfig;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * La prueba gratuita nace (y sigue, después del primer import) lista para su primera venta (misión
 * prueba-primera-venta, 12/11/2026):
 *
 *  1. El dueño sin sucursal queda con la principal; uno con una sucursal válida la conserva.
 *  2. La caja de efectivo por defecto de esa sucursal queda abierta, con la apertura a nombre del dueño; las
 *     otras cajas cerradas siguen cerradas. Dos corridas no abren dos veces.
 *  3. Sin configuración por defecto y sin ninguna caja abierta, se abre la primera de la sucursal o compartida.
 *  4. El borrado de lo sembrado al primer import (que deja las cajas de ejemplo cerradas) termina con la caja
 *     de efectivo abierta otra vez.
 */
class PrimeraVentaDeLaPruebaTest extends EmpresaTestCase
{
    /** @var \App\Models\User */
    protected $dueno;

    /** @var \App\Models\Address */
    protected $principal;

    /** @var \App\Models\Address */
    protected $otra;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.FOR_USER' => 'prueba']);
        PruebaHelper::olvidar_cache();
        SemillaRegistroHelper::descartar_lote();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        config(['app.USER_ID' => $this->dueno->id]);

        // Punto de partida del ensayo: todas las cajas cerradas, sin cajas por defecto y el dueño sin sucursal.
        Caja::where('user_id', $this->dueno->id)->update(['abierta' => 0, 'current_apertura_caja_id' => null]);
        DefaultPaymentMethodCaja::where('user_id', $this->dueno->id)->delete();
        $this->dueno->address_id = null;
        $this->dueno->save();

        // El helper se protege solo: sin prueba activa no hace nada (ver test_fuera_de_una_prueba_no_toca_nada).
        PruebaConfig::updateOrCreate(['owner_id' => $this->dueno->id], ['dias' => 7]);
        PruebaHelper::olvidar_cache();

        $this->principal = Address::create(['street' => 'Principal del test', 'user_id' => $this->dueno->id, 'default_address' => 1]);
        $this->otra = Address::create(['street' => 'Otra del test', 'user_id' => $this->dueno->id]);
    }

    protected function tearDown(): void
    {
        SemillaRegistroHelper::descartar_lote();
        PruebaHelper::olvidar_cache();
        parent::tearDown();
    }

    private function crear_caja($nombre, $address_id = null)
    {
        return Caja::create([
            'num'     => (int) Caja::where('user_id', $this->dueno->id)->max('num') + 1,
            'name'    => $nombre,
            'user_id' => $this->dueno->id,
            'address_id' => $address_id,
            'abierta' => 0,
        ]);
    }

    private function por_defecto_de_efectivo(Caja $caja, $address_id)
    {
        DefaultPaymentMethodCaja::create([
            'current_acount_payment_method_id' => PrimeraVentaDeLaPruebaHelper::METODO_EFECTIVO,
            'address_id' => $address_id,
            'caja_id'    => $caja->id,
            'user_id'    => $this->dueno->id,
        ]);
    }

    public function test_fija_la_principal_y_abre_la_caja_de_efectivo_por_defecto()
    {
        $efectivo_principal = $this->crear_caja('Efectivo principal', $this->principal->id);
        $efectivo_otra = $this->crear_caja('Efectivo otra', $this->otra->id);
        $this->por_defecto_de_efectivo($efectivo_principal, $this->principal->id);
        $this->por_defecto_de_efectivo($efectivo_otra, $this->otra->id);

        $resultado = PrimeraVentaDeLaPruebaHelper::dejar_lista_para_vender($this->dueno->id);

        $this->assertSame($this->principal->id, $resultado['address_id']);
        $this->assertSame($efectivo_principal->id, $resultado['caja_abierta_id']);
        $this->assertSame($this->principal->id, (int) $this->dueno->fresh()->address_id, 'El dueño vende desde la principal.');

        $abierta = $efectivo_principal->fresh();
        $this->assertSame(1, (int) $abierta->abierta);
        $this->assertNotNull($abierta->current_apertura_caja_id);
        $this->assertSame(
            $this->dueno->id,
            (int) AperturaCaja::find($abierta->current_apertura_caja_id)->apertura_employee_id,
            'La apertura queda a nombre del dueño.'
        );

        $this->assertSame(0, (int) $efectivo_otra->fresh()->abierta, 'La caja de otra sucursal no se toca.');
    }

    public function test_dos_corridas_no_abren_dos_veces()
    {
        $efectivo = $this->crear_caja('Efectivo principal', $this->principal->id);
        $this->por_defecto_de_efectivo($efectivo, $this->principal->id);

        PrimeraVentaDeLaPruebaHelper::dejar_lista_para_vender($this->dueno->id);
        PrimeraVentaDeLaPruebaHelper::dejar_lista_para_vender($this->dueno->id);

        $this->assertSame(1, AperturaCaja::where('caja_id', $efectivo->id)->count());
    }

    public function test_conserva_la_sucursal_valida_del_dueno()
    {
        $this->dueno->address_id = $this->otra->id;
        $this->dueno->save();

        $efectivo_otra = $this->crear_caja('Efectivo otra', $this->otra->id);
        $this->por_defecto_de_efectivo($efectivo_otra, $this->otra->id);

        $resultado = PrimeraVentaDeLaPruebaHelper::dejar_lista_para_vender($this->dueno->id);

        $this->assertSame($this->otra->id, (int) $this->dueno->fresh()->address_id);
        $this->assertSame($efectivo_otra->id, $resultado['caja_abierta_id']);
    }

    public function test_sin_caja_por_defecto_abre_la_primera_de_la_sucursal()
    {
        $de_otra = $this->crear_caja('De otra sucursal', $this->otra->id);
        $de_la_principal = $this->crear_caja('De la principal', $this->principal->id);

        $resultado = PrimeraVentaDeLaPruebaHelper::dejar_lista_para_vender($this->dueno->id);

        $this->assertSame($de_la_principal->id, $resultado['caja_abierta_id']);
        $this->assertSame(1, (int) $de_la_principal->fresh()->abierta);
        $this->assertSame(0, (int) $de_otra->fresh()->abierta);
    }

    public function test_sin_caja_por_defecto_y_con_una_abierta_no_abre_otra()
    {
        $abierta = $this->crear_caja('Ya abierta', $this->principal->id);
        (new CajaAperturaHelper($abierta->id))->abrir_caja();
        $cerrada = $this->crear_caja('Cerrada', null);

        $resultado = PrimeraVentaDeLaPruebaHelper::dejar_lista_para_vender($this->dueno->id);

        $this->assertSame($abierta->id, $resultado['caja_abierta_id']);
        $this->assertSame(0, (int) $cerrada->fresh()->abierta);
    }

    public function test_el_borrado_de_la_semilla_al_importar_deja_la_caja_de_efectivo_abierta()
    {
        $efectivo = $this->crear_caja('Efectivo principal', $this->principal->id);
        $this->por_defecto_de_efectivo($efectivo, $this->principal->id);

        // La semilla abre la caja (apertura de ejemplo, sin movimientos reales).
        SemillaRegistroHelper::abrir_lote($this->dueno->id, 'test');
        (new CajaAperturaHelper($efectivo->id))->abrir_caja();
        SemillaRegistroHelper::cerrar_lote();
        $apertura_de_ejemplo = $efectivo->fresh()->current_apertura_caja_id;

        $this->assertTrue(PruebaHelper::borrar_semilla_antes_de_importar($this->dueno->id, 'excel'));

        $caja = $efectivo->fresh();
        $this->assertSame(1, (int) $caja->abierta, 'Después del primer import la caja de efectivo sigue abierta.');
        $this->assertNotNull($caja->current_apertura_caja_id);
        $this->assertNotEquals($apertura_de_ejemplo, $caja->current_apertura_caja_id, 'Es una apertura nueva, no la de ejemplo.');
    }

    public function test_la_apertura_del_armado_no_queda_huerfana_al_primer_import()
    {
        $efectivo = $this->crear_caja('Efectivo principal', $this->principal->id);
        $this->por_defecto_de_efectivo($efectivo, $this->principal->id);

        // El demo setup: la semilla (un artículo de ejemplo) en su lote, y DESPUÉS se deja lista para vender.
        SemillaRegistroHelper::abrir_lote($this->dueno->id, 'test');
        \App\Models\Article::create(['name' => 'De ejemplo', 'user_id' => $this->dueno->id, 'status' => 'active']);
        SemillaRegistroHelper::cerrar_lote();

        PrimeraVentaDeLaPruebaHelper::dejar_lista_para_vender($this->dueno->id);
        $apertura_del_armado = $efectivo->fresh()->current_apertura_caja_id;
        $this->assertContains($apertura_del_armado, SemillaRegistroHelper::ids($this->dueno->id, 'apertura_cajas'), 'La apertura del armado cuenta como dato de ejemplo.');

        $this->assertTrue(PruebaHelper::borrar_semilla_antes_de_importar($this->dueno->id, 'excel'));

        $aperturas = AperturaCaja::where('caja_id', $efectivo->id)->get();
        $this->assertCount(1, $aperturas, 'Queda UNA sola apertura: la del armado se fue con la semilla.');
        $this->assertNull(AperturaCaja::find($apertura_del_armado));
        $this->assertSame(1, (int) $efectivo->fresh()->abierta);
        $this->assertSame((int) $aperturas->first()->id, (int) $efectivo->fresh()->current_apertura_caja_id);
        $this->assertNotContains((int) $aperturas->first()->id, SemillaRegistroHelper::ids($this->dueno->id, 'apertura_cajas'), 'La nueva es del negocio.');
    }

    public function test_fuera_de_una_prueba_no_toca_nada()
    {
        $efectivo = $this->crear_caja('Efectivo principal', $this->principal->id);
        $this->por_defecto_de_efectivo($efectivo, $this->principal->id);

        // Sin configuración de prueba (un negocio que no está en prueba)...
        PruebaConfig::where('owner_id', $this->dueno->id)->delete();
        PruebaHelper::olvidar_cache();
        $resultado = PrimeraVentaDeLaPruebaHelper::dejar_lista_para_vender($this->dueno->id);
        $this->assertNull($resultado['caja_abierta_id']);

        // ...y en una instancia que no es de prueba, aunque tenga la configuración.
        PruebaConfig::updateOrCreate(['owner_id' => $this->dueno->id], ['dias' => 7]);
        config(['app.FOR_USER' => null]);
        PruebaHelper::olvidar_cache();
        $resultado = PrimeraVentaDeLaPruebaHelper::dejar_lista_para_vender($this->dueno->id);
        $this->assertNull($resultado['caja_abierta_id']);

        $this->assertSame(0, (int) $efectivo->fresh()->abierta);
        $this->assertNull($this->dueno->fresh()->address_id);
    }

    public function test_el_comando_se_niega_fuera_de_una_prueba()
    {
        $efectivo = $this->crear_caja('Efectivo principal', $this->principal->id);
        $this->por_defecto_de_efectivo($efectivo, $this->principal->id);

        config(['app.FOR_USER' => null]);
        PruebaHelper::olvidar_cache();

        $this->artisan('prueba:lista-para-vender')
            ->expectsOutput('No es una instancia de prueba activa: no se toca nada.')
            ->assertExitCode(0);

        $this->assertSame(0, (int) $efectivo->fresh()->abierta);
    }

    public function test_el_comando_deja_lista_una_prueba_activa()
    {
        $efectivo = $this->crear_caja('Efectivo principal', $this->principal->id);
        $this->por_defecto_de_efectivo($efectivo, $this->principal->id);

        $this->artisan('prueba:lista-para-vender')->assertExitCode(0);

        $this->assertSame(1, (int) $efectivo->fresh()->abierta);
    }
}
