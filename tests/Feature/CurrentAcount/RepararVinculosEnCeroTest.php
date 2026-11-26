<?php

namespace Tests\Feature\CurrentAcount;

use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Provider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Modo `--vinculos-en-cero` de cuenta_corriente:reparar_creditos_historicos (tanda 25/11/2026).
 *
 * Quita sólo las filas de `pagado_por` que cruzan una cuenta con otra y valen $0. Cada test crea lo
 * que usa; ninguno depende de datos preexistentes de la base de testing.
 */
class RepararVinculosEnCeroTest extends EmpresaTestCase
{
    private $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carpeta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cc-vinculos-'.bin2hex(random_bytes(8));
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

    /**
     * Crea una cuenta con sus movimientos y la cadena de saldos conciliada.
     *
     * @param string $tipo  'client' o 'provider'.
     * @param array  $filas Cada una: [debe|null, haber|null, fecha].
     * @return array [CreditAccount, ids de movimientos en orden]
     */
    private function cuenta_con_movimientos(string $tipo, array $filas): array
    {
        $usuario = $this->app['auth']->user()->id;
        $duenio = $tipo === 'client'
            ? Client::create(['name' => 'Prueba vínculos '.bin2hex(random_bytes(3)), 'user_id' => $usuario, 'saldo_pesos' => 0])
            : Provider::create(['name' => 'Prueba vínculos '.bin2hex(random_bytes(3)), 'user_id' => $usuario]);
        $cuenta = CreditAccount::create(['model_name' => $tipo, 'model_id' => $duenio->id,
            'moneda_id' => 1, 'user_id' => $usuario, 'saldo' => 0]);
        $saldo = 0;
        $ids = [];
        foreach ($filas as $fila) {
            list($debe, $haber, $fecha) = $fila;
            $saldo += (float) $debe - (float) $haber;
            $datos = ['user_id' => $usuario, 'moneda_id' => 1, 'credit_account_id' => $cuenta->id,
                'is_provisorio' => 0, 'pagandose' => 0, 'detalle' => 'Movimiento de prueba',
                'debe' => $debe, 'haber' => $haber, 'saldo' => $saldo,
                'status' => is_null($haber) ? 'sin_pagar' : 'pago_from_client',
                'created_at' => $fecha, 'updated_at' => $fecha];
            $datos[$tipo === 'client' ? 'client_id' : 'provider_id'] = $duenio->id;
            $ids[] = CurrentAcount::create($datos)->id;
        }
        $cuenta->update(['saldo' => $saldo]);
        return [$cuenta->fresh(), $ids];
    }

    private function pivote(int $debe_id, int $haber_id, $pagado): int
    {
        return DB::table('pagado_por')->insertGetId(['debe_id' => $debe_id, 'haber_id' => $haber_id,
            'pagado' => $pagado, 'total_pago' => $pagado, 'a_cubrir' => 1000, 'fondos_iniciales' => 0,
            'nuevos_fondos' => 0, 'remantente' => 0, 'created_at' => '2026-06-01 10:00:01',
            'updated_at' => '2026-06-01 10:00:01']);
    }

    /**
     * Dos cuentas con dos cruces en $0 (uno por cada sentido) y una imputación propia con importe.
     *
     * @return array a, b, ma, mb (ids de movimientos), cero (ids de pagado_por), propia
     */
    private function escenario(string $tipo_b = 'client'): array
    {
        list($a, $ma) = $this->cuenta_con_movimientos('client', [
            [1000, null, '2026-01-01 10:00:00'], [400, null, '2026-01-02 10:00:00'], [null, 100, '2026-01-03 10:00:00']]);
        list($b, $mb) = $this->cuenta_con_movimientos($tipo_b, [
            [700, null, '2026-01-01 11:00:00'], [null, 50, '2026-01-02 11:00:00'], [null, 20, '2026-01-03 11:00:00']]);
        $propia = $this->pivote($ma[0], $ma[2], 100);
        $cero_1 = $this->pivote($ma[0], $mb[1], 0);
        $cero_2 = $this->pivote($mb[0], $ma[2], 0);
        return ['a' => $a, 'b' => $b, 'ma' => $ma, 'mb' => $mb, 'cero' => [$cero_1, $cero_2], 'propia' => $propia];
    }

    private function foto($cuenta): array
    {
        $filas = DB::table('current_acounts')->where('credit_account_id', $cuenta->id)->orderBy('id')->get();
        $ids = $filas->pluck('id');
        $tabla = $cuenta->model_name === 'client' ? 'clients' : 'providers';
        return ['cuenta' => (array) DB::table('credit_accounts')->where('id', $cuenta->id)->first(),
            'duenio' => (array) DB::table($tabla)->where('id', $cuenta->model_id)->first(),
            'movimientos' => $filas->toArray(),
            'imputaciones' => DB::table('pagado_por')->where(function ($q) use ($ids) {
                $q->whereIn('debe_id', $ids)->orWhereIn('haber_id', $ids);
            })->orderBy('id')->get()->toArray()];
    }

    /** La foto esperada tras borrar `$ids`: igual que antes salvo esas filas de imputaciones. */
    private function sin_pivotes(array $foto, array $ids): array
    {
        $foto['imputaciones'] = array_values(array_filter($foto['imputaciones'], function ($fila) use ($ids) {
            return !in_array((int) $fila->id, $ids, true);
        }));
        return $foto;
    }

    private function correr($cuenta, array $opciones = []): array
    {
        $codigo = Artisan::call('cuenta_corriente:reparar_creditos_historicos',
            array_merge(['--cuenta' => $cuenta->id, '--vinculos-en-cero' => true], $opciones));
        return [$codigo, Artisan::output()];
    }

    private function salida_json(string $salida): array
    {
        foreach (explode("\n", trim($salida)) as $linea) {
            $datos = json_decode($linea, true);
            if (is_array($datos) && isset($datos['cero'])) {
                return $datos;
            }
        }
        $this->fail('La salida no trae la línea JSON del modo: '.$salida);
    }

    private function ids_de(array $filas): array
    {
        $ids = array_map(function ($fila) { return (int) $fila['id']; }, $filas);
        sort($ids);
        return $ids;
    }

    private function contar_escrituras(callable $accion): array
    {
        $escrituras = [];
        DB::listen(function ($consulta) use (&$escrituras) {
            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $consulta->sql)) {
                $escrituras[] = $consulta->sql;
            }
        });
        $accion();
        return $escrituras;
    }

    /** @test */
    public function ca1_simular_lista_los_cruces_en_cero_sin_escribir_consultas_ni_archivos()
    {
        $e = $this->escenario();
        $antes_a = $this->foto($e['a']);
        $antes_b = $this->foto($e['b']);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'no-creado.json';
        $resultado = null;
        $escrituras = $this->contar_escrituras(function () use ($e, $ruta, &$resultado) {
            $resultado = $this->correr($e['a'], ['--respaldo' => $ruta]);
        });
        list($codigo, $salida) = $resultado;
        $this->assertSame(0, $codigo, $salida);
        $datos = $this->salida_json($salida);
        $this->assertEqualsCanonicalizing($e['cero'], $this->ids_de($datos['cero']));
        $this->assertSame([], $datos['con_importe']);
        $this->assertSame((int) $e['a']->id, (int) $datos['cuenta_id']);
        $this->assertSame([], $escrituras);
        $this->assertFalse(file_exists($ruta));
        $this->assertEquals($antes_a, $this->foto($e['a']));
        $this->assertEquals($antes_b, $this->foto($e['b']));
    }

    /** @test */
    public function ca2_aplicar_borra_exactamente_los_ids_y_conserva_saldos_estados_y_la_fila_propia()
    {
        $e = $this->escenario();
        $antes_a = $this->foto($e['a']);
        $antes_b = $this->foto($e['b']);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'cero.json';
        list($codigo, $salida) = $this->correr($e['a'], ['--aplicar' => true,
            '--pivotes' => implode(',', $e['cero']), '--respaldo' => $ruta]);
        $this->assertSame(0, $codigo, $salida);
        $this->assertSame(0, DB::table('pagado_por')->whereIn('id', $e['cero'])->count());
        $this->assertSame(1, DB::table('pagado_por')->where('id', $e['propia'])->count());
        $this->assertEquals($this->sin_pivotes($antes_a, $e['cero']), $this->foto($e['a']));
        $this->assertEquals($this->sin_pivotes($antes_b, $e['cero']), $this->foto($e['b']));
        $datos = json_decode(file_get_contents($ruta), true);
        $this->assertSame('vinculos-en-cero-v1', $datos['formato']);
        $this->assertSame((int) $e['a']->id, $datos['cuenta_id']);
        $this->assertCount(2, $datos['pivotes']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $datos['sha256']);
        $this->assertSame(0600, fileperms($ruta) & 0777);
    }

    /** @test */
    public function ca3_los_ids_deben_coincidir_exactamente_con_los_cruces_en_cero()
    {
        $e = $this->escenario();
        $antes_a = $this->foto($e['a']);
        $antes_b = $this->foto($e['b']);
        $casos = [
            'de menos' => [$e['cero'][0]],
            'de más (fila propia con importe)' => [$e['cero'][0], $e['cero'][1], $e['propia']],
            'ajeno inexistente' => [$e['cero'][0], $e['cero'][1], 987654321],
            'reemplazo por un id ajeno' => [$e['cero'][0], 987654321],
        ];
        foreach ($casos as $nombre => $ids) {
            $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'no-debe-existir.json';
            $resultado = null;
            $escrituras = $this->contar_escrituras(function () use ($e, $ids, $ruta, &$resultado) {
                $resultado = $this->correr($e['a'], ['--aplicar' => true,
                    '--pivotes' => implode(',', $ids), '--respaldo' => $ruta]);
            });
            $this->assertSame(1, $resultado[0], $nombre.': '.$resultado[1]);
            $this->assertStringContainsString('no coincide', $resultado[1], $nombre);
            $this->assertSame([], $escrituras, $nombre);
            $this->assertFalse(file_exists($ruta), $nombre);
            $this->assertEquals($antes_a, $this->foto($e['a']), $nombre);
            $this->assertEquals($antes_b, $this->foto($e['b']), $nombre);
        }
    }

    /** @test */
    public function ca3_aplicar_sin_ids_o_sin_respaldo_se_rechaza_sin_escribir()
    {
        $e = $this->escenario();
        $antes_a = $this->foto($e['a']);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'no-debe-existir.json';
        list($codigo, $salida) = $this->correr($e['a'], ['--aplicar' => true, '--respaldo' => $ruta]);
        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('--pivotes', $salida);
        list($codigo, $salida) = $this->correr($e['a'], ['--aplicar' => true, '--pivotes' => implode(',', $e['cero'])]);
        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('--respaldo', $salida);
        list($codigo, $salida) = $this->correr($e['a'], ['--aplicar' => true, '--pivotes' => 'abc,1', '--respaldo' => $ruta]);
        $this->assertSame(1, $codigo, $salida);
        $this->assertFalse(file_exists($ruta));
        $this->assertEquals($antes_a, $this->foto($e['a']));
    }

    /** @test */
    public function ca4_un_cruce_con_importe_se_informa_y_bloquea_la_aplicacion()
    {
        $e = $this->escenario();
        $con_importe = $this->pivote($e['ma'][1], $e['mb'][2], 100);
        $antes_a = $this->foto($e['a']);
        $antes_b = $this->foto($e['b']);
        list($codigo, $salida) = $this->correr($e['a']);
        $this->assertSame(0, $codigo, $salida);
        $datos = $this->salida_json($salida);
        $this->assertEqualsCanonicalizing($e['cero'], $this->ids_de($datos['cero']));
        $this->assertSame([$con_importe], $this->ids_de($datos['con_importe']));
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'no-debe-existir.json';
        list($codigo, $salida) = $this->correr($e['a'], ['--aplicar' => true,
            '--pivotes' => implode(',', $e['cero']), '--respaldo' => $ruta]);
        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('importe', $salida);
        $this->assertFalse(file_exists($ruta));
        $this->assertSame(3, DB::table('pagado_por')->whereIn('id', array_merge($e['cero'], [$con_importe]))->count());
        $this->assertEquals($antes_a, $this->foto($e['a']));
        $this->assertEquals($antes_b, $this->foto($e['b']));
    }

    /** @test */
    public function ca5_cliente_con_proveedor_aplica_bloqueando_primero_al_cliente()
    {
        $e = $this->escenario('provider');
        $antes_a = $this->foto($e['a']);
        $antes_b = $this->foto($e['b']);
        $candados = [];
        DB::listen(function ($consulta) use (&$candados) {
            if (preg_match('/for update/i', $consulta->sql)
                && preg_match('/from `(clients|providers)`/i', $consulta->sql, $m)) {
                $candados[] = $m[1];
            }
        });
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'cero.json';
        list($codigo, $salida) = $this->correr($e['a'], ['--aplicar' => true,
            '--pivotes' => implode(',', $e['cero']), '--respaldo' => $ruta]);
        $this->assertSame(0, $codigo, $salida);
        $this->assertSame(0, DB::table('pagado_por')->whereIn('id', $e['cero'])->count());
        $this->assertEquals($this->sin_pivotes($antes_a, $e['cero']), $this->foto($e['a']));
        $this->assertEquals($this->sin_pivotes($antes_b, $e['cero']), $this->foto($e['b']));
        // Orden canónico: el primer candado de dueño es el del cliente y ninguno de cliente va después de uno de proveedor.
        $this->assertNotEmpty($candados);
        $this->assertSame('clients', $candados[0]);
        $primer_proveedor = array_search('providers', $candados, true);
        $this->assertNotFalse($primer_proveedor, 'Debe bloquear también al dueño proveedor.');
        $this->assertNotContains('clients', array_slice($candados, $primer_proveedor));
    }

    /** @test */
    public function ca6_repetir_es_idempotente_y_revertir_devuelve_las_filas_con_sus_ids()
    {
        $e = $this->escenario();
        $originales = DB::table('pagado_por')->whereIn('id', $e['cero'])->orderBy('id')->get()->toArray();
        $antes_a = $this->foto($e['a']);
        $antes_b = $this->foto($e['b']);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'cero.json';
        $opciones = ['--aplicar' => true, '--pivotes' => implode(',', $e['cero']), '--respaldo' => $ruta];
        $this->assertSame(0, $this->correr($e['a'], $opciones)[0]);
        $contenido = file_get_contents($ruta);
        $despues_a = $this->foto($e['a']);

        // Segunda corrida con el mismo respaldo: "ya aplicada", sin escrituras ni cambios en el archivo.
        $resultado = null;
        $escrituras = $this->contar_escrituras(function () use ($e, $opciones, &$resultado) {
            $resultado = $this->correr($e['a'], $opciones);
        });
        $this->assertSame(0, $resultado[0], $resultado[1]);
        $this->assertStringContainsString('ya está aplicada', $resultado[1]);
        $this->assertSame([], $escrituras);
        $this->assertSame($contenido, file_get_contents($ruta));
        $this->assertEquals($despues_a, $this->foto($e['a']));

        // Reversión simulada: no escribe.
        $escrituras = $this->contar_escrituras(function () use ($e, $ruta, &$resultado) {
            $resultado = $this->correr($e['a'], ['--revertir' => true, '--respaldo' => $ruta]);
        });
        $this->assertSame(0, $resultado[0], $resultado[1]);
        $this->assertSame([], $escrituras);
        $this->assertEquals($despues_a, $this->foto($e['a']));

        // Reversión aplicada: vuelven las dos filas, con los mismos ids y valores, y las fotos son las de antes.
        list($codigo, $salida) = $this->correr($e['a'], ['--revertir' => true, '--aplicar' => true, '--respaldo' => $ruta]);
        $this->assertSame(0, $codigo, $salida);
        $this->assertEquals($originales, DB::table('pagado_por')->whereIn('id', $e['cero'])->orderBy('id')->get()->toArray());
        $this->assertEquals($antes_a, $this->foto($e['a']));
        $this->assertEquals($antes_b, $this->foto($e['b']));
        $this->assertSame($contenido, file_get_contents($ruta));

        // Revertir otra vez: ya coincide con el estado anterior.
        list($codigo, $salida) = $this->correr($e['a'], ['--revertir' => true, '--aplicar' => true, '--respaldo' => $ruta]);
        $this->assertSame(0, $codigo, $salida);
        $this->assertEquals($antes_a, $this->foto($e['a']));
    }

    /** @test */
    public function ca6_un_movimiento_posterior_en_la_otra_cuenta_impide_revertir()
    {
        $e = $this->escenario();
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'cero.json';
        $this->assertSame(0, $this->correr($e['a'], ['--aplicar' => true,
            '--pivotes' => implode(',', $e['cero']), '--respaldo' => $ruta])[0]);
        CurrentAcount::create(['user_id' => $this->app['auth']->user()->id, 'moneda_id' => 1,
            'credit_account_id' => $e['b']->id, 'client_id' => $e['b']->model_id, 'is_provisorio' => 0,
            'pagandose' => 0, 'detalle' => 'Actividad posterior', 'debe' => 5, 'saldo' => 635,
            'status' => 'sin_pagar', 'created_at' => '2026-08-01 10:00:00', 'updated_at' => '2026-08-01 10:00:00']);
        $despues_a = $this->foto($e['a']);
        $despues_b = $this->foto($e['b']);
        $resultado = null;
        $escrituras = $this->contar_escrituras(function () use ($e, $ruta, &$resultado) {
            $resultado = $this->correr($e['a'], ['--revertir' => true, '--aplicar' => true, '--respaldo' => $ruta]);
        });
        $this->assertSame(1, $resultado[0], $resultado[1]);
        $this->assertSame([], $escrituras);
        $this->assertSame(0, DB::table('pagado_por')->whereIn('id', $e['cero'])->count());
        $this->assertEquals($despues_a, $this->foto($e['a']));
        $this->assertEquals($despues_b, $this->foto($e['b']));
    }

    /** @test */
    public function ca6_un_respaldo_alterado_no_habilita_ni_repetir_ni_revertir()
    {
        $e = $this->escenario();
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'cero.json';
        $opciones = ['--aplicar' => true, '--pivotes' => implode(',', $e['cero']), '--respaldo' => $ruta];
        $this->assertSame(0, $this->correr($e['a'], $opciones)[0]);
        $datos = json_decode(file_get_contents($ruta), true);
        $datos['pivotes'][0]['debe_id'] = $e['ma'][1];
        file_put_contents($ruta, json_encode($datos));
        $despues_a = $this->foto($e['a']);
        list($codigo, $salida) = $this->correr($e['a'], ['--revertir' => true, '--aplicar' => true, '--respaldo' => $ruta]);
        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('alterado', $salida);
        list($codigo, $salida) = $this->correr($e['a'], $opciones);
        $this->assertSame(1, $codigo, $salida);
        $this->assertSame(0, DB::table('pagado_por')->whereIn('id', $e['cero'])->count());
        $this->assertEquals($despues_a, $this->foto($e['a']));
    }

    /** @test */
    public function ca8_huerfanos_y_provisorios_quedan_fuera_de_la_lista_y_del_borrado()
    {
        $e = $this->escenario();
        $huerfano = $this->pivote($e['ma'][1], 987654321, 0);
        $provisorio = CurrentAcount::create(['user_id' => $this->app['auth']->user()->id, 'moneda_id' => 1,
            'credit_account_id' => $e['a']->id, 'client_id' => $e['a']->model_id, 'is_provisorio' => 1,
            'pagandose' => 0, 'detalle' => 'Provisorio', 'haber' => 30, 'saldo' => 0,
            'status' => 'pago_from_client', 'created_at' => '2026-01-04 10:00:00', 'updated_at' => '2026-01-04 10:00:00']);
        $con_provisorio = $this->pivote($e['ma'][1], $provisorio->id, 0);
        list($codigo, $salida) = $this->correr($e['a']);
        $this->assertSame(0, $codigo, $salida);
        $datos = $this->salida_json($salida);
        $this->assertEqualsCanonicalizing($e['cero'], $this->ids_de($datos['cero']));
        $this->assertSame([], $datos['con_importe']);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'cero.json';
        list($codigo, $salida) = $this->correr($e['a'], ['--aplicar' => true,
            '--pivotes' => implode(',', $e['cero']), '--respaldo' => $ruta]);
        $this->assertSame(0, $codigo, $salida);
        $this->assertSame(2, DB::table('pagado_por')->whereIn('id', [$huerfano, $con_provisorio])->count());
        $this->assertSame(0, DB::table('pagado_por')->whereIn('id', $e['cero'])->count());
    }

    /** @test */
    public function ca9_una_falla_al_crear_el_respaldo_revierte_el_borrado()
    {
        $e = $this->escenario();
        $antes_a = $this->foto($e['a']);
        $antes_b = $this->foto($e['b']);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'ocupado';
        mkdir($ruta, 0700);
        list($codigo, $salida) = $this->correr($e['a'], ['--aplicar' => true,
            '--pivotes' => implode(',', $e['cero']), '--respaldo' => $ruta]);
        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('respaldo exclusivo', $salida);
        $this->assertSame(2, DB::table('pagado_por')->whereIn('id', $e['cero'])->count());
        $this->assertEquals($antes_a, $this->foto($e['a']));
        $this->assertEquals($antes_b, $this->foto($e['b']));
    }

    /** @test */
    public function una_cadena_de_saldos_descuadrada_en_cualquiera_de_las_cuentas_impide_aplicar()
    {
        $e = $this->escenario();
        DB::table('credit_accounts')->where('id', $e['b']->id)->update(['saldo' => 1]);
        $antes_a = $this->foto($e['a']);
        $antes_b = $this->foto($e['b']);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'no-debe-existir.json';
        list($codigo, $salida) = $this->correr($e['a'], ['--aplicar' => true,
            '--pivotes' => implode(',', $e['cero']), '--respaldo' => $ruta]);
        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('saldo', $salida);
        $this->assertFalse(file_exists($ruta));
        $this->assertSame(2, DB::table('pagado_por')->whereIn('id', $e['cero'])->count());
        $this->assertEquals($antes_a, $this->foto($e['a']));
        $this->assertEquals($antes_b, $this->foto($e['b']));
    }

    /** @test */
    public function tras_limpiar_una_pareja_la_otra_cuenta_ya_no_tiene_nada_que_borrar()
    {
        $e = $this->escenario();
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'cero.json';
        $this->assertSame(0, $this->correr($e['a'], ['--aplicar' => true,
            '--pivotes' => implode(',', $e['cero']), '--respaldo' => $ruta])[0]);
        list($codigo, $salida) = $this->correr($e['b']);
        $this->assertSame(0, $codigo, $salida);
        $datos = $this->salida_json($salida);
        $this->assertSame([], $datos['cero']);
        $this->assertSame([], $datos['con_importe']);
    }
}
