<?php

namespace Tests\Unit;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Http\Client\Factory as FabricaDeLaravel;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fakes\EntornoSinCredenciales;
use Tests\Fakes\HttpFactorySinSalida;
use Tests\Fakes\PedidoHttpRealBloqueado;
use Tests\Fakes\StreamHttpSinSalida;
use Tests\TestCase;

/**
 * Los tests de empresa-api NO salen a internet (6/10/2026, tarea derivada del incidente del 5/10 en admin-api).
 *
 * Un test de `admin-api` hizo un POST real a `https://api-<cliente>.comerciocity.com/api/admin-sync/user-setup` y,
 * como del otro lado ese endpoint corre `migrate:fresh`, vació la base de producción de un cliente. `empresa-api` tenía
 * el mismo agujero estructural. Estos tests fijan las barreras que lo impiden, en la capa más baja: la que instala el
 * `TestCase` base y las de `phpunit.xml`.
 *
 * 🔴 TODOS los hosts de este archivo son `.test` o `.invalid`. Si una barrera se rompe, lo que estos tests hagan no
 * tiene que poder llegar a ningún servidor real: un test de la barrera que apunta a un host real es exactamente el error
 * que la barrera existe para impedir.
 */
class SalidaAInternetDeLosTestsTest extends TestCase
{
    /**
     * Vacía los pedidos frenados al terminar: acá se frenan a propósito, y no tienen que ensuciar el registro de
     * auditoría (`storage/logs/http-frenado-en-tests.log`) que mira a los demás tests.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        HttpFactorySinSalida::vaciar();

        parent::tearDown();
    }

    /**
     * Estos tests frenan pedidos A PROPÓSITO: se vacía la lista ANTES de la alarma del `TestCase` (que corre en
     * `assertPostConditions()` y la miraría).
     *
     * @return void
     */
    protected function assertPostConditions(): void
    {
        HttpFactorySinSalida::vaciar();

        parent::assertPostConditions();
    }

    /**
     * @return void
     */
    public function test_el_cliente_http_de_cada_test_es_el_que_frena(): void
    {
        $this->assertInstanceOf(HttpFactorySinSalida::class, Http::getFacadeRoot());
    }

    /**
     * El caso del incidente: un pedido que ningún fake atiende NO sale.
     *
     * @return void
     */
    public function test_un_pedido_sin_fake_se_frena_y_no_sale(): void
    {
        try {
            Http::timeout(2)->acceptJson()->asJson()->post('https://api-guardia.ejemplo.test/api/admin-sync/user-setup', ['a' => 1]);

            $this->fail('El pedido sin fake salió: el freno no frenó.');
        } catch (PedidoHttpRealBloqueado $excepcion) {
            $this->assertStringContainsString('POST https://api-guardia.ejemplo.test/api/admin-sync/user-setup', $excepcion->getMessage());
        }

        $this->assertSame(['POST https://api-guardia.ejemplo.test/api/admin-sync/user-setup'], HttpFactorySinSalida::frenados());
    }

    /**
     * También se frenan los GET, y los que arman el pedido con otro método del `PendingRequest`.
     *
     * @return void
     */
    public function test_se_frena_cualquier_verbo(): void
    {
        foreach (['get', 'delete', 'put', 'patch', 'head'] as $verbo) {
            try {
                Http::{$verbo}('https://api-guardia.ejemplo.test/x');

                $this->fail('El ' . strtoupper($verbo) . ' sin fake salió.');
            } catch (PedidoHttpRealBloqueado $excepcion) {
                $this->assertStringContainsString(strtoupper($verbo) . ' https://api-guardia.ejemplo.test/x', $excepcion->getMessage());
            }
        }

        $this->assertCount(5, HttpFactorySinSalida::frenados());
    }

    /**
     * Con un fake que cubre la URL, todo anda como siempre.
     *
     * @return void
     */
    public function test_con_un_fake_que_lo_cubre_el_pedido_pasa_igual_que_siempre(): void
    {
        Http::fake(['https://api-guardia.ejemplo.test/*' => Http::response(['ok' => true], 201)]);

        $respuesta = Http::post('https://api-guardia.ejemplo.test/api/admin-sync/user-setup', ['a' => 1]);

        $this->assertSame(201, $respuesta->status());
        $this->assertTrue($respuesta->json('ok'));
        Http::assertSentCount(1);
        $this->assertSame([], HttpFactorySinSalida::frenados());
    }

    /**
     * Un fake de un host no habilita a los demás: es lo que pasó con el user setup, donde el test falseaba una cosa y el
     * pedido peligroso iba por otra.
     *
     * @return void
     */
    public function test_un_fake_de_otra_url_no_habilita_las_demas(): void
    {
        Http::fake(['https://permitido.ejemplo.test/*' => Http::response([], 200)]);

        $this->assertSame(200, Http::get('https://permitido.ejemplo.test/a')->status());

        $this->expectException(PedidoHttpRealBloqueado::class);

        Http::get('https://otro-host.ejemplo.test/a');
    }

    /**
     * El freno no cambia la semántica de los fakes: dada la misma lista de fakes, la fábrica que frena y la de Laravel
     * responden lo mismo y llaman a los mismos stubs en el mismo orden. (Los stubs se acumulan, se invocan TODOS aunque
     * responda el primero, y gana el primero que responde.)
     *
     * @return void
     */
    public function test_el_freno_no_cambia_la_semantica_de_los_fakes(): void
    {
        $armar = function ($fabrica, array &$visto) {
            $fabrica->fake(function ($pedido) use (&$visto) {
                $visto[] = 'a';

                return Http::response(['gana' => 'a'], 200);
            });

            $fabrica->fake(function ($pedido) use (&$visto) {
                $visto[] = 'b';

                return Http::response(['gana' => 'b'], 200);
            });

            return $fabrica->post('https://api-guardia.ejemplo.test/x', ['k' => 'v']);
        };

        $visto_laravel = [];
        $visto_freno   = [];

        $de_laravel = $armar(new FabricaDeLaravel(), $visto_laravel);
        $con_freno  = $armar(new HttpFactorySinSalida(), $visto_freno);

        $this->assertSame(['a', 'b'], $visto_laravel, 'Laravel invoca todos los stubs (si no, este test no prueba nada).');
        $this->assertSame($visto_laravel, $visto_freno);
        $this->assertSame($de_laravel->json(), $con_freno->json());
        $this->assertSame('a', $con_freno->json('gana'));
    }

    /**
     * Los `Http::sequence()` y `Http::fakeSequence()` siguen funcionando con el freno puesto.
     *
     * @return void
     */
    public function test_las_secuencias_siguen_andando(): void
    {
        Http::fake(['https://api-guardia.ejemplo.test/*' => Http::sequence()->push(['n' => 1], 200)->push(['n' => 2], 500)]);

        $this->assertSame(1, Http::get('https://api-guardia.ejemplo.test/a')->json('n'));
        $this->assertSame(500, Http::get('https://api-guardia.ejemplo.test/a')->status());
    }

    /**
     * `Http::pool()` (varios pedidos en paralelo) pasa por la misma fábrica: lo que el fake cubre responde y lo que no, se
     * frena. El `Pool` de Laravel 8.83 arma cada pedido con `$factory->setHandler(...)`, que entra por el `__call` del freno.
     *
     * @return void
     */
    public function test_el_pool_tambien_frena_lo_que_ningun_fake_atiende(): void
    {
        Http::fake(['https://permitido.ejemplo.test/*' => Http::response(['ok' => true], 200)]);

        $respuestas = Http::pool(function ($pool) {
            return [
                $pool->as('cubierto')->get('https://permitido.ejemplo.test/a'),
            ];
        });

        $this->assertSame(200, $respuestas['cubierto']->status());
        $this->assertSame([], HttpFactorySinSalida::frenados());

        try {
            Http::pool(function ($pool) {
                return [
                    $pool->as('sin_cubrir')->get('https://otro-host.ejemplo.test/a'),
                ];
            });

            $this->fail('El pedido del pool sin fake salió: el freno no frena el pool.');
        } catch (\Throwable $excepcion) {
            // Según la versión de Guzzle la excepción sale directa o envuelta: lo que importa es que quedó anotado.
        }

        $this->assertSame(['GET https://otro-host.ejemplo.test/a'], HttpFactorySinSalida::frenados());
    }

    /**
     * La fábrica que frena conserva el despachador de eventos de la aplicación: `ImageServiceCallLogger` escucha
     * `ResponseReceived` ahí para registrar el estado HTTP de las búsquedas, y sin despachador no se entera.
     *
     * @return void
     */
    public function test_la_fabrica_que_frena_conserva_el_despachador_de_eventos(): void
    {
        $this->assertSame(app('events'), Http::getFacadeRoot()->getDispatcher());
    }

    /**
     * La segunda barrera: Guzzle, armado a mano sin pasar por la fachada `Http`, sale por un proxy que no existe. Se mira la
     * configuración del cliente y no se hace ningún pedido.
     *
     * @return void
     */
    public function test_guzzle_sin_la_fachada_sale_por_un_proxy_muerto(): void
    {
        $proxy = (new GuzzleClient())->getConfig('proxy');

        $this->assertIsArray($proxy);
        $this->assertSame('http://127.0.0.1:9', $proxy['http']);
        $this->assertSame('http://127.0.0.1:9', $proxy['https']);
        $this->assertContains('localhost', $proxy['no']);
        $this->assertContains('127.0.0.1', $proxy['no']);
    }

    /**
     * La misma barrera para curl nativo (el de `MercadoPagoController` y el de `Afip\WS`): el pedido va al proxy muerto y
     * termina en "no pude conectar" (código 7), no en "no resuelvo el host" (6), que sería lo que da un `.test` si el proxy
     * no se aplicara. Sale por el puerto 9 de la propia máquina: no llega a ningún servidor.
     *
     * @return void
     */
    public function test_curl_nativo_sale_por_el_proxy_muerto(): void
    {
        $curl = curl_init('http://sin-salida.ejemplo.test/x');

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curl, CURLOPT_TIMEOUT, 5);

        $cuerpo = curl_exec($curl);
        $codigo = curl_errno($curl);
        $error  = curl_error($curl);

        curl_close($curl);

        $this->assertFalse($cuerpo);
        $this->assertSame(CURLE_COULDNT_CONNECT, $codigo, 'curl no salió por el proxy muerto: ' . $error);
        $this->assertStringContainsString('127.0.0.1', $error);
    }

    /**
     * La cuarta barrera: lo que sale por los streams de PHP (`file_get_contents`, `get_headers`, `getimagesize`, `copy`) no pasa por la fachada ni respeta un proxy, y se frena igual y queda anotado.
     *
     * @return void
     */
    public function test_los_streams_http_se_frenan_y_quedan_anotados(): void
    {
        $intentos = [
            'file_get_contents' => function () {
                return file_get_contents('https://sin-salida.ejemplo.test/a.json');
            },
            'get_headers' => function () {
                return get_headers('http://sin-salida.ejemplo.test/b.png');
            },
            'getimagesize' => function () {
                return getimagesize('http://sin-salida.ejemplo.test/c.png');
            },
            'copy' => function () {
                return copy('https://sin-salida.ejemplo.test/d.png', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sin-salida-d.png');
            },
        ];

        foreach ($intentos as $nombre => $intento) {
            try {
                $intento();

                $this->fail($nombre . ' sobre una URL no se frenó: el stream salió.');
            } catch (PedidoHttpRealBloqueado $excepcion) {
                $this->assertStringContainsString('sin-salida.ejemplo.test', $excepcion->getMessage(), $nombre);
            }
        }

        $this->assertCount(count($intentos), HttpFactorySinSalida::frenados());
        $this->assertSame('STREAM https://sin-salida.ejemplo.test/a.json', HttpFactorySinSalida::frenados()[0]);
    }

    /**
     * `file_exists()`, `is_file()` e `is_dir()` sobre una URL dan `false` SIN tocar la red: el wrapper `http` nativo de PHP no implementa
     * `stat()`, y la barrera se comporta igual (no lanza, no anota). Si anotara, el `is_file($image_url)` de
     * `GeneralHelper::storage_public_path_from_image_url()` daría un falso positivo en cada test que le pasa una URL.
     *
     * @return void
     */
    public function test_file_exists_e_is_file_sobre_una_url_dan_false_como_el_wrapper_nativo(): void
    {
        $this->assertFalse(file_exists('http://sin-salida.ejemplo.test/e.png'));
        $this->assertFalse(is_file('https://sin-salida.ejemplo.test/e.png'));
        $this->assertFalse(is_dir('https://sin-salida.ejemplo.test/'));
        $this->assertSame([], HttpFactorySinSalida::frenados());
    }

    /**
     * Los archivos locales no se tocan: el wrapper reemplaza solo `http` y `https`.
     *
     * @return void
     */
    public function test_los_archivos_locales_siguen_andando(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'sin-salida-');

        file_put_contents($ruta, 'contenido local');

        $this->assertSame('contenido local', file_get_contents($ruta));
        $this->assertTrue(file_exists($ruta));
        $this->assertSame([], HttpFactorySinSalida::frenados());

        unlink($ruta);
    }

    /**
     * Las credenciales reales de los servicios externos (el `.env.testing` de cada slot es una copia del `.env` de
     * desarrollo de Lucas) llegan a los tests reemplazadas por un valor de mentira, o vacías.
     *
     * @return void
     */
    public function test_las_credenciales_reales_no_se_cuelan_a_los_tests(): void
    {
        foreach (EntornoSinCredenciales::valores() as $nombre => $valor) {
            $this->assertSame($valor, env($nombre), $nombre . ' no quedó neutralizada: un test tiene a mano una credencial real.');
        }

        $this->assertSame('', trim((string) config('services.serper.api_key')));
        $this->assertNotSame('', trim((string) config('services.anthropic.api_key')), 'La clave de Anthropic de los tests tiene que estar presente (de mentira) para que el código siga por el camino "con clave".');
    }

    /**
     * El broadcast y el mailer de los tests no dependen del `.env.testing` de cada slot.
     *
     * @return void
     */
    public function test_el_broadcast_y_el_mailer_de_los_tests_no_salen(): void
    {
        $this->assertSame('log', config('broadcasting.default'));
        $this->assertSame('array', config('mail.default'));
    }

    /**
     * 🔴 La alarma: un pedido frenado que el código bajo prueba SE TRAGÓ (como hace el servicio del incidente: atrapa todo y
     * sigue) hace FALLAR al test, con la lista de pedidos y qué hacer. Es lo que corre en el `tearDown()` del `TestCase`;
     * acá se llama a mano porque este test vacía la lista en su propio `tearDown()` (frena a propósito).
     *
     * @return void
     */
    public function test_un_pedido_frenado_que_el_codigo_se_trago_hace_fallar_al_test(): void
    {
        try {
            Http::post('https://api-trago.ejemplo.test/api/admin-sync/user-setup', ['a' => 1]);
        } catch (\Throwable $excepcion) {
            // Lo que hace la aplicación casi en todas partes: se lo traga y sigue. El test "pasaría".
        }

        $salto   = false;
        $mensaje = '';

        // La alarma también anota el pedido en el registro de auditoría (`http-frenado-en-tests.log`): acá se deja ese archivo
        // como estaba, porque su sola existencia después de una corrida es la señal de que ALGÚN test intentó salir.
        $registro = storage_path('logs/http-frenado-en-tests.log');
        $existia  = file_exists($registro);
        $tamano   = $existia ? filesize($registro) : 0;

        try {
            $this->exigir_que_no_se_haya_frenado_ningun_pedido();
        } catch (AssertionFailedError $falla) {
            $salto   = true;
            $mensaje = $falla->getMessage();
        } finally {
            if (! $existia) {
                @unlink($registro);
            } elseif (file_exists($registro)) {
                $manejador = fopen($registro, 'r+');
                ftruncate($manejador, $tamano);
                fclose($manejador);
            }
        }

        $this->assertTrue($salto, 'Un pedido frenado y tragado no hizo fallar al test: el freno es solo un registro.');
        $this->assertStringContainsString('POST https://api-trago.ejemplo.test/api/admin-sync/user-setup', $mensaje);
        $this->assertStringContainsString('Http::fake', $mensaje);
        $this->assertSame([], HttpFactorySinSalida::frenados(), 'La alarma tiene que vaciar la lista: si no, se le cuenta al test siguiente.');
    }

    /**
     * Sin pedidos frenados la alarma no salta (el caso de todos los tests de la suite).
     *
     * @return void
     */
    public function test_sin_pedidos_frenados_la_alarma_no_salta(): void
    {
        Http::fake(['https://api-ok.ejemplo.test/*' => Http::response([], 200)]);

        Http::get('https://api-ok.ejemplo.test/x');

        $this->exigir_que_no_se_haya_frenado_ningun_pedido();

        $this->assertSame([], HttpFactorySinSalida::frenados());
    }

    /**
     * 🔴 Un `Http::swap(new Factory())` "para limpiar los stubs" se lleva puesto el freno: el test que lo hace FALLA al
     * terminar, aunque no haya salido ningún pedido. Se cambia por `Http::swap(new HttpFactorySinSalida(app('events')))`.
     *
     * @return void
     */
    public function test_un_swap_que_se_lleva_el_freno_hace_fallar_al_test(): void
    {
        $this->exigir_que_el_freno_siga_puesto();

        Http::swap(new FabricaDeLaravel(app('events')));

        $salto   = false;
        $mensaje = '';

        try {
            $this->exigir_que_el_freno_siga_puesto();
        } catch (AssertionFailedError $falla) {
            $salto   = true;
            $mensaje = $falla->getMessage();
        } finally {
            // Se deja puesto el freno para que el tearDown de ESTE test (que corre la misma alarma) no falle.
            Http::swap(new HttpFactorySinSalida(app('events')));
        }

        $this->assertTrue($salto, 'Un Http::swap(new Factory()) desarmó el freno y la alarma no saltó.');
        $this->assertStringContainsString('HttpFactorySinSalida', $mensaje);
    }

    /**
     * El `Http::swap(new HttpFactorySinSalida(...))` (la forma correcta de pedir una fábrica limpia) no desarma nada, y
     * después de un `Http::fake()` el freno sigue puesto.
     *
     * @return void
     */
    public function test_pedir_una_fabrica_limpia_con_la_que_frena_no_desarma_el_freno(): void
    {
        Http::fake(['https://api-ok.ejemplo.test/*' => Http::response([], 200)]);
        Http::swap(new HttpFactorySinSalida(app('events')));

        $this->exigir_que_el_freno_siga_puesto();

        try {
            Http::get('https://api-ok.ejemplo.test/x');

            $this->fail('La fábrica limpia conservó el fake anterior: no es una fábrica limpia.');
        } catch (PedidoHttpRealBloqueado $excepcion) {
            $this->assertStringContainsString('GET https://api-ok.ejemplo.test/x', $excepcion->getMessage());
        }
    }

    /**
     * 🔴 Un test que toca los wrappers de streams por su cuenta y al terminar llama a `stream_wrapper_restore()` (lo hacía
     * `ComprobantesConDisenoDePagina::sin_red_https()`) deja el wrapper NATIVO. La barrera se reinstala sola en el `setUp` del
     * test siguiente: no hay bandera de "ya instalada" que lo impida. (Sin red: entre el `restore` y `instalar()` no se pide nada.)
     *
     * @return void
     */
    public function test_la_barrera_de_streams_se_repone_despues_de_un_stream_wrapper_restore(): void
    {
        stream_wrapper_unregister('https');
        stream_wrapper_restore('https');

        StreamHttpSinSalida::instalar();

        try {
            file_get_contents('https://sin-salida.ejemplo.test/x');

            $this->fail('Después de un stream_wrapper_restore() la barrera no volvió a ponerse: el stream salió.');
        } catch (PedidoHttpRealBloqueado $excepcion) {
            $this->assertStringContainsString('https://sin-salida.ejemplo.test/x', $excepcion->getMessage());
        }
    }

    /**
     * Lo que corre en el `setUp` de CADA test (`cerrar_la_salida_a_internet()`) deja la barrera puesta aunque el wrapper de
     * `http` haya quedado en el nativo.
     *
     * @return void
     */
    public function test_cerrar_la_salida_vuelve_a_poner_la_barrera_de_streams_en_cada_test(): void
    {
        stream_wrapper_unregister('http');
        stream_wrapper_restore('http');

        $this->cerrar_la_salida_a_internet();

        $this->expectException(PedidoHttpRealBloqueado::class);

        get_headers('http://sin-salida.ejemplo.test/x');
    }

    /**
     * `ClientMailConfigHelper::apply()` pisa en caliente `mail.default` con el SMTP de la casilla del comercio (host, usuario y
     * clave salen de `online_configurations`, o sea de la base) y eso anula `MAIL_MAILER=array`. El transporte `smtp` de los tests
     * escribe en memoria: no se abre ningún socket.
     *
     * @return void
     */
    public function test_el_smtp_de_la_casilla_del_comercio_no_abre_ningun_socket(): void
    {
        config([
            'mail.default'                 => 'smtp',
            'mail.mailers.smtp.host'       => 'smtp.sin-salida.ejemplo.test',
            'mail.mailers.smtp.port'       => 2525,
            'mail.mailers.smtp.encryption' => null,
            'mail.mailers.smtp.username'   => 'casilla@ejemplo.test',
            'mail.mailers.smtp.password'   => 'clave-de-mentira',
        ]);

        app('mail.manager')->purge('smtp');

        Mail::raw('hola', function ($mensaje) {
            $mensaje->to('alguien@ejemplo.test')->subject('prueba');
        });

        $transporte = app('mail.manager')->mailer('smtp')->getSwiftMailer()->getTransport();

        $this->assertInstanceOf(ArrayTransport::class, $transporte);
        $this->assertCount(1, $transporte->messages());
    }

    /**
     * Un test que necesita una clave concreta la pone él (`$_ENV`, `putenv()`…) y gana: el valor de mentira de
     * `EntornoSinCredenciales` vive solo en `putenv()`, que `Env` lee al final (después de `$_SERVER` y de `$_ENV`).
     *
     * @return void
     */
    public function test_un_test_que_necesita_una_clave_la_pone_por_env_o_por_putenv_y_gana(): void
    {
        $nombre = 'ANTHROPIC_API_KEY';

        try {
            $this->assertSame(EntornoSinCredenciales::valores()[$nombre], env($nombre), 'Antes del override tiene que verse el valor de mentira.');

            $_ENV[$nombre] = 'clave-que-puso-el-test';
            $this->assertSame('clave-que-puso-el-test', env($nombre), '$_ENV no le ganó al valor de mentira.');

            unset($_ENV[$nombre]);
            putenv($nombre . '=clave-por-putenv');
            $this->assertSame('clave-por-putenv', env($nombre), 'putenv() no le ganó al valor de mentira.');

            $_SERVER[$nombre] = 'clave-por-server';
            $this->assertSame('clave-por-server', env($nombre), '$_SERVER no le ganó al valor de mentira.');
        } finally {
            unset($_SERVER[$nombre], $_ENV[$nombre]);
            EntornoSinCredenciales::aplicar();
        }

        $this->assertSame(EntornoSinCredenciales::valores()[$nombre], env($nombre));
    }

    /**
     * Los procesos hijos reciben las credenciales con un valor NO vacío: `proc_open` descarta los vacíos de un entorno armado a mano
     * y el hijo volvería a leer la clave real de su `.env.testing`.
     *
     * @return void
     */
    public function test_las_credenciales_de_los_procesos_hijos_no_van_vacias(): void
    {
        $para_hijos = EntornoSinCredenciales::para_procesos_hijos();

        $this->assertSame(array_keys(EntornoSinCredenciales::valores()), array_keys($para_hijos));

        foreach ($para_hijos as $nombre => $valor) {
            $this->assertNotSame('', $valor, $nombre . ' va vacía a los procesos hijos.');
        }

        $this->assertSame('', EntornoSinCredenciales::valores()['SERPER_API_KEY']);
        $this->assertSame(EntornoSinCredenciales::SIN_CREDENCIAL_REAL, $para_hijos['SERPER_API_KEY']);
    }

    /**
     * La alarma vive en `assertPostConditions()` del `TestCase`: un test que lo sobreescriba sin llamar a `parent` se queda sin ella.
     *
     * @return void
     */
    public function test_ningun_test_sobreescribe_assertPostConditions_sin_llamar_a_parent(): void
    {
        $raiz       = base_path('tests');
        $sin_parent = [];

        $archivos = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS));

        foreach ($archivos as $archivo) {
            if ($archivo->getExtension() !== 'php') {
                continue;
            }

            $codigo = file_get_contents($archivo->getPathname());

            if (preg_match('/function\s+assertPostConditions\s*\(/i', $codigo) && ! preg_match('/parent\s*::\s*assertPostConditions\s*\(/i', $codigo)) {
                $sin_parent[] = substr($archivo->getPathname(), strlen($raiz) + 1);
            }
        }

        $this->assertSame([], $sin_parent, 'Estos tests sobreescriben assertPostConditions() sin llamar a parent: no tienen la alarma del freno de internet.');
    }

    /**
     * Una clave real definida como variable de entorno del sistema operativo (Windows) llega a `$_SERVER` y a `$_ENV` cuando arranca PHP, y `Env`
     * las lee ANTES que `putenv()`: la neutralización las borra, y un test que necesita la clave la sigue pudiendo poner después y ganar.
     *
     * @return void
     */
    public function test_una_credencial_real_que_dejo_el_sistema_operativo_no_le_gana_al_valor_de_mentira(): void
    {
        $nombre = 'ANTHROPIC_API_KEY';

        try {
            $_SERVER[$nombre] = 'clave-real-del-sistema-operativo';
            $_ENV[$nombre]    = 'clave-real-del-sistema-operativo';

            $this->assertSame('clave-real-del-sistema-operativo', env($nombre), 'El test no armó la situación: la del sistema operativo tiene que ganar antes de neutralizar.');

            EntornoSinCredenciales::aplicar();   // lo que corre en el arranque de cada test

            $this->assertSame(EntornoSinCredenciales::valores()[$nombre], env($nombre), 'La clave del sistema operativo le ganó al valor de mentira.');
            $this->assertArrayNotHasKey($nombre, $_SERVER);
            $this->assertArrayNotHasKey($nombre, $_ENV);

            $_ENV[$nombre] = 'clave-que-puso-el-test';
            $this->assertSame('clave-que-puso-el-test', env($nombre), 'Un override posterior del test no ganó.');
        } finally {
            unset($_SERVER[$nombre], $_ENV[$nombre]);
            EntornoSinCredenciales::aplicar();
        }
    }

    /**
     * 🔴 La alarma tiene que estar ENCHUFADA en `assertPostConditions()` del `TestCase`, que es lo que corre PHPUnit al terminar cada test. Los demás
     * tests de este archivo llaman a `exigir_*` directo: si alguien borrara esas llamadas de `assertPostConditions()`, ninguno lo notaría y la suite
     * seguiría en verde SIN alarma. Acá se llama al `assertPostConditions()` real del `TestCase` base, que es el que corre en todos los demás tests.
     *
     * @return void
     */
    public function test_la_alarma_esta_enchufada_a_assert_post_conditions_del_test_case(): void
    {
        $alarma = function () {
            return $this->sin_tocar_el_registro_de_frenados(function () {
                try {
                    parent::assertPostConditions();
                } catch (AssertionFailedError $falla) {
                    return $falla->getMessage();
                }

                return null;
            });
        };

        // 1) Un pedido frenado que el código bajo prueba se tragó.
        try {
            Http::post('https://api-trago.ejemplo.test/x', ['a' => 1]);
        } catch (\Throwable $excepcion) {
            // Lo que hace la aplicación casi en todas partes.
        }

        $por_pedido = $alarma();

        // 2) Un `Http::swap(new Factory)` pelado, sin ningún pedido.
        Http::swap(new FabricaDeLaravel(app('events')));

        try {
            $por_swap = $alarma();
        } finally {
            Http::swap(new HttpFactorySinSalida(app('events')));
        }

        $this->assertNotNull($por_pedido, 'assertPostConditions() del TestCase no hizo fallar a un test que se tragó un pedido frenado: la alarma está desenchufada.');
        $this->assertStringContainsString('POST https://api-trago.ejemplo.test/x', $por_pedido);
        $this->assertNotNull($por_swap, 'assertPostConditions() del TestCase no hizo fallar a un test que se llevó puesto el freno: la alarma está desenchufada.');
        $this->assertStringContainsString('HttpFactorySinSalida', $por_swap);
    }

    /**
     * Un test que lanza un proceso hijo con un entorno armado a mano (`proc_open`) tiene que sumarle `EntornoSinCredenciales::para_procesos_hijos()`:
     * `proc_open` descarta los valores vacíos de un entorno armado a mano, y el hijo volvería a leer la clave real de su `.env.testing`.
     *
     * @return void
     */
    public function test_los_tests_que_lanzan_procesos_con_entorno_armado_a_mano_le_pasan_las_credenciales_de_mentira(): void
    {
        $raiz           = base_path('tests');
        $sin_credencial = [];

        $archivos = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS));

        foreach ($archivos as $archivo) {
            $relativo = str_replace('\\', '/', substr($archivo->getPathname(), strlen($raiz) + 1));

            // Los dobles de las barreras y este mismo archivo nombran `proc_open` en sus comentarios.
            if ($archivo->getExtension() !== 'php' || strpos($relativo, 'Fakes/') === 0 || $relativo === 'Unit/SalidaAInternetDeLosTestsTest.php') {
                continue;
            }

            $codigo = file_get_contents($archivo->getPathname());

            if (preg_match('/\bproc_open\s*\(/i', $codigo) && stripos($codigo, 'para_procesos_hijos') === false) {
                $sin_credencial[] = $relativo;
            }
        }

        $this->assertSame([], $sin_credencial, 'Estos tests lanzan un proceso hijo con proc_open y no suman EntornoSinCredenciales::para_procesos_hijos() a su entorno: el hijo lee las credenciales reales del .env.testing.');
    }

    /**
     * Corre `$accion` y deja el registro de auditoría (`http-frenado-en-tests.log`) como estaba: su sola existencia después de una corrida es la
     * señal de que ALGÚN test intentó salir, y los tests de este archivo frenan pedidos a propósito.
     *
     * @param callable $accion Lo que se corre.
     *
     * @return mixed Lo que devuelve `$accion`.
     */
    private function sin_tocar_el_registro_de_frenados(callable $accion)
    {
        $registro = storage_path('logs/http-frenado-en-tests.log');
        $existia  = file_exists($registro);
        $tamano   = $existia ? filesize($registro) : 0;

        try {
            return $accion();
        } finally {
            if (! $existia) {
                @unlink($registro);
            } elseif (file_exists($registro)) {
                $manejador = fopen($registro, 'r+');
                ftruncate($manejador, $tamano);
                fclose($manejador);
            }
        }
    }
}
