<?php

namespace Tests\Feature\Demo;

use App\Http\Controllers\Helpers\DemoSetupLockHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * POST /api/admin-sync/demo-setup con el candado ya tomado → 409 y la base intacta.
 *
 * Lo que gobierna acá es la segunda mitad de esa frase. El 409 es la señal para admin-api;
 * lo que arregla el bug es que la corrida rebotada NO llegue al `migrate:fresh` de
 * DemoSetupHelper::run(), que es lo que el 25/8/2026 le vació la base a la corrida que ya
 * estaba sembrando (`SQLSTATE[42S02]` adentro de `semilla:datos`).
 *
 * 🔴 NINGÚN test de esta clase llama a DemoSetupHelper::run(). Arranca con
 * `Artisan::call('migrate:fresh')` y vaciaría empresa_testing_s1 en medio de la suite,
 * dejando en rojo todo lo que corra después. El camino feliz no se cubre acá a propósito:
 * lo único que se puede verificar sin correr el setup es que el candado quede libre, y eso
 * se prueba derecho contra el helper.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot está sembrada de
 * antes y un refresh la vaciaría, que es justo el accidente que este test vigila.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class CandadoDeDemoSetupTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Handle del candado que toma el test para simular "ya hay una corrida". Se suelta en
     * tearDown pase lo que pase: un candado que queda tomado por un test traba los que siguen.
     *
     * @var resource|false
     */
    protected $candado = false;

    protected function tearDown(): void
    {
        DemoSetupLockHelper::soltar($this->candado);
        $this->candado = false;

        parent::tearDown();
    }

    /** @test */
    public function con_el_candado_tomado_el_endpoint_responde_409_y_no_toca_la_base()
    {
        $this->candado = DemoSetupLockHelper::tomar();

        $this->assertNotFalse(
            $this->candado,
            'El candado tenía que estar libre al empezar el test. Si acá da false, quedó tomado por otra corrida.'
        );

        /**
         * La prueba de que la base no se tocó: `migrate:fresh` dropea TODAS las tablas, así
         * que alcanza con contar cuántas hay antes y después. Es más honesto que contar filas
         * de una tabla puntual —un wipe podría dejarla vacía y recreada— y no depende de que
         * la base esté sembrada de una forma en particular.
         */
        $baseActiva = DB::connection()->getDatabaseName();
        $tablasAntes = $this->cantidadDeTablas($baseActiva);

        $this->assertGreaterThan(0, $tablasAntes, 'La base de testing tiene que tener tablas antes de empezar.');

        $respuesta = $this->postJson('/api/admin-sync/demo-setup', [
            'business_type' => 'kiosco',
            'name' => 'Demo del test',
            'company_name' => 'Demo del test',
        ]);

        $respuesta->assertStatus(409);
        $respuesta->assertJson(['en_curso' => true]);
        $this->assertNotEmpty($respuesta->json('error'), 'El 409 tiene que traer un mensaje para mostrarle a quien disparó el setup.');

        $this->assertSame(
            $tablasAntes,
            $this->cantidadDeTablas($baseActiva),
            'Cambió la cantidad de tablas: el request rebotado llegó igual al migrate:fresh del helper.'
        );
    }

    /** @test */
    public function el_candado_queda_libre_despues_de_soltarlo()
    {
        $primero = DemoSetupLockHelper::tomar();
        $this->assertNotFalse($primero, 'El candado tenía que estar libre al empezar el test.');

        $this->assertFalse(
            DemoSetupLockHelper::tomar(),
            'Con el candado tomado, un segundo tomar() tiene que devolver false y no colgarse esperando.'
        );

        DemoSetupLockHelper::soltar($primero);

        $segundo = DemoSetupLockHelper::tomar();
        $this->assertNotFalse($segundo, 'Después de soltar, el candado tiene que volver a estar disponible.');

        DemoSetupLockHelper::soltar($segundo);
    }

    /** @test */
    public function sin_candado_tomado_el_archivo_lock_no_bloquea_por_si_solo()
    {
        /**
         * El archivo .lock NO se borra al soltar (borrarlo abre una carrera entre el fopen de
         * uno y el unlink del otro). Este caso fija esa decisión: que el archivo exista de
         * corridas anteriores no puede alcanzar para trabar la instancia — lo que traba es el
         * flock vivo de un proceso vivo.
         */
        $handle = DemoSetupLockHelper::tomar();
        $this->assertNotFalse($handle);
        DemoSetupLockHelper::soltar($handle);

        $this->assertFileExists(DemoSetupLockHelper::ruta(), 'El archivo de candado queda en disco a propósito.');

        $otro = DemoSetupLockHelper::tomar();
        $this->assertNotFalse($otro, 'Un archivo .lock huérfano no puede trabar el setup.');
        DemoSetupLockHelper::soltar($otro);
    }

    /**
     * @param string $baseActiva
     *
     * @return int
     */
    protected function cantidadDeTablas($baseActiva)
    {
        $fila = DB::selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
            [$baseActiva]
        );

        return (int) $fila->total;
    }
}
