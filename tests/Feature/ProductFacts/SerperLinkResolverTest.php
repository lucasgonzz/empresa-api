<?php

namespace Tests\Feature\ProductFacts;

use App\Models\SerperUsageEvent;
use App\Models\User;
use App\Services\ProductFacts\SerperLinkResolver;
use App\Services\ProductFacts\TopeSerperDemo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

class SerperLinkResolverTest extends EmpresaTestCase
{
    protected function body()
    {
        return ['searchParameters' => ['q' => 'producto'], 'credits' => 1,
            'organic' => [['title' => 'Fuente', 'link' => 'https://www.google.com/goto?url=opaco']]];
    }

    public function test_resuelve_con_payload_original_y_clave_sin_repetir_la_busqueda()
    {
        $body = $this->body();
        Http::fake([SerperLinkResolver::URL => Http::response(['organic' => [['link' => 'https://fabricante.test/producto']]])]);
        $result = (new ResolverConReloj)->resolver($body, 'CLAVE-FALSA', ['user_id' => 1], 140);
        $this->assertSame('https://fabricante.test/producto', $result['organic'][0]['link']);
        $this->assertSame('Fuente', $result['organic'][0]['title']);
        Http::assertSent(function ($request) use ($body) {
            return $request->url() === SerperLinkResolver::URL && $request->method() === 'POST'
                && $request->hasHeader('X-API-KEY', 'CLAVE-FALSA') && $request->data() === $body;
        });
        $event = SerperUsageEvent::where('process', SerperLinkResolver::PROCESO)->latest('id')->firstOrFail();
        $this->assertSame('search', $event->endpoint);
        $this->assertEquals(0, $event->credits);
        Http::assertSentCount(1);
    }

    public function test_no_llama_sin_pendientes_sin_clave_o_sin_presupuesto()
    {
        Http::fake();
        $resolver = new ResolverConReloj;
        $resolver->resolver(['organic' => [['link' => 'https://fabricante.test']]], 'CLAVE', [], 140);
        $resolver->resolver($this->body(), '', [], 140);
        $resolver->resolver($this->body(), 'CLAVE', [], 100);
        Http::assertNothingSent();
    }

    public function test_respeta_retry_after_y_conserva_las_urls_ya_resueltas()
    {
        $body = $this->body();
        $body['organic'][] = ['title' => 'Otra', 'link' => 'https://google.com/goto?url=otro'];
        Http::fake([SerperLinkResolver::URL => Http::sequence()
            ->push(['organic' => [['link' => 'https://uno.test'], ['link' => 'https://google.com/goto?url=otro']], 'retryAfter' => 2])
            ->push(['organic' => [['link' => 'https://uno.test'], ['link' => 'https://dos.test']]])]);
        $resolver = new ResolverConReloj;
        $result = $resolver->resolver($body, 'CLAVE', [], 140);
        $this->assertSame([2.0], $resolver->esperas);
        $this->assertSame(['https://uno.test', 'https://dos.test'], array_column($result['organic'], 'link'));
        Http::assertSentCount(2);
    }

    public function test_retry_after_fuera_del_presupuesto_no_espera_ni_reintenta()
    {
        Http::fake([SerperLinkResolver::URL => Http::response(array_merge($this->body(), ['retryAfter' => 99]))]);
        $resolver = new ResolverConReloj;
        $resolver->resolver($this->body(), 'CLAVE', [], 140);
        $this->assertSame([], $resolver->esperas);
        Http::assertSentCount(1);
    }

    public function test_limita_reintentos_aunque_el_proveedor_pida_espera_cero()
    {
        Http::fake([SerperLinkResolver::URL => Http::response(array_merge($this->body(), ['retryAfter' => 0]))]);
        (new ResolverConReloj)->resolver($this->body(), 'CLAVE', [], 140);
        Http::assertSentCount(3);
    }

    public function test_timeout_conserva_resultados_y_registra_cero_sin_consumir_demo()
    {
        $owner = User::create(['name' => 'Dueño resolución', 'email' => uniqid().'@resolver.test', 'password' => 'sin-acceso']);
        Http::fake(function () { throw new ConnectionException('Timeout con datos secretos'); });
        $result = (new ResolverConReloj)->resolver($this->body(), 'CLAVE', ['user_id' => $owner->id], 140);
        $this->assertSame($this->body(), $result);
        $event = SerperUsageEvent::where('user_id', $owner->id)->firstOrFail();
        $this->assertFalse($event->ok);
        $this->assertEquals(0, $event->credits);
        $this->assertSame(0, TopeSerperDemo::usadas($owner));
        config(['app.FOR_USER' => 'demo']);
        $this->assertSame(25, TopeSerperDemo::restantes($owner));
    }

    public function test_error_http_o_respuesta_malformada_no_sacan_fuentes_directas()
    {
        foreach ([Http::response([], 429), Http::response('no es json')] as $response) {
            Http::swap(new \Tests\Fakes\HttpFactorySinSalida(app('events')));
            Http::fake([SerperLinkResolver::URL => $response]);
            $this->assertSame($this->body(), (new ResolverConReloj)->resolver($this->body(), 'CLAVE', [], 140));
        }
    }

    public function test_el_timeout_http_se_recorta_al_presupuesto_y_no_se_pasa_por_retry()
    {
        $resolver = new ResolverConReloj;
        $options = null;
        Http::fake(function ($r, $opts) use ($resolver, &$options) {
            $options = $opts;
            $resolver->reloj += 3;
            return Http::response(array_merge($this->body(), ['retryAfter' => 2]));
        });
        $resolver->resolver($this->body(), 'CLAVE', [], 104);
        $this->assertEquals(4, $options['timeout']);
        $this->assertEquals(3, $options['connect_timeout']);
        $this->assertSame([], $resolver->esperas);
        Http::assertSentCount(1);
    }

    public function test_resolver_no_gasta_el_ultimo_cupo_y_scrape_si_lo_gasta()
    {
        $owner = User::create(['name' => 'Dueño cupo', 'email' => uniqid().'@resolver.test', 'password' => 'sin-acceso']);
        config(['app.FOR_USER' => 'demo']);
        for ($i = 0; $i < 24; $i++) {
            \App\Services\ProductFacts\SerperUsageService::record(['user_id' => $owner->id, 'process' => 'imagenes'], 'images', '', null, microtime(true));
        }
        Http::fake([SerperLinkResolver::URL => Http::response(['organic' => [['link' => 'https://fabricante.test']]])]);
        (new ResolverConReloj)->resolver($this->body(), 'CLAVE', ['user_id' => $owner->id], 140);
        $this->assertSame(1, TopeSerperDemo::restantes($owner));
        \App\Services\ProductFacts\SerperUsageService::record(['user_id' => $owner->id, 'process' => 'medidas_scrape'], 'search', '', null, microtime(true));
        $this->assertTrue(TopeSerperDemo::agotado($owner));
    }

    public function test_imagen_directa_con_pagina_goto_no_agrega_resolucion()
    {
        Http::fake(['https://google.serper.dev/images' => Http::response(['images' => [[
            'imageUrl' => 'https://fabricante.test/foto.jpg', 'link' => 'https://www.google.com/goto?url=opaco',
        ]]])]);
        $result = (new \App\Services\ImageSearch\SerperImageSearchProvider('CLAVE'))->buscar('producto');
        $this->assertTrue($result['ok']);
        $this->assertSame('https://fabricante.test/foto.jpg', $result['resultados'][0]['url']);
        Http::assertSentCount(1);
    }

    public function test_un_destino_vacio_o_invalido_conserva_el_enlace_pendiente()
    {
        foreach (['', 'javascript:alert(1)', 'file:///privado', 'no es una url'] as $url) {
            Http::swap(new \Tests\Fakes\HttpFactorySinSalida(app('events')));
            Http::fake([SerperLinkResolver::URL => Http::response(['organic' => [['link' => $url]]])]);
            $result = (new ResolverConReloj)->resolver($this->body(), 'CLAVE', [], 140);
            $this->assertTrue(SerperLinkResolver::pendiente($result['organic'][0]['link']));
        }
    }

    public function test_identifica_solo_el_host_google_y_ruta_goto()
    {
        $this->assertTrue(SerperLinkResolver::pendiente('https://www.google.com/goto?url=a'));
        $this->assertTrue(SerperLinkResolver::pendiente('https://WWW.GOOGLE.COM./goto?url=a'));
        $this->assertFalse(SerperLinkResolver::pendiente('https://google.com.evil.test/goto?url=a'));
        $this->assertFalse(SerperLinkResolver::pendiente('https://fabricante.test/goto?url=a'));
        $this->assertCount(1, SerperLinkResolver::directos([$this->body()['organic'][0], ['link' => 'https://publica.test']]));
    }
}

class ResolverConReloj extends SerperLinkResolver
{
    public $reloj = 100;
    public $esperas = [];
    protected function ahora() { return $this->reloj; }
    protected function esperar($seconds) { $this->esperas[] = $seconds; $this->reloj += $seconds; }
}
