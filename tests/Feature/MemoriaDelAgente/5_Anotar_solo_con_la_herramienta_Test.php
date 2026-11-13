<?php

namespace Tests\Feature\MemoriaDelAgente;

use App\Http\Controllers\Helpers\asistente_ia\AgenteContextoHelper;
use App\Http\Controllers\Helpers\asistente_ia\AnotacionPrometidaIaHelper;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Support\Facades\Http;

/**
 * Misión asistente-truvari-pedidos-fallidos (A5, 13/11/2026) — «Me lo anoto» solo con guardar_en_contexto.
 *
 * 🔴 EL CASO REAL. Truvari, 12/11/2026 por WhatsApp: «Me lo anoto» seis veces (una caja es una unidad, una
 * sola sucursal y un solo depósito, la cepa solo en los vinos, contestar más rápido) y guardar_en_contexto
 * nunca se llamó: la conversación siguiente no sabía nada. La regla de la memoria enseñaba «Me lo anoto.»
 * como la frase de cuando guarda, y el modelo la usaba de muletilla.
 *
 * 🔴 LA POLÍTICA QUE CAMBIA. Hasta acá «Me lo anoto.» era la frase pedida y nada la cruzaba con la
 * herramienta. Ahora: solo con un guardar_en_contexto ok en el turno; si no, un reintento que pide la
 * herramienta y, si el modelo insiste, se saca la oración y se avisa que no quedó guardado. Y lo aprendido
 * (correcciones y cómo quiere que le respondan) viaja siempre en el prompt, para usarse sin leer_contexto.
 */
class Anotar_solo_con_la_herramienta_Test extends MemoriaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.deepseek.model'          => 'deepseek-general-p5m',
            'services.deepseek.model_agil'     => 'deepseek-flash-p5m',
            'services.deepseek.model_profundo' => 'deepseek-pro-p5m',
        ]);
    }

    protected function el_modelo_contesta(array $respuestas)
    {
        $secuencia = Http::sequence()->whenEmpty(Http::response(['error' => ['type' => 'api_error', 'message' => 'la secuencia se agotó (P5M)']], 500));

        foreach ($respuestas as $respuesta) {
            $secuencia->push($respuesta, 200);
        }

        Http::fake(['api.deepseek.com/*' => $secuencia]);
    }

    protected function nota_del_reintento()
    {
        $segundo  = Http::recorded()[1][0]->data();
        $ultimo   = $segundo['messages'][count($segundo['messages']) - 1];

        return is_string($ultimo['content']) ? $ultimo['content'] : json_encode($ultimo['content'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 🔴 Mensaje 148: «Me lo anoto para no volver a preguntarlo en cada presupuesto.» sin la herramienta. Un
     * reintento que la pide; el reintento guarda en correcciones y recién ahí «Me lo anoto.».
     *
     * @test
     */
    public function me_lo_anoto_sin_la_herramienta_reintenta_y_el_reintento_guarda()
    {
        $this->el_modelo_contesta([
            $this->end_turn("Sí, quedó claro: una caja pedida es una unidad del artículo.\nMe lo anoto para no volver a preguntarlo en cada presupuesto."),
            $this->tool_use('guardar_en_contexto', [
                'ruta'      => 'aprendizajes/correcciones.md',
                'operacion' => 'agregar',
                'contenido' => '- En los presupuestos, «una caja» es una unidad del artículo, no las botellas que trae (12/11/2026).',
                'motivo'    => 'Me corrigió cómo cargar las cajas',
            ]),
            $this->end_turn('Sí, quedó claro: una caja pedida es una unidad del artículo. Me lo anoto.'),
        ]);

        list($conversation, $assistant) = $this->conversacion_con_pendiente('whatsapp', 'Cuando te pido una caja, es una unidad de ese artículo', null, true);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Sí, quedó claro: una caja pedida es una unidad del artículo. Me lo anoto.', $texto);
        $this->assertCount(3, Http::recorded());
        $this->assertStringContainsString('NO llamaste a guardar_en_contexto', $this->nota_del_reintento());
        $this->assertStringContainsString('una caja', AgenteContextoHelper::leer($this->dueno, 'aprendizajes/correcciones.md')['contenido']);
    }

    /**
     * Si el reintento tampoco guarda, «Me lo anoto: X» deja X y el aviso de que no quedó guardado.
     *
     * @test
     */
    public function si_sigue_sin_guardar_se_saca_la_oracion_y_se_avisa()
    {
        $this->el_modelo_contesta([
            $this->end_turn('Me lo anoto: la cepa va solo en los vinos, y en las demás bebidas el campo queda vacío.'),
            $this->end_turn('Me lo anoto: la cepa va solo en los vinos, y en las demás bebidas el campo queda vacío.'),
        ]);

        list($conversation, $assistant) = $this->conversacion_con_pendiente('whatsapp', 'Hay bebidas que no son vinos y no llevan cepa', null, true);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame("La cepa va solo en los vinos, y en las demás bebidas el campo queda vacío.\n\n" . AnotacionPrometidaIaHelper::AVISO, $texto);
        $this->assertCount(2, Http::recorded());
    }

    /**
     * Sin la memoria (sin Agentes de IA) no hay dónde anotar: la nota le dice que no lo diga.
     *
     * @test
     */
    public function sin_memoria_la_nota_dice_que_no_lo_diga()
    {
        $sin = $this->nuevo_dueno('Sin agentes P5M', false);

        $this->el_modelo_contesta([
            $this->end_turn('Anoto la corrección: responder más rápido, sin vueltas.'),
            $this->end_turn('Entendido: respondo más rápido, sin vueltas.'),
        ]);

        list($conversation, $assistant) = $this->conversacion_con_pendiente('sistema', 'Sé más rápido cuando te doy una orden', $sin, true);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Entendido: respondo más rápido, sin vueltas.', $texto);
        $this->assertStringContainsString('no tenés memoria entre conversaciones', $this->nota_del_reintento());
    }

    /**
     * Con la herramienta llamada y ok, ningún reintento (lo cubre también 2_Herramientas_y_prompt_Test).
     *
     * @test
     */
    public function con_la_herramienta_ok_no_hay_reintento()
    {
        $this->el_modelo_contesta([
            $this->tool_use('guardar_en_contexto', [
                'ruta'      => 'aprendizajes/correcciones.md',
                'operacion' => 'agregar',
                'contenido' => '- Tiene una sola sucursal y un solo depósito: el stock va ahí, sin preguntar (12/11/2026).',
                'motivo'    => 'Me corrigió el depósito',
            ]),
            $this->end_turn('Entendido: una sola sucursal y un solo depósito. Me lo anoto.'),
        ]);

        list($conversation, $assistant) = $this->conversacion_con_pendiente('whatsapp', 'Tenemos una sola sucursal y un solo depósito', null, true);

        $this->assertSame('Entendido: una sola sucursal y un solo depósito. Me lo anoto.', (new AsistenteIaService())->responder($conversation, $assistant));
        $this->assertCount(2, Http::recorded());
    }

    /**
     * Lo aprendido viaja en el bloque de la memoria del prompt: las correcciones y cómo quiere que le
     * respondan, si tienen algo. Vacíos, no van.
     *
     * @test
     */
    public function lo_aprendido_va_en_el_prompt_y_vacio_no()
    {
        $service = new AsistenteIaService();

        list($conversation) = $this->conversacion_con_pendiente('whatsapp');

        $antes = $this->texto_de_la_memoria($service->build_system_payload($conversation, $this->dueno, true, true));

        $this->assertStringNotContainsString('=== aprendizajes/correcciones.md', $antes, 'Con la plantilla vacía no va.');
        $this->assertStringNotContainsString('=== dueno/como_responder.md', $antes);

        AgenteContextoHelper::guardar($this->dueno, 'aprendizajes/correcciones.md', 'agregar', '- Una caja es una unidad del artículo.', null, 'm', 'agente');
        AgenteContextoHelper::guardar($this->dueno, 'dueno/como_responder.md', 'agregar', '- Quiere respuestas rápidas, sin preguntar tantas veces.', null, 'm', 'agente');

        $despues = $this->texto_de_la_memoria($service->build_system_payload($conversation, $this->dueno, true, true));

        $this->assertStringContainsString('=== aprendizajes/correcciones.md', $despues);
        $this->assertStringContainsString('Una caja es una unidad del artículo.', $despues);
        $this->assertStringContainsString('=== dueno/como_responder.md', $despues);
        $this->assertStringContainsString('sin preguntar tantas veces', $despues);

        /* La regla, en el bloque cacheado. */
        $this->assertStringContainsString('Nunca digas "me lo anoto"', $service->build_system_payload($conversation, $this->dueno, true, true)[0]['text']);
    }

    /**
     * Con correcciones largas va lo más NUEVO (se agrega al final), con la marca de que lo viejo está en
     * leer_contexto, y nunca más de TOPE_DE_LO_APRENDIDO entre los dos.
     *
     * @test
     */
    public function con_correcciones_largas_va_lo_mas_nuevo_hasta_el_tope()
    {
        AgenteContextoHelper::guardar($this->dueno, 'aprendizajes/correcciones.md', 'agregar', '- LA PRIMERA corrección, la más vieja.', null, 'm', 'agente');

        for ($i = 1; $i <= 60; $i++) {
            AgenteContextoHelper::guardar($this->dueno, 'aprendizajes/correcciones.md', 'agregar', '- Corrección número ' . $i . ': un texto de relleno para pasar el tope de lo aprendido.', null, 'm', 'agente');
        }

        AgenteContextoHelper::guardar($this->dueno, 'aprendizajes/correcciones.md', 'agregar', '- LA ÚLTIMA corrección, la más nueva.', null, 'm', 'agente');

        list($conversation) = $this->conversacion_con_pendiente('whatsapp');

        $memoria = $this->texto_de_la_memoria((new AsistenteIaService())->build_system_payload($conversation, $this->dueno, true, true));

        $desde = mb_strpos($memoria, '=== aprendizajes/correcciones.md');

        $this->assertNotFalse($desde);

        $bloque = mb_substr($memoria, $desde);

        $this->assertStringContainsString('LA ÚLTIMA corrección, la más nueva.', $bloque);
        $this->assertStringNotContainsString('LA PRIMERA corrección', $bloque);
        $this->assertStringContainsString('lo más viejo de este archivo no entra acá', $bloque);
        $this->assertLessThanOrEqual(AgenteContextoHelper::TOPE_DE_LO_APRENDIDO + 200, mb_strlen($bloque), 'El encabezado y la etiqueta de cierre, nada más de lo que entra en el tope.');
    }

    /**
     * D4 de la revisión independiente: «anoto» también es cargar. Las cargas y las cuentas no son una
     * anotación de la memoria; las de Truvari siguen siéndolo.
     *
     * @test
     */
    public function el_detector_no_toma_una_carga_por_una_anotacion()
    {
        $no_anotan = [
            "Te anoto el gasto de $5000 en Varios.\n¿Confirmás?",
            'Listo, queda anotado el pago de $10.000.',
            'Lo tengo en cuenta para el total: $45.000.',
            'Anoto la venta: 2 cajas de Malbec a $24.000. ¿Lo registro?',
            'Anoto la tarea para mañana a las 9.',
            'Si querés, me lo anoto.',
            '¿Querés que me lo anote?',
        ];

        foreach ($no_anotan as $texto) {
            $this->assertFalse(AnotacionPrometidaIaHelper::dice_que_lo_anota($texto), 'No es una anotación de la memoria: ' . $texto);
        }

        $anotan = [
            'Me lo anoto: la cepa va solo en los vinos.',
            'Anoto la corrección: responder más rápido, sin vueltas.',
            'Me lo anoto para no volver a preguntarlo en cada presupuesto.',
            "Entendido: una sola sucursal y un solo depósito real.\nMe lo anoto, y ahora cargo las 6 unidades del aceite Coratina. ¿Confirmás?",
            'Me lo anoto: en Truvari los vinos se venden por caja cerrada y en el presupuesto una caja es una unidad.',
            'Lo tengo en cuenta para la próxima.',
        ];

        foreach ($anotan as $texto) {
            $this->assertTrue(AnotacionPrometidaIaHelper::dice_que_lo_anota($texto), 'Es una anotación de la memoria: ' . $texto);
        }
    }

    /**
     * D4: con una carga armada en el turno, «Me lo anoto.» no reintenta (la guarda no mira ese turno).
     *
     * @test
     */
    public function con_una_carga_armada_en_el_turno_no_hay_reintento()
    {
        $this->el_modelo_contesta([
            $this->tool_use('proponer_tarea', ['detalle' => 'ZZP5M revisar los pedidos', 'fecha' => now()->addDay()->format('Y-m-d')]),
            $this->end_turn('Me lo anoto.'),
        ]);

        list($conversation, $assistant) = $this->conversacion_con_pendiente('whatsapp', 'Agendame revisar los pedidos mañana', null, true);

        $this->assertSame('Me lo anoto.', (new AsistenteIaService())->responder($conversation, $assistant));
        $this->assertCount(2, Http::recorded());
    }

    /**
     * 🔴 D6 de la revisión independiente: sin lugar para nada más que el encabezado y la marca, el archivo
     * no va. Antes mb_substr($texto, -0) devolvía el archivo entero (15.396 caracteres con tope 250).
     *
     * @test
     */
    public function sin_lugar_el_archivo_no_va_y_nunca_pasa_el_tope()
    {
        $lo_ultimo = new \ReflectionMethod(AgenteContextoHelper::class, 'lo_ultimo');
        $lo_ultimo->setAccessible(true);

        $cabecera = "# Correcciones que te hizo el dueño\n\n> Para qué: lo que el dueño te corrigió para no repetirlo. Se agrega al final con la fecha, una corrección por renglón, y cada una dice qué estaba mal y qué es lo correcto.\n";
        $cuerpo = '';

        for ($i = 0; $i < 200; $i++) {
            $cuerpo .= '- 12/11: corrección número ' . $i . ", una caja es una unidad y no seis botellas.\n";
        }

        foreach ([3000, 400] as $tope) {
            $this->assertLessThanOrEqual($tope, mb_strlen($lo_ultimo->invoke(null, $cabecera . $cuerpo, $tope)), 'Tope ' . $tope);
        }

        foreach ([250, 201] as $tope) {
            $this->assertSame('', $lo_ultimo->invoke(null, $cabecera . $cuerpo, $tope), 'Tope ' . $tope . ': sin lugar, nada.');
        }

        /* Un solo renglón larguísimo: su final, dentro del tope. */
        $this->assertLessThanOrEqual(400, mb_strlen($lo_ultimo->invoke(null, str_repeat('x', 5000), 400)));
    }

    /**
     * El texto del bloque de la memoria (el que va después del cacheado).
     *
     * @param  array  $system
     * @return string
     */
    protected function texto_de_la_memoria(array $system)
    {
        foreach ($system as $bloque) {
            if (strpos($bloque['text'], '<' . AgenteContextoHelper::ETIQUETA_DEL_PROMPT . '>') === 0) {
                return $bloque['text'];
            }
        }

        return '';
    }
}
