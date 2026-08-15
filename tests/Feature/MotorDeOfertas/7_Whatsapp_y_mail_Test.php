<?php

namespace Tests\Feature\MotorDeOfertas;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\OfertaComunicacionHelper;
use App\Http\Controllers\Helpers\TelefonoArgentinoHelper;
use App\Http\Controllers\Helpers\WhatsappPhoneHelper;
use App\Mail\ComercioCityMail;
use App\Models\Article;
use App\Models\Buyer;
use App\Models\Client;
use App\Models\ClientOffer;
use App\Models\ExtencionEmpresa;
use App\Models\OfferSuggestion;
use App\Models\OfferSuggestionLine;
use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Misión motor-de-ofertas-por-cliente — archivo 7: el aviso al cliente.
 *
 * 🔴 LA TESIS: avisar es un extra, nunca una precondición. Medido el 15/8/2026
 * sobre `prueba`, 417 de 496 clientes (84%) no tienen ni mail ni teléfono: todos
 * los caminos degradados de acá terminan igual —con la oferta ACTIVA— y ninguno
 * lanza. Los casos del normalizador NO son inventados: son los teléfonos que
 * están hoy en `prueba.clients.phone`.
 */
class Whatsapp_y_mail_Test extends TestCase
{
    use DatabaseTransactions;

    /** ivas.id de la alícuota del 21%. */
    const IVA_21 = 2;

    /** @var User */
    protected $user;
    /** @var string|null users.online del comercio antes de que la suite lo toque */
    protected $online_original;
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();
        // Sin esto los tests salen a la API real de Anthropic (clave en .env.testing).
        config(['services.anthropic.api_key' => null]);
        $this->user = User::find(500);
        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->online_original = $this->user->online;
        // forceCreate y no firstOrCreate: ExtencionEmpresa no declara $fillable
        // y fuera de Model::unguarded() (que solo aplica db:seed) el create falla.
        $extencion = ExtencionEmpresa::where('slug', 'motor_de_ofertas')->first();
        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'motor_de_ofertas', 'name' => 'Motor de ofertas']);
        }
        $this->user->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->user->load('extencions');
        $this->actingAs($this->user, 'web');
    }

    protected function tearDown(): void
    {
        // DatabaseTransactions revierte las filas, pero users.online se restaura
        // a mano igual: el objeto en memoria no participa de la transacción.
        if (!is_null($this->user)) {
            $this->user->online = $this->online_original;
            $this->user->save();
        }
        parent::tearDown();
    }

    /**
     * 🔴 LA TABLA DE CONVERSIÓN, con los teléfonos REALES de `prueba.clients.phone`
     * medidos el 15/8/2026. Si alguien "simplifica" el helper a un str_replace
     * del 15, los dos números de 10 dígitos que TIENEN un 15 adentro
     * (1126322965 y 3415634190) se rompen.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_normalizador_convierte_los_telefonos_reales_a_e164()
    {
        // Los cuatro primeros salen de la base real; los otros son variantes que
        // hoy generan un link que no abre ningún chat.
        $casos = [
            '1126322965'          => '5491126322965', // área 11 (CABA) + 8 dígitos
            '2355-451028'         => '5492355451028', // área 2355 + 6 dígitos
            '341563-4190'         => '5493415634190', // área 341 (Rosario) + 7 dígitos
            '92966497980'         => '5492966497980', // ya trae el 9 de celular
            '03444-15-622139'     => '5493444622139', // 0 de larga distancia + 15
            '+54 9 3444 62-2139'  => '5493444622139', // ya viene en E.164
            '3444622139'          => '5493444622139', // pelado
            '011 4321-1234'       => '5491143211234', // CABA con 0 adelante
        ];
        foreach ($casos as $crudo => $esperado) {
            $this->assertSame($esperado, TelefonoArgentinoHelper::a_e164($crudo), 'crudo: ' . $crudo);
        }
    }

    /**
     * 🔴 Un largo que no da devuelve null y el que llama NO muestra el botón: si
     * se adivinara el código de área, el mensaje iría a un desconocido.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function un_telefono_que_no_alcanza_devuelve_null_y_no_se_inventa_el_area()
    {
        foreach (['15 622139', '622139', '', null, '  ', 'sin telefono', '12345678901234'] as $crudo) {
            $this->assertNull(TelefonoArgentinoHelper::a_e164($crudo),
                'crudo: ' . var_export($crudo, true) . ' — no se puede armar un link confiable');
        }
    }

    /**
     * 🔴 WhatsappPhoneHelper::normalize() NO SE TOCÓ: su contrato sigue siendo
     * "solo los dígitos" y tiene otros consumidores (grupo 137).
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_helper_viejo_de_whatsapp_sigue_devolviendo_solo_digitos()
    {
        $this->assertSame('03444156221390', WhatsappPhoneHelper::normalize('0344-4156-2213 90'));
        $this->assertSame('1126322965', WhatsappPhoneHelper::normalize('11 2632-2965'));
        $this->assertSame('', WhatsappPhoneHelper::normalize(null));
    }

    /**
     * 🔴 rawurlencode NO ES DEFENSIVO: sin él, el `&` de "Tuerca & Bulón" cierra
     * el parámetro `text` y el mensaje llega cortado a la mitad.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_ampersand_del_nombre_del_articulo_no_corta_el_link()
    {
        $this->user->online = 'tienda.local:8001';
        $this->user->save();
        $offer = $this->activar_oferta(
            ['phone' => '1126322965'],
            ['name' => 'zz-Tuerca & Bulón ' . uniqid()]
        );

        // El & del nombre viaja escapado: crudo habría DOS '&' en la URL y todo
        // lo que sigue al segundo sería otro parámetro.
        $this->assertSame(1, substr_count($offer->whatsapp_url, '&'), 'el único & tiene que ser el de &text=');
        $this->assertNotFalse(strpos($offer->whatsapp_url, '%26'), 'el & del nombre tiene que ir como %26');
        // Y el texto plano, decodificado, sí trae el nombre entero.
        $texto = rawurldecode(substr($offer->whatsapp_url, strpos($offer->whatsapp_url, '&text=') + 6));
        $this->assertNotFalse(strpos($texto, 'Tuerca & Bulón'));
    }

    /**
     * 🔴 `users.online` no tiene formato uniforme: medido, `empresa` la tiene sin
     * esquema y `leudinox` con esquema. Sin normalizar, el link sin esquema se
     * abre como ruta relativa y no lleva a ninguna parte.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function la_url_de_la_tienda_sin_esquema_sale_con_https()
    {
        $this->user->online = 'tienda.local:8001';
        $this->user->save();
        $this->assertSame('https://tienda.local:8001', OfertaComunicacionHelper::url_de_la_tienda($this->user));
        // La que ya trae esquema no se toca: anteponerle https la rompería.
        $this->user->online = 'http://tienda.local:8081';
        $this->user->save();
        $this->assertSame('http://tienda.local:8081', OfertaComunicacionHelper::url_de_la_tienda($this->user));
        $offer = $this->activar_oferta(['phone' => '2355-451028']);
        $this->assertNotFalse(strpos(rawurldecode($offer->whatsapp_url), 'http://tienda.local:8081'));
    }

    /**
     * `users.online` vacía (medido: `prueba`, `ferretotal`, `golonorte_bien`): el
     * mensaje sale SIN link y la oferta se activa igual. Degrada, no rompe.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function sin_url_de_tienda_el_mensaje_sale_sin_link_y_la_oferta_igual()
    {
        $this->user->online = '';
        $this->user->save();
        $offer = $this->activar_oferta(['phone' => '1126322965']);

        $this->assertSame('activa', $offer->estado);
        $this->assertNotNull($offer->whatsapp_url);
        $this->assertFalse(strpos(rawurldecode($offer->whatsapp_url), 'Entrá a la tienda'),
            'sin users.online el mensaje no puede prometer un link que no existe');
    }

    /**
     * 🔴 EL CASO DEL 84%: sin mail y sin teléfono la oferta se activa IGUAL, sin
     * notificar y sin lanzar. Si se rompe, el motor no sirve para 8 de cada 10.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function sin_mail_ni_telefono_la_oferta_se_activa_igual_y_queda_sin_notificar()
    {
        $offer = $this->activar_oferta([]);
        $this->assertSame('activa', $offer->estado);
        $this->assertNull($offer->notificada_email_at);
        $this->assertNull($offer->email_destino);
        $this->assertNull($offer->whatsapp_telefono);
        $this->assertNull($offer->whatsapp_url, 'sin teléfono no se muestra el botón');
        Mail::assertNothingQueued();
    }

    /**
     * 🔴 El orden buyer → cliente: el Buyer del ecommerce SIEMPRE tiene mail
     * (es como se registra), `clients.email` casi nunca (26 de 541 en `prueba`).
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_mail_del_buyer_le_gana_al_del_cliente()
    {
        $client = $this->cliente(['email' => 'zz-cliente@test.local']);
        Buyer::create([
            'name' => 'zz-buyer-oferta', 'email' => 'zz-buyer@test.local',
            'user_id' => 500, 'comercio_city_client_id' => $client->id,
        ]);
        $this->assertSame('zz-buyer@test.local',
            OfertaComunicacionHelper::email_del_cliente($client->id, 500, $client));
        // Sin buyer vinculado cae al del cliente, que es el fallback real.
        $otro = $this->cliente(['email' => 'zz-solo-cliente@test.local']);
        $this->assertSame('zz-solo-cliente@test.local',
            OfertaComunicacionHelper::email_del_cliente($otro->id, 500, $otro));
    }

    /**
     * 🔴 A diferencia de new_sale(), la oferta sale del remitente DEL COMERCIO:
     * un mail de "ComercioCity" con el descuento de una ferretería parece spam.
     * Se verifica por el efecto observable de ClientMailConfigHelper::apply().
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_mail_sale_con_el_remitente_del_comercio()
    {
        OnlineConfiguration::where('user_id', 500)->delete();
        OnlineConfiguration::create([
            'user_id' => 500, 'mail_enabled' => true, 'mail_host' => 'smtp.zz-comercio.test',
            'mail_port' => 587, 'mail_username' => 'ventas@zz-comercio.test', 'mail_password' => 'zz-secreto',
            'mail_from_address' => 'ofertas@zz-comercio.test', 'mail_from_name' => 'Comercio de prueba',
        ]);
        config(['mail.from.address' => 'contacto@comerciocity.com']);
        $offer = $this->activar_oferta(['email' => 'zz-oferta-remitente@test.local']);

        Mail::assertQueued(ComercioCityMail::class);
        $this->assertSame('ofertas@zz-comercio.test', config('mail.from.address'),
            'apply() tiene que haber pisado el remitente del .env con el del comercio');
        $this->assertSame('smtp.zz-comercio.test', config('mail.mailers.smtp.host'));
        $this->assertSame('zz-oferta-remitente@test.local', $offer->email_destino);
    }

    /**
     * Activa una oferta sobre un cliente y un artículo nuevos, y devuelve el
     * ClientOffer ya escrito. @param array $datos_cliente @param array $datos_articulo
     * @return ClientOffer
     */
    protected function activar_oferta(array $datos_cliente, array $datos_articulo = [])
    {
        $client = $this->cliente($datos_cliente);
        $article = Article::create(array_merge([
            'name' => 'zz-oferta-wa-' . uniqid(), 'user_id' => 500, 'cost' => 1000,
            'percentage_gain' => 100, 'aplicar_iva' => 1, 'iva_id' => self::IVA_21,
        ], $datos_articulo));
        ArticleHelper::setFinalPrice($article, null, $this->user, null, true);
        $suggestion = OfferSuggestion::create(['user_id' => 500, 'status' => 'terminado', 'origen_generacion' => 'manual']);
        $linea = OfferSuggestionLine::create([
            'offer_suggestion_id' => $suggestion->id, 'client_id' => $client->id,
            'article_id' => $article->id, 'tipo_descuento' => 'unidad', 'porcentaje_sugerido' => 10,
            'porcentaje_techo' => 40, 'porcentaje_piso' => 5, 'margen_base' => 100, 'criterio' => 'afinidad',
        ]);
        $this->postJson('api/client-offer', [
            'offer_suggestion_line_id' => $linea->id, 'porcentaje' => 10, 'tipo_descuento' => 'unidad',
            'hasta' => Carbon::today()->addDays(10)->toDateString(),
        ])->assertStatus(201);

        return ClientOffer::where('offer_suggestion_line_id', $linea->id)->first();
    }

    /** @param array $atributos @return Client */
    protected function cliente(array $atributos = [])
    {
        return Client::create(array_merge(['name' => 'zz-cli-wa-' . uniqid(), 'user_id' => 500], $atributos));
    }
}
