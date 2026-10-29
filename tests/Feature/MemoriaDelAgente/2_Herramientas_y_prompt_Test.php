<?php

namespace Tests\Feature\MemoriaDelAgente;

use App\Http\Controllers\Helpers\asistente_ia\AgenteContextoHelper;
use App\Models\AgenteContextoArchivo;
use App\Models\AgenteContextoRevision;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use App\Services\AsistenteIa\HerramientasDeContexto;
use Illuminate\Support\Facades\Http;

/**
 * Misión app-ia-y-memoria (E2) — la memoria adentro del agente.
 *
 * Protege: que leer_contexto y guardar_en_contexto viajen y se ejecuten por el loop de siempre (con un
 * proveedor falso), con el dueño sacado de la conversación; que el bloque de la memoria vaya DESPUÉS
 * del bloque cacheado y sin cache_control, en los canales sistema, WhatsApp y app; que las reglas
 * («lo calculable no se guarda») vayan en el bloque cacheado; y que sin la extensión no cambie nada de
 * lo de antes (ni el system ni las tools).
 */
class Herramientas_y_prompt_Test extends MemoriaTestCase
{
    /** @var AsistenteIaService */
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AsistenteIaService();
    }

    /**
     * El texto completo del system de un request grabado.
     *
     * @param  array  $system
     * @return string
     */
    protected function texto_del_system(array $system)
    {
        return implode("\n", array_column($system, 'text'));
    }

    /** @test */
    public function el_bloque_de_memoria_va_despues_del_cacheado_en_sistema_whatsapp_y_app()
    {
        AgenteContextoHelper::guardar($this->dueno, 'contexto/contexto_maestro.md', 'agregar', '- Se llama Raúl.', null, 'm', 'agente', null, 'Quién es el dueño');

        foreach (['sistema' => false, 'whatsapp' => true, 'app' => false] as $canal => $es_whatsapp) {

            list($conversation) = $this->conversacion_con_pendiente($canal);

            $system = $this->service->build_system_payload($conversation, $this->dueno, false, $es_whatsapp);

            $this->assertCount(2, $system, 'Canal ' . $canal . ': el cacheado y la memoria.');
            $this->assertEquals(['type' => 'ephemeral'], $system[0]['cache_control']);
            $this->assertStringContainsString('guardar_en_contexto', $system[0]['text'], 'Canal ' . $canal . ': las reglas van en el bloque cacheado (son fijas).');
            $this->assertStringContainsString('Lo calculable NO se guarda nunca', $system[0]['text']);
            $this->assertStringNotContainsString('Se llama Raúl', $system[0]['text'], 'Canal ' . $canal . ': la memoria NO va en el cacheado (cambia).');

            $this->assertArrayNotHasKey('cache_control', $system[1], 'Canal ' . $canal . ': la memoria va sin cache_control.');
            $this->assertStringContainsString('=== contexto/contexto_maestro.md', $system[1]['text']);
            $this->assertStringContainsString('=== contexto/INDICE.md', $system[1]['text']);
            $this->assertStringContainsString('Se llama Raúl', $system[1]['text']);
        }

        // Con el contexto de fondo de una sugerencia, la memoria va antes y el contexto al final.
        list($con_contexto) = $this->conversacion_con_pendiente('sistema');
        $con_contexto->contexto = 'Datos ya calculados: 3 líneas';
        $con_contexto->save();

        $system = $this->service->build_system_payload($con_contexto, $this->dueno);

        $this->assertCount(3, $system);
        $this->assertStringContainsString('=== contexto/contexto_maestro.md', $system[1]['text']);
        $this->assertStringStartsWith('Contexto de fondo de esta conversación', $system[2]['text']);
    }

    /** @test */
    public function el_bloque_cacheado_no_cambia_cuando_cambia_la_memoria()
    {
        list($conversation) = $this->conversacion_con_pendiente('sistema');

        $antes = $this->service->build_system_payload($conversation, $this->dueno);

        AgenteContextoHelper::guardar($this->dueno, 'areas/stock.md', 'agregar', '- La lavandina no puede faltar.', 'Lo que no puede faltar.', 'm', 'agente');

        $despues = $this->service->build_system_payload($conversation, $this->dueno);

        $this->assertSame($antes[0]['text'], $despues[0]['text'], 'El prefijo cacheado es el mismo: el caché sigue pegando.');
        $this->assertNotSame($antes[1]['text'], $despues[1]['text'], 'El que cambió es el bloque de la memoria (el índice).');
    }

    /** @test */
    public function sin_la_extension_no_cambia_nada()
    {
        $sin = $this->nuevo_dueno('Sin agentes', false);

        list($conversation) = $this->conversacion_con_pendiente('sistema', 'Hola', $sin);

        $system = $this->service->build_system_payload($conversation, $sin);

        $this->assertCount(1, $system);
        $this->assertStringNotContainsString('guardar_en_contexto', $system[0]['text']);

        $nombres = array_column($this->service->build_tools(false, false, $sin), 'name');

        $this->assertNotContains('leer_contexto', $nombres);
        $this->assertSame($this->service->nombres_de_lectura(), $nombres, 'Las tools de siempre, en su orden.');
        $this->assertSame(0, AgenteContextoArchivo::where('user_id', $sin->id)->count(), 'Sin la extensión no se crea memoria.');

        // Y si el modelo igual llamara a una, contesta sin escribir.
        $resultado = $this->service->execute_tool_calls([[
            'type' => 'tool_use', 'id' => 'toolu_x', 'name' => 'guardar_en_contexto',
            'input' => ['ruta' => 'areas/stock.md', 'operacion' => 'agregar', 'contenido' => 'x', 'motivo' => 'm'],
        ]], $conversation);

        $this->assertStringContainsString('no la tiene activa', $resultado[0]['content']);
        $this->assertSame(0, AgenteContextoArchivo::where('user_id', $sin->id)->count());
    }

    /** @test */
    public function las_dos_herramientas_van_al_final_en_todos_los_canales_con_la_extension()
    {
        $lectura = $this->service->nombres_de_lectura();

        $sistema = array_column($this->service->build_tools(false, false, $this->dueno), 'name');
        $this->assertSame(array_merge($lectura, HerramientasDeContexto::nombres()), $sistema, 'Al final: el prefijo de las de antes no cambia.');

        $con_acciones = array_column($this->service->build_tools(true, false, $this->dueno), 'name');
        $this->assertSame(array_merge($lectura, HerramientasDeCarga::nombres(), HerramientasDeContexto::nombres()), $con_acciones);

        $whatsapp = array_column($this->service->build_tools(true, true, $this->dueno), 'name');
        $this->assertSame(array_merge($lectura, HerramientasDeCarga::nombres(true), HerramientasDeContexto::nombres()), $whatsapp);

        // El marcador de caché queda en la última, como siempre.
        $tools = $this->service->build_tools(false, false, $this->dueno);
        $this->assertEquals(['type' => 'ephemeral'], $tools[count($tools) - 1]['cache_control']);
        $this->assertSame('guardar_en_contexto', $tools[count($tools) - 1]['name']);

        // Sin dueño (los llamadores de antes) siguen viajando las de siempre.
        $this->assertSame($lectura, array_column($this->service->build_tools(), 'name'));
    }

    /** @test */
    public function el_loop_guarda_con_la_herramienta_y_deja_la_conversacion_en_la_revision()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->tool_use('guardar_en_contexto', [
                    'ruta'      => 'areas/compras_y_proveedores.md',
                    'operacion' => 'agregar',
                    'contenido' => '- La pintura se la compra a Pinturerías Rex porque le da 60 días.',
                    'resumen'   => 'Proveedores clave.',
                    'motivo'    => 'El dueño contó con quién trabaja la pintura',
                ]), 200)
                ->push($this->end_turn('Me lo anoto.'), 200),
        ]);

        list($conversation, $assistant) = $this->conversacion_con_pendiente('whatsapp', 'La pintura se la compro a Rex porque me da 60 días');

        $texto = $this->service->responder($conversation, $assistant);

        $this->assertSame('Me lo anoto.', $texto);

        $archivo = AgenteContextoArchivo::where('user_id', $this->dueno->id)->where('ruta', 'areas/compras_y_proveedores.md')->first();

        $this->assertStringContainsString('Pinturerías Rex', $archivo->contenido);
        $this->assertSame('agente', $archivo->origen);

        $revision = AgenteContextoRevision::where('archivo_id', $archivo->id)->orderByDesc('version')->first();
        $this->assertSame($conversation->id, $revision->ai_conversation_id);

        // El primer request ya llevaba las dos tools y la memoria.
        $primero = Http::recorded()[0][0]->data();

        $this->assertContains('leer_contexto', array_column($primero['tools'], 'name'));
        $this->assertContains('guardar_en_contexto', array_column($primero['tools'], 'name'));
        $this->assertStringContainsString('=== contexto/INDICE.md', $this->texto_del_system($primero['system']));

        // Y el tool_result que volvió al modelo dice que se guardó.
        $segundo = Http::recorded()[1][0]->data();
        $ultimo  = $segundo['messages'][count($segundo['messages']) - 1];
        $this->assertSame('tool_result', $ultimo['content'][0]['type']);
        $this->assertStringContainsString('"ok":true', $ultimo['content'][0]['content']);
    }

    /**
     * 🔴 «Me lo anoto.» no lo puede confundir la guarda de cargas prometidas con una carga hecha: en un
     * turno con acciones lo cambiaría por un «no pude cargarlo». Es la frase que piden las reglas y la
     * nota de guardar_en_contexto; «anotado» o «guardado» sí la disparan, y por eso no se piden.
     *
     * @test
     */
    public function me_lo_anoto_pasa_la_guarda_de_cargas_en_un_turno_con_acciones()
    {
        $this->assertSame('', \App\Http\Controllers\Helpers\asistente_ia\TarjetaPrometidaIaHelper::afirma_una_carga_hecha('Me lo anoto.'));

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->tool_use('guardar_en_contexto', [
                    'ruta'      => 'aprendizajes/correcciones.md',
                    'operacion' => 'agregar',
                    'contenido' => '- No le digas "jefe": se llama Raúl.',
                    'motivo'    => 'Me corrigió cómo llamarlo',
                ]), 200)
                ->push($this->end_turn('Me lo anoto.'), 200),
        ]);

        list($conversation, $assistant) = $this->conversacion_con_pendiente('sistema', 'No me digas jefe, decime Raúl', null, true);

        $this->assertSame('Me lo anoto.', $this->service->responder($conversation, $assistant));
        $this->assertCount(2, Http::recorded(), 'Sin reintento de la guarda: dos vueltas y listo.');
        $this->assertStringContainsString('se llama Raúl', AgenteContextoHelper::leer($this->dueno, 'aprendizajes/correcciones.md')['contenido']);
    }

    /** @test */
    public function el_loop_lee_con_la_herramienta_lo_que_se_guardo_en_otra_conversacion()
    {
        AgenteContextoHelper::guardar($this->dueno, 'decisiones/decisiones.md', 'agregar', '- No trabaja más con Distribuidora Norte (29/10/2026).', null, 'm', 'agente');

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->tool_use('leer_contexto', ['ruta' => 'decisiones/decisiones.md']), 200)
                ->push($this->end_turn('Ya no le comprás a Distribuidora Norte.'), 200),
        ]);

        list($conversation, $assistant) = $this->conversacion_con_pendiente('app', '¿Le sigo comprando a Norte?');

        $this->service->responder($conversation, $assistant);

        $segundo = Http::recorded()[1][0]->data();
        $ultimo  = $segundo['messages'][count($segundo['messages']) - 1];

        $this->assertArrayNotHasKey('is_error', $ultimo['content'][0]);
        $this->assertStringContainsString('Distribuidora Norte', $ultimo['content'][0]['content']);
    }

    /** @test */
    public function leer_una_ruta_que_no_existe_devuelve_las_que_hay_y_sin_ruta_el_indice()
    {
        list($conversation) = $this->conversacion_con_pendiente('sistema');

        $resultados = $this->service->execute_tool_calls([
            ['type' => 'tool_use', 'id' => 't1', 'name' => 'leer_contexto', 'input' => ['ruta' => 'areas/no_existe.md']],
            ['type' => 'tool_use', 'id' => 't2', 'name' => 'leer_contexto', 'input' => ['ruta' => '']],
            ['type' => 'tool_use', 'id' => 't3', 'name' => 'guardar_en_contexto', 'input' => ['ruta' => 'contexto/INDICE.md', 'operacion' => 'reemplazar', 'contenido' => 'x', 'motivo' => 'm']],
        ], $conversation);

        $no_existe = json_decode($resultados[0]['content'], true);
        $this->assertArrayHasKey('error', $no_existe);
        $this->assertContains('areas/stock.md', $no_existe['rutas_disponibles']);

        $indice = json_decode($resultados[1]['content'], true);
        $this->assertSame('contexto/INDICE.md', $indice['ruta']);

        $generado = json_decode($resultados[2]['content'], true);
        $this->assertFalse($generado['ok']);
        $this->assertSame('archivo_generado', $generado['code']);

        foreach ($resultados as $resultado) {
            $this->assertArrayNotHasKey('is_error', $resultado, 'Lo que no se pudo hacer viaja como datos, no como falla técnica.');
        }
    }
}
