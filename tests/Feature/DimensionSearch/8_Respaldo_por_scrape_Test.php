<?php

namespace Tests\Feature\DimensionSearch;

use App\Models\DimensionSearchItem;
use App\Models\SerperUsageEvent;
use App\Services\DimensionSearch\DimensionPageReader;
use App\Services\DimensionSearch\DimensionPageText;
use Carbon\Carbon;

/**
 * El respaldo por el scrape de Serper (misión medidas-sin-datos, 28/10/2026): una página que la descarga directa no
 * baja (403, captcha) y un PDF se piden como texto plano a `scrape.serper.dev`, con topes de costo.
 *
 * Todo falso: el doble del scrape devuelve el texto que el test le diga (ver DimensionSearchTestCase::falsear).
 */
class Respaldo_por_scrape_Test extends DimensionSearchTestCase
{
    const URL_A = 'https://catalogo.test/cuchilla-rotary';
    const URL_B = 'https://tienda.test/producto-b';
    const URL_C = 'https://otra.test/producto-c';
    const URL_D = 'https://mas.test/producto-d';
    const URL_E = 'https://ultima.test/producto-e';

    /** @var string */
    protected $gtin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gtin = $this->con_verificador('779123456834');
    }

    /**
     * El texto plano de una página con los cuatro datos y el código.
     *
     * @return string
     */
    protected function texto_completo()
    {
        return 'Cuchilla rotary. Código '.$this->gtin.'. Peso: 820 g. Ancho: 70 mm. Alto: 11 mm. Largo: 100 mm.';
    }

    /**
     * Los cuatro datos tal como están escritos en el texto de arriba.
     *
     * @param  string $url
     * @return array
     */
    protected function datos($url)
    {
        return [
            'peso'  => $this->dato(820, 'g', 'Peso: 820 g', $url),
            'ancho' => $this->dato(70, 'mm', 'Ancho: 70 mm', $url),
            'alto'  => $this->dato(11, 'mm', 'Alto: 11 mm', $url),
            'largo' => $this->dato(100, 'mm', 'Largo: 100 mm', $url),
        ];
    }

    /**
     * Un resultado de Serper cuyo título trae el código (la pista para gastar un scrape).
     *
     * @param  string $url
     * @return array
     */
    protected function resultado_con_codigo($url)
    {
        return $this->resultado($url, 'Cuchilla rotary '.$this->gtin);
    }

    /**
     * Una página bloqueada (403): se pide el scrape, el texto plano llega a la IA y valida.
     *
     * @group dimension-search
     * @test
     */
    public function una_pagina_con_403_se_lee_con_el_respaldo_y_el_texto_plano_llega_a_la_ia_y_valida()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $this->gtin);

        $this->falsear(
            ['"'.$this->gtin.'"' => [$this->resultado_con_codigo(self::URL_A)]],
            [self::URL_A => ['status' => 403]],
            $this->ficha('exacto', $this->datos(self::URL_A)),
            [self::URL_A => ['text' => $this->texto_completo(), 'credits' => 2, 'title' => 'Cuchilla rotary']]
        );

        list($item, $resultado) = $this->buscar($articulo);

        $this->assertSame([self::URL_A], $this->paginas_pedidas, 'Primero se intentó la descarga directa.');
        $this->assertSame([self::URL_A], $this->scrapes_pedidos);

        // El texto plano llegó a la IA (sin HTML) y el validador comprobó la cita contra ESE texto.
        $this->assertCount(1, $this->pedidos_ia);
        $texto = $this->pedidos_ia[0]['entrada']['documents'][0]['text'];
        $this->assertStringContainsString('Peso: 820 g', $texto);
        $this->assertStringContainsString('TÍTULO: Cuchilla rotary', $texto);

        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame('codigo_exacto', $item->motivo);
        $this->assertEquals(0.82, (float) $articulo->fresh()->peso);
        $this->assertEquals(7, (float) $articulo->fresh()->ancho);

        // El diagnóstico dice que se leyó con el respaldo y cuántos créditos costó.
        $pagina = $item->diagnostico[0]['paginas'][0];
        $this->assertTrue($pagina['leida']);
        $this->assertTrue($pagina['via_scrape']);
        $this->assertEquals(2, $pagina['creditos']);
        $this->assertSame('Página leída con el respaldo.', $pagina['motivo']);

        $this->assertSame(1, $resultado['scrapes']);
        $this->assertEquals(2, $resultado['creditos_scrape']);
    }

    /**
     * El consumo del scrape queda en serper_usage_events y la clave nunca se guarda en el diagnóstico.
     *
     * @group dimension-search
     * @test
     */
    public function el_scrape_se_registra_en_serper_usage_events_con_sus_creditos_y_la_clave_no_queda_en_ningun_lado()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $this->gtin);

        $this->falsear(
            ['"'.$this->gtin.'"' => [$this->resultado_con_codigo(self::URL_A)]],
            [self::URL_A => ['status' => 403]],
            $this->ficha('exacto', $this->datos(self::URL_A)),
            [self::URL_A => ['text' => $this->texto_completo(), 'credits' => 4]]
        );

        list($item) = $this->buscar($articulo);

        $evento = SerperUsageEvent::where('process', 'medidas_scrape')->first();

        $this->assertNotNull($evento);
        $this->assertSame('search', $evento->endpoint, 'El admin solo acepta search|images: el scrape se distingue por el proceso.');
        $this->assertEquals(4, (float) $evento->credits);
        $this->assertTrue((bool) $evento->ok);
        $this->assertSame(200, (int) $evento->http_status);
        $this->assertSame(self::URL_A, $evento->query);
        $this->assertSame($this->owner->id, (int) $evento->user_id);

        // La búsqueda sigue registrándose aparte.
        $this->assertSame(1, SerperUsageEvent::where('process', 'medidas_busqueda')->count());

        // La clave viaja en el header (el doble la vio) y no se escribe en el diagnóstico ni en el ítem.
        $this->assertSame(['SERPER-DE-PRUEBA'], $this->claves_del_scrape);
        $this->assertStringNotContainsString('SERPER-DE-PRUEBA', json_encode($item->diagnostico));
        $this->assertStringNotContainsString('SERPER-DE-PRUEBA', json_encode($item->getAttributes()));
    }

    /**
     * 🔴 El admin valida `endpoint` con `in:search,images`: un evento con otro valor traba la sincronización de TODO el
     * consumo de Serper del cliente. Ningún evento del motor puede quedar con otro endpoint.
     *
     * @group dimension-search
     * @test
     */
    public function ningun_evento_de_serper_queda_con_un_endpoint_fuera_de_search_o_images()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $this->gtin);
        $pdf      = 'https://catalogo.test/ficha.pdf';

        $this->falsear(
            ['"'.$this->gtin.'"' => [$this->resultado_con_codigo(self::URL_A), $this->resultado_con_codigo($pdf)]],
            [self::URL_A => ['status' => 403]],
            $this->ficha('exacto', $this->datos(self::URL_A)),
            [self::URL_A => $this->texto_completo(), $pdf => ['status' => 500]]
        );

        $this->buscar($articulo);

        $this->assertGreaterThanOrEqual(3, SerperUsageEvent::count(), 'La búsqueda y dos scrapes (uno fallido).');
        $this->assertSame(
            [],
            SerperUsageEvent::whereNotIn('endpoint', ['search', 'images'])->pluck('endpoint')->all()
        );
    }

    /**
     * Un PDF no se baja directo: se lee con el respaldo.
     *
     * @group dimension-search
     * @test
     */
    public function un_pdf_se_lee_con_el_respaldo_sin_intentar_la_descarga_directa()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $this->gtin);
        $pdf      = 'https://catalogo.test/ficha-tecnica.pdf';

        $this->falsear(
            ['"'.$this->gtin.'"' => [$this->resultado_con_codigo($pdf)]],
            [],
            $this->ficha('exacto', $this->datos($pdf)),
            [$pdf => $this->texto_completo()]
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame([], $this->paginas_pedidas, 'Un PDF no se baja por la vía directa.');
        $this->assertSame([$pdf], $this->scrapes_pedidos);
        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);

        $pagina = $item->diagnostico[0]['paginas'][0];
        $this->assertTrue($pagina['via_scrape']);
        $this->assertSame('PDF leído con el respaldo.', $pagina['motivo']);
    }

    /**
     * Una página que la descarga directa devuelve como application/pdf (aunque la URL no termine en .pdf) también
     * se lee con el respaldo.
     *
     * @group dimension-search
     * @test
     */
    public function una_pagina_que_responde_application_pdf_tambien_se_lee_con_el_respaldo()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $this->gtin);
        $url      = 'https://catalogo.test/descargar?id=77';

        $this->falsear(
            ['"'.$this->gtin.'"' => [$this->resultado_con_codigo($url)]],
            [$url => ['html' => '%PDF-1.4 ...', 'tipo' => 'application/pdf']],
            $this->ficha('exacto', $this->datos($url)),
            [$url => $this->texto_completo()]
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame([$url], $this->scrapes_pedidos);
        $this->assertSame('PDF leído con el respaldo.', $item->diagnostico[0]['paginas'][0]['motivo']);
    }

    /**
     * Un 404 no se scrapea: la página no existe.
     *
     * @group dimension-search
     * @test
     */
    public function una_pagina_que_no_existe_404_no_gasta_un_scrape()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $this->gtin);

        $this->falsear(
            ['"'.$this->gtin.'"' => [$this->resultado_con_codigo(self::URL_A)]],
            [self::URL_A => ['status' => 404]],
            $this->ficha('exacto'),
            [self::URL_A => $this->texto_completo()]
        );

        $this->buscar($articulo);

        $this->assertSame([], $this->scrapes_pedidos);
    }

    /**
     * Tope: 3 scrapes por artículo, sumando los planes.
     *
     * @group dimension-search
     * @test
     */
    public function como_mucho_tres_scrapes_por_articulo_acumulados_entre_los_planes()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $this->gtin, ['provider_code' => 'VR-CU-0402']);

        $this->falsear(
            [
                '"'.$this->gtin.'"'            => [$this->resultado_con_codigo(self::URL_A), $this->resultado_con_codigo(self::URL_B)],
                '"VR-CU-0402" CUCHILLA ROTARY' => [
                    $this->resultado(self::URL_C, 'Cuchilla VR-CU-0402'),
                    $this->resultado(self::URL_D, 'Cuchilla VR-CU-0402'),
                    $this->resultado(self::URL_E, 'Cuchilla VR-CU-0402'),
                ],
            ],
            [
                self::URL_A => ['status' => 403], self::URL_B => ['status' => 403],
                self::URL_C => ['status' => 403], self::URL_D => ['status' => 403], self::URL_E => ['status' => 403],
            ],
            $this->ficha('otro_producto'),
            [] // Ningún scrape devuelve texto: cada uno falla y cuenta igual.
        );

        list($item, $resultado) = $this->buscar($articulo);

        $this->assertCount(3, $this->scrapes_pedidos, 'Dos en el plan de barras y uno en el de proveedor: ya no hay más.');
        $this->assertSame(3, $resultado['scrapes']);
        $this->assertSame(3, SerperUsageEvent::where('process', 'medidas_scrape')->count());
    }

    /**
     * El tope sale de la configuración.
     *
     * @group dimension-search
     * @test
     */
    public function el_tope_de_scrapes_por_articulo_sale_de_la_configuracion()
    {
        config(['services.medidas_masivas.max_scrapes_por_articulo' => 1]);

        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $this->gtin);

        $this->falsear(
            ['"'.$this->gtin.'"' => [$this->resultado_con_codigo(self::URL_A), $this->resultado_con_codigo(self::URL_B)]],
            [self::URL_A => ['status' => 403], self::URL_B => ['status' => 403]],
            $this->ficha('otro_producto'),
            []
        );

        $this->buscar($articulo);

        $this->assertCount(1, $this->scrapes_pedidos);

        config(['services.medidas_masivas.max_scrapes_por_articulo' => 0]);

        $otro = $this->nuevo_articulo('OTRA CUCHILLA ROTARY', $this->gtin);
        $this->falsear(
            ['"'.$this->gtin.'"' => [$this->resultado_con_codigo(self::URL_A)]],
            [self::URL_A => ['status' => 403]],
            $this->ficha('otro_producto'),
            [self::URL_A => $this->texto_completo()]
        );

        $this->buscar($otro);

        $this->assertSame([], $this->scrapes_pedidos, 'Con el tope en cero el respaldo está apagado.');
    }

    /**
     * Las redes sociales no se scrapean (ni sus subdominios ni pinterest.*).
     *
     * @group dimension-search
     * @test
     */
    public function los_dominios_sociales_no_se_scrapean()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $this->gtin);

        $urls = [
            'https://www.facebook.com/marketplace/item/1',
            'https://m.facebook.com/p/2',
            'https://www.instagram.com/p/3',
            'https://es.pinterest.com/pin/4',
            'https://www.pinterest.com.ar/pin/5',
        ];

        $resultados = [];
        $paginas    = [];

        foreach ($urls as $url) {
            $resultados[] = $this->resultado_con_codigo($url);
            $paginas[$url] = ['status' => 403];
        }

        $this->falsear(['"'.$this->gtin.'"' => $resultados], $paginas, $this->ficha('otro_producto'), array_fill_keys($urls, $this->texto_completo()));

        $this->buscar($articulo);

        $this->assertSame([], $this->scrapes_pedidos);

        // youtube.com y x.com también; un dominio que solo CONTIENE el nombre no es social.
        $propio = 'https://www.xtreme-herramientas.com.ar/cuchilla';
        $otro   = $this->nuevo_articulo('CUCHILLA ROTARY 2', $this->gtin);

        $this->falsear(
            ['"'.$this->gtin.'"' => [$this->resultado_con_codigo('https://www.youtube.com/watch?v=1'), $this->resultado_con_codigo('https://x.com/a/status/1'), $this->resultado_con_codigo($propio)]],
            ['https://www.youtube.com/watch?v=1' => ['status' => 403], 'https://x.com/a/status/1' => ['status' => 403], $propio => ['status' => 403]],
            $this->ficha('otro_producto'),
            [$propio => $this->texto_completo()]
        );

        $this->buscar($otro);

        $this->assertSame([$propio], $this->scrapes_pedidos);
    }

    /**
     * En un plan de código, una página sin el código en el título/snippet no gasta un scrape.
     *
     * @group dimension-search
     * @test
     */
    public function en_un_plan_de_codigo_una_pagina_sin_el_codigo_en_el_titulo_o_el_snippet_no_se_scrapea()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $this->gtin);

        $this->falsear(
            ['"'.$this->gtin.'"' => [
                $this->resultado(self::URL_A, 'Cuchilla rotary genérica'),                                                                 // Sin código: no.
                ['title' => 'Otra', 'link' => self::URL_B, 'snippet' => 'EAN '.$this->gtin.' en stock', 'position' => 2],               // Código en el snippet: sí.
            ]],
            [self::URL_A => ['status' => 403], self::URL_B => ['status' => 403]],
            $this->ficha('exacto', $this->datos(self::URL_B)),
            [self::URL_A => $this->texto_completo(), self::URL_B => $this->texto_completo()]
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame([self::URL_B], $this->scrapes_pedidos);
        $this->assertSame('El sitio bloquea la descarga (403)', $item->diagnostico[0]['paginas'][0]['motivo']);
    }

    /**
     * El plan de nombre scrapea solo si no quedó ninguna página con el código; no si la IA ya reconoció el producto.
     *
     * @group dimension-search
     * @test
     */
    public function el_plan_de_nombre_scrapea_solo_si_no_hay_pagina_con_el_codigo_ni_producto_reconocido()
    {
        // Sin ningún código: ninguna página puede tenerlo → se scrapea (y la página no necesita pista en el título).
        $sin_codigo = $this->nuevo_articulo('CUCHILLA ROTARY GRANDE');

        $this->falsear(
            ['CUCHILLA ROTARY GRANDE ficha técnica peso medidas' => [$this->resultado(self::URL_A, 'Cuchilla rotary grande')]],
            [self::URL_A => ['status' => 403]],
            $this->ficha('probable', $this->datos(self::URL_A)),
            [self::URL_A => 'Cuchilla rotary grande. Peso: 820 g. Ancho: 70 mm. Alto: 11 mm. Largo: 100 mm.']
        );

        list($item) = $this->buscar($sin_codigo);

        $this->assertSame([self::URL_A], $this->scrapes_pedidos);
        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('coincidencia_por_nombre', $item->motivo);

        // Con código de proveedor: el primer plan reconoce el producto (sin datos) → el de nombre no gasta nada.
        $con_codigo = $this->nuevo_articulo('CUCHILLA ROTARY GRANDE', null, ['provider_code' => 'VR-CU-0402']);

        $this->falsear(
            [
                '"VR-CU-0402" CUCHILLA ROTARY GRANDE'                => [$this->resultado(self::URL_B, 'Cuchilla VR-CU-0402')],
                'CUCHILLA ROTARY GRANDE ficha técnica peso medidas' => [$this->resultado(self::URL_C, 'Cuchilla rotary grande')],
            ],
            [self::URL_B => $this->pagina('Cuchilla', 'Código VR-CU-0402. Cuchilla rotativa.'), self::URL_C => ['status' => 403]],
            $this->ficha('exacto'),
            [self::URL_C => $this->texto_completo()]
        );

        list($item2) = $this->buscar($con_codigo);

        $this->assertSame([], $this->scrapes_pedidos, 'La IA ya reconoció el producto: no se gastan créditos en el plan de nombre.');
        $this->assertSame('sin_medidas_en_la_pagina', $item2->motivo);
    }

    /**
     * Una página que ya se scrapeó no se vuelve a pedir en otro plan.
     *
     * @group dimension-search
     * @test
     */
    public function una_pagina_que_ya_se_scrapeo_no_se_vuelve_a_scrapear_en_otro_plan()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $this->gtin, ['provider_code' => 'VR-CU-0402']);

        $this->falsear(
            [
                '"'.$this->gtin.'"'            => [$this->resultado(self::URL_A, 'Cuchilla '.$this->gtin.' VR-CU-0402')],
                '"VR-CU-0402" CUCHILLA ROTARY' => [$this->resultado(self::URL_A, 'Cuchilla '.$this->gtin.' VR-CU-0402')],
            ],
            [self::URL_A => ['status' => 403]],
            $this->ficha('otro_producto'),
            [] // El respaldo no la baja: falla una vez y no se reintenta.
        );

        $this->buscar($articulo);

        $this->assertSame([self::URL_A], $this->scrapes_pedidos);
    }

    /**
     * Si el scrape falla, la página queda como «no se pudo leer ni con el respaldo» y nada se rompe.
     *
     * @group dimension-search
     * @test
     */
    public function si_el_respaldo_falla_se_anota_y_el_articulo_sigue_sin_romperse()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $this->gtin);

        $this->falsear(
            ['"'.$this->gtin.'"' => [$this->resultado_con_codigo(self::URL_A)]],
            [self::URL_A => ['status' => 403]],
            $this->ficha('exacto'),
            [self::URL_A => ['status' => 500]]
        );

        list($item) = $this->buscar($articulo);

        $pagina = $item->diagnostico[0]['paginas'][0];
        $this->assertFalse($pagina['leida']);
        $this->assertSame('No se pudo leer ni con el respaldo.', $pagina['motivo']);

        $evento = SerperUsageEvent::where('process', 'medidas_scrape')->first();
        $this->assertFalse((bool) $evento->ok, 'El intento queda registrado aunque falle.');
        $this->assertSame(500, (int) $evento->http_status);
        $this->assertCount(0, $this->pedidos_ia);
    }

    /**
     * Si Serper cobra más de lo esperado, se usa igual pero queda anotado.
     *
     * @group dimension-search
     * @test
     */
    public function si_serper_cobra_mas_del_tope_de_creditos_se_usa_igual_y_queda_anotado()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $this->gtin);

        $this->falsear(
            ['"'.$this->gtin.'"' => [$this->resultado_con_codigo(self::URL_A)]],
            [self::URL_A => ['status' => 403]],
            $this->ficha('exacto', $this->datos(self::URL_A)),
            [self::URL_A => ['text' => $this->texto_completo(), 'credits' => 12]]
        );

        list($item, $resultado) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        $pagina = $item->diagnostico[0]['paginas'][0];
        $this->assertEquals(12, $pagina['creditos']);
        $this->assertTrue($pagina['creditos_sobre_el_tope']);
        $this->assertEquals(12, $resultado['creditos_scrape']);
    }

    /**
     * El texto del respaldo que no trae el código del plan de código no se le manda a la IA (igual que el HTML).
     *
     * @group dimension-search
     * @test
     */
    public function el_texto_del_respaldo_sin_el_codigo_no_cuenta_en_un_plan_de_codigo()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $this->gtin);

        $this->falsear(
            ['"'.$this->gtin.'"' => [$this->resultado_con_codigo(self::URL_A)]],
            [self::URL_A => ['status' => 403]],
            $this->ficha('exacto', $this->datos(self::URL_A)),
            [self::URL_A => 'Cuchilla rotary de otro modelo. Peso: 820 g. Ancho: 70 mm.']
        );

        list($item) = $this->buscar($articulo);

        $this->assertCount(0, $this->pedidos_ia);
        $pagina = $item->diagnostico[0]['paginas'][0];
        $this->assertFalse($pagina['leida']);
        $this->assertSame('La página no contiene el código buscado.', $pagina['motivo']);
        $this->assertTrue($pagina['via_scrape']);
    }

    /**
     * En una demo, con las búsquedas agotadas el respaldo no sale a la red (cuenta como una búsqueda).
     *
     * @group dimension-search
     * @test
     */
    public function en_una_demo_sin_busquedas_no_se_scrapea()
    {
        $anterior = [config('app.FOR_USER'), config('app.USER_ID')];
        config(['app.FOR_USER' => 'demo', 'app.USER_ID' => $this->owner->id]);

        try {
            // 24 de 25 usadas: la búsqueda del artículo es la 25ª y agota el tope.
            $ahora = Carbon::now();
            $filas = [];

            for ($i = 0; $i < 24; $i++) {
                $filas[] = [
                    'event_uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $this->owner->id, 'process' => 'imagenes_seleccion',
                    'endpoint' => 'images', 'query' => 'previa '.$i, 'ok' => true, 'occurred_at' => $ahora, 'created_at' => $ahora, 'updated_at' => $ahora,
                ];
            }

            SerperUsageEvent::insert($filas);

            // Nombre corto: no genera plan de nombre, así el único plan es el de barras (el que gastó la 25ª búsqueda).
            $articulo = $this->nuevo_articulo('CUCH', $this->gtin);

            $this->falsear(
                ['"'.$this->gtin.'"' => [$this->resultado_con_codigo(self::URL_A)]],
                [self::URL_A => ['status' => 403]],
                $this->ficha('exacto', $this->datos(self::URL_A)),
                [self::URL_A => $this->texto_completo()]
            );

            list($item, $resultado) = $this->buscar($articulo);

            $this->assertCount(1, $this->consultas_serper, 'La búsqueda sí salió (era la 25ª).');
            $this->assertSame([], $this->scrapes_pedidos, 'El scrape no sale: el tope de la demo está agotado.');
            $this->assertSame(0, SerperUsageEvent::where('process', 'medidas_scrape')->count());
            $this->assertSame('paginas_bloqueadas', $item->motivo);
            $this->assertStringContainsString('el respaldo no estaba disponible', $item->motivo_detalle);
        } finally {
            config(['app.FOR_USER' => $anterior[0], 'app.USER_ID' => $anterior[1]]);
        }
    }

    /**
     * En una demo con búsquedas disponibles, el scrape cuenta como una búsqueda más.
     *
     * @group dimension-search
     * @test
     */
    public function en_una_demo_un_scrape_cuenta_como_una_busqueda_del_tope()
    {
        $anterior = [config('app.FOR_USER'), config('app.USER_ID')];
        config(['app.FOR_USER' => 'demo', 'app.USER_ID' => $this->owner->id]);

        try {
            $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $this->gtin);

            $this->falsear(
                ['"'.$this->gtin.'"' => [$this->resultado_con_codigo(self::URL_A)]],
                [self::URL_A => ['status' => 403]],
                $this->ficha('exacto', $this->datos(self::URL_A)),
                [self::URL_A => $this->texto_completo()]
            );

            $this->buscar($articulo);

            $this->assertSame([self::URL_A], $this->scrapes_pedidos);
            $this->assertSame(2, \App\Services\ProductFacts\TopeSerperDemo::usadas($this->owner), 'Una búsqueda y un scrape.');
        } finally {
            config(['app.FOR_USER' => $anterior[0], 'app.USER_ID' => $anterior[1]]);
        }
    }

    /**
     * El lector sin clave de Serper: no sale a la red y no rompe.
     *
     * @group dimension-search
     * @test
     */
    public function el_lector_sin_clave_de_serper_no_scrapea_ni_rompe()
    {
        $this->falsear([], [], $this->ficha('otro_producto'), [self::URL_A => $this->texto_completo()]);

        config(['services.serper.api_key' => '']);

        $lector    = app()->makeWith(DimensionPageReader::class, ['owner' => $this->owner]);
        $respuesta = $lector->scrapear(self::URL_A);

        $this->assertFalse($respuesta['ok']);
        $this->assertTrue($respuesta['sin_clave']);
        $this->assertSame([], $this->scrapes_pedidos);
        $this->assertSame(0, SerperUsageEvent::where('process', 'medidas_scrape')->count());
    }

    /**
     * El lector con la clave del comercio usa esa y no la del servidor.
     *
     * @group dimension-search
     * @test
     */
    public function el_lector_usa_la_clave_de_serper_del_comercio_si_la_tiene()
    {
        $this->owner->serper_api_key = 'SERPER-DEL-COMERCIO';
        $this->owner->save();

        $this->falsear([], [], $this->ficha('otro_producto'), [self::URL_A => $this->texto_completo()]);

        $lector    = app()->makeWith(DimensionPageReader::class, ['owner' => $this->owner->fresh()]);
        $respuesta = $lector->scrapear(self::URL_A);

        $this->assertTrue($respuesta['ok']);
        $this->assertSame(['SERPER-DEL-COMERCIO'], $this->claves_del_scrape);
        $this->assertSame($this->texto_completo(), $respuesta['texto']);
        $this->assertEquals(2, $respuesta['creditos']);
    }

    /**
     * El texto plano no se «limpia» como HTML: lo que parece una etiqueta se conserva, y el recorte sigue
     * centrándose en el código.
     *
     * @group dimension-search
     * @test
     */
    public function el_texto_plano_no_se_limpia_como_html_y_conserva_la_ventana_del_codigo()
    {
        $relleno = str_repeat('Texto de relleno sin interés para la ficha. ', 400);
        $texto   = $relleno.'Código '.$this->gtin.' Dimensiones <70> x 11 mm. Peso 820 g.'.$relleno;

        $extracto = DimensionPageText::recortar($texto, [['codigo' => $this->gtin, 'tipo' => DimensionPageText::CODIGO_DE_BARRAS]], true, 'Una ficha');

        $this->assertStringContainsString('TÍTULO: Una ficha', $extracto);
        $this->assertStringContainsString('<70> x 11 mm', $extracto, 'Como HTML, «<70>» se habría borrado como etiqueta.');
        $this->assertStringContainsString($this->gtin, $extracto);
        $this->assertLessThan(DimensionPageText::MAXIMO_EXTRACTO + 500, mb_strlen($extracto), 'Se conserva el truncado.');

        // Como HTML, lo mismo SÍ lo borraría (el comportamiento de siempre no cambió).
        $como_html = DimensionPageText::recortar('<html><body>Código '.$this->gtin.' Dimensiones <70> x 11 mm.</body></html>', [['codigo' => $this->gtin, 'tipo' => DimensionPageText::CODIGO_DE_BARRAS]]);
        $this->assertStringNotContainsString('<70>', $como_html);
    }
}
