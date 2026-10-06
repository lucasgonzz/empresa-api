<?php

namespace Tests\Feature\CuentaCorriente;

use App\Http\Controllers\Helpers\currentAcount\CuentaCorrientePeriodoHelper;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión cuenta-corriente-periodo (1/10/2026) — el listado de la cuenta corriente por período.
 *
 * `GET api/current-acount/{credit_account_id}/{cantidad}` suma tres query params opcionales
 * (`desde`, `hasta`, `minimo`) y, solo cuando `desde` es un Y-m-d válido, una clave `periodo` en la
 * respuesta. Sin `desde` válido el comportamiento es el de siempre (los últimos N) y la respuesta NO
 * lleva `periodo`: es lo que permite que una SPA vieja y una API nueva (o al revés) convivan.
 *
 * Se prueba por el endpoint real, con movimientos sembrados a mano (sin recalcular saldos: acá no
 * importa la cadena sino QUÉ filas salen y en qué orden).
 *
 * @group cuenta-corriente
 */
class Periodo_del_listado_Test extends EmpresaTestCase
{
    use ArmaCadenas;

    /** @var \App\Models\CreditAccount */
    protected $cuenta;

    protected function setUp(): void
    {
        parent::setUp();

        list($cliente, $this->cuenta) = $this->cliente_con_cuenta($this->app['auth']->user()->id, 'Período');

        $this->ultimas_arriba(false);
    }

    protected function tearDown(): void
    {
        // Algún test baja el tope del listado; no puede quedar bajo para los que corren después.
        CuentaCorrientePeriodoHelper::$limite_listado = CuentaCorrientePeriodoHelper::LIMITE_LISTADO;

        parent::tearDown();
    }

    /**
     * El listado lee `cc_ultimas_arriba` del usuario con un User::find() fresco, así que se escribe
     * en la base (la transacción del test lo revierte).
     *
     * @param  bool  $valor
     * @return void
     */
    protected function ultimas_arriba($valor)
    {
        User::where('id', $this->app['auth']->user()->id)->update(['cc_ultimas_arriba' => $valor ? 1 : 0]);
    }

    /**
     * @param  string  $fecha_hora  'Y-m-d H:i:s'
     * @return \App\Models\CurrentAcount
     */
    protected function mov($fecha_hora, $detalle = null)
    {
        return $this->movimiento($this->cuenta, [
            'debe'       => 100,
            'detalle'    => $detalle,
            'created_at' => $fecha_hora,
        ]);
    }

    /**
     * @param  string  $query  ej. 'desde=2026-09-01&hasta=2026-09-30'
     * @param  int     $cantidad
     * @return \Illuminate\Testing\TestResponse
     */
    protected function listar($query = '', $cantidad = 10)
    {
        return $this->getJson('api/current-acount/'.$this->cuenta->id.'/'.$cantidad.($query ? '?'.$query : ''));
    }

    /**
     * @param  \Illuminate\Testing\TestResponse  $respuesta
     * @return array<int,int>  Los ids de `models`, en el orden de la respuesta.
     */
    protected function ids($respuesta)
    {
        return array_map(function ($m) {
            return $m['id'];
        }, $respuesta->json('models'));
    }

    /**
     * @test
     */
    public function sin_parametros_son_los_ultimos_N_y_la_respuesta_no_lleva_periodo()
    {
        $creados = [];
        for ($dia = 1; $dia <= 12; $dia++) {
            $creados[] = $this->mov('2026-09-'.str_pad($dia, 2, '0', STR_PAD_LEFT).' 10:00:00')->id;
        }

        $respuesta = $this->listar();

        $respuesta->assertStatus(200);
        $this->assertArrayNotHasKey('periodo', $respuesta->json());

        // Los 10 más recientes (días 3 a 12), de más viejo a más nuevo (cc_ultimas_arriba = false).
        $this->assertSame(array_slice($creados, 2), $this->ids($respuesta));
    }

    /**
     * @test
     */
    public function un_rango_con_movimientos_devuelve_solo_los_del_rango_y_el_periodo_efectivo()
    {
        $this->mov('2026-08-31 23:59:59');
        $a = $this->mov('2026-09-01 00:00:00')->id;
        $b = $this->mov('2026-09-15 12:00:00')->id;
        $c = $this->mov('2026-09-30 23:59:59')->id;
        $this->mov('2026-10-01 00:00:00');

        $respuesta = $this->listar('desde=2026-09-01&hasta=2026-09-30');

        $respuesta->assertStatus(200);
        $this->assertSame([$a, $b, $c], $this->ids($respuesta));
        $this->assertSame([
            'desde'     => '2026-09-01',
            'hasta'     => '2026-09-30',
            'ampliado'  => false,
            'cantidad'  => 3,
            'truncado'  => false,
        ], $respuesta->json('periodo'));
    }

    /**
     * @test
     */
    public function el_rango_ignora_la_cantidad_de_la_ruta()
    {
        for ($hora = 1; $hora <= 15; $hora++) {
            $this->mov('2026-09-10 '.str_pad($hora, 2, '0', STR_PAD_LEFT).':00:00');
        }

        // La ruta dice 10, pero con período se muestra el período completo.
        $respuesta = $this->listar('desde=2026-09-01&hasta=2026-09-30', 10);

        $this->assertCount(15, $respuesta->json('models'));
        $this->assertSame(15, $respuesta->json('periodo.cantidad'));
    }

    /**
     * @test
     */
    public function un_rango_vacio_devuelve_models_vacio_sin_ampliar()
    {
        $this->mov('2026-09-10 10:00:00');

        $respuesta = $this->listar('desde=2026-07-01&hasta=2026-07-31');

        $respuesta->assertStatus(200);
        $this->assertSame([], $respuesta->json('models'));
        $this->assertSame([
            'desde'     => '2026-07-01',
            'hasta'     => '2026-07-31',
            'ampliado'  => false,
            'cantidad'  => 0,
            'truncado'  => false,
        ], $respuesta->json('periodo'));
    }

    /**
     * @test
     */
    public function el_hasta_es_inclusivo_del_dia_completo()
    {
        $tarde = $this->mov('2026-09-30 23:30:00')->id;
        $this->mov('2026-10-01 00:00:01');

        $respuesta = $this->listar('desde=2026-09-30&hasta=2026-09-30');

        $this->assertSame([$tarde], $this->ids($respuesta));
    }

    /**
     * @test
     */
    public function sin_hasta_no_hay_tope_superior_y_periodo_hasta_es_null()
    {
        $viejo = $this->mov('2026-09-10 10:00:00')->id;
        $nuevo = $this->mov('2030-01-01 10:00:00')->id;

        $respuesta = $this->listar('desde=2026-09-01');

        $this->assertSame([$viejo, $nuevo], $this->ids($respuesta));
        $this->assertNull($respuesta->json('periodo.hasta'));
        $this->assertSame('2026-09-01', $respuesta->json('periodo.desde'));
    }

    /**
     * @test
     */
    public function con_minimo_y_un_rango_escaso_se_amplia_hacia_atras_hasta_el_minimo()
    {
        // 3 en septiembre y 8 en agosto, cada uno en su día (1 a 8).
        for ($dia = 1; $dia <= 8; $dia++) {
            $this->mov('2026-08-0'.$dia.' 10:00:00');
        }
        for ($dia = 10; $dia <= 12; $dia++) {
            $this->mov('2026-09-'.$dia.' 10:00:00');
        }

        $respuesta = $this->listar('desde=2026-09-01&hasta=2026-09-30&minimo=10');

        // Faltan 7: del más nuevo al más viejo de agosto son los días 8,7,6,5,4,3,2 → desde el 2/8.
        $this->assertCount(10, $respuesta->json('models'));
        $this->assertTrue($respuesta->json('periodo.ampliado'));
        $this->assertSame('2026-08-02', $respuesta->json('periodo.desde'));
        $this->assertSame('2026-09-30', $respuesta->json('periodo.hasta'));
        $this->assertSame(10, $respuesta->json('periodo.cantidad'));
    }

    /**
     * El movimiento que completa el mínimo comparte día con otros: entran todos, no se parte el día.
     *
     * @test
     */
    public function la_ampliacion_trae_el_dia_completo_del_movimiento_del_borde()
    {
        $this->mov('2026-08-10 09:00:00');
        $fuera = $this->mov('2026-08-19 23:00:00');

        // El día del borde: cuatro movimientos.
        $del_borde = [
            $this->mov('2026-08-20 06:00:00')->id,
            $this->mov('2026-08-20 08:00:00')->id,
            $this->mov('2026-08-20 12:00:00')->id,
            $this->mov('2026-08-20 18:00:00')->id,
        ];

        $s1 = $this->mov('2026-09-05 10:00:00')->id;
        $s2 = $this->mov('2026-09-06 10:00:00')->id;

        // minimo 5: los 2 de septiembre + los 3 más nuevos del 20/8 (18:00, 12:00, 08:00).
        // El mínimo se cumple a mitad del 20/8, y el día se trae completo (incluye el de las 06:00).
        $respuesta = $this->listar('desde=2026-09-01&hasta=2026-09-30&minimo=5');

        $this->assertSame('2026-08-20', $respuesta->json('periodo.desde'));
        $this->assertTrue($respuesta->json('periodo.ampliado'));
        $this->assertSame(array_merge($del_borde, [$s1, $s2]), $this->ids($respuesta));
        $this->assertNotContains($fuera->id, $this->ids($respuesta));
        $this->assertSame(6, $respuesta->json('periodo.cantidad'));
    }

    /**
     * @test
     */
    public function si_la_cuenta_entera_tiene_menos_del_minimo_devuelve_todos()
    {
        $a = $this->mov('2026-07-01 10:00:00')->id;
        $b = $this->mov('2026-08-15 10:00:00')->id;
        $c = $this->mov('2026-09-10 10:00:00')->id;

        $respuesta = $this->listar('desde=2026-09-01&hasta=2026-09-30&minimo=10');

        $this->assertSame([$a, $b, $c], $this->ids($respuesta));
        $this->assertTrue($respuesta->json('periodo.ampliado'));
        $this->assertSame('2026-07-01', $respuesta->json('periodo.desde'));
    }

    /**
     * @test
     */
    public function si_todo_cabe_en_el_rango_aunque_falte_el_minimo_no_hay_ampliacion()
    {
        $a = $this->mov('2026-09-10 10:00:00')->id;
        $b = $this->mov('2026-09-11 10:00:00')->id;

        // El período arranca antes que el primer movimiento: no hay nada más atrás que traer.
        $respuesta = $this->listar('desde=2026-09-01&hasta=2026-09-30&minimo=10');

        $this->assertSame([$a, $b], $this->ids($respuesta));
        $this->assertFalse($respuesta->json('periodo.ampliado'));
        $this->assertSame('2026-09-01', $respuesta->json('periodo.desde'));
    }

    /**
     * @test
     */
    public function la_ampliacion_respeta_el_hasta_y_una_cuenta_sin_movimientos_no_se_amplia()
    {
        $dentro = $this->mov('2026-09-10 10:00:00')->id;
        $this->mov('2026-10-20 10:00:00');
        $this->mov('2026-10-21 10:00:00');

        $respuesta = $this->listar('desde=2026-09-01&hasta=2026-09-30&minimo=10');

        $this->assertSame([$dentro], $this->ids($respuesta));
        $this->assertFalse($respuesta->json('periodo.ampliado'));

        // Cuenta sin un solo movimiento hasta `hasta`.
        $respuesta = $this->listar('desde=2026-01-01&hasta=2026-01-31&minimo=10');

        $this->assertSame([], $this->ids($respuesta));
        $this->assertFalse($respuesta->json('periodo.ampliado'));
        $this->assertSame('2026-01-01', $respuesta->json('periodo.desde'));
    }

    /**
     * Sin `minimo`, un período elegido a mano se muestra tal cual aunque tenga pocos movimientos.
     *
     * @test
     */
    public function sin_minimo_no_se_amplia_nunca()
    {
        for ($dia = 1; $dia <= 9; $dia++) {
            $this->mov('2026-08-0'.$dia.' 10:00:00');
        }
        $unico = $this->mov('2026-09-10 10:00:00')->id;

        $respuesta = $this->listar('desde=2026-09-01&hasta=2026-09-30');

        $this->assertSame([$unico], $this->ids($respuesta));
        $this->assertFalse($respuesta->json('periodo.ampliado'));
    }

    /**
     * @test
     */
    public function los_parametros_invalidos_se_tratan_como_ausentes()
    {
        $creados = [];
        for ($dia = 1; $dia <= 12; $dia++) {
            $creados[] = $this->mov('2026-09-'.str_pad($dia, 2, '0', STR_PAD_LEFT).' 10:00:00')->id;
        }

        // `desde` inválido => comportamiento de siempre: los últimos 10 y sin `periodo`.
        foreach (['desde=abc', 'desde=2026-13-45', 'desde=2026-02-30', 'desde=01/09/2026', 'desde=2026-9-1', 'desde=', 'desde[]=2026-09-01', 'hasta=2026-09-30&minimo=10'] as $query) {

            $respuesta = $this->listar($query);

            $respuesta->assertStatus(200);
            $this->assertArrayNotHasKey('periodo', $respuesta->json(), $query);
            $this->assertSame(array_slice($creados, 2), $this->ids($respuesta), $query);
        }

        // `hasta` inválido con `desde` válido: sin tope superior.
        $respuesta = $this->listar('desde=2026-09-01&hasta=cualquier-cosa');

        $this->assertNull($respuesta->json('periodo.hasta'));
        $this->assertCount(12, $respuesta->json('models'));

        // `minimo` inválido (0, negativo, texto): no amplía.
        $this->mov('2026-07-01 10:00:00');

        foreach (['minimo=0', 'minimo=-3', 'minimo=muchos'] as $minimo) {

            $respuesta = $this->listar('desde=2026-09-01&hasta=2026-09-30&'.$minimo);

            $this->assertFalse($respuesta->json('periodo.ampliado'), $minimo);
            $this->assertCount(12, $respuesta->json('models'), $minimo);
        }
    }

    /**
     * @test
     */
    public function el_orden_respeta_cc_ultimas_arriba_y_desempata_por_id()
    {
        $a = $this->mov('2026-09-10 10:00:00')->id;
        $b = $this->mov('2026-09-10 10:00:00')->id; // mismo instante: desempata el id
        $c = $this->mov('2026-09-11 10:00:00')->id;

        $this->ultimas_arriba(false);
        $this->assertSame([$a, $b, $c], $this->ids($this->listar('desde=2026-09-01')));

        $this->ultimas_arriba(true);
        $this->assertSame([$c, $b, $a], $this->ids($this->listar('desde=2026-09-01')));
    }

    /**
     * El período se arma con límites de datetime sobre `created_at`, no con whereDate()/DATE(): con
     * DATE() la consulta deja de usar el índice cc_cuenta_orden_idx.
     *
     * @test
     */
    public function la_consulta_no_envuelve_created_at_en_funciones()
    {
        $this->mov('2026-09-10 10:00:00');

        DB::enableQueryLog();

        $this->listar('desde=2026-09-01&hasta=2026-09-30&minimo=5')->assertStatus(200);

        $sqls = array_map(function ($q) {
            return strtolower($q['query']);
        }, DB::getQueryLog());

        DB::disableQueryLog();

        $de_cuenta = array_filter($sqls, function ($sql) {
            return strpos($sql, 'from `current_acounts`') !== false;
        });

        $this->assertNotEmpty($de_cuenta);

        foreach ($de_cuenta as $sql) {
            $this->assertStringNotContainsString('date(', $sql);
        }
    }

    /**
     * 🔴 El verde falso que detectó un verificador: si la ampliación ignorara `hasta`, contaría los
     * movimientos POSTERIORES para completar el mínimo y el período efectivo quedaría más cerca de
     * `desde` de lo que corresponde. Acá hay 5 posteriores a `hasta`: contándolos, el `desde`
     * efectivo sería el 7/8 en vez del 2/8.
     *
     * @test
     */
    public function la_ampliacion_no_cuenta_los_movimientos_posteriores_al_hasta()
    {
        for ($dia = 1; $dia <= 8; $dia++) {
            $this->mov('2026-08-0'.$dia.' 10:00:00');
        }
        for ($dia = 10; $dia <= 12; $dia++) {
            $this->mov('2026-09-'.$dia.' 10:00:00');
        }
        for ($dia = 5; $dia <= 9; $dia++) {
            $this->mov('2026-10-0'.$dia.' 10:00:00');
        }

        $respuesta = $this->listar('desde=2026-09-01&hasta=2026-09-30&minimo=10');

        // Los 10 más recientes con created_at <= 30/9: 3 de septiembre + agosto 8,7,6,5,4,3,2.
        $this->assertSame('2026-08-02', $respuesta->json('periodo.desde'));
        $this->assertTrue($respuesta->json('periodo.ampliado'));
        $this->assertCount(10, $respuesta->json('models'));

        foreach ($respuesta->json('models') as $modelo) {
            $this->assertLessThan('2026-10-01', substr($modelo['created_at'], 0, 10));
        }
    }

    /**
     * La carga por defecto nueva de la SPA no manda `hasta`: un movimiento con fecha futura (hay
     * cuentas con fechas cargadas a mano) aparece y cuenta para el mínimo.
     *
     * @test
     */
    public function sin_hasta_un_movimiento_futuro_aparece_y_cuenta_para_el_minimo()
    {
        $this->mov('2026-08-05 10:00:00');
        $this->mov('2026-08-06 10:00:00');
        $this->mov('2026-09-10 10:00:00');
        $futuro = $this->mov('2030-01-01 10:00:00')->id;

        $respuesta = $this->listar('desde=2026-09-01&minimo=3');

        // En el rango hay 2 (el de septiembre y el futuro): falta 1, y es el del 6/8, no el del 5/8.
        $this->assertSame('2026-08-06', $respuesta->json('periodo.desde'));
        $this->assertCount(3, $respuesta->json('models'));
        $this->assertContains($futuro, $this->ids($respuesta));
    }

    /**
     * @test
     */
    public function si_hay_mas_filas_que_el_tope_devuelve_las_mas_recientes_y_avisa_truncado()
    {
        CuentaCorrientePeriodoHelper::$limite_listado = 5;

        $creados = [];
        for ($dia = 1; $dia <= 8; $dia++) {
            $creados[] = $this->mov('2026-09-0'.$dia.' 10:00:00')->id;
        }

        $respuesta = $this->listar('desde=2026-09-01');

        $respuesta->assertStatus(200);
        // Las 5 más recientes (días 4 a 8), de más viejo a más nuevo.
        $this->assertSame(array_slice($creados, 3), $this->ids($respuesta));
        $this->assertTrue($respuesta->json('periodo.truncado'));
        $this->assertSame(5, $respuesta->json('periodo.cantidad'));

        // Con cc_ultimas_arriba el recorte es el mismo, solo cambia el orden.
        $this->ultimas_arriba(true);
        $this->assertSame(array_reverse(array_slice($creados, 3)), $this->ids($this->listar('desde=2026-09-01')));
    }

    /**
     * @test
     */
    public function con_exactamente_el_tope_o_menos_no_hay_truncado()
    {
        CuentaCorrientePeriodoHelper::$limite_listado = 5;

        for ($dia = 1; $dia <= 5; $dia++) {
            $this->mov('2026-09-0'.$dia.' 10:00:00');
        }

        $respuesta = $this->listar('desde=2026-09-01');

        $this->assertCount(5, $respuesta->json('models'));
        $this->assertFalse($respuesta->json('periodo.truncado'));

        $this->assertFalse($this->listar('desde=2026-09-04')->json('periodo.truncado'));
        $this->assertFalse($this->listar('desde=2027-01-01')->json('periodo.truncado'));
    }

    /**
     * El tope vigente por defecto es el de la constante y el helper sin límite (el PDF) no recorta.
     *
     * @test
     */
    public function el_tope_por_defecto_es_2000_y_el_helper_sin_limite_no_recorta()
    {
        $this->assertSame(2000, CuentaCorrientePeriodoHelper::LIMITE_LISTADO);
        $this->assertSame(2000, CuentaCorrientePeriodoHelper::$limite_listado);

        for ($dia = 1; $dia <= 8; $dia++) {
            $this->mov('2026-09-0'.$dia.' 10:00:00');
        }

        $resultado = CuentaCorrientePeriodoHelper::consultar($this->cuenta->id, '2026-09-01');

        $this->assertCount(8, $resultado['models']);
        $this->assertFalse($resultado['periodo']['truncado']);
    }
}
