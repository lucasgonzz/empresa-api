<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\FormatoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PlanillaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RolesDePlanillaIaHelper as Roles;
use App\Http\Controllers\Helpers\asistente_ia\TextoDePlanillaIaHelper as Texto;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiPlanilla;
use App\Models\Article;

/**
 * WhatsApp envía una propuesta en un solo mensaje. El límite se mide sobre el texto completo,
 * conservando cada fila que se va a confirmar, sus avisos y los números originales de la tabla.
 * Todos los clientes y documentos son inventados.
 *
 * PHP 7.4: sin match, argumentos nombrados, union types ni str_contains.
 *
 * @group chat-ia
 */
class Planilla_texto_largo_Test extends PlanillaTestCase
{
    /** Nombres distintos del largo pedido: el renderer también tiene que manejar celdas mayores que el alta admite. */
    protected function nombres_largos($cantidad, $largo = 170)
    {
        $nombres = [];

        for ($i = 1; $i <= $cantidad; $i++) {
            $nombres[] = mb_substr('Cliente de prueba ' . sprintf('%02d', $i) . ' ' . str_repeat('Apellido ', 25), 0, $largo);
        }

        return $nombres;
    }

    /** Texto real con encabezados: cada fila lleva un importe distinto para reconocer su total. */
    protected function preparar_planilla(array $nombres, $comprobante = 'C FINAL')
    {
        $lineas = [implode("\t", ['COMPROBANTE', 'VENTA TOTAL', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'])];

        foreach ($nombres as $i => $nombre) {
            $lineas[] = implode("\t", [$comprobante, number_format(5001 + $i, 2, ',', '.'), '70101', '1', 'DNI', (string) (18500101 + $i), $nombre]);
        }

        $conversation = $this->conversacion();
        $planilla = $this->planilla_pegada($conversation, implode("\n", $lineas));
        $this->assertNotNull($planilla);
        $this->assertCount(count($nombres), $planilla->filas);

        return [$conversation, $planilla];
    }

    /** Una propuesta por el mismo camino que la herramienta de WhatsApp. */
    protected function proponer_por_whatsapp($conversation)
    {
        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR, AiMessage::CANAL_WHATSAPP);
        $assistant = $this->assistant($conversation, AiMessage::CANAL_WHATSAPP);
        $this->definir_para_cargar($conversation, $assistant);
        $respuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $assistant->contenido = 'La propuesta del sistema.';
        $assistant->estado = 'listo';
        $assistant->save();

        return $respuesta;
    }

    /** Comprueba que el texto acotado corresponde exactamente a lo que la tarjeta va a ejecutar. */
    protected function assert_tanda_exacta(AiPlanilla $planilla, AiMessageAction $tarjeta)
    {
        $datos = $tarjeta->datos;
        $this->assertLessThanOrEqual(Texto::LARGO_MAXIMO, mb_strlen($datos['texto']));
        $this->assertSame(PlanillaIaHelper::firma($planilla->fresh(), $datos['renglones']), $datos['firma']);

        $total = 0.0;

        foreach ($datos['renglones'] as $numero) {
            $fila = PlanillaIaHelper::fila_numero($planilla->fresh(), (int) $numero);
            $nombre = Texto::nombre_del_cliente($fila);
            $this->assertStringContainsString((int) $numero . '. ' . $nombre, $datos['texto'], 'El cliente de la fila confirmable no se corta.');
            $total += (float) Roles::total_que_se_vende_de($fila, $planilla->importe_es);
        }

        preg_match_all('/^\d+\. /mu', $datos['texto'], $lineas);
        $this->assertCount(count($datos['renglones']), $lineas[0], 'Todas y solamente las filas confirmables están listadas.');
        $this->assertStringContainsString('Total: ' . FormatoIaHelper::monto($total), $datos['texto']);
        $this->assertStringContainsString(Texto::cuantas_ventas(count($datos['renglones'])), $datos['resumen']);

        $presentacion = $tarjeta->presentacion;
        $total_de_la_tarjeta = null;

        foreach ($presentacion['renglones'] as $renglon) {
            if ($renglon['etiqueta'] === 'Total') {
                $total_de_la_tarjeta = $renglon['valor'];
            }
        }

        $this->assertSame(FormatoIaHelper::monto($total), $total_de_la_tarjeta);

        return $total;
    }

    /** @test */
    public function veinticinco_clientes_largos_se_confirman_en_tandas_medidas_sin_perder_ventas()
    {
        /*
         * clients.name es varchar(128), tanto en la migración original como en el esquema de s7.
         * El fixture anterior de 170 reproducía el exceso del mensaje pero no podía ejecutar el alta.
         * Con 128 válidos más encabezados y avisos sigue sobrando texto; las garantías de la tanda son las mismas.
         */
        list($conversation, $planilla) = $this->preparar_planilla($this->nombres_largos(25, 128));
        Article::where('user_id', $this->dueno->id)->where('bar_code', '70101')->update(['stock' => 0]);

        $respuesta = $this->proponer_por_whatsapp($conversation);
        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $primera = AiMessageAction::find((int) $respuesta['tarjeta_id']);
        $numeros = $primera->datos['renglones'];

        $this->assertGreaterThan(0, count($numeros));
        $this->assertLessThan(25, count($numeros), 'Veinticinco nombres de 128 caracteres más sus avisos no entran en un mensaje.');
        $this->assertSame(range(1, count($numeros)), $numeros);
        $total_primero = $this->assert_tanda_exacta($planilla, $primera);
        $this->assertStringContainsString('quedan ' . (25 - count($numeros)) . ' renglones más', $primera->datos['texto']);
        $this->assertStringContainsString('hay 0, quedaría en -' . count($numeros), $primera->datos['texto'], 'El stock corresponde a la tanda final, no a las 25 filas originales.');

        $confirmada = ConfirmacionPorTextoIaHelper::confirmar_como_el_boton($conversation, $primera->id);
        $this->assertTrue($confirmada['ok'], json_encode($confirmada));
        $this->assertLessThanOrEqual(Texto::LARGO_MAXIMO, mb_strlen(Texto::resultado($primera->fresh())), 'El resultado de la primera tanda también tiene que entrar en un mensaje.');
        $ventas = $this->ventas_de($planilla);
        $this->assertCount(count($numeros), $ventas);
        $this->assertEquals($total_primero, (float) $ventas->sum('total'));

        $siguiente = $this->proponer_por_whatsapp($conversation);
        $this->assertTrue($siguiente['ok'], json_encode($siguiente));
        $segunda = AiMessageAction::find((int) $siguiente['tarjeta_id']);
        $this->assert_tanda_exacta($planilla, $segunda);
        $this->assertSame(range(count($numeros) + 1, 25), $segunda->datos['renglones']);
        $this->assertSame([], array_values(array_intersect($numeros, $segunda->datos['renglones'])));

        $otra = ConfirmacionPorTextoIaHelper::confirmar_como_el_boton($conversation, $segunda->id);
        $this->assertTrue($otra['ok'], json_encode($otra));
        $this->assertLessThanOrEqual(Texto::LARGO_MAXIMO, mb_strlen(Texto::resultado($segunda->fresh())), 'El resultado de la segunda tanda también tiene que entrar en un mensaje.');
        $ventas = $this->ventas_de($planilla);
        $this->assertCount(25, $ventas);
        $this->assertEquals(array_sum(range(5001, 5025)), (float) $ventas->sum('total'));
    }

    /** @test */
    public function si_ni_una_fila_completa_entra_no_se_crea_una_tarjeta_confirmable()
    {
        list($conversation) = $this->preparar_planilla(['Cliente de prueba ' . str_repeat('Apellido ', 550)]);

        $respuesta = $this->proponer_por_whatsapp($conversation);

        $this->assertFalse($respuesta['ok']);
        $this->assertSame('planilla_texto_demasiado_largo', $respuesta['codigo']);
        $this->assertStringContainsString('renglón 1', $respuesta['error']);
        $this->assertStringContainsString('nombre', $respuesta['error']);
        $this->assertArrayNotHasKey('tarjeta_id', $respuesta);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /** @test */
    public function una_fila_que_se_volvio_imposible_invalida_la_tarjeta_anterior()
    {
        list($conversation, $planilla) = $this->preparar_planilla(['Cliente de prueba corto']);
        $propuesta = $this->proponer_por_whatsapp($conversation);
        $this->assertTrue($propuesta['ok']);
        $anterior = AiMessageAction::find((int) $propuesta['tarjeta_id']);

        $filas = $planilla->fresh()->filas;
        $filas[0]['valores'][Roles::NOMBRE] = 'Cliente de prueba ' . str_repeat('Apellido ', 550);
        $planilla->filas = $filas;
        $planilla->save();

        $respuesta = $this->proponer_por_whatsapp($conversation);

        $this->assertFalse($respuesta['ok']);
        $this->assertSame(AiMessageAction::ESTADO_REEMPLAZADA, $anterior->fresh()->estado_guardado());
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', AiMessageAction::ESTADO_PROPUESTA)->count());
    }

    /** @test */
    public function los_avisos_de_iva_largos_se_preservan_al_achicar_la_tanda()
    {
        list($conversation, $planilla) = $this->preparar_planilla($this->nombres_largos(25), 'FACTURA A');

        $respuesta = $this->proponer_por_whatsapp($conversation);
        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $tarjeta = AiMessageAction::find((int) $respuesta['tarjeta_id']);
        $this->assertLessThan(25, count($tarjeta->datos['renglones']));
        $this->assert_tanda_exacta($planilla, $tarjeta);

        foreach ($tarjeta->datos['renglones'] as $numero) {
            $this->assertStringContainsString('Ojo con el renglón ' . (int) $numero . ': la planilla pide Factura A y trae un DNI.', $tarjeta->datos['texto']);
        }

        $this->assertStringContainsString('la planilla pide ' . count($tarjeta->datos['renglones']) . ' Factura A', $tarjeta->datos['texto']);
    }

    /** @test */
    public function un_bloque_largo_de_filas_sin_venta_no_se_oculta_para_pedir_el_si()
    {
        list($conversation, $planilla) = $this->preparar_planilla(['Cliente de prueba corto']);
        $filas = $planilla->filas;
        $filas[] = [
            'numero' => 99,
            'estado' => AiPlanilla::FILA_DESCARTADA,
            'sin_venta' => true,
            'valores' => [Roles::NOMBRE => 'Persona sin venta ' . str_repeat('Apellido ', 550), Roles::DOCUMENTO => '18509999'],
        ];
        $planilla->filas = $filas;
        $planilla->save();

        $respuesta = $this->proponer_por_whatsapp($conversation);

        $this->assertFalse($respuesta['ok']);
        $this->assertSame('planilla_texto_demasiado_largo', $respuesta['codigo']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /** @test */
    public function un_bloque_largo_de_cambios_no_se_oculta_para_pedir_el_si()
    {
        list($conversation, $planilla) = $this->preparar_planilla(['Cliente de prueba corto']);
        $definiciones = is_array($planilla->definiciones) ? $planilla->definiciones : [];
        $definiciones['cambios'] = ['cliente anterior → ' . str_repeat('Nombre corregido ', 350)];
        $planilla->definiciones = $definiciones;
        $planilla->save();

        $respuesta = $this->proponer_por_whatsapp($conversation);

        $this->assertFalse($respuesta['ok']);
        $this->assertSame('planilla_texto_demasiado_largo', $respuesta['codigo']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }
}
