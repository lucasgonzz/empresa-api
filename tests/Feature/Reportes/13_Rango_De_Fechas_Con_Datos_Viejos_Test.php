<?php

namespace Tests\Feature\Reportes;

use App\Models\Client;
use App\Models\Provider;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión soporte-t177 (ticket de soporte 177, cliente "2r", 27/11/2026): buscar un rango de fechas en
 * Reportes tiraba "Server Error".
 *
 * 🔴 QUÉ PROTEGE.
 *
 * 1. Datos viejos o incompletos no pueden romper el reporte legado de Gráficos
 *    (`GET api/company-performance/{desde}/{hasta}`): un cobro o un pago a proveedor sin cuenta
 *    corriente (`credit_account_id` NULL) y un cobro sin métodos de pago cuyo `employee_id` no es un
 *    empleado del dueño daban 500 (`Trying to get property 'moneda_id' of non-object` y
 *    `Undefined offset`). CA1 a CA3.
 * 2. Fechas inválidas o rangos imposibles en los cinco endpoints del selector de rango
 *    (company-performance y api/reportes/{estado-resultados,posicion-fiscal,flujo-caja,detalle})
 *    responden 422 con un mensaje en castellano y `errors` (para que la SPA lo reconozca como
 *    validación), nunca 500. CA4.
 * 3. Tope de 366 días SOLO en Gráficos (company-performance); los reportes contables no tienen tope.
 *    CA5.
 * 4. Un empleado (`owner_id` = dueño) ve los mismos totales que el dueño. CA6.
 *
 * Septiembre de 2010 a propósito: `grep -rn "'2010-" tests/` no da ninguna fecha de 2010, así que los
 * deltas medidos no se mezclan con datos de otros tests.
 *
 * PHP 7.4 (nada de `?->`, `match` ni `str_contains`).
 *
 * @group reportes
 */
class Rango_De_Fechas_Con_Datos_Viejos_Test extends EmpresaTestCase
{
    /** Delta para comparar floats, mismo criterio que el resto de la carpeta. */
    const DELTA = 0.01;

    /** Primer día del período sembrado. */
    const DESDE = '2010-09-01';

    /** Último día del período sembrado. */
    const HASTA = '2010-09-30';

    /** Id del método de pago "Efectivo", donde cae un cobro sin métodos de pago. */
    const METODO_EFECTIVO = 3;

    /** @var \App\Models\User Dueño del fixture. */
    protected $dueno;

    /**
     * Guarda el dueño del fixture (EmpresaTestCase ya lo dejó autenticado).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Inserta un movimiento de cuenta corriente tipo "pago" directo en la tabla (es setup: lo que se
     * prueba es el reporte, no el flujo que crea el pago).
     *
     * @param array $atributos Columnas que pisan los valores por defecto.
     * @return int Id insertado.
     */
    protected function sembrar_pago($atributos)
    {
        return DB::table('current_acounts')->insertGetId(array_merge([
            'user_id'    => $this->dueno->id,
            'haber'      => 1000,
            'status'     => 'pago_from_client',
            'detalle'    => 'soporte-t177',
            'created_at' => '2010-09-10 10:00:00',
            'updated_at' => '2010-09-10 10:00:00',
        ], $atributos));
    }

    /**
     * Pide Gráficos del período sembrado y devuelve la respuesta.
     *
     * @param string $desde
     * @param string $hasta
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir_graficos($desde = self::DESDE, $hasta = self::HASTA)
    {
        return $this->getJson('api/company-performance/'.$desde.'/'.$hasta);
    }

    /**
     * Total acumulado en el método de pago dado dentro de `ingresos_cuenta_corriente`.
     *
     * @param array $modelo `model` de la respuesta de company-performance.
     * @param int   $metodo_id
     * @return float
     */
    protected function ingreso_cc_del_metodo($modelo, $metodo_id)
    {
        $total = 0.0;

        foreach ($modelo['ingresos_cuenta_corriente'] as $ingreso) {
            if ((int) $ingreso['id'] === (int) $metodo_id) {
                $total += (float) $ingreso['pivot']['amount'];
            }
        }

        return $total;
    }

    /**
     * Cambia el usuario autenticado (el guard sanctum cachea al primero: ver
     * `12_Articulos_Vendidos_Test::actuar_como`).
     *
     * @param \App\Models\User $user
     * @return void
     */
    protected function actuar_como($user)
    {
        Auth::forgetGuards();

        $this->actingAs($user, 'web');
    }

    /**
     * URLs de los cinco endpoints que reciben el rango del selector.
     *
     * @param string $desde
     * @param string $hasta
     * @return array Nombre del endpoint => URL.
     */
    protected function urls_del_rango($desde, $hasta)
    {
        return [
            'company-performance' => 'api/company-performance/'.$desde.'/'.$hasta,
            'estado-resultados'   => 'api/reportes/estado-resultados?'.http_build_query(['desde' => $desde, 'hasta' => $hasta, 'moneda' => 'pesos']),
            'posicion-fiscal'     => 'api/reportes/posicion-fiscal?'.http_build_query(['desde' => $desde, 'hasta' => $hasta]),
            'flujo-caja'          => 'api/reportes/flujo-caja?'.http_build_query(['desde' => $desde, 'hasta' => $hasta, 'moneda' => 'pesos']),
            'detalle'             => 'api/reportes/detalle?'.http_build_query(['concepto' => 'ventas_brutas', 'desde' => $desde, 'hasta' => $hasta]),
        ];
    }

    /**
     * CA1: un cobro de cliente sin cuenta corriente no rompe el rango y suma como pesos.
     *
     * @test
     */
    public function test_cobro_sin_cuenta_corriente_no_rompe_el_rango()
    {
        $base = $this->pedir_graficos();
        $base->assertStatus(201);

        $cliente = Client::where('user_id', $this->dueno->id)->first();
        $this->sembrar_pago(['client_id' => $cliente->id, 'credit_account_id' => null]);

        $respuesta = $this->pedir_graficos();
        $respuesta->assertStatus(201);

        $antes = $base->json('model');
        $despues = $respuesta->json('model');

        $this->assertEqualsWithDelta(
            (float) $antes['total_pagado_a_cuenta_corriente'] + 1000,
            (float) $despues['total_pagado_a_cuenta_corriente'],
            self::DELTA
        );
        $this->assertEqualsWithDelta(
            (float) $antes['total_pagado_a_cuenta_corriente_usd'],
            (float) $despues['total_pagado_a_cuenta_corriente_usd'],
            self::DELTA
        );
    }

    /**
     * CA2: un pago a proveedor sin cuenta corriente no rompe el rango y suma como pesos.
     *
     * @test
     */
    public function test_pago_a_proveedor_sin_cuenta_corriente_no_rompe_el_rango()
    {
        $base = $this->pedir_graficos();
        $base->assertStatus(201);

        $proveedor = Provider::where('user_id', $this->dueno->id)->first();
        $this->sembrar_pago(['provider_id' => $proveedor->id, 'credit_account_id' => null]);

        $respuesta = $this->pedir_graficos();
        $respuesta->assertStatus(201);

        $antes = $base->json('model');
        $despues = $respuesta->json('model');

        $this->assertEqualsWithDelta(
            (float) $antes['total_pagado_a_proveedores'] + 1000,
            (float) $despues['total_pagado_a_proveedores'],
            self::DELTA
        );
        $this->assertEqualsWithDelta(
            (float) $antes['total_pagado_a_proveedores_usd'],
            (float) $despues['total_pagado_a_proveedores_usd'],
            self::DELTA
        );
    }

    /**
     * Ids de empleado que no pertenecen al dueño: uno inexistente y el cero.
     *
     * @return array
     */
    public function vendedores_ajenos()
    {
        return [
            'empleado inexistente' => [999999],
            'empleado cero'        => [0],
        ];
    }

    /**
     * CA3: un cobro sin métodos de pago con `employee_id` que no es empleado del dueño no rompe el
     * rango, y su plata entra a Efectivo de `ingresos_cuenta_corriente`.
     *
     * @test
     * @dataProvider vendedores_ajenos
     */
    public function test_cobro_sin_metodos_con_vendedor_ajeno_no_rompe_el_rango($employee_id)
    {
        $base = $this->pedir_graficos();
        $base->assertStatus(201);

        $cliente = Client::where('user_id', $this->dueno->id)->first();
        $cuenta_id = DB::table('credit_accounts')
            ->where('model_name', 'client')
            ->where('model_id', $cliente->id)
            ->value('id');

        $this->sembrar_pago([
            'client_id'         => $cliente->id,
            'credit_account_id' => $cuenta_id,
            'employee_id'       => $employee_id,
        ]);

        $respuesta = $this->pedir_graficos();
        $respuesta->assertStatus(201);

        $this->assertEqualsWithDelta(
            $this->ingreso_cc_del_metodo($base->json('model'), self::METODO_EFECTIVO) + 1000,
            $this->ingreso_cc_del_metodo($respuesta->json('model'), self::METODO_EFECTIVO),
            self::DELTA
        );
    }

    /**
     * Fechas que no existen o no tienen el formato AAAA-MM-DD, incluidas las que el input de fecha
     * de Chrome deja pasar (año de cinco dígitos) y un año fuera de 2000-2100.
     *
     * @return array
     */
    public function fechas_invalidas()
    {
        return [
            'texto'              => ['abc'],
            'mes 13'             => ['2026-13-01'],
            'dia inexistente'    => ['2026-02-30'],
            'undefined del front' => ['undefined'],
            'año de 5 digitos'   => ['20261-11-27'],
            'antes del 2000'     => ['1999-12-31'],
        ];
    }

    /**
     * CA4: una fecha inválida en "Desde" o en "Hasta" devuelve 422 en castellano en los cinco
     * endpoints, con `errors` no vacío; nunca 500.
     *
     * @test
     * @dataProvider fechas_invalidas
     */
    public function test_fecha_invalida_devuelve_422_en_castellano($fecha)
    {
        foreach (['desde', 'hasta'] as $lado) {

            $desde = ($lado === 'desde') ? $fecha : '2010-09-01';
            $hasta = ($lado === 'hasta') ? $fecha : '2010-09-30';

            foreach ($this->urls_del_rango($desde, $hasta) as $endpoint => $url) {

                $respuesta = $this->getJson($url);

                $contexto = $endpoint.' con '.$lado.'='.$fecha;

                $this->assertSame(422, $respuesta->getStatusCode(), 'Status inesperado en '.$contexto);
                $this->assertStringContainsString('no es válida', (string) $respuesta->json('message'), $contexto);
                $this->assertNotEmpty($respuesta->json('errors'), 'errors vacío en '.$contexto);
            }
        }
    }

    /**
     * CA4: "Desde" posterior a "Hasta" devuelve 422 explicando qué cambiar, en los cinco endpoints.
     *
     * @test
     */
    public function test_desde_posterior_a_hasta_devuelve_422()
    {
        foreach ($this->urls_del_rango('2010-09-30', '2010-09-01') as $endpoint => $url) {

            $respuesta = $this->getJson($url);

            $this->assertSame(422, $respuesta->getStatusCode(), 'Status inesperado en '.$endpoint);
            $this->assertStringContainsString('posterior', (string) $respuesta->json('message'), $endpoint);
            $this->assertNotEmpty($respuesta->json('errors'), 'errors vacío en '.$endpoint);
        }
    }

    /**
     * CA5: Gráficos rechaza más de 366 días (de 2010-01-01 a 2011-01-02 son 367, inclusive).
     *
     * @test
     */
    public function test_rango_de_mas_de_12_meses_en_graficos_devuelve_422()
    {
        $respuesta = $this->pedir_graficos('2010-01-01', '2011-01-02');

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertStringContainsString('12 meses', (string) $respuesta->json('message'));
        $this->assertNotEmpty($respuesta->json('errors'));
    }

    /**
     * CA5: Gráficos acepta exactamente 366 días (de 2010-01-01 a 2011-01-01, inclusive).
     *
     * @test
     */
    public function test_rango_de_12_meses_en_graficos_anda()
    {
        $this->pedir_graficos('2010-01-01', '2011-01-01')->assertStatus(201);
    }

    /**
     * CA5: el Estado de Resultados no tiene tope: tres años responden 200.
     *
     * @test
     */
    public function test_estado_resultados_acepta_tres_anios()
    {
        $url = 'api/reportes/estado-resultados?'.http_build_query([
            'desde' => '2008-01-01', 'hasta' => '2010-12-31', 'moneda' => 'pesos',
        ]);

        $this->getJson($url)->assertStatus(200);
    }

    /**
     * CA6: un empleado (`owner_id` = dueño) pide los cuatro endpoints del período sembrado y ve los
     * mismos totales que el dueño.
     *
     * @test
     */
    public function test_empleado_ve_los_mismos_totales_que_el_duenio()
    {
        $cliente = Client::where('user_id', $this->dueno->id)->first();
        $this->sembrar_pago(['client_id' => $cliente->id, 'credit_account_id' => null]);

        $urls = $this->urls_del_rango(self::DESDE, self::HASTA);

        $del_duenio = [];
        foreach ($urls as $endpoint => $url) {
            $respuesta = $this->getJson($url);
            $this->assertContains($respuesta->getStatusCode(), [200, 201], 'Dueño en '.$endpoint);
            $del_duenio[$endpoint] = $respuesta->json();
        }

        $empleado = User::create([
            'name'     => 'Empleado soporte t177',
            'email'    => 'empleado-t177-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno->id,
        ]);

        $this->actuar_como($empleado);

        foreach ($urls as $endpoint => $url) {
            $respuesta = $this->getJson($url);
            $this->assertContains($respuesta->getStatusCode(), [200, 201], 'Empleado en '.$endpoint);

            if ($endpoint === 'company-performance') {
                $this->assertEqualsWithDelta(
                    (float) $del_duenio[$endpoint]['model']['total_pagado_a_cuenta_corriente'],
                    (float) $respuesta->json('model.total_pagado_a_cuenta_corriente'),
                    self::DELTA
                );
            } elseif ($endpoint === 'detalle') {
                $this->assertEqualsWithDelta((float) $del_duenio[$endpoint]['total'], (float) $respuesta->json('total'), self::DELTA);
            } else {
                $this->assertEquals($del_duenio[$endpoint], $respuesta->json(), 'Empleado distinto del dueño en '.$endpoint);
            }
        }
    }
}
