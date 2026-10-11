<?php

namespace Tests\Feature\Seguridad\AdminSync;

use App\Http\Middleware\AdminApiKey;
use Illuminate\Http\Request;

/**
 * La clave de la instancia se normaliza antes de decidir si "hay clave" y antes de comparar (misión
 * cerrar-admin-sync, 11/10/2026, hallazgos del verificador independiente).
 *
 *   - Una clave con espacios o `\r` en los extremos (un `.env` guardado con CRLF) NO puede dejar la
 *     puerta cerrada para siempre: el admin manda la clave limpia y tiene que entrar.
 *   - Una clave que `env()` convierta en booleano (literalmente `true` o `false`) NO puede dejar la
 *     instancia abierta: sigue exigiendo.
 */
class La_clave_de_la_instancia_se_normaliza_Test extends AdminSyncTestCase
{
    /**
     * @param  string|null $header
     * @return int Código HTTP con el que respondió el middleware.
     */
    protected function codigo($header)
    {
        $request = Request::create('/api/admin-sync/employees', 'GET');

        if ($header !== null) {
            $request->headers->set('X-Admin-Api-Key', $header);
        }

        return (new AdminApiKey())->handle($request, function () {
            return response()->json(['ok' => true], 200);
        })->getStatusCode();
    }

    /** @test */
    public function una_clave_de_instancia_con_espacios_o_cr_igual_deja_entrar_a_la_clave_limpia()
    {
        foreach (["  " . self::CLAVE . "  ", self::CLAVE . "\r", self::CLAVE . "\r\n", "\t" . self::CLAVE] as $guardada) {
            $this->configurar(null, $guardada);

            $this->assertSame(200, $this->codigo(self::CLAVE), 'La clave limpia no entró con la clave de la instancia sucia.');
            $this->assertSame(401, $this->codigo(self::CLAVE_MALA));
            $this->assertSame(401, $this->codigo(null));
        }
    }

    /** @test */
    public function una_clave_que_env_convirtio_en_booleano_no_deja_la_instancia_abierta()
    {
        $this->configurar(null, true);
        $this->assertSame(401, $this->codigo(null), 'Con la clave literal "true" la instancia quedó abierta.');
        $this->assertSame(401, $this->codigo(self::CLAVE_MALA));
        $this->assertSame(200, $this->codigo('true'));

        $this->configurar(null, false);
        $this->assertSame(401, $this->codigo(null), 'Con la clave literal "false" la instancia quedó abierta.');
        $this->assertSame(200, $this->codigo('false'));
    }

    /** @test */
    public function una_clave_vacia_o_solo_espacios_sigue_sin_exigir_como_hoy()
    {
        foreach ([null, '', '   ', "\r\n"] as $guardada) {
            $this->configurar(null, $guardada);

            $this->assertSame(200, $this->codigo(null), 'Sin clave cargada la instancia tiene que seguir como hoy.');
        }
    }

    /** @test */
    public function con_la_variable_en_true_y_sin_clave_cargada_siempre_es_401()
    {
        foreach ([null, '', '  '] as $guardada) {
            $this->configurar(true, $guardada);

            $this->assertSame(401, $this->codigo(null));
            $this->assertSame(401, $this->codigo(self::CLAVE));
        }
    }
}
