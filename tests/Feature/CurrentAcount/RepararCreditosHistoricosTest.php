<?php

namespace Tests\Feature\CurrentAcount;

use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\Seller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/** Ticket 145: operar estados históricos exige simulación real y reversión exacta. */
class RepararCreditosHistoricosTest extends EmpresaTestCase
{
    private $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carpeta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cc-creditos-'.bin2hex(random_bytes(8));
        mkdir($this->carpeta, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->carpeta.DIRECTORY_SEPARATOR.'*') ?: [] as $archivo) {
            if (is_file($archivo)) {
                unlink($archivo);
            } elseif (is_dir($archivo)) {
                rmdir($archivo);
            }
        }
        rmdir($this->carpeta);
        parent::tearDown();
    }

    private function escenario(): array
    {
        $usuario = $this->app['auth']->user()->id;
        $cliente = Client::create(['name' => 'Prueba reparación créditos', 'user_id' => $usuario, 'saldo_pesos' => 0]);
        $cuenta = CreditAccount::create(['model_name' => 'client', 'model_id' => $cliente->id,
            'moneda_id' => 1, 'user_id' => $usuario, 'saldo' => 0]);
        $comunes = ['client_id' => $cliente->id, 'user_id' => $usuario, 'moneda_id' => 1,
            'credit_account_id' => $cuenta->id, 'is_provisorio' => 0, 'pagandose' => 0];
        $debito = CurrentAcount::create(array_merge($comunes, ['detalle' => 'Venta histórica',
            'debe' => 1000, 'saldo' => 1000, 'status' => 'pagandose', 'pagandose' => 200,
            'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00']));
        $pago = CurrentAcount::create(array_merge($comunes, ['detalle' => 'Pago real', 'haber' => 200,
            'saldo' => 800, 'status' => 'pago_from_client', 'created_at' => '2026-06-01 10:00:00',
            'updated_at' => '2026-06-01 10:00:00']));
        $credito = CurrentAcount::create(array_merge($comunes, ['detalle' => 'Venta negativa histórica',
            'debe' => -800, 'saldo' => 0, 'status' => 'sin_pagar', 'created_at' => '2026-07-01 10:00:00',
            'updated_at' => '2026-07-01 10:00:00']));
        DB::table('pagado_por')->insert(['debe_id' => $debito->id, 'haber_id' => $pago->id,
            'pagado' => 200, 'total_pago' => 200, 'a_cubrir' => 1000, 'fondos_iniciales' => 200,
            'nuevos_fondos' => 0, 'remantente' => 800, 'created_at' => '2026-06-01 10:00:01']);
        return [$cuenta, $cliente, $debito, $pago, $credito];
    }

    private function foto($cuenta): array
    {
        $filas = DB::table('current_acounts')->where('credit_account_id', $cuenta->id)->orderBy('id')->get();
        return ['cuenta' => (array) DB::table('credit_accounts')->where('id', $cuenta->id)->first(),
            'duenio' => (array) DB::table('clients')->where('id', $cuenta->model_id)->first(),
            'movimientos' => $filas->toArray(),
            'imputaciones' => DB::table('pagado_por')->whereIn('debe_id', $filas->pluck('id'))
                ->orderBy('id')->get()->toArray()];
    }

    private function correr($cuenta, array $opciones = []): array
    {
        $codigo = Artisan::call('cuenta_corriente:reparar_creditos_historicos', array_merge(['--cuenta' => $cuenta->id], $opciones));
        return [$codigo, Artisan::output()];
    }

    /** @test */
    public function simular_muestra_el_plan_sin_escribir_consultas_ni_archivos()
    {
        list($cuenta) = $this->escenario();
        $antes = $this->foto($cuenta);
        $escrituras = [];
        DB::listen(function ($consulta) use (&$escrituras) {
            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $consulta->sql)) {
                $escrituras[] = $consulta->sql;
            }
        });
        list($codigo, $salida) = $this->correr($cuenta, ['--respaldo' => $this->carpeta.DIRECTORY_SEPARATOR.'no-creado.json']);
        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('"credito_total":800', $salida);
        $this->assertStringContainsString('"pendiente":0', $salida);
        $this->assertSame([], $escrituras);
        $this->assertSame([], glob($this->carpeta.DIRECTORY_SEPARATOR.'*'));
        $this->assertEquals($antes, $this->foto($cuenta));
    }

    /** @test */
    public function aplicar_repetir_y_revertir_conserva_exactamente_las_fuentes_y_el_respaldo()
    {
        list($cuenta, $cliente, $debito, $pago, $credito) = $this->escenario();
        $antes = $this->foto($cuenta);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'original.json';
        list($codigo, $salida) = $this->correr($cuenta, ['--aplicar' => true, '--respaldo' => $ruta]);
        $this->assertSame(0, $codigo, $salida);
        $this->assertSame('pagado', $debito->fresh()->status);
        $this->assertEquals(1000, $debito->fresh()->pagandose);
        $this->assertSame('pagado', $credito->fresh()->status);
        $this->assertEquals(-800, $credito->fresh()->debe);
        $this->assertNull($credito->fresh()->haber);
        $this->assertEquals(200, $pago->fresh()->haber);
        $this->assertEquals(0, $cuenta->fresh()->saldo);
        $this->assertEquals(0, $cliente->fresh()->saldo_pesos);
        $this->assertEquals(800, DB::table('pagado_por')->where('haber_id', $credito->id)->sum('pagado'));
        $contenido = file_get_contents($ruta);
        $datos = json_decode($contenido, true);
        $this->assertSame('creditos-historicos-v1', $datos['formato']);
        $despues = $this->foto($cuenta);
        list($codigo, $salida) = $this->correr($cuenta, ['--aplicar' => true, '--respaldo' => $ruta]);
        $this->assertSame(0, $codigo, $salida);
        $this->assertEquals($despues, $this->foto($cuenta));
        $this->assertSame($contenido, file_get_contents($ruta));
        list($codigo, $salida) = $this->correr($cuenta, ['--revertir' => true, '--respaldo' => $ruta]);
        $this->assertSame(0, $codigo, $salida);
        $this->assertEquals($despues, $this->foto($cuenta));
        list($codigo, $salida) = $this->correr($cuenta, ['--revertir' => true, '--aplicar' => true, '--respaldo' => $ruta]);
        $this->assertSame(0, $codigo, $salida);
        $this->assertEquals($antes, $this->foto($cuenta));
        $this->assertSame($contenido, file_get_contents($ruta));
        list($codigo, $salida) = $this->correr($cuenta, ['--revertir' => true, '--aplicar' => true, '--respaldo' => $ruta]);
        $this->assertSame(0, $codigo, $salida);
        $this->assertEquals($antes, $this->foto($cuenta));
    }

    /** @test */
    public function un_movimiento_posterior_impide_revertir_sin_perder_su_rastro()
    {
        list($cuenta, $cliente) = $this->escenario();
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'original.json';
        $this->assertSame(0, $this->correr($cuenta, ['--aplicar' => true, '--respaldo' => $ruta])[0]);
        CurrentAcount::create(['detalle' => 'Nuevo movimiento real', 'debe' => 50, 'status' => 'sin_pagar',
            'client_id' => $cliente->id, 'credit_account_id' => $cuenta->id, 'user_id' => $cliente->user_id]);
        $antes = $this->foto($cuenta);
        $contenido = file_get_contents($ruta);
        list($codigo, $salida) = $this->correr($cuenta, ['--revertir' => true, '--aplicar' => true, '--respaldo' => $ruta]);
        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('cambios posteriores', $salida);
        $this->assertEquals($antes, $this->foto($cuenta));
        $this->assertSame($contenido, file_get_contents($ruta));
    }

    /** @test */
    public function un_cambio_del_dueno_impide_revertir_aunque_no_cambien_los_importes()
    {
        list($cuenta, $cliente) = $this->escenario();
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'original.json';
        $this->assertSame(0, $this->correr($cuenta, ['--aplicar' => true, '--respaldo' => $ruta])[0]);
        DB::table('clients')->where('id', $cliente->id)->update(['name' => 'Dato posterior verificado']);
        $antes = $this->foto($cuenta);
        list($codigo, $salida) = $this->correr($cuenta, ['--revertir' => true, '--aplicar' => true, '--respaldo' => $ruta]);
        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('cambios posteriores', $salida);
        $this->assertEquals($antes, $this->foto($cuenta));
    }

    /** @test */
    public function actividad_posterior_de_puntos_impide_revertir_sin_tocar_el_libro()
    {
        list($cuenta, $cliente) = $this->escenario();
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'original.json';
        $this->assertSame(0, $this->correr($cuenta, ['--aplicar' => true, '--respaldo' => $ruta])[0]);
        $punto_id = DB::table('movimiento_puntos')->insertGetId(['client_id' => $cliente->id,
            'user_id' => $cliente->user_id, 'tipo' => 'ajuste', 'puntos' => 10, 'detalle' => 'Actividad posterior']);
        $antes = $this->foto($cuenta);
        list($codigo, $salida) = $this->correr($cuenta, ['--revertir' => true, '--aplicar' => true, '--respaldo' => $ruta]);
        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('cambios posteriores', $salida);
        $this->assertEquals($antes, $this->foto($cuenta));
        $this->assertEquals(10, DB::table('movimiento_puntos')->where('id', $punto_id)->value('puntos'));
    }

    /** @test */
    public function una_imputacion_de_comision_posterior_impide_revertir_sin_borrarla()
    {
        list($cuenta, $cliente, $debito, $pago) = $this->escenario();
        $venta = Sale::create(['client_id' => $cliente->id, 'user_id' => $cliente->user_id,
            'moneda_id' => 1, 'total' => 1000, 'terminada' => 1]);
        DB::table('current_acounts')->where('id', $debito->id)->update(['sale_id' => $venta->id]);
        $vendedor = Seller::create(['name' => 'Vendedor reparación', 'user_id' => $cliente->user_id,
            'num' => (int) Seller::where('user_id', $cliente->user_id)->max('num') + 1]);
        $comision_id = DB::table('seller_commissions')->insertGetId(['sale_id' => $venta->id,
            'seller_id' => $vendedor->id, 'user_id' => $cliente->user_id, 'debe' => 100, 'status' => 'inactive']);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'original.json';
        $this->assertSame(0, $this->correr($cuenta, ['--aplicar' => true, '--respaldo' => $ruta])[0]);
        $this->assertSame('inactive', DB::table('seller_commissions')->where('id', $comision_id)->value('status'));
        $vinculo_id = DB::table('current_acount_seller_commission')->insertGetId([
            'current_acount_id' => $pago->id, 'seller_commission_id' => $comision_id]);
        $antes = $this->foto($cuenta);
        list($codigo, $salida) = $this->correr($cuenta, ['--revertir' => true, '--aplicar' => true, '--respaldo' => $ruta]);
        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('cambios posteriores', $salida);
        $this->assertEquals($antes, $this->foto($cuenta));
        $this->assertTrue(DB::table('current_acount_seller_commission')->where('id', $vinculo_id)->exists());
    }

    /** @test */
    public function un_respaldo_alterado_o_de_otra_cuenta_no_habilita_escrituras()
    {
        list($cuenta) = $this->escenario();
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'original.json';
        $this->assertSame(0, $this->correr($cuenta, ['--aplicar' => true, '--respaldo' => $ruta])[0]);
        list($otra) = $this->escenario();
        $antes = $this->foto($otra);
        $this->assertSame(1, $this->correr($otra, ['--revertir' => true, '--aplicar' => true, '--respaldo' => $ruta])[0]);
        $this->assertEquals($antes, $this->foto($otra));
        $datos = json_decode(file_get_contents($ruta), true);
        $datos['antes']['movimientos'][0]['debe'] = '99999.00';
        file_put_contents($ruta, json_encode($datos));
        $antes = $this->foto($cuenta);
        list($codigo, $salida) = $this->correr($cuenta, ['--revertir' => true, '--aplicar' => true, '--respaldo' => $ruta]);
        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('alterado', $salida);
        $this->assertEquals($antes, $this->foto($cuenta));
    }

    /** @test */
    public function ni_un_sha_recalculado_habilita_insertar_imputaciones_ajenas_desde_el_respaldo()
    {
        list($cuenta) = $this->escenario();
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'original.json';
        $this->assertSame(0, $this->correr($cuenta, ['--aplicar' => true, '--respaldo' => $ruta])[0]);
        list($otra, $otro_cliente, $otro_debito) = $this->escenario();
        $datos = json_decode(file_get_contents($ruta), true);
        $datos['antes']['imputaciones'][0]['debe_id'] = $otro_debito->id;
        unset($datos['sha256']);
        $datos['sha256'] = hash('sha256', json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        file_put_contents($ruta, json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $antes = $this->foto($cuenta);
        $otra_antes = $this->foto($otra);
        list($codigo, $salida) = $this->correr($cuenta, ['--revertir' => true, '--aplicar' => true, '--respaldo' => $ruta]);
        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('otra cuenta', $salida);
        $this->assertEquals($antes, $this->foto($cuenta));
        $this->assertEquals($otra_antes, $this->foto($otra));
    }

    /** @test */
    public function una_falla_al_crear_el_respaldo_revierte_todos_los_cambios()
    {
        list($cuenta) = $this->escenario();
        $antes = $this->foto($cuenta);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'ocupado';
        mkdir($ruta, 0700);
        list($codigo, $salida) = $this->correr($cuenta, ['--aplicar' => true, '--respaldo' => $ruta]);
        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('respaldo exclusivo', $salida);
        $this->assertEquals($antes, $this->foto($cuenta));
    }

    /** @test */
    public function la_reparacion_solo_escribe_estados_e_imputaciones_sin_efectos_en_otros_libros()
    {
        list($cuenta) = $this->escenario();
        $escrituras_ajenas = [];
        DB::listen(function ($consulta) use (&$escrituras_ajenas) {
            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $consulta->sql)
                && !preg_match('/^\s*(update `current_acounts`|delete from `pagado_por`|insert into `pagado_por`)(?:\s|$)/i', $consulta->sql)) {
                $escrituras_ajenas[] = $consulta->sql;
            }
        });
        list($codigo, $salida) = $this->correr($cuenta, ['--aplicar' => true,
            '--respaldo' => $this->carpeta.DIRECTORY_SEPARATOR.'original.json']);
        $this->assertSame(0, $codigo, $salida);
        $this->assertSame([], $escrituras_ajenas, 'No se permite escribir en caja, stock, ventas, documentos, comisiones, puntos, cuenta ni dueño.');
    }

    /** @test */
    public function un_importe_ambiguo_rechaza_simulacion_y_aplicacion_sin_crear_respaldo()
    {
        list($cuenta, $cliente, $debito) = $this->escenario();
        DB::table('current_acounts')->where('id', $debito->id)->update(['haber' => 25]);
        $antes = $this->foto($cuenta);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'no-creado.json';
        foreach ([[], ['--aplicar' => true, '--respaldo' => $ruta]] as $opciones) {
            list($codigo, $salida) = $this->correr($cuenta, $opciones);
            $this->assertSame(1, $codigo);
            $this->assertStringContainsString('movimiento #'.$debito->id, $salida);
            $this->assertStringContainsString('Revisá', $salida);
            $this->assertEquals($antes, $this->foto($cuenta));
            $this->assertFileDoesNotExist($ruta);
        }
    }

    public function saldos_discordantes()
    {
        return [['cuenta'], ['intermedio'], ['final'], ['nulo']];
    }

    /**
     * @test
     * @dataProvider saldos_discordantes
     */
    public function un_saldo_discordante_rechaza_simular_y_aplicar_sin_escrituras_ni_respaldo($caso)
    {
        list($cuenta, $cliente, $debito, $pago, $credito) = $this->escenario();
        if ($caso === 'cuenta') {
            DB::table('credit_accounts')->where('id', $cuenta->id)->update(['saldo' => 15]);
        } elseif ($caso === 'intermedio') {
            DB::table('current_acounts')->where('id', $pago->id)->update(['saldo' => 15]);
        } else {
            DB::table('current_acounts')->where('id', $credito->id)->update(['saldo' => $caso === 'nulo' ? null : 15]);
        }
        $antes = $this->foto($cuenta);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'no-creado.json';
        $escrituras = [];
        DB::listen(function ($consulta) use (&$escrituras) {
            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $consulta->sql)) {
                $escrituras[] = $consulta->sql;
            }
        });
        foreach ([[], ['--aplicar' => true, '--respaldo' => $ruta]] as $opciones) {
            list($codigo, $salida) = $this->correr($cuenta, $opciones);
            $this->assertSame(1, $codigo, $caso);
            $this->assertStringContainsString('saldo guardado', $salida);
            $this->assertStringContainsString('soporte', $salida);
            $this->assertSame([], $escrituras, 'La validación tiene que ocurrir antes de escribir cualquier estado o imputación.');
            $this->assertEquals($antes, $this->foto($cuenta));
            $this->assertFileDoesNotExist($ruta);
        }
    }

    /** @test */
    public function rechaza_escrituras_sin_cuenta_o_sin_respaldo_o_dentro_del_codigo()
    {
        list($cuenta) = $this->escenario();
        $antes = $this->foto($cuenta);
        $this->assertSame(1, Artisan::call('cuenta_corriente:reparar_creditos_historicos'));
        $this->assertSame(1, $this->correr($cuenta, ['--aplicar' => true])[0]);
        $this->assertSame(1, $this->correr($cuenta, ['--aplicar' => true, '--respaldo' => base_path('respaldo-prohibido.json')])[0]);
        $this->assertFileDoesNotExist(base_path('respaldo-prohibido.json'));
        $this->assertEquals($antes, $this->foto($cuenta));
    }
}
