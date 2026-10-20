<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaPromocionWhatsappIaHelper;
use App\Jobs\SendPromocionWhatsappJob;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-arreglos-segunda-prueba (19/10/2026) — N5: la promo por WhatsApp de varios artículos.
 *
 * EL CASO REAL (segunda prueba por WhatsApp contra demo3, sin el módulo de Combos): el prompt y un error
 * de la herramienta le decían al modelo que ofreciera "la promo del o de los artículos, cada uno con su
 * link", pero proponer_promocion_por_whatsapp aceptaba UN artículo. Y como la herramienta devolvía
 * `faltan` sin texto, el modelo le pedía al dueño que le dictara la promo.
 *
 * Lo que protege este archivo:
 *  - con `article_ids` (hasta 5) cada artículo pasa por las guardas de siempre y lleva su link: un
 *    renglón por artículo en el texto libre, la portada en la plantilla, y el bloque lo dice;
 *  - uno despublicado corta nombrándolo; seis es demasiado;
 *  - al confirmar se revalida CADA link;
 *  - sin texto es un error para el modelo (no `faltan`), y un texto con un link también;
 *  - un job encolado sin la propiedad nueva sale como siempre.
 *
 * Nada sale a la red: WhatsApp en envío simulado y Http::fake.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Promocion_de_varios_articulos_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio(['agente_confianza' => ConfianzaDelAgenteIaHelper::DIRECTO]);

        Http::fake(['*' => Http::response([], 200)]);
    }

    /**
     * Un artículo del negocio, publicado (o no) en la tienda.
     *
     * @param  string  $nombre
     * @param  string  $slug
     * @param  int  $online
     * @return Article
     */
    protected function articulo_de_la_tienda($nombre, $slug, $online = 1)
    {
        return Article::create([
            'name'        => $nombre,
            'slug'        => $slug,
            'online'      => $online,
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'final_price' => 10000,
            'cost'        => 5000,
            'stock'       => 10,
            'iva_id'      => 2,
        ]);
    }

    /**
     * El link de un artículo en la tienda del negocio del test.
     *
     * @param  string  $slug
     * @return string
     */
    protected function link($slug)
    {
        return 'https://tienda-zze.test/articulos/' . $slug . '/' . $this->dueno->id;
    }

    /**
     * Las promociones que salieron de este negocio.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function promociones_enviadas()
    {
        return WhatsappChatMessage::where('direction', 'out')
            ->where('source', PropuestaPromocionWhatsappIaHelper::SOURCE)
            ->whereIn('whatsapp_chat_id', WhatsappChat::where('user_id', $this->dueno->id)->select('id'))
            ->orderBy('id')
            ->get();
    }

    /**
     * 🔴 Dos artículos: un renglón con su link por artículo en el mensaje, la portada en la plantilla
     * (que tiene un solo lugar para el link), y la tarjeta y el bloque lo dicen. Con el sí sale así.
     *
     * @test
     */
    public function la_promo_de_dos_articulos_lleva_un_link_por_articulo_y_la_portada_en_la_plantilla()
    {
        $taladro = $this->articulo_de_la_tienda('ZZE Taladro 750W', 'zze-taladro-750w');
        $amoladora = $this->articulo_de_la_tienda('ZZE Amoladora 115', 'zze-amoladora-115');

        $ana = $this->cliente('Ana Gomez', '5493444101010');
        $this->chat_abierto($ana);
        $this->cliente('Bruno Diaz', '5493444202020');

        list($conversation, $assistant) = $this->vuelta('mandales una promo del taladro y la amoladora');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto'       => 'Taladro 750W a $ 89.990 y amoladora 115 a $ 54.990, solo esta semana',
            'article_ids' => [$taladro->id, $amoladora->id],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $mostrar = $this->para_mostrar($respuesta);

        // El mensaje: el texto del modelo y un renglón por artículo con su link.
        $this->assertStringContainsString(
            "Hola Ana Gomez! Taladro 750W a \$ 89.990 y amoladora 115 a \$ 54.990, solo esta semana\n"
            . 'ZZE Taladro 750W: ' . $this->link('zze-taladro-750w') . "\n"
            . 'ZZE Amoladora 115: ' . $this->link('zze-amoladora-115'),
            $mostrar['texto_que_sale']
        );

        // La plantilla: la portada de la tienda, que es lo único que entra.
        $this->assertStringContainsString('solo esta semana. Miralo en https://tienda-zze.test', $mostrar['texto_que_sale']);

        $bloque = $this->bloque($respuesta);

        $this->assertStringContainsString('Links (uno por artículo): ZZE Taladro 750W: ' . $this->link('zze-taladro-750w'), $bloque);
        $this->assertStringContainsString('Por plantilla va el link de la tienda: https://tienda-zze.test', $bloque);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame('https://tienda-zze.test', $tarjeta->datos['link']);
        $this->assertSame([
            ['article_id' => (int) $taladro->id, 'nombre' => 'ZZE Taladro 750W', 'link' => $this->link('zze-taladro-750w')],
            ['article_id' => (int) $amoladora->id, 'nombre' => 'ZZE Amoladora 115', 'link' => $this->link('zze-amoladora-115')],
        ], $tarjeta->datos['articulos']);

        $etiquetas = array_column($tarjeta->presentacion['renglones'], 'valor', 'etiqueta');

        $this->assertArrayHasKey('Links (uno por artículo)', $etiquetas);
        $this->assertSame('va el link de la tienda: https://tienda-zze.test', $etiquetas['Por plantilla']);

        $confirmada = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $respuesta['tarjeta_id']);

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));

        $enviadas = $this->promociones_enviadas()->keyBy(function ($mensaje) {
            return strpos($mensaje->body, 'Ana Gomez') !== false ? 'ana' : 'bruno';
        });

        $this->assertCount(2, $enviadas);
        $this->assertStringContainsString('ZZE Taladro 750W: ' . $this->link('zze-taladro-750w'), $enviadas['ana']->body);
        $this->assertStringContainsString('ZZE Amoladora 115: ' . $this->link('zze-amoladora-115'), $enviadas['ana']->body);
        $this->assertSame('cc_cli_promocion', $enviadas['bruno']->template_meta_name);
        $this->assertStringContainsString('Miralo en https://tienda-zze.test', $enviadas['bruno']->body);
        $this->assertStringNotContainsString('/articulos/', $enviadas['bruno']->body);

        Http::assertNothingSent();
    }

    /**
     * Uno despublicado corta la promo nombrándolo, y seis artículos es demasiado. Nada de eso deja tarjeta.
     *
     * @test
     */
    public function uno_despublicado_corta_y_mas_de_cinco_es_demasiado()
    {
        $this->cliente('Ana Gomez', '5493444101010');

        $taladro = $this->articulo_de_la_tienda('ZZE Taladro 750W', 'zze-taladro-750w');
        $oculta = $this->articulo_de_la_tienda('ZZE Amoladora oculta', 'zze-amoladora-oculta', 0);

        list($conversation, $assistant) = $this->vuelta('mandales una promo');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto'       => 'Taladro y amoladora con 20% off',
            'article_ids' => [$taladro->id, $oculta->id],
        ]);

        $this->assertTrue(empty($respuesta['ok']));
        $this->assertStringContainsString('El artículo "ZZE Amoladora oculta" no está publicado en la tienda', $respuesta['error']);

        $ids = [];

        for ($i = 1; $i <= PropuestaPromocionWhatsappIaHelper::MAXIMO_DE_ARTICULOS + 1; $i++) {
            $ids[] = $this->articulo_de_la_tienda('ZZE Articulo ' . $i, 'zze-articulo-' . $i)->id;
        }

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto'       => 'Todo con 20% off',
            'article_ids' => $ids,
        ]);

        $this->assertTrue(empty($respuesta['ok']));
        $this->assertStringContainsString('hasta 5 artículos', $respuesta['error']);

        // Un artículo de OTRO negocio (la ferretería sembrada en la base de testing) no existe para este dueño.
        $ajeno = Article::where('user_id', '!=', $this->dueno->id)->value('id');

        if (is_null($ajeno)) {
            $ajeno = (int) Article::max('id') + 1000;
        }

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto'       => 'Taladro y algo más',
            'article_ids' => [$taladro->id, $ajeno],
        ]);

        $this->assertTrue(empty($respuesta['ok']));
        $this->assertStringContainsString('No encontré el artículo ' . $ajeno, $respuesta['error']);

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * 🔴 Lo que sale va a donde se mostró, en CADA link: si cambió el slug de uno de los artículos entre
     * la tarjeta y el sí, no se manda nada.
     *
     * @test
     */
    public function al_confirmar_se_revalida_cada_link()
    {
        $taladro = $this->articulo_de_la_tienda('ZZE Taladro 750W', 'zze-taladro-750w');
        $amoladora = $this->articulo_de_la_tienda('ZZE Amoladora 115', 'zze-amoladora-115');

        $this->cliente('Ana Gomez', '5493444101010');

        list($conversation, $assistant) = $this->vuelta('mandales una promo');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto'       => 'Taladro y amoladora con 20% off',
            'article_ids' => [$taladro->id, $amoladora->id],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        Article::where('id', $amoladora->id)->update(['slug' => 'zze-amoladora-115-nueva']);

        $confirmada = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $respuesta['tarjeta_id']);

        $this->assertTrue(empty($confirmada['ok']), json_encode($confirmada));
        $this->assertStringContainsString('cambió el link de alguno de los artículos', $confirmada['error']);
        $this->assertCount(0, $this->promociones_enviadas());

        // Y uno que se despublica entre la tarjeta y el sí corta igual, nombrándolo.
        list($otra_conversacion, $otra_vuelta) = $this->vuelta('mandales una promo');

        $otra = $this->herramienta($otra_conversacion, $otra_vuelta, 'proponer_promocion_por_whatsapp', [
            'texto'       => 'Taladro y amoladora con 20% off, ahora sí',
            'article_ids' => [$taladro->id, $amoladora->id],
        ]);

        $this->assertTrue(!empty($otra['ok']), json_encode($otra));

        Article::where('id', $taladro->id)->update(['online' => 0]);

        $confirmada = $this->confirmar_en_la_vuelta_siguiente($otra_conversacion, $otra_vuelta, $otra['tarjeta_id']);

        $this->assertTrue(empty($confirmada['ok']), json_encode($confirmada));
        $this->assertStringContainsString('El artículo "ZZE Taladro 750W" no está publicado en la tienda', $confirmada['error']);
        $this->assertStringContainsString('No mandé nada', $confirmada['error']);
        $this->assertCount(0, $this->promociones_enviadas());
    }

    /**
     * Segunda revisión del 19/10/2026: un mail argentino ("…@ferreteria.com.ar") no es un link y pasa; los
     * acortadores y los links de perfiles ("bit.ly/…", "t.me/…", "linktr.ee/…", "goo.gl/…") y cualquier
     * dominio con ruta frenan. El texto normal sigue pasando.
     *
     * @test
     */
    public function los_mails_pasan_y_los_acortadores_frenan()
    {
        $taladro = $this->articulo_de_la_tienda('ZZE Taladro 750W', 'zze-taladro-750w');
        $this->cliente('Ana Gomez', '5493444101010');

        list($conversation, $assistant) = $this->vuelta('mandales una promo del taladro');

        $frenan = [
            'Escribinos por bit.ly/promo-ferre',
            'Unite en t.me/ferreteria',
            'Todo en linktr.ee/ferreteria',
            'El catálogo en goo.gl/abc123',
            'La promo en ofertas.xyz/semana',
        ];

        foreach ($frenan as $texto) {

            $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => $texto, 'article_ids' => [$taladro->id]]);

            $this->assertTrue(empty($respuesta['ok']), 'Tenía que frenar: ' . $texto);
            $this->assertSame(PropuestaPromocionWhatsappIaHelper::MENSAJE_TEXTO_CON_LINK, $respuesta['error'], $texto);
        }

        $pasan = [
            'Consultas a ventas@ferreteria.com.ar',
            'Escribí a info@hipermax.net.ar por el taladro',
            'Llevá 2 x 1.5 L a $ 3.000',
            'Taladro 750W a $ 22.000 hasta el 20.10',
            'Precio final $ 1.234,56 (IVA incl.), 3/6 cuotas',
        ];

        foreach ($pasan as $texto) {

            $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => $texto, 'article_ids' => [$taladro->id]]);

            $this->assertTrue(!empty($respuesta['ok']), 'No tenía que frenar: ' . $texto . ' → ' . json_encode($respuesta));
        }
    }

    /**
     * Revisión del 19/10/2026: el renglón que sale es "<nombre>: <link>", así que un artículo renombrado
     * entre la tarjeta y el sí tampoco sale (mismo link, otro renglón).
     *
     * @test
     */
    public function al_confirmar_se_revalida_tambien_el_nombre_de_cada_articulo()
    {
        $taladro = $this->articulo_de_la_tienda('ZZE Taladro 750W', 'zze-taladro-750w');
        $amoladora = $this->articulo_de_la_tienda('ZZE Amoladora 115', 'zze-amoladora-115');

        $this->cliente('Ana Gomez', '5493444101010');

        list($conversation, $assistant) = $this->vuelta('mandales una promo');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto'       => 'Taladro y amoladora con 20% off',
            'article_ids' => [$taladro->id, $amoladora->id],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        Article::where('id', $amoladora->id)->update(['name' => 'ZZE Amoladora 115 PRO']);

        $confirmada = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $respuesta['tarjeta_id']);

        $this->assertTrue(empty($confirmada['ok']), json_encode($confirmada));
        $this->assertStringContainsString('cambió el nombre de alguno de los artículos', $confirmada['error']);
        $this->assertCount(0, $this->promociones_enviadas());
    }

    /**
     * 🔴 El texto lo redacta el modelo: sin texto es un ERROR para él (no un `faltan` que termine en
     * "dictame la promo"), y un texto con un link escrito también (los pone el sistema).
     *
     * @test
     */
    public function sin_texto_o_con_un_link_en_el_texto_es_un_error_para_el_modelo()
    {
        $taladro = $this->articulo_de_la_tienda('ZZE Taladro 750W', 'zze-taladro-750w');
        $this->cliente('Ana Gomez', '5493444101010');

        list($conversation, $assistant) = $this->vuelta('mandales una promo del taladro');

        $sin_texto = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['article_ids' => [$taladro->id]]);

        $this->assertTrue(empty($sin_texto['ok']));
        $this->assertSame([], $sin_texto['faltan'], 'No es un "faltan": el modelo no le tiene que pedir el texto al dueño.');
        $this->assertSame(PropuestaPromocionWhatsappIaHelper::MENSAJE_SIN_TEXTO, $sin_texto['error']);
        $this->assertStringContainsString('Redactalo vos', $sin_texto['error']);
        $this->assertStringContainsString('No le pidas que te lo dicte', $sin_texto['error']);

        foreach (['Taladro a $ 89.990 en https://tienda-zze.test/articulos/zze-taladro-750w/1', 'Taladro en tienda-zze.test/articulos/zze-taladro', 'Mirá HTTP://algo'] as $texto) {

            $con_link = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => $texto, 'article_ids' => [$taladro->id]]);

            $this->assertTrue(empty($con_link['ok']), $texto);
            $this->assertSame(PropuestaPromocionWhatsappIaHelper::MENSAJE_TEXTO_CON_LINK, $con_link['error'], $texto);
        }

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        // Un solo artículo por article_ids es como article_id: el link de siempre.
        $uno = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => 'Taladro a $ 89.990', 'article_ids' => [$taladro->id]]);

        $this->assertTrue(!empty($uno['ok']), json_encode($uno));
        $this->assertSame($this->link('zze-taladro-750w'), AiMessageAction::find($uno['tarjeta_id'])->datos['link']);
        $this->assertSame([], AiMessageAction::find($uno['tarjeta_id'])->datos['articulos']);
    }

    /**
     * 🔴 Revisión del 19/10/2026: los links inventados que pasaban sin "http" (www., wa.me/, un dominio
     * suelto) también son un error. Y el texto normal de una promo (litros, precios, fechas, un mail) no
     * se frena.
     *
     * @test
     */
    public function un_link_sin_http_tambien_es_un_error_y_el_texto_normal_pasa()
    {
        $taladro = $this->articulo_de_la_tienda('ZZE Taladro 750W', 'zze-taladro-750w');
        $this->cliente('Ana Gomez', '5493444101010');

        list($conversation, $assistant) = $this->vuelta('mandales una promo del taladro');

        $inventados = [
            'Visitá www.ferreteria-falsa.com.ar y llevalo',
            'Entrá a mitienda.com/ofertas',
            'Escribinos a wa.me/5493444',
            'Mirá todo en ferreteria.com.ar',
            'Todo en tienda.online',
            'El catálogo en ferretería.shop/promo',
            'Más en MITIENDA.COM',
        ];

        foreach ($inventados as $texto) {

            $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => $texto, 'article_ids' => [$taladro->id]]);

            $this->assertTrue(empty($respuesta['ok']), 'Tenía que frenar: ' . $texto);
            $this->assertSame(PropuestaPromocionWhatsappIaHelper::MENSAJE_TEXTO_CON_LINK, $respuesta['error'], $texto);
        }

        $normales = [
            'Llevá 2 x 1.5 L a $ 3.000',
            'Taladro 750W a $ 22.000 hasta el 20.10',
            'Pintura Alba 20 L. Oferta imperdible',
            'Precio final $ 1.234,56 (IVA incl.), solo hoy',
            'Consultas a ventas@ferreteria.com',
        ];

        foreach ($normales as $texto) {

            $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => $texto, 'article_ids' => [$taladro->id]]);

            $this->assertTrue(!empty($respuesta['ok']), 'No tenía que frenar: ' . $texto . ' → ' . json_encode($respuesta));
        }
    }

    /**
     * La herramienta: article_ids va al final de las propiedades (prefijo del caché), es opcional, y la
     * descripción y la del texto dicen que el texto lo redacta el modelo, sin links.
     *
     * @test
     */
    public function la_herramienta_lleva_article_ids_al_final_y_el_texto_lo_redacta_el_modelo()
    {
        $por_nombre = [];

        foreach (HerramientasDeCarga::definiciones() as $definicion) {
            $por_nombre[$definicion['name']] = $definicion;
        }

        $promo = $por_nombre['proponer_promocion_por_whatsapp'];

        $propiedades = array_keys($promo['input_schema']['properties']);

        $this->assertSame('article_ids', end($propiedades), 'Lo nuevo va al final (prefijo del caché).');
        $this->assertSame(['texto'], $promo['input_schema']['required']);
        $this->assertSame('integer', $promo['input_schema']['properties']['article_ids']['items']['type']);
        $this->assertStringContainsString('lo redactás VOS', $promo['description']);
        $this->assertStringContainsString('hasta 5 en article_ids', $promo['description']);
        $this->assertStringContainsString('REDACTADO POR VOS', $promo['input_schema']['properties']['texto']['description']);
        $this->assertStringContainsString('sin links', $promo['input_schema']['properties']['texto']['description']);
    }

    /**
     * 🔴 Un job de promo encolado antes de este cambio se deserializa sin `links`: el default de la
     * declaración lo deja como antes y sale con el link de siempre.
     *
     * @test
     */
    public function un_job_encolado_sin_links_sale_como_siempre()
    {
        $ana = $this->cliente('Ana Gomez', '5493444101010');
        $this->chat_abierto($ana);

        // Como lo despachaba la versión anterior: siete argumentos, sin links.
        $job = new SendPromocionWhatsappJob($this->dueno->id, $ana->id, '20% en todo', 'https://tienda-zze.test', '', $this->dueno->id, null);

        $serializado = serialize($job);

        // Y su payload en la cola, sin la propiedad que esta versión agregó.
        $clave = "\0*\0links";

        $sin = preg_replace('/s:' . strlen($clave) . ':"' . preg_quote($clave, '/') . '";a:0:\{\}/', '', $serializado, 1, $cuantas);

        $this->assertSame(1, $cuantas, 'El payload tenía la propiedad.');

        $sin = preg_replace_callback('/^O:(\d+):"([^"]+)":(\d+):/', function ($m) {
            return 'O:' . $m[1] . ':"' . $m[2] . '":' . ((int) $m[3] - 1) . ':';
        }, $sin, 1);

        $viejo = unserialize($sin);

        $propiedad = new \ReflectionProperty(SendPromocionWhatsappJob::class, 'links');
        $propiedad->setAccessible(true);

        $this->assertSame([], $propiedad->getValue($viejo));

        $viejo->handle();

        $enviadas = $this->promociones_enviadas();

        $this->assertCount(1, $enviadas);
        $this->assertStringContainsString("20% en todo\nhttps://tienda-zze.test", $enviadas->first()->body);
        $this->assertStringNotContainsString('no-tiene-que-salir', $enviadas->first()->body);
    }
}
