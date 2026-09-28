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
     * Guarda contra arreglar esto sacando también 'whatsapp': esa es la que gatea el ítem de menú
     * del chat y tiene que seguir yendo de base en las dos.
     *
     * @test
     */
    public function una_demo_nueva_sigue_naciendo_con_whatsapp()
    {
        $this->assertContains('whatsapp', $this->extencions_de_la_demo());
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

    /** @test */
    public function un_cliente_real_nuevo_sigue_naciendo_con_whatsapp()
    {
        $this->assertContains('whatsapp', $this->extencions_del_user_setup());
    }
}
