<?php

namespace Tests\Feature\Semilla;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\UserSetupHelper;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Misión agente-ia-default-deepseek-directo (28/9/2026): ni una demo nueva ni un cliente real
 * nuevo nacen con `whatsapp_ia` (la extensión que gatilla `GenerateArticleEmbeddingJob` vía
 * `ArticleObserver`/`DescriptionObserver` — ver `ConfianzaDelAgenteIaHelper`/`ProveedorIaHelper`
 * para el resto de la misión). Se activa aparte, por cliente, cuando Lucas la prende a mano.
 *
 * `UserSetupHelper::base_extencions()` ya la había sacado el 10/9/2026 (medido en producción:
 * de 117 dueños con la extensión en esa lista base, solo 5 la usaban de verdad). El mismo fix NO
 * se había replicado en `DemoSetupHelper::base_extencions()`, así que toda demo nueva seguía
 * naciendo con embeddings encendidos sin que nadie los pidiera. Este test cubre los dos caminos
 * para que la próxima vez que alguien agregue una extensión de IA "de base" no vuelva a colarse
 * ahí por accidente.
 *
 * Se usa reflexión porque `base_extencions()` es privado en los dos Helpers y correr `run()`
 * entero (arranca con `migrate:fresh`) no entra en esta suite — mismo criterio que
 * `AlineacionLocalDemoTest`.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group semilla
 */
class ExtencionesDeSetupSinEmbeddingsTest extends TestCase
{
    /**
     * Las extensiones base de una demo nueva, por el mismo camino que `DemoSetupHelper::run()`.
     *
     * @return string[]
     */
    protected function extencions_de_la_demo()
    {
        $metodo = new ReflectionMethod(DemoSetupHelper::class, 'base_extencions');
        $metodo->setAccessible(true);

        return $metodo->invoke(null);
    }

    /**
     * Las extensiones base de un cliente real nuevo, por el mismo camino que
     * `UserSetupHelper::run()`.
     *
     * @return string[]
     */
    protected function extencions_del_user_setup()
    {
        $metodo = new ReflectionMethod(UserSetupHelper::class, 'base_extencions');
        $metodo->setAccessible(true);

        return $metodo->invoke(null);
    }

    /** @test */
    public function una_demo_nueva_no_nace_con_whatsapp_ia()
    {
        $this->assertNotContains(
            'whatsapp_ia',
            $this->extencions_de_la_demo(),
            'DemoSetupHelper::base_extencions() volvió a sembrar whatsapp_ia: toda demo nueva '
                . 'quedaría generando embeddings de su catálogo sin que nadie la haya activado.'
        );
    }

    /**
     * Cambio de contrato (misión agentes-ia-extension-unica, 22/10/2026): el módulo de WhatsApp dejó de ser
     * la extensión `whatsapp` y es parte de `agentes_ia`. Toda demo nueva nace con `agentes_ia` (una demo
     * existe para mostrar la IA) y ya no con ninguna de las seis extensiones de IA que reemplaza.
     *
     * @test
     */
    public function una_demo_nueva_sigue_naciendo_con_el_modulo_de_whatsapp_ahora_dentro_de_agentes_ia()
    {
        $extenciones = $this->extencions_de_la_demo();

        $this->assertContains('agentes_ia', $extenciones);

        foreach (['whatsapp', 'whatsapp_ia', 'asistente_ia', 'escaneo_factura_compra', 'sugerencias_inteligentes', 'sugerencias_compras'] as $reemplazada) {
            $this->assertNotContains($reemplazada, $extenciones, 'La demo no debe otorgar la extensión vieja ' . $reemplazada . '.');
        }
    }

    /** @test */
    public function un_cliente_real_nuevo_no_nace_con_whatsapp_ia()
    {
        $this->assertNotContains(
            'whatsapp_ia',
            $this->extencions_del_user_setup(),
            'UserSetupHelper::base_extencions() volvió a sembrar whatsapp_ia (se había sacado el 10/9/2026).'
        );
    }

    /**
     * Cambio de contrato (misión agentes-ia-extension-unica, 22/10/2026, decisión de Lucas «nadie hereda»):
     * un sistema real nuevo ya NO trae ninguna extensión de IA de base —ni `agentes_ia` ni las seis que
     * reemplaza—. Se activa por cliente, desde el admin o el configurador. Antes este test exigía `whatsapp`.
     *
     * @test
     */
    public function un_cliente_real_nuevo_no_nace_con_agentes_ia_ni_con_las_extensiones_de_ia_viejas()
    {
        $extenciones = $this->extencions_del_user_setup();

        foreach (['agentes_ia', 'whatsapp', 'whatsapp_ia', 'asistente_ia', 'escaneo_factura_compra', 'sugerencias_inteligentes', 'sugerencias_compras'] as $slug) {
            $this->assertNotContains($slug, $extenciones, 'UserSetupHelper::base_extencions() otorga ' . $slug . ': las altas nuevas de producción no traen IA por defecto.');
        }
    }

    /**
     * Pedido de Lucas (7/10/2026): la extensión de tienda online ('online') se habilita siempre,
     * en la demo y en el user setup, sin depender de ningún flag del formulario.
     *
     * @test
     */
    public function la_demo_y_el_user_setup_nacen_siempre_con_tienda_online()
    {
        $this->assertContains('online', $this->extencions_de_la_demo());
        $this->assertContains('online', $this->extencions_del_user_setup());
    }

    /** @test */
    public function la_demo_permite_probar_el_asistente_las_facturas_y_el_codigo_de_barras()
    {
        $extenciones = $this->extencions_de_la_demo();
        /* Asistente, escaneo de facturas y WhatsApp son hoy la extensión única `agentes_ia`. */
        foreach (['agentes_ia', 'bar_code_scanner'] as $slug) {
            $this->assertContains($slug, $extenciones, 'La demo debe permitir el recorrido anunciado de '.$slug.'.');
        }
    }
}
