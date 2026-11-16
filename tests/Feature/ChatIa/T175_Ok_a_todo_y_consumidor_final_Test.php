<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionDeterministaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaGenericaIaHelper;
use App\Models\AiMessageAction;
use App\Models\Client;
use App\Models\Provider;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-diario-20261116 (rama C, 16/11/2026), ítem 31/10 #10 — «ok a todo» y el consumidor final.
 *
 * EL CASO REAL (Ferre Total, conversación 33, 31/10/2026): Pablo contestó «OK a todo» y el agente le pidió
 * confirmar el alta dos veces seguidas; y ante «creá el cliente sin más datos» le preguntó el tipo de persona.
 * Lo que protege este archivo:
 *
 *  - «ok a todo» confirma TODAS las tarjetas pendientes que un sí confirmaría, en orden, y la nota las cuenta;
 *  - 🔴 si entre las pendientes hay una que un sí no confirma solo (NUNCA_AUTO_CONFIRMABLES: un borrado), no
 *    se confirma ninguna: «a todo» no ensancha lo que un sí puede;
 *  - un «sí» suelto con dos pendientes sigue sin confirmar nada (como antes);
 *  - el alta de un cliente sin CUIT ni condición de IVA queda como Consumidor final y la tarjeta lo dice; con
 *    la condición dicha, o con CUIT, no se toca.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados, union types.
 *
 * @group chat-ia
 */
class T175_Ok_a_todo_y_consumidor_final_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio();

        // En "resuelto" un alta queda como tarjeta esperando el sí.
        $this->dueno->agente_confianza = 'resuelto';
        $this->dueno->save();
    }

    /**
     * Una vuelta que propone las altas pedidas (y opcionalmente una baja) y queda leída por el dueño.
     *
     * @param  array  $herramientas  [[herramienta, input], ...]
     * @return array{0: \App\Models\AiConversation, 1: array<int, int>}
     */
    protected function propuestas_leidas(array $herramientas)
    {
        list($conversation, $assistant) = $this->vuelta('dá de alta al cliente y al proveedor');

        $ids = [];

        foreach ($herramientas as $par) {

            $r = $this->herramienta($conversation, $assistant, $par[0], $par[1]);

            $this->assertTrue($r['ok'], json_encode($r));

            $ids[] = (int) $r['tarjeta_id'];
        }

        $assistant->estado = 'listo';
        $assistant->contenido = 'Te dejo las dos altas. ¿Las registro?';
        $assistant->save();

        return [$conversation, $ids];
    }

    /**
     * 🔴 El caso de Ferre Total: «OK a todo» confirma las dos altas de una vez.
     *
     * @test
     */
    public function ok_a_todo_confirma_la_cadena_entera()
    {
        list($conversation, $ids) = $this->propuestas_leidas([
            ['proponer_alta', ['entidad' => 'client', 'datos' => ['name' => 'ZZC175 Cliente']]],
            ['proponer_alta', ['entidad' => 'provider', 'datos' => ['name' => 'ZZC175 Proveedor']]],
        ]);

        list($conversation, $assistant) = $this->vuelta('OK a todo', $conversation);

        $confirmacion = ConfirmacionDeterministaIaHelper::quizas_confirmar($conversation, $assistant);

        $this->assertNotNull($confirmacion);
        $this->assertCount(2, $confirmacion['cadena']);
        $this->assertTrue($confirmacion['resultado']['ok']);

        foreach ($ids as $id) {
            $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::find($id)->estado);
        }

        $this->assertSame(1, Client::where('user_id', $this->dueno->id)->where('name', 'ZZC175 Cliente')->count());
        $this->assertSame(1, Provider::where('user_id', $this->dueno->id)->where('name', 'ZZC175 Proveedor')->count());

        $nota = ConfirmacionDeterministaIaHelper::nota($confirmacion);

        $this->assertStringContainsString('tarjeta #' . $ids[0], $nota);
        $this->assertStringContainsString('tarjeta #' . $ids[1], $nota);
        $this->assertNotNull(ConfirmacionDeterministaIaHelper::texto_de_respaldo($confirmacion));
    }

    /**
     * 🔴 Con un borrado entre las pendientes, «ok a todo» no confirma nada: decide el modelo.
     *
     * @test
     */
    public function con_una_pendiente_que_un_si_no_confirma_no_se_confirma_ninguna()
    {
        $existente = $this->cliente('ZZC175 Para borrar');

        list($conversation, $ids) = $this->propuestas_leidas([
            ['proponer_alta', ['entidad' => 'client', 'datos' => ['name' => 'ZZC175 Otro cliente']]],
            ['proponer_baja', ['entidad' => 'client', 'registro' => $existente->id]],
        ]);

        list($conversation, $assistant) = $this->vuelta('sí a todo', $conversation);

        $this->assertNull(ConfirmacionDeterministaIaHelper::quizas_confirmar($conversation, $assistant));

        foreach ($ids as $id) {
            $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($id)->estado);
        }

        $this->assertNull(Client::find($existente->id)->deleted_at);
    }

    /**
     * 🔴 Solo altas y ediciones de registros van en cadena: con un gasto, o con la edición de una venta, entre
     * las pendientes, «ok a todo» no confirma ninguna y decide el modelo.
     *
     * @test
     */
    public function con_plata_entre_las_pendientes_no_se_confirma_ninguna_en_cadena()
    {
        foreach ([['gasto', []], ['edicion', ['entidad' => 'sale', 'operacion' => 'edicion', 'id' => 1, 'cambios' => []]]] as $caso) {

            list($conversation, $ids) = $this->propuestas_leidas([
                ['proponer_alta', ['entidad' => 'client', 'datos' => ['name' => 'ZZC175 Con plata ' . $caso[0]]]],
            ]);

            $propuso = AiMessageAction::find($ids[0])->ai_message_id;

            $plata = AiMessageAction::create([
                'ai_conversation_id' => $conversation->id,
                'ai_message_id'      => $propuso,
                'user_id'            => $this->dueno->id,
                'auth_user_id'       => $this->dueno->id,
                'tipo'               => $caso[0],
                'clave'              => 'c175:' . $caso[0] . ':' . uniqid(),
                'estado'             => AiMessageAction::ESTADO_PROPUESTA,
                'datos'              => $caso[1],
                'presentacion'       => ['titulo' => 'Plata', 'renglones' => [], 'aviso' => null],
            ]);

            list($conversation, $assistant) = $this->vuelta('ok a todo', $conversation);

            $this->assertNull(ConfirmacionDeterministaIaHelper::quizas_confirmar($conversation, $assistant), $caso[0]);
            $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($ids[0])->estado, $caso[0]);
            $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $plata->fresh()->estado, $caso[0]);
        }
    }

    /**
     * Un «sí» suelto con dos pendientes sigue sin confirmar nada.
     *
     * @test
     */
    public function un_si_suelto_con_dos_pendientes_no_confirma()
    {
        list($conversation, $ids) = $this->propuestas_leidas([
            ['proponer_alta', ['entidad' => 'client', 'datos' => ['name' => 'ZZC175 Uno']]],
            ['proponer_alta', ['entidad' => 'provider', 'datos' => ['name' => 'ZZC175 Dos']]],
        ]);

        list($conversation, $assistant) = $this->vuelta('sí', $conversation);

        $this->assertNull(ConfirmacionDeterministaIaHelper::quizas_confirmar($conversation, $assistant));
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($ids[0])->estado);
    }

    /**
     * El detector del «sí a todo».
     *
     * @test
     */
    public function el_detector_del_si_a_todo()
    {
        $this->assertTrue(ConfirmacionDeterministaIaHelper::es_si_a_todo('OK a todo'));
        $this->assertTrue(ConfirmacionDeterministaIaHelper::es_si_a_todo('Sí a todo!'));
        $this->assertTrue(ConfirmacionDeterministaIaHelper::es_si_a_todo('confirmo todo'));
        $this->assertTrue(ConfirmacionDeterministaIaHelper::es_si_a_todo('dale a los dos'));
        $this->assertFalse(ConfirmacionDeterministaIaHelper::es_si_a_todo('¿ok a todo?'));
        $this->assertFalse(ConfirmacionDeterministaIaHelper::es_si_a_todo('eso es todo'));
        $this->assertFalse(ConfirmacionDeterministaIaHelper::es_si_a_todo('ok'));
        $this->assertFalse(ConfirmacionDeterministaIaHelper::es_si_a_todo('todo no, solo el cliente'));
    }

    /**
     * 🔴 «Creá el cliente sin más datos»: queda como Consumidor final y la tarjeta lo dice.
     *
     * @test
     */
    public function el_cliente_sin_mas_datos_queda_como_consumidor_final()
    {
        list($conversation, $assistant) = $this->vuelta('creá el cliente sin más datos');

        $r = $this->herramienta($conversation, $assistant, 'proponer_alta', ['entidad' => 'client', 'datos' => ['name' => 'ZZC175 Sin datos']]);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertStringContainsString('Consumidor final', $r['aviso']);

        $accion = AiMessageAction::find($r['tarjeta_id']);
        $payload = json_decode(json_encode($accion->datos), true)['payload'];

        $this->assertSame(PropuestaGenericaIaHelper::id_de_condicion_de_iva('Consumidor final'), (int) $payload['iva_condition_id']);
        $this->assertStringContainsString('Consumidor final', json_encode($accion->presentacion, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Con la condición dicha, o con CUIT, no se toca.
     *
     * @test
     */
    public function con_condicion_o_con_cuit_no_se_toca()
    {
        $monotributo = PropuestaGenericaIaHelper::id_de_condicion_de_iva('Monotributista');

        list($conversation, $assistant) = $this->vuelta('cliente monotributista');

        $r = $this->herramienta($conversation, $assistant, 'proponer_alta', ['entidad' => 'client', 'datos' => ['name' => 'ZZC175 Mono', 'iva_condition_id' => 'Monotributista']]);

        $payload = json_decode(json_encode(AiMessageAction::find($r['tarjeta_id'])->datos), true)['payload'];
        $this->assertSame($monotributo, (int) $payload['iva_condition_id']);

        list($conversation, $assistant) = $this->vuelta('cliente con cuit');

        $r = $this->herramienta($conversation, $assistant, 'proponer_alta', ['entidad' => 'client', 'datos' => ['name' => 'ZZC175 Con cuit', 'cuit' => '30712345671']]);

        $payload = json_decode(json_encode(AiMessageAction::find($r['tarjeta_id'])->datos), true)['payload'];
        $this->assertTrue(empty($payload['iva_condition_id']), 'Con CUIT la condición no se supone: la pregunta el asistente.');
    }
}
