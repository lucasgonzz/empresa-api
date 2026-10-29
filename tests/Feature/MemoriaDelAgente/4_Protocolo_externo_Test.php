<?php

namespace Tests\Feature\MemoriaDelAgente;

use App\Http\Controllers\Helpers\asistente_ia\AgenteContextoHelper;
use App\Models\AgenteContextoArchivo;
use App\Services\AsistenteIa\HerramientasDeContexto;
use Tests\Feature\Mcp\McpTestCase;

/**
 * Misión app-ia-y-memoria (E2) — la memoria por el protocolo externo (el servidor MCP).
 *
 * Protege: que un cliente de afuera vea leer_contexto y guardar_en_contexto al final de tools/list
 * (con las pistas correctas: leer es de solo lectura) y que tools/call pase por el mismo despacho que
 * el loop interno, escribiendo y leyendo la memoria del DUEÑO de la clave.
 */
class Protocolo_externo_Test extends McpTestCase
{
    /** @test */
    public function las_dos_herramientas_van_al_final_de_la_lista()
    {
        $tools = $this->rpc('tools/list', [], 1)->assertStatus(200)->json('result.tools');

        $nombres = array_column($tools, 'name');

        $this->assertSame(HerramientasDeContexto::nombres(), array_slice($nombres, -2));

        $por_nombre = [];
        foreach ($tools as $tool) {
            $por_nombre[$tool['name']] = $tool;
        }

        $this->assertTrue($por_nombre['leer_contexto']['annotations']['readOnlyHint'], 'Leer la memoria no escribe nada.');
        $this->assertFalse($por_nombre['guardar_en_contexto']['annotations']['readOnlyHint']);
        $this->assertFalse($por_nombre['guardar_en_contexto']['annotations']['openWorldHint']);
    }

    /** @test */
    public function guardar_y_leer_por_el_protocolo_usan_la_memoria_del_dueno()
    {
        $sesion = $this->inicializar();

        $guardado = $this->estructurado($this->tool('guardar_en_contexto', [
            'ruta'      => 'areas/clientes_y_cobranzas.md',
            'operacion' => 'agregar',
            'contenido' => '- A los pintores les fía hasta 30 días.',
            'motivo'    => 'Lo contó el dueño desde Claude',
        ], $sesion));

        $this->assertTrue($guardado['ok']);

        $this->assertStringContainsString('les fía hasta 30 días', AgenteContextoHelper::leer($this->dueno, 'areas/clientes_y_cobranzas.md')['contenido']);

        $leido = $this->estructurado($this->tool('leer_contexto', ['ruta' => 'areas/clientes_y_cobranzas.md'], $sesion, 2));

        $this->assertStringContainsString('les fía hasta 30 días', $leido['contenido']);

        // El otro dueño de la base no se enteró de nada.
        $otro = $this->otro_dueno(true);
        $this->assertSame(0, AgenteContextoArchivo::where('user_id', $otro->id)->count());
    }
}
