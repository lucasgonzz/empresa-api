<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\TextoFinalIaHelper;
use App\Http\Controllers\Helpers\sale\ArticlePurchaseHelper;
use App\Models\Article;
use App\Models\Provider;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-diario-20261118 — M3 (Ferre Total, conv 61, 17/11/2026).
 *
 * «Un listado de lo que vendí de Pinturería Sudamericana en 60 días, en una planilla Excel»: el asistente
 * entregó 20 renglones (el tope por defecto de la consulta) y dijo «son los primeros 20 de un total de 500
 * artículos»: el 500 era el catálogo del proveedor, no los que vendieron; y el texto de WhatsApp terminaba en
 * «(10 unidad», cortado por el máximo de salida del modelo. Lo que protege este archivo:
 *
 *  - la consulta de más vendidos por proveedor dice cuántos artículos TUVIERON movimiento y si hay más, y se
 *    puede pedir por páginas de hasta 100 (hasta 500 renglones, lo que entra en un Excel);
 *  - sin `limite` ni `pagina` el comportamiento es el de siempre (20);
 *  - una respuesta cortada por max_tokens se recorta en un salto de línea y avisa que sigue.
 *
 * El tope de llamadas por minuto de la misión fbc900da («Limitar herramientas de IA») no se toca: cinco páginas
 * son cinco llamadas, muy por debajo de su cupo.
 *
 * @group chat-ia
 */
class U181_Ventas_por_proveedor_completas_y_texto_sin_cortar_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'     => 'Comercio M3',
            'email'    => 'm3-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * Un proveedor con $cuantos artículos vendidos y uno más SIN ventas (el catálogo es más grande que lo vendido).
     */
    protected function proveedor_con_ventas($cuantos)
    {
        $proveedor = Provider::create(['name' => 'Pinturas Sudamericanas M3', 'user_id' => $this->comercio->id]);

        // Un artículo del proveedor que no vendió nada: cuenta para el catálogo, no para el movimiento.
        Article::create(['name' => 'zz-m3 sin movimiento', 'user_id' => $this->comercio->id, 'provider_id' => $proveedor->id]);

        for ($i = 1; $i <= $cuantos; $i++) {

            $articulo = Article::create([
                'name'        => 'zz-m3 articulo ' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'user_id'     => $this->comercio->id,
                'provider_id' => $proveedor->id,
            ]);

            $momento = now()->subDays(5);

            $venta = Sale::create(['user_id' => $this->comercio->id, 'moneda_id' => 1, 'created_at' => $momento]);
            // Más unidades al principio: el orden por unidades es estable y verificable.
            $venta->articles()->attach($articulo->id, ['amount' => 1000 - $i, 'price' => 10, 'cost' => 0]);

            (new ArticlePurchaseHelper())->set_article_purcase($venta->fresh());

            DB::table('article_purchases')->where('sale_id', $venta->id)->update(['created_at' => $momento]);
        }

        return $proveedor;
    }

    /**
     * Sin `limite` ni `pagina`: los 20 de siempre, pero ahora la respuesta dice que hay más y cuántos son.
     *
     * @test
     */
    public function por_defecto_trae_20_y_avisa_cuantos_tuvieron_movimiento()
    {
        $this->proveedor_con_ventas(45);

        $resultado = ConsultasSistemaIaHelper::mas_vendidos($this->comercio->id, 90, null, null, 0, 'Pinturas Sudamericanas M3');

        $this->assertArrayNotHasKey('error', $resultado, json_encode($resultado));
        $this->assertCount(20, $resultado['articulos']);
        $this->assertSame(45, $resultado['articulos_con_movimiento'], 'Son los que VENDIERON, no los 46 del catálogo.');
        $this->assertSame(46, $resultado['articulos_con_ese_proveedor']);
        $this->assertTrue($resultado['hay_mas']);
        $this->assertSame(1, $resultado['pagina']);
        $this->assertStringContainsString('pagina 2', $resultado['nota_de_paginacion']);
        $this->assertStringContainsString('exportar_excel', $resultado['nota_de_paginacion']);
    }

    /**
     * Con limite y paginas se recorren TODOS los que vendieron, sin repetir ni saltear, en orden de unidades.
     *
     * @test
     */
    public function las_paginas_cubren_todos_los_articulos_con_movimiento_sin_repetir()
    {
        $this->proveedor_con_ventas(45);

        $vistos = [];

        for ($pagina = 1; $pagina <= 3; $pagina++) {

            $resultado = ConsultasSistemaIaHelper::mas_vendidos($this->comercio->id, 90, null, null, 20, 'Pinturas Sudamericanas M3', $pagina);

            $this->assertSame($pagina < 3, $resultado['hay_mas'], 'Página ' . $pagina);

            foreach ($resultado['articulos'] as $fila) {
                $vistos[] = $fila['nombre'];
            }
        }

        $this->assertCount(45, $vistos);
        $this->assertCount(45, array_unique($vistos), 'Ningún artículo se repite entre páginas.');
        $this->assertSame('zz-m3 articulo 001', $vistos[0], 'El primero es el que más unidades vendió.');
        $this->assertSame('zz-m3 articulo 045', $vistos[44]);
    }

    /**
     * Con limite 100 entran de una vez más de 20, y la tool (el handler del loop) acepta limite y pagina.
     *
     * @test
     */
    public function la_tool_acepta_limite_y_pagina()
    {
        $this->proveedor_con_ventas(45);

        $servicio = new AsistenteIaService();
        $definicion = null;

        foreach ($servicio->herramientas_de_lectura() as $herramienta) {
            if ($herramienta['name'] === 'consultar_articulos_mas_vendidos') {
                $definicion = $herramienta;
            }
        }

        $this->assertNotNull($definicion);
        $this->assertSame('integer', $definicion['input_schema']['properties']['limite']['type']);
        $this->assertSame('integer', $definicion['input_schema']['properties']['pagina']['type']);
        $this->assertStringContainsString('hay_mas', $definicion['description']);
        $this->assertStringContainsString('articulos_con_movimiento', $definicion['description']);

        $metodo = new \ReflectionMethod(AsistenteIaService::class, 'handler_de_lectura');
        $metodo->setAccessible(true);
        $handler = $metodo->invoke($servicio, 'consultar_articulos_mas_vendidos');

        $todos = $handler(['dias' => 90, 'proveedor' => 'Pinturas Sudamericanas M3', 'limite' => 100], $this->comercio->id);

        $this->assertCount(45, $todos['articulos']);
        $this->assertFalse($todos['hay_mas']);
        $this->assertArrayNotHasKey('nota_de_paginacion', $todos);

        // Un límite pasado de rosca se acota al techo duro (100) y una página absurda a las 5 permitidas.
        $acotado = ConsultasSistemaIaHelper::mas_vendidos($this->comercio->id, 90, null, null, 5000, 'Pinturas Sudamericanas M3', 99);
        $this->assertSame(ConsultasSistemaIaHelper::PAGINAS_MAXIMAS_DE_MAS_VENDIDOS, $acotado['pagina']);
    }

    /**
     * Sin proveedor la salida sigue siendo la lista pelada (contrato del canal «sistema:» de admin-api).
     *
     * @test
     */
    public function sin_proveedor_la_salida_es_la_lista_pelada_de_siempre()
    {
        $this->proveedor_con_ventas(3);

        $resultado = ConsultasSistemaIaHelper::mas_vendidos($this->comercio->id, 90);

        $this->assertCount(3, $resultado);
        $this->assertArrayHasKey('nombre', $resultado[0]);
        $this->assertArrayNotHasKey('hay_mas', $resultado);
    }

    // ------------------------------------------------------------------ texto recortado

    /**
     * 🔴 El caso real: la respuesta terminaba en «(10 unidad». Se corta en el último salto de línea y avisa.
     *
     * @test
     */
    public function una_respuesta_cortada_se_recorta_en_el_ultimo_salto_de_linea_y_avisa()
    {
        $texto = "Usé *PINTURAS SUDAMERICANAS SRL*, el proveedor que nombraste.\n\n"
            . "ESMALTE AC. MULT.NEGRO X 1 LT. — 41 unidades — \$659.441,95\n"
            . "ENDUIDO INTERIOR X 1 L. — 22 — \$107.798,78\n\n"
            . "Los esmaltes lideran y la Membrana en pasta blanco x 20 L. es la que más plata facturó en pocas unidades (10 unidad";

        $recortado = TextoFinalIaHelper::recortar_sin_cortar_frases($texto);

        $this->assertStringNotContainsString('(10 unidad', $recortado);
        $this->assertStringContainsString('ENDUIDO INTERIOR X 1 L. — 22 — $107.798,78', $recortado);
        $this->assertStringEndsWith(TextoFinalIaHelper::AVISO_DE_RESPUESTA_RECORTADA, $recortado);
        $this->assertStringContainsString('«seguí»', $recortado);
    }

    /**
     * Un texto de una sola línea se corta en el último fin de oración; sin ninguno, queda entero con el aviso.
     *
     * @test
     */
    public function sin_saltos_de_linea_corta_en_el_ultimo_punto_o_deja_entero()
    {
        $una_linea = 'Primero te cuento una cosa importante sobre las ventas del mes. Después sigo con otra que quedó a med';

        $recortado = TextoFinalIaHelper::recortar_sin_cortar_frases($una_linea);

        $this->assertStringStartsWith('Primero te cuento una cosa importante sobre las ventas del mes.', $recortado);
        $this->assertStringNotContainsString('a med', $recortado);

        $sin_cortes = 'Un texto largo sin ningun punto ni salto de linea que se corto por la mitad de una pal';

        $this->assertStringStartsWith($sin_cortes, TextoFinalIaHelper::recortar_sin_cortar_frases($sin_cortes));
    }
}
