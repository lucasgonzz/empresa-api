<?php
namespace Tests\Feature\ProductFacts;

use App\Models\User;
use App\Services\ProductFacts\ProductFactsResearchService;
use App\Services\ProductFacts\ProductFactsException;
use App\Services\ProductFacts\SerperLinkResolver;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

class ProductFactsSerperLinksTest extends EmpresaTestCase
{
    protected function service($directa = false, $primera_descartada = false, $destino = 'https://fabricante.test/producto', $fallo = false)
    {
        config(['services.serper.api_key' => 'CLAVE-FALSA']);
        $owner = User::create(['name' => 'Dueño', 'email' => uniqid().'@fuentes.test', 'password' => 'sin-acceso']);
        $body = ['organic' => [['title' => 'Fuente', 'link' => $directa ? 'https://directa.test/producto' : 'https://www.google.com/goto?url=opaco']]];
        if ($directa) {
            $body['organic'][] = ['title' => 'Directa dos', 'link' => 'https://directa2.test/producto'];
            $body['organic'][] = ['title' => 'Directa tres', 'link' => 'https://directa3.test/producto'];
            $body['organic'][] = ['title' => 'Cuarta fuente', 'link' => 'https://www.google.com/goto?url=opaco'];
        }
        Http::fake(function ($request) use ($body, $destino, $fallo) {
            if ($request->url() === 'https://google.serper.dev/search') { return Http::response($body); }
            if ($request->url() === SerperLinkResolver::URL) {
                if ($fallo) { return Http::response([], 429); }
                $resolved = $body;
                foreach ($resolved['organic'] as &$row) { if (SerperLinkResolver::pendiente($row['link'])) { $row['link'] = $destino; } }
                return Http::response($resolved);
            }
            return Http::response('<html><p>GTIN 7791234567898</p></html>', 200, ['Content-Type' => 'text/html']);
        });
        $service = new ResearchLinksConDns($owner);
        $service->descartar_primera = $primera_descartada;
        return $service;
    }

    public function test_una_ficha_directa_valida_no_resuelve_otras_fuentes()
    {
        $service = $this->service(true);
        $product = $service->research('07791234567898');
        $this->assertSame('Producto verificado', $product['name']);
        Http::assertNotSent(function ($r) { return $r->url() === SerperLinkResolver::URL; });
    }

    public function test_usa_la_fuente_resuelta_cuando_no_hay_directas()
    {
        $product = $this->service()->research('07791234567898');
        $this->assertSame('https://fabricante.test/producto', $product['sources'][0]['url']);
        Http::assertNotSent(function ($r) { return SerperLinkResolver::pendiente($r->url()); });
    }

    public function test_si_ia_descarta_la_directa_se_intenta_la_fuente_pendiente_sin_rebuscar()
    {
        $service = $this->service(true, true);
        $product = $service->research('07791234567898');
        $this->assertSame('https://fabricante.test/producto', $product['sources'][0]['url']);
        $this->assertSame(2, $service->extracciones);
        $count = 0;
        Http::assertSent(function ($r) use (&$count) { if ($r->url() === 'https://google.serper.dev/search') { $count++; } return true; });
        $this->assertSame(1, $count);
    }

    public function test_fuente_pendiente_no_se_cachea_como_producto_inexistente()
    {
        $service = $this->service(false, false, '', true);
        $this->expectException(ProductFactsException::class);
        $service->research('07791234567898');
    }

    public function test_directas_descartadas_y_resolucion_fallida_no_producen_ausencia_definitiva()
    {
        $service = $this->service(true, true, '', true);
        $this->expectException(ProductFactsException::class);
        $service->research('07791234567898');
    }

    public function test_fuente_resuelta_privada_no_sale_a_la_red()
    {
        $service = $this->service(false, false, 'http://127.0.0.1/privado');
        try { $service->research('07791234567898'); $this->fail('Se esperaba rechazo de fuentes.'); }
        catch (ProductFactsException $e) { $this->assertStringContainsString('fuentes', $e->getMessage()); }
        Http::assertNotSent(function ($r) { return strpos($r->url(), '127.0.0.1') !== false; });
    }
}

class ResearchLinksConDns extends ProductFactsResearchService
{
    public $descartar_primera = false;
    public $extracciones = 0;
    protected function resolver_host($host) { return ['93.184.216.34']; }
    protected function ask($process, $system, array $input, $tokens)
    {
        $this->extracciones++;
        if ($this->descartar_primera && $this->extracciones === 1) { return ['matched_gtin' => null, 'name' => null, 'sources' => []]; }
        $documents = $input['documents'];
        $last = end($documents);
        return ['matched_gtin' => '7791234567898', 'name' => 'Producto verificado', 'sources' => [['url' => $last['url']]]];
    }
}
