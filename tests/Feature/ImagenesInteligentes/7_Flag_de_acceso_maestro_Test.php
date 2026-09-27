<?php

namespace Tests\Feature\ImagenesInteligentes;

/**
 * El flag `es_acceso_maestro` del usuario (contrato §5.3): con él la SPA muestra "Buscar imágenes
 * para todo el catálogo" solo en la sesión del login maestro. Se prueban las dos ramas de
 * AuthController::get_user(): la del login maestro (sin candado de sesión) y la normal.
 */
class Flag_de_acceso_maestro_Test extends ImagenesInteligentesTestCase
{
    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function una_sesion_normal_trae_el_flag_en_false()
    {
        $respuesta = $this->getJson('api/user');

        $respuesta->assertStatus(200);
        $this->assertSame((int) $this->owner->id, (int) $respuesta->json('user.id'));
        $this->assertFalse($respuesta->json('user.es_acceso_maestro'));
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function la_sesion_del_login_maestro_trae_el_flag_en_true()
    {
        $this->actuar_como($this->owner, true);

        $respuesta = $this->getJson('api/user');

        $respuesta->assertStatus(200);
        $this->assertSame((int) $this->owner->id, (int) $respuesta->json('user.id'));
        $this->assertTrue($respuesta->json('user.es_acceso_maestro'));
    }
}
