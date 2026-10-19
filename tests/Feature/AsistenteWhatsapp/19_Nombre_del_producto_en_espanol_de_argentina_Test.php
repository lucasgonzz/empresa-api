<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\BusquedaPorCodigoDeBarrasService;
use App\Services\ProductFacts\ProductFactsResearchService;
use Tests\TestCase;

/**
 * Misión asistente-arreglos-segunda-prueba, N7 (19/10/2026) — el nombre del producto como lo nombraría un
 * comercio de Argentina.
 *
 * El caso real (segunda prueba contra demo3, A7): el código de barras de una cinta métrica Tramontina
 * (EAN 7891114080247) dio de alta el artículo «Trena Tramontina Master 5 m x 19 mm». Los prompts pedían
 * «el nombre comercial con el que se vende, en español» y la descripción traducida, pero nada decía que
 * el TIPO de producto se tradujera. Lo que fija este archivo:
 *  - los tres prompts que arman el nombre (la búsqueda web, la redacción desde una base abierta y la
 *    ficha compartida) llevan la MISMA regla, BusquedaPorCodigoDeBarrasService::NOMBRE_COMO_EN_ARGENTINA,
 *    con los ejemplos de las herramientas que llegan de Brasil y la marca y el modelo sin traducir;
 *  - lo que ya estaba sigue: «SIEMPRE EN ESPAÑOL» para la descripción (lo asserta también
 *    Busqueda_por_codigo_de_barras_Test) y el «Extraé» con el que arranca la extracción de la ficha (el
 *    test de ProductEnrichment distingue esa llamada por esa palabra);
 *  - la herramienta buscar_producto_por_codigo_de_barras le dice al modelo que traduzca el tipo de
 *    producto antes de proponer el alta.
 *
 * Sin base y sin red: solo lee los textos.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Nombre_del_producto_en_espanol_de_argentina_Test extends TestCase
{
    /**
     * Un prompt protegido del servicio, leído por reflexión (no se cambia la visibilidad del método
     * solo para el test).
     *
     * @param  object  $servicio
     * @param  string  $metodo
     * @return string
     */
    protected function prompt($servicio, $metodo)
    {
        $reflejo = new \ReflectionMethod(get_class($servicio), $metodo);
        $reflejo->setAccessible(true);

        return (string) $reflejo->invoke($servicio);
    }

    /**
     * Los tres prompts, por nombre.
     *
     * @return array<string, string>
     */
    protected function los_tres_prompts()
    {
        $busqueda = new BusquedaPorCodigoDeBarrasService(null);
        $ficha = new ProductFactsResearchService(null);

        return [
            'busqueda_web' => $this->prompt($busqueda, 'prompt_de_busqueda_web'),
            'redaccion'    => $this->prompt($busqueda, 'prompt_de_redaccion'),
            'extraccion'   => $this->prompt($ficha, 'prompt_de_extraccion'),
        ];
    }

    /**
     * 🔴 Los tres prompts llevan la regla del nombre, con los ejemplos de traducción y la marca y el modelo
     * tal cual.
     *
     * @test
     */
    public function los_tres_prompts_piden_el_nombre_como_en_argentina()
    {
        foreach ($this->los_tres_prompts() as $cual => $prompt) {

            $this->assertStringContainsString(BusquedaPorCodigoDeBarrasService::NOMBRE_COMO_EN_ARGENTINA, $prompt, $cual);
        }

        $regla = BusquedaPorCodigoDeBarrasService::NOMBRE_COMO_EN_ARGENTINA;

        foreach ([
            'como lo nombraría un comercio de Argentina',
            'traducido si las fuentes están en otro idioma',
            'trena → cinta métrica',
            'chave → llave',
            'alicate → pinza',
            'furadeira → taladro',
            'parafusadeira → atornillador',
            'fita → cinta',
            'lixa → lija',
            'martelo → martillo',
            'La marca y el modelo NO se traducen',
        ] as $fragmento) {
            $this->assertStringContainsString($fragmento, $regla, $fragmento);
        }

        // El caso del 19/10, como ejemplo en la búsqueda web.
        $this->assertStringContainsString('"Cinta Métrica Tramontina Master 5 m"', $this->los_tres_prompts()['busqueda_web']);
    }

    /**
     * Lo que ya pedían los prompts sigue ahí: la descripción siempre en español, y el arranque de la
     * extracción de la ficha.
     *
     * @test
     */
    public function lo_que_ya_estaba_sigue()
    {
        $prompts = $this->los_tres_prompts();

        $this->assertStringContainsString('SIEMPRE EN ESPAÑOL', $prompts['busqueda_web']);
        $this->assertStringContainsString('SIEMPRE EN ESPAÑOL', $prompts['redaccion']);
        $this->assertStringContainsString('"nombre": null', $prompts['busqueda_web'], 'El freno del producto equivocado sigue.');

        $this->assertStringStartsWith('Extraé una ficha pública de producto EN ESPAÑOL', $prompts['extraccion']);
        $this->assertStringNotContainsString('Elegí', $prompts['extraccion'], 'Esa palabra es la de la clasificación.');
        $this->assertStringContainsString('Respondé SOLO JSON {matched_gtin,name,description,brand,net_content', $prompts['extraccion']);
    }

    /**
     * La herramienta le dice al modelo que traduzca el tipo de producto antes de proponer el alta.
     *
     * @test
     */
    public function la_herramienta_pide_traducir_el_tipo_de_producto_antes_del_alta()
    {
        $descripcion = null;

        foreach ((new AsistenteIaService())->herramientas_de_lectura() as $herramienta) {
            if ($herramienta['name'] === 'buscar_producto_por_codigo_de_barras') {
                $descripcion = (string) $herramienta['description'];
            }
        }

        $this->assertNotNull($descripcion);
        $this->assertStringContainsString('traducilo al español de Argentina antes de proponer el alta, sin traducir la marca ni el modelo', $descripcion);
        $this->assertStringContainsString('"Trena" en vez de "Cinta métrica"', $descripcion);
    }
}
