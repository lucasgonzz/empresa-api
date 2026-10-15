<?php

namespace Tests\Feature\Semilla;

use App\Http\Controllers\AdminSync\DemoSetupController;
use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\SetupErrorHelper;
use App\Http\Controllers\Helpers\UserSetupHelper;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Mockery\MockInterface;
use ReflectionMethod;
use Tests\EmpresaTestCase;

/**
 * Lo que tiene que cumplir el demo-setup con las credenciales de prueba de Zipnova y Mercado Pago
 * que manda el admin (misión credenciales-demo-zipnova-mp, 14/10/2026), más allá de cada
 * integración (esas están en `DemoSetupZipnovaDesdePayloadTest` y `DemoSetupMercadoPagoDesdePayloadTest`):
 *
 *  1. El orden en `run()`: el payload se aplica DESPUÉS de la restauración y del `.env`, así gana.
 *  2. Ni el helper ni el controlador persisten ni loguean el payload crudo.
 *  3. Los cuatro secretos se tachan en cualquier error del setup (`SetupErrorHelper`).
 *  4. Los clientes REALES (`user-setup`) no leen estos campos: son credenciales de demo.
 *
 * @group semilla
 */
class DemoSetupCredencialesDelPayloadTest extends EmpresaTestCase
{
    /** Rellenos inventados, con la forma de cada credencial pero sin ser de nadie. */
    const ZIPNOVA_TOKEN  = 'zp-token-de-prueba-0001';
    const ZIPNOVA_SECRET = 'zp-secret-de-prueba-0002';
    const MP_ACCESS      = 'TEST-relleno-access-token-0001';
    const MP_PUBLIC      = 'TEST-relleno-public-key-0002';

    /** Renglón inventado en `migrations` que un `migrate:fresh` no puede dejar en pie. */
    const MARCADOR = 'marcador_del_test_de_credenciales_del_payload';

    /** @var array Texto (mensaje + contexto) de cada línea de log del test. */
    private $logs = [];

    /**
     * Los cuatro campos secretos con sus rellenos.
     *
     * @return array
     */
    private function secretos()
    {
        return [
            'zipnova_api_token'        => self::ZIPNOVA_TOKEN,
            'zipnova_api_secret'       => self::ZIPNOVA_SECRET,
            'mercadopago_access_token' => self::MP_ACCESS,
            'mercadopago_public_key'   => self::MP_PUBLIC,
        ];
    }

    /**
     * El código fuente de un método (por reflexión), para probar el ORDEN de las llamadas.
     *
     * @param string $clase
     * @param string $metodo
     * @return string
     */
    private function fuente_de($clase, $metodo)
    {
        $reflexion = new ReflectionMethod($clase, $metodo);
        $lineas = file($reflexion->getFileName());

        return implode('', array_slice($lineas, $reflexion->getStartLine() - 1, $reflexion->getEndLine() - $reflexion->getStartLine() + 1));
    }

    /**
     * Archivo completo, con los finales de línea normalizados.
     *
     * @param string $ruta Relativa a la raíz de empresa-api.
     * @return string
     */
    private function leer($ruta)
    {
        return str_replace("\r\n", "\n", file_get_contents(base_path($ruta)));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 1. Orden en run()
    |---------------------------------------------------------------------------------------------
    */

    /**
     * 🔴 La precedencia "payload > foto restaurada > .env" es el ORDEN de las llamadas en `run()`:
     * restaurar, rama del `.env` y recién después el payload (que pisa lo que haya quedado). Si
     * alguien mueve el payload antes, la foto o el `.env` lo pisan y deja de ganar.
     *
     * @return void
     */
    public function test_el_payload_se_aplica_despues_de_la_restauracion_y_del_env()
    {
        $fuente = $this->fuente_de(DemoSetupHelper::class, 'run');

        foreach (['mercado_pago' => 'mercado_pago', 'zipnova' => 'zipnova'] as $nombre => $sufijo) {
            $restaurar = strpos($fuente, 'self::restaurar_' . $sufijo . '(');
            $env       = strpos($fuente, 'self::conectar_' . $sufijo . '_desde_env(');
            $payload   = strpos($fuente, 'self::conectar_' . $sufijo . '_desde_payload(');

            foreach (['restaurar' => $restaurar, 'env' => $env, 'payload' => $payload] as $paso => $posicion) {
                $this->assertNotFalse($posicion, "Falta el paso '$paso' de $nombre en run().");
            }

            $this->assertGreaterThan($restaurar, $env, "$nombre: el .env va después de restaurar.");
            $this->assertGreaterThan($env, $payload, "$nombre: el payload va DESPUÉS del .env y de la restauración, para ganarles.");
        }
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 2. El payload crudo no se persiste ni se loguea
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Ninguna llamada a `Log::` de `DemoSetupHelper` ni de `AdminSync\DemoSetupController` lleva el
     * payload crudo (`$data`, `$datos`, `$request`). La única mención permitida es el payload pasado
     * por `SetupErrorHelper::sin_secretos()` en el controlador, que lo usa para TACHAR.
     *
     * Es un chequeo estático a propósito: el payload entero lleva otras credenciales (Serper, token
     * de ingreso) y una línea de log nueva que lo interpolara las dejaría en el archivo de la
     * instancia.
     *
     * @return void
     */
    public function test_ningun_log_lleva_el_payload_crudo()
    {
        $archivos = [
            'app/Http/Controllers/Helpers/DemoSetupHelper.php' => ['$data'],
            'app/Http/Controllers/AdminSync/DemoSetupController.php' => ['$data', '$datos', '$request'],
        ];

        foreach ($archivos as $ruta => $prohibidos) {
            $fuente = $this->leer($ruta);

            // Quita los comentarios de bloque y de línea: acá importa el código, no lo que se explica.
            $fuente = preg_replace('#/\*.*?\*/#s', '', $fuente);
            $fuente = preg_replace('#^\s*//.*$#m', '', $fuente);

            $this->assertGreaterThan(
                0,
                preg_match_all('/Log::\w+\(.*?\);/s', $fuente, $llamadas),
                "No se encontró ninguna llamada a Log:: en $ruta: si cambió el estilo, actualizar este test."
            );

            foreach ($llamadas[0] as $llamada) {
                // El payload pasado a sin_secretos() es para tachar, no para loguear.
                $sin_tachado = preg_replace('/SetupErrorHelper::sin_secretos\((?:[^()]|\([^()]*\))*\)/', '', $llamada);

                foreach ($prohibidos as $variable) {
                    $this->assertFalse(
                        strpos($sin_tachado, $variable) !== false,
                        "Un Log:: de $ruta lleva $variable:\n$llamada"
                    );
                }
            }
        }
    }

    /**
     * El payload no se persiste: `DemoSetupHelper` no vuelca `$data` a ninguna tabla ni archivo.
     * Chequeo estático sobre dónde se usa `$data` como argumento COMPLETO (no `$data['clave']`):
     * solo puede pasarse a los métodos de la lista, que leen claves puntuales. Si alguien suma una
     * llamada nueva que reciba el payload entero (un `Log::`, un `json_encode`, un `User::create`),
     * este test se pone rojo y obliga a revisar que no lleve credenciales.
     *
     * @return void
     */
    public function test_el_payload_entero_solo_se_pasa_a_metodos_que_leen_claves_puntuales()
    {
        $fuente = $this->leer('app/Http/Controllers/Helpers/DemoSetupHelper.php');
        $fuente = preg_replace('#/\*.*?\*/#s', '', $fuente);
        $fuente = preg_replace('#^\s*//.*$#m', '', $fuente);

        // Solo el `$data` pelado (el array entero): un acceso `$data['clave']` lee una clave puntual y no cuenta.
        preg_match_all('/([A-Za-z_:>\\\\-]+)\(([^()]*\$data(?![\w\[])[^()]*)\)/', $fuente, $usos, PREG_SET_ORDER);

        $permitidos = [
            'self::create_demo_user', 'self::apply_business_type_rules', 'self::apply_flag_rules',
            'self::crear_depositos', 'self::crear_price_types', 'self::crear_client_con_mail_del_user_demo',
            'self::password_inicial', 'ImageSearchProviderFactory::clave_serper_del_payload',
            'self::conectar_mercado_pago_desde_payload', 'self::conectar_zipnova_desde_payload',
            'self::credencial_del_payload', 'run', 'create_demo_user', 'apply_business_type_rules',
            'apply_flag_rules', 'crear_depositos', 'crear_price_types', 'crear_client_con_mail_del_user_demo',
            'password_inicial', 'conectar_mercado_pago_desde_payload', 'conectar_zipnova_desde_payload',
            'credencial_del_payload', 'array_key_exists', 'function',
        ];

        foreach ($usos as $uso) {
            $this->assertContains(
                trim($uso[1]),
                $permitidos,
                'DemoSetupHelper pasa el payload entero a un método que no estaba en la lista: ' . $uso[0]
            );
        }

        $this->assertNotEmpty($usos);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 3. Los secretos se tachan en los errores
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Los cuatro campos secretos se tachan de cualquier texto de error, aunque venga con espacios o
     * embebido en un SQL; lo que no es secreto (la cuenta de Zipnova, el rubro) queda a la vista.
     *
     * @return void
     */
    public function test_los_secretos_del_payload_se_tachan_en_los_errores()
    {
        $payload = array_merge($this->secretos(), [
            'business_type'      => 'ferreteria',
            'zipnova_account_id' => '424242',
        ]);

        $texto = 'insert into platform_connectors (access_token) values (' . self::ZIPNOVA_TOKEN . ':' . self::ZIPNOVA_SECRET . ', '
            . self::MP_ACCESS . ', ' . self::MP_PUBLIC . ') -- ferreteria, cuenta 424242';

        $limpio = SetupErrorHelper::sin_secretos($texto, $payload);

        foreach ($this->secretos() as $valor) {
            $this->assertStringNotContainsString($valor, $limpio);
        }
        $this->assertStringContainsString('ferreteria', $limpio);
        $this->assertStringContainsString('424242', $limpio, 'El id de cuenta de Zipnova no es una credencial.');

        // Y están en la lista de nombres explícitos (no dependen solo del patrón por nombre).
        foreach (array_keys($this->secretos()) as $nombre) {
            $this->assertContains($nombre, SetupErrorHelper::CLAVES_SECRETAS);
        }
    }

    /**
     * Un `demo-setup` que falla (el INSERT del dueño, por un id repetido) con las credenciales en el
     * payload devuelve un 500 y deja un log SIN ninguno de los valores. Mismo recurso que
     * `SecretosEnErroresDelSetupTest`: el `migrate:fresh` se mockea y se verifica ANTES de postear.
     *
     * @return void
     */
    public function test_un_demo_setup_que_falla_no_deja_las_credenciales_en_la_respuesta_ni_en_el_log()
    {
        DB::table('migrations')->insert(['migration' => self::MARCADOR, 'batch' => 9999]);

        $existente = User::create([
            'name'     => 'Ya existe (credenciales del payload)',
            'email'    => 'credenciales-payload-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->logs = [];
        $test = $this;
        Event::listen(MessageLogged::class, function (MessageLogged $evento) use ($test) {
            $test->logs[] = (string) $evento->message . ' ' . json_encode((array) $evento->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
        });

        // La demo toma su id de config('app.USER_ID'): uno que ya existe hace fallar el INSERT.
        config(['app.USER_ID' => $existente->id]);

        Artisan::shouldReceive('call')->once()->with('migrate:fresh', ['--force' => true])->andReturn(0);
        $this->assertInstanceOf(
            MockInterface::class,
            Artisan::getFacadeRoot(),
            'SEGURO: Artisan no quedó mockeado y el setup vaciaría la base de testing. No se sigue.'
        );

        $respuesta = $this->postJson('/api/admin-sync/demo-setup', array_merge($this->secretos(), [
            'business_type' => 'ferreteria',
            'name'          => 'Demo del test de credenciales',
            'company_name'  => 'Demo del test de credenciales',
            'email'         => 'demo-credenciales-' . uniqid() . '@test.local',
            'doc_number'    => '30111222',
        ]));

        $respuesta->assertStatus(500);

        foreach ($this->secretos() as $valor) {
            $this->assertStringNotContainsString($valor, $respuesta->getContent(), 'La respuesta (que el admin guarda en el lead) trae una credencial.');

            foreach ($this->logs as $linea) {
                $this->assertStringNotContainsString($valor, $linea, 'Una línea de log trae una credencial.');
            }
        }

        $this->assertNotEmpty($this->logs, 'Tiene que haber líneas de log (la del error del setup).');
        $this->assertTrue(
            DB::table('migrations')->where('migration', self::MARCADOR)->exists(),
            'El marcador desapareció: corrió un migrate:fresh sobre la base de testing.'
        );
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 4. Los clientes reales no leen estos campos
    |---------------------------------------------------------------------------------------------
    */

    /**
     * 🔴 `user-setup` (clientes REALES) no lee ninguno de los cinco campos ni llama a los métodos que
     * los usan, aunque lleguen en el payload: son credenciales de demo. Chequeo estático sobre
     * `UserSetupHelper` y `UserSetupController` (las dos puntas de ese endpoint): ni el nombre de un
     * campo ni una llamada a `DemoSetupHelper` aparecen en su código.
     *
     * @return void
     */
    public function test_el_user_setup_de_clientes_reales_no_lee_estos_campos()
    {
        $campos = array_merge(array_keys($this->secretos()), ['zipnova_account_id']);

        foreach (['app/Http/Controllers/Helpers/UserSetupHelper.php', 'app/Http/Controllers/AdminSync/UserSetupController.php'] as $ruta) {
            $fuente = $this->leer($ruta);
            $fuente = preg_replace('#/\*.*?\*/#s', '', $fuente);
            $fuente = preg_replace('#^\s*//.*$#m', '', $fuente);

            foreach ($campos as $campo) {
                $this->assertStringNotContainsString($campo, $fuente, "$ruta lee el campo de demo '$campo'.");
            }

            foreach (['conectar_zipnova_desde', 'conectar_mercado_pago_desde', 'DemoSetupHelper::'] as $llamada) {
                $this->assertStringNotContainsString($llamada, $fuente, "$ruta llama a '$llamada': los clientes reales no conectan credenciales de demo.");
            }
        }
    }
}
