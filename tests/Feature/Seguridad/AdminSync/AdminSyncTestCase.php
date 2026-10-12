<?php

namespace Tests\Feature\Seguridad\AdminSync;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Base de los tests de la misión cerrar-admin-sync (10/10/2026): las rutas `api/admin-sync/*` que pasan por
 * `admin.api.key` (App\Http\Middleware\AdminApiKey) dejan de depender de que alguien prenda
 * ADMIN_SYNC_REQUIRE_API_KEY: con la clave de la instancia cargada (ADMIN_API_INBOUND_KEY) la exigen solas.
 *
 * Usa `DatabaseTransactions` y NO se loguea con `actingAs()`: lo que se prueba es justamente el pedido de
 * quien no es el admin. Ninguna ruta de este grupo hace `migrate:fresh` (eso es `user-setup`/`demo-setup`,
 * que están fuera del grupo y fuera de estos tests).
 *
 * 🔴 NO se confía en lo que traiga el `.env.testing`: cada test deja la clave y la variable en un estado
 * conocido con `configurar()`.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
abstract class AdminSyncTestCase extends TestCase
{
    use DatabaseTransactions;

    /** La clave de admin "de la instancia" para estos tests. */
    const CLAVE = 'clave-de-admin-sync-del-test-5d1f0c7a9e3b';

    /** Un valor de header que NO es la clave. */
    const CLAVE_MALA = 'otra-clave-que-no-es-la-de-la-instancia-0000';

    /** @var array<int, array{nivel: string, mensaje: string, contexto: array}> Lo que se logueó durante el test. */
    protected $logs = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logs = [];

        $test = $this;

        Event::listen(MessageLogged::class, function (MessageLogged $evento) use ($test) {
            $test->logs[] = [
                'nivel'    => (string) $evento->level,
                'mensaje'  => (string) $evento->message,
                'contexto' => (array) $evento->context,
            ];
        });
    }

    /**
     * Deja la configuración de la puerta en un estado conocido.
     *
     * @param  bool|null   $flag   services.admin_api.require_api_key (null = variable sin definir).
     * @param  string|null $clave  services.admin_api.api_key (la de la instancia; null/'' = sin clave).
     * @return void
     */
    protected function configurar($flag, $clave)
    {
        config([
            'services.admin_api.require_api_key' => $flag,
            'services.admin_api.api_key'         => $clave,
        ]);
    }

    /**
     * Los headers de un request del admin con la clave dada.
     *
     * @param  string $valor
     * @return array<string, string>
     */
    protected function con_clave($valor)
    {
        return ['X-Admin-Api-Key' => $valor];
    }

    /**
     * Todo lo que se logueó, en un solo texto (mensaje + contexto), para buscar filtraciones.
     *
     * @return string
     */
    protected function logs_como_texto()
    {
        $texto = '';

        foreach ($this->logs as $linea) {
            $texto .= $linea['nivel'] . ' ' . $linea['mensaje'] . ' ' . json_encode($linea['contexto']) . "\n";
        }

        return $texto;
    }

    /**
     * Las líneas de log del rechazo.
     *
     * @return array<int, array>
     */
    protected function rechazos_logueados()
    {
        $rechazos = [];

        foreach ($this->logs as $linea) {
            if ($linea['nivel'] === 'warning' && strpos($linea['mensaje'], 'rechazado por falta de clave de admin') !== false) {
                $rechazos[] = $linea;
            }
        }

        return $rechazos;
    }
}
