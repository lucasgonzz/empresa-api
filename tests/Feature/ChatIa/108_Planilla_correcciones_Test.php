<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\ArchivosAdjuntosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteImagenHelper;
use App\Http\Controllers\Helpers\asistente_ia\EjecucionDePlanillaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\LecturaDePlanillaIaHelper as Lectura;
use App\Http\Controllers\Helpers\asistente_ia\PlanillaDeTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PlanillaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RolesDePlanillaIaHelper as Roles;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiPlanilla;
use App\Models\Article;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

/**
 * Misión agente-venta-por-planilla (02/11/2026): lo que encontró la revisión independiente. Cada test de los puntos 1
 * a 12 (plata y datos) se escribió antes que su arreglo, y falló.
 *
 * Todo inventado: nombres, documentos, códigos y operaciones.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 *
 * @group chat-ia
 */
class Planilla_correcciones_Test extends PlanillaTestCase
{
    /**
     * Una tabla como texto pegado.
     *
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
     * Una planilla armada a mano en la conversación, para los casos que una lectura no deja armar.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  array  $filas
     * @param  array  $extra
     * @return AiPlanilla
     */
    protected function planilla_con(\App\Models\AiConversation $conversation, array $filas, array $extra = [])
    {
        return AiPlanilla::create(array_merge([
            'ai_conversation_id' => $conversation->id,
            'user_id'            => $this->dueno->id,
            'origen'             => AiPlanilla::ORIGEN_TEXTO,
            'filas'              => $filas,
            'lectura'            => ['fuentes' => []],
            'estado'             => AiPlanilla::ESTADO_ACTIVA,
        ], $extra));
    }

    /**
     * Una venta suelta, registrada a mano.
     *
     * @param  int|null  $cliente_id
     * @param  string  $articulo  bar_code o provider_code
     * @param  float  $total
     * @param  string|null  $observaciones
     * @param  int  $numero
     * @return Sale
     */
    protected function venta_a_mano($cliente_id, $articulo, $total, $observaciones, $numero)
    {
        $venta = Sale::create([
            'num'          => $numero,
            'user_id'      => $this->dueno->id,
            'client_id'    => $cliente_id,
            'total'        => $total,
            'observations' => $observaciones,
        ]);

        $articulo = Article::where('user_id', $this->dueno->id)->where(function ($q) use ($articulo) {
            $q->where('bar_code', $articulo)->orWhere('provider_code', $articulo);
        })->first();

        $venta->articles()->attach($articulo->id, ['amount' => 1, 'price' => $total]);

        return $venta;
    }

    /**
     * Un cliente del dueño.
     *
     * @param  string  $nombre
     * @param  array  $extra
     * @return Client
     */
    protected function cliente($nombre, array $extra = [])
    {
        return Client::create(array_merge(['name' => $nombre, 'user_id' => $this->dueno->id, 'num' => 910800 + rand(1, 9000)], $extra));
    }

    /**
     * Confirma la tarjeta que está esperando, con un «sí» por el servicio de verdad.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @return string
     */
    protected function decir_que_si(\App\Models\AiConversation $conversation)
    {
        $this->del_dueno($conversation, 'Sí');

        return $this->responder($conversation, $this->assistant($conversation));
    }

    /**
     * Cierra un assistant que quedó abierto, como si el mensaje ya hubiera salido.
     *
     * @param  AiMessage  $assistant
     * @param  string  $texto
     * @return void
     */
    protected function cerrar(AiMessage $assistant, $texto = 'x')
    {
        $assistant->contenido = $texto;
        $assistant->estado = 'listo';
        $assistant->save();
    }

    // ---------------------------------------------------------------------
    // 1. La cantidad decimal
    // ---------------------------------------------------------------------

    /**
     * @test
     */
    public function la_cantidad_con_coma_es_decimal_y_con_punto_de_miles_es_ambigua()
    {
        $this->assertSame(0.5, Roles::a_cantidad('0,500'));
        $this->assertSame(1.5, Roles::a_cantidad('1,500'));
        $this->assertSame(1.5, Roles::a_cantidad('1,5'));
        $this->assertSame(1.5, Roles::a_cantidad('1.5'));
        $this->assertSame(0.125, Roles::a_cantidad('0.125'));
        $this->assertSame(0.125, Roles::a_cantidad(0.125));
        $this->assertSame(3.0, Roles::a_cantidad('3'));
        $this->assertSame(1000.5, Roles::a_cantidad('1.000,5'));

        $this->assertNull(Roles::a_cantidad('1.500'), 'Un punto con tres cifras no se adivina.');
        $this->assertTrue(Roles::cantidad_ambigua('1.500'));
        $this->assertTrue(Roles::cantidad_ambigua('12.500'));
        $this->assertFalse(Roles::cantidad_ambigua('1,500'));
        $this->assertFalse(Roles::cantidad_ambigua('0.125'));
        $this->assertNull(Roles::a_cantidad('dos'));
    }

    /**
     * «0,500» es medio, no 500: es la venta multiplicada por mil.
     *
     * @test
     */
    public function una_cantidad_decimal_se_vende_como_tal()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['TOTAL', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['1.000,00', '70101', '0,500', 'DNI', '17000001', 'Ana Prueba Uno'],
        ]));

        $fila = PlanillaIaHelper::fila_numero($planilla, 1);

        $this->assertSame(0.5, Roles::cantidad_de($fila));
        $this->assertSame(2000.0, Roles::precio_unitario_de($fila, $planilla->importe_es));

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR);
        $assistant = $this->assistant($conversation);

        $this->definir_para_cargar($conversation, $assistant);

        $this->assertTrue($this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant)['ok']);

        $this->cerrar($assistant);
        $this->decir_que_si($conversation);

        $venta = $this->ventas_de($planilla)[0];

        $this->assertEquals(0.5, (float) $venta->articles[0]->pivot->amount);
        $this->assertEquals(2000, (float) $venta->articles[0]->pivot->price);
        $this->assertEquals(1000, (float) $venta->total);
    }

    /**
     * @test
     */
    public function una_cantidad_con_punto_de_miles_queda_como_duda_y_se_pregunta()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['TOTAL', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['1.000,00', '70101', '1.500', 'DNI', '17000001', 'Ana Prueba Uno'],
        ]));

        $fila = PlanillaIaHelper::fila_numero($planilla, 1);

        $this->assertTrue(Roles::tiene_duda($fila, Roles::CANTIDAD));
        $this->assertNull(Roles::cantidad_de($fila));

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR);
        $assistant = $this->assistant($conversation);

        $this->definir_para_cargar($conversation, $assistant);

        $respuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('la cantidad', implode(' | ', $respuesta['faltan']));
        $this->assertStringContainsString('1.500', implode(' | ', $respuesta['faltan']));
    }

    /**
     * Un número del JSON de la lectura es el número que dice.
     *
     * @test
     */
    public function un_numero_del_json_de_la_lectura_es_decimal()
    {
        $filas = $this->filas_de_prueba();
        $filas[0][6] = 0.125;

        $this->todos_leen($filas);
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '');
        $this->foto($pedido);

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

        $planilla = AiPlanilla::where('ai_conversation_id', $conversation->id)->first();

        $this->assertSame(0.125, Roles::cantidad_de(PlanillaIaHelper::fila_numero($planilla, 1)));
    }

    // ---------------------------------------------------------------------
    // 2. La columna de cantidad
    // ---------------------------------------------------------------------

    /**
     * @test
     */
    public function una_columna_titulada_cantidad_es_la_cantidad_aunque_la_forma_no_llegue()
    {
        foreach (['CANT', 'CANTIDAD', 'CANTIDA', 'UNID', 'UNIDADES', 'UDS'] as $titulo) {

            $roles = Roles::roles_de(['TOTAL', 'CODIGO', $titulo, 'TIPO', 'DOCUMENTO', 'NOMBRE'], [
                ['1.000,00', '70101', '1.5', 'DNI', '17000001', 'Ana Prueba Uno'],
                ['2.000,00', '70102', 'dos', 'DNI', '14000002', 'Bruno Prueba Dos'],
                ['3.000,00', '70102', '', 'DNI', '13000004', 'Carla Prueba Tres'],
            ]);

            $this->assertSame(Roles::CANTIDAD, $roles[2], $titulo);
        }

        // Y con los valores que parecerían una fecha.
        $roles = Roles::roles_de(['TOTAL', 'CODIGO', 'CANT', 'DOCUMENTO', 'NOMBRE'], [['1.000,00', '70101', '1/2', '17000001', 'Ana Prueba Uno']]);

        $this->assertSame(Roles::CANTIDAD, $roles[2]);
    }

    /**
     * @test
     */
    public function un_punto_decimal_no_es_una_fecha()
    {
        $this->assertNotSame('fecha', Roles::forma_de('1.5'));
        $this->assertNotSame('fecha', Roles::forma_de('2.5'));

        $this->assertSame('fecha', Roles::forma_de('13-oct'));
        $this->assertSame('fecha', Roles::forma_de('13/10'));
        $this->assertSame('fecha', Roles::forma_de('13/10/2026'));
        $this->assertSame('fecha', Roles::forma_de('13.10.2026'));
    }

    /**
     * Una celda de cantidad que no se entiende, o vacía, no es 1: es una duda.
     *
     * @test
     */
    public function una_celda_de_cantidad_que_no_se_entiende_es_una_duda()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['TOTAL', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['1.000,00', '70101', 'dos', 'DNI', '17000001', 'Ana Prueba Uno'],
            ['2.000,00', '70102', '', 'DNI', '14000002', 'Bruno Prueba Dos'],
            ['3.000,00', '70102', '3', 'DNI', '13000004', 'Carla Prueba Tres'],
        ]));

        $this->assertTrue(Roles::tiene_duda(PlanillaIaHelper::fila_numero($planilla, 1), Roles::CANTIDAD));
        $this->assertTrue(Roles::tiene_duda(PlanillaIaHelper::fila_numero($planilla, 2), Roles::CANTIDAD));
        $this->assertFalse(Roles::tiene_duda(PlanillaIaHelper::fila_numero($planilla, 3), Roles::CANTIDAD));

        $this->assertNull(Roles::cantidad_de(PlanillaIaHelper::fila_numero($planilla, 1)));
        $this->assertNull(Roles::cantidad_de(PlanillaIaHelper::fila_numero($planilla, 2)));
    }

    /**
     * La cantidad 1 por defecto, solo si no hay ninguna columna de cantidad.
     *
     * @test
     */
    public function sin_columna_de_cantidad_es_uno()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['TOTAL', 'CODIGO', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['1.000,00', '70101', 'DNI', '17000001', 'Ana Prueba Uno'],
        ]));

        $fila = PlanillaIaHelper::fila_numero($planilla, 1);

        $this->assertFalse(Roles::tiene_duda($fila, Roles::CANTIDAD));
        $this->assertSame(1.0, Roles::cantidad_de($fila));
    }

    /**
     * Sin encabezado, medio kilo también dispara la pregunta del importe: la pregunta es con cualquier cantidad distinta de 1.
     *
     * @test
     */
    public function una_cantidad_distinta_de_uno_dispara_la_pregunta_del_importe()
    {
        $conversation = $this->conversacion();

        $this->planilla_pegada($conversation, $this->como_texto([
            ['C FINAL', '1.000,00', '13-oct', 'SKU 70101', 'Bomba', '#2000000000000001', '0,5', 'DNI', '17000001', 'Ana Prueba Uno', 'Calle Falsa, 100'],
        ]));

        $this->del_dueno($conversation, 'Cargala');
        $assistant = $this->assistant($conversation);

        $respuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->assertStringContainsString('TOTAL de esa venta', implode(' | ', $respuesta['faltan']));
    }

    // ---------------------------------------------------------------------
    // 3. La operación corta
    // ---------------------------------------------------------------------

    /**
     * «#145» no es la venta de la «Planilla N° 145», ni «#451» la de la «Op. #4512».
     *
     * @test
     */
    public function una_operacion_corta_no_toma_una_venta_ajena()
    {
        $cliente = $this->cliente('Ana Prueba Uno', ['dni' => '17000001']);
        $otro = $this->cliente('Otra Persona', ['dni' => '19000009']);

        $ajena = $this->venta_a_mano($otro->id, '70102', 500, 'Planilla N° 145, renglón 1 · Op. #4512', 91080001);

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['C FINAL', '1.000,00', '13-oct', 'SKU 70101', 'Bomba', '#145', '1', 'DNI', '17000001', 'Ana Prueba Uno', 'Calle Falsa, 100'],
            ['C FINAL', '2.000,00', '13-oct', 'SKU 70101', 'Bomba', '#451', '1', 'DNI', '17000001', 'Ana Prueba Uno', 'Calle Falsa, 100'],
            ['C FINAL', '3.000,00', '13-oct', 'SKU 70101', 'Bomba', '#4512', '1', 'DNI', '17000001', 'Ana Prueba Uno', 'Calle Falsa, 100'],
        ]));

        foreach ([1, 2, 3] as $renglon) {

            $fila = PlanillaIaHelper::fila_numero($planilla, $renglon);

            $this->assertSame(AiPlanilla::FILA_PENDIENTE, $fila['estado'], 'Renglón ' . $renglon . ': esa venta es de otro cliente, o de otra operación.');
        }

        // La misma operación del mismo cliente sí es la misma venta.
        $suya = $this->venta_a_mano($cliente->id, '70101', 3000, 'Planilla N° 9, renglón 1 · 13-oct · Op. #4512', 91080002);

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        PlanillaIaHelper::revisar($contexto, $planilla->fresh());

        $fila = PlanillaIaHelper::fila_numero($planilla->fresh(), 3);

        $this->assertSame(AiPlanilla::FILA_YA_REGISTRADA, $fila['estado']);
        $this->assertSame((int) $suya->id, (int) $fila['venta_id']);
    }

    // ---------------------------------------------------------------------
    // 4. cargar_igual
    // ---------------------------------------------------------------------

    /**
     * Una compra repetida legítima tiene salida: la persona la pide, y la fila no se vuelve a marcar.
     *
     * @test
     */
    public function cargar_igual_reabre_una_fila_ya_registrada_y_no_se_vuelve_a_marcar()
    {
        $cliente = $this->cliente('Ana Prueba Uno', ['dni' => '17000001']);

        $vieja = $this->venta_a_mano($cliente->id, '70101', 61250, null, 91080003);

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $this->assertSame(AiPlanilla::FILA_YA_REGISTRADA, PlanillaIaHelper::fila_numero($planilla, 1)['estado']);

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR);
        $assistant = $this->assistant($conversation);

        $this->definir_para_cargar($conversation, $assistant);

        $propuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->assertStringContainsString('Si alguna es otra compra, decime cuál y la cargo igual', AiMessageAction::find($propuesta['tarjeta_id'])->datos['texto']);

        $this->cerrar($assistant);

        // Un «sí» no la reabre.
        $this->del_dueno($conversation, 'Sí');
        $con_si = $this->assistant($conversation);

        $this->assertFalse($this->herramienta('corregir_renglon_de_planilla', [
            'renglon' => 1, 'campo' => 'cargar_igual', 'lo_que_dijo_el_dueno' => 'Sí',
        ], $conversation, $con_si)['ok']);

        $this->cerrar($con_si);

        // Pidiéndolo, sí.
        $this->del_dueno($conversation, 'Lo de Ana es otra compra, cargala igual');
        $otro = $this->assistant($conversation);

        $reabierta = $this->herramienta('corregir_renglon_de_planilla', [
            'renglon' => 1, 'campo' => 'cargar_igual', 'lo_que_dijo_el_dueno' => 'Lo de Ana es otra compra',
        ], $conversation, $otro);

        $this->assertTrue($reabierta['ok'], json_encode($reabierta));

        PlanillaIaHelper::revisar(ContextoDeCargaIa::de_la_conversacion($conversation), $planilla->fresh());

        $fila = PlanillaIaHelper::fila_numero($planilla->fresh(), 1);

        $this->assertSame(AiPlanilla::FILA_PENDIENTE, $fila['estado'], 'No se vuelve a marcar.');

        $this->assertTrue($this->herramienta('proponer_ventas_de_planilla', [], $conversation, $otro)['ok']);

        $this->cerrar($otro);
        $this->decir_que_si($conversation);

        $venta = $this->ventas_de($planilla)->firstWhere('client_id', $cliente->id);

        $this->assertNotNull($venta);
        $this->assertNotSame((int) $vieja->id, (int) $venta->id, 'Es otra venta.');
        $this->assertSame((int) $venta->num, (int) PlanillaIaHelper::fila_numero($planilla->fresh(), 1)['venta_numero']);
    }

    // ---------------------------------------------------------------------
    // 5. Una foto nueva no se traga ventas
    // ---------------------------------------------------------------------

    /**
     * Dos filas con operaciones distintas nunca son la misma, aunque coincidan documento, código e importe.
     *
     * @test
     */
    public function una_fila_con_otra_operacion_es_otra_venta()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['C FINAL', '1.000,00', '13-oct', 'SKU 70101', 'Bomba', '#2000000000000001', '1', 'DNI', '17000001', 'Ana Prueba Uno', 'Calle Falsa, 100'],
        ]));

        $this->todos_leen([
            ['C FINAL', '1.000,00', '14-oct', 'SKU 70101', 'Bomba', '#2000000000000099', '1', 'DNI', '17000001', 'Ana Prueba Uno', 'Calle Falsa, 100'],
        ]);

        $this->falsear_ia();

        $pedido = $this->del_dueno($conversation, '');
        $this->foto($pedido);

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

        $this->assertCount(2, $planilla->fresh()->filas);
    }

    /**
     * Dos filas iguales contra una: cada fila existente se consume una sola vez.
     *
     * @test
     */
    public function dos_filas_iguales_contra_una_dan_dos_renglones()
    {
        $conversation = $this->conversacion();

        $sin_operacion = ['C FINAL', '1.000,00', '13-oct', 'SKU 70101', 'Bomba', '1', 'DNI', '17000001', 'Ana Prueba Uno', 'Calle Falsa, 100'];

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([$sin_operacion]));

        $this->todos_leen([$sin_operacion, $sin_operacion]);
        $this->falsear_ia();

        $pedido = $this->del_dueno($conversation, '');
        $this->foto($pedido);

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

        $this->assertCount(2, $planilla->fresh()->filas);
    }

    /**
     * El documento de una foto de documento y nombre se copia solo con un nombre exacto y único; lo que no cruza se ve.
     *
     * @test
     */
    public function el_documento_se_copia_solo_con_un_nombre_exacto_y_unico()
    {
        $conversation = $this->conversacion();

        // Renglones sin documento: el 1 y el 2 con el mismo nombre, el 3 con el suyo.
        $planilla = $this->planilla_con($conversation, [
            Roles::renglon(1, ['1.000,00', '70101', 'Ana Prueba Uno'], [0 => Roles::IMPORTE, 1 => Roles::CODIGO, 2 => Roles::NOMBRE]),
            Roles::renglon(2, ['2.000,00', '70101', 'Ana Prueba Uno'], [0 => Roles::IMPORTE, 1 => Roles::CODIGO, 2 => Roles::NOMBRE]),
            Roles::renglon(3, ['3.000,00', '70101', 'Bruno Prueba Dos'], [0 => Roles::IMPORTE, 1 => Roles::CODIGO, 2 => Roles::NOMBRE]),
        ]);

        $this->todos_leen([
            ['DNI', '17000001', 'Ana Prueba Uno'],
            ['DNI', '14000002', 'Bruno Prueba Dos'],
            ['DNI', '99000099', 'Alguien Que No Esta'],
        ]);

        $this->falsear_ia();

        $pedido = $this->del_dueno($conversation, '');
        $this->foto($pedido, 381, 219);

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

        $planilla = $planilla->fresh();

        $this->assertArrayNotHasKey(Roles::DOCUMENTO, PlanillaIaHelper::fila_numero($planilla, 1)['valores'], 'Dos «Ana»: no se adivina cuál.');
        $this->assertArrayNotHasKey(Roles::DOCUMENTO, PlanillaIaHelper::fila_numero($planilla, 2)['valores']);
        $this->assertSame('14000002', PlanillaIaHelper::fila_numero($planilla, 3)['valores'][Roles::DOCUMENTO]);

        // Lo que no cruza queda como renglón, a la vista, sin trabar los demás.
        $this->assertCount(5, $planilla->filas);

        $nuevos = array_values(array_filter($planilla->filas, function ($fila) {
            return ($fila['valores'][Roles::NOMBRE] ?? '') === 'Alguien Que No Esta';
        }));

        $this->assertCount(1, $nuevos);
        $this->assertSame(AiPlanilla::FILA_DESCARTADA, $nuevos[0]['estado']);
        $this->assertTrue($nuevos[0]['sin_venta']);
    }

    // ---------------------------------------------------------------------
    // 6. Las anclas
    // ---------------------------------------------------------------------

    /**
     * Sin cita, o con una que la persona no dijo, se rechaza.
     *
     * @test
     */
    public function corregir_sin_una_cita_del_ultimo_mensaje_se_rechaza()
    {
        $conversation = $this->conversacion();

        $this->planilla_pegada($conversation);

        $this->del_dueno($conversation, 'En el renglón 2 son 3 unidades');
        $assistant = $this->assistant($conversation);

        $sin_cita = $this->herramienta('corregir_renglon_de_planilla', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 3], $conversation, $assistant);

        $this->assertFalse($sin_cita['ok']);
        $this->assertSame('sin_ancla', $sin_cita['codigo']);

        $inventada = $this->herramienta('corregir_renglon_de_planilla', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 3, 'lo_que_dijo_el_dueno' => 'el dueño pidió 3'], $conversation, $assistant);

        $this->assertFalse($inventada['ok']);
        $this->assertSame('sin_ancla', $inventada['codigo']);

        $buena = $this->herramienta('corregir_renglon_de_planilla', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 3, 'lo_que_dijo_el_dueno' => '¡En el RENGLON 2 son 3 unidades!'], $conversation, $assistant);

        $this->assertTrue($buena['ok'], json_encode($buena));
    }

    /**
     * El número nuevo tiene que estar en el mensaje, también escrito con letras.
     *
     * @test
     */
    public function la_cantidad_y_el_importe_nuevos_tienen_que_estar_en_el_mensaje()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $this->del_dueno($conversation, 'Cambiá el renglón 2');
        $assistant = $this->assistant($conversation);

        foreach (['cantidad' => 12, 'importe' => 50000] as $campo => $valor) {

            $rechazo = $this->herramienta('corregir_renglon_de_planilla', ['renglon' => 2, 'campo' => $campo, 'valor' => $valor, 'lo_que_dijo_el_dueno' => 'Cambiá el renglón 2'], $conversation, $assistant);

            $this->assertFalse($rechazo['ok'], $campo);
            $this->assertSame('sin_ancla', $rechazo['codigo'], $campo);
        }

        $this->cerrar($assistant);

        $this->del_dueno($conversation, 'Las bombas de Bruno son tres unidades, y salieron 50.000');
        $otro = $this->assistant($conversation);

        $this->assertTrue($this->herramienta('corregir_renglon_de_planilla', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 3, 'lo_que_dijo_el_dueno' => 'son tres unidades'], $conversation, $otro)['ok']);
        $this->assertTrue($this->herramienta('corregir_renglon_de_planilla', ['renglon' => 2, 'campo' => 'importe', 'valor' => 50000, 'lo_que_dijo_el_dueno' => 'salieron 50.000'], $conversation, $otro)['ok']);

        $fila = PlanillaIaHelper::fila_numero($planilla->fresh(), 2);

        $this->assertSame(3.0, Roles::cantidad_de($fila));
        $this->assertSame(50000.0, Roles::total_de($fila, $planilla->fresh()->importe_es));
    }

    /**
     * El artículo y el cliente: su código o documento, o una palabra de su nombre.
     *
     * @test
     */
    public function el_articulo_y_el_cliente_nuevos_tienen_que_estar_en_el_mensaje()
    {
        $conversation = $this->conversacion();

        $this->planilla_pegada($conversation);

        $this->del_dueno($conversation, 'El renglón 1 está mal');
        $assistant = $this->assistant($conversation);

        $this->assertFalse($this->herramienta('corregir_renglon_de_planilla', ['renglon' => 1, 'campo' => 'articulo', 'valor' => 'wzz3324', 'lo_que_dijo_el_dueno' => 'El renglón 1 está mal'], $conversation, $assistant)['ok']);
        $this->assertFalse($this->herramienta('corregir_renglon_de_planilla', ['renglon' => 1, 'campo' => 'cliente', 'valor' => self::CUIT_EMPRESA, 'lo_que_dijo_el_dueno' => 'El renglón 1 está mal'], $conversation, $assistant)['ok']);

        $this->cerrar($assistant);

        $this->del_dueno($conversation, 'El artículo del renglón 1 es el maletin herramientas');
        $otro = $this->assistant($conversation);

        $this->assertTrue($this->herramienta('corregir_renglon_de_planilla', ['renglon' => 1, 'campo' => 'articulo', 'valor' => 'wzz3324', 'lo_que_dijo_el_dueno' => 'es el maletin herramientas'], $conversation, $otro)['ok']);

        $this->cerrar($otro);

        $this->del_dueno($conversation, 'El CUIT del renglón 1 es ' . self::CUIT_EMPRESA);
        $otro = $this->assistant($conversation);

        $this->assertTrue($this->herramienta('corregir_renglon_de_planilla', ['renglon' => 1, 'campo' => 'cliente', 'valor' => self::CUIT_EMPRESA, 'lo_que_dijo_el_dueno' => 'es ' . self::CUIT_EMPRESA], $conversation, $otro)['ok']);
    }

    /**
     * Descartar: el nombre del cliente, una palabra del artículo o el número de renglón.
     *
     * @test
     */
    public function descartar_tiene_que_nombrar_el_renglon()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $this->del_dueno($conversation, 'Sacá ese');
        $assistant = $this->assistant($conversation);

        $this->assertFalse($this->herramienta('corregir_renglon_de_planilla', ['renglon' => 4, 'campo' => 'descartar', 'lo_que_dijo_el_dueno' => 'Sacá ese'], $conversation, $assistant)['ok']);

        $this->cerrar($assistant);

        // «una» no es el renglón 1.
        $this->del_dueno($conversation, 'Sacá una');
        $con_una = $this->assistant($conversation);

        $this->assertFalse($this->herramienta('corregir_renglon_de_planilla', ['renglon' => 1, 'campo' => 'descartar', 'lo_que_dijo_el_dueno' => 'Sacá una'], $conversation, $con_una)['ok']);

        $this->cerrar($con_una);

        foreach (['El renglón 4 no lo cargues' => 4, 'Lo de Bruno no va' => 2, 'La bomba alfa sacala' => 1] as $mensaje => $renglon) {

            $this->del_dueno($conversation, $mensaje);
            $otro = $this->assistant($conversation);

            $this->assertTrue($this->herramienta('corregir_renglon_de_planilla', ['renglon' => $renglon, 'campo' => 'descartar', 'lo_que_dijo_el_dueno' => $mensaje], $conversation, $otro)['ok'], $mensaje);

            $this->cerrar($otro);

            $this->assertSame(AiPlanilla::FILA_DESCARTADA, PlanillaIaHelper::fila_numero($planilla->fresh(), $renglon)['estado'], $mensaje);
        }
    }

    /**
     * 🔴 El mensaje 314 del caso real: el dueño dijo «sí» y el modelo cambió lo acordado. Rechazado, sobre una fila resuelta.
     *
     * @test
     */
    public function un_si_no_cambia_una_fila_resuelta()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $assistant_que_pregunta = AiMessage::where('ai_conversation_id', $conversation->id)->where('rol', 'assistant')->first();
        $assistant_que_pregunta->contenido = 'Carla Prueba Tres lleva 12 cajas por $999.111. ¿Armo las ventas?';
        $assistant_que_pregunta->save();

        $this->del_dueno($conversation, 'Si');
        $assistant = $this->assistant($conversation);

        foreach ([['campo' => 'cantidad', 'valor' => 12], ['campo' => 'importe', 'valor' => 999111], ['campo' => 'descartar']] as $intento) {

            $rechazo = $this->herramienta('corregir_renglon_de_planilla', array_merge(['renglon' => 4, 'lo_que_dijo_el_dueno' => 'Si'], $intento), $conversation, $assistant);

            $this->assertFalse($rechazo['ok'], $intento['campo']);
            $this->assertSame('un_si_no_corrige', $rechazo['codigo'], $intento['campo']);
        }

        $this->assertSame('1', PlanillaIaHelper::fila_numero($planilla->fresh(), 4)['valores'][Roles::CANTIDAD]);
    }

    /**
     * Pero un «sí» contesta una duda, si lo que pregunta el asistente es ese valor.
     *
     * @test
     */
    public function un_si_contesta_una_duda_si_el_valor_estaba_en_la_pregunta()
    {
        $filas = $this->filas_de_prueba();
        $filas[2][8] = self::CUIT_MAL;

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto($filas));

        $assistant_que_pregunta = AiMessage::where('ai_conversation_id', $conversation->id)->where('rol', 'assistant')->first();
        $assistant_que_pregunta->contenido = '¿El CUIT de Empresa Prueba SA es ' . self::CUIT_EMPRESA . '?';
        $assistant_que_pregunta->save();

        $this->del_dueno($conversation, 'Si');
        $assistant = $this->assistant($conversation);

        // Otro valor, que el asistente no preguntó: no.
        $otro_cuit = '20175000004';

        $this->assertFalse($this->herramienta('corregir_renglon_de_planilla', ['renglon' => 3, 'campo' => 'cliente', 'valor' => $otro_cuit, 'lo_que_dijo_el_dueno' => 'Si'], $conversation, $assistant)['ok']);

        // El que preguntó: sí.
        $bueno = $this->herramienta('corregir_renglon_de_planilla', ['renglon' => 3, 'campo' => 'cliente', 'valor' => self::CUIT_EMPRESA, 'lo_que_dijo_el_dueno' => 'Si'], $conversation, $assistant);

        $this->assertTrue($bueno['ok'], json_encode($bueno));
        $this->assertSame(self::CUIT_EMPRESA, PlanillaIaHelper::fila_numero($planilla->fresh(), 3)['valores'][Roles::DOCUMENTO]);
    }

    /**
     * 🔴 El mensaje 316 del caso real: una corrección con sus palabras se acepta, y la lista nueva dice qué cambió.
     *
     * @test
     */
    public function un_pedido_con_sus_palabras_se_acepta_y_la_lista_dice_que_cambio()
    {
        $conversation = $this->conversacion();

        $this->planilla_pegada($conversation);

        $this->del_dueno($conversation, 'Es el total y se cobraron en Efectivo, Caja Efectivo. Bruno Prueba Dos lleva 1 bombas');
        $assistant = $this->assistant($conversation);

        $this->definir_para_cargar($conversation, $assistant);

        $corregido = $this->herramienta('corregir_renglon_de_planilla', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 1, 'lo_que_dijo_el_dueno' => 'Bruno Prueba Dos lleva 1 bombas'], $conversation, $assistant);

        $this->assertTrue($corregido['ok'], json_encode($corregido));

        $propuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->assertTrue($propuesta['ok'], json_encode($propuesta));

        $texto = AiMessageAction::find($propuesta['tarjeta_id'])->datos['texto'];

        $this->assertStringContainsString('2. Bruno Prueba Dos (nuevo): 1 × zz-c107 Bomba Beta (70102), $ 88.400 [corregido]', $texto);
        $this->assertStringContainsString('Cambios que pediste: renglón 2, cantidad 2 → 1', $texto);
    }

    /**
     * «definir» con un «SI»: el importe y el cobro no se definen con un sí suelto.
     *
     * @test
     */
    public function definir_con_un_si_no_define_nada_sin_que_el_dueno_lo_haya_dicho()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $this->del_dueno($conversation, 'SI');
        $assistant = $this->assistant($conversation);

        foreach ([['importe_es' => 'total'], ['metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO], ['caja' => TestingFerreteriaSeeder::CAJA_EFECTIVO]] as $input) {

            $rechazo = $this->herramienta('definir_planilla', $input, $conversation, $assistant);

            $this->assertFalse($rechazo['ok'], json_encode($input));
            $this->assertSame('sin_ancla', $rechazo['codigo'], json_encode($input));
        }

        $this->assertNull($planilla->fresh()->importe_es);
        $this->assertNull($planilla->fresh()->metodo_de_pago);

        $this->cerrar($assistant);

        foreach (['El importe es el total' => 'total', 'Son precios por unidad' => 'unidad', 'cada una a ese precio' => 'unidad', 'es c/u' => 'unidad'] as $mensaje => $importe_es) {

            $this->del_dueno($conversation, $mensaje);
            $otro = $this->assistant($conversation);

            $this->assertTrue($this->herramienta('definir_planilla', ['importe_es' => $importe_es], $conversation, $otro)['ok'], $mensaje);

            $this->cerrar($otro);
        }
    }

    /**
     * Un importe que fijó el título no se cambia, salvo que el último mensaje lo pida.
     *
     * @test
     */
    public function el_importe_que_fijo_el_titulo_no_se_cambia_sin_pedirlo()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['VENTA TOTAL', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['1.000,00', '70101', '2', 'DNI', '17000001', 'Ana Prueba Uno'],
        ]));

        $this->assertSame(AiPlanilla::IMPORTE_TOTAL, $planilla->importe_es);

        $this->del_dueno($conversation, 'Dale, cargala');
        $assistant = $this->assistant($conversation);

        $this->assertFalse($this->herramienta('definir_planilla', ['importe_es' => 'unidad'], $conversation, $assistant)['ok']);
        $this->assertSame(AiPlanilla::IMPORTE_TOTAL, $planilla->fresh()->importe_es);

        $this->cerrar($assistant);

        $this->del_dueno($conversation, 'No, todas son por unidad');
        $otro = $this->assistant($conversation);

        $this->assertTrue($this->herramienta('definir_planilla', ['importe_es' => 'unidad'], $conversation, $otro)['ok']);
        $this->assertSame(AiPlanilla::IMPORTE_UNIDAD, $planilla->fresh()->importe_es);
    }

    /**
     * El método tiene que estar en un mensaje, y un método dado con cobro en cuenta corriente pasa a contado.
     *
     * @test
     */
    public function el_metodo_de_pago_tiene_que_estar_en_un_mensaje_y_pasa_a_contado()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $this->del_dueno($conversation, 'Van a la cuenta corriente');
        $assistant = $this->assistant($conversation);

        $this->assertTrue($this->herramienta('definir_planilla', ['cobro' => 'cuenta_corriente'], $conversation, $assistant)['ok']);
        $this->assertFalse($this->herramienta('definir_planilla', ['metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO], $conversation, $assistant)['ok']);
        $this->assertSame('cuenta_corriente', $planilla->fresh()->cobro);

        $this->cerrar($assistant);

        $this->del_dueno($conversation, 'No, mejor efectivo');
        $otro = $this->assistant($conversation);

        $this->assertTrue($this->herramienta('definir_planilla', ['metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO], $conversation, $otro)['ok']);

        $this->assertSame('contado', $planilla->fresh()->cobro);
        $this->assertSame(TestingFerreteriaSeeder::PAGO_EFECTIVO, $planilla->fresh()->metodo_de_pago);
    }

    // ---------------------------------------------------------------------
    // 7. proponer_venta
    // ---------------------------------------------------------------------

    /**
     * Con planilla activa: por cliente o por artículo, aunque venga es_otra_venta, salvo que el dueño lo diga.
     *
     * @test
     */
    public function proponer_venta_no_esquiva_la_planilla()
    {
        $bruno = $this->cliente('Bruno Prueba Dos', ['dni' => '14000002']);

        $conversation = $this->conversacion();

        $this->planilla_pegada($conversation);

        $this->del_dueno($conversation, 'Vendele algo');
        $assistant = $this->assistant($conversation);

        $venta = [
            'cobro'          => 'contado',
            'metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO,
            'caja'           => TestingFerreteriaSeeder::CAJA_EFECTIVO,
        ];

        $de_otro_articulo = $this->articulo_de_prueba('zz-c108 Otro articulo', ['bar_code' => '70199']);

        // Por el cliente, por su id, con otro artículo, y con es_otra_venta.
        $por_cliente = $this->herramienta('proponer_venta', array_merge($venta, [
            'cliente' => $bruno->id, 'items' => [['articulo_id' => $de_otro_articulo->id, 'cantidad' => 1]], 'es_otra_venta' => true,
        ]), $conversation, $assistant);

        $this->assertFalse($por_cliente['ok']);
        $this->assertSame('planilla_activa', $por_cliente['codigo']);
        $this->assertStringNotContainsString('es_otra_venta', $por_cliente['error'], 'El error no se la sugiere.');

        // Por el artículo, sin cliente.
        $por_articulo = $this->herramienta('proponer_venta', array_merge($venta, [
            'items' => [['articulo' => 'zz-c107 Bomba Beta', 'cantidad' => 2]], 'es_otra_venta' => true,
        ]), $conversation, $assistant);

        $this->assertFalse($por_articulo['ok']);
        $this->assertSame('planilla_activa', $por_articulo['codigo']);

        // Otro cliente con otro artículo sigue como siempre.
        $de_afuera = $this->herramienta('proponer_venta', array_merge($venta, [
            'cliente' => TestingFerreteriaSeeder::CLIENTE_CONTADO, 'items' => [['articulo_id' => $de_otro_articulo->id, 'cantidad' => 1]],
        ]), $conversation, $assistant);

        $this->assertTrue(!empty($de_afuera['ok']), json_encode($de_afuera));

        $this->cerrar($assistant);

        // Y si el dueño dice que es otra, con cualquiera de sus palabras.
        foreach (['Es otra venta', 'Esta va aparte', 'Además vendele una bomba', 'También vendele esto', 'Esta va fuera de la planilla'] as $mensaje) {

            $this->del_dueno($conversation, $mensaje);
            $otro = $this->assistant($conversation);

            $aceptada = $this->herramienta('proponer_venta', array_merge($venta, [
                'cliente' => $bruno->id, 'items' => [['articulo' => 'zz-c107 Bomba Beta', 'cantidad' => 1]],
            ]), $conversation, $otro);

            $this->assertTrue(!empty($aceptada['ok']), $mensaje . ': ' . json_encode($aceptada));

            $this->cerrar($otro);
        }
    }

    /**
     * Una venta suelta no pisa la tarjeta de la planilla por `reemplaza_a`.
     *
     * @test
     */
    public function una_venta_suelta_no_pisa_la_tarjeta_de_la_planilla()
    {
        $conversation = $this->conversacion();

        $this->tarjeta_lista($conversation);

        $tarjeta = AiMessageAction::where('ai_conversation_id', $conversation->id)->where('tipo', AiMessageAction::TIPO_VENTAS_PLANILLA)->first();

        $this->del_dueno($conversation, 'Es otra venta, aparte');
        $assistant = $this->assistant($conversation);

        $otro_articulo = $this->articulo_de_prueba('zz-c108 Otro articulo', ['bar_code' => '70199']);

        $respuesta = $this->herramienta('proponer_venta', [
            'cliente'        => TestingFerreteriaSeeder::CLIENTE_CONTADO,
            'cobro'          => 'contado',
            'metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO,
            'caja'           => TestingFerreteriaSeeder::CAJA_EFECTIVO,
            'items'          => [['articulo_id' => $otro_articulo->id, 'cantidad' => 1]],
            'reemplaza_a'    => $tarjeta->id,
        ], $conversation, $assistant);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->fresh()->estado_guardado());
    }

    // ---------------------------------------------------------------------
    // 8. Documentos
    // ---------------------------------------------------------------------

    /**
     * @test
     */
    public function el_documento_se_compara_normalizado()
    {
        $conversation = $this->conversacion();

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $con_dni = $this->cliente('Persona Con Dni', ['dni' => '16700001']);
        $con_ceros = $this->cliente('Persona Corta', ['dni' => '7123456']);
        $con_puntos = $this->cliente('Persona Con Puntos', ['dni' => '16.700.002']);

        $ids = function ($clientes) {
            return array_map(function ($cliente) {
                return (int) $cliente->id;
            }, $clientes);
        };

        // Los 8 del medio de un CUIT 20/23/24/27.
        $this->assertSame([(int) $con_dni->id], $ids(PlanillaIaHelper::clientes_por_documento($contexto, '20-16700001-9')));
        $this->assertSame([(int) $con_dni->id], $ids(PlanillaIaHelper::clientes_por_documento($contexto, '27167000019')));

        // Ceros a la izquierda y separadores.
        $this->assertSame([(int) $con_ceros->id], $ids(PlanillaIaHelper::clientes_por_documento($contexto, '07123456')));
        $this->assertSame([(int) $con_puntos->id], $ids(PlanillaIaHelper::clientes_por_documento($contexto, '16700002')));

        // Una sociedad no es el DNI del medio.
        $this->assertSame([], $ids(PlanillaIaHelper::clientes_por_documento($contexto, '30167000019')));

        // Sin los de otro dueño ni los borrados.
        $otro = User::create(['name' => 'Otro dueño', 'email' => 'c108-' . uniqid() . '@test.local', 'password' => Hash::make('x')]);

        Client::create(['name' => 'De otro', 'dni' => '16700003', 'user_id' => $otro->id, 'num' => 1]);

        $borrado = $this->cliente('Borrado', ['dni' => '16700004']);
        $borrado->delete();

        $this->assertSame([], $ids(PlanillaIaHelper::clientes_por_documento($contexto, '16700003')));
        $this->assertSame([], $ids(PlanillaIaHelper::clientes_por_documento($contexto, '16700004')));

        // Dos: no se elige, se pregunta.
        $this->cliente('Mismo Dni Uno', ['dni' => '16700005']);
        $this->cliente('Mismo Dni Dos', ['cuit' => '16700005']);

        $this->assertCount(2, PlanillaIaHelper::clientes_por_documento($contexto, '16700005'));
    }

    // ---------------------------------------------------------------------
    // 9. Repegar sin cliente ni operación
    // ---------------------------------------------------------------------

    /**
     * Sin cliente ni operación, lo único que dice que es la misma fila es la fila.
     *
     * @test
     */
    public function una_fila_sin_cliente_ni_operacion_se_reconoce_por_su_huella()
    {
        $roles = [0 => Roles::IMPORTE, 1 => Roles::CODIGO, 2 => Roles::CANTIDAD];
        $celdas = ['1.000,00', '70101', '1'];

        $vendida = $this->venta_a_mano(null, '70101', 1000, null, 91080004);

        $conversation_vieja = $this->conversacion();

        $vieja = Roles::renglon(1, $celdas, $roles);
        $vieja['estado'] = AiPlanilla::FILA_REGISTRADA;
        $vieja['venta_id'] = (int) $vendida->id;
        $vieja['venta_numero'] = (int) $vendida->num;

        $this->planilla_con($conversation_vieja, [$vieja], ['estado' => AiPlanilla::ESTADO_CERRADA]);

        $conversation = $this->conversacion();

        // La misma fila, y una que no se vendió.
        $planilla = $this->planilla_con($conversation, [
            Roles::renglon(1, ['1.000,00 ', '70101', '1'], $roles),
            Roles::renglon(2, ['2.000,00', '70101', '1'], $roles),
        ], ['importe_es' => AiPlanilla::IMPORTE_TOTAL]);

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        PlanillaIaHelper::revisar($contexto, $planilla);

        $planilla = $planilla->fresh();

        $repetida = PlanillaIaHelper::fila_numero($planilla, 1);

        $this->assertSame(AiPlanilla::FILA_YA_REGISTRADA, $repetida['estado']);
        $this->assertSame((int) $vendida->num, (int) $repetida['venta_numero']);

        $this->assertSame(AiPlanilla::FILA_PENDIENTE, PlanillaIaHelper::fila_numero($planilla, 2)['estado']);

        // Con su salida.
        $this->del_dueno($conversation, 'El renglón 1 es otra compra');
        $assistant = $this->assistant($conversation);

        $this->assertTrue($this->herramienta('corregir_renglon_de_planilla', ['renglon' => 1, 'campo' => 'cargar_igual', 'lo_que_dijo_el_dueno' => 'El renglón 1 es otra compra'], $conversation, $assistant)['ok']);

        PlanillaIaHelper::revisar($contexto, $planilla->fresh());

        $this->assertSame(AiPlanilla::FILA_PENDIENTE, PlanillaIaHelper::fila_numero($planilla->fresh(), 1)['estado']);
    }

    // ---------------------------------------------------------------------
    // 10. Los títulos del importe
    // ---------------------------------------------------------------------

    /**
     * @test
     */
    public function los_titulos_dicen_que_es_el_importe()
    {
        $filas = [['1.000,00', '70101', '2', 'DNI', '17000001', 'Ana Prueba Uno']];

        foreach (['TOTAL' => 'total', 'VENTA TOTAL' => 'total', 'IMPORTE TOTAL' => 'total', 'UNITARIO' => 'unidad', 'P. UNIT' => 'unidad', 'PRECIO UNITARIO' => 'unidad', 'C/U' => 'unidad', 'PRECIO' => null, 'IMPORTE' => null, 'MONTO' => null] as $titulo => $esperado) {

            $encabezado = [$titulo, 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'];

            $roles = Roles::roles_de($encabezado, $filas);

            $this->assertSame(Roles::IMPORTE, $roles[0], $titulo);
            $this->assertSame($esperado, Roles::importe_es_de($encabezado, $roles), $titulo);
        }
    }

    // ---------------------------------------------------------------------
    // 11. La condición de IVA
    // ---------------------------------------------------------------------

    /**
     * @test
     */
    public function la_condicion_de_iva_sale_del_comprobante_y_del_documento()
    {
        $inscripto = (int) IvaCondition::where('name', 'Responsable inscripto')->value('id');
        $final = (int) IvaCondition::where('name', 'Consumidor final')->value('id');

        $casos = [
            ['FACTURA A', '30712345671', $inscripto, false],
            ['FACTURA A', '17000001', null, true],
            ['C FINAL', '17000001', $final, false],
            ['C FINAL', '20175000004', $final, false],
            ['C FINAL', '30712345671', null, true],
            ['C FINAL', '33712345670', null, true],
            ['C FINAL', '34712345677', null, true],
        ];

        foreach ($casos as $caso) {

            $fila = Roles::renglon(1, [$caso[0], $caso[1]], [0 => Roles::COMPROBANTE, 1 => Roles::DOCUMENTO]);

            $condicion = EjecucionDePlanillaIaHelper::condicion_de_iva($fila);

            $this->assertSame($caso[2], $condicion['id'], $caso[0] . ' ' . $caso[1]);
            $this->assertSame($caso[3], !is_null($condicion['aviso']), $caso[0] . ' ' . $caso[1]);
        }
    }

    /**
     * El cliente nuevo de una Factura A con DNI nace sin condición, y la lista lo avisa antes del sí.
     *
     * @test
     */
    public function una_factura_a_con_dni_nace_sin_condicion_y_se_avisa()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['FACTURA A', '1.000,00', '13-oct', 'SKU 70101', 'Bomba', '#2000000000000001', '1', 'DNI', '17000001', 'Ana Prueba Uno', 'Calle Falsa, 100'],
        ]));

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR);
        $assistant = $this->assistant($conversation);

        $this->definir_para_cargar($conversation, $assistant);

        $propuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->assertTrue($propuesta['ok'], json_encode($propuesta));
        $this->assertStringContainsString('pide Factura A y trae un DNI', AiMessageAction::find($propuesta['tarjeta_id'])->datos['texto']);

        $this->cerrar($assistant);
        $this->decir_que_si($conversation);

        $ana = Client::where('user_id', $this->dueno->id)->where('dni', '17000001')->first();

        $this->assertNotNull($ana);
        $this->assertNull($ana->iva_condition_id);
    }

    // ---------------------------------------------------------------------
    // 12. Lo demás de plata
    // ---------------------------------------------------------------------

    /**
     * El id de un artículo gana sobre un código igual, si el dueño lo nombró.
     *
     * @test
     */
    public function corregir_el_articulo_prueba_primero_el_id()
    {
        $por_id = $this->articulo_de_prueba('zz-c108 Destornillador largo');
        $con_ese_codigo = $this->articulo_de_prueba('zz-c108 Pegamento', ['provider_code' => (string) $por_id->id]);

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $this->del_dueno($conversation, 'El renglón 1 es el destornillador');
        $assistant = $this->assistant($conversation);

        $respuesta = $this->herramienta('corregir_renglon_de_planilla', ['renglon' => 1, 'campo' => 'articulo', 'valor' => (string) $por_id->id, 'lo_que_dijo_el_dueno' => 'es el destornillador'], $conversation, $assistant);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame((int) $por_id->id, (int) PlanillaIaHelper::fila_numero($planilla->fresh(), 1)['articulo_id']);

        $this->assertNotSame((int) $con_ese_codigo->id, (int) PlanillaIaHelper::fila_numero($planilla->fresh(), 1)['articulo_id']);
    }

    /**
     * 100 / 3: la tarjeta y el total dicen lo que se va a vender, con su aviso.
     *
     * @test
     */
    public function el_total_dice_lo_que_se_va_a_vender()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['VENTA TOTAL', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['100,00', '70101', '3', 'DNI', '17000001', 'Ana Prueba Uno'],
        ]));

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR);
        $assistant = $this->assistant($conversation);

        $this->definir_para_cargar($conversation, $assistant);

        $propuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->assertTrue($propuesta['ok'], json_encode($propuesta));

        $texto = AiMessageAction::find($propuesta['tarjeta_id'])->datos['texto'];

        $this->assertStringContainsString('3 × zz-c107 Bomba Alfa (70101), $ 99,99', $texto);
        $this->assertStringContainsString('Total: $ 99,99', $texto);
        $this->assertStringContainsString('no da exacto', $texto);

        $this->cerrar($assistant);
        $this->decir_que_si($conversation);

        $this->assertEquals(99.99, (float) $this->ventas_de($planilla)[0]->total);
    }

    /**
     * Revisar con una copia vieja de la planilla no regresa lo que otro ya cargó.
     *
     * @test
     */
    public function revisar_no_regresa_una_fila_que_otro_ya_cargo()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $copia_vieja = AiPlanilla::find($planilla->id);

        // Mientras tanto, la ejecución carga el renglón 1.
        $filas = $planilla->filas;
        $filas[0]['estado'] = AiPlanilla::FILA_REGISTRADA;
        $filas[0]['venta_numero'] = 4242;
        $planilla->filas = $filas;
        $planilla->save();

        PlanillaIaHelper::revisar(ContextoDeCargaIa::de_la_conversacion($conversation), $copia_vieja);

        $fila = PlanillaIaHelper::fila_numero($planilla->fresh(), 1);

        $this->assertSame(AiPlanilla::FILA_REGISTRADA, $fila['estado']);
        $this->assertSame(4242, (int) $fila['venta_numero']);
    }

    /**
     * Una fila en curso no se revisa ni se corrige.
     *
     * @test
     */
    public function una_fila_en_curso_no_se_toca()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $filas = $planilla->filas;
        $filas[1]['en_curso'] = time();
        $filas[1]['cliente_nombre'] = 'Marca de que nadie lo tocó';
        $planilla->filas = $filas;
        $planilla->save();

        PlanillaIaHelper::revisar(ContextoDeCargaIa::de_la_conversacion($conversation), $planilla->fresh());

        $this->assertSame('Marca de que nadie lo tocó', PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['cliente_nombre']);

        $this->del_dueno($conversation, 'En el renglón 2 son 3 unidades');
        $assistant = $this->assistant($conversation);

        $respuesta = $this->herramienta('corregir_renglon_de_planilla', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 3, 'lo_que_dijo_el_dueno' => 'son 3 unidades'], $conversation, $assistant);

        $this->assertFalse($respuesta['ok']);
        $this->assertSame('2', PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['valores'][Roles::CANTIDAD]);
    }

    /**
     * A cuenta corriente sin cliente, o con un cliente sin cuenta: se pregunta antes del sí.
     *
     * @test
     */
    public function la_cuenta_corriente_sin_cliente_se_pregunta_antes_del_si()
    {
        $roles = [0 => Roles::IMPORTE, 1 => Roles::CODIGO];

        $conversation = $this->conversacion();

        $planilla = $this->planilla_con($conversation, [Roles::renglon(1, ['1.000,00', '70101'], $roles)], ['importe_es' => AiPlanilla::IMPORTE_TOTAL]);

        $this->del_dueno($conversation, 'Van a la cuenta corriente');
        $assistant = $this->assistant($conversation);

        $this->assertTrue($this->herramienta('definir_planilla', ['cobro' => 'cuenta_corriente'], $conversation, $assistant)['ok']);

        $respuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('cuenta corriente', implode(' | ', $respuesta['faltan']));
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * A cuenta corriente de punta a punta: la venta entra a la cuenta del cliente, también del nuevo.
     *
     * @test
     */
    public function las_ventas_a_cuenta_corriente_entran_a_la_cuenta()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $this->del_dueno($conversation, 'Es el total y van a la cuenta corriente de cada uno');
        $assistant = $this->assistant($conversation);

        $this->assertTrue($this->herramienta('definir_planilla', ['importe_es' => 'total', 'cobro' => 'cuenta_corriente'], $conversation, $assistant)['ok']);

        $propuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->assertTrue($propuesta['ok'], json_encode($propuesta));

        $this->cerrar($assistant);
        $this->decir_que_si($conversation);

        $ventas = $this->ventas_de($planilla);

        $this->assertCount(4, $ventas);

        foreach ($ventas as $venta) {
            $this->assertNotNull($venta->client_id);
            $this->assertTrue(CreditAccount::where('model_name', 'client')->where('model_id', $venta->client_id)->exists());
            $this->assertSame(1, \App\Models\CurrentAcount::where('sale_id', $venta->id)->count(), 'Entró a la cuenta corriente.');
        }
    }

    /**
     * El cliente que se creó en un renglón que falló después, se dice.
     *
     * @test
     */
    public function el_cliente_creado_en_un_renglon_que_fallo_se_informa()
    {
        $conversation = $this->conversacion();

        $this->tarjeta_lista($conversation);

        Article::where('user_id', $this->dueno->id)->where('provider_code', 'ZRRI-50LF')->update(['status' => 'inactive']);

        $texto = $this->decir_que_si($conversation);

        $this->assertStringContainsString('No se pudieron cargar:', $texto);
        $this->assertStringContainsString('el cliente Empresa Prueba SA quedó creado', $texto);
        $this->assertStringContainsString('Clientes nuevos creados: 4', $texto);
    }

    /**
     * El código de la columna `sku`, y lo que un código tomó sin sus ceros se dice.
     *
     * @test
     */
    public function el_sku_y_los_ceros_de_un_codigo()
    {
        $conversation = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $con_sku = $this->articulo_de_prueba('zz-c108 Con sku', ['sku' => 'SKU-108-A']);

        $this->assertSame([(int) $con_sku->id], array_map(function ($articulo) {
            return (int) $articulo->id;
        }, PlanillaIaHelper::articulos_por_codigo($contexto, 'sku-108-a')));

        $this->articulo_de_prueba('zz-c108 Sin ceros', ['bar_code' => '10823']);

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['VENTA TOTAL', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['1.000,00', '010823', '1', 'DNI', '17000001', 'Ana Prueba Uno'],
        ]));

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR);
        $assistant = $this->assistant($conversation);

        $this->definir_para_cargar($conversation, $assistant);

        $propuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->assertTrue($propuesta['ok'], json_encode($propuesta));
        $this->assertStringContainsString('(010823, tomado como 10823)', AiMessageAction::find($propuesta['tarjeta_id'])->datos['texto']);
    }
    // ---------------------------------------------------------------------
    // Lo que falta de la ejecución
    // ---------------------------------------------------------------------

    /**
     * Dos renglones iguales seguidos: la guarda anti doble clic los frena (mismo cliente y total en ±5 s), pero son dos ventas.
     *
     * @test
     */
    public function dos_renglones_iguales_seguidos_son_dos_ventas()
    {
        $this->cliente('Ana Prueba Uno', ['dni' => '17000001']);

        $iguales = ['C FINAL', '1.000,00', '13-oct', 'SKU 70101', 'Bomba', '1', 'DNI', '17000001', 'Ana Prueba Uno', 'Calle Falsa, 100'];

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([$iguales, $iguales]));

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR);
        $assistant = $this->assistant($conversation);

        $this->definir_para_cargar($conversation, $assistant);

        $this->assertTrue($this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant)['ok']);

        $this->cerrar($assistant);
        $this->decir_que_si($conversation);

        $ventas = $this->ventas_de($planilla);

        $this->assertCount(2, $ventas);
        $this->assertNotSame((int) $ventas[0]->id, (int) $ventas[1]->id);
        $this->assertSame('planilla-' . $planilla->id . '-1', (string) $ventas[0]->offline_uuid);
        $this->assertSame('planilla-' . $planilla->id . '-2', (string) $ventas[1]->offline_uuid);
    }

    /**
     * El resultado nombra lo que quedó en negativo: es lo que permite resolver «poné el stock de esos en 0».
     *
     * @test
     */
    public function el_resultado_nombra_los_articulos_que_quedaron_en_negativo()
    {
        Article::where('user_id', $this->dueno->id)->where('bar_code', '70102')->update(['stock' => 1]);

        $conversation = $this->conversacion();

        $this->tarjeta_lista($conversation);

        $texto = $this->decir_que_si($conversation);

        $this->assertStringContainsString('Quedaron con stock en negativo: zz-c107 Bomba Beta (-1).', $texto);
    }

    // ---------------------------------------------------------------------
    // Empleado y otro dueño
    // ---------------------------------------------------------------------

    /**
     * La planilla de un dueño no existe para otro, y sus artículos y clientes tampoco.
     *
     * @test
     */
    public function otro_dueno_no_ve_la_planilla_ni_le_cruza_con_lo_suyo()
    {
        $conversation = $this->conversacion();

        $this->planilla_pegada($conversation);

        $otro = User::create(['name' => 'Otro dueño', 'email' => 'c108-' . uniqid() . '@test.local', 'password' => Hash::make('x')]);

        $de_otro = \App\Models\AiConversation::create(['user_id' => $otro->id, 'auth_user_id' => $otro->id]);

        $this->del_dueno($de_otro, 'Mostrame la planilla');

        $respuesta = $this->herramienta('consultar_planilla', [], $de_otro, $this->assistant($de_otro));

        $this->assertFalse($respuesta['ok']);
        $this->assertSame(PlanillaIaHelper::SIN_PLANILLA, $respuesta['error']);

        // Un artículo del otro dueño con un código, y un renglón de este con ese código.
        Article::create(['name' => 'zz-c108 De otro', 'user_id' => $otro->id, 'status' => 'active', 'bar_code' => '70188', 'final_price' => 1, 'cost' => 1, 'stock' => 1, 'iva_id' => 2]);

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['VENTA TOTAL', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['1.000,00', '70188', '1', 'DNI', '17000009', 'Ana Prueba Nueve'],
        ]));

        $this->assertSame('70188', PlanillaIaHelper::fila_numero($planilla, 5)['valores'][Roles::CODIGO]);
        $this->assertNull(PlanillaIaHelper::fila_numero($planilla, 5)['articulo_id']);
    }

    /**
     * Un empleado: su planilla no es la de otro de la cuenta, y sin permiso de ventas no arma la tarjeta.
     *
     * @test
     */
    public function un_empleado_sin_permiso_no_arma_la_tarjeta_y_su_planilla_es_suya()
    {
        $empleado = User::create([
            'name'     => 'Empleado c108',
            'email'    => 'c108-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno->id,
        ]);

        $del_empleado = \App\Models\AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $empleado->id]);

        $planilla = $this->planilla_pegada($del_empleado);

        $this->assertNotNull($planilla, 'Un empleado también manda planillas.');
        $this->assertSame((int) $this->dueno->id, (int) $planilla->user_id);

        $this->del_dueno($del_empleado, self::PEDIDO_PARA_CARGAR);

        $respuesta = $this->herramienta('proponer_ventas_de_planilla', [], $del_empleado, $this->assistant($del_empleado));

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('permiso', $respuesta['error']);

        // La charla del dueño no la ve.
        $del_dueno = $this->conversacion();

        $this->del_dueno($del_dueno, 'Mostrame la planilla');

        $this->assertSame(PlanillaIaHelper::SIN_PLANILLA, $this->herramienta('consultar_planilla', [], $del_dueno, $this->assistant($del_dueno))['error']);
    }

    // ---------------------------------------------------------------------
    // 13. La memoria y el sello
    // ---------------------------------------------------------------------

    /**
     * @test
     */
    public function la_escala_se_calcula_contra_la_memoria_que_queda()
    {
        $mega = 1024 * 1024;

        $this->assertSame(3.0, Lectura::escala_segun_memoria(1500, 200, 1024 * $mega));
        $this->assertSame(2.0, Lectura::escala_segun_memoria(1500, 200, 20 * $mega));
        $this->assertSame(1.0, Lectura::escala_segun_memoria(1500, 200, 12 * $mega));
        $this->assertNull(Lectura::escala_segun_memoria(1500, 200, 5 * $mega));

        // Una foto de cámara no se agranda, y si no entra ni así, no entra.
        $this->assertSame(1.0, Lectura::escala_segun_memoria(4000, 3000, 1024 * $mega));
        $this->assertNull(Lectura::escala_segun_memoria(4000, 3000, 50 * $mega));

        // Más tramos de los que se mandan perdería columnas: no entra.
        $this->assertNull(Lectura::escala_segun_memoria(20000, 100, 1024 * $mega));
    }

    /**
     * Una foto de cámara se manda sin agrandar, cortada en tramos.
     *
     * @test
     */
    public function una_foto_de_camara_no_se_agranda()
    {
        $this->todos_leen($this->filas_de_prueba());
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '');
        $this->foto($pedido, 3000, 1000);

        $this->responder($conversation, $this->assistant($conversation));

        $cuerpo = json_decode($this->lecturas_de('claude-sonnet-5-5')[0][0]->body(), true);

        $tramos = [];

        foreach ($cuerpo['messages'][0]['content'] as $bloque) {

            if ($bloque['type'] === 'image') {
                $tramos[] = getimagesizefromstring(base64_decode($bloque['source']['data']));
            }
        }

        // 3000 de ancho, en tramos de 1560 con 60 de solape: 0-1560, 1500-3000.
        $this->assertCount(2, $tramos);

        foreach ($tramos as $tramo) {
            $this->assertLessThanOrEqual(1560, $tramo[0]);
            $this->assertSame(1000, $tramo[1]);
        }
    }

    /**
     * Con poca memoria, una foto grande no mata al proceso: no se puede leer, y se dice.
     *
     * @test
     */
    public function sin_memoria_una_foto_grande_no_se_lee_en_vez_de_matar_al_proceso()
    {
        $this->todos_leen($this->filas_de_prueba());
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '');
        $foto = $this->foto($pedido, 4000, 3000);

        $limite = ini_get('memory_limit');

        ini_set('memory_limit', (string) (memory_get_usage(true) + 40 * 1024 * 1024));

        try {

            $leida = Lectura::leer($foto, $conversation);

        } finally {

            ini_set('memory_limit', $limite);
        }

        $this->assertSame(Lectura::NO_SE_PUDO, $leida['resultado']);
        $this->assertSame([], $this->lecturas_de('claude-sonnet-5-5'));
    }

    /**
     * El proceso murió preparando la imagen: un fatal no se atrapa, y lo único que queda es el sello. No se reintenta.
     *
     * @test
     */
    public function un_sello_que_quedo_dice_que_el_proceso_murio_y_no_se_reintenta()
    {
        $this->todos_leen($this->filas_de_prueba());
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '');
        $foto = $this->foto($pedido);

        Cache::put(Lectura::PREFIJO_DEL_SELLO . $foto->id, time(), 3600);

        $this->assertSame(Lectura::NO_SE_PUDO, Lectura::leer($foto, $conversation)['resultado']);
        $this->assertSame(Lectura::NO_SE_PUDO, Lectura::leer($foto, $conversation)['resultado'], 'Queda recordado.');

        Http::assertNothingSent();

        // Y una lectura normal no deja sello.
        $otra = $this->foto($this->del_dueno($conversation, ''));

        $this->assertSame(Lectura::LEIDA, Lectura::leer($otra, $conversation)['resultado']);
        $this->assertFalse(Cache::has(Lectura::PREFIJO_DEL_SELLO . $otra->id));
    }

    // ---------------------------------------------------------------------
    // 14. El tiempo
    // ---------------------------------------------------------------------

    /**
     * El clasificador: tope corto, sin reintento y con lugar para contestar.
     *
     * @test
     */
    public function el_clasificador_no_reintenta_ni_espera_de_mas()
    {
        $pedidos = 0;

        Http::fake(function () use (&$pedidos) {

            $pedidos++;

            return Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'x']], 529);
        });

        $conversation = $this->conversacion();
        $foto = $this->foto($this->del_dueno($conversation, ''));

        $this->assertSame(Lectura::SIN_REVISAR, Lectura::leer($foto, $conversation)['resultado']);
        $this->assertSame(1, $pedidos);

        $this->assertSame(15, Lectura::SEGUNDOS_DEL_CLASIFICADOR);

        // Con la respuesta buena: 2000 tokens.
        $this->clasificador = false;
        $this->falsear_ia();

        $otra = $this->foto($this->del_dueno($conversation, ''));

        Lectura::leer($otra, $conversation);

        $cuerpo = null;

        foreach (Http::recorded() as $par) {
            $cuerpo = json_decode($par[0]->body(), true);
        }

        $this->assertSame(2000, $cuerpo['max_tokens']);
    }

    /**
     * El tope es del turno, no de la foto, y lo que no entra queda SIN_REVISAR unos minutos: un «sí» no paga la espera.
     *
     * @test
     */
    public function el_tope_de_tiempo_es_del_turno_y_sin_revisar_se_recuerda()
    {
        $this->todos_leen($this->filas_de_prueba());
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $foto = $this->foto($this->del_dueno($conversation, ''));

        $this->assertSame(100, Lectura::SEGUNDOS_POR_TURNO);

        $this->assertSame(Lectura::SIN_REVISAR, Lectura::leer($foto, $conversation, time() - 101)['resultado']);

        Http::assertNothingSent();

        $this->assertTrue(Lectura::quedo_sin_revisar((int) $foto->id));

        // Con tiempo de sobra, en el turno siguiente: sigue recordado.
        $this->assertSame(Lectura::SIN_REVISAR, Lectura::leer($foto, $conversation, time())['resultado']);

        Http::assertNothingSent();

        // Pasados los minutos, se vuelve a mirar.
        Carbon::setTestNow(Carbon::now()->addMinutes(Lectura::MINUTOS_DE_SIN_REVISAR + 1));

        $this->assertSame(Lectura::LEIDA, Lectura::leer($foto, $conversation, time())['resultado']);

        Carbon::setTestNow();
    }

    /**
     * Si el turno se queda sin tiempo a mitad de una lectura, la foto no es que no se pueda leer: queda sin revisar.
     *
     * @test
     */
    public function si_el_tiempo_se_termina_a_mitad_de_lectura_queda_sin_revisar()
    {
        $conversation = $this->conversacion();
        $foto = $this->foto($this->del_dueno($conversation, ''));

        // Clasifica con lo justo, tarda, y la lectura ya no entra.
        $inicio = time() - (Lectura::SEGUNDOS_POR_TURNO - Lectura::SEGUNDOS_DE_MARGEN) + 1;

        Http::fake(function () {

            sleep(3);

            return Http::response($this->texto_del_modelo(json_encode(['es_planilla' => true])), 200);
        });

        $resultado = Lectura::leer($foto, $conversation, $inicio)['resultado'];

        $this->assertSame(Lectura::SIN_REVISAR, $resultado);
    }

    // ---------------------------------------------------------------------
    // 15 y 16. Lo que no es una planilla de ventas
    // ---------------------------------------------------------------------

    /**
     * Un Excel de stock en foto: no hay planilla, y el modelo recibe lo que dice para poder contestar.
     *
     * @test
     */
    public function una_foto_de_un_excel_de_stock_queda_transcripta_y_no_es_una_planilla()
    {
        $filas = [
            ['77900011', 'Martillo galponero', '12', '15.000,00'],
            ['77900028', 'Pinza universal', '7', '9.500,00'],
        ];

        $this->todos_leen($filas, ['CODIGO', 'DESCRIPCION', 'STOCK', 'PRECIO']);
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '¿Cuánto stock tengo de martillos según esto?');
        $foto = $this->foto($pedido);

        Storage::disk('local')->put($foto->path . AsistenteImagenHelper::SUFIJO_DEL_ORIGINAL, (string) (new ImageManager())->canvas(1800, 100, '#ffffff')->encode('png'));

        $this->responder($conversation, $this->assistant($conversation));

        $this->assertSame(0, AiPlanilla::where('ai_conversation_id', $conversation->id)->count());
        $this->assertNotNull($foto->fresh()->gestionada_at);
        $this->assertFalse(Storage::disk('local')->exists($foto->path . AsistenteImagenHelper::SUFIJO_DEL_ORIGINAL), 'El original no sobrevive.');

        $cuerpos = $this->requests_del_modelo_que_conversa();

        $json = json_encode($cuerpos[0]['messages'], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('"type":"image"', $json);
        $this->assertStringContainsString('una tabla que no es una planilla de ventas', $json);
        $this->assertStringContainsString('Martillo galponero | 12 | 15.000,00', $json);
        $this->assertStringContainsString('¿Cuánto stock tengo de martillos', $json);
    }

    /**
     * 7 u 8 dígitos no alcanzan para ser un documento: un código de barras también los tiene.
     *
     * @test
     */
    public function siete_u_ocho_digitos_no_alcanzan_para_ser_un_documento()
    {
        $stock = $this->como_texto([
            ['77900011', 'Martillo galponero', '70101', '15.000,00'],
            ['77900028', 'Pinza universal', '70102', '9.500,00'],
        ]);

        $this->assertNull(PlanillaDeTextoIaHelper::tabla_del_texto($stock));

        // Con un título, una columna de tipo, una de comprobante o una de operación, sí.
        $this->assertNotNull(PlanillaDeTextoIaHelper::tabla_del_texto($this->como_texto([
            ['DNI', 'DETALLE', 'CODIGO', 'TOTAL'],
            ['17000001', 'Martillo galponero', '70101', '15.000,00'],
        ])));

        $this->assertNotNull(PlanillaDeTextoIaHelper::tabla_del_texto($this->como_texto([
            ['DNI', '17000001', 'Martillo galponero', 'SKU 70101', '15.000,00'],
        ])));

        $this->assertNotNull(PlanillaDeTextoIaHelper::tabla_del_texto($this->como_texto([
            ['C FINAL', '17000001', 'Martillo galponero', '15.000,00'],
        ])), 'Con C FINAL ya son ventas.');

        $this->assertNotNull(PlanillaDeTextoIaHelper::tabla_del_texto($this->como_texto([
            ['#2000000000000001', '17000001', 'Martillo galponero', 'SKU 70101', '15.000,00'],
        ])));

        // 11 dígitos con el verificador bien también.
        $this->assertNotNull(PlanillaDeTextoIaHelper::tabla_del_texto($this->como_texto([
            [self::CUIT_EMPRESA, 'Martillo galponero', 'SKU 70101', '15.000,00'],
        ])));
    }

    /**
     * Con una planilla en curso, una tabla que no cruza no se traga: el modelo la sigue viendo.
     *
     * @test
     */
    public function una_tabla_que_no_cruza_con_la_planilla_en_curso_pasa_como_texto()
    {
        $this->falsear_ia();

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $lista = $this->como_texto([
            ['DNI', 'NOMBRE', 'TELEFONO'],
            ['40111222', 'Otra Persona', '11223344'],
            ['40111333', 'Tercera Persona', '11223355'],
        ]);

        $this->del_dueno($conversation, $lista);

        $this->responder($conversation, $this->assistant($conversation));

        $this->assertCount(4, $planilla->fresh()->filas);

        $cuerpos = $this->requests_del_modelo_que_conversa();

        $this->assertStringContainsString('Tercera Persona', json_encode($cuerpos[0]['messages'], JSON_UNESCAPED_UNICODE));

        // Y una que sí cruza, la completa.
        $this->assertSame('17000001', PlanillaIaHelper::fila_numero($planilla->fresh(), 1)['valores'][Roles::DOCUMENTO]);
    }

    /**
     * Una línea que no encaja no desaparece: entra como renglón con lo que le falta, o el texto no es una planilla.
     *
     * @test
     */
    public function una_linea_que_no_encaja_no_desaparece()
    {
        $filas = $this->filas_de_prueba();

        $texto = implode("\t", $filas[0]) . "\n" . 'Martillo para Dario' . "\n" . implode("\t", $filas[1]) . "\n" . "Pinza\t$ 5.000" . "\n" . implode("\t", $filas[2]);

        $tabla = PlanillaDeTextoIaHelper::tabla_del_texto($texto);

        $this->assertCount(5, $tabla['filas']);
        $this->assertSame('Martillo para Dario', $tabla['filas'][1][0]);
        $this->assertSame(Roles::COMPROBANTE, $tabla['roles'][0], 'La línea suelta no cambia lo que es cada columna.');

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $texto);

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR);

        $respuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $this->assistant($conversation));

        $this->assertFalse($respuesta['ok'], 'El dueño la ve en lo que falta.');
        $this->assertStringContainsString('Renglón 2', implode(' | ', $respuesta['faltan']));
        $this->assertCount(5, $planilla->fresh()->filas);

        // Con otro separador no hay cómo saber qué es esa línea: no es una planilla.
        $con_punto_y_coma = "C FINAL;1.000,00;SKU 70101;DNI;17000001\nMartillo para Dario\nC FINAL;2.000,00;SKU 70102;DNI;14000002";

        $this->assertNull(PlanillaDeTextoIaHelper::tabla_del_texto($con_punto_y_coma));
    }

    /**
     * Una tabla separada por espacios: no se puede partir por código, y la leen las mismas lecturas, sin imagen.
     *
     * @test
     */
    public function una_tabla_separada_por_espacios_se_lee_con_claude_y_sin_imagen()
    {
        $this->todos_leen($this->filas_de_prueba());
        $this->falsear_ia();

        $texto = "Cargame esto\nC FINAL 61.250,00 13-oct SKU 70101 #2000000000000001 1 DNI 17000001 Ana Prueba Uno\nC FINAL 88.400,00 13-oct SKU 70102 #2000000000000002 2 DNI 14000002 Bruno Prueba Dos";

        $conversation = $this->conversacion();
        $this->del_dueno($conversation, $texto);

        $this->responder($conversation, $this->assistant($conversation));

        $planilla = AiPlanilla::where('ai_conversation_id', $conversation->id)->first();

        $this->assertNotNull($planilla);
        $this->assertSame(AiPlanilla::ORIGEN_TEXTO, $planilla->origen);

        $lecturas = $this->lecturas_de('claude-sonnet-5-5');

        $this->assertCount(1, $lecturas);
        $this->assertStringNotContainsString('"type":"image"', $lecturas[0][0]->body());
        $this->assertStringContainsString('Ana Prueba Uno', $lecturas[0][0]->body());

        $json = json_encode($this->requests_del_modelo_que_conversa()[0]['messages'], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('[Tabla pegada: planilla N°' . $planilla->id, $json);
        $this->assertStringContainsString('Cargame esto', $json);
        $this->assertStringNotContainsString('61.250,00', $json, 'El modelo no ve las líneas.');

        // Un mensaje común no paga ninguna lectura.
        $this->assertSame([], PlanillaDeTextoIaHelper::lineas_de_ventas_con_espacios('Vendele a Juan un martillo por $ 5.000'));
        $this->assertSame([], PlanillaDeTextoIaHelper::lineas_de_ventas_con_espacios("Hola\nQuedó en 5.000\nGracias"));
    }

    // ---------------------------------------------------------------------
    // 17. La salida de una planilla falsa
    // ---------------------------------------------------------------------

    /**
     * Lo que no era una planilla se descarta con lo que dice el dueño, y vuelve a ser una tabla para el modelo.
     *
     * @test
     */
    public function una_planilla_falsa_se_descarta_con_lo_que_dice_el_dueno()
    {
        $this->todos_leen($this->filas_de_prueba());
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '');
        $foto = $this->foto($pedido);

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

        $planilla = AiPlanilla::where('ai_conversation_id', $conversation->id)->first();

        $descartar = ['renglon' => 'todos', 'campo' => 'descartar', 'lo_que_dijo_el_dueno' => 'x'];

        // Con un «sí», no.
        $this->del_dueno($conversation, 'Sí');
        $con_si = $this->assistant($conversation);

        $this->assertSame('un_si_no_corrige', $this->herramienta('corregir_renglon_de_planilla', array_merge($descartar, ['lo_que_dijo_el_dueno' => 'Sí']), $conversation, $con_si)['codigo']);

        $this->cerrar($con_si);

        // Sin nombrar a la planilla, tampoco.
        $this->del_dueno($conversation, 'Mirá vos');
        $sin_nombrar = $this->assistant($conversation);

        $this->assertSame('sin_ancla', $this->herramienta('corregir_renglon_de_planilla', array_merge($descartar, ['lo_que_dijo_el_dueno' => 'Mirá vos']), $conversation, $sin_nombrar)['codigo']);

        $this->cerrar($sin_nombrar);

        // Diciéndolo, sí.
        $this->del_dueno($conversation, 'No es una planilla para cargar, era para que la mires');
        $diciendo = $this->assistant($conversation);

        $hecho = $this->herramienta('corregir_renglon_de_planilla', array_merge($descartar, ['lo_que_dijo_el_dueno' => 'No es una planilla para cargar']), $conversation, $diciendo);

        $this->assertTrue($hecho['ok'], json_encode($hecho));
        $this->assertSame(4, $hecho['descartados']);
        $this->assertSame(AiPlanilla::ESTADO_REEMPLAZADA, $planilla->fresh()->estado);
        $this->assertNull(PlanillaIaHelper::planilla_activa(ContextoDeCargaIa::de_la_conversacion($conversation)));

        // El modelo ve lo que dice la tabla, y no la foto.
        $marca = PlanillaIaHelper::lo_leido_de($conversation)['fotos'][(int) $foto->id];

        $this->assertStringContainsString('una tabla que no es una planilla de ventas', $marca);
        $this->assertStringContainsString('Ana Prueba Uno', $marca);
    }

    /**
     * La guarda de la venta suelta va por documento o id exactos: un nombre parecido no frena nada.
     *
     * @test
     */
    public function un_nombre_parecido_no_frena_una_venta_suelta()
    {
        $parecida = $this->cliente('Ana Prueba', ['dni' => '40111222']);

        $otro_articulo = $this->articulo_de_prueba('zz-c108 Otro articulo', ['bar_code' => '70199']);

        $conversation = $this->conversacion();

        $this->planilla_pegada($conversation);

        $this->del_dueno($conversation, 'Vendele a Ana Prueba');

        $respuesta = $this->herramienta('proponer_venta', [
            'cliente'        => $parecida->name,
            'cobro'          => 'contado',
            'metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO,
            'caja'           => TestingFerreteriaSeeder::CAJA_EFECTIVO,
            'items'          => [['articulo_id' => $otro_articulo->id, 'cantidad' => 1]],
        ], $conversation, $this->assistant($conversation));

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
    }

    // ---------------------------------------------------------------------
    // 18. La nota de una foto sin revisar
    // ---------------------------------------------------------------------

    /**
     * La advertencia de una foto sin clasificar acompaña la transcripción de Haiku en cada turno y no frena una factura.
     *
     * @test
     */
    public function la_nota_de_una_foto_sin_revisar_va_en_cada_turno_en_que_viaja()
    {
        $this->clasificador = null;
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, 'Mirá');
        $this->foto($pedido);

        $this->responder($conversation, $this->assistant($conversation));

        $this->del_dueno($conversation, 'Gracias');

        $this->responder($conversation, $this->assistant($conversation));

        $cuerpos = $this->requests_del_modelo_que_conversa();

        $this->assertCount(2, $cuerpos);

        foreach ($cuerpos as $cuerpo) {

            $json = json_encode($cuerpo['messages'], JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString('"type":"image"', $json);
            $this->assertStringContainsString('Lectura transitoria con Haiku', $json);
            $this->assertStringContainsString('si es una planilla de ventas o de clientes, no la transcribas y pedí el texto', mb_strtolower($json));
            $this->assertStringContainsString('una factura, un remito', $json);
        }

        $this->assert_deepseek_conversa_sin_imagenes();
    }

    // ---------------------------------------------------------------------
    // 19. Forzar la lectura
    // ---------------------------------------------------------------------

    /**
     * Si el clasificador se equivoca, la herramienta manda la foto a la lectura.
     *
     * @test
     */
    public function leer_planilla_de_la_foto_lee_sin_clasificador()
    {
        $this->clasificador = false;
        $this->todos_leen($this->filas_de_prueba());
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '');
        $foto = $this->foto($pedido);

        // El clasificador dijo que no: la foto siguió de largo.
        PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

        $this->assertSame(0, AiPlanilla::where('ai_conversation_id', $conversation->id)->count());

        $this->del_dueno($conversation, 'Cargá las ventas de esa foto');

        $respuesta = $this->herramienta('leer_planilla_de_la_foto', [], $conversation, $this->assistant($conversation));

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertCount(4, $respuesta['renglones']);

        $this->assertNotNull($foto->fresh()->gestionada_at);

        // Ya está en una planilla: no se vuelve a leer.
        $otra_vez = $this->herramienta('leer_planilla_de_la_foto', [], $conversation, $this->assistant($conversation));

        $this->assertFalse($otra_vez['ok']);
        $this->assertCount(1, $this->lecturas_de('claude-sonnet-5-5'));

        // Está en la lista de herramientas, al final, y el prompt dice cuándo se usa.
        $nombres = HerramientasDeCarga::nombres();

        $this->assertContains('leer_planilla_de_la_foto', $nombres);

        $prompt = (new AsistenteIaService())->build_system_prompt($conversation, $this->dueno->fresh(), true, true);

        $this->assertStringContainsString('leer_planilla_de_la_foto', $prompt);
    }

    /**
     * Una tabla que no parecía de ventas también se puede cargar, si el dueño lo pide.
     *
     * @test
     */
    public function leer_planilla_de_la_foto_toma_una_tabla_transcripta()
    {
        $filas = [['Martillo galponero', '70101', '15.000,00', 'Ana Prueba Uno']];

        $this->todos_leen($filas);
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '');
        $foto = $this->foto($pedido);

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

        $this->assertSame(0, AiPlanilla::where('ai_conversation_id', $conversation->id)->count());
        $this->assertNotNull(Lectura::transcripcion_guardada((int) $foto->id));

        $this->del_dueno($conversation, 'Son ventas, cargalas');

        $respuesta = $this->herramienta('leer_planilla_de_la_foto', [], $conversation, $this->assistant($conversation));

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(1, AiPlanilla::where('ai_conversation_id', $conversation->id)->count());

        $this->assertStringContainsString('ya leída', PlanillaIaHelper::lo_leido_de($conversation)['fotos'][(int) $foto->id], 'La marca ya es la de una planilla.');
    }

    /**
     * Si no se puede leer, no inventa: dice que no y no guarda nada.
     *
     * @test
     */
    public function leer_planilla_de_la_foto_sin_poder_leer_no_guarda_nada()
    {
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, 'Cargalas');
        $this->foto($pedido);

        $respuesta = $this->herramienta('leer_planilla_de_la_foto', [], $conversation, $this->assistant($conversation));

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('No la transcribas vos', $respuesta['error']);
        $this->assertSame(0, AiPlanilla::where('ai_conversation_id', $conversation->id)->count());
    }

    // ---------------------------------------------------------------------
    // 20, 21 y 22. La imagen
    // ---------------------------------------------------------------------

    /**
     * El original solo sobrevive mientras hace falta.
     *
     * @test
     */
    public function el_original_se_borra_cuando_ya_no_hace_falta()
    {
        $this->todos_leen($this->filas_de_prueba());
        $this->falsear_ia();

        $conversation = $this->conversacion();

        $con_original = function () use ($conversation) {

            $foto = $this->foto($this->del_dueno($conversation, ''));

            Storage::disk('local')->put($foto->path . AsistenteImagenHelper::SUFIJO_DEL_ORIGINAL, (string) (new ImageManager())->canvas(1800, 100, '#ffffff')->encode('png'));

            return $foto;
        };

        // Leída.
        $leida = $con_original();

        $this->assertSame(Lectura::LEIDA, Lectura::leer($leida, $conversation)['resultado']);
        $this->assertFalse(Storage::disk('local')->exists($leida->path . AsistenteImagenHelper::SUFIJO_DEL_ORIGINAL));

        // No era una planilla.
        $this->clasificador = false;

        $no_es = $con_original();

        $this->assertSame(Lectura::NO_ES_PLANILLA, Lectura::leer($no_es, $conversation)['resultado']);
        $this->assertFalse(Storage::disk('local')->exists($no_es->path . AsistenteImagenHelper::SUFIJO_DEL_ORIGINAL));

        // Sin revisar todavía hace falta.
        $this->clasificador = null;

        $sin_revisar = $con_original();

        $this->assertSame(Lectura::SIN_REVISAR, Lectura::leer($sin_revisar, $conversation)['resultado']);
        $this->assertTrue(Storage::disk('local')->exists($sin_revisar->path . AsistenteImagenHelper::SUFIJO_DEL_ORIGINAL));
    }

    /**
     * Un tramo que pesa de más va como JPG, y el JPG también se controla.
     *
     * @test
     */
    public function un_tramo_que_pesa_de_mas_va_como_jpg()
    {
        $this->assertSame(3500000, Lectura::BYTES_POR_TRAMO);

        $core = imagecreatetruecolor(1500, 1000);

        for ($x = 0; $x < 1500; $x++) {

            for ($y = 0; $y < 1000; $y++) {

                imagesetpixel($core, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }

        $codificar = new \ReflectionMethod(Lectura::class, 'codificar_tramo');
        $codificar->setAccessible(true);

        $tramo = $codificar->invoke(null, (new ImageManager())->make($core));

        $this->assertSame('image/jpeg', $tramo['media_type']);
        $this->assertLessThanOrEqual(3500000, strlen(base64_decode($tramo['data'])));

        // Sin ruido, PNG: se leen dígitos.
        $this->assertSame('image/png', $codificar->invoke(null, (new ImageManager())->canvas(500, 100, '#ffffff'))['media_type']);
    }

    /**
     * Un encabezado que no tiene las mismas columnas que las filas no se usa: los títulos quedarían corridos.
     *
     * @test
     */
    public function un_encabezado_con_otra_cantidad_de_columnas_se_ignora()
    {
        $this->todos_leen($this->filas_de_prueba(), ['TIPO', 'TOTAL', 'CODIGO', 'DESCRIPCION', 'OPERACION', 'CANTIDAD', 'DOC', 'DNI', 'NOMBRE', 'DIRECCION']);
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $this->foto($this->del_dueno($conversation, ''));

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

        $planilla = AiPlanilla::where('ai_conversation_id', $conversation->id)->first();

        $this->assertNull($planilla->columnas);
        $this->assertNull($planilla->importe_es, 'Sin títulos no se sabe si es el total: se pregunta.');
        $this->assertSame('61.250,00', PlanillaIaHelper::fila_numero($planilla, 1)['valores'][Roles::IMPORTE]);
        $this->assertSame('13-oct', PlanillaIaHelper::fila_numero($planilla, 1)['valores'][Roles::FECHA]);
    }

    // ---------------------------------------------------------------------
    // 23. Lo que ve el dueño
    // ---------------------------------------------------------------------

    /**
     * Cada duda va con el cliente y el artículo: el dueño no numeró su Excel.
     *
     * @test
     */
    public function cada_duda_dice_de_quien_es_y_de_que_articulo()
    {
        $roles = [0 => Roles::IMPORTE, 1 => Roles::CODIGO, 2 => Roles::DOCUMENTO, 3 => Roles::NOMBRE];

        $conversation = $this->conversacion();

        $planilla = $this->planilla_con($conversation, [
            Roles::renglon(3, ['1.000,00', '70101', '30712345671', 'Empresa Prueba SA'], $roles, [2 => ['30712345671', '30712345698']]),
        ], ['importe_es' => AiPlanilla::IMPORTE_TOTAL]);

        PlanillaIaHelper::revisar(ContextoDeCargaIa::de_la_conversacion($conversation), $planilla);

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR);

        $respuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $this->assistant($conversation));

        $this->assertContains('Renglón 3 (Empresa Prueba SA, zz-c107 Bomba Alfa): el documento se lee 30712345671 o 30712345698, ¿cuál es?', $respuesta['faltan']);
    }
    // ---------------------------------------------------------------------
    // Parte 3. P1: qué modelo lee
    // ---------------------------------------------------------------------

    /**
     * Sonnet y Haiku leen, Opus desempata. El fallback va en Sonnet y en Opus, con su header; en Haiku no existe.
     *
     * @test
     */
    public function sonnet_y_haiku_leen_opus_desempata_y_haiku_no_lleva_fallback()
    {
        $this->assertSame('claude-sonnet-5-5', config('services.lectura_planilla.modelo_a'));
        $this->assertSame('claude-haiku-5-5', config('services.lectura_planilla.modelo_b'));
        $this->assertSame('claude-opus-5-5', config('services.lectura_planilla.modelo_desempate'));

        $bien = $this->filas_de_prueba();
        $mal = $this->filas_de_prueba();
        $mal[1][1] = '88.000,00';

        $this->lecturas = [
            'claude-sonnet-5-5' => $this->lectura($bien),
            'claude-haiku-5-5'  => $this->lectura($mal),
            'claude-opus-5-5'   => $this->lectura($bien),
        ];

        $this->falsear_ia();

        $conversation = $this->conversacion();
        $this->foto($this->del_dueno($conversation, ''));

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

        foreach (['claude-sonnet-5-5' => true, 'claude-haiku-5-5' => false, 'claude-opus-5-5' => true] as $modelo => $con_fallback) {

            $pedidos = $this->lecturas_de($modelo);

            $this->assertCount(1, $pedidos, $modelo);

            $cuerpo = json_decode($pedidos[0][0]->body(), true);

            $this->assertSame($con_fallback, array_key_exists('fallbacks', $cuerpo), $modelo);
            $this->assertSame($con_fallback, mb_strpos(implode(',', $pedidos[0][0]->header('anthropic-beta')), Lectura::BETA_DEL_FALLBACK) !== false, $modelo);
        }

        // Sin desacuerdo, Opus ni se llama.
        Http::fake();

        $this->todos_leen($bien);
        $this->falsear_ia();

        $otra = $this->conversacion();
        $otra_foto = $this->foto($this->del_dueno($otra, ''));

        $antes = count($this->lecturas_de('claude-opus-5-5'));

        Lectura::leer($otra_foto, $otra);

        $this->assertSame($antes, count($this->lecturas_de('claude-opus-5-5')));
    }

    // ---------------------------------------------------------------------
    // Parte 3. P2: la foto que no se lee
    // ---------------------------------------------------------------------

    /**
     * 🔴 Dos modelos pueden coincidir en un valor equivocado: por debajo de 12 px por fila no hay planilla, aunque coincidan.
     *
     * @test
     */
    public function una_foto_con_menos_de_doce_pixeles_por_fila_no_es_una_planilla()
    {
        $this->assertSame(12, Lectura::MINIMO_DE_PX_POR_FILA);

        $this->todos_leen($this->filas_de_prueba());
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, 'Cargá esto');
        $foto = $this->foto($pedido, 1500, 40);

        $notas = PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

        $this->assertSame(0, AiPlanilla::where('ai_conversation_id', $conversation->id)->count());
        $this->assertTrue(Lectura::quedo_sin_poder_leerse((int) $foto->id));
        $this->assertNotNull($foto->fresh()->gestionada_at);

        $nota = implode(' ', $notas);

        $this->assertStringContainsString('poca resolución', $nota);
        $this->assertStringContainsString('captura más grande', $nota);
        $this->assertStringContainsString('como texto', $nota);
    }

    /**
     * El corte: alto / (filas + encabezado), sobre la imagen más grande. 12 pasa, y el encabezado cuenta.
     *
     * @test
     */
    public function el_corte_por_resolucion_cuenta_el_encabezado_y_mira_el_original()
    {
        $filas = $this->filas_de_prueba();

        $this->assertFalse(Lectura::motivo_si_no_se_puede_confiar(1500, 48, ['encabezado' => null, 'filas' => $filas]), '48 / 4 = 12: pasa.');
        $this->assertTrue(Lectura::motivo_si_no_se_puede_confiar(1500, 47, ['encabezado' => null, 'filas' => $filas]));
        $this->assertTrue(Lectura::motivo_si_no_se_puede_confiar(1500, 48, ['encabezado' => ['a', 'b'], 'filas' => $filas]), '48 / 5 = 9,6: no pasa.');

        // Con un original grande, se mide ese: el webp achicado no es lo que se leyó.
        $this->todos_leen($filas);
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $foto = $this->foto($this->del_dueno($conversation, ''), 1500, 40);

        Storage::disk('local')->put($foto->path . AsistenteImagenHelper::SUFIJO_DEL_ORIGINAL, (string) (new ImageManager())->canvas(1800, 200, '#ffffff')->encode('png'));

        $this->assertSame(Lectura::LEIDA, Lectura::leer($foto, $conversation)['resultado']);
    }

    /**
     * Una foto de cámara no se puede medir: sigue, pero con dos filas rotas no se le cree.
     *
     * @test
     */
    public function una_foto_de_camara_con_dos_filas_rotas_no_es_una_planilla()
    {
        $una_mala = $this->filas_de_prueba();
        $una_mala[2][8] = self::CUIT_MAL;

        $dos_malas = $una_mala;
        $dos_malas[3][8] = '30712345672';

        $importes_malos = $this->filas_de_prueba();
        $importes_malos[0][1] = 'ilegible';
        $importes_malos[1][1] = 'tampoco';

        $this->assertFalse(Lectura::motivo_si_no_se_puede_confiar(3000, 2000, ['encabezado' => null, 'filas' => $una_mala]), 'Una sola es una duda.');
        $this->assertTrue(Lectura::motivo_si_no_se_puede_confiar(3000, 2000, ['encabezado' => null, 'filas' => $dos_malas]));
        $this->assertTrue(Lectura::motivo_si_no_se_puede_confiar(3000, 2000, ['encabezado' => null, 'filas' => $importes_malos]));

        // Sin medidas, lo mismo.
        $this->assertTrue(Lectura::motivo_si_no_se_puede_confiar(0, 0, ['encabezado' => null, 'filas' => $dos_malas]));

        // De punta a punta.
        $this->todos_leen($dos_malas);
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $foto = $this->foto($this->del_dueno($conversation, ''), 3000, 1000);

        $this->assertSame(Lectura::NO_SE_PUDO, Lectura::leer($foto, $conversation)['resultado']);
    }

    // ---------------------------------------------------------------------
    // Parte 3. P3: el Excel adjunto
    // ---------------------------------------------------------------------

    /**
     * Un mensaje con un archivo ya leído.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  string  $texto
     * @param  array  $extra
     * @return \App\Models\AiMessage
     */
    protected function con_archivo(\App\Models\AiConversation $conversation, $texto, array $extra = [])
    {
        $pedido = $this->del_dueno($conversation, 'Cargá estas ventas: es el total y se cobraron en Efectivo, Caja Efectivo');

        $pedido->archivos = [array_merge([
            'orden'     => 1,
            'nombre'    => 'ventas.xlsx',
            'tipo'      => 'xlsx',
            'estado'    => ArchivosAdjuntosIaHelper::ESTADO_LISTO,
            'texto'     => $texto,
            'recortado' => false,
        ], $extra)];

        $pedido->save();

        return $pedido;
    }

    /**
     * El texto de la hoja de un Excel de ventas, como lo deja ArchivosAdjuntosIaHelper.
     *
     * @return string
     */
    protected function texto_de_la_hoja()
    {
        return "Hoja «Octubre»:\nVentas de octubre\nVENTA TOTAL | CODIGO | CANTIDAD | TIPO | DOCUMENTO | NOMBRE\n1.000,00 | 70101 | 1 | DNI | 17000001 | Ana Prueba Uno\n2.000,00 | 70102 | 2 | DNI | 14000002 | Bruno Prueba Dos";
    }

    /**
     * Un Excel de ventas queda como planilla, con su tarjeta, sin ningún modelo. Y el modelo no ve sus filas.
     *
     * @test
     */
    public function un_excel_adjunto_de_ventas_queda_como_planilla_y_arma_su_tarjeta()
    {
        $this->falsear_ia();

        $conversation = $this->conversacion();

        $this->con_archivo($conversation, $this->texto_de_la_hoja());

        $this->vueltas = [
            $this->pide_herramienta('definir_planilla', ['metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO, 'caja' => TestingFerreteriaSeeder::CAJA_EFECTIVO]),
            $this->pide_herramienta('proponer_ventas_de_planilla'),
            $this->texto_del_modelo('Listo.'),
        ];

        $texto = $this->responder($conversation, $this->assistant($conversation));

        $planilla = AiPlanilla::where('ai_conversation_id', $conversation->id)->first();

        $this->assertNotNull($planilla);
        $this->assertSame(AiPlanilla::ORIGEN_ARCHIVO, $planilla->origen);
        $this->assertCount(2, $planilla->filas);
        $this->assertSame(AiPlanilla::IMPORTE_TOTAL, $planilla->importe_es);
        $this->assertSame('2', PlanillaIaHelper::fila_numero($planilla, 2)['valores'][Roles::CANTIDAD]);

        $this->assertSame([], $this->lecturas_de('claude-sonnet-5-5'), 'Sin ningún modelo.');

        $this->assertStringContainsString('2 ventas para cargar', $texto);
        $this->assertSame(1, AiMessageAction::where('ai_conversation_id', $conversation->id)->where('tipo', AiMessageAction::TIPO_VENTAS_PLANILLA)->count());

        $json = json_encode($this->requests_del_modelo_que_conversa()[0]['messages'], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('[Archivo adjunto: planilla N°' . $planilla->id . ' ya leída, 2 renglones', $json);
        $this->assertStringNotContainsString('Ana Prueba Uno', $json);

        // Otro turno no lo vuelve a cruzar.
        $this->del_dueno($conversation, 'Gracias');

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

        $this->assertCount(2, $planilla->fresh()->filas);
    }

    /**
     * Si el texto se recortó, no se arma una planilla a medias: se dice.
     *
     * @test
     */
    public function un_excel_recortado_no_arma_una_planilla_a_medias()
    {
        $this->falsear_ia();

        foreach ([
            [$this->texto_de_la_hoja(), ['recortado' => true]],
            [str_replace('Hoja «Octubre»:', 'Hoja «Octubre» (se leyeron las primeras 200 filas):', $this->texto_de_la_hoja()), []],
        ] as $caso) {

            $conversation = $this->conversacion();

            $this->con_archivo($conversation, $caso[0], $caso[1]);

            $notas = PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

            $this->assertSame(0, AiPlanilla::where('ai_conversation_id', $conversation->id)->count());
            $this->assertStringContainsString('no se armó ninguna planilla', implode(' ', $notas));
            $this->assertStringContainsString('ventas.xlsx', implode(' ', $notas));

            AiPlanilla::where('user_id', $this->dueno->id)->delete();
        }
    }

    /**
     * Un Excel que no es de ventas sigue siendo un archivo: el modelo lo lee como siempre.
     *
     * @test
     */
    public function un_excel_de_stock_sigue_siendo_un_archivo_para_el_modelo()
    {
        $this->falsear_ia();

        $conversation = $this->conversacion();

        $this->con_archivo($conversation, "Hoja «Stock»:\nCODIGO | DESCRIPCION | STOCK | PRECIO\n77900011 | Martillo galponero | 12 | 15.000,00");

        $this->responder($conversation, $this->assistant($conversation));

        $this->assertSame(0, AiPlanilla::where('ai_conversation_id', $conversation->id)->count());

        $this->assertStringContainsString('Martillo galponero', json_encode($this->requests_del_modelo_que_conversa()[0]['messages'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * De punta a punta con un CSV de verdad, por el mismo extractor de siempre.
     *
     * @test
     */
    public function un_csv_real_pasa_por_el_extractor_y_queda_como_planilla()
    {
        $this->falsear_ia();

        $conversation = $this->conversacion();

        $pedido = $this->del_dueno($conversation, 'Cargá estas ventas');

        $path = 'asistente_archivos/' . $this->dueno->id . '/' . $pedido->id . '/1.csv';

        Storage::disk('local')->put($path, "VENTA TOTAL;CODIGO;CANTIDAD;TIPO;DOCUMENTO;NOMBRE\n1.000,00;70101;1;DNI;17000001;Ana Prueba Uno\n");

        $pedido->archivos = [[
            'orden'     => 1,
            'nombre'    => 'ventas.csv',
            'tipo'      => 'csv',
            'path'      => $path,
            'estado'    => ArchivosAdjuntosIaHelper::ESTADO_PENDIENTE,
            'texto'     => null,
            'recortado' => false,
        ]];
        $pedido->save();

        ArchivosAdjuntosIaHelper::extraer_pendientes($pedido->fresh());

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

        $planilla = AiPlanilla::where('ai_conversation_id', $conversation->id)->first();

        $this->assertNotNull($planilla);
        $this->assertSame('Ana Prueba Uno', PlanillaIaHelper::fila_numero($planilla, 1)['valores'][Roles::NOMBRE]);
    }
}
