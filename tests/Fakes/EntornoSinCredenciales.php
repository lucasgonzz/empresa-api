<?php

namespace Tests\Fakes;

/**
 * Las credenciales REALES de servicios externos no entran a los tests.
 *
 * 🔴 POR QUÉ (6/10/2026). El `.env.testing` de cada slot es una copia del `.env` de desarrollo de Lucas
 * (`setup-pool.ps1`), y trae las claves reales de Anthropic y OpenAI (que cobran por uso), el token de
 * Tienda Nube, el de Mercado Libre y el de Mercado Pago, y la cuenta de un SMTP. Laravel carga ese archivo
 * ENCIMA de lo que el test crea, así que un pedido que se escapara del freno de internet
 * (`HttpFactorySinSalida`, `StreamHttpSinSalida`, el proxy muerto de `phpunit.xml`) saldría AUTENTICADO como
 * Lucas. El 27/9/2026 pasó con Serper: un test recorrió las herramientas del asistente con la cola en `sync`,
 * corrió el job de imágenes en línea y salió a buscar (pago) con la clave real del slot.
 *
 * Qué hace: antes de que arranque la aplicación de CADA test (`CreatesApplication::createApplication()`),
 * deja con `putenv()` un valor de mentira para cada credencial de la lista. Como el dotenv de Laravel es
 * inmutable, lo que ya está definido (en `$_SERVER`, `$_ENV` o `putenv()`) no se pisa con el `.env.testing`.
 *
 * 🔴 `Illuminate\Support\Env` lee `$_SERVER`, después `$_ENV` y recién al final `putenv()` (medido el 6/10/2026 en
 * Laravel 8.83 y en 10.50). Por eso, antes de cada arranque las credenciales viven SOLO en `putenv()`: lo que haya en
 * `$_SERVER` o en `$_ENV` con ese nombre se BORRA (una clave real definida como variable de entorno de Windows llega ahí
 * cuando arranca PHP, y también un `<server name="SERPER_API_KEY" value=""/>` de `phpunit.xml`, o lo que dejó el test
 * anterior). Así la regla es siempre la misma: un test que necesita una clave concreta la pone con `$_SERVER`, `$_ENV`,
 * `putenv()` o `config()` —después del arranque— y gana sobre el valor de mentira.
 *
 * 🔴 Por qué en cada test y no una vez en `phpunit.xml`: hay tests que simulan el entorno con `putenv()` y
 * `$_ENV[...]` y, al terminar, BORRAN la variable (`TiendaNubePedidosSyncTest`, `44_Foto_de_articulo_Test`…).
 * Borrada, el arranque del test siguiente vuelve a cargar el valor real desde `.env.testing`. Reaplicarlo en
 * cada `createApplication()` hace que ningún test pueda dejarle el valor real al siguiente.
 *
 * Dos criterios para el valor:
 *  - Una credencial que hoy está PRESENTE en los `.env.testing` pasa a un valor de mentira NO vacío: el código
 *    sigue por el mismo camino "con clave" (los tests que falsean la llamada con `Http::fake()` no notan nada),
 *    pero lo que salga nunca va autenticado.
 *  - Una que puede aparecer en un slot nuevo (la plantilla se copia del `.env` de Lucas el día que se arma cada
 *    slot) se deja VACÍA: es lo que ven los slots que no la tienen, así la suite da lo mismo en todos.
 *
 * Los procesos hijos: heredan `getenv()`, así que reciben las de mentira, pero NO las vacías (`proc_open` descarta
 * los valores vacíos de un entorno armado a mano) y un hijo que arranca sin la variable la vuelve a leer del
 * `.env.testing`. Los tests que lanzan procesos (`CategoryProposals`) suman `para_procesos_hijos()` a su entorno.
 *
 * Qué NO hace: no toca `ADMIN_API_*` (son las claves de un admin local que algunos tests usan como parte del
 * contrato) ni las URL. Tampoco las credenciales que viven en filas de la base (la casilla SMTP de
 * `online_configurations`, `users.google_custom_search_api_key`): hoy las bases de testing no traen ninguna con valor, y el
 * SMTP de la casilla del comercio lo cubre `Tests\TestCase` con un transporte en memoria. Y un test que necesita una
 * clave la pone él con `config([...])`, `putenv()`, `$_ENV` o `$_SERVER`: eso corre DESPUÉS de este arranque y gana.
 */
class EntornoSinCredenciales
{
    /**
     * Valor de mentira que reemplaza a las credenciales presentes. No es una clave válida de nadie.
     */
    const SIN_CREDENCIAL_REAL = 'sin-credencial-real-en-tests';

    /**
     * Credenciales que están presentes (con un valor real) en el `.env.testing` de los slots: se reemplazan por un
     * valor de mentira no vacío.
     *
     * @var array<string, string>
     */
    const REEMPLAZADAS = [
        'ANTHROPIC_API_KEY'           => 'sk-ant-' . self::SIN_CREDENCIAL_REAL,
        'OPENAI_API_KEY'              => 'sk-' . self::SIN_CREDENCIAL_REAL,
        'TN_ACCESS_TOKEN'             => self::SIN_CREDENCIAL_REAL,
        'MERCADO_LIBRE_CLIENT_SECRET' => self::SIN_CREDENCIAL_REAL,
        'MERCADO_LIBRE_TOKEN'         => self::SIN_CREDENCIAL_REAL,
        'MERCADO_PAGO_ACCESS_TOKEN'   => self::SIN_CREDENCIAL_REAL,
        'MAIL_USERNAME'               => self::SIN_CREDENCIAL_REAL,
        'MAIL_PASSWORD'               => self::SIN_CREDENCIAL_REAL,
        'PUSHER_APP_KEY'              => self::SIN_CREDENCIAL_REAL,
        'PUSHER_APP_SECRET'           => self::SIN_CREDENCIAL_REAL,
    ];

    /**
     * Credenciales que pueden aparecer en un slot nuevo: se dejan vacías.
     *
     * @var array<int, string>
     */
    const VACIAS = [
        'DEEPSEEK_API_KEY',
        'GOOGLE_SEARCH_API_KEY',
        'SERPER_API_KEY',
        'GITHUB_ERROR_REPORTER_TOKEN',
    ];

    /**
     * Todas las variables que este arranque neutraliza, con el valor que les deja.
     *
     * @return array<string, string>
     */
    public static function valores(): array
    {
        $valores = self::REEMPLAZADAS;

        foreach (self::VACIAS as $nombre) {
            $valores[$nombre] = '';
        }

        return $valores;
    }

    /**
     * Lo mismo que `valores()`, pero con TODOS los valores no vacíos: es lo que se le pasa a un proceso hijo
     * (`proc_open` con un entorno armado a mano descarta los valores vacíos, y el hijo volvería a leer la clave
     * real del `.env.testing`).
     *
     * @return array<string, string>
     */
    public static function para_procesos_hijos(): array
    {
        $valores = [];

        foreach (self::valores() as $nombre => $valor) {
            $valores[$nombre] = $valor === '' ? self::SIN_CREDENCIAL_REAL : $valor;
        }

        return $valores;
    }

    /**
     * Deja las credenciales neutralizadas en el entorno del proceso: el valor de mentira en `putenv()` y nada en `$_SERVER` ni en
     * `$_ENV` (ver el docblock de la clase). Se llama en cada arranque de la aplicación de test.
     *
     * @return void
     */
    public static function aplicar(): void
    {
        foreach (self::valores() as $nombre => $valor) {
            unset($_SERVER[$nombre], $_ENV[$nombre]);

            putenv($nombre . '=' . $valor);
        }
    }
}
