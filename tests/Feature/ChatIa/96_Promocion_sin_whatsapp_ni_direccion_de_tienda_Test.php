<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaPromocionWhatsappIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\WhatsappDeClientesIaHelper;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Combo;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * Misión agente-mejoras-uso-real (29/10/2026, §4 E3.2 del plan) — la promoción por WhatsApp contra la
 * CONV 24 de Ferre Total.
 *
 * Lo que pasó (29/10/2026, 17:59 a 18:13): el dueño pidió una promo de la bomba Pluvius; el agente dijo
 * *"El negocio no tiene tienda online cargada"*, después *"el negocio sí tiene tienda, lo que no tiene es
 * publicado ese artículo"*, después *"entonces el artículo sí está publicado"*, a *"mandámela primero a
 * mí"* contestó *"No puedo mandarte la promoción a vos solo"*, y recién al «Mandalo» dijo que el negocio
 * no tiene WhatsApp conectado. En Ferre Total `users.online` es NULL (tiene 6.319 artículos publicados) y
 * no hay `whatsapp_bot_configs`. Lo que protege este archivo:
 *
 *  - sin WhatsApp con clientes, la promo y los recordatorios lo dicen DE ENTRADA, con el código
 *    `sin_whatsapp_de_clientes`, antes de resolver artículos o clientes; y el prompt lo avisa en el bloque
 *    de módulos apagados;
 *  - sin la dirección de la tienda, el código `sin_direccion_de_tienda` y un texto que dice qué NO se sabe:
 *    nunca "no está publicado" de un artículo publicado;
 *  - `articulo_no_publicado` (online = 0) y `articulo_sin_slug` (publicado sin dirección propia) separados;
 *  - la regla de «mandámela a mí primero» en la descripción: el bloque previo al sí ES la vista previa.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Promocion_sin_whatsapp_ni_direccion_de_tienda_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio(['agente_confianza' => ConfianzaDelAgenteIaHelper::RESUELTO]);

        Http::fake(['*' => Http::response([], 200)]);
    }

    /**
     * La bomba de la CONV 24 (o el artículo que se pida), con la publicación y el slug que se digan.
     *
     * @param  string  $nombre
     * @param  int  $online
     * @param  string|null  $slug
     * @return Article
     */
    protected function articulo_de_la_promo($nombre = 'ZZE Bomba presurizadora Pluvius PRES100W', $online = 1, $slug = 'zze-bomba-pluvius-pres100w')
    {
        return Article::create([
            'name'        => $nombre,
            'slug'        => $slug,
            'online'      => $online,
            'bar_code'    => '11723',
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'final_price' => 61891.50,
            'cost'        => 40000,
            'stock'       => 5,
            'iva_id'      => 2,
        ]);
    }

    /**
     * El negocio sin WhatsApp con clientes (lo de Ferre Total: ninguna configuración activa).
     *
     * @return void
     */
    protected function sin_whatsapp_de_clientes()
    {
        $this->config->is_active = false;
        $this->config->save();
    }

    /**
     * 🔴 Sin WhatsApp con clientes, la promo lo dice en la PRIMERA llamada, antes de buscar nada: ni el
     * artículo, ni el cliente, ni la tienda.
     *
     * @test
     */
    public function sin_whatsapp_de_clientes_la_promo_lo_dice_de_entrada()
    {
        $this->sin_whatsapp_de_clientes();

        $this->cliente('ACP GROUP S.A.', '5493444101010');

        list($conversation, $assistant) = $this->vuelta('pasame eso de la promocion');

        // Un artículo que ni existe y un cliente que no es: igual contesta primero lo del WhatsApp.
        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto'      => 'Bomba presurizadora Pluvius PRES100W a $61.891,50',
            'articulo'   => 'un artículo que no existe',
            'client_ids' => [999999],
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertSame(WhatsappDeClientesIaHelper::CODIGO_SIN_WHATSAPP, $respuesta['codigo']);
        $this->assertStringContainsString('no tiene conectado el WhatsApp con sus clientes', $respuesta['error']);
        $this->assertStringContainsString('no es el WhatsApp por el que la persona habla con vos', $respuesta['error']);
        $this->assertStringContainsString('pedir_ayuda_a_soporte', $respuesta['error']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count(), 'No quedó ninguna propuesta.');

        // Lo mismo con los recordatorios de cobro.
        $recordatorio = $this->herramienta($conversation, $assistant, 'proponer_recordatorios_de_cobro', []);

        $this->assertFalse($recordatorio['ok']);
        $this->assertSame(WhatsappDeClientesIaHelper::CODIGO_SIN_WHATSAPP, $recordatorio['codigo']);
        $this->assertStringContainsString('recordatorios de cobro', $recordatorio['error']);

        // Con el WhatsApp conectado, el mismo pedido sigue su camino de siempre (acá: el artículo que no existe).
        $this->config->is_active = true;
        $this->config->save();

        $con_whatsapp = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto'    => 'Bomba presurizadora Pluvius PRES100W a $61.891,50',
            'articulo' => 'un artículo que no existe',
        ]);

        $this->assertArrayNotHasKey('codigo', $con_whatsapp);
        $this->assertStringContainsString('No encontré ningún artículo', $con_whatsapp['error']);
    }

    /**
     * El prompt avisa desde el primer mensaje que no hay WhatsApp con clientes (y ofrece soporte).
     *
     * @test
     */
    public function el_prompt_avisa_que_no_hay_whatsapp_con_clientes()
    {
        list($conversation) = $this->vuelta('hola');

        $service = new AsistenteIaService();

        $con = $service->build_system_prompt($conversation, $this->dueno->fresh(), true, true);

        $this->assertStringNotContainsString(WhatsappDeClientesIaHelper::linea_para_el_prompt(), $con);

        $this->sin_whatsapp_de_clientes();

        $sin = $service->build_system_prompt($conversation, $this->dueno->fresh(), true, true);

        $this->assertStringContainsString('Módulos que este negocio NO tiene', $sin);
        $this->assertStringContainsString(WhatsappDeClientesIaHelper::linea_para_el_prompt(), $sin);
        $this->assertStringContainsString('pedir_ayuda_a_soporte', $sin);

        // Sin herramientas de carga no hay nada que prometer: la línea no va.
        $this->assertStringNotContainsString(WhatsappDeClientesIaHelper::linea_para_el_prompt(), $service->build_system_prompt($conversation, $this->dueno->fresh(), false, true));
    }

    /**
     * 🔴 Ferre Total: `users.online` vacío y el artículo publicado. El motivo dice que NO SE SABE la
     * dirección y que eso no significa que no esté publicado; nunca "no está publicado".
     *
     * @test
     */
    public function sin_la_direccion_de_la_tienda_no_dice_que_el_articulo_no_esta_publicado()
    {
        User::where('id', $this->dueno->id)->update(['online' => null]);

        $this->cliente('ACP GROUP S.A.', '5493444101010');

        $bomba = $this->articulo_de_la_promo();
        $this->articulo_de_la_promo('ZZE Otra publicada', 1, 'zze-otra-publicada');

        list($conversation, $assistant) = $this->vuelta('mandale la promo de la bomba a ACP Group');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto'      => 'Bomba presurizadora Pluvius PRES100W a $61.891,50',
            'article_id' => $bomba->id,
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertSame(PropuestaPromocionWhatsappIaHelper::CODIGO_SIN_DIRECCION_DE_TIENDA, $respuesta['codigo']);
        $this->assertStringContainsString('No tengo la dirección de la tienda', $respuesta['error']);
        $this->assertStringContainsString('NO quiere decir que el negocio no tenga tienda', $respuesta['error']);
        $this->assertStringContainsString('"ZZE Bomba presurizadora Pluvius PRES100W" figura publicado', $respuesta['error']);
        $this->assertStringContainsString('2 artículos publicados', $respuesta['error']);
        $this->assertStringNotContainsString('no está publicado', $respuesta['error']);

        // Con varios artículos y con un combo, el mismo motivo.
        $otra = Article::where('user_id', $this->dueno->id)->where('name', 'ZZE Otra publicada')->first();

        $varios = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto'       => 'Bomba y otra',
            'article_ids' => [$bomba->id, $otra->id],
        ]);

        $this->assertSame(PropuestaPromocionWhatsappIaHelper::CODIGO_SIN_DIRECCION_DE_TIENDA, $varios['codigo']);
        $this->assertStringNotContainsString('no está publicado', $varios['error']);

        Combo::create(['num' => 1, 'name' => 'ZZE Combo bomba', 'price' => 70000, 'cost' => 50000, 'online' => 1, 'user_id' => $this->dueno->id]);

        $combo = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto' => 'Combo bomba a $70.000',
            'combo' => 'ZZE Combo bomba',
        ]);

        $this->assertSame(PropuestaPromocionWhatsappIaHelper::CODIGO_SIN_DIRECCION_DE_TIENDA, $combo['codigo']);

        // Una promo sin artículo ni combo sale igual, y la tarjeta dice que falta la dirección, no que no hay tienda.
        $general = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto' => '20% en toda la ferretería este fin de semana',
        ]);

        $this->assertTrue(!empty($general['ok']), json_encode($general));

        $tarjeta = AiMessageAction::find($general['tarjeta_id']);
        $renglones = json_encode($tarjeta->presentacion['renglones'], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('sin link: no tengo cargada la dirección de la tienda del negocio', $renglones);
        $this->assertStringNotContainsString('no tiene tienda', $renglones);
    }

    /**
     * `online = 0` y "publicado sin slug" son dos motivos distintos, cada uno con su código.
     *
     * @test
     */
    public function el_articulo_sin_publicar_y_el_publicado_sin_slug_tienen_su_codigo()
    {
        $this->cliente('ACP GROUP S.A.', '5493444101010');

        $oculto = $this->articulo_de_la_promo('ZZE Bomba oculta', 0, 'zze-bomba-oculta');
        $sin_slug = $this->articulo_de_la_promo('ZZE Bomba sin slug', 1, '');

        list($conversation, $assistant) = $this->vuelta('mandales la promo');

        $no_publicado = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto' => 'Bomba oculta a mitad de precio', 'article_id' => $oculto->id,
        ]);

        $this->assertSame(PropuestaPromocionWhatsappIaHelper::CODIGO_ARTICULO_NO_PUBLICADO, $no_publicado['codigo']);
        $this->assertStringContainsString('El artículo "ZZE Bomba oculta" no está publicado en la tienda', $no_publicado['error']);

        $falta_slug = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto' => 'Bomba a mitad de precio', 'article_id' => $sin_slug->id,
        ]);

        $this->assertSame(PropuestaPromocionWhatsappIaHelper::CODIGO_ARTICULO_SIN_SLUG, $falta_slug['codigo']);
        $this->assertStringContainsString('"ZZE Bomba sin slug" figura publicado', $falta_slug['error']);
        $this->assertStringNotContainsString('no está publicado', $falta_slug['error']);
        $this->assertStringContainsString('guardándolo de nuevo', $falta_slug['error']);

        // Y en una promo de varios, el mismo código para el que no tiene slug.
        $varios = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto' => 'Dos bombas', 'article_ids' => [$this->articulo_de_la_promo('ZZE Bomba buena', 1, 'zze-bomba-buena')->id, $sin_slug->id],
        ]);

        $this->assertSame(PropuestaPromocionWhatsappIaHelper::CODIGO_ARTICULO_SIN_SLUG, $varios['codigo']);
    }

    /**
     * 🔴 «Mandámela a mí primero»: la descripción dice que el bloque previo al sí ES la vista previa exacta
     * y que nunca se dice que no se puede; y ese bloque trae el texto tal cual sale.
     *
     * @test
     */
    public function mandamela_a_mi_primero_el_bloque_es_la_vista_previa_exacta()
    {
        $descripcion = '';

        foreach (HerramientasDeCarga::definiciones() as $definicion) {
            if ($definicion['name'] === 'proponer_promocion_por_whatsapp') {
                $descripcion = $definicion['description'];
            }
        }

        $this->assertStringContainsString('mandámela a mí primero', $descripcion);
        $this->assertStringContainsString('ES la vista previa exacta', $descripcion);
        $this->assertStringContainsString('nunca digas que no se puede', $descripcion);
        $this->assertStringContainsString('sin_whatsapp_de_clientes', $descripcion);
        $this->assertStringContainsString('sin_direccion_de_tienda', $descripcion);

        // El bloque que se agrega antes del sí trae el texto exacto que le llega al cliente.
        $bomba = $this->articulo_de_la_promo();
        $this->cliente('ACP GROUP S.A.', '5493444101010');

        list($conversation, $assistant) = $this->vuelta('mandámela primero a mí y te doy el ok');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto'      => 'Bomba presurizadora Pluvius PRES100W a $61.891,50',
            'article_id' => $bomba->id,
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $bloque = $this->bloque($respuesta);

        $this->assertStringContainsString('Hola ACP GROUP S.A.! Bomba presurizadora Pluvius PRES100W a $61.891,50', $bloque);
        $this->assertStringContainsString('https://tienda-zze.test/articulos/zze-bomba-pluvius-pres100w/' . $this->dueno->id, $bloque);
    }
}
