<?php

namespace Tests\Feature\Seguridad\AdminSync;

/**
 * Cómo `config/services.php` convierte `ADMIN_SYNC_REQUIRE_API_KEY` en `services.admin_api.require_api_key`
 * (misión cerrar-admin-sync, 10/10/2026): tri-estado.
 *
 *   - sin definir o vacía  => null  (automático: exige si la instancia tiene clave)
 *   - true / 1 / on / yes  => true
 *   - false / 0 / off / no => false
 *   - un texto que no se entiende ('banana') => null (ante la duda, el automático, que es el lado que cierra)
 *
 * Se evalúa el `config/services.php` de verdad con la variable puesta (putenv, que `env()` lee) y se
 * restaura la que había, mismo recurso que BlindajeDelUserSetupTest. No toca la configuración de la app.
 */
class La_variable_de_entorno_pasa_a_la_config_Test extends AdminSyncTestCase
{
    /**
     * Lo que valdría `services.admin_api.require_api_key` con esa variable de entorno.
     *
     * @param  string|null $valor  Texto de la variable, o null para "sin definir".
     * @return mixed
     */
    protected function config_segun_la_variable($valor)
    {
        $nombre   = 'ADMIN_SYNC_REQUIRE_API_KEY';
        $anterior = getenv($nombre);

        try {
            if ($valor === null) {
                putenv($nombre);
            } else {
                putenv($nombre . '=' . $valor);
            }

            $services = require config_path('services.php');

            return $services['admin_api']['require_api_key'];
        } finally {
            if ($anterior === false) {
                putenv($nombre);
            } else {
                putenv($nombre . '=' . $anterior);
            }
        }
    }

    /** @test */
    public function sin_definir_o_vacia_es_null()
    {
        $this->assertNull($this->config_segun_la_variable(null));
        $this->assertNull($this->config_segun_la_variable(''));
        $this->assertNull($this->config_segun_la_variable('   '));
    }

    /** @test */
    public function los_valores_verdaderos_son_true()
    {
        foreach (['true', 'TRUE', '1', 'on', 'yes'] as $valor) {
            $this->assertSame(true, $this->config_segun_la_variable($valor), $valor);
        }
    }

    /** @test */
    public function los_valores_falsos_son_false()
    {
        foreach (['false', 'FALSE', '0', 'off', 'no'] as $valor) {
            $this->assertSame(false, $this->config_segun_la_variable($valor), $valor);
        }
    }

    /** @test */
    public function un_texto_que_no_se_entiende_es_null_y_no_abre_la_puerta()
    {
        $this->assertNull($this->config_segun_la_variable('banana'));
    }
}
