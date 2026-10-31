<?php
namespace Tests\Feature\DimensionSearch;

use App\Models\DimensionSearchItem;
use App\Models\SerperUsageEvent;
use App\Services\ProductFacts\SerperLinkResolver;
use Illuminate\Support\Facades\Http;

class EnlacesSerperTest extends DimensionSearchTestCase
{
    protected $resueltos = 0;

    protected function fuentes($directas, $destino = 'https://resuelta.test/producto', $fallo = false)
    {
        $results = [];
        for ($i = 0; $i < $directas; $i++) { $results[] = $this->resultado('https://directa'.$i.'.test/producto'); }
        $results[] = $this->resultado('https://www.google.com/goto?url=opaco');
        Http::fake(function ($r) use ($results, $destino, $fallo) {
            if ($r->url() === 'https://google.serper.dev/search') { return Http::response(['organic' => $results, 'credits' => 1]); }
            if ($r->url() === SerperLinkResolver::URL) {
                $this->resueltos++;
                if ($fallo) { return Http::response([], 429); }
                $resolved = $results;
                $resolved[count($resolved) - 1]['link'] = $destino;
                return Http::response(['organic' => $resolved]);
            }
            if (strpos($r->url(), 'ia.test') !== false) {
                $this->pedidos_ia[] = json_decode($r['messages'][0]['content'], true);
                return Http::response(['content' => [['type' => 'text', 'text' => json_encode($this->ficha('otro_producto'))]],
                    'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);
            }
            $this->paginas_pedidas[] = $r->url();
            return Http::response($this->pagina('Producto', 'Peso 5 kg. Producto de prueba.'), 200, ['Content-Type' => 'text/html']);
        });
    }

    public function test_tres_documentos_directos_no_necesitan_resolucion()
    {
        $this->fuentes(3);
        $this->buscar($this->nuevo_articulo('PRODUCTO DE PRUEBA'));
        $this->assertSame(0, $this->resueltos);
        $this->assertCount(3, $this->paginas_pedidas);
    }

    public function test_la_fuente_resuelta_se_lee_sin_pasar_goto_al_lector()
    {
        $this->fuentes(0);
        $this->buscar($this->nuevo_articulo('PRODUCTO DE PRUEBA'));
        $this->assertSame(1, $this->resueltos);
        $this->assertSame(['https://resuelta.test/producto'], $this->paginas_pedidas);
        $this->assertSame('https://resuelta.test/producto', $this->pedidos_ia[0]['documents'][0]['url']);
        $event = SerperUsageEvent::where('user_id', $this->owner->id)->where('process', SerperLinkResolver::PROCESO)->firstOrFail();
        $this->assertEquals(0, $event->credits);
    }

    public function test_la_resolucion_fallida_deja_el_articulo_reintentable()
    {
        $this->fuentes(0, '', true);
        list($item, $result) = $this->buscar($this->nuevo_articulo('PRODUCTO DE PRUEBA'));
        $this->assertSame(DimensionSearchItem::STATUS_SIN_PROCESAR, $item->status);
        $this->assertSame('error_de_busqueda', $item->motivo);
        $this->assertTrue($result['error_de_proveedor']);
        $this->assertSame([], $this->paginas_pedidas);
        $this->assertSame([], $this->pedidos_ia);
    }

    public function test_un_pdf_privado_resuelto_no_llega_al_scrape_pago()
    {
        $this->fuentes(0, 'http://127.0.0.1/privado.pdf');
        $this->buscar($this->nuevo_articulo('PRODUCTO DE PRUEBA'));
        $this->assertSame([], $this->paginas_pedidas);
        Http::assertNotSent(function ($r) { return strpos($r->url(), 'scrape.serper.dev') !== false; });
    }
    public function test_los_planes_comparten_cuarenta_segundos_de_resolucion()
    {
        $article = $this->nuevo_articulo('PRODUCTO DE PRUEBA');
        $engine = new MotorConRelojDeResolucion($this->corrida([$article]), $this->owner);
        $resolver = new ResolucionQueConsumeTiempo($engine);
        app()->instance(SerperLinkResolver::class, $resolver);
        $engine->intentar_fuentes();
        $engine->intentar_fuentes();
        $engine->intentar_fuentes();
        $this->assertSame([140, 140], $resolver->limites);
        $this->assertSame(0, $engine->presupuesto());
    }

}


class MotorConRelojDeResolucion extends \App\Services\DimensionSearch\ArticleDimensionSearchEngine
{
    public $reloj = 100;
    protected function ahora_para_resolucion() { return $this->reloj; }
    public function presupuesto() { return $this->segundos_resolucion_restantes; }
    public function intentar_fuentes()
    {
        $this->ultima_respuesta_serper = ['organic' => [['link' => 'https://google.com/goto?url=opaco']]];
        iterator_to_array($this->fuentes_directas_y_resueltas($this->ultima_respuesta_serper['organic'], function () { return true; }));
    }
}

class ResolucionQueConsumeTiempo extends SerperLinkResolver
{
    public $limites = [];
    protected $motor;
    public function __construct($motor) { $this->motor = $motor; }
    public function resolver(array $body, $key, array $context, $deadline)
    {
        $this->limites[] = $deadline;
        $this->motor->reloj = min($deadline, $this->motor->reloj + 30);
        return $body;
    }
}
