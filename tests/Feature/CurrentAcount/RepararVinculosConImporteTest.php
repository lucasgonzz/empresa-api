<?php

namespace Tests\Feature\CurrentAcount;

use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Provider;
use App\Models\Sale;
use App\Models\Seller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Modo `--vinculos-con-importe` de cuenta_corriente:reparar_creditos_historicos (misión 25/11/2026).
 *
 * Para UN par débito D (cuenta A) <-> haber H (cuenta B) cruzado entre cuentas, borra exactamente las filas de
 * `pagado_por` del par (con importe y en $0) y recalcula SÓLO el estado de pago de D a partir de sus imputaciones
 * propias que quedan. H no se toca y ningún saldo puede cambiar.
 *
 * Escenario base (calco de San Cayetano 427 <-> 3867): D debe 75.330 en la cuenta A; H es un pago de 50.000 en la
 * cuenta B (to_pay_id = D) ya aplicado a una deuda propia de B por 50.000 y, en exceso, a D por 50.000 + 25.330
 * + 3 filas en $0. La cuenta C es ajena. Cada test crea lo que usa.
 */
class RepararVinculosConImporteTest extends EmpresaTestCase
{
    private $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carpeta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cc-con-importe-'.bin2hex(random_bytes(8));
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
            ? Client::create(['name' => 'Prueba importe '.bin2hex(random_bytes(3)), 'user_id' => $usuario, 'saldo_pesos' => 0])
            : Provider::create(['name' => 'Prueba importe '.bin2hex(random_bytes(3)), 'user_id' => $usuario]);
        $cuenta = CreditAccount::create(['model_name' => $tipo, 'model_id' => $duenio->id,
            'moneda_id' => 1, 'user_id' => $usuario, 'saldo' => 0]);
        $saldo = 0;
        $ids = [];
        foreach ($filas as $fila) {
            list($debe, $haber, $fecha) = $fila;
            $saldo += (float) $debe - (float) $haber;
            $datos = ['user_id' => $usuario, 'moneda_id' => 1, 'credit_account_id' => $cuenta->id,
                'is_provisorio' => 0, 'pagandose' => 0, 'detalle' => 'Movimiento de prueba',
                'debe' => $debe, 'haber' => $haber, 'saldo' => round($saldo, 2),
                'status' => is_null($haber) ? 'sin_pagar' : 'pago_from_client',
                'created_at' => $fecha, 'updated_at' => $fecha];
            $datos[$tipo === 'client' ? 'client_id' : 'provider_id'] = $duenio->id;
            $ids[] = CurrentAcount::create($datos)->id;
        }
        $cuenta->update(['saldo' => round($saldo, 2)]);
        return [$cuenta->fresh(), $ids];
    }

    private function pivote(int $debe_id, int $haber_id, $pagado): int
    {
        return DB::table('pagado_por')->insertGetId(['debe_id' => $debe_id, 'haber_id' => $haber_id,
            'pagado' => $pagado, 'total_pago' => $pagado, 'a_cubrir' => 1000, 'fondos_iniciales' => 0,
            'nuevos_fondos' => 0, 'remantente' => 0, 'created_at' => '2026-06-01 10:00:01',
            'updated_at' => '2026-06-01 10:00:01']);
    }

    private function estado(int $id, string $status, $pagandose): void
    {
        DB::table('current_acounts')->where('id', $id)->update(['status' => $status, 'pagandose' => $pagandose]);
    }

    /**
     * Arma el escenario. Opciones (todas opcionales):
     *  - debe        importe de D (75330)
     *  - tipo_b      'client' o 'provider' para la cuenta de H
     *  - cruces      importes con dinero del par D<->H ([50000, 25330])
     *  - ceros       cantidad de filas del par en $0 (3)
     *  - propias_d   importes de haberes propios de la cuenta A que ya pagan D ([])
     *  - propias_h   importes de deudas propias de B que paga H ([50000]); su suma es el haber de H
     *  - haber_libre haber de A sin imputar (null)
     *
     * @return array a, b, c, d, h, d2, hc, pares (ids de pagado_por del par), cruce_c, propias_h, propias_d,
     *               ma, mb (ids de movimientos de A y B), debe
     */
    private function escenario(array $o = []): array
    {
        $o = array_merge(['debe' => 75330, 'tipo_b' => 'client', 'cruces' => [50000, 25330], 'ceros' => 3,
            'propias_d' => [], 'propias_h' => [50000], 'haber_libre' => null], $o);
        $haber_h = round(array_sum($o['propias_h']), 2);

        // Cuenta A: D, otra deuda D2 (con su cruce hacia C), haberes propios que pagan D y un haber libre opcional.
        $filas_a = [[$o['debe'], null, '2026-01-01 10:00:00'], [1000, null, '2026-01-02 10:00:00']];
        foreach ($o['propias_d'] as $indice => $importe) {
            $filas_a[] = [null, $importe, '2026-01-03 10:0'.$indice.':00'];
        }
        if ($o['haber_libre'] !== null) {
            $filas_a[] = [null, $o['haber_libre'], '2026-01-04 10:00:00'];
        }
        list($a, $ma) = $this->cuenta_con_movimientos('client', $filas_a);

        // Cuenta B: deudas propias y el pago H.
        $filas_b = [];
        foreach ($o['propias_h'] as $indice => $importe) {
            $filas_b[] = [$importe, null, '2026-01-01 11:0'.$indice.':00'];
        }
        $filas_b[] = [null, $haber_h, '2026-01-05 11:00:00'];
        list($b, $mb) = $this->cuenta_con_movimientos($o['tipo_b'], $filas_b);

        // Cuenta C, ajena: una deuda y un pago que también cruza hacia D2 de A.
        list($c, $mc) = $this->cuenta_con_movimientos('client', [[500, null, '2026-01-01 12:00:00'], [null, 100, '2026-01-02 12:00:00']]);

        $d = $ma[0];
        $d2 = $ma[1];
        $h = end($mb);
        $hc = $mc[1];
        $this->estado($d, 'pagado', $o['debe']);
        $this->estado($d2, 'pagandose', 40);
        DB::table('current_acounts')->where('id', $h)->update(['to_pay_id' => $d]);

        $par = [];
        foreach ($o['cruces'] as $importe) {
            $par[] = $this->pivote($d, $h, $importe);
        }
        for ($i = 0; $i < $o['ceros']; $i++) {
            $par[] = $this->pivote($d, $h, 0);
        }
        $propias_h = [];
        foreach ($o['propias_h'] as $indice => $importe) {
            $this->estado($mb[$indice], 'pagado', $importe);
            $propias_h[] = $this->pivote($mb[$indice], $h, $importe);
        }
        $propias_d = [];
        foreach ($o['propias_d'] as $indice => $importe) {
            $propias_d[] = $this->pivote($d, $ma[2 + $indice], $importe);
        }
        $cruce_c = $this->pivote($d2, $hc, 40);
        $this->pivote($mc[0], $hc, 60);
        return ['a' => $a, 'b' => $b, 'c' => $c, 'd' => $d, 'h' => $h, 'd2' => $d2, 'hc' => $hc, 'par' => $par,
            'cruce_c' => $cruce_c, 'propias_h' => $propias_h, 'propias_d' => $propias_d, 'ma' => $ma, 'mb' => $mb,
            'debe' => $o['debe']];
    }

    /** Foto completa de una cuenta: cuenta, dueño, todos los movimientos e imputaciones con alguna punta en ella. */
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

    /**
     * La foto esperada de la cuenta de D tras aplicar: sin las filas del par y con D en el estado indicado.
     * Todo lo demás (otras columnas de D, incluido updated_at, y los demás movimientos) queda idéntico.
     */
    private function foto_esperada(array $foto, array $ids_par, int $d_id, $status, $pagandose): array
    {
        $foto['imputaciones'] = array_values(array_filter($foto['imputaciones'], function ($fila) use ($ids_par) {
            return !in_array((int) $fila->id, $ids_par, true);
        }));
        foreach ($foto['movimientos'] as $movimiento) {
            if ((int) $movimiento->id === $d_id) {
                $movimiento->status = $status;
                $movimiento->pagandose = number_format((float) $pagandose, 2, '.', '');
            }
        }
        return $foto;
    }

    /** Foto de una cuenta sin las filas del par (para H, la otra cuenta): sólo cambia pagado_por. */
    private function foto_sin_par(array $foto, array $ids_par): array
    {
        $foto['imputaciones'] = array_values(array_filter($foto['imputaciones'], function ($fila) use ($ids_par) {
            return !in_array((int) $fila->id, $ids_par, true);
        }));
        return $foto;
    }

    /** Llama al comando en el modo nuevo con el par del escenario; `$opciones` pisa o (con null) quita opciones. */
    private function correr(array $e, array $opciones = []): array
    {
        $base = ['--cuenta' => $e['a']->id, '--vinculos-con-importe' => true, '--debe' => $e['d'], '--haber' => $e['h']];
        $todas = array_filter(array_merge($base, $opciones), function ($valor) { return $valor !== null; });
        $codigo = Artisan::call('cuenta_corriente:reparar_creditos_historicos', $todas);
        return [$codigo, Artisan::output()];
    }

    /** Opciones de una aplicación completa con los ids del par y el respaldo indicado. */
    private function aplicar_en(array $e, string $ruta, array $ids = null): array
    {
        return ['--aplicar' => true, '--pivotes' => implode(',', $ids === null ? $e['par'] : $ids), '--respaldo' => $ruta];
    }

    private function salida_json(string $salida): array
    {
        foreach (explode("\n", trim($salida)) as $linea) {
            $datos = json_decode($linea, true);
            if (is_array($datos) && isset($datos['pivotes_suma'])) {
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

    private function ordenados(array $ids): array
    {
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

    /** Corre y devuelve [codigo, salida, escrituras]. */
    private function correr_contando(array $e, array $opciones = []): array
    {
        $resultado = null;
        $escrituras = $this->contar_escrituras(function () use ($e, $opciones, &$resultado) {
            $resultado = $this->correr($e, $opciones);
        });
        return [$resultado[0], $resultado[1], $escrituras];
    }

    /** Pares (id, saldo) de todos los movimientos de las cuentas, más el saldo de cada cuenta y de su dueño. */
    private function saldos(array $cuentas): array
    {
        $saldos = [];
        foreach ($cuentas as $cuenta) {
            $tabla = $cuenta->model_name === 'client' ? 'clients' : 'providers';
            $saldos[] = [
                DB::table('current_acounts')->where('credit_account_id', $cuenta->id)->orderBy('id')->pluck('saldo', 'id')->all(),
                DB::table('credit_accounts')->where('id', $cuenta->id)->value('saldo'),
                (array) DB::table($tabla)->where('id', $cuenta->model_id)->first(),
            ];
        }
        return $saldos;
    }

    /** Comisiones, vínculos de comisión y puntos ligados a la venta de D y al cliente de la cuenta A. */
    private function dependencias(array $e, $venta_id): array
    {
        $comisiones = DB::table('seller_commissions')->where('sale_id', $venta_id)->orderBy('id')->get();
        return ['comisiones' => $comisiones->toArray(),
            'vinculos' => DB::table('current_acount_seller_commission')->whereIn('seller_commission_id', $comisiones->pluck('id'))->orderBy('id')->get()->toArray(),
            'puntos' => DB::table('movimiento_puntos')->where('client_id', $e['a']->model_id)->orderBy('id')->get()->toArray()];
    }

    /** @test */
    public function ca1_simular_informa_el_par_y_el_estado_nuevo_sin_escribir_consultas_ni_archivos()
    {
        $e = $this->escenario(['haber_libre' => 250]);
        $antes_a = $this->foto($e['a']);
        $antes_b = $this->foto($e['b']);
        $antes_c = $this->foto($e['c']);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'no-creado.json';
        list($codigo, $salida, $escrituras) = $this->correr_contando($e, ['--respaldo' => $ruta]);
        $this->assertSame(0, $codigo, $salida);
        $datos = $this->salida_json($salida);
        $this->assertSame((int) $e['a']->id, (int) $datos['cuenta_id']);
        $this->assertSame('simulacion', $datos['modo']);
        $this->assertEqualsCanonicalizing($e['par'], $this->ids_de($datos['pivotes']));
        $this->assertEquals(75330, $datos['pivotes_suma']);
        $this->assertSame((int) $e['d'], (int) $datos['debe']['id']);
        $this->assertSame((int) $e['a']->id, (int) $datos['debe']['cuenta']);
        $this->assertEquals(75330, $datos['debe']['debe']);
        $this->assertSame('pagado', $datos['debe']['status']);
        $this->assertSame('sin_pagar', $datos['debe']['status_nuevo']);
        $this->assertEquals(0, $datos['debe']['pagandose_nuevo']);
        $this->assertSame([], $datos['debe']['propias']);
        $this->assertSame((int) $e['h'], (int) $datos['haber']['id']);
        $this->assertSame((int) $e['b']->id, (int) $datos['haber']['cuenta']);
        $this->assertEquals(50000, $datos['haber']['haber']);
        $this->assertEquals(50000, $datos['haber']['propias_suma']);
        $this->assertSame((int) $e['d'], (int) $datos['haber']['to_pay_id']);
        $this->assertTrue($datos['cadena_ok']);
        // El cruce de otra deuda de A hacia la cuenta C no es del par: se informa y no se toca.
        $this->assertSame([$e['cruce_c']], $this->ids_de($datos['otros_cruces']));
        // Haber propio de A que ninguna imputación propia consume (informativo).
        $this->assertEquals(250, $datos['haber_libre_cuenta_debe']);
        $this->assertSame([], $escrituras);
        $this->assertFalse(file_exists($ruta));
        $this->assertEquals($antes_a, $this->foto($e['a']));
        $this->assertEquals($antes_b, $this->foto($e['b']));
        $this->assertEquals($antes_c, $this->foto($e['c']));
    }

    /** @test */
    public function ca2_aplicar_borra_solo_el_cruce_recalcula_solo_el_estado_de_d_y_no_mueve_ningun_saldo()
    {
        $e = $this->escenario();
        $antes_a = $this->foto($e['a']);
        $antes_b = $this->foto($e['b']);
        $saldos_antes = $this->saldos([$e['a'], $e['b']]);
        $fila_h = (array) DB::table('current_acounts')->where('id', $e['h'])->first();
        $propia_h = DB::table('pagado_por')->whereIn('id', $e['propias_h'])->orderBy('id')->get()->toArray();
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'par.json';
        list($codigo, $salida) = $this->correr($e, $this->aplicar_en($e, $ruta));
        $this->assertSame(0, $codigo, $salida);
        $this->assertSame(0, DB::table('pagado_por')->whereIn('id', $e['par'])->count());
        $this->assertSame(1, DB::table('pagado_por')->where('id', $e['cruce_c'])->count());
        $this->assertEquals($fila_h, (array) DB::table('current_acounts')->where('id', $e['h'])->first());
        $this->assertEquals($propia_h, DB::table('pagado_por')->whereIn('id', $e['propias_h'])->orderBy('id')->get()->toArray());
        $d = DB::table('current_acounts')->where('id', $e['d'])->first();
        $this->assertSame('sin_pagar', $d->status);
        $this->assertEquals(0, $d->pagandose);
        $this->assertEquals($this->foto_esperada($antes_a, $e['par'], $e['d'], 'sin_pagar', 0), $this->foto($e['a']));
        $this->assertEquals($this->foto_sin_par($antes_b, $e['par']), $this->foto($e['b']));
        $this->assertEquals($saldos_antes, $this->saldos([$e['a'], $e['b']]));
        // El respaldo guarda el par completo, las dos fotos de D y las fotos de las dos cuentas.
        $respaldo = json_decode(file_get_contents($ruta), true);
        $this->assertSame('vinculos-con-importe-v1', $respaldo['formato']);
        $this->assertSame((int) $e['a']->id, $respaldo['cuenta_id']);
        $this->assertSame((int) $e['d'], (int) $respaldo['debe_id']);
        $this->assertSame((int) $e['h'], (int) $respaldo['haber_id']);
        $this->assertCount(5, $respaldo['pivotes']);
        $this->assertSame('pagado', $respaldo['debe_antes']['status']);
        $this->assertSame('sin_pagar', $respaldo['debe_despues']['status']);
        $this->assertCount(2, $respaldo['antes']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $respaldo['sha256']);
        $this->assertSame(0600, fileperms($ruta) & 0777);
        // Tras la corrección, el modo hermano ya no ve nada del par: ni en cero ni con importe (en B).
        foreach ([$e['a'], $e['b']] as $cuenta) {
            $this->assertSame(0, Artisan::call('cuenta_corriente:reparar_creditos_historicos',
                ['--cuenta' => $cuenta->id, '--vinculos-en-cero' => true]), Artisan::output());
            $json = null;
            foreach (explode("\n", trim(Artisan::output())) as $linea) {
                $decodificado = json_decode($linea, true);
                if (is_array($decodificado) && isset($decodificado['cero'])) {
                    $json = $decodificado;
                }
            }
            $this->assertNotNull($json);
            $this->assertSame([], $json['cero']);
            // La única fila con importe que queda en A es el cruce hacia C, ajeno al par; en B no queda ninguna.
            $esperado = $cuenta->id === $e['a']->id ? [$e['cruce_c']] : [];
            $this->assertSame($esperado, $this->ids_de($json['con_importe']));
        }
    }

    /** @test */
    public function ca3_si_d_tiene_pagos_propios_queda_pagandose_por_el_importe_propio()
    {
        // Calco de CF 2910 <-> 2946: debe 106.430,35; propia 12.743,40; cruce 93.686,95 y una fila en $0.
        $e = $this->escenario(['debe' => 106430.35, 'cruces' => [93686.95], 'ceros' => 1,
            'propias_d' => [12743.40], 'propias_h' => [500000]]);
        $antes_a = $this->foto($e['a']);
        $antes_b = $this->foto($e['b']);
        $simulacion = $this->salida_json($this->correr($e)[1]);
        $this->assertSame('pagandose', $simulacion['debe']['status_nuevo']);
        $this->assertEquals(12743.40, $simulacion['debe']['pagandose_nuevo']);
        $this->assertEquals(12743.40, array_sum(array_column($simulacion['debe']['propias'], 'pagado')));
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'cf.json';
        list($codigo, $salida) = $this->correr($e, $this->aplicar_en($e, $ruta));
        $this->assertSame(0, $codigo, $salida);
        $this->assertSame(1, DB::table('pagado_por')->whereIn('id', $e['propias_d'])->count());
        $this->assertEquals($this->foto_esperada($antes_a, $e['par'], $e['d'], 'pagandose', 12743.40), $this->foto($e['a']));
        $this->assertEquals($this->foto_sin_par($antes_b, $e['par']), $this->foto($e['b']));
    }

    /** @test */
    public function ca3_si_las_propias_cubren_todo_el_debe_queda_pagado_por_el_debe()
    {
        $e = $this->escenario(['debe' => 1000, 'cruces' => [500], 'ceros' => 1, 'propias_d' => [1000], 'propias_h' => [500]]);
        $antes_a = $this->foto($e['a']);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'cubierto.json';
        list($codigo, $salida) = $this->correr($e, $this->aplicar_en($e, $ruta));
        $this->assertSame(0, $codigo, $salida);
        $this->assertEquals($this->foto_esperada($antes_a, $e['par'], $e['d'], 'pagado', 1000), $this->foto($e['a']));
    }

    /** @test */
    public function ca4_un_debito_negativo_nota_de_credito_queda_pagado_en_cero_sin_pasar_por_el_modo_de_creditos()
    {
        // Calco de 725 <-> 505: D debe -2.400 con un cruce de -2.400 y filas en $0; H cubierto por dos propias.
        $e = $this->escenario(['debe' => -2400, 'cruces' => [-2400], 'ceros' => 3, 'propias_h' => [189253.67, 34060]]);
        $antes_a = $this->foto($e['a']);
        $antes_b = $this->foto($e['b']);
        $fila_h = (array) DB::table('current_acounts')->where('id', $e['h'])->first();
        $simulacion = $this->salida_json($this->correr($e)[1]);
        $this->assertEquals(-2400, $simulacion['pivotes_suma']);
        $this->assertSame('pagado', $simulacion['debe']['status_nuevo']);
        $this->assertEquals(0, $simulacion['debe']['pagandose_nuevo']);
        $this->assertEquals(223313.67, $simulacion['haber']['propias_suma']);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'credito.json';
        list($codigo, $salida) = $this->correr($e, $this->aplicar_en($e, $ruta));
        $this->assertSame(0, $codigo, $salida);
        $this->assertEquals($this->foto_esperada($antes_a, $e['par'], $e['d'], 'pagado', 0), $this->foto($e['a']));
        $this->assertEquals($this->foto_sin_par($antes_b, $e['par']), $this->foto($e['b']));
        $this->assertEquals($fila_h, (array) DB::table('current_acounts')->where('id', $e['h'])->first());
    }

    /** @test */
    public function ca5_cliente_con_proveedor_aplica_bloqueando_primero_al_cliente()
    {
        $e = $this->escenario(['tipo_b' => 'provider']);
        $antes_a = $this->foto($e['a']);
        $antes_b = $this->foto($e['b']);
        $candados = [];
        DB::listen(function ($consulta) use (&$candados) {
            if (preg_match('/for update/i', $consulta->sql)
                && preg_match('/from `(clients|providers)`/i', $consulta->sql, $m)) {
                $candados[] = $m[1];
            }
        });
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'proveedor.json';
        list($codigo, $salida) = $this->correr($e, $this->aplicar_en($e, $ruta));
        $this->assertSame(0, $codigo, $salida);
        $this->assertSame(0, DB::table('pagado_por')->whereIn('id', $e['par'])->count());
        $this->assertEquals($this->foto_esperada($antes_a, $e['par'], $e['d'], 'sin_pagar', 0), $this->foto($e['a']));
        $this->assertEquals($this->foto_sin_par($antes_b, $e['par']), $this->foto($e['b']));
        // Orden canónico: el primer candado de dueño es el del cliente y ninguno de cliente va después de uno de proveedor.
        $this->assertNotEmpty($candados);
        $this->assertSame('clients', $candados[0]);
        $primer_proveedor = array_search('providers', $candados, true);
        $this->assertNotFalse($primer_proveedor, 'Debe bloquear también al dueño proveedor.');
        $this->assertNotContains('clients', array_slice($candados, $primer_proveedor));
    }

    /** @test */
    public function ca6_los_ids_deben_coincidir_exactamente_con_las_filas_del_par()
    {
        $e = $this->escenario();
        $antes_a = $this->foto($e['a']);
        $antes_b = $this->foto($e['b']);
        $casos = [
            'de menos' => array_slice($e['par'], 1),
            'de más (propia de H)' => array_merge($e['par'], [$e['propias_h'][0]]),
            'de más (cruce hacia C)' => array_merge($e['par'], [$e['cruce_c']]),
            'ajeno inexistente' => array_merge($e['par'], [987654321]),
            'reemplazo por un id ajeno' => [$e['par'][0], $e['par'][1], $e['par'][2], $e['par'][3], 987654321],
        ];
        foreach ($casos as $nombre => $ids) {
            $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'no-debe-existir.json';
            list($codigo, $salida, $escrituras) = $this->correr_contando($e, $this->aplicar_en($e, $ruta, $ids));
            $this->assertSame(1, $codigo, $nombre.': '.$salida);
            $this->assertStringContainsString('no coincide', $salida, $nombre);
            $this->assertSame([], $escrituras, $nombre);
            $this->assertFalse(file_exists($ruta), $nombre);
            $this->assertEquals($antes_a, $this->foto($e['a']), $nombre);
            $this->assertEquals($antes_b, $this->foto($e['b']), $nombre);
        }
    }

    /** @test */
    public function ca6_pares_que_no_son_los_pedidos_o_cuenta_equivocada_se_rechazan_sin_escribir()
    {
        $e = $this->escenario();
        $antes_a = $this->foto($e['a']);
        $antes_b = $this->foto($e['b']);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'no-debe-existir.json';
        $casos = [
            // D2 y H no forman un par: no hay filas entre ellos.
            'debe y haber que no forman par' => [['--debe' => $e['d2']], 'sin filas'],
            // La cuenta pedida no es la de D.
            'cuenta distinta de la de D' => [['--cuenta' => $e['b']->id], 'cuenta'],
            'debe inexistente' => [['--debe' => 987654321], 'existe'],
            'haber inexistente' => [['--haber' => 987654321], 'existe'],
            // Se invierten los roles: D no es un débito (es un haber) y H no es un haber con importe.
            'roles invertidos' => [['--debe' => $e['h'], '--haber' => $e['d'], '--cuenta' => $e['b']->id], 'débito'],
            'sin --debe' => [['--debe' => null], '--debe'],
            'sin --haber' => [['--haber' => null], '--haber'],
        ];
        foreach ($casos as $nombre => $caso) {
            list($codigo, $salida, $escrituras) = $this->correr_contando($e, array_merge($caso[0],
                ['--aplicar' => true, '--pivotes' => implode(',', $e['par']), '--respaldo' => $ruta]));
            $this->assertSame(1, $codigo, $nombre.': '.$salida);
            $this->assertStringContainsString($caso[1], $salida, $nombre);
            $this->assertSame([], $escrituras, $nombre);
            $this->assertFalse(file_exists($ruta), $nombre);
        }
        $this->assertEquals($antes_a, $this->foto($e['a']));
        $this->assertEquals($antes_b, $this->foto($e['b']));
    }

    /** @test */
    public function ca6_aplicar_sin_ids_o_sin_respaldo_o_con_ids_invalidos_se_rechaza_sin_escribir()
    {
        $e = $this->escenario();
        $antes_a = $this->foto($e['a']);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'no-debe-existir.json';
        list($codigo, $salida) = $this->correr($e, ['--aplicar' => true, '--respaldo' => $ruta]);
        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('--pivotes', $salida);
        list($codigo, $salida) = $this->correr($e, ['--aplicar' => true, '--pivotes' => implode(',', $e['par'])]);
        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('--respaldo', $salida);
        list($codigo, $salida) = $this->correr($e, ['--aplicar' => true, '--pivotes' => 'abc,1', '--respaldo' => $ruta]);
        $this->assertSame(1, $codigo, $salida);
        $this->assertFalse(file_exists($ruta));
        $this->assertEquals($antes_a, $this->foto($e['a']));
    }

    /** @test */
    public function ca7_d_y_h_de_la_misma_cuenta_no_son_un_cruce()
    {
        $e = $this->escenario(['propias_d' => [100]]);
        $antes_a = $this->foto($e['a']);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'no-debe-existir.json';
        // H' (haber propio de A que ya paga a D) y D viven en la misma cuenta.
        $haber_propio = $e['ma'][2];
        list($codigo, $salida, $escrituras) = $this->correr_contando($e, ['--haber' => $haber_propio,
            '--aplicar' => true, '--pivotes' => implode(',', $e['propias_d']), '--respaldo' => $ruta]);
        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('no es un cruce entre cuentas', $salida);
        $this->assertSame([], $escrituras);
        $this->assertFalse(file_exists($ruta));
        $this->assertEquals($antes_a, $this->foto($e['a']));
    }

    /** @test */
    public function ca7_un_par_sin_filas_se_rechaza_y_tras_aplicar_ya_no_hay_cruce()
    {
        $e = $this->escenario();
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'par.json';
        $this->assertSame(0, $this->correr($e, $this->aplicar_en($e, $ruta))[0]);
        $despues_a = $this->foto($e['a']);
        list($codigo, $salida, $escrituras) = $this->correr_contando($e);
        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('sin filas', $salida);
        $this->assertSame([], $escrituras);
        $this->assertEquals($despues_a, $this->foto($e['a']));
    }

    /** @test */
    public function ca7_un_par_con_todas_las_filas_en_cero_remite_al_modo_en_cero()
    {
        $e = $this->escenario(['cruces' => [], 'ceros' => 3]);
        $antes_a = $this->foto($e['a']);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'no-debe-existir.json';
        list($codigo, $salida, $escrituras) = $this->correr_contando($e, $this->aplicar_en($e, $ruta));
        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('--vinculos-en-cero', $salida);
        $this->assertSame([], $escrituras);
        $this->assertFalse(file_exists($ruta));
        $this->assertEquals($antes_a, $this->foto($e['a']));
    }

    /** @test */
    public function ca8_h_que_no_queda_cubierto_por_sus_propias_imputaciones_requiere_decision()
    {
        // H vale 50.000 pero sus imputaciones propias suman 40.000: el cruce no es sólo consumo en exceso.
        $e = $this->escenario();
        DB::table('pagado_por')->where('id', $e['propias_h'][0])->update(['pagado' => 40000, 'total_pago' => 40000]);
        $antes_a = $this->foto($e['a']);
        $antes_b = $this->foto($e['b']);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'no-debe-existir.json';
        list($codigo, $salida, $escrituras) = $this->correr_contando($e, $this->aplicar_en($e, $ruta));
        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('H no quedaría cubierto', $salida);
        $this->assertSame([], $escrituras);
        $this->assertFalse(file_exists($ruta));
        $this->assertEquals($antes_a, $this->foto($e['a']));
        $this->assertEquals($antes_b, $this->foto($e['b']));
    }

    /** @test */
    public function ca8_d_con_otras_imputaciones_no_propias_requiere_decision()
    {
        $casos = [
            'cruce de D hacia la cuenta C' => function (array $e) { return $this->pivote($e['d'], $e['hc'], 10); },
            'imputación huérfana de D' => function (array $e) { return $this->pivote($e['d'], 987654321, 10); },
        ];
        foreach ($casos as $nombre => $crear) {
            $e = $this->escenario();
            $crear($e);
            $antes_a = $this->foto($e['a']);
            $antes_b = $this->foto($e['b']);
            $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'no-debe-existir.json';
            list($codigo, $salida, $escrituras) = $this->correr_contando($e, $this->aplicar_en($e, $ruta));
            $this->assertSame(1, $codigo, $nombre.': '.$salida);
            $this->assertStringContainsString('otras imputaciones no propias', $salida, $nombre);
            $this->assertSame([], $escrituras, $nombre);
            $this->assertFalse(file_exists($ruta), $nombre);
            $this->assertEquals($antes_a, $this->foto($e['a']), $nombre);
            $this->assertEquals($antes_b, $this->foto($e['b']), $nombre);
        }
    }

    /** @test */
    public function ca8_si_las_propias_de_d_superan_su_importe_requiere_decision()
    {
        $e = $this->escenario(['propias_d' => [80000]]);
        $antes_a = $this->foto($e['a']);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'no-debe-existir.json';
        list($codigo, $salida, $escrituras) = $this->correr_contando($e, $this->aplicar_en($e, $ruta));
        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('superan', $salida);
        $this->assertSame([], $escrituras);
        $this->assertFalse(file_exists($ruta));
        $this->assertEquals($antes_a, $this->foto($e['a']));
    }

    /** @test */
    public function ca9_una_cadena_de_saldos_descuadrada_en_cualquiera_de_las_dos_cuentas_impide_aplicar()
    {
        foreach (['a', 'b'] as $cual) {
            $e = $this->escenario();
            DB::table('credit_accounts')->where('id', $e[$cual]->id)->update(['saldo' => 1]);
            $antes_a = $this->foto($e['a']);
            $antes_b = $this->foto($e['b']);
            // La simulación lo informa sin fallar.
            $simulacion = $this->correr($e);
            $this->assertSame(0, $simulacion[0], $simulacion[1]);
            $this->assertFalse($this->salida_json($simulacion[1])['cadena_ok'], $cual);
            $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'no-debe-existir.json';
            list($codigo, $salida, $escrituras) = $this->correr_contando($e, $this->aplicar_en($e, $ruta));
            $this->assertSame(1, $codigo, $cual.': '.$salida);
            $this->assertStringContainsString('saldo', $salida, $cual);
            $this->assertSame([], $escrituras, $cual);
            $this->assertFalse(file_exists($ruta), $cual);
            $this->assertSame(5, DB::table('pagado_por')->whereIn('id', $e['par'])->count(), $cual);
            $this->assertEquals($antes_a, $this->foto($e['a']), $cual);
            $this->assertEquals($antes_b, $this->foto($e['b']), $cual);
        }
    }

    /** @test */
    public function ca10_comisiones_y_puntos_de_la_venta_de_d_quedan_intactos()
    {
        $e = $this->escenario();
        $usuario = $this->app['auth']->user()->id;
        $venta = Sale::create(['client_id' => $e['a']->model_id, 'user_id' => $usuario,
            'moneda_id' => 1, 'total' => 75330, 'terminada' => 1]);
        DB::table('current_acounts')->where('id', $e['d'])->update(['sale_id' => $venta->id]);
        $vendedor = Seller::create(['name' => 'Vendedor importe', 'user_id' => $usuario,
            'num' => (int) Seller::where('user_id', $usuario)->max('num') + 1]);
        $comision = DB::table('seller_commissions')->insertGetId(['sale_id' => $venta->id,
            'seller_id' => $vendedor->id, 'user_id' => $usuario, 'debe' => 100, 'status' => 'inactive']);
        DB::table('current_acount_seller_commission')->insert(['current_acount_id' => $e['d'], 'seller_commission_id' => $comision]);
        DB::table('movimiento_puntos')->insert(['client_id' => $e['a']->model_id, 'user_id' => $usuario,
            'sale_id' => $venta->id, 'tipo' => 'ajuste', 'puntos' => 10, 'detalle' => 'Puntos de la venta']);
        $antes = $this->dependencias($e, $venta->id);
        $this->assertCount(1, $antes['comisiones']);
        $this->assertCount(1, $antes['puntos']);
        $simulacion = $this->salida_json($this->correr($e)[1]);
        $this->assertSame(1, (int) $simulacion['dependencias']['comisiones']);
        $this->assertSame(1, (int) $simulacion['dependencias']['puntos']);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'dependencias.json';
        list($codigo, $salida) = $this->correr($e, $this->aplicar_en($e, $ruta));
        $this->assertSame(0, $codigo, $salida);
        $this->assertSame(0, DB::table('pagado_por')->whereIn('id', $e['par'])->count());
        $this->assertEquals($antes, $this->dependencias($e, $venta->id));
    }

    /** @test */
    public function ca11_la_cuenta_ajena_y_el_cruce_de_a_hacia_ella_quedan_identicos()
    {
        $e = $this->escenario();
        $antes_c = $this->foto($e['c']);
        $cruce_c = (array) DB::table('pagado_por')->where('id', $e['cruce_c'])->first();
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'par.json';
        list($codigo, $salida) = $this->correr($e, $this->aplicar_en($e, $ruta));
        $this->assertSame(0, $codigo, $salida);
        $this->assertEquals($antes_c, $this->foto($e['c']));
        $this->assertEquals($cruce_c, (array) DB::table('pagado_por')->where('id', $e['cruce_c'])->first());
        $d2 = DB::table('current_acounts')->where('id', $e['d2'])->first();
        $this->assertSame('pagandose', $d2->status);
        $this->assertEquals(40, $d2->pagandose);
    }

    /** @test */
    public function ca12_repetir_con_el_mismo_respaldo_es_idempotente_y_con_otro_se_rechaza()
    {
        $e = $this->escenario();
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'par.json';
        $opciones = $this->aplicar_en($e, $ruta);
        $this->assertSame(0, $this->correr($e, $opciones)[0]);
        $contenido = file_get_contents($ruta);
        $despues_a = $this->foto($e['a']);
        $despues_b = $this->foto($e['b']);

        list($codigo, $salida, $escrituras) = $this->correr_contando($e, $opciones);
        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('ya está aplicada', $salida);
        $this->assertSame([], $escrituras);
        $this->assertSame($contenido, file_get_contents($ruta));
        $this->assertEquals($despues_a, $this->foto($e['a']));

        $otra = $this->carpeta.DIRECTORY_SEPARATOR.'otro.json';
        list($codigo, $salida, $escrituras) = $this->correr_contando($e, $this->aplicar_en($e, $otra));
        $this->assertSame(1, $codigo, $salida);
        $this->assertSame([], $escrituras);
        $this->assertFalse(file_exists($otra));
        $this->assertEquals($despues_a, $this->foto($e['a']));
        $this->assertEquals($despues_b, $this->foto($e['b']));
    }

    /** @test */
    public function ca13_revertir_devuelve_las_filas_con_sus_ids_y_el_estado_de_d()
    {
        $e = $this->escenario();
        $originales = DB::table('pagado_por')->whereIn('id', $e['par'])->orderBy('id')->get()->toArray();
        $antes_a = $this->foto($e['a']);
        $antes_b = $this->foto($e['b']);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'par.json';
        $this->assertSame(0, $this->correr($e, $this->aplicar_en($e, $ruta))[0]);
        $contenido = file_get_contents($ruta);
        $despues_a = $this->foto($e['a']);

        // Reversión simulada: no escribe.
        list($codigo, $salida, $escrituras) = $this->correr_contando($e, ['--revertir' => true, '--respaldo' => $ruta]);
        $this->assertSame(0, $codigo, $salida);
        $this->assertSame([], $escrituras);
        $this->assertEquals($despues_a, $this->foto($e['a']));

        // Reversión aplicada: vuelven las cinco filas con los mismos ids y valores; D vuelve a pagado/75330.
        list($codigo, $salida) = $this->correr($e, ['--revertir' => true, '--aplicar' => true, '--respaldo' => $ruta]);
        $this->assertSame(0, $codigo, $salida);
        $this->assertEquals($originales, DB::table('pagado_por')->whereIn('id', $e['par'])->orderBy('id')->get()->toArray());
        $d = DB::table('current_acounts')->where('id', $e['d'])->first();
        $this->assertSame('pagado', $d->status);
        $this->assertEquals(75330, $d->pagandose);
        $this->assertEquals($antes_a, $this->foto($e['a']));
        $this->assertEquals($antes_b, $this->foto($e['b']));
        $this->assertSame($contenido, file_get_contents($ruta));

        // Revertir otra vez: ya coincide con el estado anterior.
        list($codigo, $salida) = $this->correr($e, ['--revertir' => true, '--aplicar' => true, '--respaldo' => $ruta]);
        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('ya coinciden', $salida);
        $this->assertEquals($antes_a, $this->foto($e['a']));
    }

    /** @test */
    public function ca13_un_movimiento_posterior_en_la_otra_cuenta_impide_revertir()
    {
        $e = $this->escenario();
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'par.json';
        $this->assertSame(0, $this->correr($e, $this->aplicar_en($e, $ruta))[0]);
        CurrentAcount::create(['user_id' => $this->app['auth']->user()->id, 'moneda_id' => 1,
            'credit_account_id' => $e['b']->id, 'client_id' => $e['b']->model_id, 'is_provisorio' => 0,
            'pagandose' => 0, 'detalle' => 'Actividad posterior', 'debe' => 5, 'saldo' => 5,
            'status' => 'sin_pagar', 'created_at' => '2026-08-01 10:00:00', 'updated_at' => '2026-08-01 10:00:00']);
        $despues_a = $this->foto($e['a']);
        $despues_b = $this->foto($e['b']);
        list($codigo, $salida, $escrituras) = $this->correr_contando($e, ['--revertir' => true, '--aplicar' => true, '--respaldo' => $ruta]);
        $this->assertSame(1, $codigo, $salida);
        $this->assertSame([], $escrituras);
        $this->assertSame(0, DB::table('pagado_por')->whereIn('id', $e['par'])->count());
        $this->assertEquals($despues_a, $this->foto($e['a']));
        $this->assertEquals($despues_b, $this->foto($e['b']));
    }

    /** @test */
    public function ca13_un_respaldo_alterado_no_habilita_ni_repetir_ni_revertir()
    {
        $e = $this->escenario();
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'par.json';
        $opciones = $this->aplicar_en($e, $ruta);
        $this->assertSame(0, $this->correr($e, $opciones)[0]);
        $datos = json_decode(file_get_contents($ruta), true);
        $datos['pivotes'][0]['pagado'] = '1.00';
        file_put_contents($ruta, json_encode($datos));
        $despues_a = $this->foto($e['a']);
        list($codigo, $salida) = $this->correr($e, ['--revertir' => true, '--aplicar' => true, '--respaldo' => $ruta]);
        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('alterado', $salida);
        list($codigo, $salida) = $this->correr($e, $opciones);
        $this->assertSame(1, $codigo, $salida);
        $this->assertSame(0, DB::table('pagado_por')->whereIn('id', $e['par'])->count());
        $this->assertEquals($despues_a, $this->foto($e['a']));
    }

    /** @test */
    public function ca14_una_falla_al_crear_el_respaldo_revierte_el_borrado_y_el_estado_de_d()
    {
        $e = $this->escenario();
        $antes_a = $this->foto($e['a']);
        $antes_b = $this->foto($e['b']);
        $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'ocupado';
        mkdir($ruta, 0700);
        list($codigo, $salida) = $this->correr($e, $this->aplicar_en($e, $ruta));
        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('respaldo exclusivo', $salida);
        $this->assertSame(5, DB::table('pagado_por')->whereIn('id', $e['par'])->count());
        $d = DB::table('current_acounts')->where('id', $e['d'])->first();
        $this->assertSame('pagado', $d->status);
        $this->assertEquals(75330, $d->pagandose);
        $this->assertEquals($antes_a, $this->foto($e['a']));
        $this->assertEquals($antes_b, $this->foto($e['b']));
    }

    /** @test */
    public function ca15_no_se_combina_con_el_modo_en_cero_y_debe_haber_exigen_el_modo_nuevo()
    {
        $e = $this->escenario();
        $antes_a = $this->foto($e['a']);
        list($codigo, $salida, $escrituras) = $this->correr_contando($e, ['--vinculos-en-cero' => true]);
        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('distintos', $salida);
        $this->assertSame([], $escrituras);
        // --debe/--haber sin el modo nuevo: error claro, sin caer en ningún otro modo.
        $codigo = Artisan::call('cuenta_corriente:reparar_creditos_historicos',
            ['--cuenta' => $e['a']->id, '--debe' => $e['d'], '--haber' => $e['h']]);
        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('--vinculos-con-importe', Artisan::output());
        $this->assertEquals($antes_a, $this->foto($e['a']));
    }
}
