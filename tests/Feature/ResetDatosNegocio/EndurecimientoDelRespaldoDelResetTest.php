<?php

namespace Tests\Feature\ResetDatosNegocio;

use Tests\Feature\ResetDatosNegocio\Concerns\BaseDescartableParaElReset;
use Tests\TestCase;

/**
 * Endurecimiento del respaldo del reset (misión reset-datos-cliente, ronda posterior a la revisión 1).
 *
 * El respaldo `.sql.gz` trae la tabla `users` completa, así que la carpeta donde vive no puede servirse por web:
 * se escribe un `.htaccess` que niega todo y un `index.html` vacío, el archivo queda con permisos de sólo el
 * dueño, y la retención de los 3 respaldos más nuevos sigue andando con esos archivos de protección al lado.
 *
 * Corre sobre la base descartable y con el storage redirigido (ver BaseDescartableParaElReset).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados ni union types.
 */
class EndurecimientoDelRespaldoDelResetTest extends TestCase
{
    use BaseDescartableParaElReset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->iniciar_entorno_del_reset();
    }

    protected function tearDown(): void
    {
        $this->terminar_entorno_del_reset();
        parent::tearDown();
    }

    /**
     * Carpeta absoluta de los respaldos del reset (dentro del storage redirigido del test).
     *
     * @return string
     */
    protected function carpeta_de_respaldos()
    {
        return storage_path('app/respaldos-reset-datos');
    }

    /** @test */
    public function test_la_carpeta_de_respaldos_queda_protegida_de_la_web()
    {
        $this->simular_y_ejecutar_el_reset()->assertStatus(200);

        $htaccess = $this->carpeta_de_respaldos() . '/.htaccess';
        $this->assertFileExists($htaccess);
        $contenido = file_get_contents($htaccess);
        $this->assertStringContainsString('Require all denied', $contenido, 'Apache 2.4.');
        $this->assertStringContainsString('Deny from all', $contenido, 'Apache 2.2.');

        $index = $this->carpeta_de_respaldos() . '/index.html';
        $this->assertFileExists($index);
        $this->assertSame('', file_get_contents($index), 'El index.html está vacío.');
    }

    /** @test */
    public function test_la_proteccion_no_pisa_un_htaccess_que_ya_existe()
    {
        $token = $this->obtener_un_token_de_simulacion();

        mkdir($this->carpeta_de_respaldos(), 0775, true);
        file_put_contents($this->carpeta_de_respaldos() . '/.htaccess', "# mío\n");
        file_put_contents($this->carpeta_de_respaldos() . '/index.html', 'ya estaba');

        $this->ejecutar_el_reset($token)->assertStatus(200);

        $this->assertSame("# mío\n", file_get_contents($this->carpeta_de_respaldos() . '/.htaccess'));
        $this->assertSame('ya estaba', file_get_contents($this->carpeta_de_respaldos() . '/index.html'));
    }

    /** @test */
    public function test_el_respaldo_queda_legible_solo_por_su_dueno()
    {
        $this->simular_y_ejecutar_el_reset()->assertStatus(200);

        $respaldos = glob($this->carpeta_de_respaldos() . '/*.sql.gz');
        $this->assertCount(1, $respaldos);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($respaldos[0])), -4));
    }

    /** @test */
    public function test_la_retencion_sigue_conservando_tres_respaldos_con_la_proteccion_al_lado()
    {
        $token = $this->obtener_un_token_de_simulacion();

        // Cuatro respaldos viejos, con el formato de siempre.
        mkdir($this->carpeta_de_respaldos(), 0775, true);
        foreach (['20260101-000000', '20260201-000000', '20260301-000000', '20260401-000000'] as $stamp) {
            file_put_contents($this->carpeta_de_respaldos() . '/' . $stamp . '-' . $this->base_descartable . '.sql.gz', gzencode('-- viejo ' . $stamp));
        }

        $this->ejecutar_el_reset($token)->assertStatus(200);

        $nombres = array_map('basename', glob($this->carpeta_de_respaldos() . '/*.sql.gz'));
        $this->assertCount(3, $nombres, 'Se conservan 3 respaldos: ' . implode(', ', $nombres));
        $this->assertNotContains('20260101-000000-' . $this->base_descartable . '.sql.gz', $nombres);
        $this->assertNotContains('20260201-000000-' . $this->base_descartable . '.sql.gz', $nombres);

        // Los archivos de protección no los toca la retención.
        $this->assertFileExists($this->carpeta_de_respaldos() . '/.htaccess');
        $this->assertFileExists($this->carpeta_de_respaldos() . '/index.html');
    }
}
