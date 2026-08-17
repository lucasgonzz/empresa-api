<?php

namespace Tests\Feature\MotorDeOfertas;

use App\Http\Controllers\Helpers\OfertaComunicacionHelper;
use App\Models\Article;
use App\Models\Client;
use App\Models\ClientOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Misión actividad-de-clientes-y-oferta-por-whatsapp — archivo 11: EL CONTRATO DE
 * FORMATO DEL LINK DE WHATSAPP DE LA OFERTA, ahora que lo lee la SPA.
 *
 * 🔴 POR QUÉ EXISTE ESTE ARCHIVO. Desde esta misión, el botón "Avisarle por WhatsApp"
 * ya no manda al operador a una pestaña de api.whatsapp.com: abre el sidebar del agente
 * con el mensaje precargado en el composer. Y ese mensaje NO viaja por una columna
 * propia ni por un endpoint nuevo — se saca del `whatsapp_url` que ya está escrito en
 * TODAS las filas de client_offers, cortando por `&text=` y decodificando
 * (`empresa-spa/src/utils/whatsapp_phone.js`, función `mensaje_de_oferta()`).
 *
 * O sea que el formato que arma `link_de_whatsapp()` pasó a ser un CONTRATO ENTRE LOS
 * DOS REPOS, y el que consume un contrato es el que lo fija con un test. Sin esto,
 * cambiar `&text=` por otra cosa acá dejaría al front devolviendo cadena vacía EN
 * SILENCIO: el operador apretaría el botón, se abriría la conversación correcta y el
 * composer estaría vacío, sin un solo error en ningún lado.
 *
 * Lo que se fija, y nada más que esto:
 *   1. el link arranca con `https://api.whatsapp.com/send?phone=`,
 *   2. `&text=` aparece UNA SOLA VEZ y va último,
 *   3. lo que sigue es el texto entero, rawurlencodeado, y vuelve byte a byte.
 *
 * 🔴 No se tocó nada de `OfertaComunicacionHelper`: este archivo sólo LO MIDE.
 *
 * PHP 7.4: sin match, ?->, str_contains ni #[...].
 */
class Contrato_del_link_de_whatsapp_Test extends TestCase
{
    use DatabaseTransactions;

    /** ivas.id de la alícuota del 21%. */
    const IVA_21 = 2;

    /** El prefijo del link, palabra por palabra. Del otro lado, `mensaje_de_oferta()` corta por '&text='. */
    const PREFIJO = 'https://api.whatsapp.com/send?phone=';

    /** @var User */
    protected $user;
    /** @var string|null users.online del comercio antes de que la suite lo toque */
    protected $online_original;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();
        // Sin esto los tests salen a la API real de Anthropic (hay una clave REAL en .env.testing
        // y cada llamada se paga). Es una clase de error ya cometida en este repo.
        config(['services.anthropic.api_key' => null]);
        $this->user = User::find(500);
        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->online_original = $this->user->online;
    }

    protected function tearDown(): void
    {
        // DatabaseTransactions revierte las filas, pero users.online se restaura a mano
        // igual: el objeto en memoria no participa de la transacción.
        if (!is_null($this->user)) {
            $this->user->online = $this->online_original;
            $this->user->save();
        }
        parent::tearDown();
    }

    /**
     * El formato base, sin base de datos de por medio: prefijo, un solo `&text=`, y el
     * texto que vuelve IDÉNTICO al que entró.
     *
     * El texto de prueba lleva a propósito lo que el mensaje real trae y suele romper un
     * encodeo a medias: acentos y ñ (multibyte), dos puntos, punto y coma, un `%`, un
     * `+`, un `#` y una URL con `//` y `:` adentro.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_link_termina_en_text_con_el_mensaje_encodeado()
    {
        $texto = 'Hola Ferretería Ñandú! Te preparamos una oferta en Caño de 1/2": '
            . 'descuento por cantidad: llevando de 1 a 5, 10%; llevando 6 o más, 15%. '
            . 'Válida hasta el 27/08/2026. Entrá a la tienda: https://tienda.local:8001/#/ofertas?x=1+2';
        $url = OfertaComunicacionHelper::link_de_whatsapp('5491126322965', $texto);

        $this->afirmar_el_formato($url, $texto);
        // El teléfono queda ANTES del &text=, que es lo que hace que cortar por ahí sea seguro.
        $this->assertSame(self::PREFIJO . '5491126322965', substr($url, 0, strpos($url, '&text=')),
            'entre el prefijo y el &text= no puede haber nada más que el teléfono');
        // Los espacios van como %20 y NUNCA como '+': con urlencode() a secas el front tendría
        // que además reemplazar los '+', y un '+' real del texto (que viaja como %2B) se
        // volvería un espacio.
        $this->assertFalse(strpos($url, '+'), 'rawurlencode manda %20, no + (un + real va como %2B)');
    }

    /**
     * 🔴 EL CASO QUE HACE QUE EL FRONT PUEDA CORTAR POR `indexOf('&text=')` SIN
     * EQUIVOCARSE: un `&` adentro del mensaje viaja como %26 y no abre un segundo
     * parámetro. Es el caso real que cuenta el comentario de WhatsappBtn.vue:194-202 y
     * el que ya mide `7_Whatsapp_y_mail_Test` del lado del nombre del artículo; acá se
     * mira desde el contrato con la SPA.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function un_ampersand_en_el_mensaje_no_parte_el_link()
    {
        $texto = 'Tuerca & Bulón 50% al 3x1';
        $url = OfertaComunicacionHelper::link_de_whatsapp('5491126322965', $texto);

        $this->afirmar_el_formato($url, $texto);
        // El ÚNICO & de toda la URL es el del separador: si el del texto viajara crudo,
        // el front cortaría bien igual pero WhatsApp mostraría el mensaje cortado.
        $this->assertSame(1, substr_count($url, '&'), 'el único & tiene que ser el de &text=');
        $this->assertNotFalse(strpos($url, '%26'), 'el & del mensaje tiene que ir como %26');
        $this->assertNotFalse(strpos($url, '%25'), 'el % del mensaje tiene que ir como %25');
    }

    /**
     * Lo mismo, pero sobre el `whatsapp_url` de una oferta REAL, armado por el camino de
     * producción (`OfertaComunicacionHelper::whatsapp()`), que es exactamente la cadena
     * que el endpoint le manda a la SPA y de la que sale el borrador del composer.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_whatsapp_url_de_una_oferta_activada_respeta_el_mismo_formato()
    {
        $this->user->online = 'tienda.local:8001';
        $this->user->save();
        // El & y el % en el nombre del artículo no son adorno: son lo que hace que este
        // test mida algo. El teléfono es uno de los reales de `prueba.clients.phone`.
        $offer = $this->oferta(
            ['phone' => '1126322965', 'name' => 'zz-Ferretería Ñandú ' . uniqid()],
            ['name' => 'zz-Tuerca & Bulón 50% ' . uniqid()]
        );
        $datos = OfertaComunicacionHelper::whatsapp($offer, $this->user);

        $this->assertNotNull($datos['url'], 'con teléfono usable tiene que haber link');
        // El texto esperado sale del mismo lugar del que lo saca el helper: si mañana cambia
        // la redacción, este test no se pone rojo por eso — se pone rojo si cambia el FORMATO,
        // que es lo único que la SPA da por cierto.
        $this->afirmar_el_formato($datos['url'], OfertaComunicacionHelper::texto_del_mensaje($offer, $this->user));
        $this->assertSame(1, substr_count($datos['url'], '&'),
            'el & del nombre del artículo no puede abrir un segundo parámetro');
        // Y el mensaje decodificado trae el nombre entero y el link de la tienda: es lo que
        // el operador va a ver escrito en el composer.
        $texto = rawurldecode(substr($datos['url'], strpos($datos['url'], '&text=') + 6));
        $this->assertNotFalse(strpos($texto, 'Tuerca & Bulón 50%'));
        $this->assertNotFalse(strpos($texto, 'https://tienda.local:8001'));
    }

    /**
     * Las tres aserciones del contrato, juntas: es lo que la SPA da por cierto en
     * `mensaje_de_oferta()`.
     *
     * @param string $url
     * @param string $texto_esperado
     * @return void
     */
    protected function afirmar_el_formato($url, $texto_esperado)
    {
        // 1. Arranca con el prefijo. strpos(...) === 0 y no str_starts_with(): PHP 7.4.
        $this->assertSame(0, strpos($url, self::PREFIJO),
            'el link tiene que arrancar con ' . self::PREFIJO);
        // 2. `&text=` una sola vez: con dos, el indexOf() del front cortaría en el primero y
        // el mensaje llegaría partido.
        $this->assertSame(1, substr_count($url, '&text='),
            '&text= tiene que aparecer una sola vez y ser el último parámetro');
        // 3. Lo que sigue vuelve BYTE A BYTE. assertSame y no assertEquals: acá la diferencia
        // de un solo carácter es el bug.
        $corte = strpos($url, '&text=') + 6;
        $this->assertSame($texto_esperado, rawurldecode(substr($url, $corte)),
            'el texto decodificado tiene que ser idéntico al original');
    }

    /**
     * Una ClientOffer activa, escrita directo (no por el endpoint): a este archivo le
     * importa el formato del link, no el camino de activación, que ya lo miden los
     * archivos 6 y 7.
     *
     * @param array $datos_cliente
     * @param array $datos_articulo
     * @return ClientOffer
     */
    protected function oferta(array $datos_cliente, array $datos_articulo = [])
    {
        $client = Client::create(array_merge(
            ['name' => 'zz-cli-link-wa-' . uniqid(), 'user_id' => 500],
            $datos_cliente
        ));
        $article = Article::create(array_merge([
            'name' => 'zz-art-link-wa-' . uniqid(), 'user_id' => 500, 'cost' => 1000,
            'percentage_gain' => 100, 'aplicar_iva' => 1, 'iva_id' => self::IVA_21,
        ], $datos_articulo));

        return ClientOffer::create([
            'user_id' => 500,
            'client_id' => $client->id,
            'article_id' => $article->id,
            'tipo_descuento' => 'unidad',
            'porcentaje' => 15,
            'desde' => Carbon::today()->toDateString(),
            'hasta' => Carbon::today()->addDays(10)->toDateString(),
            'estado' => 'activa',
        ]);
    }
}
