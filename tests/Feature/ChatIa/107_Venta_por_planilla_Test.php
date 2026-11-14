<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\AsistenteImagenHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\LecturaDePlanillaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PlanillaDeTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PlanillaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaVentaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RolesDePlanillaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\AiPlanilla;
use App\Models\AiTokenUsage;
use App\Models\Article;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

/**
 * Misión agente-venta-por-planilla (02/11/2026): un dueño manda una planilla, en foto o pegada como texto, y
 * el agente carga EXACTAMENTE esas ventas. Se lee una vez con Claude, se guarda en `ai_planillas`, y desde
 * ahí todo sale de la tabla por código; el modelo que conversa no ve la foto ni reescribe la tabla.
 *
 * 🔴 EL CASO QUE SOSTIENE ESTE ARCHIVO: una charla real del 31/10/2026 (ver el plan de la misión). Con los datos de esa charla
 * NO se escribe nada acá: todos los nombres, documentos y operaciones son inventados, y las imágenes se generan
 * en el momento (un lienzo blanco: lo que «dice» la foto lo contesta el proveedor falso).
 *
 * Corre sobre el fixture de plata (TestingFerreteriaSeeder), con los artículos propios del archivo (prefijo
 * `zz-c107`) y el dueño en "cauteloso". Nada sale a la red: Http::fake enruta por modelo (ver falsear_ia()).
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 *
 * @group chat-ia
 */
class Venta_por_planilla_Test extends PlanillaTestCase
{
    // ---------------------------------------------------------------------
    // La foto: se lee una vez, con Claude, y no le llega al modelo que conversa
    // ---------------------------------------------------------------------

    /**
     * 🔴 El corazón de la misión: el modelo que conversa no recibe la foto, y la lectura es la decidida.
     *
     * @test
     */
    public function una_planilla_en_foto_se_lee_con_claude_y_no_le_llega_al_modelo_que_conversa()
    {
        $this->todos_leen($this->filas_de_prueba());
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, 'CARGAME ESTAS VENTAS');
        $foto = $this->foto($pedido);
        $assistant = $this->assistant($conversation);

        $this->vueltas = [$this->texto_del_modelo('Leí tu planilla.')];

        $this->responder($conversation, $assistant);

        $planilla = AiPlanilla::where('ai_conversation_id', $conversation->id)->first();

        $this->assertNotNull($planilla);
        $this->assertSame(AiPlanilla::ORIGEN_FOTO, $planilla->origen);
        $this->assertCount(4, $planilla->filas);
        $this->assertSame('61.250,00', $planilla->filas[0]['valores'][RolesDePlanillaIaHelper::IMPORTE]);
        $this->assertSame('SKU 70101', $planilla->filas[0]['valores'][RolesDePlanillaIaHelper::CODIGO]);
        $this->assertSame('#2000000000000001', $planilla->filas[0]['valores'][RolesDePlanillaIaHelper::OPERACION]);
        $this->assertSame('17000001', $planilla->filas[0]['valores'][RolesDePlanillaIaHelper::DOCUMENTO]);

        // Queda sellada: no vuelve a viajar ni puede terminar en una compra con factura.
        $this->assertNotNull($foto->fresh()->gestionada_at);

        // Y lo que ve el modelo que conversa es texto.
        $conversa = $this->requests_del_modelo_que_conversa();
        $this->assertCount(1, $conversa);

        $json = json_encode($conversa[0]['messages'], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('"type":"image"', $json);
        $this->assertStringContainsString('[Foto: planilla N°' . $planilla->id . ' ya leída, 4 renglones; usá consultar_planilla, también si la persona solo quiere mirarla y no cargar ventas]', $json);
        $this->assertStringNotContainsString('17000001', $json);
    }

    /**
     * Lo decidido sobre la API: los ids exactos, sin temperature ni thinking, y el fallback en Sonnet y Opus, nunca en Haiku.
     *
     * @test
     */
    public function las_lecturas_llaman_a_la_api_como_se_decidio()
    {
        $this->todos_leen($this->filas_de_prueba());
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '');
        $this->foto($pedido);
        $assistant = $this->assistant($conversation);

        $this->responder($conversation, $assistant);

        $sonnet = $this->lecturas_de('claude-sonnet-5-5');
        $haiku = $this->lecturas_de('claude-haiku-5-5');

        $this->assertCount(1, $sonnet);
        $this->assertCount(1, $haiku);
        $this->assertCount(0, $this->lecturas_de('claude-opus-5-5'), 'Sin desacuerdo no hay desempate.');

        $cuerpo_sonnet = json_decode($sonnet[0][0]->body(), true);
        $cuerpo_haiku = json_decode($haiku[0][0]->body(), true);

        $this->assertSame('default', $cuerpo_sonnet['fallbacks']);
        $this->assertStringContainsString('server-side-fallback-2026-07-01', implode(',', $sonnet[0][0]->header('anthropic-beta')));

        $this->assertArrayNotHasKey('fallbacks', $cuerpo_haiku);
        $this->assertStringNotContainsString('server-side-fallback', implode(',', $haiku[0][0]->header('anthropic-beta')));

        foreach ([$cuerpo_sonnet, $cuerpo_haiku] as $cuerpo) {
            $this->assertArrayNotHasKey('temperature', $cuerpo);
            $this->assertArrayNotHasKey('thinking', $cuerpo);
        }

        // Tres procesos propios en el gasto: clasificador y dos lecturas.
        $this->assertSame(3, AiTokenUsage::where('ai_conversation_id', $conversation->id)->where('proceso', LecturaDePlanillaIaHelper::PROCESO)->count());
    }

    /**
     * La imagen se agranda ×3 y se corta en tramos de hasta 1560 px con solape.
     *
     * @test
     */
    public function la_imagen_se_agranda_por_tres_y_se_corta_en_tramos()
    {
        $this->todos_leen($this->filas_de_prueba());
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '');
        $this->foto($pedido, 1500, 200);
        $assistant = $this->assistant($conversation);

        $this->responder($conversation, $assistant);

        $cuerpo = json_decode($this->lecturas_de('claude-sonnet-5-5')[0][0]->body(), true);

        $tramos = [];

        foreach ($cuerpo['messages'][0]['content'] as $bloque) {

            if ($bloque['type'] === 'image') {
                $tramos[] = getimagesizefromstring(base64_decode($bloque['source']['data']));
            }
        }

        // 1500 × 3 = 4500 de ancho, en tramos de 1560 con 180 de solape: 0-1560, 1380-2940, 2760-4320, 4140-4500.
        $this->assertCount(4, $tramos);

        foreach ($tramos as $tramo) {
            $this->assertLessThanOrEqual(1560, $tramo[0]);
            $this->assertSame(600, $tramo[1]);
        }
    }

    /**
     * Si la foto llegó más grande que el webp, se lee del original: el que se perdía al achicar.
     *
     * @test
     */
    public function una_foto_grande_guarda_su_original_y_se_lee_de_ahi()
    {
        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '');

        $grande = (string) (new ImageManager())->canvas(2000, 100, '#ffffff')->encode('png');

        $ruta = tempnam(sys_get_temp_dir(), 'c107');
        file_put_contents($ruta, $grande);

        AsistenteImagenHelper::guardar($pedido, [new UploadedFile($ruta, 'planilla.png', 'image/png', null, true)], $this->dueno->id);

        $imagen = AiMessageImagen::where('ai_message_id', $pedido->id)->first();

        $this->assertNotNull($imagen);
        $this->assertSame(1568, getimagesizefromstring(AsistenteImagenHelper::binario($imagen))[0], 'El webp de siempre no cambia.');
        $this->assertSame(2000, getimagesizefromstring(AsistenteImagenHelper::binario_para_leer($imagen))[0]);

        // Y una foto chica no guarda nada de más.
        $chica = (string) (new ImageManager())->canvas(800, 100, '#ffffff')->encode('png');
        file_put_contents($ruta, $chica);

        $otro = $this->del_dueno($conversation, '');
        AsistenteImagenHelper::guardar($otro, [new UploadedFile($ruta, 'chica.png', 'image/png', null, true)], $this->dueno->id);

        $imagen_chica = AiMessageImagen::where('ai_message_id', $otro->id)->first();

        $this->assertFalse(Storage::disk('local')->exists($imagen_chica->path . AsistenteImagenHelper::SUFIJO_DEL_ORIGINAL));

        @unlink($ruta);
    }

    /**
     * La foto leída tampoco vuelve en los turnos siguientes, y no se vuelve a leer.
     *
     * @test
     */
    public function la_foto_leida_no_vuelve_a_viajar_ni_se_vuelve_a_leer()
    {
        $this->todos_leen($this->filas_de_prueba());
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '');
        $this->foto($pedido);
        $primero = $this->assistant($conversation);

        $this->responder($conversation, $primero);

        $this->del_dueno($conversation, 'Bárbaro, gracias');
        $segundo = $this->assistant($conversation);

        $this->responder($conversation, $segundo);

        $this->assertCount(1, $this->lecturas_de('claude-sonnet-5-5'), 'Hasta acá se leyó una sola vez, en el primer turno.');

        $conversa = $this->requests_del_modelo_que_conversa();

        $this->assertStringNotContainsString('"type":"image"', json_encode($conversa[count($conversa) - 1]['messages']));
    }

    /**
     * Una foto de producto, o el recorte de una sola celda, NO es planilla: Haiku la transcribe y DeepSeek recibe texto.
     *
     * @test
     */
    public function una_foto_que_no_es_planilla_llega_transcripta_a_deepseek()
    {
        $this->clasificador = false;
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, 'Mirá este producto');
        $foto = $this->foto($pedido, 300, 300);
        $assistant = $this->assistant($conversation);

        $this->responder($conversation, $assistant);

        $this->assertSame(0, AiPlanilla::where('ai_conversation_id', $conversation->id)->count());
        $this->assertNull($foto->fresh()->gestionada_at);
        $this->assertCount(0, $this->lecturas_de('claude-sonnet-5-5'));

        $conversa = $this->requests_del_modelo_que_conversa();

        $this->assertStringContainsString('Lectura transitoria con Haiku', json_encode($conversa[0]['messages']));
        $this->assert_deepseek_conversa_sin_imagenes();
    }

    // ---------------------------------------------------------------------
    // Doble lectura: acuerdo, desempate, duda y cantidad de filas
    // ---------------------------------------------------------------------

    /**
     * Si las dos primeras no coinciden, lee Sonnet y gana la mayoría.
     *
     * @test
     */
    public function un_desacuerdo_lo_desempata_la_tercera_lectura()
    {
        $bien = $this->filas_de_prueba();

        $mal = $bien;
        $mal[1][1] = '88.000,00';

        $this->lecturas = [
            'claude-sonnet-5-5'   => $this->lectura($bien),
            'claude-haiku-5-5'  => $this->lectura($mal),
            'claude-opus-5-5' => $this->lectura($bien),
        ];

        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '');
        $this->foto($pedido);

        $this->responder($conversation, $this->assistant($conversation));

        $this->assertCount(1, $this->lecturas_de('claude-opus-5-5'));

        $planilla = AiPlanilla::where('ai_conversation_id', $conversation->id)->first();

        $this->assertSame('88.400,00', $planilla->filas[1]['valores'][RolesDePlanillaIaHelper::IMPORTE]);
        $this->assertCount(0, $planilla->filas[1]['dudas']);
        $this->assertCount(1, $planilla->lectura['fuentes'][0]['desacuerdos']);
    }

    /**
     * Sin mayoría, la celda queda como duda y se le pregunta ESA celda al dueño, sin elegir por él.
     *
     * @test
     */
    public function sin_mayoria_la_celda_queda_como_duda_y_se_pregunta()
    {
        $a = $this->filas_de_prueba();
        $b = $a;
        $c = $a;

        $b[1][1] = '88.000,00';
        $c[1][1] = '86.900,00';

        $this->lecturas = [
            'claude-sonnet-5-5'   => $this->lectura($a),
            'claude-haiku-5-5'  => $this->lectura($b),
            'claude-opus-5-5' => $this->lectura($c),
        ];

        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '');
        $this->foto($pedido);

        $assistant = $this->assistant($conversation);

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $assistant);

        $planilla = AiPlanilla::where('ai_conversation_id', $conversation->id)->first();

        $this->assertArrayNotHasKey(RolesDePlanillaIaHelper::IMPORTE, $planilla->filas[1]['valores']);
        $this->assertSame(RolesDePlanillaIaHelper::IMPORTE, $planilla->filas[1]['dudas'][0]['rol']);

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR);
        $assistant = $this->assistant($conversation);

        $this->definir_para_cargar($conversation, $assistant);

        $respuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->assertFalse($respuesta['ok']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        $faltan = implode(' | ', $respuesta['faltan']);

        $this->assertStringContainsString('Renglón 2', $faltan);
        $this->assertStringContainsString('88.400,00', $faltan);
        $this->assertStringContainsString('88.000,00', $faltan);
        $this->assertStringContainsString('86.900,00', $faltan);
    }

    /**
     * Si la cantidad de filas no coincide ni con la tercera, no hay planilla: se le pide el texto.
     *
     * @test
     */
    public function si_no_coincide_la_cantidad_de_filas_no_hay_planilla()
    {
        $filas = $this->filas_de_prueba();

        $this->lecturas = [
            'claude-sonnet-5-5'   => $this->lectura($filas),
            'claude-haiku-5-5'  => $this->lectura(array_slice($filas, 0, 3)),
            'claude-opus-5-5' => $this->lectura(array_slice($filas, 0, 2)),
        ];

        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '');
        $foto = $this->foto($pedido);
        $assistant = $this->assistant($conversation);

        $this->responder($conversation, $assistant);

        $this->assertSame(0, AiPlanilla::where('ai_conversation_id', $conversation->id)->count());

        // La foto no le llega al modelo que conversa, y la nota le dice qué pedir.
        $this->assertNotNull($foto->fresh()->gestionada_at);

        $json = json_encode($this->requests_del_modelo_que_conversa()[0]['messages'], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('"type":"image"', $json);
        $this->assertStringContainsString('no se pudo leer con seguridad', $json);
        $this->assertStringContainsString('pegue como texto', $json);
    }

    /**
     * Si dos de tres coinciden en la cantidad de filas, valen esas dos.
     *
     * @test
     */
    public function si_dos_lecturas_coinciden_en_las_filas_valen_esas()
    {
        $filas = $this->filas_de_prueba();

        $this->lecturas = [
            'claude-sonnet-5-5'   => $this->lectura($filas),
            'claude-haiku-5-5'  => $this->lectura(array_slice($filas, 0, 3)),
            'claude-opus-5-5' => $this->lectura($filas),
        ];

        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '');
        $this->foto($pedido);

        $this->responder($conversation, $this->assistant($conversation));

        $this->assertCount(4, AiPlanilla::where('ai_conversation_id', $conversation->id)->first()->filas);
    }

    /**
     * Una lectura que se niega (`refusal`) o que se corta es una lectura fallida, y nunca cae a otro modelo.
     *
     * @test
     */
    public function una_lectura_fallida_pide_el_texto_y_no_cae_a_otro_modelo()
    {
        $this->dueno->agente_proveedor = 'deepseek';
        $this->dueno->save();

        $this->lecturas = [
            'claude-sonnet-5-5' => $this->texto_del_modelo('', 'refusal'),
        ];

        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '');
        $this->foto($pedido);
        $assistant = $this->assistant($conversation);

        $this->responder($conversation, $assistant);

        $this->assertSame(0, AiPlanilla::where('ai_conversation_id', $conversation->id)->count());

        $conversa = $this->requests_del_modelo_que_conversa();

        $json = json_encode($conversa[0]['messages'], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('"type":"image"', $json, 'Flash no ve la foto de la planilla.');
        $this->assertStringContainsString('pegue como texto', $json);
    }

    /**
     * 🔴 Sin clave de Anthropic no se lee ni se le manda la foto a DeepSeek: falla antes de inventar una lectura.
     *
     * @test
     */
    public function sin_clave_de_anthropic_no_se_lee_ni_se_delega_la_foto_a_deepseek()
    {
        config(['services.anthropic.api_key' => null]);

        $this->dueno->agente_proveedor = 'deepseek';
        $this->dueno->save();

        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '');
        $this->foto($pedido, 300, 300);
        $assistant = $this->assistant($conversation);

        try {
            $this->responder($conversation, $assistant);
            $this->fail('Una foto sin lector habilitado tiene que fallar antes de conversar.');
        } catch (\App\Exceptions\AsistenteIaException $e) {
            $this->assertSame(\App\Exceptions\AsistenteIaException::MOTIVO_FALLA_TECNICA, $e->motivo());
        }

        $this->assertSame(0, AiPlanilla::where('ai_conversation_id', $conversation->id)->count());

        foreach (Http::recorded() as $par) {
            $this->assertStringNotContainsString('api.anthropic.com', (string) $par[0]->url(), 'No se llama a Anthropic sin clave.');
        }

        $this->assertSame([], $this->requests_del_modelo_que_conversa(), 'DeepSeek no recibe una imagen ni inventa lo que contiene.');
    }

    // ---------------------------------------------------------------------
    // El texto pegado: sin ningún modelo
    // ---------------------------------------------------------------------

    /**
     * 🔴 El mensaje 243 del caso real: texto con tabuladores, parser determinista, ninguna llamada de lectura, y el modelo
     * que conversa no vuelve a ver esas líneas.
     *
     * @test
     */
    public function una_planilla_pegada_como_texto_se_lee_sin_ningun_modelo()
    {
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $this->del_dueno($conversation, "Cargame estas ventas\n" . $this->texto_pegado());
        $assistant = $this->assistant($conversation);

        $this->responder($conversation, $assistant);

        $planilla = AiPlanilla::where('ai_conversation_id', $conversation->id)->first();

        $this->assertNotNull($planilla);
        $this->assertSame(AiPlanilla::ORIGEN_TEXTO, $planilla->origen);
        $this->assertCount(4, $planilla->filas);

        $this->assertCount(0, $this->lecturas_de('claude-sonnet-5-5'));
        $this->assertCount(1, $this->requests_del_modelo_que_conversa());

        $json = json_encode($this->requests_del_modelo_que_conversa()[0]['messages'], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Cargame estas ventas', $json);
        $this->assertStringContainsString('[Tabla pegada: planilla N°' . $planilla->id . ' ya leída, 4 renglones', $json);
        $this->assertStringNotContainsString('17000001', $json, 'El documento no viaja.');
        $this->assertStringNotContainsString('Ana Prueba Uno', $json, 'El nombre no viaja.');
    }

    /**
     * Un mensaje común, con una lista que no es de ventas, no es una planilla.
     *
     * @test
     */
    public function un_texto_que_no_es_de_ventas_no_es_una_planilla()
    {
        $this->assertNull(PlanillaDeTextoIaHelper::tabla_del_texto("Hola, ¿cómo va?\nTe paso los datos: martes; jueves; sábado"));

        // Una lista de precios pegada: tiene código y precio, pero no a quién se vendió.
        $this->assertNull(PlanillaDeTextoIaHelper::tabla_del_texto("A100\tMartillo\t1.500,00\nA101\tPinza\t2.500,00"));

        // Ni con una columna de marca, que parece un nombre.
        $this->assertNull(PlanillaDeTextoIaHelper::tabla_del_texto("A100\tMartillo\tStanley\t1.500,00\nA101\tPinza\tBahco\t2.500,00"));

        // Un título que dice de quién es, sí.
        $this->assertNotNull(PlanillaDeTextoIaHelper::tabla_del_texto("CODIGO\tDESCRIPCION\tCLIENTE\tTOTAL\nA100\tMartillo\tAna Prueba\t1.500,00"));

        $this->assertNotNull(PlanillaDeTextoIaHelper::tabla_del_texto($this->texto_pegado()));
    }

    // ---------------------------------------------------------------------
    // Roles de columna
    // ---------------------------------------------------------------------

    /**
     * 🔴 Sin encabezado, el N° de operación NO es un documento: es lo que confundió a Flash en el caso real.
     *
     * @test
     */
    public function sin_encabezado_el_numero_de_operacion_no_es_el_documento()
    {
        $roles = RolesDePlanillaIaHelper::roles_de(null, $this->filas_de_prueba());

        $this->assertSame([
            0  => RolesDePlanillaIaHelper::COMPROBANTE,
            1  => RolesDePlanillaIaHelper::IMPORTE,
            2  => RolesDePlanillaIaHelper::FECHA,
            3  => RolesDePlanillaIaHelper::CODIGO,
            4  => RolesDePlanillaIaHelper::DESCRIPCION,
            5  => RolesDePlanillaIaHelper::OPERACION,
            6  => RolesDePlanillaIaHelper::CANTIDAD,
            7  => RolesDePlanillaIaHelper::TIPO_DOCUMENTO,
            8  => RolesDePlanillaIaHelper::DOCUMENTO,
            9  => RolesDePlanillaIaHelper::NOMBRE,
            10 => RolesDePlanillaIaHelper::DIRECCION,
        ], $roles);
    }

    /**
     * Con encabezado, los títulos cortados y repetidos del Excel real se entienden, y «total» dice qué es el importe.
     *
     * @test
     */
    public function con_encabezado_se_entienden_los_titulos_del_excel_y_el_importe_total()
    {
        $encabezado = ['VENTA TOTAL', '', 'CODIGO', 'DESCRIPCION', 'CANTIDA', 'DNI O CUIT', 'O DNI O CUIT', 'NOMBRE', 'DIRECCION'];

        $filas = [
            ['88.400,00', '13-oct', '70102', 'Bomba Beta', '2', 'DNI', '14000002', 'Bruno Prueba Dos', 'Calle Falsa, 200'],
            ['204.000,00', '19-oct', 'ZRRI-50LF', 'Foco', '2', 'CUIT', self::CUIT_EMPRESA, 'Empresa Prueba SA', ''],
        ];

        $roles = RolesDePlanillaIaHelper::roles_de($encabezado, $filas);

        $this->assertSame(RolesDePlanillaIaHelper::IMPORTE, $roles[0]);
        $this->assertSame(RolesDePlanillaIaHelper::FECHA, $roles[1]);
        $this->assertSame(RolesDePlanillaIaHelper::CODIGO, $roles[2]);
        $this->assertSame(RolesDePlanillaIaHelper::DESCRIPCION, $roles[3]);
        $this->assertSame(RolesDePlanillaIaHelper::CANTIDAD, $roles[4]);
        $this->assertSame(RolesDePlanillaIaHelper::TIPO_DOCUMENTO, $roles[5]);
        $this->assertSame(RolesDePlanillaIaHelper::DOCUMENTO, $roles[6]);
        $this->assertSame(RolesDePlanillaIaHelper::NOMBRE, $roles[7]);
        $this->assertSame(RolesDePlanillaIaHelper::DIRECCION, $roles[8]);

        $this->assertSame(AiPlanilla::IMPORTE_TOTAL, RolesDePlanillaIaHelper::importe_es_de($encabezado, $roles));
    }

    /**
     * Los números escritos a la argentina, y cuándo dos celdas son la misma.
     *
     * @test
     */
    public function los_numeros_y_las_celdas_se_comparan_normalizados()
    {
        $this->assertSame(62300.0, RolesDePlanillaIaHelper::a_numero('62.300,00'));
        $this->assertSame(62300.0, RolesDePlanillaIaHelper::a_numero('62.300'));
        $this->assertSame(62300.5, RolesDePlanillaIaHelper::a_numero('62300.50'));
        $this->assertSame(1.5, RolesDePlanillaIaHelper::a_numero('1,5'));
        $this->assertSame(1000.0, RolesDePlanillaIaHelper::a_numero('$ 1.000'));
        $this->assertNull(RolesDePlanillaIaHelper::a_numero('13-oct'));

        $this->assertSame(RolesDePlanillaIaHelper::normalizar_celda('61.250,00'), RolesDePlanillaIaHelper::normalizar_celda('61250'));
        $this->assertSame(RolesDePlanillaIaHelper::normalizar_celda('SKU 70101'), RolesDePlanillaIaHelper::normalizar_celda('70101'));
        $this->assertSame(RolesDePlanillaIaHelper::normalizar_celda('#2000000000000001'), RolesDePlanillaIaHelper::normalizar_celda('2000000000000001'));
        $this->assertSame(RolesDePlanillaIaHelper::normalizar_celda('Empresa S.A.'), RolesDePlanillaIaHelper::normalizar_celda('EMPRESA SA'));
        $this->assertNotSame(RolesDePlanillaIaHelper::normalizar_celda('88.400,00'), RolesDePlanillaIaHelper::normalizar_celda('88.000,00'));
    }

    // ---------------------------------------------------------------------
    // El artículo, por código exacto
    // ---------------------------------------------------------------------

    /**
     * Por las tres vías: código de barras, código de proveedor y nombre exacto. Sin mayúsculas ni el prefijo SKU, y con
     * ceros a la izquierda.
     *
     * @test
     */
    public function el_articulo_se_encuentra_por_las_tres_vias_sin_mayusculas_ni_sku_y_con_ceros()
    {
        $conversation = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $por_barras = $this->articulo_de_prueba('zz-c107 Por barras', ['bar_code' => '7770107001']);
        $por_proveedor = $this->articulo_de_prueba('zz-c107 Por proveedor', ['provider_code' => 'AbC-107']);
        $por_nombre = $this->articulo_de_prueba('zz-c107 Por nombre exacto');
        $con_ceros = $this->articulo_de_prueba('zz-c107 Con ceros', ['provider_code' => '0029810']);

        $this->assertSame([$por_barras->id], $this->ids(PlanillaIaHelper::articulos_por_codigo($contexto, '7770107001')));
        $this->assertSame([$por_proveedor->id], $this->ids(PlanillaIaHelper::articulos_por_codigo($contexto, 'abc-107')));
        $this->assertSame([$por_proveedor->id], $this->ids(PlanillaIaHelper::articulos_por_codigo($contexto, 'SKU ABC-107')));
        $this->assertSame([$por_nombre->id], $this->ids(PlanillaIaHelper::articulos_por_codigo($contexto, 'zz-c107 Por nombre exacto')));

        $this->assertSame([$con_ceros->id], $this->ids(PlanillaIaHelper::articulos_por_codigo($contexto, '0029810')), 'Exacto, con sus ceros.');
        $this->assertSame([$con_ceros->id], $this->ids(PlanillaIaHelper::articulos_por_codigo($contexto, '29810')), 'Sin los ceros de la izquierda.');
        $this->assertSame([$con_ceros->id], $this->ids(PlanillaIaHelper::articulos_por_codigo($contexto, 'SKU 029810')), 'Con otros ceros y el prefijo.');

        // Nunca por parecido.
        $this->assertCount(0, PlanillaIaHelper::articulos_por_codigo($contexto, 'ABC-10'));
        $this->assertCount(0, PlanillaIaHelper::articulos_por_codigo($contexto, 'Por nombre'));
    }

    /**
     * @param  array  $modelos
     * @return array<int, int>
     */
    protected function ids(array $modelos)
    {
        return array_map(function ($modelo) {
            return (int) $modelo->id;
        }, $modelos);
    }

    /**
     * Un código que no existe o que tienen dos artículos se pregunta en ese renglón, sin elegir.
     *
     * @test
     */
    public function un_codigo_inexistente_o_ambiguo_se_pregunta_en_ese_renglon()
    {
        $this->articulo_de_prueba('zz-c107 Doble A', ['provider_code' => 'REPE-107']);
        $this->articulo_de_prueba('zz-c107 Doble B', ['provider_code' => 'REPE-107']);

        $filas = $this->filas_de_prueba();
        $filas[0][3] = 'NO-EXISTE-107';
        $filas[1][3] = 'REPE-107';

        $lineas = array_map(function ($fila) {
            return implode("\t", $fila);
        }, $filas);

        $conversation = $this->conversacion();
        $this->planilla_pegada($conversation, implode("\n", $lineas));

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR);
        $assistant = $this->assistant($conversation);

        $this->definir_para_cargar($conversation, $assistant);

        $respuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->assertFalse($respuesta['ok']);

        $faltan = implode(' | ', $respuesta['faltan']);

        $this->assertStringContainsString('Renglón 1 (Ana Prueba Uno, Bomba Presurizadora Alfa): no hay ningún artículo con el código «NO-EXISTE-107»', $faltan);
        $this->assertStringContainsString('Renglón 2 (Bruno Prueba Dos, Bomba Presurizadora Beta): hay 2 artículos con el código «REPE-107»', $faltan);
        $this->assertStringContainsString('zz-c107 Doble A', $faltan);
        $this->assertCount(2, $respuesta['opciones']['renglones'][0]['articulos']);
    }

    // ---------------------------------------------------------------------
    // El CUIT y el importe
    // ---------------------------------------------------------------------

    /**
     * 🔴 Un CUIT inválido es una duda: nunca se «corrige», que es lo que hizo el modelo en el caso real.
     *
     * @test
     */
    public function un_cuit_invalido_es_una_duda_y_no_se_corrige()
    {
        $filas = $this->filas_de_prueba();
        $filas[2][8] = self::CUIT_MAL;

        $lineas = array_map(function ($fila) {
            return implode("\t", $fila);
        }, $filas);

        $conversation = $this->conversacion();
        $planilla = $this->planilla_pegada($conversation, implode("\n", $lineas));

        $this->assertArrayNotHasKey(RolesDePlanillaIaHelper::DOCUMENTO, $planilla->filas[2]['valores']);
        $this->assertSame(RolesDePlanillaIaHelper::DOCUMENTO, $planilla->filas[2]['dudas'][0]['rol']);
        $this->assertSame([self::CUIT_MAL], $planilla->filas[2]['dudas'][0]['opciones']);

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR);
        $assistant = $this->assistant($conversation);

        $this->definir_para_cargar($conversation, $assistant);

        $respuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('Renglón 3 (Empresa Prueba SA, zz-c107 Foco): el documento «' . self::CUIT_MAL . '» no sirve', implode(' | ', $respuesta['faltan']));

        // Lo corrige la persona, y un número malo de nuevo no entra.
        $this->del_dueno($conversation, 'El CUIT del renglón 3 es ' . self::CUIT_EMPRESA);
        $otro = $this->assistant($conversation);

        $malo = $this->herramienta('corregir_renglon_de_planilla', ['renglon' => 3, 'campo' => 'cliente', 'valor' => self::CUIT_MAL, 'lo_que_dijo_el_dueno' => 'El CUIT del renglón 3'], $conversation, $otro);

        $this->assertFalse($malo['ok']);

        $bueno = $this->herramienta('corregir_renglon_de_planilla', ['renglon' => 3, 'campo' => 'cliente', 'valor' => self::CUIT_EMPRESA, 'lo_que_dijo_el_dueno' => 'El CUIT del renglón 3'], $conversation, $otro);

        $this->assertTrue($bueno['ok'], json_encode($bueno));
        $this->assertSame(self::CUIT_EMPRESA, PlanillaIaHelper::fila_numero($planilla->fresh(), 3)['valores'][RolesDePlanillaIaHelper::DOCUMENTO]);
    }

    /**
     * Sin encabezado y con alguna cantidad mayor a 1, se pregunta UNA vez si el importe es el total o por unidad.
     *
     * @test
     */
    public function sin_encabezado_el_importe_se_pregunta_una_vez_y_vale_para_toda_la_planilla()
    {
        $conversation = $this->conversacion();
        $planilla = $this->planilla_pegada($conversation);

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR);
        $assistant = $this->assistant($conversation);

        $respuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->assertFalse($respuesta['ok']);

        $sobre_el_importe = array_values(array_filter($respuesta['faltan'], function ($falta) {
            return mb_strpos($falta, 'TOTAL de esa venta') !== false;
        }));

        $this->assertCount(1, $sobre_el_importe);
        $this->assertStringContainsString('los renglones 2, 3', $sobre_el_importe[0]);

        // El caso real: «son dos unidades, es el total de la venta».
        $this->del_dueno($conversation, 'ES EL TOTAL DE LA VENTA');
        $otro = $this->assistant($conversation);

        $this->herramienta('definir_planilla', ['importe_es' => 'total'], $conversation, $otro);

        $planilla = $planilla->fresh();

        $fila = PlanillaIaHelper::fila_numero($planilla, 2);

        $this->assertSame(88400.0, RolesDePlanillaIaHelper::total_de($fila, $planilla->importe_es));
        $this->assertSame(44200.0, RolesDePlanillaIaHelper::precio_unitario_de($fila, $planilla->importe_es));

        // Por unidad, el mismo renglón vale el doble. Sin lo que definió el dueño, que ahora queda en el renglón.
        unset($fila['importe_es']);

        $this->assertSame(176800.0, RolesDePlanillaIaHelper::total_de($fila, AiPlanilla::IMPORTE_UNIDAD));
        $this->assertSame(88400.0, RolesDePlanillaIaHelper::precio_unitario_de($fila, AiPlanilla::IMPORTE_UNIDAD));
    }

    /**
     * Un encabezado con «total» lo dice solo, y una planilla sin cantidades mayores a 1 no pregunta nada del importe.
     *
     * @test
     */
    public function el_encabezado_con_total_no_pregunta_nada_del_importe()
    {
        $encabezado = implode("\t", ['VENTA TOTAL', 'FECHA', 'CODIGO', 'DESCRIPCION', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE']);

        $filas = [
            implode("\t", ['88.400,00', '13-oct', '70102', 'Bomba', '2', 'DNI', '14000002', 'Bruno Prueba Dos']),
            implode("\t", ['61.250,00', '13-oct', '70101', 'Otra bomba', '1', 'DNI', '17000001', 'Ana Prueba Uno']),
        ];

        $conversation = $this->conversacion();
        $planilla = $this->planilla_pegada($conversation, $encabezado . "\n" . implode("\n", $filas));

        $this->assertSame(AiPlanilla::IMPORTE_TOTAL, $planilla->importe_es);
        $this->assertCount(2, $planilla->filas);

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR);
        $assistant = $this->assistant($conversation);

        $respuesta = $this->herramienta('consultar_planilla', [], $conversation, $assistant);

        $this->assertStringNotContainsString('TOTAL de esa venta', implode(' | ', $respuesta['faltan']));
    }

    // ---------------------------------------------------------------------
    // Ya registrada
    // ---------------------------------------------------------------------

    /**
     * Una venta que ya está, por su N° de operación en las observaciones o por cliente, artículo y total, no se vuelve a
     * proponer: queda con su número.
     *
     * @test
     */
    public function una_venta_ya_registrada_no_se_vuelve_a_proponer()
    {
        // Primero, una tarjeta confirmada: esas ventas ya tienen su operación en las observaciones.
        $conversation = $this->conversacion();

        list($planilla, $propuso) = $this->tarjeta_lista($conversation);

        $this->del_dueno($conversation, 'Sí');
        $this->responder($conversation, $this->assistant($conversation));

        $ventas = $this->ventas_de($planilla);

        $this->assertCount(4, $ventas);
        $this->assertSame(AiPlanilla::ESTADO_CERRADA, $planilla->fresh()->estado);

        // El dueño vuelve a pegar la misma planilla al otro día: 4 renglones, ninguno por cargar.
        $otra_conversacion = $this->conversacion();

        $otra = $this->planilla_pegada($otra_conversacion);

        $this->assertNotSame((int) $planilla->id, (int) $otra->id, 'La anterior está cerrada: es otra planilla.');

        foreach ($otra->filas as $i => $fila) {
            $this->assertSame(AiPlanilla::FILA_YA_REGISTRADA, $fila['estado']);
            $this->assertSame((int) $ventas[$i]->num, (int) $fila['venta_numero']);
        }

        $this->del_dueno($otra_conversacion, self::PEDIDO_PARA_CARGAR);
        $assistant = $this->assistant($otra_conversacion);

        $this->definir_para_cargar($otra_conversacion, $assistant);

        $respuesta = $this->herramienta('proponer_ventas_de_planilla', [], $otra_conversacion, $assistant);

        $this->assertFalse($respuesta['ok']);
        $this->assertSame('planilla_sin_pendientes', $respuesta['codigo']);
        $this->assertStringContainsString('Venta N° ' . (int) $ventas[0]->num, $respuesta['error']);
        $this->assertSame(4, $this->ventas_de($planilla)->count());
    }

    /**
     * La cargada a mano, sin operación: la reconoce por cliente, artículo y total.
     *
     * @test
     */
    public function una_venta_cargada_a_mano_se_reconoce_por_cliente_articulo_y_total()
    {
        $cliente = Client::create([
            'name'    => 'Ana Prueba Uno',
            'dni'     => '17000001',
            'user_id' => $this->dueno->id,
            'num'     => 910701,
        ]);

        $articulo = Article::where('user_id', $this->dueno->id)->where('bar_code', '70101')->first();

        $a_mano = Sale::create([
            'num'       => 91070101,
            'user_id'   => $this->dueno->id,
            'client_id' => $cliente->id,
            'total'     => 61250,
        ]);

        $a_mano->articles()->attach($articulo->id, ['amount' => 1, 'price' => 61250]);

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $fila = PlanillaIaHelper::fila_numero($planilla->fresh(), 1);

        $this->assertSame(AiPlanilla::FILA_YA_REGISTRADA, $fila['estado']);
        $this->assertSame(91070101, (int) $fila['venta_numero']);

        // Los demás siguen pendientes.
        $this->assertSame(AiPlanilla::FILA_PENDIENTE, PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['estado']);
    }

    // ---------------------------------------------------------------------
    // El «sí»: la tarjeta entera, y lo que crea
    // ---------------------------------------------------------------------

    /**
     * 🔴 El «sí» carga exactamente la planilla: artículo, cantidad y precio de cada renglón, sin llamar al modelo.
     *
     * @test
     */
    public function el_si_carga_todas_las_ventas_exactas_sin_llamar_al_modelo()
    {
        $conversation = $this->conversacion();

        list($planilla) = $this->tarjeta_lista($conversation);

        $this->falsear_ia();

        $this->del_dueno($conversation, 'SI');
        $texto = $this->responder($conversation, $this->assistant($conversation));

        Http::assertNothingSent();

        $ventas = $this->ventas_de($planilla);

        $this->assertCount(4, $ventas);

        $esperadas = [
            ['70101', null, 1, 61250, 61250, 'Ana Prueba Uno'],
            ['70102', null, 2, 44200, 88400, 'Bruno Prueba Dos'],
            [null, 'ZRRI-50LF', 2, 102000, 204000, 'Empresa Prueba SA'],
            [null, 'wzz3324', 1, 73500, 73500, 'Carla Prueba Tres'],
        ];

        foreach ($esperadas as $i => $esperada) {

            $venta = $ventas[$i];

            $this->assertEquals($esperada[4], (float) $venta->total, 'Total del renglón ' . ($i + 1));
            $this->assertSame($esperada[5], Client::find($venta->client_id)->name);

            $this->assertCount(1, $venta->articles);

            $articulo = $venta->articles[0];

            if (!is_null($esperada[0])) {
                $this->assertSame($esperada[0], (string) $articulo->bar_code);
            } else {
                $this->assertSame($esperada[1], (string) $articulo->provider_code);
            }

            $this->assertEquals($esperada[2], (float) $articulo->pivot->amount, 'Cantidad del renglón ' . ($i + 1));
            $this->assertEquals($esperada[3], (float) $articulo->pivot->price, 'Precio del renglón ' . ($i + 1));
        }

        // Observaciones: la fecha y la operación de la planilla, y la fecha de la venta es la de hoy.
        $this->assertStringContainsString('13-oct', (string) $ventas[0]->observations);
        $this->assertStringContainsString('Op. #2000000000000001', (string) $ventas[0]->observations);
        $this->assertTrue($ventas[0]->created_at->isToday());

        // Sin facturar: la tarjeta no toca ARCA.
        $this->assertSame(0, \App\Models\AfipTicket::whereIn('sale_id', $ventas->pluck('id'))->count());

        // El texto lo armó el código, con cada número real.
        foreach ($ventas as $venta) {
            $this->assertStringContainsString('Venta N° ' . (int) $venta->num, $texto);
        }

        $this->assertStringContainsString('Quedan sin facturar: la planilla pide 1 Factura A y 3 a consumidor final', $texto);

        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::where('ai_conversation_id', $conversation->id)->first()->estado);
        $this->assertSame(AiPlanilla::ESTADO_CERRADA, $planilla->fresh()->estado);
    }

    /**
     * Lo mismo por WhatsApp, y el precio de la planilla le gana a la lista.
     *
     * @test
     */
    public function por_whatsapp_el_si_tambien_carga_todo()
    {
        $conversation = $this->conversacion();

        list($planilla) = $this->tarjeta_lista($conversation, AiMessage::CANAL_WHATSAPP);

        $this->falsear_ia();

        $this->del_dueno($conversation, 'dale', AiMessage::CANAL_WHATSAPP);
        $texto = $this->responder($conversation, $this->assistant($conversation, AiMessage::CANAL_WHATSAPP));

        Http::assertNothingSent();

        $this->assertCount(4, $this->ventas_de($planilla));
        $this->assertStringContainsString('Listo, cargué 4 ventas', $texto);
    }

    /**
     * Los clientes nuevos: sin teléfono, con nombre, documento y dirección de la planilla, y con la condición de IVA según
     * el comprobante. Es la Factura B que el dueño tuvo que rehacer como A en el caso real.
     *
     * @test
     */
    public function el_cliente_nuevo_nace_con_sus_datos_y_la_condicion_de_iva_que_pide_la_planilla()
    {
        $conversation = $this->conversacion();

        list($planilla) = $this->tarjeta_lista($conversation);

        $this->del_dueno($conversation, 'Sí');
        $this->responder($conversation, $this->assistant($conversation));

        $empresa = Client::where('user_id', $this->dueno->id)->where('cuit', self::CUIT_EMPRESA)->first();

        $this->assertNotNull($empresa);
        $this->assertSame('Empresa Prueba SA', $empresa->name);
        $this->assertSame('Empresa Prueba SA', $empresa->razon_social);
        $this->assertSame((int) IvaCondition::where('name', 'Responsable inscripto')->value('id'), (int) $empresa->iva_condition_id);
        $this->assertEmpty($empresa->phone, 'No se pide teléfono.');

        $ana = Client::where('user_id', $this->dueno->id)->where('dni', '17000001')->first();

        $this->assertNotNull($ana);
        $this->assertSame('Calle Falsa, 100 - Ciudad Uno - CP: 1000', $ana->address);
        $this->assertSame((int) IvaCondition::where('name', 'Consumidor final')->value('id'), (int) $ana->iva_condition_id);

        // Y nunca se crean dos veces.
        $this->assertSame(1, Client::where('user_id', $this->dueno->id)->where('dni', '17000001')->count());
    }

    /**
     * Un cliente que ya existe, por su documento, no se crea ni se toca.
     *
     * @test
     */
    public function el_cliente_existente_se_encuentra_por_su_documento_exacto()
    {
        $existente = Client::create([
            'name'    => 'Nombre Distinto En El Sistema',
            'cuit'    => '30-71234567-1',
            'user_id' => $this->dueno->id,
            'num'     => 910702,
        ]);

        $conversation = $this->conversacion();

        list($planilla) = $this->tarjeta_lista($conversation);

        $this->assertSame((int) $existente->id, (int) PlanillaIaHelper::fila_numero($planilla, 3)['cliente_id']);
        $this->assertFalse(PlanillaIaHelper::fila_numero($planilla, 3)['cliente_nuevo']);

        $this->del_dueno($conversation, 'Sí');
        $this->responder($conversation, $this->assistant($conversation));

        $this->assertSame('Nombre Distinto En El Sistema', $existente->fresh()->name);
        $this->assertSame(0, Client::where('user_id', $this->dueno->id)->where('cuit', self::CUIT_EMPRESA)->count());

        $venta = $this->ventas_de($planilla)[2];

        $this->assertSame((int) $existente->id, (int) $venta->client_id);
    }

    /**
     * 🔴 Reintento sin duplicar: lo hecho se guarda renglón por renglón. Se reintenta y hace solo lo que falta.
     *
     * @test
     */
    public function un_reintento_hace_solo_lo_que_falta_y_no_duplica()
    {
        $conversation = $this->conversacion();

        list($planilla) = $this->tarjeta_lista($conversation);

        // El artículo del renglón 3 deja de estar: esa fila falla y las otras salen.
        $reflector = Article::where('user_id', $this->dueno->id)->where('provider_code', 'ZRRI-50LF')->first();
        $reflector->status = 'inactive';
        $reflector->save();

        $this->del_dueno($conversation, 'Sí');
        $texto = $this->responder($conversation, $this->assistant($conversation));

        $this->assertCount(3, $this->ventas_de($planilla));
        $this->assertStringContainsString('No se pudieron cargar:', $texto);
        $this->assertStringContainsString('Renglón 3', $texto);

        $planilla = $planilla->fresh();

        $this->assertSame(AiPlanilla::FILA_BLOQUEADA, PlanillaIaHelper::fila_numero($planilla, 3)['estado']);
        $this->assertSame(AiPlanilla::ESTADO_ACTIVA, $planilla->estado);

        $numero_de_la_primera = (int) PlanillaIaHelper::fila_numero($planilla, 1)['venta_numero'];

        $this->assertGreaterThan(0, $numero_de_la_primera);

        // Vuelve a estar, y se pide de nuevo: una tarjeta con UN renglón.
        $reflector->status = 'active';
        $reflector->save();

        $this->del_dueno($conversation, 'Ya lo activé, reintentá');
        $otro = $this->assistant($conversation);

        $propuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $otro);

        $this->assertTrue($propuesta['ok'], json_encode($propuesta));

        $tarjeta = AiMessageAction::find($propuesta['tarjeta_id']);

        $this->assertSame([3], $tarjeta->datos['renglones']);

        $otro->contenido = 'La lista.';
        $otro->estado = 'listo';
        $otro->save();

        $this->del_dueno($conversation, 'Sí');
        $this->responder($conversation, $this->assistant($conversation));

        $this->assertCount(4, $this->ventas_de($planilla));
        $this->assertSame(AiPlanilla::ESTADO_CERRADA, $planilla->fresh()->estado);
        $this->assertSame($numero_de_la_primera, (int) PlanillaIaHelper::fila_numero($planilla->fresh(), 1)['venta_numero']);
    }

    /**
     * Y si el proceso murió justo después de crear la venta, sin guardar su número: el renglón sigue pendiente, pero la
     * venta no se duplica.
     *
     * @test
     */
    public function si_se_perdio_el_numero_de_la_venta_no_se_duplica()
    {
        $conversation = $this->conversacion();

        list($planilla, $propuso) = $this->tarjeta_lista($conversation);

        $this->del_dueno($conversation, 'Sí');
        $this->responder($conversation, $this->assistant($conversation));

        $this->assertCount(4, $this->ventas_de($planilla));

        // Como si no hubiera llegado a escribir nada de los renglones.
        $planilla = $planilla->fresh();

        $filas = $planilla->filas;

        foreach ($filas as $i => $fila) {
            $filas[$i]['estado'] = AiPlanilla::FILA_PENDIENTE;
            $filas[$i]['venta_id'] = null;
            $filas[$i]['venta_numero'] = null;
        }

        $planilla->filas = $filas;
        $planilla->estado = AiPlanilla::ESTADO_ACTIVA;
        $planilla->save();

        $this->del_dueno($conversation, 'Reintentá');
        $otro = $this->assistant($conversation);

        $respuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $otro);

        $this->assertFalse($respuesta['ok'], 'Las reconoce por su operación: no hay nada por cargar.');
        $this->assertSame('planilla_sin_pendientes', $respuesta['codigo']);
        $this->assertCount(4, $this->ventas_de($planilla));
    }

    /**
     * Si no se pudo cargar NINGUNO, la tarjeta sigue para confirmar y se dice por qué.
     *
     * @test
     */
    public function si_no_se_pudo_cargar_ninguna_la_tarjeta_sigue_para_confirmar()
    {
        $conversation = $this->conversacion();

        list($planilla) = $this->tarjeta_lista($conversation);

        Article::where('user_id', $this->dueno->id)->whereIn('bar_code', ['70101', '70102'])->update(['status' => 'inactive']);
        Article::where('user_id', $this->dueno->id)->whereIn('provider_code', ['ZRRI-50LF', 'wzz3324'])->update(['status' => 'inactive']);

        $this->del_dueno($conversation, 'Sí');
        $texto = $this->responder($conversation, $this->assistant($conversation));

        $this->assertCount(0, $this->ventas_de($planilla));
        $this->assertStringContainsString('No pude cargar las ventas de la planilla', $texto);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::where('ai_conversation_id', $conversation->id)->first()->estado_guardado());
    }

    /**
     * Una tarjeta armada con una tabla que después cambió no se puede ejecutar.
     *
     * @test
     */
    public function una_tarjeta_vieja_no_ejecuta_una_tabla_que_cambio()
    {
        $conversation = $this->conversacion();

        list($planilla, $propuso) = $this->tarjeta_lista($conversation);

        $tarjeta = AiMessageAction::where('ai_conversation_id', $conversation->id)->first();

        // Se toca la tabla sin pasar por las herramientas (que reemplazarían la tarjeta).
        $filas = $planilla->filas;
        $filas[0]['valores'][RolesDePlanillaIaHelper::IMPORTE] = '1.000,00';
        $planilla->filas = $filas;
        $planilla->save();

        $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta->id . '/confirmar')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'La planilla cambió después de armar esta tarjeta. Pedime las ventas de nuevo.']);

        $this->assertCount(0, $this->ventas_de($planilla));
    }

    /**
     * El botón del panel ejecuta lo mismo que el «sí».
     *
     * @test
     */
    public function el_boton_del_panel_ejecuta_lo_mismo()
    {
        $conversation = $this->conversacion();

        list($planilla) = $this->tarjeta_lista($conversation);

        $tarjeta = AiMessageAction::where('ai_conversation_id', $conversation->id)->first();

        $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta->id . '/confirmar')->assertStatus(200);

        $this->assertCount(4, $this->ventas_de($planilla));

        $resultado = AiMessageAction::find($tarjeta->id)->resultado;

        $this->assertStringContainsString('4 ventas registradas', $resultado->texto);
    }

    /**
     * 🔴 La tarjeta NO va en la autoconfirmación: ni en "directo" se ejecuta sola, y un «sí» determinista sí la confirma.
     *
     * @test
     */
    public function la_tarjeta_no_se_autoconfirma_en_ningun_nivel_de_confianza()
    {
        $this->assertNotContains(AiMessageAction::TIPO_VENTAS_PLANILLA, HerramientasDeCarga::auto_confirmables_de(ConfianzaDelAgenteIaHelper::DIRECTO));
        $this->assertNotContains(AiMessageAction::TIPO_VENTAS_PLANILLA, HerramientasDeCarga::auto_confirmables_de(ConfianzaDelAgenteIaHelper::RESUELTO));
        $this->assertNotContains(AiMessageAction::TIPO_VENTAS_PLANILLA, HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES, 'Si estuviera ahí, el «sí» determinista no la confirmaría.');

        $this->dueno->agente_confianza = ConfianzaDelAgenteIaHelper::DIRECTO;
        $this->dueno->save();

        $conversation = $this->conversacion();

        list($planilla) = $this->tarjeta_lista($conversation);

        $this->assertCount(0, $this->ventas_de($planilla), 'Queda esperando el sí.');
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::where('ai_conversation_id', $conversation->id)->first()->estado_guardado());
    }

    /**
     * Con el importe por unidad, la venta sale por cantidad × importe.
     *
     * @test
     */
    public function con_el_importe_por_unidad_la_venta_sale_por_cantidad_por_importe()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $this->del_dueno($conversation, 'Son precios por unidad, en Efectivo, Caja Efectivo');
        $assistant = $this->assistant($conversation);

        $this->herramienta('definir_planilla', [
            'importe_es'     => 'unidad',
            'metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO,
            'caja'           => TestingFerreteriaSeeder::CAJA_EFECTIVO,
        ], $conversation, $assistant);

        $this->assertTrue($this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant)['ok']);

        $assistant->contenido = 'x';
        $assistant->estado = 'listo';
        $assistant->save();

        $this->del_dueno($conversation, 'Sí');
        $this->responder($conversation, $this->assistant($conversation));

        $venta = $this->ventas_de($planilla)[1];

        $this->assertEquals(176800, (float) $venta->total);
        $this->assertEquals(2, (float) $venta->articles[0]->pivot->amount);
        $this->assertEquals(88400, (float) $venta->articles[0]->pivot->price);
    }

    /**
     * Si el sí no es inequívoco lo confirma el modelo con su herramienta, y el texto lo sigue armando el código.
     *
     * @test
     */
    public function un_si_que_confirma_el_modelo_tambien_lo_cuenta_el_codigo()
    {
        $conversation = $this->conversacion();

        list($planilla) = $this->tarjeta_lista($conversation, AiMessage::CANAL_WHATSAPP);

        $tarjeta = AiMessageAction::where('ai_conversation_id', $conversation->id)->first();

        $this->falsear_ia();

        $this->vueltas = [
            $this->pide_herramienta('confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta->id]),
            $this->texto_del_modelo('Listo, cargué 12 ventas por $ 999.999.'),
        ];

        $this->del_dueno($conversation, 'sí, cargá las cuatro nomás', AiMessage::CANAL_WHATSAPP);
        $texto = $this->responder($conversation, $this->assistant($conversation, AiMessage::CANAL_WHATSAPP));

        $this->assertCount(4, $this->ventas_de($planilla));
        $this->assertStringNotContainsString('999.999', $texto);
        $this->assertStringContainsString('Listo, cargué 4 ventas', $texto);
    }

    /**
     * 🔴 La charla del caso real, de punta a punta, con DeepSeek conversando: foto, preguntas, un «SI» que no cambia nada, la
     * lista armada por el código, y una foto repetida que no la vuelve a cambiar.
     *
     * @test
     */
    public function la_charla_del_caso_real_de_punta_a_punta_con_deepseek()
    {
        $this->dueno->agente_proveedor = 'deepseek';
        $this->dueno->save();

        $this->todos_leen($this->filas_de_prueba());
        $this->falsear_ia();

        $conversation = $this->conversacion();

        // 1. La foto.
        $pedido = $this->del_dueno($conversation, 'CARGAME ESTAS VENTAS');
        $this->foto($pedido);

        $this->vueltas = [
            $this->pide_herramienta('proponer_ventas_de_planilla'),
            $this->texto_del_modelo('¿El importe es el total? ¿Cómo se cobraron?'),
        ];

        $this->responder($conversation, $this->assistant($conversation));

        // 2. La respuesta.
        $this->del_dueno($conversation, 'ES EL TOTAL DE LA VENTA, EFECTIVO');

        $this->vueltas = [
            $this->pide_herramienta('definir_planilla', ['importe_es' => 'total', 'metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO, 'caja' => TestingFerreteriaSeeder::CAJA_EFECTIVO]),
            $this->pide_herramienta('proponer_ventas_de_planilla'),
            $this->texto_del_modelo('Con la planilla nueva aparecen cambios: Carla lleva 12 cajas.'),
        ];

        $lista = $this->responder($conversation, $this->assistant($conversation));

        $this->assertStringContainsString('4. Carla Prueba Tres (nuevo): 1 × zz-c107 Maletin herramientas (wzz3324), $ 73.500', $lista);
        $this->assertStringNotContainsString('12 cajas', $lista);

        // 3. El mismo Excel, de nuevo y mal leído: no cambia lo acordado, y la tarjeta ya no se puede confirmar a ciegas.
        $releida = $this->filas_de_prueba();
        $releida[3][6] = '12';

        $this->todos_leen($releida);

        // 4. El «SI» confirma lo que se mostró: 4 ventas, exactas, sin que el modelo escriba nada.
        $this->del_dueno($conversation, 'SI');

        $antes = count(Http::recorded());

        $texto = $this->responder($conversation, $this->assistant($conversation));

        $this->assertCount($antes, Http::recorded(), 'El sí no llama a ningún modelo.');

        $planilla = AiPlanilla::where('ai_conversation_id', $conversation->id)->first();

        $ventas = $this->ventas_de($planilla);

        $this->assertCount(4, $ventas);
        $this->assertEquals(427150, $ventas->sum(function ($venta) {
            return (float) $venta->total;
        }));
        $this->assertEquals(1, (float) $ventas[3]->articles[0]->pivot->amount, 'Carla lleva 1, no 12.');
        $this->assertStringContainsString('Listo, cargué 4 ventas', $texto);

        // 5. Ningún modelo que conversa vio una imagen ni un documento en toda la charla.
        foreach ($this->requests_del_modelo_que_conversa() as $cuerpo) {
            $this->assertStringNotContainsString('"type":"image"', json_encode($cuerpo['messages']));
            $this->assertStringContainsString('deepseek', (string) $cuerpo['model']);
        }

        // 6. La foto repetida después de cargar no carga nada de nuevo.
        $otra = $this->del_dueno($conversation, 'te la mando de nuevo');
        $this->foto($otra);

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

        $nueva = AiPlanilla::where('ai_conversation_id', $conversation->id)->orderBy('id', 'DESC')->first();

        $this->assertNotSame((int) $planilla->id, (int) $nueva->id);

        foreach ($nueva->filas as $fila) {
            $this->assertSame(AiPlanilla::FILA_YA_REGISTRADA, $fila['estado']);
        }
    }

    /**
     * Cada venta lleva su identificador de renglón: la red contra un proceso que muere entre crearla y anotar su número.
     *
     * @test
     */
    public function cada_venta_lleva_el_identificador_de_su_renglon()
    {
        $conversation = $this->conversacion();

        list($planilla) = $this->tarjeta_lista($conversation);

        $this->del_dueno($conversation, 'Sí');
        $this->responder($conversation, $this->assistant($conversation));

        $venta = $this->ventas_de($planilla)[1];

        $this->assertSame('planilla-' . $planilla->id . '-2', (string) $venta->offline_uuid);
    }

    /**
     * Un artículo que no se puede vender desde acá (con variantes) se dice antes del sí, no después.
     *
     * @test
     */
    public function un_articulo_con_variantes_se_dice_antes_del_si()
    {
        $extencion = ExtencionEmpresa::where('slug', 'article_variants')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'article_variants', 'name' => 'Variantes']);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->dueno->load('extencions');

        $articulo = Article::where('user_id', $this->dueno->id)->where('bar_code', '70101')->first();

        \App\Models\ArticleVariant::create(['article_id' => $articulo->id, 'variant_description' => 'Talle único']);

        $conversation = $this->conversacion();

        $this->planilla_pegada($conversation);

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR);
        $assistant = $this->assistant($conversation);

        $this->definir_para_cargar($conversation, $assistant);

        $respuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('Renglón 1 (Ana Prueba Uno, zz-c107 Bomba Alfa):', implode(' | ', $respuesta['faltan']));
        $this->assertStringContainsString('se vende por variante', implode(' | ', $respuesta['faltan']));
    }

    /**
     * El cliente que ya existe no se toca, pero si la planilla pide Factura A y no es responsable inscripto, se avisa antes.
     *
     * @test
     */
    public function se_avisa_si_la_planilla_pide_factura_a_a_un_cliente_que_no_es_inscripto()
    {
        Client::create([
            'name'             => 'Empresa Prueba Vieja',
            'cuit'             => self::CUIT_EMPRESA,
            'iva_condition_id' => IvaCondition::where('name', 'Consumidor final')->value('id'),
            'user_id'          => $this->dueno->id,
            'num'              => 910704,
        ]);

        $conversation = $this->conversacion();

        $this->tarjeta_lista($conversation);

        $tarjeta = AiMessageAction::where('ai_conversation_id', $conversation->id)->first();

        $this->assertStringContainsString('Ojo con el renglón 3: la planilla pide Factura A y Empresa Prueba Vieja no figura como responsable inscripto', $tarjeta->datos['texto']);
    }

    /**
     * Una foto sin revisar no se le escapa al modelo en el turno siguiente: se vuelve a mirar.
     *
     * @test
     */
    public function una_foto_que_no_se_pudo_revisar_se_vuelve_a_mirar_en_el_turno_siguiente()
    {
        $this->clasificador = null;
        $this->todos_leen($this->filas_de_prueba());
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '');
        $foto = $this->foto($pedido);

        $this->responder($conversation, $this->assistant($conversation));

        $this->assertSame(0, AiPlanilla::where('ai_conversation_id', $conversation->id)->count());
        $this->assertNull($foto->fresh()->gestionada_at);

        // Anthropic volvió: pasado lo que se recuerda SIN_REVISAR, en el turno siguiente se lee.
        $this->clasificador = true;

        \Carbon\Carbon::setTestNow(\Carbon\Carbon::now()->addMinutes(LecturaDePlanillaIaHelper::MINUTOS_DE_SIN_REVISAR + 1));

        $this->del_dueno($conversation, '¿La pudiste ver?');
        $this->responder($conversation, $this->assistant($conversation));

        \Carbon\Carbon::setTestNow();

        $this->assertSame(1, AiPlanilla::where('ai_conversation_id', $conversation->id)->count());
        $this->assertNotNull($foto->fresh()->gestionada_at);

        $cuerpos = $this->requests_del_modelo_que_conversa();

        $this->assertStringNotContainsString('"type":"image"', json_encode($cuerpos[count($cuerpos) - 1]['messages']));
    }

    // ---------------------------------------------------------------------
    // corregir_renglon_de_planilla
    // ---------------------------------------------------------------------

    /**
     * 🔴 Los mensajes 312 y 314 del caso real: un «sí» no cambia nada. Y un pedido real sí.
     *
     * @test
     */
    public function corregir_un_renglon_se_rechaza_con_un_si_y_se_acepta_con_un_pedido_real()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        foreach (['SI', 'dale', 'Sí, gracias', 'ok'] as $si) {

            $this->del_dueno($conversation, $si);
            $assistant = $this->assistant($conversation);

            $rechazo = $this->herramienta('corregir_renglon_de_planilla', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 12], $conversation, $assistant);

            $this->assertFalse($rechazo['ok'], $si);
            $this->assertSame('un_si_no_corrige', $rechazo['codigo']);

            $assistant->contenido = 'x';
            $assistant->estado = 'listo';
            $assistant->save();
        }

        $this->assertSame('2', PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['valores'][RolesDePlanillaIaHelper::CANTIDAD]);

        $this->del_dueno($conversation, 'En el renglón 2 son 3 unidades');
        $assistant = $this->assistant($conversation);

        $bueno = $this->herramienta('corregir_renglon_de_planilla', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 3, 'lo_que_dijo_el_dueno' => 'En el renglón 2 son 3 unidades'], $conversation, $assistant);

        $this->assertTrue($bueno['ok'], json_encode($bueno));
        $this->assertEquals(3.0, RolesDePlanillaIaHelper::cantidad_de(PlanillaIaHelper::fila_numero($planilla->fresh(), 2)));
    }

    /**
     * Los campos cerrados: importe, artículo y descartar; lo cargado no se toca; y corregir deja vieja la tarjeta.
     *
     * @test
     */
    public function corregir_cambia_lo_pedido_y_deja_vieja_la_tarjeta()
    {
        $conversation = $this->conversacion();

        list($planilla) = $this->tarjeta_lista($conversation);

        $this->del_dueno($conversation, 'El renglón 1 fue por 50.000, el renglón 2 es el artículo wzz3324 y el renglón 4 no lo cargues');
        $assistant = $this->assistant($conversation);

        $tarjeta_vieja = AiMessageAction::where('ai_conversation_id', $conversation->id)->first();

        $this->assertTrue($this->herramienta('corregir_renglon_de_planilla', ['renglon' => 1, 'campo' => 'importe', 'valor' => '50.000', 'lo_que_dijo_el_dueno' => 'El renglón 1 fue por 50.000'], $conversation, $assistant)['ok']);
        $this->assertTrue($this->herramienta('corregir_renglon_de_planilla', ['renglon' => 2, 'campo' => 'articulo', 'valor' => 'wzz3324', 'lo_que_dijo_el_dueno' => 'el renglón 2 es el artículo wzz3324'], $conversation, $assistant)['ok']);
        $this->assertTrue($this->herramienta('corregir_renglon_de_planilla', ['renglon' => 4, 'campo' => 'descartar', 'lo_que_dijo_el_dueno' => 'el renglón 4 no lo cargues'], $conversation, $assistant)['ok']);

        $this->assertSame(AiMessageAction::ESTADO_REEMPLAZADA, $tarjeta_vieja->fresh()->estado_guardado());

        $propuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->assertTrue($propuesta['ok'], json_encode($propuesta));
        $this->assertSame([1, 2, 3], AiMessageAction::find($propuesta['tarjeta_id'])->datos['renglones']);

        $assistant->contenido = 'x';
        $assistant->estado = 'listo';
        $assistant->save();

        $this->del_dueno($conversation, 'Sí');
        $this->responder($conversation, $this->assistant($conversation));

        $ventas = $this->ventas_de($planilla);

        $this->assertCount(3, $ventas);
        $this->assertEquals(50000, (float) $ventas[0]->total);
        $this->assertSame('wzz3324', (string) $ventas[1]->articles[0]->provider_code);

        // Lo cargado no se cambia.
        $this->del_dueno($conversation, 'Cambiá el renglón 1 a 60.000');
        $otro = $this->assistant($conversation);

        $tarde = $this->herramienta('consultar_planilla', [], $conversation, $otro);

        $this->assertFalse($tarde['ok'], 'La planilla ya se cerró: ninguna venta se reescribe desde acá.');
    }

    // ---------------------------------------------------------------------
    // Foto nueva con planilla en curso
    // ---------------------------------------------------------------------

    /**
     * 🔴 «Con la planilla nueva aparecen cambios»: una foto nueva no pisa nada. Cruza por operación y completa lo que faltaba.
     *
     * @test
     */
    public function una_foto_nueva_con_planilla_en_curso_cruza_por_operacion_y_no_pisa_nada()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        // La misma planilla releída distinto, como en el caso real: otra cantidad y otro cliente en el renglón 2.
        $releida = $this->filas_de_prueba();
        $releida[1][6] = '12';
        $releida[1][9] = 'Otro Nombre Cruzado';

        $this->todos_leen($releida);
        $this->falsear_ia();

        $pedido = $this->del_dueno($conversation, '');
        $this->foto($pedido);
        $assistant = $this->assistant($conversation);

        $notas = PlanillaIaHelper::al_arrancar_el_turno($conversation, $assistant);

        $this->assertSame(1, AiPlanilla::where('ai_conversation_id', $conversation->id)->count(), 'No hay planilla nueva: es la misma.');

        $planilla = $planilla->fresh();

        $this->assertCount(4, $planilla->filas);

        $fila = PlanillaIaHelper::fila_numero($planilla, 2);

        $this->assertSame('Bruno Prueba Dos', $fila['valores'][RolesDePlanillaIaHelper::NOMBRE], 'El nombre no se pisa.');
        $this->assertArrayNotHasKey(RolesDePlanillaIaHelper::CANTIDAD, $fila['valores']);
        $this->assertSame(RolesDePlanillaIaHelper::CANTIDAD, $fila['dudas'][0]['rol'], 'Dos lecturas distintas de lo que mueve plata: se le pregunta.');

        $this->assertStringContainsString('Lo ya cargado sigue cargado', implode(' ', $notas));
    }

    /**
     * Lo cargado sigue cargado, y un renglón nuevo de la foto entra.
     *
     * @test
     */
    public function lo_cargado_sigue_cargado_y_un_renglon_nuevo_entra()
    {
        $conversation = $this->conversacion();

        $filas = $this->filas_de_prueba();

        $lineas = array_map(function ($fila) {
            return implode("\t", $fila);
        }, array_slice($filas, 0, 3));

        $planilla = $this->planilla_pegada($conversation, implode("\n", $lineas));

        // Se marca cargado el primero a mano, como si lo hubiera hecho una tarjeta anterior.
        $marcadas = $planilla->filas;
        $marcadas[0]['estado'] = AiPlanilla::FILA_REGISTRADA;
        $marcadas[0]['venta_numero'] = 12345;
        $planilla->filas = $marcadas;
        $planilla->save();

        $releida = $filas;
        $releida[0][1] = '1,00';

        $this->todos_leen($releida);
        $this->falsear_ia();

        $pedido = $this->del_dueno($conversation, '');
        $this->foto($pedido);

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

        $planilla = $planilla->fresh();

        $this->assertCount(4, $planilla->filas);
        $this->assertSame('61.250,00', PlanillaIaHelper::fila_numero($planilla, 1)['valores'][RolesDePlanillaIaHelper::IMPORTE], 'Lo cargado sigue como estaba.');
        $this->assertSame(12345, (int) PlanillaIaHelper::fila_numero($planilla, 1)['venta_numero']);
        $this->assertSame('Carla Prueba Tres', PlanillaIaHelper::fila_numero($planilla, 4)['valores'][RolesDePlanillaIaHelper::NOMBRE]);
    }

    /**
     * Una foto con solo documento y nombre completa las filas: el caso de la foto `299`.
     *
     * @test
     */
    public function una_foto_con_solo_documento_y_nombre_completa_las_filas()
    {
        $conversation = $this->conversacion();

        // Una planilla sin nombres: solo importe, código, cantidad y documento.
        $planilla = $this->planilla_pegada($conversation, implode("\n", [
            implode("\t", ['61.250,00', 'SKU 70101', '1', 'DNI', '17000001']),
            implode("\t", ['88.400,00', 'SKU 70102', '2', 'DNI', '14000002']),
        ]));

        // Sin nombre el texto no parece de ventas... salvo por el documento: tiene a quién.
        $this->assertNotNull($planilla);
        $this->assertArrayNotHasKey(RolesDePlanillaIaHelper::NOMBRE, $planilla->filas[0]['valores']);

        $this->todos_leen([
            ['DNI', '14000002', 'Bruno Prueba Dos'],
            ['DNI', '17000001', 'Ana Prueba Uno'],
            ['DNI', '99000099', 'Alguien Que No Esta'],
        ]);

        $this->falsear_ia();

        $pedido = $this->del_dueno($conversation, '');
        $this->foto($pedido, 381, 219);

        $notas = PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

        $planilla = $planilla->fresh();

        $this->assertCount(3, $planilla->filas, 'La fila que no cruza se ve, pero no es una venta.');
        $this->assertSame(AiPlanilla::FILA_DESCARTADA, PlanillaIaHelper::fila_numero($planilla, 3)['estado']);
        $this->assertTrue(PlanillaIaHelper::fila_numero($planilla, 3)['sin_venta']);
        $this->assertSame('Ana Prueba Uno', PlanillaIaHelper::fila_numero($planilla, 1)['valores'][RolesDePlanillaIaHelper::NOMBRE]);
        $this->assertSame('Bruno Prueba Dos', PlanillaIaHelper::fila_numero($planilla, 2)['valores'][RolesDePlanillaIaHelper::NOMBRE]);
        $this->assertStringContainsString('1 no cruzaron', implode(' ', $notas));
    }

    /**
     * La planilla es una sola aunque la charla se parta: el texto en una conversación y la foto en su hermana.
     *
     * @test
     */
    public function la_planilla_se_cruza_entre_conversaciones_hermanas()
    {
        $primera = $this->conversacion();
        $planilla = $this->planilla_pegada($primera);

        $this->todos_leen($this->filas_de_prueba());
        $this->falsear_ia();

        $segunda = $this->conversacion();
        $pedido = $this->del_dueno($segunda, '');
        $foto = $this->foto($pedido);

        PlanillaIaHelper::al_arrancar_el_turno($segunda, $this->assistant($segunda));

        $this->assertSame(0, AiPlanilla::where('ai_conversation_id', $segunda->id)->count());
        $this->assertCount(4, $planilla->fresh()->filas);

        // En la segunda, la foto también se nombra por su planilla.
        $leido = PlanillaIaHelper::lo_leido_de($segunda);

        $this->assertArrayHasKey((int) $foto->id, $leido['fotos']);
    }

    // ---------------------------------------------------------------------
    // La guarda de proponer_venta
    // ---------------------------------------------------------------------

    /**
     * Con planilla en curso, proponer_venta a un cliente con renglón pendiente se rechaza, salvo que sea otra venta.
     *
     * @test
     */
    public function proponer_venta_se_frena_con_un_renglon_pendiente_salvo_otra_venta()
    {
        $conversation = $this->conversacion();

        $this->planilla_pegada($conversation);

        $cliente = Client::create(['name' => 'Bruno Prueba Dos', 'dni' => '14000002', 'user_id' => $this->dueno->id, 'num' => 910703]);

        $this->del_dueno($conversation, 'Vendele a Bruno');
        $assistant = $this->assistant($conversation);

        $input = [
            'cliente' => 'Bruno Prueba Dos',
            'cobro'   => 'contado',
            'metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO,
            'caja'    => TestingFerreteriaSeeder::CAJA_EFECTIVO,
            'items'   => [['articulo' => 'zz-c107 Bomba Beta', 'cantidad' => 2]],
        ];

        $frenada = $this->herramienta('proponer_venta', $input, $conversation, $assistant);

        $this->assertFalse($frenada['ok']);
        $this->assertSame('planilla_activa', $frenada['codigo']);
        $this->assertStringContainsString('proponer_ventas_de_planilla', $frenada['error']);
        $this->assertSame(0, AiMessageAction::where('ai_message_id', $assistant->id)->count());

        // Un cliente que no está en la planilla, con otro artículo, sigue como siempre.
        $otro_articulo = $this->articulo_de_prueba('zz-c107 Otro articulo', ['bar_code' => '70199']);

        $de_otro = $this->herramienta('proponer_venta', array_merge($input, ['cliente' => TestingFerreteriaSeeder::CLIENTE_CONTADO, 'items' => [['articulo_id' => $otro_articulo->id, 'cantidad' => 1]]]), $conversation, $assistant);

        $this->assertTrue(!empty($de_otro['ok']), json_encode($de_otro));

        // `es_otra_venta` lo manda el modelo: no alcanza.
        $this->assertSame('planilla_activa', $this->herramienta('proponer_venta', array_merge($input, ['es_otra_venta' => true]), $conversation, $assistant)['codigo']);

        $assistant->contenido = 'x';
        $assistant->estado = 'listo';
        $assistant->save();

        // Y la persona puede decir que es otra.
        $this->del_dueno($conversation, 'Es otra venta, aparte de la planilla');

        $otra = $this->herramienta('proponer_venta', $input, $conversation, $this->assistant($conversation));

        $this->assertTrue(!empty($otra['ok']), json_encode($otra));
    }

    // ---------------------------------------------------------------------
    // El texto lo arma el código
    // ---------------------------------------------------------------------

    /**
     * 🔴 Lo que ve el dueño al proponer es la tabla, aunque el modelo escriba otra cosa.
     *
     * @test
     */
    public function el_texto_de_la_propuesta_lo_arma_el_codigo_aunque_el_modelo_escriba_otra_cosa()
    {
        $this->falsear_ia();

        $conversation = $this->conversacion();

        $this->planilla_pegada($conversation);

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR);

        $assistant = $this->assistant($conversation);

        $this->vueltas = [
            $this->pide_herramienta('definir_planilla', ['importe_es' => 'total', 'metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO, 'caja' => TestingFerreteriaSeeder::CAJA_EFECTIVO]),
            $this->pide_herramienta('proponer_ventas_de_planilla'),
            $this->texto_del_modelo('Carla lleva 12 cajas por $999.111 y cambié todo porque llegó una planilla nueva.'),
        ];

        $texto = $this->responder($conversation, $assistant);

        $this->assertStringNotContainsString('999.111', $texto);
        $this->assertStringNotContainsString('planilla nueva', $texto);

        $this->assertStringContainsString('4 ventas para cargar', $texto);
        $this->assertStringContainsString('1. Ana Prueba Uno (nuevo): 1 × zz-c107 Bomba Alfa (70101), $ 61.250', $texto);
        $this->assertStringContainsString('2. Bruno Prueba Dos (nuevo): 2 × zz-c107 Bomba Beta (70102), $ 88.400', $texto);
        $this->assertStringContainsString('3. Empresa Prueba SA (nuevo): 2 × zz-c107 Foco (ZRRI-50LF), $ 204.000', $texto);
        $this->assertStringContainsString('4. Carla Prueba Tres (nuevo): 1 × zz-c107 Maletin herramientas (wzz3324), $ 73.500', $texto);
        $this->assertStringContainsString('Total: $ 427.150', $texto);
        $this->assertStringContainsString('Cobro: ' . TestingFerreteriaSeeder::PAGO_EFECTIVO, $texto);
        $this->assertStringContainsString('Clientes nuevos que se crean: 4', $texto);
        $this->assertStringContainsString('Quedan sin facturar: la planilla pide 1 Factura A y 3 a consumidor final', $texto);

        // La tarjeta tiene la misma tabla, con el renderer genérico de los SPA: título, renglones y aviso.
        $tarjeta = AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', AiMessageAction::ESTADO_PROPUESTA)->first();

        $this->assertSame(AiMessageAction::TIPO_VENTAS_PLANILLA, $tarjeta->tipo);
        $this->assertSame('Renglón 1', $tarjeta->presentacion['renglones'][0]['etiqueta']);
    }

    /**
     * Lo que ya está registrado aparece con su número en la propuesta y el aviso de stock va cuando no alcanza.
     *
     * @test
     */
    public function el_texto_dice_las_ya_registradas_y_el_stock_de_toda_la_tarjeta()
    {
        // Dos renglones del mismo artículo: de a uno alcanza, entre los dos no.
        Article::where('user_id', $this->dueno->id)->where('provider_code', 'wzz3324')->update(['stock' => 1]);

        $filas = $this->filas_de_prueba();
        $filas[0][3] = 'wzz3324';

        $lineas = array_map(function ($fila) {
            return implode("\t", $fila);
        }, $filas);

        $conversation = $this->conversacion();
        $planilla = $this->planilla_pegada($conversation, implode("\n", $lineas));

        $marcadas = $planilla->fresh()->filas;
        $marcadas[1]['estado'] = AiPlanilla::FILA_YA_REGISTRADA;
        $marcadas[1]['venta_numero'] = 777;
        $planilla->filas = $marcadas;
        $planilla->save();

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR);
        $assistant = $this->assistant($conversation);

        $this->definir_para_cargar($conversation, $assistant);

        $propuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->assertTrue($propuesta['ok'], json_encode($propuesta));

        $texto = AiMessageAction::find($propuesta['tarjeta_id'])->datos['texto'];

        $this->assertStringContainsString('Renglón 2, Bruno Prueba Dos: Venta N° 777', $texto);
        $this->assertStringContainsString('queda en negativo', $texto);
        $this->assertStringContainsString('3 ventas para cargar', $texto);
    }

    /**
     * 🔴 Por WhatsApp el texto no pasa del tope de Meta, y lo que no entra queda para la tarjeta siguiente.
     *
     * @test
     */
    public function por_whatsapp_el_texto_entra_en_un_mensaje()
    {
        $lineas = [];

        for ($i = 1; $i <= 30; $i++) {

            $lineas[] = implode("\t", [
                'C FINAL', '1.000,00', '13-oct', 'wzz3324', 'Descripcion larga del articulo vendido', '#20000000000' . (10000 + $i), '1', 'DNI', (string) (20000000 + $i), 'Cliente De Prueba Con Nombre Largo Numero ' . $i, 'Calle',
            ]);
        }

        $this->articulo_de_prueba('zz-c107 Un articulo con un nombre larguisimo que no entraria nunca en un renglon corto', ['provider_code' => 'LARGO-107']);

        $conversation = $this->conversacion();

        $this->del_dueno($conversation, implode("\n", $lineas), AiMessage::CANAL_WHATSAPP);

        $assistant = $this->assistant($conversation, AiMessage::CANAL_WHATSAPP);

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $assistant);

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR, AiMessage::CANAL_WHATSAPP);
        $assistant = $this->assistant($conversation, AiMessage::CANAL_WHATSAPP);

        $this->definir_para_cargar($conversation, $assistant);

        $propuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->assertTrue($propuesta['ok'], json_encode($propuesta));

        $tarjeta = AiMessageAction::find($propuesta['tarjeta_id']);

        $this->assertCount(PlanillaIaHelper::RENGLONES_POR_TARJETA_DE_TEXTO, $tarjeta->datos['renglones']);
        $this->assertLessThanOrEqual(3800, mb_strlen($tarjeta->datos['texto']));
        $this->assertStringContainsString('quedan 5 renglones más', $tarjeta->datos['texto']);
    }

    /**
     * Con muchas ya registradas, el texto las resume antes de pasarse del tope: los renglones a cargar nunca se sacan.
     *
     * @test
     */
    public function por_whatsapp_el_texto_resume_las_ya_registradas_antes_de_pasarse_del_tope()
    {
        $lineas = [];

        for ($i = 1; $i <= 60; $i++) {

            $lineas[] = implode("\t", ['C FINAL', '1.000,00', '13-oct', 'wzz3324', 'Algo', '#20000000000' . (10000 + $i), '1', 'DNI', (string) (20000000 + $i), 'Cliente De Prueba Con Nombre Bastante Largo Numero ' . $i, 'Calle']);
        }

        $conversation = $this->conversacion();

        $this->del_dueno($conversation, implode("\n", $lineas), AiMessage::CANAL_WHATSAPP);

        $assistant = $this->assistant($conversation, AiMessage::CANAL_WHATSAPP);

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $assistant);

        $planilla = AiPlanilla::where('ai_conversation_id', $conversation->id)->first();

        $marcadas = $planilla->filas;

        for ($i = 0; $i < 35; $i++) {
            $marcadas[$i]['estado'] = AiPlanilla::FILA_YA_REGISTRADA;
            $marcadas[$i]['venta_numero'] = 5000 + $i;
        }

        $planilla->filas = $marcadas;
        $planilla->save();

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR, AiMessage::CANAL_WHATSAPP);
        $assistant = $this->assistant($conversation, AiMessage::CANAL_WHATSAPP);

        $this->definir_para_cargar($conversation, $assistant);

        $propuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->assertTrue($propuesta['ok'], json_encode($propuesta));

        $tarjeta = AiMessageAction::find($propuesta['tarjeta_id']);

        $this->assertCount(25, $tarjeta->datos['renglones']);
        $this->assertLessThanOrEqual(3800, mb_strlen($tarjeta->datos['texto']));
        $this->assertStringContainsString('35 de esta planilla ya estaban registradas', $tarjeta->datos['texto']);
        $this->assertStringContainsString('36. Cliente De Prueba Con Nombre Bastante Largo Numero 36', $tarjeta->datos['texto']);
    }

    /**
     * Un modelo que repite los títulos como primera fila lee lo mismo que el que los manda aparte.
     *
     * @test
     */
    public function los_titulos_repetidos_como_fila_no_cuentan_como_una_lectura_distinta()
    {
        $encabezado = ['VENTA TOTAL', 'FECHA', 'CODIGO', 'DESCRIPCION', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'];

        $filas = [
            ['88.400,00', '13-oct', '70102', 'Bomba', '2', 'DNI', '14000002', 'Bruno Prueba Dos'],
            ['61.250,00', '13-oct', '70101', 'Otra bomba', '1', 'DNI', '17000001', 'Ana Prueba Uno'],
        ];

        $this->lecturas = [
            'claude-sonnet-5-5'  => $this->lectura($filas, $encabezado),
            'claude-haiku-5-5' => $this->lectura(array_merge([$encabezado], $filas), $encabezado),
        ];

        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '');
        $this->foto($pedido);

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

        $this->assertCount(0, $this->lecturas_de('claude-opus-5-5'), 'Coinciden: no hace falta desempate.');

        $planilla = AiPlanilla::where('ai_conversation_id', $conversation->id)->first();

        $this->assertCount(2, $planilla->filas);
        $this->assertSame(AiPlanilla::IMPORTE_TOTAL, $planilla->importe_es);
    }

    // ---------------------------------------------------------------------
    // Los tres canales ven lo mismo
    // ---------------------------------------------------------------------

    /**
     * Las cinco herramientas están en la lista común, al final, y el prompt lleva la regla.
     *
     * @test
     */
    public function las_herramientas_y_la_regla_estan_donde_tienen_que_estar()
    {
        $nombres = HerramientasDeCarga::nombres();

        foreach (['consultar_planilla', 'definir_planilla', 'corregir_renglon_de_planilla', 'proponer_ventas_de_planilla', 'leer_planilla_de_la_foto'] as $nombre) {
            $this->assertContains($nombre, $nombres);
        }

        $this->assertSame('volver_a_mirar_la_foto', $nombres[count($nombres) - 1]);
        $this->assertSame('leer_planilla_de_la_foto', $nombres[count($nombres) - 2]);

        $this->assertTrue(HerramientasDeCarga::es_de_carga('proponer_ventas_de_planilla'));
        $this->assertFalse(HerramientasDeCarga::es_de_carga('consultar_planilla'));

        $conversation = $this->conversacion();

        $prompt = (new AsistenteIaService())->build_system_prompt($conversation, $this->dueno->fresh(), true, true);

        $this->assertStringContainsString('PLANILLA YA LEÍDA', $prompt);
        $this->assertStringContainsString('proponer_ventas_de_planilla', $prompt);
        $this->assertStringContainsString('No hay planilla nueva sin una foto o una tabla nueva', $prompt);
    }

    /**
     * Borrar la conversación se lleva sus planillas: traen documentos y nombres.
     *
     * @test
     */
    public function borrar_la_conversacion_borra_sus_planillas()
    {
        $conversation = $this->conversacion();

        $this->planilla_pegada($conversation);

        $this->assertSame(1, AiPlanilla::where('ai_conversation_id', $conversation->id)->count());

        $this->deleteJson('api/ai-conversations/' . $conversation->id)->assertStatus(200);

        $this->assertSame(0, AiPlanilla::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * Apagar la lectura de planillas conserva el texto pegado y la transcripción de fotos con Haiku.
     *
     * @test
     */
    public function con_la_lectura_de_planillas_apagada_la_foto_sigue_por_haiku()
    {
        config(['services.lectura_planilla.activa' => false]);

        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, $this->texto_pegado());
        $foto = $this->foto($pedido, 300, 300);
        $assistant = $this->assistant($conversation);

        $this->responder($conversation, $assistant);

        $this->assertSame(0, AiPlanilla::where('ai_conversation_id', $conversation->id)->count());
        $this->assertNull($foto->fresh()->gestionada_at);

        $json = json_encode($this->requests_del_modelo_que_conversa()[0]['messages'], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Lectura transitoria con Haiku', $json);
        $this->assertStringContainsString('Ana Prueba Uno', $json);
        $this->assert_deepseek_conversa_sin_imagenes();
    }
}
