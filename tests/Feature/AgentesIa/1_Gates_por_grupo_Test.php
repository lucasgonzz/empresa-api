<?php

namespace Tests\Feature\AgentesIa;

/**
 * Misión agentes-ia-extension-unica (22/10/2026) — cada grupo de rutas de IA exige `agentes_ia`.
 *
 * Lo que protege, por grupo (asistente y chat, MCP, mostrador, escaneo de facturas, módulo de WhatsApp,
 * sugerencias, asignación automática de imágenes, descripciones, fichas por código de barras, categorías con
 * IA, configuración del bot):
 *
 *  - SIN la extensión, la ruta contesta **403** con «Extensión requerida: agentes_ia» (jamás 404: el admin
 *    interpreta un 404 como «versión vieja»). El gate corta antes del controlador, así que no hace falta que
 *    el pedido sea válido: por eso se prueba también con POST/PUT/DELETE sin cuerpo, sin efectos.
 *  - CON la extensión, la ruta deja de ser el 403 de la extensión. Solo se ejecutan con extensión los GET
 *    representativos de cada grupo (los POST tienen efectos: encolan jobs, escriben, llaman a la IA).
 *
 * Los ids de los `{id}` son inexistentes a propósito: el gate corre antes de resolverlos.
 */
class Gates_por_grupo_Test extends AgentesIaTestCase
{
    /**
     * Todas las rutas gateadas por `agentes_ia`, con su método y la URI concreta. Se agrupan por la forma en
     * que se gatean (el comentario de cada bloque dice de qué módulo son).
     *
     * @return array<int, array{0:string,1:string}>
     */
    protected function rutas_gateadas()
    {
        $rutas = [
            /* Agente personal: chat, mensajes, tarjetas, menciones, configuración y consumo. */
            ['GET', 'api/ai-conversations'],
            ['POST', 'api/ai-conversations'],
            ['DELETE', 'api/ai-conversations/999999'],
            ['GET', 'api/ai-conversations/999999/messages'],
            ['POST', 'api/ai-conversations/999999/messages'],
            ['GET', 'api/ai-conversations/999999/messages/999999'],
            ['GET', 'api/ai-mensajes/999999/imagen/1'],
            ['POST', 'api/ai-conversations/999999/acciones/999999/confirmar'],
            ['POST', 'api/ai-conversations/999999/acciones/999999/cancelar'],
            ['GET', 'api/articles/999999/ficha-asistente'],
            ['GET', 'api/clients/999999/para-cuenta-corriente'],
            ['GET', 'api/user/asistente-config'],
            ['PUT', 'api/user/asistente-config'],
            ['GET', 'api/mi-consumo-ia'],

            /* Conexión por MCP. */
            ['POST', 'api/mcp'],
            ['GET', 'api/mcp'],
            ['DELETE', 'api/mcp'],
            ['GET', 'api/mcp/conexion'],
            ['POST', 'api/mcp/conexion'],
            ['DELETE', 'api/mcp/conexion'],

            /* Mostrador de informes del módulo IA. */
            ['GET', 'api/mostrador/reportes'],
            ['GET', 'api/mostrador/reportes/999999'],
            ['POST', 'api/mostrador/reportes/999999/conversacion'],

            /* Carga de facturas de compra desde una foto. */
            ['GET', 'api/provider-order-scan/pendientes'],
            ['GET', 'api/provider-order-scan/en-curso'],
            ['GET', 'api/provider-order-scan/historial/999999'],
            ['POST', 'api/provider-order-scan'],
            ['GET', 'api/provider-order-scan/00000000-0000-0000-0000-000000000000'],
            ['GET', 'api/provider-order-scan/00000000-0000-0000-0000-000000000000/imagen/1'],
            ['POST', 'api/provider-order-scan/00000000-0000-0000-0000-000000000000/visto'],
            ['POST', 'api/provider-order-scan/00000000-0000-0000-0000-000000000000/confirmar'],
            ['POST', 'api/provider-order-scan/00000000-0000-0000-0000-000000000000/descartar'],

            /* Módulo de WhatsApp: chats, plantillas, recordatorio de cobro, comprobante por el agente. */
            ['GET', 'api/whatsapp-chats'],
            ['GET', 'api/whatsapp-chats/resumen'],
            ['GET', 'api/whatsapp-chats/999999'],
            ['GET', 'api/whatsapp-chats/999999/messages'],
            ['POST', 'api/whatsapp-chats'],
            ['POST', 'api/whatsapp-chats/999999/messages'],
            ['POST', 'api/whatsapp-chats/999999/suggest'],
            ['POST', 'api/whatsapp-chats/999999/summary'],
            ['PUT', 'api/whatsapp-chats/999999/toggle-ai'],
            ['PUT', 'api/whatsapp-chats/999999/link-client'],
            ['PUT', 'api/whatsapp-chats/999999/read'],
            ['PUT', 'api/whatsapp-chats/999999/resolve-follow-up'],
            ['GET', 'api/whatsapp-chats/999999/media/999999'],
            ['POST', 'api/whatsapp-chats/999999/media'],
            ['POST', 'api/whatsapp-chats/999999/send-template'],
            ['PUT', 'api/whatsapp-chats/messages/999999/confirm'],
            ['DELETE', 'api/whatsapp-chats/messages/999999'],
            ['PUT', 'api/whatsapp-chats/messages/999999'],
            ['POST', 'api/sales/999999/send-whatsapp-agent'],
            ['GET', 'api/recordatorio-cobro/preview/999999'],
            ['POST', 'api/recordatorio-cobro/enviar'],
            ['POST', 'api/recordatorio-cobro/enviar-masivo'],
            ['GET', 'api/recordatorio-cobro/lote/00000000-0000-0000-0000-000000000000'],
            ['GET', 'api/whatsapp-templates'],
            ['POST', 'api/whatsapp-templates'],
            ['PUT', 'api/whatsapp-templates/999999'],
            ['DELETE', 'api/whatsapp-templates/999999'],
            ['PUT', 'api/whatsapp-templates/999999/solicitar-alta'],

            /* Configuración del bot de WhatsApp a clientes y su simulador. */
            ['GET', 'api/whatsapp-bot/config'],
            ['POST', 'api/whatsapp-bot/config'],
            ['PUT', 'api/whatsapp-bot/config'],
            ['PUT', 'api/whatsapp-bot/config/999999'],
            ['POST', 'api/whatsapp-bot/simulate-inbound'],

            /* Sugerencias de stock y de compra. */
            ['GET', 'api/stock-suggestion/999999/articles'],
            ['POST', 'api/stock-suggestion/999999/resumen'],
            ['GET', 'api/purchase-suggestion'],
            ['POST', 'api/purchase-suggestion'],
            ['GET', 'api/purchase-suggestion/999999'],
            ['DELETE', 'api/purchase-suggestion/999999'],
            ['GET', 'api/purchase-suggestion/999999/articles'],
            ['POST', 'api/purchase-suggestion/999999/resumen'],
            ['POST', 'api/purchase-suggestion/999999/create-provider-order'],

            /* Asignación automática de imágenes (la valida un modelo de visión) e intentos de búsqueda. */
            ['POST', 'api/google/batch-assign-images'],
            ['GET', 'api/image-assignment-runs'],
            ['GET', 'api/image-assignment-runs/resumen'],
            ['GET', 'api/image-assignment-runs/catalogo/previa'],
            ['POST', 'api/image-assignment-runs/catalogo'],
            ['GET', 'api/image-assignment-runs/por-uuid/00000000-0000-0000-0000-000000000000'],
            ['GET', 'api/image-assignment-runs/999999'],
            ['GET', 'api/image-assignment-runs/999999/items'],
            ['POST', 'api/image-assignment-runs/999999/detener'],
            ['POST', 'api/image-assignment-runs/999999/reanudar'],
            ['POST', 'api/image-assignment-items/aprobar-varios'],
            ['POST', 'api/image-assignment-items/rechazar-varios'],
            ['POST', 'api/image-assignment-items/999999/aprobar'],
            ['POST', 'api/image-assignment-items/999999/rechazar'],
            ['POST', 'api/image-assignment-items/999999/quitar'],
            ['GET', 'api/article-image-search-attempts/recent'],
            ['GET', 'api/article-image-search-attempts/summary/00000000-0000-0000-0000-000000000000'],
            ['GET', 'api/article-image-search-attempts/batch/00000000-0000-0000-0000-000000000000'],

            /* Categorías con IA. */
            ['GET', 'api/category-proposal-runs/resumen'],
            ['GET', 'api/category-proposal-runs/actual'],
            ['PUT', 'api/category-proposal-runs/999999/visto'],
            ['GET', 'api/category-proposal-runs/999999/items'],
            ['POST', 'api/category-proposal-runs/999999/elegir'],
            ['POST', 'api/category-proposal-runs/999999/volver-atras'],
            ['POST', 'api/category-proposal-items/aprobar-varios'],
            ['POST', 'api/category-proposal-items/rechazar-varios'],
            ['POST', 'api/category-proposal-items/999999/aprobar'],
            ['POST', 'api/category-proposal-items/999999/rechazar'],

            /* Descripciones con IA: individual, masiva y Alertas → Catálogo → Descripciones. */
            ['POST', 'api/article-description-ai/preview/999999'],
            ['POST', 'api/article-description-ai/store/999999'],
            ['POST', 'api/article-description-ai/batch-generate'],
            ['GET', 'api/article-description-ai/pending-review'],
            ['PUT', 'api/article-description-ai/approve/999999'],
            ['DELETE', 'api/article-description-ai/discard/999999'],
            ['GET', 'api/article-description-ai/resumen'],
            ['GET', 'api/article-description-ai/revision'],
            ['POST', 'api/article-description-ai/revision/aprobar-varios'],
            ['POST', 'api/article-description-ai/revision/descartar-varios'],
            ['POST', 'api/article-description-ai/revision/999999/aprobar'],
            ['POST', 'api/article-description-ai/revision/999999/descartar'],
            ['PUT', 'api/article-description-ai/revision/999999/publicada'],
            ['POST', 'api/article-description-ai/revision/999999/quitar'],

            /* Ficha de producto por código de barras (GTIN). */
            ['POST', 'api/article-enrichments'],
            ['GET', 'api/article-enrichments/00000000-0000-0000-0000-000000000000'],
            ['DELETE', 'api/article-enrichments/00000000-0000-0000-0000-000000000000'],
        ];

        return $rutas;
    }

    /**
     * GET representativos de cada grupo, que con la extensión dan 200 (o la forma vacía del módulo) y no el 403
     * de la extensión.
     *
     * @return array<int, array{0:string}>
     */
    protected function lecturas_que_funcionan_con_la_extension()
    {
        $uris = [
            'api/ai-conversations',
            'api/mi-consumo-ia',
            'api/mcp/conexion',
            'api/mostrador/reportes',
            'api/provider-order-scan/pendientes',
            'api/provider-order-scan/en-curso',
            'api/whatsapp-chats',
            'api/whatsapp-templates',
            'api/whatsapp-bot/config',
            'api/purchase-suggestion',
            'api/image-assignment-runs',
            'api/image-assignment-runs/resumen',
            'api/article-image-search-attempts/recent',
            'api/category-proposal-runs/resumen',
            'api/article-description-ai/resumen',
            'api/article-description-ai/pending-review',
        ];

        return array_map(function ($uri) {
            return [$uri];
        }, $uris);
    }

    /**
     * Sin la extensión, cada ruta gateada contesta 403 con el mensaje de la extensión y nunca 404.
     *
     * Es UN solo test que recorre todas las rutas (y no un caso por ruta con dataProvider): cada caso paga el
     * arranque completo de la suite (guards de entorno, fixture, transacción) y son más de cien rutas. Si
     * alguna falla, el mensaje las lista todas.
     *
     * @test
     * @return void
     */
    public function sin_la_extension_cada_ruta_contesta_403_y_nunca_404()
    {
        $this->quitar_extension();

        $fallas = [];

        foreach ($this->rutas_gateadas() as $ruta) {

            $metodo = $ruta[0];
            $uri    = $ruta[1];

            $respuesta = $this->json($metodo, $uri, []);

            if ($respuesta->getStatusCode() === 404) {
                $fallas[] = $metodo . ' ' . $uri . ' contestó 404 (el admin lo lee como «versión vieja»).';
            } elseif (! $this->es_el_403_de_la_extension($respuesta)) {
                $fallas[] = $metodo . ' ' . $uri . ' contestó ' . $respuesta->getStatusCode() . ' en vez del 403 de la extensión: ' . substr($respuesta->getContent(), 0, 120);
            }
        }

        $this->assertSame([], $fallas, "Rutas que no exigen agentes_ia:
" . implode("
", $fallas));
    }

    /**
     * Con la extensión, las lecturas representativas de cada grupo ya no son el 403 de la extensión (200).
     *
     * @test
     * @return void
     */
    public function con_la_extension_las_lecturas_de_cada_grupo_responden_200()
    {
        $this->dar_extension();

        $fallas = [];

        foreach ($this->lecturas_que_funcionan_con_la_extension() as $caso) {

            $uri = $caso[0];

            $respuesta = $this->getJson($uri);

            if ($this->es_el_403_de_la_extension($respuesta)) {
                $fallas[] = 'GET ' . $uri . ' sigue pidiendo la extensión aunque el comercio la tiene.';
            } elseif ($respuesta->getStatusCode() !== 200) {
                $fallas[] = 'GET ' . $uri . ' contestó ' . $respuesta->getStatusCode() . ' con la extensión: ' . substr($respuesta->getContent(), 0, 120);
            }
        }

        $this->assertSame([], $fallas, "Lecturas que no dan 200 con agentes_ia:
" . implode("
", $fallas));
    }

    /**
     * Un empleado del comercio hereda la extensión de su dueño (las extensiones son de la EMPRESA): el
     * middleware resuelve al dueño y no al empleado.
     *
     * @test
     * @return void
     */
    public function un_empleado_hereda_la_extension_del_dueno()
    {
        $this->dar_extension();

        $empleado = \App\Models\User::create([
            'name'     => 'Empleado agentes IA',
            'email'    => 'empleado-agentes-ia-' . uniqid() . '@test.local',
            'password' => \Illuminate\Support\Facades\Hash::make('secret'),
            'owner_id' => $this->comercio()->id,
        ]);

        $this->actingAs($empleado, 'web');

        $respuesta = $this->getJson('api/whatsapp-templates');

        $this->assertFalse($this->es_el_403_de_la_extension($respuesta), 'El empleado no hereda la extensión del dueño.');
    }
}
