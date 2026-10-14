<?php

namespace Tests\Feature\Seguridad\AdminSync;

use App\Http\Middleware\AdminApiKey;
use Illuminate\Http\Request;

/**
 * `AdminApiKey` con los cuatro estados de `ADMIN_SYNC_REQUIRE_API_KEY` x (sin header / header malo /
 * header bueno) (misión cerrar-admin-sync, 10/10/2026).
 *
 * EL CASO: `AdminApiKey::handle()` no validaba NADA mientras la variable no estuviera en `true`, y en
 * producción nadie la define: las 43 rutas `api/admin-sync/*` respondían sin clave aunque la instancia
 * tuviera `ADMIN_API_INBOUND_KEY` cargada. Ahora:
 *
 *   - `true`                         => exige.
 *   - `false`                        => no exige (apagado a propósito; es el estado que se respeta tal cual).
 *   - sin definir (`null`) + clave   => EXIGE (lo nuevo).
 *   - sin definir (`null`) sin clave => no exige (no hay con qué comparar: demos y entornos de test).
 *
 * Se prueba el middleware solo, con un Request armado a mano y un `$next` que marca que se llegó al
 * controlador: sin ruta de por medio. El recorrido de las rutas reales está en el test 2.
 */
class El_middleware_con_los_cuatro_estados_Test extends AdminSyncTestCase
{
    /**
     * Pasa un request por el middleware.
     *
     * @param  string|null $header  Valor de X-Admin-Api-Key, o null para no mandarlo.
     * @return array{respuesta: \Symfony\Component\HttpFoundation\Response, llego: bool}
     */
    protected function pasar($header)
    {
        $request = Request::create('/api/admin-sync/employees', 'GET');

        if ($header !== null) {
            $request->headers->set('X-Admin-Api-Key', $header);
        }

        $llego = false;

        $respuesta = (new AdminApiKey())->handle($request, function () use (&$llego) {
            $llego = true;

            return response()->json(['ok' => true], 200);
        });

        return ['respuesta' => $respuesta, 'llego' => $llego];
    }

    /**
     * Asegura que el request fue rechazado con el 401 de siempre y no llegó al controlador.
     *
     * @param  array $resultado
     * @return void
     */
    protected function assert_rechazado(array $resultado)
    {
        $this->assertFalse($resultado['llego'], 'El request llegó al controlador sin la clave.');
        $this->assertSame(401, $resultado['respuesta']->getStatusCode());
        $this->assertSame('{"error":"unauthorized"}', $resultado['respuesta']->getContent());
    }

    /**
     * @param  array $resultado
     * @return void
     */
    protected function assert_pasa(array $resultado)
    {
        $this->assertTrue($resultado['llego'], 'El request no llegó al controlador.');
        $this->assertSame(200, $resultado['respuesta']->getStatusCode());
    }

    // --- true: exige ----------------------------------------------------------------------------

    /** @test */
    public function con_la_variable_en_true_y_sin_header_es_401()
    {
        $this->configurar(true, self::CLAVE);

        $this->assert_rechazado($this->pasar(null));
    }

    /** @test */
    public function con_la_variable_en_true_y_header_malo_es_401()
    {
        $this->configurar(true, self::CLAVE);

        $this->assert_rechazado($this->pasar(self::CLAVE_MALA));
    }

    /** @test */
    public function con_la_variable_en_true_y_header_bueno_pasa()
    {
        $this->configurar(true, self::CLAVE);

        $this->assert_pasa($this->pasar(self::CLAVE));
    }

    /** @test */
    public function con_la_variable_en_true_y_la_instancia_sin_clave_es_401_aunque_el_header_traiga_algo()
    {
        $this->configurar(true, null);

        $this->assert_rechazado($this->pasar(null));
        $this->assert_rechazado($this->pasar(self::CLAVE_MALA));
        $this->assert_rechazado($this->pasar(''));
    }

    // --- sin definir + clave cargada: exige (lo nuevo) -----------------------------------------

    /** @test */
    public function sin_definir_la_variable_y_con_clave_cargada_sin_header_es_401()
    {
        $this->configurar(null, self::CLAVE);

        $this->assert_rechazado($this->pasar(null));
    }

    /** @test */
    public function sin_definir_la_variable_y_con_clave_cargada_con_header_malo_es_401()
    {
        $this->configurar(null, self::CLAVE);

        $this->assert_rechazado($this->pasar(self::CLAVE_MALA));
    }

    /** @test */
    public function sin_definir_la_variable_y_con_clave_cargada_con_header_bueno_pasa()
    {
        $this->configurar(null, self::CLAVE);

        $this->assert_pasa($this->pasar(self::CLAVE));
    }

    // --- sin definir + sin clave: igual que antes ---------------------------------------------

    /** @test */
    public function sin_definir_la_variable_y_sin_clave_cargada_pasa_con_cualquier_header()
    {
        foreach ([null, '', '   '] as $clave_vacia) {
            $this->configurar(null, $clave_vacia);

            $this->assert_pasa($this->pasar(null));
            $this->assert_pasa($this->pasar(self::CLAVE_MALA));
            $this->assert_pasa($this->pasar(self::CLAVE));
        }
    }

    // --- false: apagado a propósito -----------------------------------------------------------

    /** @test */
    public function con_la_variable_en_false_pasa_sin_header_header_malo_y_header_bueno()
    {
        $this->configurar(false, self::CLAVE);

        $this->assert_pasa($this->pasar(null));
        $this->assert_pasa($this->pasar(self::CLAVE_MALA));
        $this->assert_pasa($this->pasar(self::CLAVE));
    }

    /** @test */
    public function con_la_variable_en_false_y_sin_clave_cargada_tambien_pasa()
    {
        $this->configurar(false, null);

        $this->assert_pasa($this->pasar(null));
        $this->assert_pasa($this->pasar(self::CLAVE_MALA));
    }
}
