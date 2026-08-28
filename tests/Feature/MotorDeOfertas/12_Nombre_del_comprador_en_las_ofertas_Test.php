<?php

namespace Tests\Feature\MotorDeOfertas;

use App\Http\Controllers\Helpers\ComercioCityMailHelper;
use App\Http\Controllers\Helpers\OfertaComunicacionHelper;
use App\Mail\ComercioCityMail;
use App\Models\Article;
use App\Models\Buyer;
use App\Models\Client;
use App\Models\ClientOffer;
use App\Models\ExtencionEmpresa;
use App\Models\OfferSuggestion;
use App\Models\OfferSuggestionLine;
use App\Models\User;
use App\Services\OfertasClientes\ResumenIaOfertasService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Misión ofertas-buyer-en-vez-de-cliente-erp — archivo 12: el punto B del plan, "qué nombre se
 * muestra". Todo el módulo mostraba `clients.name` (el nombre del ERP) donde tendría que mostrar
 * el nombre del comprador de la tienda (`buyers.name` + `surname`). Este archivo prueba los seis
 * puntos de consumo de OfertaComunicacionHelper::nombre_para_mostrar() (§3.3 del plan) y el guard
 * de N+1 sobre el eager-load de `client.buyer`.
 *
 * Molde de 6_Activacion_Test.php: pega contra los endpoints reales con el usuario 500.
 */
class Nombre_del_comprador_en_las_ofertas_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();
        // Sin esto el pipeline sale a la API real de Anthropic: la clave vive en el .env.testing.
        config(['services.anthropic.api_key' => null]);
        $this->user = User::find(500);
        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        // forceCreate y no firstOrCreate: ExtencionEmpresa no declara $fillable y fuera de
        // Model::unguarded() (que solo aplica db:seed) el create falla.
        $extencion = ExtencionEmpresa::where('slug', 'motor_de_ofertas')->first();
        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'motor_de_ofertas', 'name' => 'Motor de ofertas']);
        }
        $this->user->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->user->load('extencions');
        $this->actingAs($this->user, 'web');
    }

    /** @param array $atributos @return Client */
    protected function cliente(array $atributos = [])
    {
        return Client::create(array_merge(['name' => 'zz-cli-erp-' . uniqid(), 'user_id' => 500], $atributos));
    }

    /** @param Client $cliente @param array $atributos @return Buyer */
    protected function comprador($cliente, array $atributos = [])
    {
        return Buyer::create(array_merge([
            'name' => 'Sofia', 'surname' => 'Martinez', 'email' => 'zz-buyer-' . uniqid() . '@test.local',
            'user_id' => 500, 'comercio_city_client_id' => $cliente->id,
        ], $atributos));
    }

    /** @param array $atributos @return Article */
    protected function articulo(array $atributos = [])
    {
        return Article::create(array_merge(['name' => 'zz-art-nombre-' . uniqid(), 'user_id' => 500], $atributos));
    }

    /** @param array $atributos @return OfferSuggestion */
    protected function corrida(array $atributos = [])
    {
        return OfferSuggestion::create(array_merge([
            'user_id' => 500, 'status' => 'terminado', 'origen_generacion' => 'manual',
            'dias_vigencia_sugerida' => 15,
        ], $atributos));
    }

    /** @param OfferSuggestion $corrida @param Client $client @param Article $article @param array $atributos @return OfferSuggestionLine */
    protected function linea($corrida, $client, $article, array $atributos = [])
    {
        return OfferSuggestionLine::create(array_merge([
            'offer_suggestion_id' => $corrida->id, 'client_id' => $client->id, 'article_id' => $article->id,
            'tipo_descuento' => 'unidad', 'porcentaje_sugerido' => 10, 'porcentaje_techo' => 40,
            'porcentaje_piso' => 5, 'margen_base' => 100, 'criterio' => 'afinidad',
        ], $atributos));
    }

    /** Una oferta ACTIVA, escrita directo (sin pasar por el endpoint de activación). @return ClientOffer */
    protected function oferta_activa($client, $article, array $atributos = [])
    {
        return ClientOffer::create(array_merge([
            'user_id' => 500, 'client_id' => $client->id, 'article_id' => $article->id,
            'tipo_descuento' => 'unidad', 'porcentaje' => 10, 'estado' => 'activa',
            'desde' => Carbon::today()->toDateString(), 'hasta' => Carbon::today()->addDays(10)->toDateString(),
        ], $atributos));
    }

    /**
     * 🔴 LA ASERCIÓN CENTRAL DEL PUNTO B: la grilla de sugeridas tiene que mostrar el nombre del
     * comprador de la tienda, no la razón social del cliente del ERP.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function la_grilla_de_sugeridas_muestra_el_nombre_del_comprador_y_no_el_del_cliente()
    {
        $client = $this->cliente(['name' => 'Ferreteria San Jose SRL']);
        $this->comprador($client, ['name' => 'Sofia', 'surname' => 'Martinez']);
        $article = $this->articulo();
        $corrida = $this->corrida();
        $this->linea($corrida, $client, $article);

        $response = $this->getJson('api/offer-suggestion/' . $corrida->id . '/lines')->assertStatus(200);
        $fila = $response->json('models.data.0');

        $this->assertSame('Sofia Martinez', $fila['client_nombre']);
        $this->assertNotSame($client->name, $fila['client_nombre']);
    }

    /**
     * 🔴 Los tres precedentes de la SPA escupen "Sofia undefined" o "Sofia " cuando falta el
     * apellido; compuesto del lado del servidor, acá va con trim y assertSame exacto: el punto es
     * que no quede "Sofia " con el espacio de más.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function un_comprador_sin_apellido_sale_sin_espacio_de_mas()
    {
        $client = $this->cliente();
        $this->comprador($client, ['name' => 'Sofia', 'surname' => null]);
        $article = $this->articulo();
        $corrida = $this->corrida();
        $this->linea($corrida, $client, $article);

        $response = $this->getJson('api/offer-suggestion/' . $corrida->id . '/lines')->assertStatus(200);
        $this->assertSame('Sofia', $response->json('models.data.0.client_nombre'));
    }

    /**
     * El fallback defensivo que pidió Lucas: buyers.name es NOT NULL pero puede venir en blanco.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function si_el_comprador_no_tiene_nombre_cargado_se_muestra_el_del_cliente()
    {
        $client = $this->cliente(['name' => 'Cliente con buyer en blanco']);
        $this->comprador($client, ['name' => '   ', 'surname' => null]);
        $article = $this->articulo();
        $corrida = $this->corrida();
        $this->linea($corrida, $client, $article);

        $response = $this->getJson('api/offer-suggestion/' . $corrida->id . '/lines')->assertStatus(200);
        $this->assertSame($client->name, $response->json('models.data.0.client_nombre'));
    }

    /**
     * Una línea persistida ANTES del punto A, de un cliente sin buyer: la columna nunca queda
     * vacía habiendo un nombre de algún lado.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function una_linea_vieja_sin_buyer_sigue_mostrando_el_nombre_del_cliente()
    {
        $client = $this->cliente(['name' => 'Cliente sin ningun buyer']);
        $article = $this->articulo();
        $corrida = $this->corrida();
        $this->linea($corrida, $client, $article);

        $response = $this->getJson('api/offer-suggestion/' . $corrida->id . '/lines')->assertStatus(200);
        $this->assertSame('Cliente sin ningun buyer', $response->json('models.data.0.client_nombre'));
    }

    /**
     * 🔴 client_nombre viaja en las ofertas ACTIVAS, no solo en las sugeridas — y `client.name`
     * sigue viajando intacto: es lo que usa la SPA para el display_name del chat de WhatsApp y
     * para telefono_de_chat_de_oferta() (§3.5.1 del plan, explícitamente fuera de alcance). Si
     * alguien "limpia" el client de la respuesta, esta segunda mitad se pone roja.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function las_ofertas_activas_traen_client_nombre_y_conservan_client_name()
    {
        $client = $this->cliente(['name' => 'Cliente con oferta activa']);
        $this->comprador($client, ['name' => 'Sofia', 'surname' => 'Martinez']);
        $article = $this->articulo();
        $this->oferta_activa($client, $article);

        $response = $this->getJson('api/client-offer')->assertStatus(200);
        $fila = $response->json('models.data.0');

        $this->assertSame('Sofia Martinez', $fila['client_nombre']);
        $this->assertSame('Cliente con oferta activa', $fila['client']['name'],
            'client.name tiene que seguir viajando intacto: la SPA lo usa para el chat de WhatsApp');
    }

    /**
     * El WhatsApp le habla AL comprador, no al ERP.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_mensaje_de_whatsapp_saluda_al_comprador()
    {
        $con_buyer = $this->cliente(['name' => 'Cliente con buyer para whatsapp']);
        $this->comprador($con_buyer, ['name' => 'Sofia', 'surname' => 'Martinez']);
        $article = $this->articulo();
        $offer_con_buyer = $this->oferta_activa($con_buyer, $article);

        $texto = OfertaComunicacionHelper::texto_del_mensaje($offer_con_buyer->fresh(), $this->user);
        $this->assertSame('Hola Sofia Martinez! ', substr($texto, 0, strlen('Hola Sofia Martinez! ')));

        $sin_buyer = $this->cliente(['name' => 'Cliente sin buyer para whatsapp']);
        $offer_sin_buyer = $this->oferta_activa($sin_buyer, $this->articulo());

        $texto_sin_buyer = OfertaComunicacionHelper::texto_del_mensaje($offer_sin_buyer->fresh(), $this->user);
        $saludo_esperado = 'Hola ' . $sin_buyer->name . '! ';
        $this->assertSame($saludo_esperado, substr($texto_sin_buyer, 0, strlen($saludo_esperado)));
    }

    /**
     * El mail sale preferentemente al buyers.email: llamarlo por la razón social del ERP es justo
     * lo que este cambio viene a arreglar.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_mail_de_la_oferta_nombra_al_comprador()
    {
        $client = $this->cliente(['name' => 'Cliente del mail']);
        $this->comprador($client, ['name' => 'Sofia', 'surname' => 'Martinez']);
        $article = $this->articulo();
        $offer = $this->oferta_activa($client, $article);

        ComercioCityMailHelper::nueva_oferta($offer->fresh());

        $nombre_en_el_mail = null;
        Mail::assertQueued(ComercioCityMail::class, function ($mail) use (&$nombre_en_el_mail) {
            foreach ($mail->payload->detail_lines as $linea) {
                if ($linea['label'] === 'Cliente') {
                    $nombre_en_el_mail = $linea['value'];
                }
            }

            return true; // no filtra: solo se usa para leer el payload del mail encolado.
        });

        $this->assertSame('Sofia Martinez', $nombre_en_el_mail);
    }

    /**
     * El bloque de datos que lee la IA tiene que nombrar al comprador, no al ERP.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_bloque_de_datos_de_la_ia_nombra_al_comprador()
    {
        $client = $this->cliente(['name' => 'Cliente del resumen ia']);
        $this->comprador($client, ['name' => 'Sofia', 'surname' => 'Martinez']);
        $article = $this->articulo();
        $corrida = $this->corrida();
        $this->linea($corrida, $client, $article, ['prioridad' => 1]);

        $datos = (new ResumenIaOfertasService())->armar_datos($corrida->fresh());

        $this->assertStringContainsString('Sofia Martinez', $datos);
        $this->assertStringNotContainsString('Cliente del resumen ia', $datos);
    }

    /**
     * 🔴 HALLAZGO DEL CHEQUEO INDEPENDIENTE (26/8/2026): `Client::buyer()` es un `hasOne` SIN
     * `where('user_id', ...)` — el mismo vínculo manual que ya obliga a CriteriosDeOfertaService::
     * afinidad() a filtrar por `buyers.user_id` para no habilitar candidatos con un buyer ajeno.
     * Sin la misma validación del lado de la visualización, la columna "Cliente" de ESTE comercio
     * podía mostrar el nombre de un comprador real de OTRO comercio con el que ese Client no tiene
     * ninguna relación de negocio — no una cosmética, un dato ajeno mostrado donde no corresponde.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function un_buyer_de_otro_comercio_no_se_muestra_en_la_columna_cliente()
    {
        $client = $this->cliente(['name' => 'Cliente con buyer ajeno']);
        $article = $this->articulo();
        $corrida = $this->corrida();
        $this->linea($corrida, $client, $article);

        $otro_comercio = User::create([
            'name' => 'Otro comercio P12', 'password' => bcrypt('secret'),
            'email' => 'otro-comercio-p12-' . uniqid() . '@test.local',
        ]);
        Buyer::create([
            'name' => 'Comprador de otro comercio', 'surname' => 'Ajeno',
            'email' => 'buyer-ajeno-p12-' . uniqid() . '@test.local',
            'user_id' => $otro_comercio->id, 'comercio_city_client_id' => $client->id,
        ]);

        $response = $this->getJson('api/offer-suggestion/' . $corrida->id . '/lines')->assertStatus(200);
        $this->assertSame(
            'Cliente con buyer ajeno',
            $response->json('models.data.0.client_nombre'),
            'un buyer de otro comercio no puede aparecer en la columna Cliente de este comercio; cae al nombre del cliente del ERP'
        );
    }

    /**
     * 🔴 GUARD DE N+1: sin el eager-load de client.buyer en scopeWithAll(), cada fila de la grilla
     * pega su propia consulta a buyers. Con ~20 clientes distintos, el conteo de consultas no
     * puede crecer con la cantidad de filas.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function la_grilla_de_sugeridas_no_hace_una_consulta_por_fila()
    {
        $corrida = $this->corrida();
        $article = $this->articulo();

        for ($i = 0; $i < 20; $i++) {
            $client = $this->cliente(['name' => 'Cliente n+1 ' . $i]);
            $this->comprador($client, ['name' => 'Comprador', 'surname' => (string) $i]);
            $this->linea($corrida, $client, $article);
        }

        DB::enableQueryLog();
        $this->getJson('api/offer-suggestion/' . $corrida->id . '/lines')->assertStatus(200);
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Un puñado fijo de consultas (líneas + su cliente + sus buyers + su artículo + el conteo
        // del paginador, más lo que agregue el framework alrededor de la request), no una por
        // fila: sin el eager-load, 20 líneas agregarían 20 consultas más solo para los buyers.
        $this->assertLessThan(20, $consultas,
            'la cantidad de consultas no puede crecer con la cantidad de filas de la grilla');
    }
}
