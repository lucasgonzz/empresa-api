<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ProgresoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaPromocionWhatsappIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\WhatsappDeClientesIaHelper;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Combo;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use App\Services\AsistenteIa\Mcp\McpHerramientasHelper;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * Misión agente-mejoras-diario-30-10 (B2, 31/10/2026) — el texto de la promo para que la persona lo reenvíe
 * ella, y el comprobante «a mí».
 *
 * 🔴 LOS CASOS REALES (análisis diarios de las 22 h):
 *  - 30/10, problema #4 (con 👎): el dueño pidió el texto de una promo para mandarlo él. El negocio no tiene
 *    el WhatsApp con clientes conectado y WhatsappDeClientesIaHelper::texto_sin_whatsapp() le decía al
 *    modelo «No armes el texto…»: la promo solo existía como envío masivo y el dueño se quedó sin texto.
 *  - 31/10, problema #7: pidió un comprobante «a mi WhatsApp» y el agente le habló de la línea de clientes.
 *
 * Lo que protege este archivo:
 *  - armar_texto_de_promocion devuelve el texto (sin «Hola {nombre}!») con el link REAL de la tienda, aunque
 *    no haya WhatsApp con clientes, sin crear ninguna tarjeta;
 *  - sin la dirección de la tienda (o con el artículo sin publicar) el texto sale igual, sin link y con el
 *    código del motivo; un link escrito por el modelo es un error;
 *  - el texto del corte y el renglón del prompt ya no dicen «No armes el texto» y mandan a
 *    armar_texto_de_promocion y a consultar_link_de_pdf;
 *  - la herramienta va al FINAL de la lista (prefijo del caché), tiene su `case`, su etiqueta de progreso y es
 *    de lectura para el MCP; con el WhatsApp conectado, proponer_promocion_por_whatsapp sigue igual.
 *
 * 🔴 Nada sale a internet: Http::fake y el WhatsApp del fixture en envío simulado.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Promo_para_reenviar_y_comprobante_al_dueno_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    /** El texto de la promo que redacta el modelo en estos tests. */
    const TEXTO = 'Bomba presurizadora Pluvius PRES100W a $61.891,50';

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio(['agente_confianza' => ConfianzaDelAgenteIaHelper::RESUELTO]);

        Http::fake(['*' => Http::response([], 200)]);
    }

    /**
     * Un artículo del negocio del test, con la publicación y el slug que se digan.
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
     * El link de la tienda que arma el sistema para un artículo de este negocio (la misma regla que la promo).
     *
     * @param  Article  $articulo
     * @return string
     */
    protected function link_de($articulo)
    {
        return 'https://tienda-zze.test/articulos/' . rawurlencode((string) $articulo->slug) . '/' . (int) $this->dueno->id;
    }

    /**
     * La definición de una herramienta de carga por su nombre.
     *
     * @param  string  $nombre
     * @return array
     */
    protected function definicion($nombre)
    {
        foreach (HerramientasDeCarga::definiciones() as $definicion) {
            if ($definicion['name'] === $nombre) {
                return $definicion;
            }
        }

        $this->fail('No está declarada la herramienta ' . $nombre);

        return [];
    }

    /**
     * 🔴 30/10 #4: sin WhatsApp con clientes, el texto para reenviar sale con el link REAL del artículo, sin
     * saludo de envío masivo y sin ninguna tarjeta.
     *
     * @test
     */
    public function sin_whatsapp_de_clientes_el_texto_para_reenviar_sale_con_el_link_y_sin_tarjeta()
    {
        $this->sin_whatsapp_de_clientes();

        $bomba = $this->articulo_de_la_promo();

        list($conversation, $assistant) = $this->vuelta('pasame el texto de la promo de la bomba así lo mando yo');

        $respuesta = $this->herramienta($conversation, $assistant, 'armar_texto_de_promocion', [
            'texto'      => self::TEXTO,
            'article_id' => $bomba->id,
            'vigencia'   => 'hasta el domingo 2/11',
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta, JSON_UNESCAPED_UNICODE));
        $this->assertArrayNotHasKey('codigo', $respuesta, 'No es el corte del WhatsApp.');
        $this->assertNull($respuesta['sin_link']);
        $this->assertNull($respuesta['motivo_sin_link']);

        $texto = $respuesta['texto_para_reenviar'];

        $this->assertStringStartsWith(self::TEXTO, $texto, 'Sin el «Hola {nombre}!» del envío masivo.');
        $this->assertStringNotContainsString('Hola ', $texto);
        $this->assertStringContainsString("\nVálida hasta el domingo 2/11.", $texto);
        $this->assertStringContainsString($this->link_de($bomba), $texto, 'El link real de la tienda, armado por el sistema.');
        $this->assertSame(self::TEXTO . "\nVálida hasta el domingo 2/11.\n" . $this->link_de($bomba), $texto);

        $this->assertSame([['nombre' => $bomba->name, 'link' => $this->link_de($bomba)]], $respuesta['links']);
        $this->assertStringContainsString('No se mandó nada', $respuesta['nota']);
        $this->assertStringContainsString('TAL CUAL', $respuesta['nota']);

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count(), 'No crea ninguna tarjeta.');

        // Con varios artículos, un renglón «nombre: link» por artículo.
        $otra = $this->articulo_de_la_promo('ZZE Amoladora 115 mm', 1, 'zze-amoladora-115');

        $varios = $this->herramienta($conversation, $assistant, 'armar_texto_de_promocion', [
            'texto'       => 'Bomba Pluvius a $61.891,50 y amoladora 115 mm a $45.000',
            'article_ids' => [$bomba->id, $otra->id],
        ]);

        $this->assertTrue($varios['ok'], json_encode($varios, JSON_UNESCAPED_UNICODE));
        $this->assertCount(2, $varios['links']);
        $this->assertStringContainsString($bomba->name . ': ' . $this->link_de($bomba), $varios['texto_para_reenviar']);
        $this->assertStringContainsString($otra->name . ': ' . $this->link_de($otra), $varios['texto_para_reenviar']);

        // Una promo general lleva la portada de la tienda.
        $general = $this->herramienta($conversation, $assistant, 'armar_texto_de_promocion', ['texto' => '20% en toda la ferretería este fin de semana']);

        $this->assertTrue($general['ok']);
        $this->assertSame("20% en toda la ferretería este fin de semana\nhttps://tienda-zze.test", $general['texto_para_reenviar']);

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count(), 'Ninguna llamada crea tarjetas.');
    }

    /**
     * Sin `users.online` (Ferre Total) el texto sale igual, SIN link, con `sin_link = sin_direccion_de_tienda`
     * y un motivo que no dice que no hay tienda ni que el artículo no está publicado.
     *
     * @test
     */
    public function sin_la_direccion_de_la_tienda_el_texto_sale_sin_link_y_con_el_motivo()
    {
        User::where('id', $this->dueno->id)->update(['online' => null]);

        $this->sin_whatsapp_de_clientes();

        $bomba = $this->articulo_de_la_promo();

        list($conversation, $assistant) = $this->vuelta('dame la promo para mandarla yo');

        $respuesta = $this->herramienta($conversation, $assistant, 'armar_texto_de_promocion', [
            'texto'      => self::TEXTO,
            'article_id' => $bomba->id,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta, JSON_UNESCAPED_UNICODE));
        $this->assertSame(PropuestaPromocionWhatsappIaHelper::CODIGO_SIN_DIRECCION_DE_TIENDA, $respuesta['sin_link']);
        $this->assertSame(self::TEXTO, $respuesta['texto_para_reenviar'], 'El texto, sin link.');
        $this->assertSame([], $respuesta['links']);
        $this->assertStringContainsString('el texto va sin link', $respuesta['motivo_sin_link']);
        $this->assertStringContainsString('NO quiere decir que el negocio no tenga tienda', $respuesta['motivo_sin_link']);
        $this->assertStringNotContainsString('no está publicado', $respuesta['motivo_sin_link']);

        // La promo general, igual.
        $general = $this->herramienta($conversation, $assistant, 'armar_texto_de_promocion', ['texto' => '20% en toda la ferretería']);

        $this->assertTrue($general['ok']);
        $this->assertSame(PropuestaPromocionWhatsappIaHelper::CODIGO_SIN_DIRECCION_DE_TIENDA, $general['sin_link']);
        $this->assertSame('20% en toda la ferretería', $general['texto_para_reenviar']);

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * El artículo sin publicar y el combo sin publicar: el texto sale sin link, con su código. Lo que no se
     * resuelve (un artículo que no existe) sigue siendo un error para el modelo.
     *
     * @test
     */
    public function sin_publicar_el_texto_sale_sin_link_y_lo_que_no_existe_es_un_error()
    {
        $oculto = $this->articulo_de_la_promo('ZZE Bomba oculta', 0, 'zze-bomba-oculta');

        list($conversation, $assistant) = $this->vuelta('armame el texto para reenviar');

        $respuesta = $this->herramienta($conversation, $assistant, 'armar_texto_de_promocion', [
            'texto' => 'Bomba oculta a mitad de precio', 'article_id' => $oculto->id,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta, JSON_UNESCAPED_UNICODE));
        $this->assertSame(PropuestaPromocionWhatsappIaHelper::CODIGO_ARTICULO_NO_PUBLICADO, $respuesta['sin_link']);
        $this->assertSame('Bomba oculta a mitad de precio', $respuesta['texto_para_reenviar']);
        $this->assertStringContainsString('El artículo "ZZE Bomba oculta" no está publicado en la tienda', $respuesta['motivo_sin_link']);

        Combo::create(['num' => 1, 'name' => 'ZZE Combo asado', 'price' => 25000, 'cost' => 15000, 'online' => 0, 'user_id' => $this->dueno->id]);

        $combo = $this->herramienta($conversation, $assistant, 'armar_texto_de_promocion', ['texto' => 'Combo asado a $25.000', 'combo' => 'ZZE Combo asado']);

        $this->assertTrue($combo['ok'], json_encode($combo, JSON_UNESCAPED_UNICODE));
        $this->assertSame(PropuestaPromocionWhatsappIaHelper::CODIGO_COMBO_NO_PUBLICADO, $combo['sin_link']);
        $this->assertSame(0, (int) Combo::where('user_id', $this->dueno->id)->value('online'), 'No se publica nada: no hay confirmación.');

        $no_existe = $this->herramienta($conversation, $assistant, 'armar_texto_de_promocion', ['texto' => 'Algo a $10', 'articulo' => 'un artículo que no existe']);

        $this->assertFalse($no_existe['ok']);
        $this->assertStringContainsString('No encontré ningún artículo', $no_existe['error']);
    }

    /**
     * 🔴 F6 de la ronda de arreglos (31/10/2026), el caso real de Pablo (9 clavos): con más artículos que
     * MAXIMO_DE_ARTICULOS el texto para reenviar NO es un error: sale con el link a la portada de la tienda,
     * como una promo general, y la nota lo dice. La promo por WhatsApp (el envío masivo) mantiene su tope.
     *
     * @test
     */
    public function con_mas_articulos_que_el_tope_el_texto_sale_con_el_link_de_la_tienda()
    {
        $this->sin_whatsapp_de_clientes();

        $ids = [];

        for ($i = 1; $i <= 9; $i++) {
            $ids[] = $this->articulo_de_la_promo('ZZE Clavo punta París ' . $i . '"', 1, 'zze-clavo-' . $i)->id;
        }

        $this->assertGreaterThan(PropuestaPromocionWhatsappIaHelper::MAXIMO_DE_ARTICULOS, count($ids));

        list($conversation, $assistant) = $this->vuelta('pasame el texto de la promo de los clavos así lo mando yo');

        $respuesta = $this->herramienta($conversation, $assistant, 'armar_texto_de_promocion', [
            'texto'       => 'Clavos punta París de todas las medidas con 15% off',
            'article_ids' => $ids,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta, JSON_UNESCAPED_UNICODE));
        $this->assertNull($respuesta['sin_link']);
        $this->assertSame("Clavos punta París de todas las medidas con 15% off\nhttps://tienda-zze.test", $respuesta['texto_para_reenviar']);
        $this->assertSame([['nombre' => 'Tienda', 'link' => 'https://tienda-zze.test']], $respuesta['links']);
        $this->assertStringStartsWith('Son 9 artículos: va el link a la tienda, no uno por artículo.', $respuesta['nota']);
        $this->assertStringContainsString('TAL CUAL', $respuesta['nota'], 'La nota de siempre sigue.');
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        // La promo por WhatsApp (envío masivo) con el WhatsApp conectado sigue con su tope de artículos.
        $this->config->is_active = true;
        $this->config->save();

        list($conversation_2, $assistant_2) = $this->vuelta('mandales la promo de los clavos');

        $promo = $this->herramienta($conversation_2, $assistant_2, 'proponer_promocion_por_whatsapp', [
            'texto'       => 'Clavos punta París de todas las medidas con 15% off',
            'article_ids' => $ids,
        ]);

        $this->assertFalse($promo['ok'], json_encode($promo, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('Una promo lleva hasta ' . PropuestaPromocionWhatsappIaHelper::MAXIMO_DE_ARTICULOS . ' artículos', $promo['error']);
    }

    /**
     * F6: un combo sin publicar y SIN precio no corta el texto para reenviar (acá no se publica nada): sale sin
     * link y con el motivo. El combo sigue sin publicar.
     *
     * @test
     */
    public function un_combo_sin_publicar_y_sin_precio_no_corta_el_texto_para_reenviar()
    {
        Combo::create(['num' => 1, 'name' => 'ZZE Combo sin precio', 'price' => 0, 'cost' => 0, 'online' => 0, 'user_id' => $this->dueno->id]);

        list($conversation, $assistant) = $this->vuelta('armame el texto del combo para reenviarlo');

        $respuesta = $this->herramienta($conversation, $assistant, 'armar_texto_de_promocion', ['texto' => 'Combo parrillero a precio especial', 'combo' => 'ZZE Combo sin precio']);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta, JSON_UNESCAPED_UNICODE));
        $this->assertSame(PropuestaPromocionWhatsappIaHelper::CODIGO_COMBO_SIN_PRECIO, $respuesta['sin_link']);
        $this->assertSame('Combo parrillero a precio especial', $respuesta['texto_para_reenviar'], 'El texto, sin link.');
        $this->assertSame([], $respuesta['links']);
        $this->assertStringContainsString('El combo "ZZE Combo sin precio" no está publicado en la tienda y no tiene precio cargado', $respuesta['motivo_sin_link']);
        $this->assertStringContainsString('el texto va sin link', $respuesta['motivo_sin_link']);
        $this->assertSame(0, (int) Combo::where('user_id', $this->dueno->id)->value('online'), 'No se publica nada.');
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * F6 (c): la descripción de proponer_promocion_por_whatsapp, después de lo de «mandámela a mí primero», manda
     * el «pasame el texto y lo mando yo» a armar_texto_de_promocion.
     *
     * @test
     */
    public function la_promo_por_whatsapp_manda_el_texto_para_mandarlo_ella_a_armar_texto_de_promocion()
    {
        $descripcion = $this->definicion('proponer_promocion_por_whatsapp')['description'];

        $frase = 'Si en cambio pide el texto para mandarlo ELLA ("pasame el texto y lo mando yo"), no es un envío: usá armar_texto_de_promocion.';

        $this->assertStringContainsString($frase, $descripcion);
        $this->assertGreaterThan(mb_strpos($descripcion, 'mandámela a mí primero'), mb_strpos($descripcion, $frase), 'Va después de lo de «mandámela a mí primero».');
    }

    /**
     * Un link escrito por el modelo es un error (los arma el sistema), y sin texto también.
     *
     * @test
     */
    public function un_texto_con_link_o_sin_texto_es_un_error()
    {
        $bomba = $this->articulo_de_la_promo();

        list($conversation, $assistant) = $this->vuelta('pasame el texto de la promo');

        foreach (['Bomba a $61.891 en https://mitienda.com/bomba', 'Bomba a $61.891, mirala en www.ferreteria.com.ar', 'Bomba en tienda-zze.test/ofertas'] as $con_link) {

            $respuesta = $this->herramienta($conversation, $assistant, 'armar_texto_de_promocion', ['texto' => $con_link, 'article_id' => $bomba->id]);

            $this->assertFalse($respuesta['ok'], $con_link);
            $this->assertSame(PropuestaPromocionWhatsappIaHelper::MENSAJE_TEXTO_CON_LINK, $respuesta['error'], $con_link);
        }

        $sin_texto = $this->herramienta($conversation, $assistant, 'armar_texto_de_promocion', ['article_id' => $bomba->id]);

        $this->assertFalse($sin_texto['ok']);
        $this->assertStringContainsString('Redactalo vos', $sin_texto['error']);

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * 🔴 El texto del corte sin WhatsApp y el renglón del prompt: ya no dicen «No armes el texto» y mandan a
     * armar_texto_de_promocion y a consultar_link_de_pdf; el prompt del negocio sin WhatsApp lo lleva.
     *
     * @test
     */
    public function el_corte_y_el_prompt_mandan_al_texto_para_reenviar_y_al_link_del_pdf()
    {
        foreach (['promociones', 'recordatorios de cobro'] as $que) {

            $texto = WhatsappDeClientesIaHelper::texto_sin_whatsapp($que);

            $this->assertStringNotContainsString('No armes el texto', $texto, $que);

            /*
             * Ronda de arreglos (F6, 31/10/2026): el texto para reenviar es de una PROMO; en el corte de los
             * recordatorios de cobro no se nombra armar_texto_de_promocion (sí el link del comprobante y la
             * conexión ofrecida aparte).
             */
            if ($que === 'promociones') {
                $this->assertStringContainsString('armar_texto_de_promocion', $texto, $que);
            } else {
                $this->assertStringNotContainsString('armar_texto_de_promocion', $texto, $que);
            }

            $this->assertStringContainsString('consultar_link_de_pdf', $texto, $que);
            $this->assertStringContainsString('Eso es solo hacia los CLIENTES', $texto, $que);
            $this->assertStringContainsString('nunca como condición', $texto, $que);
            // Lo de siempre sigue.
            $this->assertStringContainsString('no es el WhatsApp por el que la persona habla con vos', $texto, $que);
            $this->assertStringContainsString('pedir_ayuda_a_soporte', $texto, $que);
        }

        $linea = WhatsappDeClientesIaHelper::linea_para_el_prompt();

        $this->assertStringContainsString('armar_texto_de_promocion', $linea);
        $this->assertStringContainsString('consultar_link_de_pdf', $linea);
        $this->assertStringContainsString('"a mi WhatsApp"', $linea);
        $this->assertStringEndsWith('nunca como condición)', $linea, 'Cierra el paréntesis sin el punto: el punto lo pone el llamador.');

        // El prompt del negocio sin WhatsApp lo lleva en el bloque de módulos apagados.
        $this->sin_whatsapp_de_clientes();

        list($conversation) = $this->vuelta('hola');

        $prompt = (new AsistenteIaService())->build_system_prompt($conversation, $this->dueno->fresh(), true, true);

        $this->assertStringContainsString($linea, $prompt);

        // Y la promo por WhatsApp sin WhatsApp contesta el corte con el texto nuevo.
        list($conversation, $assistant) = $this->vuelta('mandales la promo de la bomba');

        $corte = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => self::TEXTO]);

        $this->assertSame(WhatsappDeClientesIaHelper::CODIGO_SIN_WHATSAPP, $corte['codigo']);
        $this->assertStringContainsString('armar_texto_de_promocion', $corte['error']);
    }

    /**
     * La herramienta precede a cuotas en el cierre de la lista común (prefijo del caché), con su `case`, su etiqueta de progreso,
     * de lectura para el MCP, y una descripción que manda a proponer_promocion_por_whatsapp para «mandales».
     * Y con el WhatsApp conectado, proponer_promocion_por_whatsapp sigue dejando su tarjeta como siempre.
     *
     * @test
     */
    public function la_herramienta_va_al_final_y_la_promo_con_whatsapp_sigue_igual()
    {
        $nombres = HerramientasDeCarga::nombres();

        $this->assertSame(
            ['armar_texto_de_promocion', 'proponer_cuotas_tienda'],
            // Fichas verificadas se añade detrás de cuotas: el prefijo de promo/cuotas queda intacto.
            // Los avisos del dueño (C2, 13/11/2026) se suman detrás de las fichas.
            array_slice($nombres, -10, 2),
            'Las herramientas posteriores se agregan detrás del prefijo de promo y cuotas.'
        );
        $this->assertSame(['proponer_descripcion_verificada', 'proponer_avisos_por_whatsapp'], array_slice($nombres, -8, 2));
        $this->assertTrue(HerramientasDeCarga::maneja('armar_texto_de_promocion'));
        $this->assertFalse(HerramientasDeCarga::es_de_carga('armar_texto_de_promocion'), 'No es una carga: no escala al Profundo.');
        $this->assertStringContainsString("case 'armar_texto_de_promocion':", file_get_contents(app_path('Services/AsistenteIa/HerramientasDeCarga.php')));
        $this->assertSame('Armando el texto de la promo…', ProgresoIaHelper::etiqueta('armar_texto_de_promocion'));
        $this->assertContains('armar_texto_de_promocion', McpHerramientasHelper::LECTURAS_POR_NOMBRE);

        $definicion = $this->definicion('armar_texto_de_promocion');

        $this->assertSame(['texto'], $definicion['input_schema']['required']);
        $this->assertStringContainsString('proponer_promocion_por_whatsapp', $definicion['description']);
        $this->assertStringContainsString('NO manda nada ni deja tarjeta', $definicion['description']);
        $this->assertStringContainsString('aunque el negocio no tenga conectado el WhatsApp', $definicion['description']);

        // Con el WhatsApp conectado, «mandales» sigue siendo la promo de siempre: tarjeta y bloque.
        $bomba = $this->articulo_de_la_promo();
        $this->cliente('ACP GROUP S.A.', '5493444101010');

        list($conversation, $assistant) = $this->vuelta('mandales la promo de la bomba');

        $promo = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto'      => self::TEXTO,
            'article_id' => $bomba->id,
        ]);

        $this->assertTrue(!empty($promo['ok']), json_encode($promo, JSON_UNESCAPED_UNICODE));
        $this->assertSame(AiMessageAction::TIPO_PROMOCION_WHATSAPP, AiMessageAction::find($promo['tarjeta_id'])->tipo);

        // El texto para reenviar con el WhatsApp conectado también sale, y no toca esa tarjeta.
        $para_reenviar = $this->herramienta($conversation, $assistant, 'armar_texto_de_promocion', [
            'texto'      => self::TEXTO,
            'article_id' => $bomba->id,
        ]);

        $this->assertTrue($para_reenviar['ok']);
        $this->assertSame(1, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($promo['tarjeta_id'])->estado_guardado());
    }
}
