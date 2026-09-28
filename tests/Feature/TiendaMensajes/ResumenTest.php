<?php

namespace Tests\Feature\TiendaMensajes;

use Carbon\Carbon;
use Tests\EmpresaTestCase;

/**
 * `GET tienda-chats/resumen` (misión mensajes-tienda-online, 28/9/2026, contrato C3): lo que
 * alimenta los badges de Tienda Online y Alertas desde el login, y las tarjetas del submódulo.
 *
 * `{ chats_no_leidos: int, mensajes_no_leidos: int, conversaciones_hoy: int }`, solo con los
 * compradores del comercio, y enteros de verdad (`assertSame`).
 *
 * PHP 7.4.
 */
class ResumenTest extends EmpresaTestCase
{
    use ConversacionesDePrueba;

    const RUTA = 'api/tienda-chats/resumen';

    /**
     * @test
     */
    public function cuenta_chats_y_mensajes_sin_leer_y_conversaciones_de_hoy_solo_del_comercio()
    {
        $dueno = $this->crear_dueno('resumen');

        $ayer = Carbon::yesterday()->setTime(12, 0, 0);

        // Ana: 2 sin leer de hoy. Beto: 1 sin leer de ayer. Caro: todo leído, hoy.
        // Dani: solo un mensaje del comercio, de ayer, sin leer por el comprador (no cuenta).
        $ana = $this->crear_comprador_de($dueno, ['name' => 'Ana']);
        $beto = $this->crear_comprador_de($dueno, ['name' => 'Beto']);
        $caro = $this->crear_comprador_de($dueno, ['name' => 'Caro']);
        $dani = $this->crear_comprador_de($dueno, ['name' => 'Dani']);

        $this->mensaje_del_comprador($ana);
        $this->mensaje_del_comprador($ana);
        $this->mensaje_del_comprador($beto, ['created_at' => $ayer]);
        $this->mensaje_del_comprador($caro, ['read' => 1]);
        $this->mensaje_del_comercio($caro);
        $this->mensaje_del_comercio($dani, ['created_at' => $ayer]);

        // Otro comercio con pendientes de hoy: no puede sumar.
        $ajeno = $this->crear_comprador_de($this->crear_dueno('resumen ajeno'));
        $this->mensaje_del_comprador($ajeno);
        $this->mensaje_del_comprador($ajeno);

        $this->actuar_como($dueno);

        $json = $this->getJson(self::RUTA)->assertStatus(200)->json();

        $this->assertSame([
            'chats_no_leidos'    => 2,
            'mensajes_no_leidos' => 3,
            'conversaciones_hoy' => 2,
        ], $json);

        // El empleado ve lo mismo.
        $this->actuar_como($this->crear_empleado($dueno));

        $this->assertSame($json, $this->getJson(self::RUTA)->assertStatus(200)->json());
    }

    /**
     * @test
     */
    public function sin_conversaciones_da_todo_en_cero()
    {
        $this->actuar_como($this->crear_dueno('resumen vacío'));

        $this->assertSame([
            'chats_no_leidos'    => 0,
            'mensajes_no_leidos' => 0,
            'conversaciones_hoy' => 0,
        ], $this->getJson(self::RUTA)->assertStatus(200)->json());
    }
}
