<?php

namespace Tests\Feature\Seguridad\AdminSync;

/**
 * Cada 401 de `AdminApiKey` deja un `Log::warning` con la ruta, el método, la IP y si traía el header, y
 * NUNCA el valor del header ni la clave de la instancia (misión cerrar-admin-sync, 10/10/2026).
 *
 * Para qué: apenas se cierra la puerta, el log es la red para descubrir a los llamadores que nadie vio (un
 * n8n, un script a mano) y no mandan la clave. Pero un log con la clave adentro sería peor que el problema:
 * `laravel.log` lo leen sesiones y herramientas que no tienen por qué ver el secreto.
 */
class El_401_deja_log_sin_secretos_Test extends AdminSyncTestCase
{
    /** @test */
    public function un_401_sin_header_deja_un_warning_con_la_ruta_y_sin_header()
    {
        $this->configurar(null, self::CLAVE);

        $this->json('GET', '/api/admin-sync/mensualidad-info/7')->assertStatus(401);

        $rechazos = $this->rechazos_logueados();

        $this->assertCount(1, $rechazos);
        $contexto = $rechazos[0]['contexto'];
        $this->assertSame('api/admin-sync/mensualidad-info/{user_id?}', $contexto['ruta']);
        $this->assertSame('GET', $contexto['metodo']);
        $this->assertArrayHasKey('ip', $contexto);
        $this->assertFalse($contexto['con_header']);
    }

    /** @test */
    public function un_401_con_header_malo_marca_con_header_true()
    {
        $this->configurar(null, self::CLAVE);

        $this->withHeaders($this->con_clave(self::CLAVE_MALA))->json('PUT', '/api/admin-sync/session-lock', [])->assertStatus(401);

        $rechazos = $this->rechazos_logueados();

        $this->assertCount(1, $rechazos);
        $this->assertTrue($rechazos[0]['contexto']['con_header']);
        $this->assertSame('PUT', $rechazos[0]['contexto']['metodo']);
        $this->assertSame('api/admin-sync/session-lock', $rechazos[0]['contexto']['ruta']);
    }

    /** @test */
    public function el_log_del_401_no_contiene_ni_el_header_ni_la_clave()
    {
        $this->configurar(null, self::CLAVE);

        $this->withHeaders($this->con_clave(self::CLAVE_MALA))->json('GET', '/api/admin-sync/employees')->assertStatus(401);
        $this->json('GET', '/api/admin-sync/employees')->assertStatus(401);

        $texto = $this->logs_como_texto();

        $this->assertNotSame('', $texto, 'No se logueó nada: el test no prueba nada.');
        $this->assertStringNotContainsString(self::CLAVE, $texto, 'El log contiene la clave de la instancia.');
        $this->assertStringNotContainsString(self::CLAVE_MALA, $texto, 'El log contiene el valor del header recibido.');
    }

    /** @test */
    public function un_pedido_con_la_clave_correcta_no_deja_rechazo_en_el_log()
    {
        $this->configurar(null, self::CLAVE);

        $this->withHeaders($this->con_clave(self::CLAVE))->json('GET', '/api/admin-sync/employees');

        $this->assertCount(0, $this->rechazos_logueados());
        $this->assertStringNotContainsString(self::CLAVE, $this->logs_como_texto());
    }
}
