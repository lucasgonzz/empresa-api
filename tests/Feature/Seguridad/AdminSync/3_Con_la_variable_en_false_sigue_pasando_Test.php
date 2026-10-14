<?php

namespace Tests\Feature\Seguridad\AdminSync;

/**
 * `ADMIN_SYNC_REQUIRE_API_KEY=false` sigue significando "no exijas la clave" (misión cerrar-admin-sync,
 * 10/10/2026).
 *
 * Este test FIJA un comportamiento que la misión deliberadamente no cambia: quien puso la variable en
 * `false` a propósito la apagó, y hoy producción puede tener instancias así. El cambio de la misión es
 * solo para la variable SIN DEFINIR. Si alguien quiere endurecer también el `false`, que lo decida a
 * propósito y rompa este test, no que se cuele de rebote.
 *
 * Se prueba por HTTP contra rutas reales de lectura (no escriben nada) con el cuerpo vacío.
 */
class Con_la_variable_en_false_sigue_pasando_Test extends AdminSyncTestCase
{
    /**
     * Rutas de lectura del grupo, representativas de cada familia.
     *
     * @return array<int, string>
     */
    protected function rutas_de_lectura()
    {
        return [
            '/api/admin-sync/employees',
            '/api/admin-sync/mensualidad-info',
            '/api/admin-sync/contacto-dueno',
            '/api/admin-sync/mostrador/duenos',
        ];
    }

    /** @test */
    public function en_false_sin_header_no_es_401_aunque_la_instancia_tenga_clave()
    {
        $this->configurar(false, self::CLAVE);

        foreach ($this->rutas_de_lectura() as $url) {
            $respuesta = $this->json('GET', $url);

            $this->assertNotSame(401, $respuesta->getStatusCode(), $url . ' dio 401 con la variable en false.');
        }
    }

    /** @test */
    public function en_false_con_header_malo_no_es_401()
    {
        $this->configurar(false, self::CLAVE);

        foreach ($this->rutas_de_lectura() as $url) {
            $respuesta = $this->withHeaders($this->con_clave(self::CLAVE_MALA))->json('GET', $url);

            $this->assertNotSame(401, $respuesta->getStatusCode(), $url . ' dio 401 con la variable en false.');
        }
    }

    /** @test */
    public function en_false_no_se_loguea_ningun_rechazo()
    {
        $this->configurar(false, self::CLAVE);

        $this->json('GET', '/api/admin-sync/employees');

        $this->assertCount(0, $this->rechazos_logueados());
    }
}
