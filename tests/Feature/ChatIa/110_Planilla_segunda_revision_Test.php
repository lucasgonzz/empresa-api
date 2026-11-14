<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\AccionesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\AnclasDePlanillaIaHelper as Anclas;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\LecturaDePlanillaIaHelper as Lectura;
use App\Http\Controllers\Helpers\asistente_ia\PlanillaDeTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PlanillaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RolesDePlanillaIaHelper as Roles;
use App\Http\Controllers\Helpers\asistente_ia\TarjetaPrometidaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\TextoFinalIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiPlanilla;
use App\Models\Client;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\EscenariosDePlata;

/**
 * Misión agente-venta-por-planilla: la segunda revisión independiente y la prueba real con modelos (02/11/2026). Cada test se
 * escribió antes que su arreglo, y falló.
 *
 * Todo inventado: nombres, documentos, códigos y operaciones.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 *
 * @group chat-ia
 */
class Planilla_segunda_revision_Test extends PlanillaTestCase
{
    use EscenariosDePlata;

    /**
     * @param  array  $filas
     * @return string
     */
    protected function como_texto(array $filas)
    {
        return implode("\n", array_map(function ($fila) {
            return implode("\t", $fila);
        }, $filas));
    }

    /**
     * @param  AiMessage  $assistant
     * @return void
     */
    protected function cerrar(AiMessage $assistant)
    {
        $assistant->contenido = 'x';
        $assistant->estado = 'listo';
        $assistant->save();
    }

    /**
     * Una corrección, en un mensaje nuevo del dueño.
     *
     * @param  AiConversation  $conversation
     * @param  string  $mensaje
     * @param  array  $input
     * @return array
     */
    protected function corregir_con(AiConversation $conversation, $mensaje, array $input)
    {
        $this->del_dueno($conversation, $mensaje);

        $assistant = $this->assistant($conversation);

        $respuesta = $this->herramienta('corregir_renglon_de_planilla', array_merge(['lo_que_dijo_el_dueno' => $mensaje], $input), $conversation, $assistant);

        $this->cerrar($assistant);

        return $respuesta;
    }

    /**
     * Un definir_planilla, en un mensaje nuevo del dueño.
     *
     * @param  AiConversation  $conversation
     * @param  string  $mensaje
     * @param  array  $input
     * @return array
     */
    protected function definir_con(AiConversation $conversation, $mensaje, array $input)
    {
        $this->del_dueno($conversation, $mensaje);

        $assistant = $this->assistant($conversation);

        $respuesta = $this->herramienta('definir_planilla', $input, $conversation, $assistant);

        $this->cerrar($assistant);

        return $respuesta;
    }

    /**
     * La propuesta, en un mensaje nuevo del dueño que ya dijo cómo se cobró.
     *
     * @param  AiConversation  $conversation
     * @return array
     */
    protected function proponer(AiConversation $conversation)
    {
        $this->del_dueno($conversation, 'Se cobraron en Efectivo, Caja Efectivo');

        $assistant = $this->assistant($conversation);

        $this->herramienta('definir_planilla', ['metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO, 'caja' => TestingFerreteriaSeeder::CAJA_EFECTIVO], $conversation, $assistant);

        $respuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->cerrar($assistant);

        return $respuesta;
    }

    // ---------------------------------------------------------------------
    // R1. El tipo y el número de documento en una sola celda
    // ---------------------------------------------------------------------

    /**
     * @test
     */
    public function una_celda_con_el_tipo_y_el_numero_de_documento_se_separa()
    {
        $this->assertSame(['DNI', '14000002'], Roles::documento_con_tipo('DNI 14000002'));
        $this->assertSame(['DNI', '14000002'], Roles::documento_con_tipo('DNI 14.000.002'));
        $this->assertSame(['DNI', '14000002'], Roles::documento_con_tipo('dni: 14.000.002'));
        $this->assertSame(['DNI', '14000002'], Roles::documento_con_tipo('D.N.I. 14000002'));
        $this->assertSame(['CUIT', self::CUIT_EMPRESA], Roles::documento_con_tipo('CUIT 30-71234567-1'));
        $this->assertSame(['CUIL', '20175000004'], Roles::documento_con_tipo('CUIL 20-17500000-4'));

        $this->assertNull(Roles::documento_con_tipo('DNI'));
        $this->assertNull(Roles::documento_con_tipo('14000002'));
        $this->assertNull(Roles::documento_con_tipo('Calle DNI 14000002'));
        $this->assertNull(Roles::documento_con_tipo('DNI de Ana'));

        // La columna se parte en dos, en cualquier origen.
        $separada = Roles::separar_tipo_de_documento(['TOTAL', 'DOCUMENTO', 'NOMBRE'], [
            ['1.000,00', 'DNI 14000002', 'Bruno Prueba Dos'],
            ['2.000,00', 'CUIT 30-71234567-1', 'Empresa Prueba SA'],
            ['3.000,00', '17000001', 'Ana Prueba Uno'],
        ]);

        $this->assertSame(['TOTAL', 'TIPO', 'DOCUMENTO', 'NOMBRE'], $separada['encabezado']);
        $this->assertSame(['1.000,00', 'DNI', '14000002', 'Bruno Prueba Dos'], $separada['filas'][0]);
        $this->assertSame(['2.000,00', 'CUIT', self::CUIT_EMPRESA, 'Empresa Prueba SA'], $separada['filas'][1]);
        $this->assertSame(['3.000,00', '', '17000001', 'Ana Prueba Uno'], $separada['filas'][2]);
    }

    /**
     * 🔴 La prueba real: las 11 ventas salieron bien y los 11 clientes se crearon sin documento.
     *
     * @test
     */
    public function una_tabla_por_espacios_con_el_dni_en_una_celda_crea_los_clientes_con_su_documento()
    {
        $this->todos_leen([
            ['C FINAL', '61.250,00', '13-oct', 'SKU 70101', 'Bomba Alfa', '#2000000000000001', '1', 'DNI 17000001', 'Ana Prueba Uno', 'Calle Falsa 100'],
            ['FACTURA A', '204.000,00', '19-oct', 'SKU ZRRI-50LF', 'Foco Led', '#2000000000000003', '2', 'CUIT 30-71234567-1', 'Empresa Prueba SA', 'Calle Falsa 300'],
        ]);

        $this->falsear_ia();

        $texto = "C FINAL 61.250,00 13-oct SKU 70101 Bomba Alfa #2000000000000001 1 DNI 17000001 Ana Prueba Uno Calle Falsa 100\nFACTURA A 204.000,00 19-oct SKU ZRRI-50LF Foco Led #2000000000000003 2 CUIT 30-71234567-1 Empresa Prueba SA Calle Falsa 300";

        $conversation = $this->conversacion();
        $this->del_dueno($conversation, $texto);

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

        $planilla = AiPlanilla::where('ai_conversation_id', $conversation->id)->first();

        $this->assertNotNull($planilla);
        $this->assertSame('17000001', PlanillaIaHelper::fila_numero($planilla, 1)['valores'][Roles::DOCUMENTO]);
        $this->assertSame(self::CUIT_EMPRESA, PlanillaIaHelper::fila_numero($planilla, 2)['valores'][Roles::DOCUMENTO]);

        // El prompt de la lectura por texto lo pide.
        $cuerpo = $this->lecturas_de('claude-sonnet-5-5')[0][0]->body();

        $this->assertStringContainsString('tipo de documento', json_decode($cuerpo, true)['messages'][0]['content'][0]['text']);

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR);

        $assistant = $this->assistant($conversation);

        $this->definir_para_cargar($conversation, $assistant);

        $propuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->assertTrue($propuesta['ok'], json_encode($propuesta));
        $this->assertStringNotContainsString('no trae CUIT', AiMessageAction::find($propuesta['tarjeta_id'])->datos['texto']);

        $this->cerrar($assistant);

        $this->del_dueno($conversation, 'Sí');
        $this->responder($conversation, $this->assistant($conversation));

        $this->assertSame('Ana Prueba Uno', Client::where('user_id', $this->dueno->id)->where('dni', '17000001')->value('name'));
        $this->assertSame('Empresa Prueba SA', Client::where('user_id', $this->dueno->id)->where('cuit', self::CUIT_EMPRESA)->value('name'));
    }

    /**
     * Dos lecturas que parten distinto la misma celda son la misma lectura: no hace falta el desempate.
     *
     * @test
     */
    public function una_lectura_que_separa_el_documento_y_otra_que_no_coinciden()
    {
        $junto = [['C FINAL', '61.250,00', 'SKU 70101', '1', 'DNI 17000001', 'Ana Prueba Uno']];
        $separado = [['C FINAL', '61.250,00', 'SKU 70101', '1', 'DNI', '17000001', 'Ana Prueba Uno']];

        $this->lecturas = [
            'claude-sonnet-5-5' => $this->lectura($junto),
            'claude-haiku-5-5'  => $this->lectura($separado),
        ];

        $this->falsear_ia();

        $conversation = $this->conversacion();
        $this->foto($this->del_dueno($conversation, ''));

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

        $planilla = AiPlanilla::where('ai_conversation_id', $conversation->id)->first();

        $this->assertNotNull($planilla);
        $this->assertCount(0, $this->lecturas_de('claude-opus-5-5'));
        $this->assertSame('17000001', PlanillaIaHelper::fila_numero($planilla, 1)['valores'][Roles::DOCUMENTO]);
    }

    /**
     * También pegada con tabuladores y en un archivo.
     *
     * @test
     */
    public function el_documento_junto_se_separa_en_una_tabla_pegada_y_en_un_archivo()
    {
        $fila = ['C FINAL', '61.250,00', 'SKU 70101', 'Bomba Alfa', '1', 'DNI 17.000.001', 'Ana Prueba Uno'];

        $tabla = PlanillaDeTextoIaHelper::tabla_del_texto($this->como_texto([$fila]));

        $this->assertNotNull($tabla);
        $this->assertContains(Roles::TIPO_DOCUMENTO, $tabla['roles']);
        $this->assertContains(Roles::DOCUMENTO, $tabla['roles']);

        $de_archivo = PlanillaDeTextoIaHelper::tablas_de_un_archivo(implode(' | ', $fila));

        $this->assertCount(1, $de_archivo);
        $this->assertContains('17000001', $de_archivo[0]['filas'][0]);

        // Un DNI junto ya dice que es un documento: sin título y sin comprobante, alcanza.
        $this->assertNotNull(PlanillaDeTextoIaHelper::tabla_del_texto($this->como_texto([
            ['61.250,00', 'SKU 70101', 'Bomba Alfa', 'DNI 17000001'],
        ])));
    }

    // ---------------------------------------------------------------------
    // R2. reemplaza_a
    // ---------------------------------------------------------------------

    /**
     * 🔴 «No, es para el viernes»: el gasto se convierte en tarea. Si la tarea no reemplaza al gasto, quedan los dos vivos, y se
     * puede registrar dos veces.
     *
     * @test
     */
    public function un_gasto_que_pasa_a_tarea_queda_reemplazado()
    {
        $conversation = $this->conversacion();

        $this->del_dueno($conversation, 'Cargá el flete');

        $assistant = $this->assistant($conversation);

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $gasto = AccionesIaHelper::crear($contexto, $assistant, AiMessageAction::TIPO_GASTO, 'gasto:c110', ['payload' => []], ['titulo' => 'Gasto', 'renglones' => []])['accion'];

        $tarea = AccionesIaHelper::crear($contexto, $assistant, AiMessageAction::TIPO_TAREA_NUEVA, 'tarea_nueva:gasto:c110', ['payload' => []], ['titulo' => 'Tarea', 'renglones' => []], $gasto->id);

        $this->assertSame(AiMessageAction::ESTADO_REEMPLAZADA, $gasto->fresh()->estado_guardado());
        $this->assertContains((int) $gasto->id, $tarea['reemplazadas']);

        // Y lo mismo de punta a punta, por la herramienta.
        $otra = $this->conversacion();

        $this->del_dueno($otra, 'Cargá el flete de 5000');

        $primero = $this->assistant($otra);

        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        $vivo = $this->herramienta('proponer_gasto', [
            'subcategoria_id' => $concepto->id,
            'monto'           => 5000,
            'pagos'           => [['metodo_de_pago_id' => $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO)->id, 'importe' => 5000, 'caja_id' => $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO)->id]],
        ], $otra, $primero);

        $this->assertTrue(!empty($vivo['tarjeta_id']), json_encode($vivo));

        $this->cerrar($primero);

        $this->del_dueno($otra, 'No, es para la semana que viene');

        $futura = $this->herramienta('proponer_gasto', [
            'subcategoria_id' => $concepto->id,
            'monto'           => 5000,
            'fecha'           => Carbon::now()->addDays(7)->format('Y-m-d'),
            'reemplaza_a'     => $vivo['tarjeta_id'],
        ], $otra, $this->assistant($otra));

        $this->assertTrue(!empty($futura['tarjeta_id']), json_encode($futura));
        $this->assertSame(AiMessageAction::TIPO_TAREA_NUEVA, AiMessageAction::find($futura['tarjeta_id'])->tipo);
        $this->assertSame(AiMessageAction::ESTADO_REEMPLAZADA, AiMessageAction::find($vivo['tarjeta_id'])->estado_guardado());
    }

    /**
     * Lo que la regla sigue frenando: una carga suelta no pisa la tarjeta de una planilla, y la de una planilla sí se reemplaza con otra.
     *
     * @test
     */
    public function solo_la_tarjeta_de_una_planilla_queda_protegida()
    {
        $conversation = $this->conversacion();

        $this->del_dueno($conversation, 'x');

        $assistant = $this->assistant($conversation);

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $de_planilla = AccionesIaHelper::crear($contexto, $assistant, AiMessageAction::TIPO_VENTAS_PLANILLA, 'ventas_planilla:c110', ['payload' => []], ['titulo' => 'Planilla', 'renglones' => []])['accion'];

        AccionesIaHelper::crear($contexto, $assistant, AiMessageAction::TIPO_GASTO, 'gasto:c110', ['payload' => []], ['titulo' => 'Gasto', 'renglones' => []], $de_planilla->id);

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $de_planilla->fresh()->estado_guardado());

        AccionesIaHelper::crear($contexto, $assistant, AiMessageAction::TIPO_VENTAS_PLANILLA, 'ventas_planilla:c110-otra', ['payload' => []], ['titulo' => 'Planilla', 'renglones' => []], $de_planilla->id);

        $this->assertSame(AiMessageAction::ESTADO_REEMPLAZADA, $de_planilla->fresh()->estado_guardado());
    }

    // ---------------------------------------------------------------------
    // R3. definir_planilla con un valor ya definido
    // ---------------------------------------------------------------------

    /**
     * 🔴 «SON DOS UNIDADES ES EL TOTAL», después «SI CARGALAS», y el modelo manda unidad: $ 100 × 2 da $ 200.
     *
     * @test
     */
    public function un_valor_ya_definido_no_se_cambia_sin_pedirlo_en_el_ultimo_mensaje()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $this->assertTrue($this->definir_con($conversation, 'SON DOS UNIDADES ES EL TOTAL', ['importe_es' => 'total'])['ok']);

        foreach (['SI CARGALAS', 'SI', 'dale', 'cargalas'] as $mensaje) {

            $rechazo = $this->definir_con($conversation, $mensaje, ['importe_es' => 'unidad']);

            $this->assertFalse($rechazo['ok'], $mensaje);
            $this->assertSame('sin_ancla', $rechazo['codigo'], $mensaje);
            $this->assertSame(AiPlanilla::IMPORTE_TOTAL, $planilla->fresh()->importe_es, $mensaje);
        }

        // Con las dos palabras en el mensaje, se pregunta.
        $las_dos = $this->definir_con($conversation, 'Es el total o por unidad, no me acuerdo', ['importe_es' => 'unidad']);

        $this->assertFalse($las_dos['ok']);
        $this->assertSame('importe_ambiguo', $las_dos['codigo']);
        $this->assertSame(AiPlanilla::IMPORTE_TOTAL, $planilla->fresh()->importe_es);

        // Con «SON DOS UNIDADES» a secas no alcanza para decir que es por unidad, aunque haya sido en la ventana.
        $this->assertFalse($this->definir_con($conversation, 'SON DOS UNIDADES', ['importe_es' => 'unidad'])['ok']);

        // Pidiéndolo, sí, y la lista lo dice.
        $this->assertTrue($this->definir_con($conversation, 'No, es por unidad', ['importe_es' => 'unidad'])['ok']);

        $propuesta = $this->proponer($conversation);

        $this->assertTrue($propuesta['ok'], json_encode($propuesta));

        $texto = AiMessageAction::find($propuesta['tarjeta_id'])->datos['texto'];

        $this->assertStringContainsString('Cambios que pediste: el importe es por unidad y no el total', $texto);
        $this->assertStringContainsString('2 × zz-c107 Bomba Beta (70102), $ 176.800', $texto);
    }

    /**
     * La primera vez, tampoco: si el último que habló del importe dijo otra cosa, o las dos, no se elige.
     *
     * @test
     */
    public function la_primera_definicion_mira_lo_ultimo_que_dijo_del_importe()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $this->assertFalse($this->definir_con($conversation, 'SON DOS UNIDADES ES EL TOTAL', ['importe_es' => 'unidad'])['ok'], '«dos unidades» es una cantidad.');
        $this->assertNull($planilla->fresh()->importe_es);

        $this->assertSame('importe_ambiguo', $this->definir_con($conversation, 'Total o unidad, no sé', ['importe_es' => 'total'])['codigo']);
        $this->assertNull($planilla->fresh()->importe_es);

        // «Cada renglón» no es «cada uno».
        $this->assertTrue($this->definir_con($conversation, 'Cada renglón es el total', ['importe_es' => 'total'])['ok']);
    }

    // ---------------------------------------------------------------------
    // R4. El número se ata al renglón
    // ---------------------------------------------------------------------

    /**
     * 🔴 «SI, con un cliente nuevo» cambiaba un renglón resuelto de cantidad 2 a 1: el síntoma del 31/10.
     *
     * @test
     */
    public function un_si_con_un_cliente_nuevo_no_cambia_una_cantidad_resuelta()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $rechazo = $this->corregir_con($conversation, 'SI, con un cliente nuevo', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 1]);

        $this->assertFalse($rechazo['ok']);
        $this->assertSame('2', PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['valores'][Roles::CANTIDAD]);

        // Sin nombrar el renglón, «un» y «una» no valen. Nombrándolo, sí: Planilla_tercera_revision_Test.
        $this->assertFalse($this->corregir_con($conversation, 'lleva una sola', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 1])['ok']);
        $this->assertFalse($this->corregir_con($conversation, 'lleva un solo producto', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 1])['ok']);

        // «uno» y un 1 sí.
        $this->assertTrue($this->corregir_con($conversation, 'Bruno lleva 1 solo', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 1])['ok']);
    }

    /**
     * Sin duda en ese campo, el mensaje tiene que nombrar ESE renglón: cliente, artículo, código o número.
     *
     * @test
     */
    public function corregir_un_renglon_resuelto_exige_nombrarlo_en_el_mismo_mensaje()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        foreach ([['cantidad', 3, 'son 3 unidades'], ['importe', 50000, 'salió 50.000'], ['cantidad', 2, 'son 2 unidades']] as $caso) {

            $rechazo = $this->corregir_con($conversation, $caso[2], ['renglon' => 2, 'campo' => $caso[0], 'valor' => $caso[1]]);

            $this->assertFalse($rechazo['ok'], $caso[2]);
            $this->assertSame('sin_ancla', $rechazo['codigo'], $caso[2]);
        }

        // El nombre en un mensaje y el número en otro tampoco.
        $this->del_dueno($conversation, 'Lo de Bruno está mal');
        $this->cerrar($this->assistant($conversation));

        $this->assertFalse($this->corregir_con($conversation, 'son 3 unidades', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 3])['ok']);

        // Otro renglón no alcanza.
        $this->assertFalse($this->corregir_con($conversation, 'Ana lleva 3', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 3])['ok']);

        foreach ([
            'Bruno lleva 3'                => [2, 'cliente'],
            'las bombas beta son 3'        => [2, 'artículo'],
            'el 70102 son 3'               => [2, 'código'],
            'en el renglón 2 van 3'        => [2, 'número con renglón'],
        ] as $mensaje => $caso) {

            $bueno = $this->corregir_con($conversation, $mensaje, ['renglon' => $caso[0], 'campo' => 'cantidad', 'valor' => 3]);

            $this->assertTrue($bueno['ok'], $caso[1] . ': ' . json_encode($bueno));
        }

        $this->assertSame(3.0, Roles::cantidad_de(PlanillaIaHelper::fila_numero($planilla->fresh(), 2)));
    }

    /**
     * Con una duda en ese campo, alcanza el valor: el dueño está contestando.
     *
     * @test
     */
    public function con_una_duda_en_ese_campo_alcanza_el_valor()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['TOTAL', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['1.000,00', '70101', '1.500', 'DNI', '17000001', 'Ana Prueba Uno'],
        ]));

        $bueno = $this->corregir_con($conversation, 'son 1500', ['renglon' => 1, 'campo' => 'cantidad', 'valor' => 1500]);

        $this->assertTrue($bueno['ok'], json_encode($bueno));
        $this->assertSame(1500.0, Roles::cantidad_de(PlanillaIaHelper::fila_numero($planilla->fresh(), 1)));
    }

    // ---------------------------------------------------------------------
    // R5. Dos tablas con importes distintos
    // ---------------------------------------------------------------------

    /**
     * 🔴 Una planilla con título TOTAL y otra pegada con P. UNIT y cantidad 2: se sumaba como total, la mitad de la plata y sin aviso.
     *
     * @test
     */
    public function una_segunda_tabla_con_otro_importe_no_se_mezcla()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['VENTA TOTAL', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['1.000,00', '70101', '2', 'DNI', '17000001', 'Ana Prueba Uno'],
        ]));

        $this->assertSame(AiPlanilla::IMPORTE_TOTAL, $planilla->importe_es);

        $this->planilla_pegada($conversation, $this->como_texto([
            ['P. UNIT', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['1.000,00', '70102', '2', 'DNI', '14000002', 'Bruno Prueba Dos'],
        ]));

        $planilla = $planilla->fresh();

        $this->assertCount(2, $planilla->filas);

        $de_la_total = PlanillaIaHelper::fila_numero($planilla, 1);
        $de_la_unitaria = PlanillaIaHelper::fila_numero($planilla, 2);

        $this->assertSame(1000.0, Roles::total_de($de_la_total, $planilla->importe_es));
        $this->assertSame(500.0, Roles::precio_unitario_de($de_la_total, $planilla->importe_es));

        $this->assertSame(2000.0, Roles::total_de($de_la_unitaria, $planilla->importe_es));
        $this->assertSame(1000.0, Roles::precio_unitario_de($de_la_unitaria, $planilla->importe_es));

        $propuesta = $this->proponer($conversation);

        $this->assertTrue($propuesta['ok'], json_encode($propuesta));
        $this->assertStringContainsString('Total: $ 3.000', AiMessageAction::find($propuesta['tarjeta_id'])->datos['texto']);

        $this->del_dueno($conversation, 'Sí');
        $this->responder($conversation, $this->assistant($conversation));

        $this->assertEquals([1000, 2000], $this->ventas_de($planilla)->pluck('total')->map(function ($total) {
            return (float) $total;
        })->all());
    }

    /**
     * Una tabla sin título y otra con título que no cruza: la segunda no define la primera, y la primera se sigue preguntando.
     *
     * @test
     */
    public function una_tabla_con_titulo_que_no_cruza_no_define_la_de_antes()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['C FINAL', '1.000,00', 'SKU 70101', 'Bomba', '#2000000000000001', '2', 'DNI', '17000001', 'Ana Prueba Uno'],
        ]));

        $this->assertNull($planilla->importe_es);

        $this->planilla_pegada($conversation, $this->como_texto([
            ['P. UNIT', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['1.000,00', '70102', '2', 'DNI', '14000002', 'Bruno Prueba Dos'],
        ]));

        $planilla = $planilla->fresh();

        $this->assertNull($planilla->importe_es, 'No cruzó: su título no dice nada de la otra tabla.');

        $this->del_dueno($conversation, 'Cargá');

        $respuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $this->assistant($conversation));

        $falta = implode(' | ', $respuesta['faltan']);

        $this->assertStringContainsString('TOTAL de esa venta', $falta);
        $this->assertStringContainsString('el renglón 1', $falta);
        $this->assertStringNotContainsString('los renglones 1, 2', $falta, 'La que trajo su título no se pregunta.');

        // Y lo que dice el dueño vale para toda la planilla.
        $this->assertTrue($this->definir_con($conversation, 'Todo es el total', ['importe_es' => 'total'])['ok']);

        $planilla = $planilla->fresh();

        $this->assertSame(1000.0, Roles::total_de(PlanillaIaHelper::fila_numero($planilla, 2), $planilla->importe_es));
    }

    /**
     * Si la segunda sí cruza con la primera, es la misma tabla: su título la define.
     *
     * @test
     */
    public function una_tabla_con_titulo_que_cruza_define_la_planilla()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['C FINAL', '1.000,00', 'SKU 70101', 'Bomba', '#2000000000000001', '2', 'DNI', '17000001', 'Ana Prueba Uno'],
        ]));

        $this->planilla_pegada($conversation, $this->como_texto([
            ['COMPROBANTE', 'P. UNIT', 'CODIGO', 'DETALLE', 'OPERACION', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['C FINAL', '1.000,00', 'SKU 70101', 'Bomba', '#2000000000000001', '2', 'DNI', '17000001', 'Ana Prueba Uno'],
        ]));

        $this->assertSame(AiPlanilla::IMPORTE_UNIDAD, $planilla->fresh()->importe_es);
        $this->assertCount(1, $planilla->fresh()->filas);
    }

    // ---------------------------------------------------------------------
    // R6. ×1000 por el modelo
    // ---------------------------------------------------------------------

    /**
     * 🔴 El dueño dice «1,500» y el modelo manda 1500: la venta se multiplica por mil.
     *
     * @test
     */
    public function el_valor_de_la_cantidad_se_compara_con_lo_que_dijo_el_dueno()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $this->assertFalse($this->corregir_con($conversation, 'Bruno lleva 1,500', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 1500])['ok']);
        $this->assertSame('2', PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['valores'][Roles::CANTIDAD]);

        // Lo ambiguo se rechaza para los dos lados, y se pregunta.
        foreach ([1500, 1.5] as $valor) {
            $this->assertFalse($this->corregir_con($conversation, 'Bruno lleva 1.500', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => $valor])['ok'], (string) $valor);
        }

        $this->assertSame('2', PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['valores'][Roles::CANTIDAD]);

        // Lo que dijo.
        $this->assertTrue($this->corregir_con($conversation, 'Bruno lleva 1,500', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 1.5])['ok']);
        $this->assertSame(1.5, Roles::cantidad_de(PlanillaIaHelper::fila_numero($planilla->fresh(), 2)));

        // En un importe, esa misma coma son miles.
        $this->assertTrue($this->corregir_con($conversation, 'Bruno salió 1,500', ['renglon' => 2, 'campo' => 'importe', 'valor' => 1500])['ok']);
        $this->assertFalse($this->corregir_con($conversation, 'Ana salió 1,500', ['renglon' => 1, 'campo' => 'importe', 'valor' => 1.5])['ok']);

        // El número del renglón no es la cantidad.
        $this->assertFalse($this->corregir_con($conversation, 'el renglón 3 está mal', ['renglon' => 3, 'campo' => 'cantidad', 'valor' => 3])['ok']);

        // La cita tiene que traer el número: no alcanza con que esté en otra parte del mensaje.
        $this->del_dueno($conversation, 'Bruno lleva 7, Ana no');

        $assistant = $this->assistant($conversation);

        $this->assertFalse($this->herramienta('corregir_renglon_de_planilla', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 7, 'lo_que_dijo_el_dueno' => 'Ana no'], $conversation, $assistant)['ok']);
    }

    // ---------------------------------------------------------------------
    // R7. Una columna sin título con algún número ambiguo
    // ---------------------------------------------------------------------

    /**
     * Sin títulos, con 2 / 1.500 / 3: quedaba sin rol, todas con cantidad 1 y sin preguntar el importe.
     *
     * @test
     */
    public function una_columna_de_numeros_chicos_con_uno_ambiguo_es_la_cantidad_con_duda_en_esa_celda()
    {
        $filas = [
            ['C FINAL', '500,00', 'SKU 70101', 'Bomba', '2', 'DNI', '17000001', 'Ana Prueba Uno'],
            ['C FINAL', '800,00', 'SKU 70101', 'Bomba', '1.500', 'DNI', '14000002', 'Bruno Prueba Dos'],
            ['C FINAL', '700,00', 'SKU 70102', 'Bomba', '3', 'DNI', '13000004', 'Carla Prueba Tres'],
        ];

        $roles = Roles::roles_de(null, $filas);

        $this->assertSame(Roles::CANTIDAD, $roles[4]);
        $this->assertSame(Roles::IMPORTE, $roles[1], 'El importe sigue siendo el de los centavos, aunque 1.500 sea más grande.');

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto($filas));

        $this->assertSame(2.0, Roles::cantidad_de(PlanillaIaHelper::fila_numero($planilla, 1)));
        $this->assertTrue(Roles::tiene_duda(PlanillaIaHelper::fila_numero($planilla, 2), Roles::CANTIDAD));
        $this->assertSame(3.0, Roles::cantidad_de(PlanillaIaHelper::fila_numero($planilla, 3)));

        $this->del_dueno($conversation, 'Cargá');

        $respuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $this->assistant($conversation));

        $falta = implode(' | ', $respuesta['faltan']);

        $this->assertStringContainsString('TOTAL de esa venta', $falta);
        $this->assertStringContainsString('la cantidad «1.500»', $falta);

        // Una columna de plata sin centavos no es una cantidad: ninguno es chico.
        $de_plata = Roles::roles_de(null, [
            ['500,00', 'SKU 70101', '1.500', 'DNI', '17000001'],
            ['800,00', 'SKU 70101', '12.500', 'DNI', '14000002'],
        ]);

        $this->assertNotSame(Roles::CANTIDAD, $de_plata[2] ?? null);
    }

    // ---------------------------------------------------------------------
    // R8 a R11
    // ---------------------------------------------------------------------

    /**
     * «a la caja 2» con una «Caja 2»: las dos palabras son vacías o números, y se rechazaba siempre.
     *
     * @test
     */
    public function una_caja_con_nombre_corto_se_reconoce_por_su_nombre_entero()
    {
        $this->assertTrue(Anclas::valor_en_mensajes('Caja 2', ['a la caja 2']));
        $this->assertTrue(Anclas::valor_en_mensajes('Sucursal 1', ['Cargalas en la sucursal 1, gracias']));
        $this->assertFalse(Anclas::valor_en_mensajes('Caja 2', ['a la caja 3']));
        $this->assertFalse(Anclas::valor_en_mensajes('Caja 2', ['la caja', '2 unidades']));
    }

    /**
     * Una tabla de comprobantes de compra no es de ventas: el comprobante solo no alcanza.
     *
     * @test
     */
    public function una_tabla_de_comprobantes_de_compra_no_es_una_planilla_de_ventas()
    {
        $compras = $this->como_texto([
            ['Factura A', '0001-00012345', 'Proveedor Uno', '$ 50.000', 'Martillos'],
            ['Factura A', '0001-00012346', 'Proveedor Dos', '$ 80.000', 'Pinzas'],
        ]);

        $this->assertNull(PlanillaDeTextoIaHelper::tabla_del_texto($compras));

        // Con un título de proveedor tampoco, aunque traiga CUIT.
        $this->assertNull(PlanillaDeTextoIaHelper::tabla_del_texto($this->como_texto([
            ['COMPROBANTE', 'CUIT', 'PROVEEDOR', 'TOTAL', 'DETALLE'],
            ['Factura A', self::CUIT_EMPRESA, 'Proveedor Uno', '$ 50.000', 'Martillos'],
        ])));

        // Separada por espacios, tampoco paga una lectura.
        $this->assertSame([], PlanillaDeTextoIaHelper::lineas_de_ventas_con_espacios("Factura A 0001-00012345 Proveedor Uno $ 50.000\nFactura A 0001-00012346 Proveedor Dos $ 80.000"));

        // Con un documento, una operación o un título de ventas, sí.
        $this->assertNotNull(PlanillaDeTextoIaHelper::tabla_del_texto($this->como_texto([['Factura A', '17000001', 'Ana Prueba Uno', '$ 50.000', 'Martillos']])));
        $this->assertNotNull(PlanillaDeTextoIaHelper::tabla_del_texto($this->como_texto([['Factura A', '#2000000000000001', 'Ana Prueba Uno', '$ 50.000', 'Martillos']])));
        $this->assertNotNull(PlanillaDeTextoIaHelper::tabla_del_texto($this->como_texto([
            ['COMPROBANTE', 'CLIENTE', 'TOTAL', 'DETALLE'],
            ['Factura A', 'Ana Prueba Uno', '$ 50.000', 'Martillos'],
        ])));

        // Y el modelo ve las líneas.
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $this->del_dueno($conversation, $compras);

        $this->responder($conversation, $this->assistant($conversation));

        $this->assertSame(0, AiPlanilla::where('ai_conversation_id', $conversation->id)->count());
        $this->assertStringContainsString('Proveedor Uno', json_encode($this->requests_del_modelo_que_conversa()[0]['messages'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Si el modelo repite un marcador, el dueño no lo tiene que leer.
     *
     * @test
     */
    public function los_marcadores_de_las_fotos_no_le_llegan_al_dueno()
    {
        $limpio = function ($texto) {
            return TextoFinalIaHelper::limpiar($texto, []);
        };

        $this->assertSame('Es un martillo marca Acme.', $limpio('Es un martillo marca Acme. [imagen_id 12]'));
        $this->assertSame('Es un martillo marca Acme.', $limpio('Es un martillo marca Acme. [imagen_id 12, 13]'));
        $this->assertSame('Es un martillo.', $limpio("[Foto que mandó el dueño, ya analizada: Un martillo galponero.] [imagen_id 12]\nEs un martillo."));
        $this->assertSame('Es un martillo.', $limpio("[Foto que mandó el dueño, ya analizada: Un martillo\ncon mango de madera.]\nEs un martillo."));
        $this->assertSame('Ya las vi.', $limpio("[Foto: planilla N°12 ya leída, 4 renglones; usá consultar_planilla, también si la persona solo quiere mirarla y no cargar ventas]\nYa las vi."));

        // Lo demás no se toca.
        $this->assertSame('El imagen_id es un dato interno.', $limpio('El imagen_id es un dato interno.'));
        $this->assertSame('Dice [Foto] en la etiqueta.', $limpio('Dice [Foto] en la etiqueta.'));
    }

    /**
     * Con seis fotos sin usar y una planilla como séptima, la planilla nunca se clasificaba: se clasifican las más nuevas.
     *
     * @test
     */
    public function se_clasifican_las_fotos_mas_nuevas()
    {
        $this->clasificador = false;
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '');

        $fotos = [];

        for ($orden = 1; $orden <= 7; $orden++) {
            $fotos[] = $this->foto($pedido, 300, 100, $orden);
        }

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

        $clasificadas = 0;

        foreach (Http::recorded() as $par) {

            if (mb_strpos($par[0]->body(), 'es_planilla') !== false) {
                $clasificadas++;
            }
        }

        $this->assertSame(6, $clasificadas);

        $this->assertNotNull(\Illuminate\Support\Facades\Cache::get(\App\Http\Controllers\Helpers\asistente_ia\LecturaDePlanillaIaHelper::PREFIJO_DE_CACHE . $fotos[6]->id), 'La más nueva sí.');
        $this->assertNull(\Illuminate\Support\Facades\Cache::get(\App\Http\Controllers\Helpers\asistente_ia\LecturaDePlanillaIaHelper::PREFIJO_DE_CACHE . $fotos[0]->id), 'La más vieja queda afuera.');
    }
    // ---------------------------------------------------------------------
    // R12. Una lectura sin JSON válido no puede perder la planilla
    // ---------------------------------------------------------------------

    /**
     * Lo que contesta la API cuando está caída un momento.
     *
     * @return array
     */
    protected function falla_transitoria()
    {
        return ['http_status' => 529, 'cuerpo' => ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'x']]];
    }

    /**
     * Lee una foto nueva con lo que hay en $this->lecturas.
     *
     * @return array
     */
    protected function leer_una_foto()
    {
        $this->falsear_ia();

        $conversation = $this->conversacion();

        return Lectura::leer($this->foto($this->del_dueno($conversation, '')), $conversation);
    }

    /**
     * 🔴 Un tropiezo de una lectura no es un problema de la foto: se reintenta esa lectura, y no hay que pedirle el texto al dueño.
     *
     * @test
     */
    public function una_lectura_sin_json_valido_o_con_una_falla_transitoria_se_reintenta()
    {
        $bien = $this->lectura($this->filas_de_prueba());

        foreach ([
            'texto sin JSON'      => $this->texto_del_modelo('Acá va la planilla, no pude darte el formato.'),
            'JSON sin filas'      => $this->texto_del_modelo('{"encabezado": null}'),
            'JSON roto'           => $this->texto_del_modelo('{"encabezado": null, "filas": [["a", "b"'),
            'falla de la API'     => $this->falla_transitoria(),
        ] as $caso => $tropiezo) {

            $this->lecturas = [
                'claude-sonnet-5-5' => [$tropiezo, $bien],
                'claude-haiku-5-5'  => $bien,
            ];

            $antes = count($this->lecturas_de('claude-sonnet-5-5'));

            $leida = $this->leer_una_foto();

            $this->assertSame(Lectura::LEIDA, $leida['resultado'], $caso);
            $this->assertSame(['claude-sonnet-5-5', 'claude-haiku-5-5'], $leida['lectura']['modelos'], $caso);
            $this->assertCount($antes + 2, $this->lecturas_de('claude-sonnet-5-5'), $caso . ': una sola vez más.');
        }
    }

    /**
     * Si la lectura A falla también al reintentar, el modelo de desempate lee en su lugar: dos lecturas válidas alcanzan.
     *
     * @test
     */
    public function si_la_lectura_a_falla_dos_veces_el_desempate_lee_en_su_lugar()
    {
        $bien = $this->lectura($this->filas_de_prueba());

        $this->lecturas = [
            'claude-sonnet-5-5' => [$this->texto_del_modelo('sin json'), $this->texto_del_modelo('otra vez sin json')],
            'claude-opus-5-5'   => $bien,
            'claude-haiku-5-5'  => $bien,
        ];

        $leida = $this->leer_una_foto();

        $this->assertSame(Lectura::LEIDA, $leida['resultado']);
        $this->assertSame(['claude-opus-5-5', 'claude-haiku-5-5'], $leida['lectura']['modelos']);
        $this->assertCount(2, $this->lecturas_de('claude-sonnet-5-5'));
        $this->assertCount(1, $this->lecturas_de('claude-opus-5-5'));

        $this->assertSame('default', json_decode($this->lecturas_de('claude-opus-5-5')[0][0]->body(), true)['fallbacks']);
    }

    /**
     * Lo mismo si es la segunda la que falla.
     *
     * @test
     */
    public function si_la_lectura_b_falla_dos_veces_el_desempate_lee_en_su_lugar()
    {
        $bien = $this->lectura($this->filas_de_prueba());

        $this->lecturas = [
            'claude-sonnet-5-5' => $bien,
            'claude-haiku-5-5'  => [$this->texto_del_modelo('sin json'), $this->falla_transitoria()],
            'claude-opus-5-5'   => $bien,
        ];

        $leida = $this->leer_una_foto();

        $this->assertSame(Lectura::LEIDA, $leida['resultado']);
        $this->assertSame(['claude-sonnet-5-5', 'claude-opus-5-5'], $leida['lectura']['modelos']);
    }

    /**
     * 🔴 Con una sola lectura válida no hay planilla: es lo que garantiza el doble control. Y sin haber otra para comparar, ni se paga.
     *
     * @test
     */
    public function una_sola_lectura_valida_no_alcanza()
    {
        $bien = $this->lectura($this->filas_de_prueba());

        // Sin la A ni el desempate: la B sola no serviría, y no se la llama.
        $this->lecturas = [
            'claude-sonnet-5-5' => [$this->texto_del_modelo('sin json'), $this->texto_del_modelo('sin json')],
            'claude-opus-5-5'   => [$this->texto_del_modelo('sin json'), $this->texto_del_modelo('sin json')],
            'claude-haiku-5-5'  => $bien,
        ];

        $leida = $this->leer_una_foto();

        $this->assertSame(Lectura::NO_SE_PUDO, $leida['resultado']);
        $this->assertCount(0, $this->lecturas_de('claude-haiku-5-5'));

        // La A sola.
        $this->lecturas = [
            'claude-sonnet-5-5' => $bien,
            'claude-haiku-5-5'  => [$this->texto_del_modelo('sin json'), $this->texto_del_modelo('sin json')],
            'claude-opus-5-5'   => [$this->texto_del_modelo('sin json'), $this->texto_del_modelo('sin json')],
        ];

        $this->assertSame(Lectura::NO_SE_PUDO, $this->leer_una_foto()['resultado']);
    }

    /**
     * Si las dos coinciden en la forma y falla el desempate, las celdas sin consenso son dudas: la planilla no se pierde. Si no coinciden en la
     * cantidad de filas, sin un tercero no hay cómo elegir.
     *
     * @test
     */
    public function si_falla_el_desempate_las_dos_lecturas_alcanzan_con_sus_dudas()
    {
        $bien = $this->filas_de_prueba();
        $mal = $this->filas_de_prueba();
        $mal[1][1] = '88.000,00';

        $this->lecturas = [
            'claude-sonnet-5-5' => $this->lectura($bien),
            'claude-haiku-5-5'  => $this->lectura($mal),
            'claude-opus-5-5'   => [$this->texto_del_modelo('sin json'), $this->texto_del_modelo('sin json')],
        ];

        $leida = $this->leer_una_foto();

        $this->assertSame(Lectura::LEIDA, $leida['resultado']);
        $this->assertArrayHasKey(1, $leida['tabla']['dudas']);
        $this->assertSame(['claude-sonnet-5-5', 'claude-haiku-5-5'], $leida['lectura']['modelos']);

        $this->lecturas = [
            'claude-sonnet-5-5' => $this->lectura($bien),
            'claude-haiku-5-5'  => $this->lectura(array_slice($bien, 0, 3)),
            'claude-opus-5-5'   => [$this->texto_del_modelo('sin json'), $this->texto_del_modelo('sin json')],
        ];

        $this->assertSame(Lectura::NO_SE_PUDO, $this->leer_una_foto()['resultado']);
    }

    /**
     * Lo que no va a cambiar no se reintenta: una negativa, un corte por el techo o un pedido rechazado. Sí prueba el otro modelo.
     *
     * @test
     */
    public function lo_que_no_va_a_cambiar_no_se_reintenta()
    {
        $bien = $this->lectura($this->filas_de_prueba());

        foreach (['refusal', 'max_tokens'] as $motivo) {

            $this->lecturas = [
                'claude-sonnet-5-5' => [$this->texto_del_modelo('', $motivo), $bien],
                'claude-opus-5-5'   => $bien,
                'claude-haiku-5-5'  => $bien,
            ];

            $antes = count($this->lecturas_de('claude-sonnet-5-5'));

            $leida = $this->leer_una_foto();

            $this->assertCount($antes + 1, $this->lecturas_de('claude-sonnet-5-5'), $motivo);
            $this->assertSame(['claude-opus-5-5', 'claude-haiku-5-5'], $leida['lectura']['modelos'], $motivo);
        }

        // Un 400: «sin stub» en el proveedor falso.
        $this->lecturas = [
            'claude-opus-5-5'  => $bien,
            'claude-haiku-5-5' => $bien,
        ];

        $antes = count($this->lecturas_de('claude-sonnet-5-5'));

        $this->assertSame(Lectura::LEIDA, $this->leer_una_foto()['resultado']);
        $this->assertCount($antes + 1, $this->lecturas_de('claude-sonnet-5-5'));
    }

    /**
     * El reintento va adentro del tope del turno: sin tiempo no se reintenta, y la foto queda para el turno siguiente.
     *
     * @test
     */
    public function el_reintento_respeta_el_tope_de_tiempo_del_turno()
    {
        $lecturas = 0;

        Http::fake(function ($request) use (&$lecturas) {

            if (mb_strpos($request->body(), 'es_planilla') !== false) {
                return Http::response($this->texto_del_modelo(json_encode(['es_planilla' => true])), 200);
            }

            $lecturas++;

            sleep(3);

            return Http::response($this->texto_del_modelo('sin json'), 200);
        });

        $conversation = $this->conversacion();
        $foto = $this->foto($this->del_dueno($conversation, ''));

        $inicio = time() - (Lectura::SEGUNDOS_POR_TURNO - Lectura::SEGUNDOS_DE_MARGEN - 2);

        $this->assertSame(Lectura::SIN_REVISAR, Lectura::leer($foto, $conversation, $inicio)['resultado']);
        $this->assertSame(1, $lecturas);
    }

    /**
     * Un texto pegado por espacios pasa por las mismas lecturas, con el mismo reintento.
     *
     * @test
     */
    public function el_reintento_tambien_vale_para_un_texto_leido_con_claude()
    {
        $bien = $this->lectura($this->filas_de_prueba());

        $this->lecturas = [
            'claude-sonnet-5-5' => [$this->texto_del_modelo('sin json'), $bien],
            'claude-haiku-5-5'  => $bien,
        ];

        $this->falsear_ia();

        $leida = Lectura::leer_texto(1, 'C FINAL 61.250,00 DNI 17000001', $this->conversacion(), time());

        $this->assertSame(Lectura::LEIDA, $leida['resultado']);
    }

    // ---------------------------------------------------------------------
    // R13. La guarda de tarjeta prometida no puede borrar una pregunta
    // ---------------------------------------------------------------------

    /**
     * 🔴 Conversación 26248: un pedido de un dato que dice que DESPUÉS va a armar la tarjeta no promete una tarjeta que exista.
     *
     * @test
     */
    public function pedir_un_dato_y_decir_que_despues_se_arma_la_tarjeta_no_afirma_una_tarjeta()
    {
        foreach ([
            'Como todavía me falta el dato del importe, no puedo armar las ventas: en los renglones 3 y 4 hay 2 unidades cada uno.' . "\n\n" . 'Contestame eso ("es el total" o "es el precio por unidad") y armo la tarjeta con las 11 ventas.',
            'Contestame eso y armo la tarjeta.',
            'Contestá si es el total y te armo la tarjeta.',
            'Respondeme si es el total o por unidad y te dejo la tarjeta.',
            'Aclarame el monto y te dejo la tarjeta para que la confirmes.',
            'Escribime la caja y te dejo la tarjeta lista.',
            'Necesito que me digas la caja y te dejo la tarjeta.',
            'Necesito que me confirmes el monto y armo la tarjeta del gasto.',
            'Contestame eso. Después armo la tarjeta.',
            'Contestame eso. Luego te dejo la tarjeta.',
            'Decime la caja. Armo la tarjeta con eso.',
            'Contestame eso y dejo la tarjeta para confirmar.',
        ] as $texto) {
            $this->assertFalse(TarjetaPrometidaIaHelper::afirma_una_tarjeta($texto), 'No afirma: ' . $texto);
        }

        // Para las otras cargas, igual.
        foreach (['Contestame eso y lo cargo.', 'Respondeme la caja y lo registro.', 'Necesito que me digas el monto y lo agendo.'] as $texto) {
            $this->assertSame('', TarjetaPrometidaIaHelper::afirma_una_carga_hecha($texto), 'No afirma: ' . $texto);
        }
    }

    /**
     * 🔴 El caso por el que existe la guarda sigue atrapado, también si hay un pedido en el texto.
     *
     * @test
     */
    public function la_guarda_sigue_atrapando_una_tarjeta_afirmada()
    {
        foreach ([
            'Dejé la tarjeta para confirmar.',
            'Te dejé la tarjeta para confirmar el gasto.',
            'Te dejo la tarjeta para confirmar.',
            'Armo la tarjeta con las 11 ventas.',
            'Listo, te armé la tarjeta.',
            'Contestame eso. Dejé la tarjeta para confirmar.',
            'Te dejo la tarjeta, contestame si está bien.',
            'Ya te armé la tarjeta, contestame si está bien.',
            'Dejé la tarjeta. Decime si está bien.',
            'Contestame si está bien: dejé la tarjeta para que la confirmes.',
        ] as $texto) {
            $this->assertTrue(TarjetaPrometidaIaHelper::afirma_una_tarjeta($texto), 'Afirma: ' . $texto);
        }
    }

    /**
     * De punta a punta: la pregunta le llega al dueño tal cual, sin el reintento ni el texto honesto.
     *
     * @test
     */
    public function la_pregunta_por_el_importe_le_llega_al_dueno()
    {
        $pregunta = 'Me falta un dato: en los renglones 2 y 3 hay 2 unidades.' . "\n\n" . 'Contestame eso ("es el total" o "es el precio por unidad") y armo la tarjeta con las 4 ventas.';

        $this->falsear_ia();

        $conversation = $this->conversacion();

        $this->planilla_pegada($conversation);

        $this->del_dueno($conversation, 'Cargá');

        $this->vueltas = [$this->texto_del_modelo($pregunta)];

        $texto = $this->responder($conversation, $this->assistant($conversation));

        $this->assertSame($pregunta, $texto);
        $this->assertCount(1, $this->requests_del_modelo_que_conversa(), 'Sin reintento.');
    }
}
