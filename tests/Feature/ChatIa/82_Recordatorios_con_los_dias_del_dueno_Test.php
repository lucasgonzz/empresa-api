<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\VentasSinCobrarIaHelper;
use App\Http\Controllers\Helpers\sale\VentasSinCobrarHelper;
use App\Jobs\SendRecordatorioCobroJob;
use App\Models\AiMessageAction;
use App\Models\Client;
use App\Models\Sale;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\RecordatorioCobroSenderService;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-arreglos-segunda-prueba (19/10/2026) — N1: los días que pide el dueño contra la
 * alerta personalizada de cada venta.
 *
 * EL CASO REAL (segunda prueba por WhatsApp contra demo3): "mandales un recordatorio a los que me deben
 * hace más de 20 días" dejaba afuera una venta de 23 días, porque toda venta a cuenta corriente
 * sembrada en demo3 tiene `dias_alerta_venta_no_cobrada_personalizado = 30` y el
 * `COALESCE(personalizado, dias)` de la query compartida la medía contra 30. Y con una sola venta la
 * plantilla decía "Tenés 1 venta pendientes de pago".
 *
 * Lo que protege este archivo:
 *  - con días dichos, el asistente usa ESOS días en los tres lugares (quiénes reciben, el texto de la
 *    tarjeta y lo que recalcula el job) y en la consulta de ventas sin cobrar;
 *  - una venta reciente con un umbral propio corto ya no entra por su umbral;
 *  - sin días dichos (dias = 0), la pantalla y la alerta automática, todo como siempre;
 *  - un job ya encolado (sin la propiedad nueva) y una tarjeta vieja (sin la bandera) siguen con el
 *    COALESCE con el que se armaron;
 *  - la plantilla concuerda con una sola venta.
 *
 * Nada sale a la red: WhatsApp en envío simulado y Http::fake.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Recordatorios_con_los_dias_del_dueno_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio(['agente_confianza' => ConfianzaDelAgenteIaHelper::DIRECTO]);

        Http::fake(['*' => Http::response([], 200)]);
    }

    /**
     * Una venta impaga con su umbral propio de alerta cargado (como las de demo3: 30).
     *
     * @param  Client  $client
     * @param  int  $dias
     * @param  float  $monto
     * @param  int|null  $umbral_propio
     * @param  int|null  $num
     * @return Sale
     */
    protected function venta_con_umbral(Client $client, $dias, $monto, $umbral_propio, $num = null)
    {
        $venta = $this->venta_impaga($client, $dias, $monto, $num);

        Sale::where('id', $venta->id)->update(['dias_alerta_venta_no_cobrada_personalizado' => $umbral_propio]);

        return $venta->fresh();
    }

    /**
     * Los recordatorios que salieron a un cliente.
     *
     * @param  Client  $client
     * @return \Illuminate\Support\Collection
     */
    protected function recordatorios_de(Client $client)
    {
        return WhatsappChatMessage::where('direction', 'out')
            ->where('source', RecordatorioCobroSenderService::SOURCE)
            ->whereIn('whatsapp_chat_id', WhatsappChat::where('user_id', $this->dueno->id)->where('client_id', $client->id)->select('id'))
            ->get();
    }

    /**
     * 🔴 EL CASO DE DEMO3: tres ventas de 23, 25 y 28 días, todas con umbral propio 30, y el dueño pide
     * "hace más de 20 días". El cliente entra, la tarjeta lista las TRES y el WhatsApp que sale también.
     *
     * @test
     */
    public function los_dias_que_dijo_el_dueno_cuentan_tal_cual_en_la_tarjeta_y_en_el_job()
    {
        $perez = $this->cliente('Marcos Perez', '5493444101010');
        $this->venta_con_umbral($perez, 23, 100000, 30, 7001);
        $this->venta_con_umbral($perez, 25, 100000, 30, 7002);
        $this->venta_con_umbral($perez, 28, 100000, 30, 7003);
        $this->chat_abierto($perez);

        // La pantalla no cambia: con el COALESCE ninguna de las tres cumple su umbral de 30.
        $this->assertSame(0, VentasSinCobrarHelper::query_de_ventas($this->dueno->id, null, 20)->where('client_id', $perez->id)->count());

        list($conversation, $assistant) = $this->vuelta('mandales un recordatorio a los que me deben hace mas de 20 dias');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_recordatorios_de_cobro', ['dias' => 20]);

        $this->assertTrue(!empty($propuesta['ok']), json_encode($propuesta));

        $mostrar = $this->para_mostrar($propuesta);

        $this->assertSame('Marcos Perez', $mostrar['destinatarios']);
        $this->assertStringContainsString('Tenés 3 ventas pendientes de pago', $mostrar['texto_que_sale']);

        foreach (['Venta N° 7001', 'Venta N° 7002', 'Venta N° 7003', 'hace 23 días', 'hace 25 días', 'hace 28 días'] as $renglon) {
            $this->assertStringContainsString($renglon, $mostrar['texto_que_sale'], 'La tarjeta tiene que listar las tres ventas.');
        }

        $tarjeta = AiMessageAction::find($propuesta['tarjeta_id']);

        $this->assertFalse($tarjeta->datos['respetar_umbral_propio'], 'La bandera viaja en datos para la confirmación y el job.');

        $confirmada = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $propuesta['tarjeta_id']);

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));

        $salieron = $this->recordatorios_de($perez);

        $this->assertCount(1, $salieron);

        // El job recalculó con los MISMOS días: el WhatsApp lista las mismas tres ventas que la tarjeta.
        $this->assertStringContainsString('Tenés 3 ventas pendientes de pago', $salieron->first()->body);

        foreach (['Venta N° 7001', 'Venta N° 7002', 'Venta N° 7003'] as $renglon) {
            $this->assertStringContainsString($renglon, $salieron->first()->body);
        }

        Http::assertNothingSent();
    }

    /**
     * La consulta (consultar_ventas_sin_cobrar) con días dichos tampoco mira el umbral propio: el dueño
     * pregunta y le dan los mismos números que va a recibir el recordatorio.
     *
     * @test
     */
    public function la_consulta_de_ventas_sin_cobrar_usa_los_dias_dichos()
    {
        $perez = $this->cliente('Marcos Perez', '5493444101010');
        $this->venta_con_umbral($perez, 23, 100000, 30);
        $this->venta_con_umbral($perez, 28, 50000, 30);

        $respuesta = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->dueno->id, 20, $this->dueno);

        $this->assertSame(2, $respuesta['ventas_sin_cobrar'], json_encode($respuesta));
        $this->assertEquals(150000, $respuesta['total_pendiente_en_pesos']);
        $this->assertSame(1, $respuesta['clientes_con_deuda']);
    }

    /**
     * Al revés: una venta de 10 días con un umbral propio CORTO (3) ya no entra por su umbral cuando el
     * dueño pidió "hace más de 20 días". Y sin días dichos (dias = 0) queda como siempre.
     *
     * @test
     */
    public function una_venta_reciente_con_umbral_propio_corto_no_entra_con_los_dias_del_dueno()
    {
        $gomez = $this->cliente('Ana Gomez', '5493444202020');
        $this->venta_con_umbral($gomez, 10, 80000, 3);

        list($conversation, $assistant) = $this->vuelta('mandales un recordatorio a los que me deben hace mas de 20 dias');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_recordatorios_de_cobro', ['dias' => 20]);

        $this->assertTrue(empty($respuesta['ok']), json_encode($respuesta));
        $this->assertStringContainsString('no hay ningún cliente que deba', $respuesta['error']);

        $con_dias = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->dueno->id, 20, $this->dueno);

        $this->assertSame(0, $con_dias['ventas_sin_cobrar']);

        // Sin días dichos: el criterio de la pantalla (su umbral de 3 ya se cumplió), como hasta hoy.
        $sin_dias = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->dueno->id, null, $this->dueno);

        $this->assertSame(1, $sin_dias['ventas_sin_cobrar']);
    }

    /**
     * 🔴 Un job que ya estaba en la cola al desplegar se deserializa SIN la propiedad nueva y sin pasar
     * por el constructor: el default de la declaración lo deja con el COALESCE con el que se armó. Y un
     * job construido como lo hace la pantalla (cinco argumentos) tampoco cambia.
     *
     * @test
     */
    public function un_job_encolado_sin_la_bandera_sigue_con_el_coalesce()
    {
        $perez = $this->cliente('Marcos Perez', '5493444101010');
        $this->venta_con_umbral($perez, 23, 100000, 30);
        $this->chat_abierto($perez);

        // Como lo despacha la pantalla: el umbral propio manda y la venta de 23 días no cumple su 30.
        (new SendRecordatorioCobroJob($this->dueno->id, $perez->id, 20, $this->dueno->id, null))->handle();

        $this->assertCount(0, $this->recordatorios_de($perez), 'Sin la bandera, el job sigue con el COALESCE.');

        // Un job "viejo": serializado con la bandera en false y sin la propiedad en el payload.
        $serializado = serialize(new SendRecordatorioCobroJob($this->dueno->id, $perez->id, 20, $this->dueno->id, null, false));

        $viejo = unserialize($this->sin_la_propiedad($serializado, 'respetar_umbral_propio'));

        $propiedad = new \ReflectionProperty(SendRecordatorioCobroJob::class, 'respetar_umbral_propio');
        $propiedad->setAccessible(true);

        $this->assertTrue($propiedad->getValue($viejo), 'El default de la declaración es el del job viejo.');

        $viejo->handle();

        $this->assertCount(0, $this->recordatorios_de($perez));

        // Con la bandera en false (lo que manda el asistente con días dichos), sale.
        (new SendRecordatorioCobroJob($this->dueno->id, $perez->id, 20, $this->dueno->id, null, false))->handle();

        $this->assertCount(1, $this->recordatorios_de($perez));
    }

    /**
     * Una tarjeta propuesta antes de este cambio no tiene la bandera en datos: se confirma con el
     * COALESCE, que es con lo que se armó lo que el dueño vio.
     *
     * @test
     */
    public function una_tarjeta_vieja_sin_la_bandera_confirma_con_el_coalesce()
    {
        $perez = $this->cliente('Marcos Perez', '5493444101010');
        $this->venta_con_umbral($perez, 23, 100000, 30);
        $this->chat_abierto($perez);

        list($conversation, $assistant) = $this->vuelta('mandales un recordatorio a los que me deben hace mas de 20 dias');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_recordatorios_de_cobro', ['dias' => 20]);

        $this->assertTrue(!empty($propuesta['ok']), json_encode($propuesta));

        $tarjeta = AiMessageAction::find($propuesta['tarjeta_id']);
        $datos = $tarjeta->datos;
        unset($datos['respetar_umbral_propio']);
        $tarjeta->datos = $datos;
        $tarjeta->save();

        $confirmada = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $propuesta['tarjeta_id']);

        $this->assertTrue(empty($confirmada['ok']), json_encode($confirmada));
        $this->assertStringContainsString('No salió ningún recordatorio', $confirmada['error']);
        $this->assertCount(0, $this->recordatorios_de($perez));
    }

    /**
     * 🔴 "Tenés 1 venta pendientes de pago": el body aprobado en Meta dice "Tenés {{3}} pendientes de
     * pago" y no se toca; con UNA venta {{3}} concuerda con el plural fijo. Con dos, como siempre.
     *
     * @test
     */
    public function la_plantilla_con_una_sola_venta_concuerda()
    {
        $service = new RecordatorioCobroSenderService();

        $una = $this->cliente('Una Venta', '5493444303030');
        $this->venta_impaga($una, 40, 12000);

        $textos = $service->textos_de_los_dos_caminos($this->dueno->id, $una, VentasSinCobrarHelper::query_de_ventas($this->dueno->id, null, 0)->where('client_id', $una->id)->get());

        $this->assertNotNull($textos['plantilla'], 'La plantilla está aprobada en el negocio del test.');
        $this->assertStringContainsString('Tenés 1 venta con saldos pendientes de pago', $textos['plantilla']);
        $this->assertStringNotContainsString('1 venta pendientes', $textos['plantilla']);
        // El texto libre ya conjugaba bien.
        $this->assertStringContainsString('Tenés 1 venta pendiente de pago', $textos['texto_libre']);

        $dos = $this->cliente('Dos Ventas', '5493444404040');
        $this->venta_impaga($dos, 40, 12000);
        $this->venta_impaga($dos, 35, 8000);

        $textos = $service->textos_de_los_dos_caminos($this->dueno->id, $dos, VentasSinCobrarHelper::query_de_ventas($this->dueno->id, null, 0)->where('client_id', $dos->id)->get());

        $this->assertStringContainsString('Tenés 2 ventas pendientes de pago', $textos['plantilla']);
    }

    /**
     * 🔴 Revisión del 19/10/2026: "¿qué me debe Sanitarios?" no puede contradecir al recordatorio. La
     * venta de 23 días con umbral propio 30 entra en "los que me deben hace más de 20 días", así que la
     * consulta de las ventas impagas de ESE cliente la tiene que traer: toda la deuda viva, con la forma
     * de respuesta de siempre.
     *
     * @test
     */
    public function que_me_debe_un_cliente_trae_toda_la_deuda_viva_aunque_tenga_umbral_propio()
    {
        $sanitarios = $this->cliente('Sanitarios del Sur', '5493444505050');
        $this->venta_con_umbral($sanitarios, 23, 100000, 30, 7101);
        // Una de hoy, sin umbral propio, también es deuda.
        $this->venta_impaga($sanitarios, 0, 20000, 7102);

        $deudores = VentasSinCobrarIaHelper::clientes_deudores($this->dueno->id, 20, $this->dueno);

        $this->assertSame([(int) $sanitarios->id], array_column($deudores['clientes'], 'cliente_id'), 'El recordatorio lo trae.');

        $respuesta = \App\Http\Controllers\Helpers\ConsultasSistemaIaHelper::ventas_impagas_de_un_cliente($this->dueno->id, $sanitarios->id);

        $this->assertSame(2, $respuesta['ventas_impagas_encontradas'], json_encode($respuesta));
        $this->assertCount(2, $respuesta['ventas']);
        $this->assertEquals(120000, $respuesta['total_pendiente_en_pesos_en_esta_lista']);
        $this->assertSame(7101, $respuesta['venta_impaga_mas_vieja']['numero']);

        // La forma de la respuesta no cambia.
        $this->assertSame([
            'cliente', 'cliente_id', 'orden', 'ventas_impagas_encontradas', 'ventas_en_esta_lista',
            'total_pendiente_en_pesos_en_esta_lista', 'ventas_en_otra_moneda_en_esta_lista',
            'saldo_en_cuenta_corriente_en_pesos', 'venta_impaga_mas_vieja', 'ventas',
        ], array_keys($respuesta));
    }

    /**
     * Saca una propiedad protegida de un objeto serializado (lo que tiene en la cola un job encolado por
     * la versión anterior de la clase).
     *
     * @param  string  $serializado
     * @param  string  $propiedad
     * @return string
     */
    protected function sin_la_propiedad($serializado, $propiedad)
    {
        $clave = "\0*\0" . $propiedad;

        $patron = '/s:' . strlen($clave) . ':"' . preg_quote($clave, '/') . '";b:[01];/';

        $sin = preg_replace($patron, '', $serializado, 1, $cuantas);

        $this->assertSame(1, $cuantas, 'El payload tenía la propiedad.');

        // Una propiedad menos en el contador del objeto de afuera.
        return preg_replace_callback('/^O:(\d+):"([^"]+)":(\d+):/', function ($m) {
            return 'O:' . $m[1] . ':"' . $m[2] . '":' . ((int) $m[3] - 1) . ':';
        }, $sin, 1);
    }
}
