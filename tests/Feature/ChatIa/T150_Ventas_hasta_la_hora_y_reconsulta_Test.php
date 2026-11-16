<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ResumenDeVentasIaHelper;
use App\Models\AiConversation;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-diario-20261116, rama A, A9 — «cómo van las ventas» con el número de AHORA.
 *
 * 🔴 EL CASO REAL (Ferre Total, 5/11/2026, conversación 47). El asistente repitió «70 ventas /
 * $1.202.047» tres veces cuando ya eran 88: reusaba el resultado anterior de la charla sin volver a
 * consultar, y para comparar con ayer usaba «el mismo número de venta» como si fuera la misma hora.
 *
 * Lo que fija este archivo:
 *  - `hasta_hora` corta cada día del rango a esa hora (por la hora de carga), con las devoluciones de
 *    un día cortadas igual y las de varios días en null con su porqué;
 *  - toda respuesta dice `consultado_a_las`;
 *  - el registro ofrece `hasta_hora` al final del esquema y el prompt tiene la regla de volver a consultar.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class T150_Ventas_hasta_la_hora_y_reconsulta_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'     => 'Comercio T150',
            'email'    => 't150-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * Hoy hasta las 12:00 contra ayer hasta las 12:00: cada día queda cortado a su franja.
     *
     * @group chat-ia
     * @test
     */
    public function hasta_hora_corta_cada_dia_a_esa_hora()
    {
        $dia = now()->subDays(3)->startOfDay();
        $fecha = $dia->format('Y-m-d');

        $this->venta($dia->copy()->setTime(9, 15), 1000);
        $this->venta($dia->copy()->setTime(12, 0, 40), 300);  // 12:00:40 entra en «hasta las 12:00»
        $this->venta($dia->copy()->setTime(16, 30), 500);

        $entero = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha);

        $this->assertSame(3, $entero['cantidad_de_ventas']);
        $this->assertEquals(1800.0, $entero['total']);
        $this->assertArrayNotHasKey('hasta_hora', $entero);
        $this->assertArrayHasKey('consultado_a_las', $entero, 'Toda respuesta dice a qué hora se consultó.');

        $cortado = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, null, 'pesos', null, null, null, '12:00');

        $this->assertArrayNotHasKey('error', $cortado, json_encode($cortado));
        $this->assertSame(2, $cortado['cantidad_de_ventas']);
        $this->assertEquals(1300.0, $cortado['total']);
        $this->assertSame('12:00', $cortado['hasta_hora']);
        $this->assertArrayHasKey('aviso_de_hora', $cortado);
        $this->assertArrayHasKey('consultado_a_las', $cortado);

        // Y agrupado: el grupo del día tiene lo mismo que el total cortado.
        $por_dia = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, 'dia', 'pesos', null, null, null, '12:00');

        $this->assertSame(1, $por_dia['grupos_encontrados']);
        $this->assertEquals(1300.0, $por_dia['grupos'][0]['total']);
    }

    /**
     * Con un rango de varios días la franja se aplica a cada uno; las devoluciones van cortadas a la
     * hora en un día y en null (con el porqué) en varios.
     *
     * @group chat-ia
     * @test
     */
    public function las_devoluciones_se_cortan_en_un_dia_y_van_en_null_en_varios()
    {
        $ayer = now()->subDays(5)->startOfDay();
        $hoy = now()->subDays(4)->startOfDay();

        $this->venta($ayer->copy()->setTime(10, 0), 700);
        $this->venta($ayer->copy()->setTime(20, 0), 900);
        $this->venta($hoy->copy()->setTime(11, 0), 400);

        CurrentAcount::create(['user_id' => $this->comercio->id, 'detalle' => 'NC T150 temprano', 'haber' => 50, 'saldo' => 0, 'status' => 'nota_credito', 'created_at' => $hoy->copy()->setTime(9, 0)]);
        CurrentAcount::create(['user_id' => $this->comercio->id, 'detalle' => 'NC T150 tarde', 'haber' => 80, 'saldo' => 0, 'status' => 'nota_credito', 'created_at' => $hoy->copy()->setTime(18, 0)]);

        $un_dia = ResumenDeVentasIaHelper::resumen($this->comercio->id, $hoy->format('Y-m-d'), $hoy->format('Y-m-d'), null, 'pesos', null, null, null, '12:00');

        $this->assertEquals(50.0, $un_dia['devoluciones'], 'Solo la nota de crédito de antes de las 12.');

        $dos_dias = ResumenDeVentasIaHelper::resumen($this->comercio->id, $ayer->format('Y-m-d'), $hoy->format('Y-m-d'), null, 'pesos', null, null, null, '12:00');

        $this->assertSame(2, $dos_dias['cantidad_de_ventas'], 'La de las 20:00 de ayer queda afuera.');
        $this->assertEquals(1100.0, $dos_dias['total']);
        $this->assertNull($dos_dias['devoluciones']);
        $this->assertArrayHasKey('aviso_de_devoluciones', $dos_dias);
    }

    /**
     * Una hora mal escrita es un error que dice el formato, no un corte silencioso.
     *
     * @group chat-ia
     * @test
     */
    public function una_hora_mal_escrita_da_error()
    {
        $fecha = now()->subDays(3)->format('Y-m-d');

        foreach (['25:00', '12:75', 'mediodia', '12'] as $mala) {
            $respuesta = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, null, 'pesos', null, null, null, $mala);

            $this->assertArrayHasKey('error', $respuesta, $mala);
            $this->assertStringContainsString('HH:MM', $respuesta['error']);
        }

        $vacia = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, null, 'pesos', null, null, null, '');
        $this->assertArrayNotHasKey('error', $vacia, 'Vacío es sin corte.');
    }

    /**
     * La tool ofrece hasta_hora AL FINAL de su esquema (prefijo del caché), el handler lo pasa, y el
     * prompt tiene la regla de volver a consultar.
     *
     * @group chat-ia
     * @test
     */
    public function el_registro_ofrece_hasta_hora_y_el_prompt_obliga_a_reconsultar()
    {
        $service = new AsistenteIaService();

        $resumen = null;

        foreach ($service->herramientas_de_lectura() as $herramienta) {
            if ($herramienta['name'] === 'consultar_resumen_de_ventas') {
                $resumen = $herramienta;
            }
        }

        $this->assertNotNull($resumen);

        $propiedades = array_keys($resumen['input_schema']['properties']);

        $this->assertSame('hasta_hora', end($propiedades), 'Lo nuevo va al final del esquema.');
        $this->assertStringContainsString('consultado_a_las', $resumen['description']);

        $dia = now()->subDays(3)->startOfDay();
        $this->venta($dia->copy()->setTime(9, 0), 100);
        $this->venta($dia->copy()->setTime(19, 0), 200);

        $conversation = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);

        $resultados = $service->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_t150',
            'name'  => 'consultar_resumen_de_ventas',
            'input' => ['desde' => $dia->format('Y-m-d'), 'hasta' => $dia->format('Y-m-d'), 'hasta_hora' => '10:00'],
        ]], $conversation);

        $contenido = json_decode($resultados[0]['content'], true);

        $this->assertSame(1, $contenido['cantidad_de_ventas']);
        $this->assertEquals(100.0, $contenido['total']);

        $prompt = $service->build_system_prompt($conversation, $this->comercio, false);

        $this->assertStringContainsString('SIEMPRE volvé a', $prompt);
        $this->assertStringContainsString('nunca repitas un número de una respuesta anterior', $prompt);
    }

    /**
     * Una venta terminada en pesos, de mostrador.
     *
     * @param  Carbon  $momento
     * @param  float   $total
     * @return Sale
     */
    protected function venta(Carbon $momento, $total)
    {
        return Sale::create([
            'user_id'    => $this->comercio->id,
            'total'      => $total,
            'terminada'  => 1,
            'moneda_id'  => 1,
            'created_at' => $momento,
        ]);
    }
}
