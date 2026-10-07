<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fakes\HttpFactorySinSalida;
use Tests\Fakes\StreamHttpSinSalida;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Marca si ya corrimos la verificación del guard en este proceso. El chequeo
     * en sí corre una sola vez (no se recalcula en cada test), pero si detectó un
     * problema, el mensaje queda cacheado y se re-lanza en cada test para frenar
     * la suite entera (ver guardBaseDeTesting()).
     *
     * @var bool
     */
    protected static $baseDeTestingVerificada = false;

    /**
     * Mensaje de error cacheado si la verificación falló. Null si pasó.
     *
     * @var string|null
     */
    protected static $errorDeGuardDeTesting = null;

    /**
     * Cada test arranca sobre una base de testing y SIN salida a internet.
     *
     * 🔴 La salida a internet: ver cerrar_la_salida_a_internet().
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cerrar_la_salida_a_internet();

        $this->guardBaseDeTesting();
    }

    /**
     * La alarma del freno de internet: si el test intentó un pedido que ningún `Http::fake()` atiende (o se llevó puesto el freno),
     * FALLA.
     *
     * 🔴 Lanzar `PedidoHttpRealBloqueado` no alcanza: la aplicación atrapa `\Throwable` casi en todas partes, así que un servicio que
     * se traga la excepción deja el test en verde (es lo que pasó el 5/10/2026 en admin-api: el test "pasó" y el pedido salió). Por eso
     * cada pedido frenado se anota (`storage/logs/http-frenado-en-tests.log`) y, además, hace fallar al test que lo intentó.
     *
     * 🔴 Va acá y no en `tearDown()`: PHPUnit corre `assertPostConditions()` DESPUÉS del test y ANTES de `tearDown()`, y siempre corre
     * `tearDown()` aunque esto falle. Si la falla se levantara en el `tearDown()` de esta clase, un test que limpia filas confirmadas
     * por una segunda conexión DESPUÉS de `parent::tearDown()` (`Precios/.../7_Carreras_entre_operaciones_Test`,
     * `Precios/RecalculoEnLote/15_La_tanda_bloquea_sus_articulos_Test`) se quedaría sin limpiar justo cuando la alarma salta.
     *
     * Un test que sobreescriba este método tiene que llamar a `parent::assertPostConditions()` (un test de `tests/Unit` lo verifica).
     *
     * @return void
     */
    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();

        $this->exigir_que_el_freno_siga_puesto();
        $this->exigir_que_no_se_haya_frenado_ningun_pedido();
    }

    /**
     * Si el test falló antes de llegar a `assertPostConditions()` (el código bajo prueba dejó salir la excepción del freno, por
     * ejemplo), igual queda anotado en el registro de auditoría lo que intentó salir. No levanta ninguna falla: el test ya falló.
     *
     * `parent::tearDown()` corre SIEMPRE: ahí se deshace la transacción de la base.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->anotar_los_pedidos_frenados();

        HttpFactorySinSalida::vaciar();

        parent::tearDown();
    }

    /**
     * Ningún test de empresa-api sale a internet.
     *
     * 🔴 POR QUÉ (5/10/2026). Un test de `admin-api` avanzó una implementación a la etapa 2 sin falsear la llamada al user
     * setup: el POST salió de verdad a `https://api-<cliente>.comerciocity.com/api/admin-sync/user-setup`, que del otro lado
     * corre `migrate:fresh`, y vació la base de producción de un cliente real. `empresa-api` tenía el mismo agujero: solo
     * falseaba lo que alguien pensó en falsear (el 27/9/2026 un test salió a Serper, que cobra, con la clave real del
     * `.env.testing` del slot) y el único freno que hay en Laravel 9+ (`Http::preventStrayRequests()`) no existe en la 8.83.
     *
     * Lo que hace esto, y qué NO hace:
     *  - Cambia el cliente HTTP por `HttpFactorySinSalida`: un pedido que ningún `Http::fake()` atiende lanza
     *    `PedidoHttpRealBloqueado` en vez de salir. Con un fake que lo cubre, nada cambia. Se construye con el despachador
     *    de eventos de la aplicación, como el que arma el contenedor (`ImageServiceCallLogger` escucha `ResponseReceived`).
     *    Un test que necesite una fábrica limpia (los stubs de `Http::fake()` se acumulan y gana el primero) tiene que hacer
     *    `Http::swap(new HttpFactorySinSalida(app('events')))`, NO `Http::swap(new \Illuminate\Http\Client\Factory(...))`:
     *    esta última deja el test sin freno, y `assertPostConditions()` lo detecta y hace fallar al test.
     *  - Reemplaza los wrappers `http://` y `https://` de PHP por `StreamHttpSinSalida`: `file_get_contents($url)`,
     *    `get_headers($url)`, `getimagesize($url)`, `copy($url, …)` no pasan por la fachada ni respetan un proxy, y en `app/`
     *    hay varios (`PdfHelper`, `GeneralHelper`, `HelperController`, el reporte de errores a GitHub). Se reinstala en CADA
     *    test: hay tests que tocan los wrappers por su cuenta.
     *  - Pone el transporte `smtp` de los mailers en memoria (`ArrayTransport`): `ClientMailConfigHelper::apply()` pisa en
     *    caliente `mail.default` con el SMTP de la casilla del comercio (host, usuario y clave de `online_configurations`, que
     *    viven en la base) y eso anularía `MAIL_MAILER=array` de `phpunit.xml`: un SMTP abre su propio socket.
     *  - `phpunit.xml` agrega un proxy muerto (curl nativo y Guzzle armado a mano, como el de `MercadoPagoController`),
     *    y `EntornoSinCredenciales` reemplaza las credenciales reales de los servicios externos por valores de mentira: el
     *    `.env.testing` de cada slot es una copia del `.env` de desarrollo de Lucas, con las claves REALES de Anthropic, OpenAI,
     *    Tienda Nube, Mercado Libre, Mercado Pago, un SMTP y Pusher.
     *  - NO cubre lo que no pasa por nada de eso: SSH (phpseclib), los clientes SOAP de ARCA, Web Push y los sockets crudos.
     *    Dependen de datos que el test mismo arma (credenciales, certificados, suscripciones); los tests de ARCA inyectan un
     *    SoapClient falso (`WS::__call()` real, doble de `\SoapClient`).
     *
     * @return void
     */
    protected function cerrar_la_salida_a_internet(): void
    {
        HttpFactorySinSalida::vaciar();

        StreamHttpSinSalida::instalar();

        Http::swap(new HttpFactorySinSalida(app('events')));

        app('mail.manager')->extend('smtp', function () {
            return new ArrayTransport();
        });
    }

    /**
     * Lo que corre en `assertPostConditions()`: si el freno frenó pedidos en este test, los anota, vacía la lista (para que no se
     * le cuenten al test siguiente) y levanta la falla, con la lista de pedidos y qué hacer.
     *
     * Un test que frena pedidos A PROPÓSITO (los de las barreras mismas) vacía la lista él antes de terminar:
     * `HttpFactorySinSalida::vaciar()`.
     *
     * @return void
     *
     * @throws AssertionFailedError Si se frenó algún pedido.
     */
    protected function exigir_que_no_se_haya_frenado_ningun_pedido(): void
    {
        $frenados = HttpFactorySinSalida::frenados();

        if ($frenados === []) {
            return;
        }

        $this->anotar_los_pedidos_frenados();

        HttpFactorySinSalida::vaciar();

        throw new AssertionFailedError(
            'Este test intentó ' . count($frenados) . ' pedido(s) HTTP que ningún Http::fake() atiende, y el freno los detuvo: '
            . implode('; ', $frenados) . '. No salieron, pero el código bajo prueba se tragó la excepción y el test siguió. '
            . 'Falseá cada uno con Http::fake([...]) o apuntá la fixture a un host .test. Un test no puede tener a mano un pedido real '
            . '(el 5/10/2026 uno le vació la base de producción a un cliente).'
        );
    }

    /**
     * Lo que corre en `assertPostConditions()`: si el cliente HTTP con el que terminó el test ya no es el que frena, el test FALLA.
     *
     * 🔴 Un `Http::swap(new \Illuminate\Http\Client\Factory())` "para limpiar los stubs" se lleva puesto el freno: desde ahí
     * cualquier pedido que el `Http::fake()` siguiente no cubra sale de verdad. Se detecta al terminar el test, aunque no haya
     * salido ningún pedido (la próxima edición del test puede agregar uno). Un doble de Mockery (`Http::shouldReceive()`) sí
     * se acepta: no tiene red.
     *
     * @return void
     *
     * @throws AssertionFailedError Si el cliente HTTP no frena.
     */
    protected function exigir_que_el_freno_siga_puesto(): void
    {
        if (! $this->app) {
            return;
        }

        $raiz = Http::getFacadeRoot();

        if ($raiz instanceof HttpFactorySinSalida || $raiz instanceof \Mockery\MockInterface) {
            return;
        }

        throw new AssertionFailedError(
            'Este test dejó un cliente HTTP que NO frena (' . (is_object($raiz) ? get_class($raiz) : gettype($raiz)) . '): un '
            . 'Http::swap(new Factory(...)) se lleva puesto el freno de internet de los tests. Usá '
            . "Http::swap(new \\Tests\\Fakes\\HttpFactorySinSalida(app('events'))), o Http::fake([...]) sin el swap."
        );
    }

    /**
     * Vuelca a `storage/logs/http-frenado-en-tests.log` los pedidos que el freno detuvo en este test (si hubo). Nunca rompe un
     * test: es solo un registro.
     *
     * @return void
     */
    protected function anotar_los_pedidos_frenados(): void
    {
        $frenados = HttpFactorySinSalida::frenados();

        if ($frenados === []) {
            return;
        }

        try {
            $lineas = '';

            foreach ($frenados as $pedido) {
                $lineas .= date('Y-m-d H:i:s') . ' ' . static::class . '::' . $this->getName(false) . ' ' . $pedido . PHP_EOL;
            }

            file_put_contents(storage_path('logs/http-frenado-en-tests.log'), $lineas, FILE_APPEND);
        } catch (\Throwable $excepcion) {
            // Es un registro de auditoría: si no se puede escribir, el test sigue igual.
        }
    }

    /**
     * Aborta la suite entera si la conexión activa apunta a una base que no
     * es de testing.
     *
     * Lee el nombre real de la conexión activa (DB::connection()->getDatabaseName()),
     * no la variable de entorno: lo que importa es contra qué se va a escribir de
     * verdad, no lo que alguien creyó configurar (un <server> de phpunit.xml puede
     * estar pisando el .env.testing).
     *
     * El chequeo en sí se computa una sola vez por proceso. Pero si falla, el
     * error se re-lanza en cada test subsiguiente (barato: no vuelve a leer nada,
     * solo repite el mensaje cacheado) para que la suite entera aborte en vez de
     * fallar un solo test y seguir corriendo el resto contra la base equivocada.
     *
     * @return void
     */
    protected function guardBaseDeTesting()
    {
        if (!static::$baseDeTestingVerificada) {
            static::$baseDeTestingVerificada = true;
            static::$errorDeGuardDeTesting = $this->calcularErrorDeGuardDeTesting();
        }

        if (static::$errorDeGuardDeTesting !== null) {
            throw new \RuntimeException(static::$errorDeGuardDeTesting);
        }
    }

    /**
     * @return string|null Mensaje de error si la base activa no es segura, null si está OK.
     */
    protected function calcularErrorDeGuardDeTesting()
    {
        $nombreBaseActiva = (string) DB::connection()->getDatabaseName();
        $nombreBaseActivaMinuscula = strtolower($nombreBaseActiva);

        $noContieneTest = strpos($nombreBaseActivaMinuscula, 'test') === false;

        if (!$noContieneTest) {
            return null;
        }

        return "Guard de base de testing: se abortó la suite antes de correr tests contra una base insegura.\n"
            . "Base detectada (conexión activa): \"{$nombreBaseActiva}\".\n"
            . "Problema: el nombre \"{$nombreBaseActiva}\" no contiene la cadena \"test\", así que no parece "
            . "ser una base de testing segura.\n"
            . "Qué hacer: revisá el DB_DATABASE de tu .env.testing y confirmá que phpunit.xml no tenga "
            . "un <server name=\"DB_DATABASE\"> hardcodeado que lo esté pisando.";
    }
}
