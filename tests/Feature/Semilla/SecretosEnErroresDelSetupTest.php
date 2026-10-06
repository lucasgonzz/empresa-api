<?php

namespace Tests\Feature\Semilla;

use App\Http\Controllers\Helpers\SetupErrorHelper;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Mockery\MockInterface;
use Tests\EmpresaTestCase;

/**
 * Los errores de admin-sync/user-setup y admin-sync/demo-setup no dejan los secretos del payload ni
 * en la respuesta ni en el log (misión serper-en-user-setup, revisión independiente del 28/9/2026).
 *
 * Los dos endpoints atrapan cualquier excepción de run(), la loguean y devuelven su mensaje en el 500,
 * y admin-api guarda ese texto en el lead (leads.demo_setup_last_error / user_setup_last_error). Un
 * QueryException de Laravel 8 trae el SQL con los valores interpolados, y el INSERT del dueño lleva
 * serper_api_key y google_custom_search_api_key.
 *
 * Cómo se lo provoca sin vaciar la base: `Artisan::call('migrate:fresh')` —lo primero que hace run()—
 * se reemplaza por un mock que no hace nada, y el payload apunta a un id de usuario que YA existe, así
 * el INSERT del dueño falla de verdad por clave duplicada: es el QueryException real, con el SQL real
 * y las claves adentro. Dos redes contra un accidente: antes de postear se verifica que el facade de
 * Artisan sea el mock, y un renglón marcador en `migrations` (el mismo recurso de CandadoDeSetupTest)
 * tiene que seguir ahí al final.
 *
 * El log se mira con un listener de MessageLogged: TODAS las líneas, de cualquier nivel, y ninguna
 * puede traer una clave.
 *
 * @group semilla
 */
class SecretosEnErroresDelSetupTest extends EmpresaTestCase
{
    /** Clave de Serper del payload: alfanumérica, con la forma que valida el admin. */
    const CLAVE_SERPER = 'serperSetupSecreta0123456789abcdefXYZ';

    /**
     * Clave de Google del payload. SIN el prefijo `AIza` a propósito: con él la taparía la regla de
     * formas conocidas de sin_claves() y el test no probaría que se tacha por venir en el payload.
     */
    const CLAVE_GOOGLE = 'googleSetupSecreta0123456789abcdefXYZ';

    /** Renglón inventado en `migrations` que un `migrate:fresh` no puede dejar en pie. */
    const MARCADOR = 'marcador_del_test_de_secretos_en_errores_del_setup';

    /** @var array Las líneas de log del test: [nivel, texto (mensaje + contexto)]. */
    protected $logs = [];

    /** @var \App\Models\User Un usuario que ya existe: su id hace fallar el INSERT del dueño. */
    protected $existente;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('migrations')->insert([
            'migration' => self::MARCADOR,
            'batch'     => 9999,
        ]);

        $this->existente = User::create([
            'name'     => 'Ya existe (secretos del setup)',
            'email'    => 'secretos-del-setup-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->logs = [];

        $test = $this;

        Event::listen(MessageLogged::class, function (MessageLogged $evento) use ($test) {
            $test->logs[] = [
                'nivel' => (string) $evento->level,
                'texto' => (string) $evento->message.' '.$test->contexto_como_texto((array) $evento->context),
            ];
        });
    }

    /**
     * El contexto de una línea de log como texto, con las excepciones abiertas (json_encode de un
     * Throwable da `{}` y escondería lo que trae).
     *
     * @param  array $contexto
     * @return string
     */
    public function contexto_como_texto(array $contexto)
    {
        $plano = [];

        foreach ($contexto as $clave => $valor) {
            $plano[$clave] = $valor instanceof \Throwable
                ? $valor->getMessage().' '.$valor->getTraceAsString()
                : $valor;
        }

        return (string) json_encode($plano, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * Reemplaza el `migrate:fresh` de run() por un mock que no hace nada, y verifica que el reemplazo
     * quedó puesto ANTES de que el test llame al endpoint.
     *
     * @return void
     */
    protected function sin_migrate_fresh()
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('migrate:fresh', ['--force' => true])
            ->andReturn(0);

        $this->assertInstanceOf(
            MockInterface::class,
            Artisan::getFacadeRoot(),
            'SEGURO: Artisan no quedó mockeado y el setup vaciaría la base de testing. No se sigue.'
        );
    }

    /**
     * Lo que se verifica igual para los dos endpoints.
     *
     * @param  \Illuminate\Testing\TestResponse $respuesta
     * @param  string                           $prefijo_del_log  'AdminSync user-setup:' | 'AdminSync demo-setup:'
     * @return void
     */
    protected function sin_secretos_en_la_respuesta_ni_en_el_log($respuesta, $prefijo_del_log)
    {
        $respuesta->assertStatus(500);

        $error = (string) $respuesta->json('error');

        // Es el QueryException real del INSERT del dueño, y las claves se taparon ahí mismo.
        $this->assertStringStartsWith('internal error: ', $error);
        $this->assertStringContainsString('Duplicate entry', $error, 'Tiene que ser el error real del INSERT del dueño.');
        $this->assertStringContainsString('serper_api_key', $error, 'El SQL del INSERT lleva la columna: si no, este test no prueba nada.');
        $this->assertStringContainsString('***', $error, 'Las claves estaban en el SQL y se taparon.');

        foreach ([self::CLAVE_SERPER, self::CLAVE_GOOGLE] as $clave) {
            $this->assertStringNotContainsString($clave, $respuesta->getContent(), 'La respuesta (que el admin guarda en el lead) trae una clave.');
        }

        // El log: salió la línea del error, y NINGUNA línea (de ningún nivel) trae una clave.
        $del_setup = array_filter($this->logs, function ($log) use ($prefijo_del_log) {
            return $log['nivel'] === 'error' && strpos($log['texto'], $prefijo_del_log) !== false;
        });

        $this->assertNotEmpty($del_setup, 'Tiene que salir la línea de log del error del setup.');

        foreach ($this->logs as $log) {
            foreach ([self::CLAVE_SERPER, self::CLAVE_GOOGLE] as $clave) {
                $this->assertStringNotContainsString($clave, $log['texto'], 'Una línea de log ('.$log['nivel'].') trae una clave.');
            }
        }

        // El migrate:fresh no corrió: el marcador sigue en pie.
        $this->assertTrue(
            DB::table('migrations')->where('migration', self::MARCADOR)->exists(),
            'El marcador desapareció: corrió un migrate:fresh sobre la base de testing.'
        );
    }

    /**
     * @test
     */
    public function el_error_del_user_setup_no_deja_las_claves_en_la_respuesta_ni_en_el_log()
    {
        $this->sin_migrate_fresh();

        $respuesta = $this->postJson('/api/admin-sync/user-setup', [
            'business_type'                => 'ferreteria',
            // La base de testing TIENE datos (y la guarda de UserSetupHelper::run() se niega a vaciar
            // una base con datos): para llegar al `migrate:fresh` mockeado hay que autorizar el
            // borrado como lo haría un operador, con el flag y el nombre exacto de la base. Es seguro
            // porque Artisan está mockeado y verificado ANTES de postear (sin_migrate_fresh()).
            'forzar_borrado_total'         => true,
            'confirmar_base_de_datos'      => DB::connection()->getDatabaseName(),
            // Un id que ya existe: el INSERT del dueño falla con el QueryException real.
            'user_id'                      => $this->existente->id,
            'user_name'                    => 'Cliente del test de secretos',
            'company_name'                 => 'Cliente del test de secretos',
            'email'                        => 'cliente-secretos-'.uniqid().'@test.local',
            'serper_api_key'               => self::CLAVE_SERPER,
            'google_custom_search_api_key' => self::CLAVE_GOOGLE,
        ]);

        $this->sin_secretos_en_la_respuesta_ni_en_el_log($respuesta, 'AdminSync user-setup:');
    }

    /**
     * @test
     */
    public function el_error_del_demo_setup_no_deja_las_claves_en_la_respuesta_ni_en_el_log()
    {
        // La demo toma su id de config('app.USER_ID'): uno que ya existe hace fallar el INSERT.
        config(['app.USER_ID' => $this->existente->id]);

        $this->sin_migrate_fresh();

        $respuesta = $this->postJson('/api/admin-sync/demo-setup', [
            'business_type'                => 'ferreteria',
            'name'                         => 'Demo del test de secretos',
            'company_name'                 => 'Demo del test de secretos',
            'email'                        => 'demo-secretos-'.uniqid().'@test.local',
            'doc_number'                   => '30111222',
            'serper_api_key'               => self::CLAVE_SERPER,
            'google_custom_search_api_key' => self::CLAVE_GOOGLE,
        ]);

        $this->sin_secretos_en_la_respuesta_ni_en_el_log($respuesta, 'AdminSync demo-setup:');
    }

    /**
     * Qué junta el helper del payload: las dos claves por nombre, cualquier otro campo con nombre de
     * secreto (a cualquier profundidad, con todo lo que cuelga de él), y nada más.
     *
     * @test
     */
    public function el_helper_junta_las_claves_y_los_campos_con_nombre_de_secreto_y_nada_mas()
    {
        $payload = [
            'serper_api_key'               => '  '.self::CLAVE_SERPER.'  ',
            'google_custom_search_api_key' => self::CLAVE_GOOGLE,
            // Por nombre: el token del bus de eventos de la demo (hoy lo manda el admin).
            'demo_eventos_token'           => 'tokenDeEventosSecreto0123456789',
            // Un campo secreto que trae un array: todo lo de adentro es secreto.
            'credenciales'                 => ['usuario' => 'usuarioDeLaCredencial', 'clave' => 'claveAnidadaSecreta0123'],
            // Lo que no es secreto no se junta.
            'business_type'                => 'ferreteria',
            'demo_eventos_url'             => 'https://admin.test/eventos',
            'doc_number'                   => '30111222',
            'email'                        => 'no-es-secreto@test.local',
            'use_price_lists'              => true,
            'serper_api_key_vacia'         => null,
        ];

        $secretos = SetupErrorHelper::secretos_del_payload($payload);

        $this->assertEqualsCanonicalizing([
            '  '.self::CLAVE_SERPER.'  ',
            self::CLAVE_GOOGLE,
            'tokenDeEventosSecreto0123456789',
            'usuarioDeLaCredencial',
            'claveAnidadaSecreta0123',
        ], $secretos);

        // Y el texto queda sin ninguno (la de Serper, tapada aunque el payload la traiga con espacios).
        $texto = 'insert values ('.self::CLAVE_SERPER.', '.self::CLAVE_GOOGLE.', tokenDeEventosSecreto0123456789, '
            .'claveAnidadaSecreta0123, ferreteria, 30111222)';

        $this->assertSame(
            'insert values (***, ***, ***, ***, ferreteria, 30111222)',
            SetupErrorHelper::sin_secretos($texto, $payload)
        );

        $this->assertNull(SetupErrorHelper::sin_secretos(null, $payload));

        // Una clave contenida en otra, con la corta primero en el payload: se tapa primero la más
        // larga (ImageServiceCallLogger::sin_claves ordena por largo), así no queda un pedazo a la vista.
        $contenidas = [
            'demo_eventos_token' => 'claveCortaContenida01',
            'serper_api_key'     => 'claveCortaContenida01MasElResto',
        ];

        $this->assertSame(
            'va *** y ***.',
            SetupErrorHelper::sin_secretos('va claveCortaContenida01MasElResto y claveCortaContenida01.', $contenidas)
        );
    }
}
