<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\AccionesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\BusquedaPorPalabrasIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaPresupuestoIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-altas-y-conversacion (16/10/2026) — D6: el asistente pierde el hilo.
 *
 * 🔴 LOS DOS CASOS REALES (prueba por WhatsApp contra demo3, 16/10/2026):
 *
 *  - Conv 11: "armame un combo con la ducha gloa y el flexible" → "no aparece ninguna ducha Gloa",
 *    con DUCHA DE MANO METAL GLOA en el catálogo. Todas las búsquedas de artículos hacían
 *    `name LIKE '%ducha gloa%'`: la frase entera, pegada.
 *  - Conv 9: el asistente creó el martillo por su código de barras y, en el mensaje siguiente
 *    ("llegaron 12… ponele la categoría"), pidió de nuevo el código porque "hay varios martillos
 *    parecidos". Los resultados de las herramientas no viajan entre mensajes, y la línea [Tarjeta …]
 *    del historial no decía qué registro se había creado.
 *
 * Lo que protege:
 *  - las palabras que importan (sin conectores, plural recortado) y que los comodines se escapan;
 *  - que la consulta de stock y el resolver de artículos de un presupuesto encuentren por palabras
 *    sueltas en cualquier orden, y NO traigan lo que tiene solo una de las palabras;
 *  - que la frase entera siga encontrando lo que encontraba antes;
 *  - que la línea de historial de un alta confirmada diga el id del registro (y la de una propuesta
 *    o una tarea, no), y que esa línea llegue al payload del modelo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Busqueda_por_palabras_e_historial_con_id_Test extends EmpresaTestCase
{
    /** @var User */
    protected $dueno;

    /** @var string|null */
    protected $confianza_original = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->confianza_original = $this->dueno->agente_confianza;

        foreach (['agentes_ia', PropuestaPresupuestoIaHelper::EXTENSION] as $slug) {

            $extencion = ExtencionEmpresa::where('slug', $slug)->first();

            if (is_null($extencion)) {
                $extencion = ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $slug]);
            }

            $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        }

        $this->dueno->load('extencions');
    }

    protected function tearDown(): void
    {
        /* El modo de confianza es un interruptor global de la cuenta: vuelve a como estaba (ver test 51). */
        User::where('id', $this->dueno->id)->update(['agente_confianza' => $this->confianza_original]);

        parent::tearDown();
    }

    protected function articulo_nuevo($nombre, $precio = 1000)
    {
        return Article::create([
            'name'        => $nombre,
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'final_price' => $precio,
            'cost'        => $precio / 2,
            'stock'       => 10,
            'iva_id'      => 2,
        ]);
    }

    protected function conversacion($pedido)
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $this->dueno->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => $pedido,
            'estado'             => 'listo',
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    protected function nombres(array $filas)
    {
        $nombres = [];

        foreach ($filas as $fila) {
            $nombres[] = $fila['nombre'];
        }

        sort($nombres);

        return $nombres;
    }

    // ---------------------------------------------------------------------
    // Las palabras
    // ---------------------------------------------------------------------

    /** @test */
    public function las_palabras_que_importan_salen_sin_conectores_y_con_el_plural_recortado()
    {
        $this->assertSame(['ducha', 'gloa'], BusquedaPorPalabrasIaHelper::palabras('duchas gloa'));
        $this->assertSame(['cinta', 'embalar'], BusquedaPorPalabrasIaHelper::palabras('cinta de embalar'));
        $this->assertSame(['martillo', 'tramontina'], BusquedaPorPalabrasIaHelper::palabras('los martillos Tramontina'));
        $this->assertSame(['pincel'], BusquedaPorPalabrasIaHelper::palabras('pinceles'));
        $this->assertSame(['llav', 'allen'], BusquedaPorPalabrasIaHelper::palabras('llaves allen'));

        // Un código no se toca, y lo que es solo conectores no deja ninguna palabra.
        $this->assertSame(['3m', '10mm'], BusquedaPorPalabrasIaHelper::palabras('3m 10mm'));
        $this->assertSame([], BusquedaPorPalabrasIaHelper::palabras('de la'));
    }

    // ---------------------------------------------------------------------
    // La consulta de stock (consultar_stock_de_articulos)
    // ---------------------------------------------------------------------

    /**
     * 🔴 El caso de demo3, tal cual: "ducha gloa" y "duchas gloa" encuentran DUCHA DE MANO METAL GLOA.
     *
     * @test
     */
    public function la_consulta_de_stock_encuentra_por_palabras_sueltas_en_cualquier_orden()
    {
        $this->articulo_nuevo('ZZP69 DUCHA DE MANO METAL GLOA');
        $this->articulo_nuevo('ZZP69 DUCHA ELECTRICA LORENZETTI');
        $this->articulo_nuevo('ZZP69 FLEXIBLE GLOA PARA DUCHA');

        $con_una = $this->nombres(ConsultasSistemaIaHelper::stock_de_articulos($this->dueno->id, 'zzp69 ducha gloa'));

        // Las dos que tienen "ducha" Y "gloa"; la Lorenzetti no tiene "gloa".
        $this->assertSame(['ZZP69 DUCHA DE MANO METAL GLOA', 'ZZP69 FLEXIBLE GLOA PARA DUCHA'], $con_una);

        $this->assertSame(
            ['ZZP69 DUCHA DE MANO METAL GLOA'],
            $this->nombres(ConsultasSistemaIaHelper::stock_de_articulos($this->dueno->id, 'zzp69 duchas de mano gloa'))
        );

        // Lo de antes sigue: la frase entera, pegada, encuentra lo mismo que encontraba.
        $this->assertSame(
            ['ZZP69 DUCHA ELECTRICA LORENZETTI'],
            $this->nombres(ConsultasSistemaIaHelper::stock_de_articulos($this->dueno->id, 'zzp69 ducha electrica'))
        );
    }

    /**
     * Los comodines del LIKE se escapan palabra por palabra: "50%" no es "todo lo que empieza con 50".
     *
     * @test
     */
    public function los_comodines_de_cada_palabra_se_escapan()
    {
        $this->articulo_nuevo('ZZP69 Cable 50% descuento');
        $this->articulo_nuevo('ZZP69 Cable 500 metros');
        $this->articulo_nuevo('ZZP69 Cable_2 mm');
        $this->articulo_nuevo('ZZP69 Cable 2x2');

        $this->assertSame(
            ['ZZP69 Cable 50% descuento'],
            $this->nombres(ConsultasSistemaIaHelper::stock_de_articulos($this->dueno->id, 'zzp69 cable 50%'))
        );

        $this->assertSame(
            ['ZZP69 Cable_2 mm'],
            $this->nombres(ConsultasSistemaIaHelper::stock_de_articulos($this->dueno->id, 'zzp69 cable_2'))
        );
    }

    // ---------------------------------------------------------------------
    // El resolver de artículos de una carga (presupuesto)
    // ---------------------------------------------------------------------

    /**
     * 🔴 "3 duchas gloa" en un presupuesto: un solo artículo encaja y se toma sin preguntar.
     *
     * @test
     */
    public function el_presupuesto_resuelve_el_articulo_por_palabras_sin_pedir_que_lo_confirme()
    {
        User::where('id', $this->dueno->id)->update(['agente_confianza' => ConfianzaDelAgenteIaHelper::CAUTELOSO]);

        $ducha = $this->articulo_nuevo('ZZP69 DUCHA DE MANO METAL GLOA', 22415.01);
        $this->articulo_nuevo('ZZP69 DUCHA ELECTRICA LORENZETTI');

        list($conversation, $assistant) = $this->conversacion('armame un presupuesto de 3 duchas gloa');

        $resultado = HerramientasDeCarga::ejecutar('proponer_presupuesto', [
            'items'   => [['articulo' => 'zzp69 duchas gloa', 'cantidad' => 3]],
            'cliente' => TestingFerreteriaSeeder::CLIENTE_CONTADO,
        ], $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], $resultado['content']);

        $respuesta = json_decode($resultado['content'], true);

        $this->assertTrue(!empty($respuesta['ok']), 'No armó el presupuesto: ' . $resultado['content']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $renglones = $tarjeta->datos['payload']['articles'];

        $this->assertCount(1, $renglones);
        $this->assertSame((int) $ducha->id, (int) $renglones[0]['id']);
    }

    /**
     * 🔴 Chequeo independiente: lo que encaja SOLO por palabras no se elige sin preguntar si un número
     * del pedido no es una medida entera del nombre. "pintura blanca 4 litros" con solo la de 1 litro
     * (y un 4 en su código de barras) tiene que preguntar, no armar el presupuesto con la de 1 litro.
     *
     * @test
     */
    public function lo_que_encaja_solo_por_palabras_con_otro_numero_se_pregunta()
    {
        User::where('id', $this->dueno->id)->update(['agente_confianza' => ConfianzaDelAgenteIaHelper::CAUTELOSO]);

        $una = $this->articulo_nuevo('ZZP69 PINTURA BLANCA 1/4 LITRO');
        $una->bar_code = '7790444000444';
        $una->save();

        list($conversation, $assistant) = $this->conversacion('presupuesto de 2 pintura blanca 4 litros');

        $resultado = HerramientasDeCarga::ejecutar('proponer_presupuesto', [
            'items'   => [['articulo' => 'zzp69 pintura blanca 4 litros', 'cantidad' => 2]],
            'cliente' => TestingFerreteriaSeeder::CLIENTE_CONTADO,
        ], $conversation, $assistant);

        $respuesta = json_decode($resultado['content'], true);

        $this->assertTrue(empty($respuesta['ok']), 'Eligió solo un artículo con otra medida: ' . $resultado['content']);
        $this->assertNotEmpty($respuesta['faltan']);
        $this->assertSame((int) $una->id, (int) $respuesta['opciones']['articulos'][0]['articulo_id']);

        // Y las reglas de es_seguro, una por una.
        $this->assertTrue(BusquedaPorPalabrasIaHelper::es_seguro('DUCHA DE MANO METAL GLOA', 'duchas gloa'));
        $this->assertTrue(BusquedaPorPalabrasIaHelper::es_seguro('PINTURA BLANCA 4 LITROS', 'pintura blanca 4 litros'));
        $this->assertFalse(BusquedaPorPalabrasIaHelper::es_seguro('PINTURA BLANCA 1/4 LITRO', 'pintura blanca 4 litros'));
        $this->assertFalse(BusquedaPorPalabrasIaHelper::es_seguro('CABLE 40 METROS', 'cable 4'));
        $this->assertTrue(BusquedaPorPalabrasIaHelper::es_seguro('CAÑO 1/2 PULGADA', 'caños 1/2'));
    }

    /**
     * Las palabras sueltas se buscan solo en el NOMBRE: los códigos, por la frase entera.
     *
     * @test
     */
    public function las_palabras_sueltas_no_se_buscan_en_los_codigos()
    {
        $con_codigo = $this->articulo_nuevo('ZZP69 LLAVE FRANCESA');
        $con_codigo->bar_code = 'ZZP69-ALLEN-001';
        $con_codigo->save();

        $this->assertSame(
            [],
            $this->nombres(ConsultasSistemaIaHelper::stock_de_articulos($this->dueno->id, 'zzp69 allen francesa'))
        );

        // La frase entera en el código, como siempre.
        $this->assertSame(
            ['ZZP69 LLAVE FRANCESA'],
            $this->nombres(ConsultasSistemaIaHelper::stock_de_articulos($this->dueno->id, 'ZZP69-ALLEN-001'))
        );
    }

    // ---------------------------------------------------------------------
    // La línea de historial
    // ---------------------------------------------------------------------

    protected function tarjeta(AiConversation $conversation, AiMessage $mensaje, $tipo, $estado, $resultado)
    {
        return AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $mensaje->id,
            'user_id'            => $this->dueno->id,
            'auth_user_id'       => $this->dueno->id,
            'tipo'               => $tipo,
            'clave'              => 'zzp69-' . uniqid(),
            'estado'             => $estado,
            'datos'              => [],
            'presentacion'       => [
                'titulo'    => 'Alta de artículo',
                'renglones' => [['etiqueta' => 'Nombre', 'valor' => 'Martillo de uña 18 mm Tramontina']],
            ],
            'resultado'          => $resultado,
        ]);
    }

    /**
     * 🔴 El caso del martillo: la línea del alta confirmada dice el id del artículo que se creó.
     *
     * @test
     */
    public function la_linea_de_un_alta_confirmada_dice_el_id_del_registro()
    {
        list($conversation, $assistant) = $this->conversacion('me llegaron estos martillos, cargalo');

        $confirmada = $this->tarjeta($conversation, $assistant, AiMessageAction::TIPO_ALTA, AiMessageAction::ESTADO_CONFIRMADA, [
            'texto'   => 'Artículo Martillo de uña 18 mm Tramontina creado, con la foto y la descripción',
            'entidad' => 'article',
            'id'      => 48,
        ]);

        $linea = AccionesIaHelper::linea_de_historial($confirmada->fresh());

        $this->assertStringContainsString('article_id: 48', $linea);
        $this->assertStringContainsString('estado: confirmada (Artículo Martillo', $linea);

        // Una propuesta todavía no creó nada: no hay id que dar.
        $propuesta = $this->tarjeta($conversation, $assistant, AiMessageAction::TIPO_ALTA, AiMessageAction::ESTADO_PROPUESTA, null);

        $this->assertStringNotContainsString('_id:', AccionesIaHelper::linea_de_historial($propuesta->fresh()));

        // Un resultado sin entidad (una venta, un gasto) queda como siempre.
        $venta = $this->tarjeta($conversation, $assistant, AiMessageAction::TIPO_VENTA, AiMessageAction::ESTADO_CONFIRMADA, [
            'texto'    => 'Venta N° 337 registrada',
            'venta_id' => 999,
        ]);

        $this->assertStringNotContainsString('_id:', AccionesIaHelper::linea_de_historial($venta->fresh()));

        // 🔴 Una BAJA confirmada también trae entidad e id, y no se da: es un registro borrado.
        $baja = $this->tarjeta($conversation, $assistant, AiMessageAction::TIPO_BAJA, AiMessageAction::ESTADO_CONFIRMADA, [
            'texto'   => 'Proveedor Acme borrado',
            'entidad' => 'provider',
            'id'      => 55,
        ]);

        $this->assertStringNotContainsString('provider_id', AccionesIaHelper::linea_de_historial($baja->fresh()));
    }

    /**
     * Y esa línea es lo que el modelo lee en el mensaje siguiente: viaja en el payload.
     *
     * @test
     */
    public function el_id_del_registro_creado_llega_al_payload_del_mensaje_siguiente()
    {
        list($conversation, $assistant) = $this->conversacion('me llegaron estos martillos, cargalo');

        $assistant->contenido = 'Quedó creado el artículo "Martillo de uña 18 mm Tramontina", con la foto y la descripción.';
        $assistant->estado = 'listo';
        $assistant->save();

        $this->tarjeta($conversation, $assistant, AiMessageAction::TIPO_ALTA, AiMessageAction::ESTADO_CONFIRMADA, [
            'texto'   => 'Artículo Martillo de uña 18 mm Tramontina creado',
            'entidad' => 'article',
            'id'      => 48,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'llegaron 12 a la sucursal 1, me costaron 9000 cada uno. y ponele la categoria q corresponda',
            'estado'             => 'listo',
        ]);

        $payload = (new AsistenteIaService())->build_messages_payload($conversation);

        $del_asistente = '';

        foreach ($payload as $turno) {
            if ($turno['role'] === 'assistant') {
                $del_asistente .= is_string($turno['content']) ? $turno['content'] : json_encode($turno['content']);
            }
        }

        $this->assertStringContainsString('article_id: 48', $del_asistente);
    }

    /**
     * El prompt le dice qué hacer con ese id.
     *
     * @test
     */
    public function el_prompt_dice_que_lo_recien_creado_se_usa_por_su_id()
    {
        list($conversation) = $this->conversacion('hola');

        $prompt = (new AsistenteIaService())->build_system_prompt($conversation, $this->dueno->fresh(), true);

        $this->assertStringContainsString('Lo que acabás de crear o tocar en esta conversación', $prompt);
        $this->assertStringContainsString('usá ese id (en `registro` para', $prompt);
    }
}
