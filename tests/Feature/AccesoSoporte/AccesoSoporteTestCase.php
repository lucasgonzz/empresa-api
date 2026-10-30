<?php

namespace Tests\Feature\AccesoSoporte;

use App\Http\Controllers\Helpers\AccesoSoporteHelper;
use App\Models\AccesoSoporteToken;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Base de los tests de la misión acceso-soporte-firmado (30/10/2026): el acceso de soporte de un solo uso que el
 * admin pide con la clave compartida y que el navegador canjea por una sesión del dueño.
 *
 * Usa `DatabaseTransactions` (no `RefreshDatabase`): la base de testing del slot ya está sembrada, con el
 * usuario 500 como dueño. Cada test deja la configuración en un estado conocido en `setUp()` y no confía en lo
 * que traiga el `.env.testing`.
 *
 * Captura TODO lo que se loguea durante el test (`$this->logs`) para poder afirmar que el token en claro no
 * aparece en ningún log.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
abstract class AccesoSoporteTestCase extends TestCase
{
    use DatabaseTransactions;

    /** La clave de admin «de la instancia» para estos tests (ADMIN_API_INBOUND_KEY). */
    const CLAVE = 'clave-de-admin-del-test-de-acceso-soporte-7c1e9a';

    /** Un valor de header que NO es la clave. */
    const CLAVE_MALA = 'otra-clave-que-no-es-la-de-la-instancia-1111';

    /** Ruta de emisión (admin → cliente). */
    const URL_EMISION = '/api/admin-sync/acceso-soporte';

    /** Ruta de canje (navegador → cliente). */
    const URL_CANJE = '/api/acceso-soporte/canjear';

    /** Respuesta idéntica de TODO rechazo del canje. */
    const CUERPO_401 = '{"error":"token_invalido"}';

    /** @var \App\Models\User El dueño de la base de testing (usuario 500). */
    protected $dueno;

    /** @var array<int, array{nivel: string, mensaje: string, contexto: array}> Lo que se logueó durante el test. */
    protected $logs = [];

    /**
     * Deja el escenario en un estado conocido: dueño 500, clave de la instancia cargada, acceso de soporte
     * prendido con TTL de 120 s y un Referer del dominio stateful (sin ese header no hay sesión en el request).
     *
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

        $this->dueno = User::find(500);

        if (is_null($this->dueno)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        config([
            'services.admin_api.api_key'         => self::CLAVE,
            'services.admin_api.require_api_key' => null,
            'app.USER_ID'                        => 500,
            'acceso_soporte.habilitado'          => true,
            'acceso_soporte.ttl_segundos'        => 120,
        ]);

        $this->withHeader('Referer', rtrim((string) config('app.url'), '/') . '/');
    }

    /**
     * Los headers de un pedido del admin con la clave dada.
     *
     * @param  string $valor
     * @return array<string, string>
     */
    protected function con_clave($valor = self::CLAVE)
    {
        return ['X-Admin-Api-Key' => $valor];
    }

    /**
     * Emite un token de verdad (por el helper) para el dueño y devuelve el claro y su fila.
     *
     * @param  string|null $solicitante
     * @param  string|null $motivo
     * @return array{0: string, 1: AccesoSoporteToken} [token en claro, fila]
     */
    protected function emitir_token($solicitante = 'test', $motivo = 'prueba')
    {
        $token = AccesoSoporteHelper::emitir($this->dueno, $solicitante, $motivo, '10.0.0.9');

        $fila = AccesoSoporteToken::where('token_hash', hash('sha256', $token))->first();

        return [$token, $fila];
    }

    /**
     * Todo lo que se logueó, en un solo texto (nivel + mensaje + contexto), para buscar filtraciones.
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
     * Cantidad de filas que hay hoy en la tabla de accesos de soporte.
     *
     * @return int
     */
    protected function cantidad_de_tokens()
    {
        return AccesoSoporteToken::count();
    }
}
