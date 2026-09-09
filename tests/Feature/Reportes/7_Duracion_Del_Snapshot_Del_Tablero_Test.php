<?php

namespace Tests\Feature\Reportes;

use App\Http\Controllers\CompanyPerformanceController;
use App\Models\CompanyPerformance;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Misión actualizar-sin-el-vps (9/9/2026) — cuánto vive el snapshot del tablero del día.
 *
 * `CompanyPerformanceController::check_tiempo_ultima_creada()` borra y recalcula el tablero
 * entero (`PerformanceHelper::create_company_performance`, >10 s en Fenix) si el snapshot de
 * hoy tiene más de N minutos. N era `env('DURACION_REPORTES', 1)`: un minuto, y con config
 * cacheada null — o sea, en cada entrada. Ahora es `config('app.duracion_reportes')`, con
 * default 10.
 *
 * 🔴 El `.env.testing` de los slots trae `DURACION_REPORTES=0`, así que `config()` a secas no
 * sirve para probar el default: el test del default evalúa `config/app.php` con la variable
 * AUSENTE de las tres fuentes que lee `env()` (`$_SERVER`, `$_ENV`, `getenv`) y las restaura
 * al terminar. Los tests del controlador fijan la config a mano, que es lo que se prueba.
 *
 * @group reportes
 */
class Duracion_Del_Snapshot_Del_Tablero_Test extends EmpresaTestCase
{
    /** @var \App\Models\User El dueño del fixture, que es el usuario autenticado por EmpresaTestCase. */
    protected $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Evalúa `config/app.php` con DURACION_REPORTES en el valor dado (null = ausente) y devuelve
     * lo que quedaría en `app.duracion_reportes`. Restaura el entorno pase lo que pase.
     *
     * @param  string|null  $valor
     * @return mixed
     */
    protected function duracion_reportes_con_la_variable($valor)
    {
        $respaldo = [
            'server' => array_key_exists('DURACION_REPORTES', $_SERVER) ? $_SERVER['DURACION_REPORTES'] : null,
            'env'    => array_key_exists('DURACION_REPORTES', $_ENV) ? $_ENV['DURACION_REPORTES'] : null,
            'getenv' => getenv('DURACION_REPORTES'),
        ];

        try {
            if (is_null($valor)) {
                unset($_SERVER['DURACION_REPORTES'], $_ENV['DURACION_REPORTES']);
                putenv('DURACION_REPORTES');
            } else {
                $_SERVER['DURACION_REPORTES'] = $valor;
                $_ENV['DURACION_REPORTES'] = $valor;
                putenv('DURACION_REPORTES=' . $valor);
            }

            $config = require base_path('config/app.php');

            return $config['duracion_reportes'];
        } finally {
            if (is_null($respaldo['server'])) {
                unset($_SERVER['DURACION_REPORTES']);
            } else {
                $_SERVER['DURACION_REPORTES'] = $respaldo['server'];
            }

            if (is_null($respaldo['env'])) {
                unset($_ENV['DURACION_REPORTES']);
            } else {
                $_ENV['DURACION_REPORTES'] = $respaldo['env'];
            }

            if ($respaldo['getenv'] === false) {
                putenv('DURACION_REPORTES');
            } else {
                putenv('DURACION_REPORTES=' . $respaldo['getenv']);
            }
        }
    }

    /**
     * Deja al dueño con UN solo snapshot de hoy, creado hace `$minutos` minutos, y lo devuelve.
     *
     * @param  int  $minutos
     * @return \App\Models\CompanyPerformance
     */
    protected function snapshot_de_hoy_creado_hace($minutos)
    {
        CompanyPerformance::where('user_id', $this->owner->id)->where('from_today', 1)->delete();

        // Sin microsegundos: la columna los descarta y la comparación de fechas sería falsa.
        $creado_at = Carbon::now()->subMinutes($minutos)->startOfSecond();

        return CompanyPerformance::create([
            'user_id'    => $this->owner->id,
            'from_today' => 1,
            'day'        => (int) Carbon::now()->format('d'),
            'created_at' => $creado_at,
            'updated_at' => $creado_at,
        ]);
    }

    /**
     * Los snapshots de hoy del dueño, ordenados por id.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function snapshots_de_hoy()
    {
        return CompanyPerformance::where('user_id', $this->owner->id)
            ->where('from_today', 1)
            ->orderBy('id')
            ->get();
    }

    /**
     * Sin la variable en el entorno, el default es 10 minutos (float).
     *
     * @return void
     */
    public function test_el_default_es_diez_minutos()
    {
        $this->assertSame(10.0, $this->duracion_reportes_con_la_variable(null));
    }

    /**
     * La variable sigue mandando cuando está, y como float: las demos usan 0.5.
     *
     * @return void
     */
    public function test_la_variable_del_entorno_sigue_mandando_y_se_lee_como_float()
    {
        $this->assertSame(3.0, $this->duracion_reportes_con_la_variable('3'));
        $this->assertSame(0.5, $this->duracion_reportes_con_la_variable('0.5'));
    }

    /**
     * Y la config que ve la aplicación es numérica (en el slot vale lo que diga su .env.testing).
     *
     * @return void
     */
    public function test_la_config_de_la_aplicacion_es_numerica()
    {
        $this->assertIsFloat(config('app.duracion_reportes'));
    }

    /**
     * Con un snapshot de hace 5 minutos y el umbral en 10, el controlador NO lo toca: ni lo borra
     * ni crea otro. Es el ahorro concreto: en un cliente grande, >10 s menos por entrada.
     *
     * @return void
     */
    public function test_no_recrea_el_snapshot_si_tiene_menos_de_diez_minutos()
    {
        config(['app.duracion_reportes' => 10.0]);

        $existente = $this->snapshot_de_hoy_creado_hace(5);

        (new CompanyPerformanceController())->check_tiempo_ultima_creada();

        $snapshots = $this->snapshots_de_hoy();

        $this->assertCount(1, $snapshots, 'No puede haber creado un segundo snapshot de hoy.');
        $this->assertSame($existente->id, $snapshots->first()->id, 'El snapshot de hace 5 minutos tiene que seguir siendo el mismo.');
    }

    /**
     * Con un snapshot de hace 11 minutos y el umbral en 10, sí lo borra y lo recalcula: el gate no
     * es un no-op, y el tablero nunca queda más viejo que el umbral.
     *
     * @return void
     */
    public function test_recrea_el_snapshot_si_tiene_mas_de_diez_minutos()
    {
        config(['app.duracion_reportes' => 10.0]);

        $viejo = $this->snapshot_de_hoy_creado_hace(11);

        (new CompanyPerformanceController())->check_tiempo_ultima_creada();

        $snapshots = $this->snapshots_de_hoy();

        $this->assertCount(1, $snapshots, 'Tiene que quedar exactamente un snapshot de hoy.');
        $this->assertNotSame($viejo->id, $snapshots->first()->id, 'El snapshot viejo tenía que ser reemplazado.');
        $this->assertNull(CompanyPerformance::find($viejo->id), 'El snapshot viejo tenía que borrarse.');
        $this->assertTrue(
            $snapshots->first()->created_at->gte(Carbon::now()->subMinute()),
            'El snapshot nuevo tiene que ser de ahora.'
        );
    }

    /**
     * El umbral que manda es el de la config, no un número fijo: con el umbral en 20, el snapshot
     * de hace 11 minutos se conserva.
     *
     * @return void
     */
    public function test_el_umbral_es_el_de_la_config()
    {
        config(['app.duracion_reportes' => 20.0]);

        $existente = $this->snapshot_de_hoy_creado_hace(11);

        (new CompanyPerformanceController())->check_tiempo_ultima_creada();

        $snapshots = $this->snapshots_de_hoy();

        $this->assertCount(1, $snapshots);
        $this->assertSame($existente->id, $snapshots->first()->id, 'Con el umbral en 20 el snapshot de hace 11 minutos se conserva.');
    }
}
