<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\AnclasDePlanillaIaHelper as Anclas;
use App\Http\Controllers\Helpers\asistente_ia\EjecucionDePlanillaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\LecturaDePlanillaIaHelper as Lectura;
use App\Http\Controllers\Helpers\asistente_ia\PlanillaDeTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PlanillaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RolesDePlanillaIaHelper as Roles;
use App\Http\Controllers\Helpers\asistente_ia\TarjetaPrometidaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiPlanilla;
use App\Models\SaleType;
use App\Services\AsistenteIa\AsistenteIaService;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\EscenariosDePlata;

/**
 * Misión agente-venta-por-planilla: la tercera revisión independiente (02/11/2026). Cada test se escribió antes que su arreglo, y
 * falló.
 *
 * Lo central es la regla general de las anclas: para cambiar algo YA RESUELTO, el último mensaje dice QUÉ renglón y el VALOR nuevo,
 * con palabras distintas. Lo que está en duda o sin resolver se contesta con el valor solo.
 *
 * Todo inventado: nombres, documentos, códigos y operaciones.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 *
 * @group chat-ia
 */
class Planilla_tercera_revision_Test extends PlanillaTestCase
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
     * Lo que dice el asistente, antes del próximo mensaje del dueño.
     *
     * @param  AiConversation  $conversation
     * @param  string  $texto
     * @return void
     */
    protected function dice_el_asistente(AiConversation $conversation, $texto)
    {
        $assistant = $this->assistant($conversation);

        $assistant->contenido = $texto;
        $assistant->estado = 'listo';
        $assistant->save();
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

    /**
     * Una charla sin ninguna planilla en curso: la planilla activa se comparte entre las charlas del dueño, y una tabla nueva cruzaría con ella.
     *
     * @return AiConversation
     */
    protected function charla_sin_planilla()
    {
        AiPlanilla::where('user_id', $this->dueno->id)->update(['estado' => AiPlanilla::ESTADO_REEMPLAZADA]);

        return $this->conversacion();
    }

    /**
     * El ejemplo que trae un rechazo: lo que va entre comillas dobles.
     *
     * @param  array  $rechazo
     * @return string
     */
    protected function ejemplo_de(array $rechazo)
    {
        $this->assertSame(1, preg_match('/"([^"]+)"/u', (string) $rechazo['error'], $partes), json_encode($rechazo, JSON_UNESCAPED_UNICODE));

        return $partes[1];
    }

    /**
     * Una tabla sin títulos con una venta de cantidad 2.
     *
     * @param  string  $operacion
     * @param  string  $documento
     * @param  string  $nombre
     * @param  string  $codigo
     * @param  string  $cantidad
     * @return string
     */
    protected function tabla_sin_titulo($operacion = '#2000000000000002', $documento = '14000002', $nombre = 'Bruno Prueba Dos', $codigo = 'SKU 70102', $cantidad = '2')
    {
        return $this->como_texto([['C FINAL', '1.000,00', $codigo, 'Bomba', $operacion, $cantidad, 'DNI', $documento, $nombre]]);
    }

    /**
     * Una tabla con ese título en la columna del importe y una venta de cantidad 2.
     *
     * @param  string  $titulo
     * @param  string  $documento
     * @param  string  $nombre
     * @param  string  $codigo
     * @return string
     */
    protected function tabla_con_titulo($titulo, $documento = '17000001', $nombre = 'Ana Prueba Uno', $codigo = '70101')
    {
        return $this->como_texto([
            [$titulo, 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['1.000,00', $codigo, '2', 'DNI', $documento, $nombre],
        ]);
    }

    // ---------------------------------------------------------------------
    // T1. Una fecha o un número suelto no nombran el renglón
    // ---------------------------------------------------------------------

    /**
     * @test
     */
    public function una_fecha_o_un_numero_suelto_no_nombran_el_renglon()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        foreach ([
            ['el 2 de octubre', 'cantidad', 2],
            ['confirmo la 11', 'cantidad', 11],
            ['el 2 son 3', 'cantidad', 3],
            ['2 son 3', 'cantidad', 3],
            ['al 2 ponele 3', 'cantidad', 3],
            ['numero 2 son 3', 'cantidad', 3],
            ['el 2 de octubre', 'importe', 2],
            ['el 2 no lo cargues', 'descartar', null],
        ] as $caso) {

            $rechazo = $this->corregir_con($conversation, $caso[0], ['renglon' => 2, 'campo' => $caso[1], 'valor' => $caso[2]]);

            $this->assertFalse($rechazo['ok'], $caso[0]);
            $this->assertSame('sin_ancla', $rechazo['codigo'], $caso[0]);
            $this->assertSame(AiPlanilla::FILA_PENDIENTE, PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['estado'], $caso[0]);
            $this->assertSame('2', PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['valores'][Roles::CANTIDAD], $caso[0]);
        }

        // El número que nombró el renglón no es el valor.
        foreach (['el renglón 2', 'la fila 2 está mal', 'línea 2'] as $mensaje) {

            $this->assertFalse($this->corregir_con($conversation, $mensaje, ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 2])['ok'], $mensaje);
        }

        // Una fecha tampoco, aunque el renglón esté nombrado.
        foreach ([
            ['Bruno compró el 2 de octubre', 2],
            ['Bruno compró el dos de octubre', 2],
            ['Bruno, el 13/10', 13],
            ['Bruno, el 13/10', 10],
            ['Bruno el 13-oct', 13],
            ['Bruno, 2/11/2026', 11],
        ] as $caso) {

            $this->assertFalse($this->corregir_con($conversation, $caso[0], ['renglon' => 2, 'campo' => 'cantidad', 'valor' => $caso[1]])['ok'], $caso[0]);
        }

        $this->assertFalse($this->corregir_con($conversation, 'Bruno, el 2 de octubre', ['renglon' => 2, 'campo' => 'importe', 'valor' => 2])['ok']);
        $this->assertTrue($this->corregir_con($conversation, 'Bruno compró el 2 de octubre y fueron 7', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 7])['ok']);

        foreach ([
            ['el renglón 2 son 3', 3],
            ['la fila 2 lleva 4', 4],
            ['línea 2: 5 unidades', 5],
            ['el renglón dos son 6', 6],
            ['el renglón 2 son 2', 2],
        ] as $caso) {

            $bueno = $this->corregir_con($conversation, $caso[0], ['renglon' => 2, 'campo' => 'cantidad', 'valor' => $caso[1]]);

            $this->assertTrue($bueno['ok'], $caso[0] . ': ' . json_encode($bueno, JSON_UNESCAPED_UNICODE));
            $this->assertSame((float) $caso[1], Roles::cantidad_de(PlanillaIaHelper::fila_numero($planilla->fresh(), 2)), $caso[0]);
        }

        $this->assertTrue($this->corregir_con($conversation, 'el renglón 2 no lo cargues', ['renglon' => 2, 'campo' => 'descartar'])['ok']);
        $this->assertSame(AiPlanilla::FILA_DESCARTADA, PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['estado']);
    }

    /**
     * El documento nombra el renglón, también con separadores, y sus dígitos no son el valor.
     *
     * @test
     */
    public function el_documento_nombra_el_renglon_y_no_cuenta_como_valor()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $this->assertFalse($this->corregir_con($conversation, '30-71234567-1', ['renglon' => 3, 'campo' => 'cantidad', 'valor' => 1])['ok']);

        $bueno = $this->corregir_con($conversation, 'el 30-71234567-1 lleva 3', ['renglon' => 3, 'campo' => 'cantidad', 'valor' => 3]);

        $this->assertTrue($bueno['ok'], json_encode($bueno, JSON_UNESCAPED_UNICODE));
        $this->assertSame(3.0, Roles::cantidad_de(PlanillaIaHelper::fila_numero($planilla->fresh(), 3)));

        $this->assertTrue($this->corregir_con($conversation, 'El 14.000.002 lleva 4', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 4])['ok']);
    }

    // ---------------------------------------------------------------------
    // T2. Artículo y cliente: el cruce del 31/10
    // ---------------------------------------------------------------------

    /**
     * 🔴 El cruce del 31/10: «el taladro son 2» con campo=articulo sobre el renglón de otro artículo le cambiaba el artículo.
     *
     * @test
     */
    public function un_articulo_resuelto_cambia_solo_si_el_mensaje_nombra_el_renglon_y_el_valor()
    {
        $taladro = $this->articulo_de_prueba('zz-c111 Taladro Percutor', ['bar_code' => '70109']);

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        foreach ([
            'el taladro son 2',
            'el taladro',
            'SI, con el taladro',
            'Bruno está mal',
            'Bruno',
        ] as $mensaje) {

            $rechazo = $this->corregir_con($conversation, $mensaje, ['renglon' => 2, 'campo' => 'articulo', 'valor' => '70109']);

            $this->assertFalse($rechazo['ok'], $mensaje);
            $this->assertSame('sin_ancla', $rechazo['codigo'], $mensaje . ': ' . json_encode($rechazo, JSON_UNESCAPED_UNICODE));
            $this->assertArrayNotHasKey('articulo_elegido_id', PlanillaIaHelper::fila_numero($planilla->fresh(), 2), $mensaje);
        }

        // La misma palabra no cuenta para las dos cosas: «bomba» nombra el renglón y no dice cuál bomba.
        $this->assertFalse($this->corregir_con($conversation, 'bomba', ['renglon' => 2, 'campo' => 'articulo', 'valor' => '70101'])['ok']);

        $bueno = $this->corregir_con($conversation, 'a Bruno ponele el taladro', ['renglon' => 2, 'campo' => 'articulo', 'valor' => '70109']);

        $this->assertTrue($bueno['ok'], json_encode($bueno, JSON_UNESCAPED_UNICODE));
        $this->assertSame((int) $taladro->id, (int) PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['articulo_elegido_id']);

        // «Una palabra del artículo que ESE renglón tiene HOY»: ya no es la bomba.
        $this->assertFalse($this->corregir_con($conversation, 'la bomba beta son 3', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 3])['ok']);
        $this->assertFalse($this->corregir_con($conversation, 'el 70102 son 3', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 3])['ok']);
        $this->assertTrue($this->corregir_con($conversation, 'el taladro son 3', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 3])['ok']);
    }

    /**
     * «SI, con un cliente nuevo» con campo=cliente le cambiaba el cliente a un renglón resuelto.
     *
     * @test
     */
    public function un_cliente_resuelto_cambia_solo_si_el_mensaje_nombra_el_renglon_y_el_valor()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        foreach ([
            ['SI, con un cliente nuevo', 'Cliente Nuevo'],
            ['el cliente es Zulma', 'Zulma'],
            ['Zulma', 'Zulma'],
            ['Bruno', 'Bruno Prueba Dos'],
            ['Bruno está mal', 'Zulma'],
        ] as $caso) {

            $rechazo = $this->corregir_con($conversation, $caso[0], ['renglon' => 2, 'campo' => 'cliente', 'valor' => $caso[1]]);

            $this->assertFalse($rechazo['ok'], $caso[0]);
            $this->assertSame('sin_ancla', $rechazo['codigo'], $caso[0]);
            $this->assertSame('Bruno Prueba Dos', PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['valores'][Roles::NOMBRE], $caso[0]);
        }

        $bueno = $this->corregir_con($conversation, 'la bomba beta es de Zulma', ['renglon' => 2, 'campo' => 'cliente', 'valor' => 'Zulma']);

        $this->assertTrue($bueno['ok'], json_encode($bueno, JSON_UNESCAPED_UNICODE));
        $this->assertSame('Zulma', PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['valores'][Roles::NOMBRE]);
    }

    /**
     * Lo que está en duda o sin resolver se sigue contestando con el valor solo, o con un «sí» a lo que preguntó el asistente.
     *
     * @test
     */
    public function un_campo_sin_resolver_se_contesta_con_el_valor_solo_o_con_un_si()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['TOTAL', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['1.000,00', '70101', '1.500', 'DNI', '17000001', 'Ana Prueba Uno'],
            ['1.000,00', '70199', '1', 'DNI', '14000002', 'Bruno Prueba Dos'],
        ]));

        $this->assertTrue($this->corregir_con($conversation, 'son 1500', ['renglon' => 1, 'campo' => 'cantidad', 'valor' => 1500])['ok']);
        $this->assertSame(1500.0, Roles::cantidad_de(PlanillaIaHelper::fila_numero($planilla->fresh(), 1)));

        // Un artículo sin resolver: con un «sí», el valor estaba en lo que preguntó el asistente.
        $this->dice_el_asistente($conversation, '¿El artículo del renglón 2 es el 70102?');

        $bueno = $this->corregir_con($conversation, 'SI', ['renglon' => 2, 'campo' => 'articulo', 'valor' => '70102']);

        $this->assertTrue($bueno['ok'], json_encode($bueno, JSON_UNESCAPED_UNICODE));

        // «un» y «una» no contestan una duda sin nombrar el renglón.
        $otra = $this->charla_sin_planilla();

        $this->planilla_pegada($otra, $this->como_texto([
            ['TOTAL', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['1.000,00', '70101', '1.500', 'DNI', '17000001', 'Ana Prueba Uno'],
        ]));

        $this->assertFalse($this->corregir_con($otra, 'una sola', ['renglon' => 1, 'campo' => 'cantidad', 'valor' => 1])['ok']);
        $this->assertTrue($this->corregir_con($otra, 'Ana lleva una sola', ['renglon' => 1, 'campo' => 'cantidad', 'valor' => 1])['ok']);
    }

    // ---------------------------------------------------------------------
    // El cruce de un mensaje con dos renglones
    // ---------------------------------------------------------------------

    /**
     * 🔴 «Bruno lleva 3 y Ana lleva 2», con el renglón de Bruno, valor 2 y cita «Ana lleva 2»: Bruno estaba nombrado en el mensaje y el
     * número en la cita, y el valor de una venta iba a parar a la otra. Si la cita nombra otro renglón y no este, se rechaza.
     *
     * @test
     */
    public function la_cita_que_nombra_otro_renglon_y_no_este_se_rechaza()
    {
        $taladro = $this->articulo_de_prueba('zz-c111 Taladro Percutor', ['bar_code' => '70109']);

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        foreach ([
            ['Bruno lleva 3 y Ana lleva 2', 'Ana lleva 2', 'cantidad', 2],
            ['Bruno lleva 3 y Ana lleva 5', 'Ana lleva 5', 'cantidad', 5],
            ['la bomba beta son 3 y el foco son 5', 'el foco son 5', 'cantidad', 5],
            ['el renglón 2 son 3 y el renglón 3 son 5', 'el renglón 3 son 5', 'cantidad', 5],
            ['lo de Bruno fue 70.000 y lo de Ana 80.000', 'lo de Ana 80.000', 'importe', 80000],
            ['a Bruno ponele el taladro y a Ana el foco', 'a Ana el foco', 'articulo', 'ZRRI-50LF'],
            ['Lo de Bruno está bien y lo de Ana no lo cargues', 'lo de Ana no lo cargues', 'descartar', null],
        ] as $caso) {

            $rechazo = $this->corregir_con($conversation, $caso[0], ['renglon' => 2, 'campo' => $caso[2], 'valor' => $caso[3], 'lo_que_dijo_el_dueno' => $caso[1]]);

            $fila = PlanillaIaHelper::fila_numero($planilla->fresh(), 2);

            $this->assertFalse($rechazo['ok'], $caso[0] . ': ' . json_encode($rechazo, JSON_UNESCAPED_UNICODE));
            $this->assertSame('sin_ancla', $rechazo['codigo'], $caso[0]);
            $this->assertStringContainsString('otro renglón', $rechazo['error'], $caso[0]);
            $this->assertSame('2', $fila['valores'][Roles::CANTIDAD], $caso[0]);
            $this->assertSame(AiPlanilla::FILA_PENDIENTE, $fila['estado'], $caso[0]);
            $this->assertArrayNotHasKey('articulo_elegido_id', $fila, $caso[0]);
            $this->assertArrayNotHasKey('total_corregido', $fila, $caso[0]);
        }

        $this->assertNotNull($taladro);
    }

    /**
     * Si el mensaje nombra dos renglones, el valor tiene que estar pegado al renglón pedido en la cita.
     *
     * @test
     */
    public function con_dos_renglones_en_el_mensaje_el_valor_va_pegado_al_renglon()
    {
        $taladro = $this->articulo_de_prueba('zz-c111 Taladro Percutor', ['bar_code' => '70109']);

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        foreach ([
            ['Bruno lleva 3 y Ana lleva 5', 'Bruno lleva 3 y Ana lleva 5', 5, 'La cita entera: el 5 es de Ana.'],
            ['Bruno lleva 3 y Ana lleva 5', 'lleva 3', 3, 'La cita no dice de quién.'],
            ['Bruno 3 Ana 5', 'Bruno 3 Ana 5', 3, 'Sin nada que los separe, no se elige.'],
            ['5 para Ana y 7 para Bruno', '5 para Ana y 7 para Bruno', 5, 'El 5 es de Ana, aunque venga primero.'],
            ['Ana lleva 5, Bruno lleva 3', 'Ana lleva 5, Bruno lleva 3', 5, 'Con una coma.'],
        ] as $caso) {

            $rechazo = $this->corregir_con($conversation, $caso[0], ['renglon' => 2, 'campo' => 'cantidad', 'valor' => $caso[2], 'lo_que_dijo_el_dueno' => $caso[1]]);

            $this->assertFalse($rechazo['ok'], $caso[3] . ': ' . json_encode($rechazo, JSON_UNESCAPED_UNICODE));
            $this->assertSame('sin_ancla', $rechazo['codigo'], $caso[3]);
            $this->assertSame('2', PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['valores'][Roles::CANTIDAD], $caso[3]);
        }

        $this->assertFalse($this->corregir_con($conversation, 'a Bruno ponele el taladro y a Ana el foco', ['renglon' => 2, 'campo' => 'articulo', 'valor' => 'ZRRI-50LF'])['ok'], 'El foco es de Ana.');

        // Pegado al renglón, pasa: la cita entera o su parte, con el decimal y el punto de los miles enteros.
        foreach ([
            ['Bruno lleva 3 y Ana lleva 5', 'Bruno lleva 3 y Ana lleva 5', 3],
            ['Bruno lleva 4 y Ana lleva 5', 'Bruno lleva 4', 4],
            ['5 para Ana y 7 para Bruno', '5 para Ana y 7 para Bruno', 7],
            ['Bruno lleva 1,5 y Ana lleva 5', 'Bruno lleva 1,5 y Ana lleva 5', 1.5],
            ['el renglón 2 son 6 y el renglón 3 son 5', 'el renglón 2 son 6', 6],
        ] as $caso) {

            $bueno = $this->corregir_con($conversation, $caso[0], ['renglon' => 2, 'campo' => 'cantidad', 'valor' => $caso[2], 'lo_que_dijo_el_dueno' => $caso[1]]);

            $this->assertTrue($bueno['ok'], $caso[0] . ': ' . json_encode($bueno, JSON_UNESCAPED_UNICODE));
            $this->assertSame((float) $caso[2], Roles::cantidad_de(PlanillaIaHelper::fila_numero($planilla->fresh(), 2)), $caso[0]);
        }

        $importe = $this->corregir_con($conversation, 'Lo de Bruno fue 70.000. Ana lleva 5', ['renglon' => 2, 'campo' => 'importe', 'valor' => 70000]);

        $this->assertTrue($importe['ok'], json_encode($importe, JSON_UNESCAPED_UNICODE));

        $articulo = $this->corregir_con($conversation, 'a Bruno ponele el taladro y a Ana el foco', ['renglon' => 2, 'campo' => 'articulo', 'valor' => '70109']);

        $this->assertTrue($articulo['ok'], json_encode($articulo, JSON_UNESCAPED_UNICODE));
        $this->assertSame((int) $taladro->id, (int) PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['articulo_elegido_id']);

        // Una duda también: el valor de otro no la contesta. Un valor solo sí, porque el mensaje no nombra a otro.
        $otra = $this->charla_sin_planilla();

        $this->planilla_pegada($otra, $this->como_texto([
            ['TOTAL', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['1.000,00', '70101', '1.500', 'DNI', '17000001', 'Ana Prueba Uno'],
            ['1.000,00', '70102', '2', 'DNI', '14000002', 'Bruno Prueba Dos'],
        ]));

        $this->assertFalse($this->corregir_con($otra, 'Bruno lleva 1500 y Ana lleva 7', ['renglon' => 1, 'campo' => 'cantidad', 'valor' => 1500, 'lo_que_dijo_el_dueno' => 'Bruno lleva 1500'])['ok']);
        $this->assertTrue($this->corregir_con($otra, 'son 1500', ['renglon' => 1, 'campo' => 'cantidad', 'valor' => 1500])['ok']);
    }

    /**
     * 🔴 Lo que comparten dos renglones, o lo que es el valor nuevo, no nombra al otro: si no, se rechazaría una corrección buena y el dueño
     * no tendría cómo pedirla. En una planilla real, el mismo artículo se repite en muchas ventas.
     *
     * @test
     */
    public function lo_compartido_y_el_valor_nuevo_no_nombran_otro_renglon()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        // El foco es del renglón 3, y acá es el valor nuevo del renglón 2.
        $articulo = $this->corregir_con($conversation, 'a Bruno ponele el foco', ['renglon' => 2, 'campo' => 'articulo', 'valor' => 'ZRRI-50LF']);

        $this->assertTrue($articulo['ok'], json_encode($articulo, JSON_UNESCAPED_UNICODE));

        // Ana es la clienta del renglón 1, y acá es el valor nuevo del renglón 4.
        $cliente = $this->corregir_con($conversation, 'el maletin es de Ana', ['renglon' => 4, 'campo' => 'cliente', 'valor' => 'Ana']);

        $this->assertTrue($cliente['ok'], json_encode($cliente, JSON_UNESCAPED_UNICODE));

        // El mismo artículo en dos renglones: «bombas» nombra a este, y no por eso a la otra venta.
        $otra = $this->charla_sin_planilla();

        $de_la_otra = $this->planilla_pegada($otra, $this->como_texto([
            ['TOTAL', 'CODIGO', 'DETALLE', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['1.000,00', '70101', 'Bomba', '2', 'DNI', '17000001', 'Ana Prueba Uno'],
            ['1.000,00', '70101', 'Bomba', '2', 'DNI', '14000002', 'Bruno Prueba Dos'],
        ]));

        $bueno = $this->corregir_con($otra, 'Bruno lleva 3 bombas', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 3]);

        $this->assertTrue($bueno['ok'], json_encode($bueno, JSON_UNESCAPED_UNICODE));
        $this->assertSame(3.0, Roles::cantidad_de(PlanillaIaHelper::fila_numero($de_la_otra->fresh(), 2)));

        // Y la otra venta sí se nombra con su cliente, aunque el artículo sea el mismo.
        $this->assertFalse($this->corregir_con($otra, 'Bruno lleva 4 bombas y Ana lleva 5 bombas', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 5, 'lo_que_dijo_el_dueno' => 'Ana lleva 5 bombas'])['ok']);

        // Un número suelto puede ser el valor: «son tres» no nombra a «Carla Prueba Tres». Y «el renglón 3» sí nombra al 3.
        $this->assertFalse(Anclas::nombra_otro_renglon(
            ['numero' => 2, 'valores' => [Roles::NOMBRE => 'Bruno Prueba Dos']],
            [['numero' => 2, 'valores' => [Roles::NOMBRE => 'Bruno Prueba Dos']], ['numero' => 4, 'valores' => [Roles::NOMBRE => 'Carla Prueba Tres']]],
            'Bruno, son tres unidades',
            'cantidad'
        ));

        $this->assertTrue(Anclas::nombra_otro_renglon(
            ['numero' => 2, 'valores' => [Roles::NOMBRE => 'Bruno Prueba Dos']],
            [['numero' => 2, 'valores' => [Roles::NOMBRE => 'Bruno Prueba Dos']], ['numero' => 3, 'valores' => [Roles::NOMBRE => 'Empresa Prueba']]],
            'Bruno lleva 2 y el renglón 3 lleva 5',
            'cantidad'
        ));

        // Un código de pocos dígitos puede ser el valor: «lleva 12» no nombra al renglón que tiene el código 12. Uno largo sí lo nombra.
        $bruno = ['numero' => 2, 'valores' => [Roles::NOMBRE => 'Bruno Prueba Dos', Roles::CODIGO => '70102']];
        $de_codigo_corto = ['numero' => 3, 'valores' => [Roles::NOMBRE => 'Ana Prueba Uno', Roles::CODIGO => '12']];
        $de_codigo_largo = ['numero' => 4, 'valores' => [Roles::NOMBRE => 'Carla Prueba Tres', Roles::CODIGO => '70109']];

        $filas = [$bruno, $de_codigo_corto, $de_codigo_largo];

        $this->assertFalse(Anclas::nombra_otro_renglon($bruno, $filas, 'Bruno lleva 12', 'cantidad'));
        $this->assertTrue(Anclas::nombra_otro_renglon($bruno, $filas, 'Bruno lleva 3 y el 70109 lleva 5', 'cantidad'));
    }

    // ---------------------------------------------------------------------
    // T3. «un» y «una»
    // ---------------------------------------------------------------------

    /**
     * @test
     */
    public function un_y_una_valen_como_uno_solo_con_el_renglon_nombrado()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        foreach (['SI, con un cliente nuevo', 'lleva una caja', 'es una venta', 'una sola'] as $mensaje) {

            $this->assertFalse($this->corregir_con($conversation, $mensaje, ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 1])['ok'], $mensaje);
            $this->assertSame('2', PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['valores'][Roles::CANTIDAD], $mensaje);
        }

        $this->assertFalse($this->corregir_con($conversation, 'Bruno lleva una', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 2])['ok'], '«una» es 1, no 2.');

        $bueno = $this->corregir_con($conversation, 'Bruno lleva una caja', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 1]);

        $this->assertTrue($bueno['ok'], json_encode($bueno, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1.0, Roles::cantidad_de(PlanillaIaHelper::fila_numero($planilla->fresh(), 2)));

        $this->assertTrue($this->corregir_con($conversation, 'A Empresa ponele un foco', ['renglon' => 3, 'campo' => 'cantidad', 'valor' => 1])['ok']);
    }

    // ---------------------------------------------------------------------
    // T4. Sin bucles
    // ---------------------------------------------------------------------

    /**
     * 🔴 El rechazo pide TODO JUNTO, con los datos del renglón, y lo que pide pasa: el dueño no recibe dos preguntas partidas.
     *
     * @test
     */
    public function el_rechazo_pide_todo_en_un_solo_mensaje_y_lo_que_pide_pasa()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        // Le falta el renglón, y le falta el valor: el pedido es el mismo.
        foreach (['son 3 unidades', 'Bruno está mal'] as $mensaje) {

            $rechazo = $this->corregir_con($conversation, $mensaje, ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 3]);

            $this->assertFalse($rechazo['ok']);
            $this->assertStringContainsString('Decime en un solo mensaje a quién y cuánto: por ejemplo, "Bruno lleva 1', $rechazo['error'], $mensaje);
        }

        $this->assertStringContainsString('de qué renglón', $this->corregir_con($conversation, 'son 3 unidades', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 3])['error']);
        $this->assertStringContainsString('la cantidad 3', $this->corregir_con($conversation, 'Bruno está mal', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 3])['error']);

        $de_la_cantidad = $this->corregir_con($conversation, 'son 3 unidades', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 3]);

        // Lo que pide pasa.
        $this->assertTrue($this->corregir_con($conversation, $this->ejemplo_de($de_la_cantidad), ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 1])['ok']);

        $del_importe = $this->corregir_con($conversation, 'salió 70.000', ['renglon' => 2, 'campo' => 'importe', 'valor' => 70000]);

        $this->assertStringContainsString('Decime en un solo mensaje a quién y qué importe: por ejemplo, "lo de Bruno fue $ 50.000"', $del_importe['error']);
        $this->assertTrue($this->corregir_con($conversation, $this->ejemplo_de($del_importe), ['renglon' => 2, 'campo' => 'importe', 'valor' => 50000])['ok']);

        $del_articulo = $this->corregir_con($conversation, 'es el 70101', ['renglon' => 2, 'campo' => 'articulo', 'valor' => '70101']);

        $this->assertStringContainsString('Decime en un solo mensaje a quién y qué artículo: por ejemplo, "a Bruno ponele', $del_articulo['error']);

        $del_cliente = $this->corregir_con($conversation, 'es de Zulma', ['renglon' => 2, 'campo' => 'cliente', 'valor' => 'Zulma']);

        $this->assertStringContainsString('Decime en un solo mensaje qué venta y de qué cliente: por ejemplo, "lo de Bruno es de', $del_cliente['error']);

        $de_descartar = $this->corregir_con($conversation, 'esa no', ['renglon' => 4, 'campo' => 'descartar']);

        $this->assertStringContainsString('Decime en un solo mensaje cuál no cargo: por ejemplo, "lo de Carla no lo cargues"', $de_descartar['error']);
        $this->assertTrue($this->corregir_con($conversation, $this->ejemplo_de($de_descartar), ['renglon' => 4, 'campo' => 'descartar'])['ok']);
        $this->assertSame(AiPlanilla::FILA_DESCARTADA, PlanillaIaHelper::fila_numero($planilla->fresh(), 4)['estado']);

        // Un renglón sin nombre: el ejemplo lo nombra por su número, y también pasa.
        $otra = $this->charla_sin_planilla();

        $this->planilla_pegada($otra, $this->como_texto([
            ['TOTAL', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO'],
            ['1.000,00', '70101', '2', 'DNI', '17000001'],
        ]));

        $sin_nombre = $this->corregir_con($otra, 'son 3', ['renglon' => 1, 'campo' => 'cantidad', 'valor' => 3]);

        $this->assertTrue($this->corregir_con($otra, $this->ejemplo_de($sin_nombre), ['renglon' => 1, 'campo' => 'cantidad', 'valor' => 1])['ok'], $sin_nombre['error']);
    }

    /**
     * Un «sí» que no contesta nada: el modelo no le pregunta nada si la persona no pidió ningún cambio, y si lo pidió, una sola vez y todo junto.
     *
     * @test
     */
    public function un_si_rechazado_tambien_pide_todo_junto_y_no_inventa_una_pregunta()
    {
        $conversation = $this->conversacion();

        $this->planilla_pegada($conversation);

        $rechazo = $this->corregir_con($conversation, 'SI', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 3]);

        $this->assertSame('un_si_no_corrige', $rechazo['codigo']);
        $this->assertStringContainsString('Decime en un solo mensaje a quién y cuánto', $rechazo['error']);
        $this->assertStringContainsString('Si no pidió ningún cambio, no le preguntes nada', $rechazo['error']);
        $this->assertStringContainsString('proponer_ventas_de_planilla', $rechazo['error']);
    }

    /**
     * De punta a punta: lo que dice el rechazo le llega al modelo, y lo que el modelo pregunta le llega al dueño tal cual.
     *
     * @test
     */
    public function el_pedido_del_rechazo_le_llega_al_dueno_tal_cual()
    {
        $this->falsear_ia();

        $conversation = $this->conversacion();

        $this->planilla_pegada($conversation);

        $this->del_dueno($conversation, 'son 3 unidades');

        $rechazo = 'Decime en un solo mensaje a quién y cuánto: por ejemplo, "Bruno lleva 1 Bomba Presurizadora Beta"';

        $this->vueltas = [
            $this->pide_herramienta('corregir_renglon_de_planilla', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 3, 'lo_que_dijo_el_dueno' => 'son 3 unidades']),
            $this->texto_del_modelo($rechazo),
        ];

        $this->assertSame($rechazo, $this->responder($conversation, $this->assistant($conversation)));

        $pedidos = $this->requests_del_modelo_que_conversa();

        $this->assertCount(2, $pedidos);
        $this->assertStringContainsString('en un solo mensaje', json_encode($pedidos[1]['messages'], JSON_UNESCAPED_UNICODE));

        $this->assertFalse(TarjetaPrometidaIaHelper::afirma_una_tarjeta($rechazo));
        $this->assertSame('', TarjetaPrometidaIaHelper::afirma_una_carga_hecha($rechazo));
    }

    /**
     * La herramienta y la regla del prompt piden lo mismo: nombran el renglón y no lo enseñan con un número suelto.
     *
     * @test
     */
    public function la_herramienta_y_el_prompt_piden_el_renglon_y_el_valor_juntos()
    {
        $this->falsear_ia();

        $conversation = $this->conversacion();

        $this->del_dueno($conversation, 'Hola');

        $this->vueltas = [$this->texto_del_modelo('Hola.')];

        $this->responder($conversation, $this->assistant($conversation));

        $pedido = $this->requests_del_modelo_que_conversa()[0];

        $herramienta = null;

        foreach ($pedido['tools'] as $tool) {

            if ($tool['name'] === 'corregir_renglon_de_planilla') {
                $herramienta = $tool;
            }
        }

        $this->assertStringContainsString('«el renglón 5 no lo cargues»', $herramienta['description']);
        $this->assertStringNotContainsString('«el 5 no lo cargues»', $herramienta['description']);
        $this->assertStringNotContainsString('«el artículo del 4 es el 70102»', $herramienta['description']);
        $this->assertStringContainsString('qué renglón y el valor nuevo', $herramienta['description']);

        $prompt = is_array($pedido['system']) ? json_encode($pedido['system'], JSON_UNESCAPED_UNICODE) : (string) $pedido['system'];

        $this->assertStringContainsString('en un solo mensaje', $prompt);
    }

    // ---------------------------------------------------------------------
    // T5. Primer importe_es
    // ---------------------------------------------------------------------

    /**
     * @test
     */
    public function solo_una_frase_inequivoca_dice_que_es_el_importe()
    {
        foreach ([
            'En total son 11 ventas',
            'En total son 11 ventas, cada una con su factura',
            'cada una con su factura',
            'cada uno con su remito',
            'el total de ventas es 11',
            'total 11',
            'SON DOS UNIDADES',
            'dale, cargalas todas',
        ] as $mensaje) {

            $this->assertNull(Anclas::lo_que_dice_del_importe($mensaje), $mensaje);
        }

        foreach ([
            'es el total',
            'ES EL TOTAL DE LA VENTA',
            'son el total',
            'son totales',
            'el total de la venta',
            'total de la venta',
            'Cada renglón es el total',
            'Todo es el total',
            'SON DOS UNIDADES ES EL TOTAL',
            'Es el total y van a la cuenta corriente de cada uno',
        ] as $mensaje) {

            $this->assertSame(AiPlanilla::IMPORTE_TOTAL, Anclas::lo_que_dice_del_importe($mensaje), $mensaje);
        }

        foreach (['por unidad', 'No, es por unidad', 'es el precio unitario', 'precio unitario', '$ 500 c/u', 'cada uno a 500', 'cada una a $ 500'] as $mensaje) {

            $this->assertSame(AiPlanilla::IMPORTE_UNIDAD, Anclas::lo_que_dice_del_importe($mensaje), $mensaje);
        }

        foreach (['Es el total o por unidad, no me acuerdo', 'Total o unidad, no sé', 'por unidad, en total son 11'] as $mensaje) {

            $this->assertSame('las_dos', Anclas::lo_que_dice_del_importe($mensaje), $mensaje);
        }
    }

    /**
     * La respuesta corta vale solo si contesta lo que preguntó el asistente.
     *
     * @test
     */
    public function una_respuesta_corta_vale_si_contesta_la_pregunta_del_asistente()
    {
        $pregunta = '¿El importe es el TOTAL de esa venta o el PRECIO POR UNIDAD?';

        foreach (['total', 'el total', 'Total.', 'los totales'] as $mensaje) {

            $this->assertSame(AiPlanilla::IMPORTE_TOTAL, Anclas::lo_que_dice_del_importe($mensaje, $pregunta), $mensaje);
            $this->assertNull(Anclas::lo_que_dice_del_importe($mensaje, '¿Cómo se cobraron?'), $mensaje);
            $this->assertNull(Anclas::lo_que_dice_del_importe($mensaje), $mensaje);
        }

        foreach (['unidad', 'la unidad', 'unitario', 'el unitario', 'cada uno'] as $mensaje) {

            $this->assertSame(AiPlanilla::IMPORTE_UNIDAD, Anclas::lo_que_dice_del_importe($mensaje, $pregunta), $mensaje);
            $this->assertNull(Anclas::lo_que_dice_del_importe($mensaje), $mensaje);
        }

        // El modelo pregunta todo junto: la respuesta también viene junta.
        foreach (['total, efectivo, caja efectivo', 'TOTAL EN EFECTIVO', 'efectivo y total', 'Caja Efectivo. Total.', 'total a la cuenta corriente'] as $mensaje) {

            $this->assertSame(AiPlanilla::IMPORTE_TOTAL, Anclas::lo_que_dice_del_importe($mensaje, $pregunta), $mensaje);
            $this->assertNull(Anclas::lo_que_dice_del_importe($mensaje), $mensaje);
        }

        $this->assertSame(AiPlanilla::IMPORTE_UNIDAD, Anclas::lo_que_dice_del_importe('unitario, en efectivo', $pregunta));

        // Con la pregunta hecha, lo que no la contesta sigue sin definir nada.
        foreach (['En total son 11 ventas', 'cada una con su factura', 'total 11', 'el total de ventas es 11', 'SI'] as $mensaje) {

            $this->assertNull(Anclas::lo_que_dice_del_importe($mensaje, $pregunta), $mensaje);
        }
    }

    /**
     * De punta a punta: «En total son 11 ventas» no define nada, y contestar la pregunta sí.
     *
     * @test
     */
    public function la_primera_definicion_no_se_toma_de_cualquier_total()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        foreach (['En total son 11 ventas' => 'total', 'cada una con su factura' => 'unidad'] as $mensaje => $valor) {

            $rechazo = $this->definir_con($conversation, $mensaje, ['importe_es' => $valor]);

            $this->assertFalse($rechazo['ok'], $mensaje);
            $this->assertSame('sin_ancla', $rechazo['codigo'], $mensaje);
            $this->assertNull($planilla->fresh()->importe_es, $mensaje);
        }

        $this->assertFalse($this->definir_con($conversation, 'el total', ['importe_es' => 'total'])['ok'], 'Nadie le preguntó.');

        // Lo que contesta la pregunta vale en ese último mensaje, no después.
        $this->dice_el_asistente($conversation, 'Me falta un dato: ¿el importe es el TOTAL de esa venta o el PRECIO POR UNIDAD?');

        $this->del_dueno($conversation, 'el total');

        $this->dice_el_asistente($conversation, 'Dale.');

        $this->assertFalse($this->definir_con($conversation, 'SI', ['importe_es' => 'total'])['ok'], 'El «el total» quedó en otro turno, sin pregunta justo antes del último.');

        $this->dice_el_asistente($conversation, 'Me falta un dato: ¿el importe es el TOTAL de esa venta o el PRECIO POR UNIDAD?');

        $bueno = $this->definir_con($conversation, 'el total', ['importe_es' => 'total']);

        $this->assertTrue($bueno['ok'], json_encode($bueno, JSON_UNESCAPED_UNICODE));
        $this->assertSame(AiPlanilla::IMPORTE_TOTAL, $planilla->fresh()->importe_es);
    }

    // ---------------------------------------------------------------------
    // T6. Un título explícito no se pisa
    // ---------------------------------------------------------------------

    /**
     * 🔴 Un «es el total» solo define los renglones que no tenían título: el título de la columna no se pisa por una frase genérica.
     *
     * @test
     */
    public function un_es_el_total_no_pisa_el_titulo_de_otra_tabla()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->tabla_con_titulo('P. UNIT'));

        $de_titulo = PlanillaIaHelper::fila_numero($planilla, 1);

        $this->assertSame(AiPlanilla::IMPORTE_UNIDAD, $de_titulo['importe_es']);
        $this->assertSame(AiPlanilla::IMPORTE_DE_TITULO, $de_titulo['importe_de']);

        $this->planilla_pegada($conversation, $this->tabla_sin_titulo());

        $bueno = $this->definir_con($conversation, 'es el total', ['importe_es' => 'total']);

        $this->assertTrue($bueno['ok'], json_encode($bueno, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($bueno['cambio']);
        $this->assertStringContainsString('El renglón 1 trae un título', $bueno['nota']);

        $planilla = $planilla->fresh();

        $primera = PlanillaIaHelper::fila_numero($planilla, 1);
        $segunda = PlanillaIaHelper::fila_numero($planilla, 2);

        $this->assertSame(AiPlanilla::IMPORTE_UNIDAD, $primera['importe_es'], 'El título no se pisa.');
        $this->assertSame(2000.0, Roles::total_de($primera, $planilla->importe_es));

        $this->assertSame(AiPlanilla::IMPORTE_TOTAL, $segunda['importe_es']);
        $this->assertSame(AiPlanilla::IMPORTE_DE_DUENO, $segunda['importe_de']);
        $this->assertSame(1000.0, Roles::total_de($segunda, $planilla->importe_es));

        // Definir lo que no tenía nada no es un cambio.
        $this->assertArrayNotHasKey('cambios', (array) $planilla->definiciones);

        // Nombrando el renglón, sí se cambia, y va a Cambios.
        $nombrado = $this->definir_con($conversation, 'lo de Ana es el total', ['importe_es' => 'total']);

        $this->assertTrue($nombrado['ok'], json_encode($nombrado, JSON_UNESCAPED_UNICODE));

        $planilla = $planilla->fresh();

        $this->assertSame(1000.0, Roles::total_de(PlanillaIaHelper::fila_numero($planilla, 1), $planilla->importe_es));

        $propuesta = $this->proponer($conversation);

        $this->assertTrue($propuesta['ok'], json_encode($propuesta, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('Cambios que pediste: el importe es el total y no por unidad en el renglón 1', AiMessageAction::find($propuesta['tarjeta_id'])->datos['texto']);
    }

    /**
     * Para cambiar un título hay que nombrar el renglón o decir «todas». Si no, no cambia nada y el mensaje no dice que quedó.
     *
     * @test
     */
    public function un_titulo_cambia_solo_nombrando_el_renglon_o_diciendo_todas()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->tabla_con_titulo('P. UNIT'));

        $sin_cambio = $this->definir_con($conversation, 'es el total', ['importe_es' => 'total']);

        $this->assertFalse($sin_cambio['ok']);
        $this->assertSame('sin_ancla', $sin_cambio['codigo']);
        $this->assertStringContainsString('Decime en un solo mensaje cuáles: por ejemplo, "todas son el total"', $sin_cambio['error']);

        // Un «sí» o un mensaje que no habla del importe, tampoco.
        foreach (['SI', 'cargalas'] as $mensaje) {

            $this->assertFalse($this->definir_con($conversation, $mensaje, ['importe_es' => 'total'])['ok'], $mensaje);
        }

        $planilla = $planilla->fresh();

        $this->assertSame(AiPlanilla::IMPORTE_UNIDAD, $planilla->importe_es);
        $this->assertSame(2000.0, Roles::total_de(PlanillaIaHelper::fila_numero($planilla, 1), $planilla->importe_es));

        $todas = $this->definir_con($conversation, 'todas son el total', ['importe_es' => 'total']);

        $this->assertTrue($todas['ok'], json_encode($todas, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($todas['cambio']);

        $planilla = $planilla->fresh();

        $this->assertSame(AiPlanilla::IMPORTE_TOTAL, $planilla->importe_es);
        $this->assertSame(1000.0, Roles::total_de(PlanillaIaHelper::fila_numero($planilla, 1), $planilla->importe_es));
        $this->assertContains('el importe es el total y no por unidad', $planilla->definiciones['cambios']);
    }

    /**
     * Una foto o una tabla nueva que cruza completa lo que faltaba, y no pisa lo que dijo el dueño.
     *
     * @test
     */
    public function un_titulo_que_cruza_no_pisa_lo_que_dijo_el_dueno()
    {
        $conversation = $this->conversacion();

        $sin_titulo = $this->como_texto([['C FINAL', '1.000,00', 'SKU 70101', 'Bomba', '#2000000000000001', '2', 'DNI', '17000001', 'Ana Prueba Uno']]);

        $planilla = $this->planilla_pegada($conversation, $sin_titulo);

        $this->assertTrue($this->definir_con($conversation, 'es el total', ['importe_es' => 'total'])['ok']);

        $this->planilla_pegada($conversation, $this->como_texto([
            ['COMPROBANTE', 'P. UNIT', 'CODIGO', 'DETALLE', 'OPERACION', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['C FINAL', '1.000,00', 'SKU 70101', 'Bomba', '#2000000000000001', '2', 'DNI', '17000001', 'Ana Prueba Uno'],
        ]));

        $planilla = $planilla->fresh();

        $this->assertCount(1, $planilla->filas);
        $this->assertSame(1000.0, Roles::total_de(PlanillaIaHelper::fila_numero($planilla, 1), $planilla->importe_es));
    }

    // ---------------------------------------------------------------------
    // T7. Una tabla sin título no hereda
    // ---------------------------------------------------------------------

    /**
     * 🔴 Una tabla sin título, o con PRECIO a secas, y alguna cantidad distinta de 1 NUNCA hereda el importe de otra.
     *
     * @test
     */
    public function una_tabla_sin_titulo_no_hereda_el_importe_de_una_con_titulo()
    {
        foreach ([
            'sin título' => $this->tabla_sin_titulo(),
            'PRECIO'     => $this->tabla_con_titulo('PRECIO', '14000002', 'Bruno Prueba Dos', '70102'),
        ] as $caso => $segunda) {

            $conversation = $this->charla_sin_planilla();

            $planilla = $this->planilla_pegada($conversation, $this->tabla_con_titulo('VENTA TOTAL'));

            $this->planilla_pegada($conversation, $segunda);

            $respuesta = $this->proponer($conversation);

            $this->assertFalse(!empty($respuesta['tarjeta_id']), $caso . ': ' . json_encode($respuesta, JSON_UNESCAPED_UNICODE));

            $falta = implode(' | ', $respuesta['faltan']);

            $this->assertStringContainsString('TOTAL de esa venta', $falta, $caso);
            $this->assertStringContainsString('el renglón 2', $falta, $caso);

            // Y al modelo tampoco le muestra un total heredado.
            $consulta = $this->herramienta('consultar_planilla', [], $conversation, $this->assistant($conversation));

            $this->assertArrayHasKey('total', $consulta['renglones'][0], $caso);
            $this->assertArrayNotHasKey('total', $consulta['renglones'][1], $caso);
            $this->assertStringContainsString('Renglón 2 (Bruno Prueba Dos, ', $falta, $caso);
            $this->assertStringContainsString('Bomba', $falta, $caso);
            $this->assertStringNotContainsString('Ana Prueba Uno', $falta, $caso);

            // Un «sí» o la ventana no contestan: lo tiene que decir en el último mensaje, aunque sea lo mismo que la otra tabla.
            $this->del_dueno($conversation, 'es el total');
            $this->dice_el_asistente($conversation, 'Bien.');

            $rechazo = $this->definir_con($conversation, 'SI', ['importe_es' => 'total']);

            $this->assertFalse($rechazo['ok'], $caso);
            $this->assertSame('sin_ancla', $rechazo['codigo'], $caso);
            $this->assertArrayNotHasKey('importe_es', PlanillaIaHelper::fila_numero($planilla->fresh(), 2), $caso);

            // Con la misma respuesta que la otra tabla, se marcan esos renglones.
            $bueno = $this->definir_con($conversation, 'también es el total', ['importe_es' => 'total']);

            $this->assertTrue($bueno['ok'], $caso . ': ' . json_encode($bueno, JSON_UNESCAPED_UNICODE));
            $this->assertTrue($bueno['cambio'], $caso);
            $this->assertSame(AiPlanilla::IMPORTE_TOTAL, PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['importe_es'], $caso);

            $propuesta = $this->proponer($conversation);

            $this->assertTrue($propuesta['ok'], $caso . ': ' . json_encode($propuesta, JSON_UNESCAPED_UNICODE));
            $this->assertNotEmpty($propuesta['tarjeta_id'], $caso);
        }
    }

    /**
     * Tampoco hereda lo que el dueño dijo para otra tabla: lo que dijo vale para lo que había.
     *
     * @test
     */
    public function una_tabla_sin_titulo_no_hereda_lo_que_dijo_el_dueno_de_otra()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->tabla_sin_titulo('#2000000000000001', '17000001', 'Ana Prueba Uno', 'SKU 70101'));

        $this->assertTrue($this->definir_con($conversation, 'es el total', ['importe_es' => 'total'])['ok']);

        $this->planilla_pegada($conversation, $this->tabla_sin_titulo());

        $falta = implode(' | ', $this->proponer($conversation)['faltan']);

        $this->assertStringContainsString('el renglón 2', $falta);
        $this->assertStringContainsString('Bruno Prueba Dos', $falta);

        // Sin cantidades distintas de 1 no hay nada que preguntar.
        $otra = $this->charla_sin_planilla();

        $this->planilla_pegada($otra, $this->tabla_con_titulo('VENTA TOTAL'));

        $this->planilla_pegada($otra, $this->tabla_sin_titulo('#2000000000000002', '14000002', 'Bruno Prueba Dos', 'SKU 70102', '1'));

        $respuesta = $this->proponer($otra);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta, JSON_UNESCAPED_UNICODE));
        $this->assertNotEmpty($respuesta['tarjeta_id']);
    }

    // ---------------------------------------------------------------------
    // T8. Si no cambia nada, no dice «listo»
    // ---------------------------------------------------------------------

    /**
     * @test
     */
    public function si_la_respuesta_no_cambia_nada_no_dice_que_quedo()
    {
        $conversation = $this->conversacion();

        $this->planilla_pegada($conversation);

        $primera = $this->definir_con($conversation, 'es el total, cobradas en Efectivo', ['importe_es' => 'total', 'metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO]);

        $this->assertTrue($primera['ok'], json_encode($primera, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($primera['cambio']);
        $this->assertStringContainsString('Quedó guardado', $primera['nota']);

        $igual = $this->definir_con($conversation, 'SI', ['importe_es' => 'total', 'metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO]);

        $this->assertTrue($igual['ok'], json_encode($igual, JSON_UNESCAPED_UNICODE));
        $this->assertFalse($igual['cambio']);
        $this->assertStringNotContainsString('Quedó guardado', $igual['nota']);
        $this->assertStringNotContainsString('listo', mb_strtolower(str_replace('«listo»', '', $igual['nota'])));
        $this->assertStringContainsString('no cambió nada', $igual['nota']);

        // Una corrección al mismo valor tampoco.
        $misma = $this->corregir_con($conversation, 'Bruno lleva 2', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 2]);

        $this->assertTrue($misma['ok'], json_encode($misma, JSON_UNESCAPED_UNICODE));
        $this->assertFalse($misma['cambio']);
        $this->assertStringNotContainsString('Quedó corregido', $misma['nota']);

        $mismo_importe = $this->corregir_con($conversation, 'lo de Bruno fue 88.400', ['renglon' => 2, 'campo' => 'importe', 'valor' => 88400]);

        $this->assertTrue($mismo_importe['ok']);
        $this->assertFalse($mismo_importe['cambio']);
    }

    /**
     * Lo que no cambió nada no deja vieja la tarjeta: el «sí» que venía sigue valiendo.
     *
     * @test
     */
    public function lo_que_no_cambia_nada_no_deja_vieja_la_tarjeta()
    {
        $conversation = $this->conversacion();

        $this->tarjeta_lista($conversation);

        $tarjeta = AiMessageAction::where('tipo', AiMessageAction::TIPO_VENTAS_PLANILLA)->orderBy('id', 'DESC')->first();

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado);

        $this->assertFalse($this->definir_con($conversation, 'es el total', ['importe_es' => 'total'])['cambio']);
        $this->assertFalse($this->corregir_con($conversation, 'Bruno lleva 2', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 2])['cambio']);

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->fresh()->estado);

        $this->assertTrue($this->corregir_con($conversation, 'Bruno lleva 3', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 3])['cambio']);

        $this->assertSame(AiMessageAction::ESTADO_REEMPLAZADA, $tarjeta->fresh()->estado);
    }

    // ---------------------------------------------------------------------
    // T9. Cobro, método, caja, sucursal y tipo ya definidos
    // ---------------------------------------------------------------------

    /**
     * 🔴 «a la cuenta corriente» y después «SI» con metodo=Efectivo: pasaba a contado sin que nadie lo pidiera.
     *
     * @test
     */
    public function un_si_no_saca_una_venta_de_la_cuenta_corriente()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $this->assertTrue($this->definir_con($conversation, 'van a la cuenta corriente', ['cobro' => 'cuenta_corriente'])['ok']);

        foreach (['SI', 'dale', 'SI cargalas'] as $mensaje) {

            foreach ([['metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO], ['cobro' => 'contado']] as $input) {

                $rechazo = $this->definir_con($conversation, $mensaje, $input);

                $this->assertFalse($rechazo['ok'], $mensaje . ' ' . json_encode($input));
                $this->assertSame('sin_ancla', $rechazo['codigo'], $mensaje);
                $this->assertSame('cuenta_corriente', $planilla->fresh()->cobro, $mensaje);
                $this->assertNull($planilla->fresh()->metodo_de_pago, $mensaje);
            }
        }

        // Un método dicho antes tampoco: tiene que estar en el último mensaje.
        $this->del_dueno($conversation, 'hay algunas en efectivo');
        $this->dice_el_asistente($conversation, 'Bien.');

        $this->assertFalse($this->definir_con($conversation, 'bueno', ['metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO])['ok']);
        $this->assertSame('cuenta_corriente', $planilla->fresh()->cobro);

        // Pidiéndolo en el último mensaje, sí, y va a Cambios.
        $bueno = $this->definir_con($conversation, 'No, se cobraron en Efectivo', ['metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO]);

        $this->assertTrue($bueno['ok'], json_encode($bueno, JSON_UNESCAPED_UNICODE));

        $planilla = $planilla->fresh();

        $this->assertSame('contado', $planilla->cobro);
        $this->assertContains('el cobro es Efectivo y no a la cuenta corriente', $planilla->definiciones['cambios']);
    }

    /**
     * @test
     */
    public function un_cobro_de_contado_tambien_necesita_ancla()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        foreach (['Cargalas', 'SI'] as $mensaje) {

            $rechazo = $this->definir_con($conversation, $mensaje, ['cobro' => 'contado']);

            $this->assertFalse($rechazo['ok'], $mensaje);
            $this->assertSame('sin_ancla', $rechazo['codigo'], $mensaje);
            $this->assertNull($planilla->fresh()->cobro, $mensaje);
        }

        foreach (['de contado', 'las pagaron en Efectivo', 'ya están cobradas'] as $mensaje) {

            $otra = $this->charla_sin_planilla();

            $de_la_otra = $this->planilla_pegada($otra);

            $bueno = $this->definir_con($otra, $mensaje, ['cobro' => 'contado']);

            $this->assertTrue($bueno['ok'], $mensaje . ': ' . json_encode($bueno, JSON_UNESCAPED_UNICODE));
            $this->assertSame('contado', $de_la_otra->fresh()->cobro, $mensaje);
        }

        // De contado a la cuenta, también en el último mensaje y a Cambios.
        $otra = $this->charla_sin_planilla();

        $de_la_otra = $this->planilla_pegada($otra);

        $this->assertTrue($this->definir_con($otra, 'de contado', ['cobro' => 'contado'])['ok']);

        $this->del_dueno($otra, 'alguna capaz va a la cuenta');
        $this->dice_el_asistente($otra, 'Bien.');

        $this->assertFalse($this->definir_con($otra, 'SI', ['cobro' => 'cuenta_corriente'])['ok']);
        $this->assertSame('contado', $de_la_otra->fresh()->cobro);

        $this->assertTrue($this->definir_con($otra, 'mejor a la cuenta corriente', ['cobro' => 'cuenta_corriente'])['ok']);
        $this->assertContains('el cobro es a la cuenta corriente y no de contado', $de_la_otra->fresh()->definiciones['cambios']);
    }

    /**
     * @test
     */
    public function un_metodo_una_caja_una_sucursal_o_un_tipo_definidos_no_cambian_con_un_si()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $this->assertTrue($this->definir_con($conversation, 'es el total, en Efectivo, Caja Efectivo', [
            'importe_es'     => 'total',
            'metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO,
            'caja'           => TestingFerreteriaSeeder::CAJA_EFECTIVO,
        ])['ok']);

        foreach ([
            ['caja', TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::CAJA_MP, 'la caja'],
            ['sucursal', null, 'Sucursal Norte', 'la sucursal'],
            ['tipo_de_venta', null, 'Mostrador Norte', 'el tipo de venta'],
        ] as $caso) {

            // La primera vez, en la ventana.
            if (is_null($caso[1])) {

                $this->assertTrue($this->definir_con($conversation, 'es el Sur', [$caso[0] => $caso[3] === 'la sucursal' ? 'Sucursal Sur' : 'Mostrador Sur'])['ok'], $caso[0]);

                $caso[1] = $caso[3] === 'la sucursal' ? 'Sucursal Sur' : 'Mostrador Sur';
            }

            // Después, en el último mensaje y sin un «sí»: decirlo antes no alcanza.
            $this->del_dueno($conversation, 'capaz ' . $caso[2]);
            $this->dice_el_asistente($conversation, 'Bien.');

            foreach (['SI', 'bueno'] as $mensaje) {

                $rechazo = $this->definir_con($conversation, $mensaje, [$caso[0] => $caso[2]]);

                $this->assertFalse($rechazo['ok'], $caso[0] . ' ' . $mensaje);
                $this->assertSame('sin_ancla', $rechazo['codigo'], $caso[0]);
                $this->assertSame($caso[1], $planilla->fresh()->definiciones[$caso[0]], $caso[0]);
            }

            $bueno = $this->definir_con($conversation, 'no, es ' . $caso[2], [$caso[0] => $caso[2]]);

            $this->assertTrue($bueno['ok'], $caso[0] . ': ' . json_encode($bueno, JSON_UNESCAPED_UNICODE));
            $this->assertTrue($bueno['cambio'], $caso[0]);
            $this->assertSame($caso[2], $planilla->fresh()->definiciones[$caso[0]], $caso[0]);
            $this->assertContains($caso[3] . ' es ' . $caso[2] . ' y no ' . $caso[1], $planilla->fresh()->definiciones['cambios'], $caso[0]);
        }

        // El método, igual: «el cobro es» va a Cambios.
        $this->assertFalse($this->definir_con($conversation, 'SI', ['metodo_de_pago' => 'Transferencia'])['ok']);
        $this->assertSame(TestingFerreteriaSeeder::PAGO_EFECTIVO, $planilla->fresh()->metodo_de_pago);

        $bueno = $this->definir_con($conversation, 'no, fue Transferencia', ['metodo_de_pago' => 'Transferencia']);

        $this->assertTrue($bueno['ok'], json_encode($bueno, JSON_UNESCAPED_UNICODE));
        $this->assertContains('el cobro es Transferencia y no Efectivo', $planilla->fresh()->definiciones['cambios']);
    }

    /**
     * «Una sola opción» vale la primera vez: no hay nada que elegir. Para cambiar lo que ya está, no.
     *
     * @test
     */
    public function la_unica_opcion_vale_solo_la_primera_vez()
    {
        SaleType::where('user_id', $this->dueno->id)->delete();

        SaleType::create(['user_id' => $this->dueno->id, 'name' => 'Mostrador']);

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $this->assertTrue($this->definir_con($conversation, 'Cargalas', ['tipo_de_venta' => 'Mostrador'])['ok'], 'Es la única.');
        $this->assertSame('Mostrador', $planilla->fresh()->definiciones['tipo_de_venta']);

        $this->assertFalse($this->definir_con($conversation, 'Cargalas', ['tipo_de_venta' => 'Otro'])['ok'], 'Con un valor ya definido no alcanza con que haya una sola.');
        $this->assertSame('Mostrador', $planilla->fresh()->definiciones['tipo_de_venta']);
    }

    // ---------------------------------------------------------------------
    // T10. Roles sin títulos
    // ---------------------------------------------------------------------

    /**
     * 🔴 Sin títulos, «2 / 1.500 / 3» junto a «45000 / 30000 / 12000»: la chica quedaba como IMPORTE y salían ventas de $ 2.
     *
     * @test
     */
    public function una_columna_chica_no_le_gana_el_importe_a_una_grande_sin_decimales()
    {
        $filas = [
            ['SKU 70101', '2', '45000', 'DNI', '17000001', 'Ana Prueba Uno'],
            ['SKU 70102', '1.500', '30000', 'DNI', '14000002', 'Bruno Prueba Dos'],
            ['SKU 70101', '3', '12000', 'DNI', '13000004', 'Carla Prueba Tres'],
        ];

        $roles = Roles::roles_de(null, $filas);

        $this->assertSame(Roles::CANTIDAD, $roles[1]);
        $this->assertSame(Roles::IMPORTE, $roles[2]);

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto($filas));

        $this->assertNotNull($planilla, 'Es una planilla.');

        $primera = PlanillaIaHelper::fila_numero($planilla, 1);

        $this->assertSame('45000', $primera['valores'][Roles::IMPORTE]);
        $this->assertSame(2.0, Roles::cantidad_de($primera));

        // La ambigua queda como duda: no se elige en silencio.
        $falta = implode(' | ', $this->proponer($conversation)['faltan']);

        $this->assertStringContainsString('la cantidad «1.500»', $falta);
    }

    /**
     * «1.500 / 2.500 / 3.250» junto a «45.000,00»: quedaba sin rol y cantidad 1. Es candidata a cantidad, y se pregunta.
     *
     * @test
     */
    public function una_columna_con_formato_de_miles_al_lado_de_una_con_decimales_mas_altos_es_candidata_a_cantidad()
    {
        $filas = [
            ['SKU 70101', '1.500', '45.000,00', 'DNI', '17000001', 'Ana Prueba Uno'],
            ['SKU 70102', '2.500', '30.000,00', 'DNI', '14000002', 'Bruno Prueba Dos'],
            ['SKU 70101', '3.250', '12.000,00', 'DNI', '13000004', 'Carla Prueba Tres'],
        ];

        $roles = Roles::roles_de(null, $filas);

        $this->assertSame(Roles::CANTIDAD, $roles[1]);
        $this->assertSame(Roles::IMPORTE, $roles[2]);

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto($filas));

        $primera = PlanillaIaHelper::fila_numero($planilla, 1);

        $this->assertNull(Roles::cantidad_de($primera), 'No se asume cantidad 1.');
        $this->assertSame('45.000,00', $primera['valores'][Roles::IMPORTE]);

        $falta = implode(' | ', $this->proponer($conversation)['faltan']);

        $this->assertStringContainsString('la cantidad «1.500»', $falta);
        $this->assertStringContainsString('la cantidad «3.250»', $falta);
    }

    /**
     * «1.500 / 12.500» más altos que «500,00 / 800,00»: no se puede decidir. Se pide la fila de títulos, y nunca se elige en silencio.
     *
     * @test
     */
    public function si_no_se_puede_decidir_que_columna_es_cada_cosa_se_piden_los_titulos()
    {
        $filas = [
            ['500,00', 'SKU 70101', '1.500', 'DNI', '17000001', 'Ana Prueba Uno'],
            ['800,00', 'SKU 70102', '12.500', 'DNI', '14000002', 'Bruno Prueba Dos'],
        ];

        $roles = Roles::roles_de(null, $filas);

        $this->assertSame(Roles::SIN_DECIDIR, $roles[0]);
        $this->assertSame(Roles::SIN_DECIDIR, $roles[2]);
        $this->assertNotContains(Roles::IMPORTE, $roles);
        $this->assertNotContains(Roles::CANTIDAD, $roles);

        $this->assertTrue(PlanillaDeTextoIaHelper::parece_de_ventas($roles, false, null, [['1', 'SKU 70101', '2', 'CUIT', self::CUIT_EMPRESA, 'x']]), 'Sigue siendo una planilla: no vuelve a pasar por el modelo como texto.');

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto($filas));

        $this->assertNotNull($planilla);

        $primera = PlanillaIaHelper::fila_numero($planilla, 1);

        $this->assertNull(Roles::cantidad_de($primera), 'No se asume cantidad 1.');
        $this->assertNull(Roles::total_de($primera, null));

        $respuesta = $this->proponer($conversation);

        $this->assertFalse(!empty($respuesta['tarjeta_id']));

        $falta = implode(' | ', $respuesta['faltan']);

        $this->assertStringContainsString('qué columna es cada cosa', $falta);
        $this->assertStringContainsString('fila de títulos', $falta);
        $this->assertSame(1, substr_count($falta, 'fila de títulos'), 'Una sola vez, no renglón por renglón.');
        $this->assertStringNotContainsString('no tiene un importe que se pueda leer', $falta);
        $this->assertStringNotContainsString('no tiene una cantidad que se pueda leer', $falta);

        // Con la misma tabla y sus títulos queda resuelto.
        $this->planilla_pegada($conversation, $this->como_texto([
            ['TOTAL', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['500,00', 'SKU 70101', '2', 'DNI', '17000001', 'Ana Prueba Uno'],
            ['800,00', 'SKU 70102', '3', 'DNI', '14000002', 'Bruno Prueba Dos'],
        ]));

        $falta = implode(' | ', $this->proponer($conversation)['faltan'] ?? []);

        $this->assertStringNotContainsString('fila de títulos', $falta);
    }

    /**
     * Sin código y dos columnas de enteros grandes: tampoco se elige. Lo de siempre sigue igual.
     *
     * @test
     */
    public function dos_columnas_de_enteros_grandes_sin_codigo_no_se_eligen_y_lo_de_siempre_sigue_igual()
    {
        $dos_enteros = Roles::roles_de(null, [
            ['45000', '70101', '2', 'DNI', '17000001', 'Ana Prueba Uno'],
            ['30000', '70102', '1', 'DNI', '14000002', 'Bruno Prueba Dos'],
        ]);

        $this->assertSame(Roles::SIN_DECIDIR, $dos_enteros[0]);
        $this->assertSame(Roles::SIN_DECIDIR, $dos_enteros[1]);
        $this->assertSame(Roles::CANTIDAD, $dos_enteros[2]);

        // Plata con decimales, un código numérico y cantidades: la plata es el importe.
        $de_siempre = Roles::roles_de(null, [
            ['1.000,00', '70101', '2', 'DNI', '17000001', 'Ana Prueba Uno'],
            ['2.500,00', '70102', '1', 'DNI', '14000002', 'Bruno Prueba Dos'],
        ]);

        $this->assertSame(Roles::IMPORTE, $de_siempre[0]);
        $this->assertSame(Roles::CODIGO, $de_siempre[1]);
        $this->assertSame(Roles::CANTIDAD, $de_siempre[2]);

        // Dos columnas con decimales: la más alta es el importe, y la otra no es candidata a nada.
        $dos_de_plata = Roles::roles_de(null, [
            ['SKU 70101', '1.000,00', '1.210,00', '2', 'DNI', '17000001', 'Ana Prueba Uno'],
            ['SKU 70102', '2.000,00', '2.420,00', '1', 'DNI', '14000002', 'Bruno Prueba Dos'],
        ]);

        $this->assertSame(Roles::IMPORTE, $dos_de_plata[2]);
        $this->assertSame(Roles::CANTIDAD, $dos_de_plata[3]);
        $this->assertArrayNotHasKey(1, $dos_de_plata);
    }

    // ---------------------------------------------------------------------
    // T11. Dudas que no preguntaban
    // ---------------------------------------------------------------------

    /**
     * 🔴 FACTURA A contra C FINAL decide el IVA del cliente nuevo, y una operación mal leída no detecta la venta repetida.
     *
     * @test
     */
    public function una_celda_sin_consenso_en_comprobante_operacion_o_tipo_de_documento_es_duda()
    {
        $roles = [0 => Roles::COMPROBANTE, 1 => Roles::OPERACION, 2 => Roles::TIPO_DOCUMENTO, 3 => Roles::DESCRIPCION, 4 => Roles::FECHA];

        $fila = Roles::renglon(1, ['FACTURA A', '#2000000000000001', 'DNI', 'Bomba', '13-oct'], $roles, [
            0 => ['FACTURA A', 'C FINAL'],
            1 => ['#2000000000000001', '#2000000000000007'],
            2 => ['DNI', 'CUIT'],
            3 => ['Bomba', 'Bombas'],
            4 => ['13-oct', '18-oct'],
        ]);

        foreach ([Roles::COMPROBANTE, Roles::OPERACION, Roles::TIPO_DOCUMENTO] as $rol) {

            $this->assertTrue(Roles::tiene_duda($fila, $rol), $rol);
            $this->assertArrayNotHasKey($rol, $fila['valores'], $rol);
        }

        // Lo que no mueve nada sigue quedándose con la primera lectura.
        $this->assertFalse(Roles::tiene_duda($fila, Roles::DESCRIPCION));
        $this->assertFalse(Roles::tiene_duda($fila, Roles::FECHA));
    }

    /**
     * De punta a punta: se pregunta, no se arma la tarjeta, y se contesta con el valor solo.
     *
     * @test
     */
    public function una_duda_de_comprobante_se_pregunta_y_se_contesta_con_el_valor()
    {
        $encabezado = ['COMPROBANTE', 'TOTAL', 'CODIGO', 'OPERACION', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'];

        $fila = function ($comprobante, $operacion, $tipo) {
            return [$comprobante, '1.000,00', '70101', $operacion, '2', $tipo, '20175000004', 'Ana Prueba Uno'];
        };

        $this->lecturas = [
            'claude-sonnet-5-5' => $this->lectura([$fila('FACTURA A', '#2000000000000001', 'DNI')], $encabezado),
            'claude-haiku-5-5'  => $this->lectura([$fila('C FINAL', '#2000000000000007', 'CUIT')], $encabezado),
            'claude-opus-5-5'   => $this->lectura([$fila('FACTURA B', '#2000000000000009', 'LE')], $encabezado),
        ];

        $this->falsear_ia();

        $conversation = $this->conversacion();

        $pedido = $this->del_dueno($conversation, '');

        $this->foto($pedido);

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

        $planilla = AiPlanilla::where('ai_conversation_id', $conversation->id)->first();

        $this->assertNotNull($planilla, 'Se leyó como planilla.');

        $this->del_dueno($conversation, 'Cargá: es el total, en Efectivo, Caja Efectivo');

        $assistant = $this->assistant($conversation);

        $this->definir_para_cargar($conversation, $assistant);

        $respuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->cerrar($assistant);

        $this->assertFalse(!empty($respuesta['tarjeta_id']));

        $falta = implode(' | ', $respuesta['faltan']);

        $this->assertStringContainsString('el comprobante se lee', $falta);
        $this->assertStringContainsString('FACTURA A', $falta);
        $this->assertStringContainsString('C FINAL', $falta);
        $this->assertStringContainsString('la operación se lee', $falta);
        $this->assertStringContainsString('el tipo de documento se lee', $falta);

        // Sin comprobante no se decide la condición de IVA.
        $this->assertNull(EjecucionDePlanillaIaHelper::condicion_de_iva(PlanillaIaHelper::fila_numero($planilla->fresh(), 1))['id']);

        // Se contesta con el valor solo: es una duda.
        $bueno = $this->corregir_con($conversation, 'es FACTURA A', ['renglon' => 1, 'campo' => 'comprobante', 'valor' => 'FACTURA A']);

        $this->assertTrue($bueno['ok'], json_encode($bueno, JSON_UNESCAPED_UNICODE));

        $this->assertFalse($this->corregir_con($conversation, 'es la 8', ['renglon' => 1, 'campo' => 'operacion', 'valor' => '#2000000000000007'])['ok'], 'El valor tiene que estar dicho.');
        $this->assertTrue($this->corregir_con($conversation, '#2000000000000007', ['renglon' => 1, 'campo' => 'operacion', 'valor' => '#2000000000000007'])['ok']);
        $this->assertFalse($this->corregir_con($conversation, 'es otra cosa', ['renglon' => 1, 'campo' => 'tipo_documento', 'valor' => 'RUT'])['ok']);
        $this->assertTrue($this->corregir_con($conversation, 'es CUIT', ['renglon' => 1, 'campo' => 'tipo_documento', 'valor' => 'CUIT'])['ok']);

        $planilla = $planilla->fresh();

        $resuelta = PlanillaIaHelper::fila_numero($planilla, 1);

        $this->assertSame('FACTURA A', $resuelta['valores'][Roles::COMPROBANTE]);
        $this->assertSame('CUIT', $resuelta['valores'][Roles::TIPO_DOCUMENTO]);
        $this->assertFalse(Roles::tiene_duda($resuelta, Roles::COMPROBANTE));

        // Ya resuelto, la regla general.
        $this->assertFalse($this->corregir_con($conversation, 'es C FINAL', ['renglon' => 1, 'campo' => 'comprobante', 'valor' => 'C FINAL'])['ok']);
        $this->assertTrue($this->corregir_con($conversation, 'Ana es C FINAL', ['renglon' => 1, 'campo' => 'comprobante', 'valor' => 'C FINAL'])['ok']);

        $propuesta = $this->proponer($conversation);

        $this->assertTrue($propuesta['ok'], json_encode($propuesta, JSON_UNESCAPED_UNICODE));
        $this->assertNotEmpty($propuesta['tarjeta_id']);
    }

    // ---------------------------------------------------------------------
    // T12. El reloj del turno
    // ---------------------------------------------------------------------

    /**
     * 🔴 leer_planilla_de_la_foto arrancaba otro reloj: 100 s más a mitad de un turno, y el admin espera 180 s.
     *
     * @test
     */
    public function leer_la_foto_comparte_el_reloj_del_turno()
    {
        $this->todos_leen($this->filas_de_prueba());
        $this->falsear_ia();

        $conversation = $this->conversacion();

        $pedido = $this->del_dueno($conversation, 'Cargá las ventas de esa foto');

        $foto = $this->foto($pedido);

        $assistant = $this->assistant($conversation);

        Lectura::marcar_inicio_del_turno($assistant, time() - 96);

        $respuesta = $this->herramienta('leer_planilla_de_la_foto', [], $conversation, $assistant);

        $this->assertFalse($respuesta['ok'], json_encode($respuesta, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('tiempo', $respuesta['error']);
        $this->assertCount(0, $this->lecturas_de('claude-sonnet-5-5'), 'No arranca ninguna lectura.');
        $this->assertSame(0, AiPlanilla::where('ai_conversation_id', $conversation->id)->count());

        // La foto sigue pendiente: en el próximo turno se puede leer.
        $this->assertNull($foto->fresh()->gestionada_at);

        $this->cerrar($assistant);

        $this->del_dueno($conversation, 'Dale, leela');

        $otro = $this->assistant($conversation);

        Lectura::marcar_inicio_del_turno($otro, time());

        $bien = $this->herramienta('leer_planilla_de_la_foto', [], $conversation, $otro);

        $this->assertTrue($bien['ok'], json_encode($bien, JSON_UNESCAPED_UNICODE));
        $this->assertCount(4, $bien['renglones']);
    }

    /**
     * El reloj de otro mensaje no vale: un worker atiende muchos turnos, y no se hereda uno viejo.
     *
     * @test
     */
    public function el_reloj_de_otro_mensaje_no_se_hereda()
    {
        $conversation = $this->conversacion();

        $this->del_dueno($conversation, 'x');

        $viejo = $this->assistant($conversation);

        Lectura::marcar_inicio_del_turno($viejo, time() - 500);

        $this->cerrar($viejo);

        $this->del_dueno($conversation, 'y');

        $nuevo = $this->assistant($conversation);

        $this->assertSame(time() - 500, Lectura::inicio_del_turno_de($viejo));
        $this->assertGreaterThanOrEqual(time() - 2, Lectura::inicio_del_turno_de($nuevo));
    }

    /**
     * Volver a mirar una foto tampoco arranca otro reloj.
     *
     * @test
     */
    public function volver_a_mirar_comparte_el_reloj_del_turno()
    {
        config(['services.fotos_del_asistente.activa' => true, 'services.fotos_del_asistente.modelo' => 'claude-haiku-5-5']);

        $this->dueno->agente_proveedor = 'deepseek';
        $this->dueno->save();

        $this->clasificador = false;

        $this->falsear_ia();

        $conversation = $this->conversacion();

        $this->foto($this->del_dueno($conversation, 'Mirá esta foto'));

        $this->responder($conversation, $this->assistant($conversation));

        $this->del_dueno($conversation, '¿Qué dice el lote?');

        $assistant = $this->assistant($conversation);

        $preguntas = function () {

            $cuantas = 0;

            foreach (Http::recorded() as $par) {

                if (mb_strpos($par[0]->body(), 'La pregunta:') !== false) {
                    $cuantas++;
                }
            }

            return $cuantas;
        };

        Lectura::marcar_inicio_del_turno($assistant, time() - 96);

        $respuesta = $this->herramienta('volver_a_mirar_la_foto', ['pregunta' => '¿Qué dice el lote?'], $conversation, $assistant);

        $this->assertFalse($respuesta['ok'], json_encode($respuesta, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $preguntas(), 'No queda tiempo: no arranca ninguna llamada.');

        Lectura::marcar_inicio_del_turno($assistant, time());

        $this->assertTrue($this->herramienta('volver_a_mirar_la_foto', ['pregunta' => '¿Qué dice el lote?'], $conversation, $assistant)['ok']);
        $this->assertSame(1, $preguntas());
    }

    /**
     * El servicio marca el reloj al empezar el turno: es el mismo que usan las fotos del arranque.
     *
     * @test
     */
    public function el_servicio_marca_el_reloj_al_empezar_el_turno_y_la_cuenta_cierra_por_debajo_de_los_180()
    {
        $this->falsear_ia();

        $conversation = $this->conversacion();

        $this->del_dueno($conversation, 'Hola');

        $assistant = $this->assistant($conversation);

        $this->vueltas = [$this->texto_del_modelo('Hola.')];

        $antes = time();

        $this->responder($conversation, $assistant);

        $marcado = Lectura::inicio_del_turno_de($assistant);

        $this->assertGreaterThanOrEqual($antes, $marcado);

        // La lectura termina antes del presupuesto de WhatsApp, y el cierre llega antes de que el admin se rinda.
        $this->assertLessThan(AsistenteIaService::PRESUPUESTO_SEGUNDOS_WHATSAPP, Lectura::SEGUNDOS_POR_TURNO);
        $this->assertLessThan(180, AsistenteIaService::TECHO_DEL_REINTENTO_EN_WHATSAPP);
    }

    // ---------------------------------------------------------------------
    // T13. La guarda de tarjeta prometida
    // ---------------------------------------------------------------------

    /**
     * 🔴 Un «te dejo la tarjeta» en presente sin tarjeta se atrapa, aunque antes haya un pedido o un «después de».
     *
     * @test
     */
    public function la_guarda_atrapa_un_te_dejo_la_tarjeta_en_presente()
    {
        foreach ([
            'Después de revisar, te dejo la tarjeta para que la confirmes.',
            'Después de revisar, te dejo la tarjeta.',
            'Luego de revisar, dejo la tarjeta con las ventas.',
            'Avisame si falta algo. Te dejo la tarjeta con las ventas.',
            'Decime si falta algo. Te dejo la tarjeta para confirmar.',
            'Avisame si falta algo.' . "\n\n" . 'Te dejo la tarjeta con las ventas.',
        ] as $texto) {

            $this->assertTrue(TarjetaPrometidaIaHelper::afirma_una_tarjeta($texto), 'Afirma: ' . $texto);
        }

        // Los verbos en futuro atados a la respuesta siguen sin afirmar.
        foreach ([
            'Contestame y armo la tarjeta.',
            'Contestame eso. Después armo la tarjeta.',
            'Contestame eso. Luego te dejo la tarjeta.',
            'Contestame eso. Después te dejo la tarjeta para que la confirmes.',
            'Decime la caja. Armo la tarjeta con eso.',
            'Cuando me digas la caja, armo la tarjeta.',
            'Cuando me digas, te dejo la tarjeta.',
            'Decime la caja y te dejo la tarjeta.',
            'Si me pasás el monto, te dejo la tarjeta.',
        ] as $texto) {

            $this->assertFalse(TarjetaPrometidaIaHelper::afirma_una_tarjeta($texto), 'No afirma: ' . $texto);
        }
    }
}
