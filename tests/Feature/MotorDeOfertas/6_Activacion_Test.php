<?php

namespace Tests\Feature\MotorDeOfertas;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Mail\ComercioCityMail;
use App\Models\Article;
use App\Models\Client;
use App\Models\ClientOffer;
use App\Models\ClientOfferRange;
use App\Models\ExtencionEmpresa;
use App\Models\OfferSuggestion;
use App\Models\OfferSuggestionLine;
use App\Models\User;
use App\Services\OfertasClientes\TechoDeDescuentoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Misión motor-de-ofertas-por-cliente — archivo 6: LA ACTIVACIÓN, el único
 * camino de escritura de client_offers y por lo tanto el único lugar donde esta
 * misión le habla a la tienda (misión 5).
 *
 * Los dos que dan sentido al archivo son el del techo re-validado con los datos
 * de HOY (y no con el guardado en la línea) y el que corre TAL CUAL la query de
 * §A.6: si alguien cambia una columna o un estado, ese test se pone rojo antes
 * de que se entere la tienda. 🔴 Nada de precios hardcodeados: la base del slot
 * tiene DOS sale_taxes con apply_to_all, así que el techo sale del servicio.
 */
class Activacion_Test extends TestCase
{
    use DatabaseTransactions;

    /** ivas.id de la alícuota del 21%. */
    const IVA_21 = 2;

    /** @var User */
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();
        // Sin esto el pipeline sale a la API real de Anthropic: la clave vive en
        // el .env.testing y la cola de phpunit corre en sync.
        config(['services.anthropic.api_key' => null]);
        $this->user = User::find(500);
        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        // forceCreate y no firstOrCreate: ExtencionEmpresa no declara $fillable
        // y fuera de Model::unguarded() (que solo aplica db:seed) el create falla.
        $extencion = ExtencionEmpresa::where('slug', 'motor_de_ofertas')->first();
        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'motor_de_ofertas', 'name' => 'Motor de ofertas']);
        }
        // syncWithoutDetaching y no attach: el usuario 500 de la base sembrada
        // puede ya tenerla y un attach duplicado deja dos filas en el pivot.
        $this->user->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->user->load('extencions');
        $this->actingAs($this->user, 'web');
    }

    /**
     * Artículo pasado por el camino REAL (setFinalPrice con $guardar_cambios =
     * true) para que costo_real y final_price queden persistidos como en
     * producción. Molde del archivo 2. @param array $atributos @return Article
     */
    protected function articulo(array $atributos = [])
    {
        $article = Article::create(array_merge([
            'name'            => 'zz-ofertas-activacion-' . uniqid(),
            'user_id'         => 500,
            'cost'            => 1000,
            'percentage_gain' => 100,
            'aplicar_iva'     => 1,
            'iva_id'          => self::IVA_21,
        ], $atributos));
        ArticleHelper::setFinalPrice($article, null, $this->user, null, true);

        return Article::find($article->id);
    }

    /** @param array $atributos @return Client */
    protected function cliente(array $atributos = [])
    {
        return Client::create(array_merge(['name' => 'zz-cli-ofertas-' . uniqid(), 'user_id' => 500], $atributos));
    }

    /**
     * Una línea lista para activar, sin correr el pipeline: acá se prueba la
     * activación, no el cálculo. $user_corrida simula una corrida ajena.
     *
     * @param Article $article @param Client $client @param array $overrides
     * @param int|null $user_corrida @return OfferSuggestionLine
     */
    protected function linea($article, $client, array $overrides = [], $user_corrida = null)
    {
        $suggestion = OfferSuggestion::create([
            'user_id'           => is_null($user_corrida) ? 500 : $user_corrida,
            'status'            => 'terminado',
            'origen_generacion' => 'manual',
        ]);
        return OfferSuggestionLine::create(array_merge([
            'offer_suggestion_id' => $suggestion->id,
            'client_id'           => $client->id,
            'article_id'          => $article->id,
            'tipo_descuento'      => 'unidad',
            'porcentaje_sugerido' => 10,
            'porcentaje_techo'    => 40,
            'porcentaje_piso'     => 5,
            'margen_base'         => 100,
            'criterio'            => 'afinidad',
        ], $overrides));
    }

    /**
     * POST api/client-offer con los valores del caso feliz, pisables por $body.
     *
     * @param OfferSuggestionLine $linea @param array $body @return \Illuminate\Testing\TestResponse
     */
    protected function activar($linea, array $body = [])
    {
        $payload = array_merge([
            'offer_suggestion_line_id' => $linea->id,
            'porcentaje'               => 10,
            'hasta'                    => Carbon::today()->addDays(10)->toDateString(),
            'tipo_descuento'           => 'unidad',
        ], $body);

        /*
         * 🔴 EN 'cantidad' EL PORCENTAJE VIAJA EN null, QUE ES LO QUE MANDA LA SPA
         * (ModalActivar.vue:328) y lo que se guarda en la fila: el número vive en los
         * tramos. Hasta el 15/8/2026 este helper inyectaba un 10 fijo también en los
         * casos 'cantidad', así que el backend quedaba probado contra un payload que la
         * SPA no manda nunca — y contra el real devolvía 422 SIEMPRE, o sea que ninguna
         * oferta por cantidad se podía activar y la suite entera estaba verde.
         * Un test que le pone al request algo que el front no manda no prueba el front.
         */
        if ($payload['tipo_descuento'] === 'cantidad' && !array_key_exists('porcentaje', $body)) {
            $payload['porcentaje'] = null;
        }

        return $this->postJson('api/client-offer', $payload);
    }

    /** El techo determinista de HOY, calculado con el servicio real. */
    protected function techo_de($article, $client = null)
    {
        return TechoDeDescuentoService::calcular($article, $client, $this->user)['techo'];
    }

    /**
     * 🔴 EL CAMINO COMPLETO: la promoción vigente, el mail ENCOLADO, el link de
     * WhatsApp armado y la línea marcada como activada.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_camino_completo_deja_la_promocion_el_mail_y_el_link()
    {
        $client = $this->cliente(['email' => 'zz-oferta@test.local', 'phone' => '1126322965']);
        $linea = $this->linea($this->articulo(), $client);
        $hasta = Carbon::today()->addDays(15)->toDateString();
        $this->activar($linea, ['hasta' => $hasta])->assertStatus(201);

        // (1) la promoción vigente
        $offer = ClientOffer::where('offer_suggestion_line_id', $linea->id)->first();
        $this->assertNotNull($offer, 'la activación tiene que dejar un ClientOffer');
        $this->assertSame('activa', $offer->estado);
        $this->assertSame(Carbon::today()->toDateString(), Carbon::parse($offer->desde)->toDateString());
        $this->assertSame($hasta, Carbon::parse($offer->hasta)->toDateString());
        $this->assertEquals(10, (float) $offer->porcentaje);
        // (2) 🔴 el mail quedó ENCOLADO, no enviado: se manda con ->queue() y
        // ningún Mailable del repo implementa ShouldQueue (los 6 importan la
        // interfaz y ninguno la implementa). assertSent daría rojo acá, y sería
        // un rojo correcto.
        Mail::assertQueued(ComercioCityMail::class);
        $this->assertSame('zz-oferta@test.local', $offer->email_destino);
        $this->assertNotNull($offer->notificada_email_at);
        // (3) el link click-to-chat
        $this->assertNotNull($offer->whatsapp_url);
        $this->assertSame('https://api.whatsapp.com/send?phone=', substr($offer->whatsapp_url, 0, 36));
        $this->assertSame('5491126322965', $offer->whatsapp_telefono);
        // (4) la vista tiene que poder mostrar "ya activada"
        $this->assertSame($offer->id, (int) $linea->fresh()->client_offer_id);
    }

    /**
     * El invariante de A.2.3, que NO está en la base (no hay unique) y vive en
     * la transacción de la activación: una sola oferta ACTIVA por par.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function activar_el_mismo_par_dos_veces_deja_una_sola_activa()
    {
        $article = $this->articulo();
        $client = $this->cliente();
        $this->activar($this->linea($article, $client))->assertStatus(201);
        $this->activar($this->linea($article, $client))->assertStatus(201);
        $del_par = ClientOffer::where('user_id', 500)->where('client_id', $client->id)->where('article_id', $article->id);
        $this->assertSame(1, (clone $del_par)->where('estado', 'activa')->count(),
            'no puede quedar más de una oferta activa por (comercio, cliente, artículo)');
        // La anterior no se borra: queda como historial.
        $this->assertSame(1, (clone $del_par)->where('estado', 'cancelada')->count());
    }

    /**
     * 🔴 EL TECHO SE RE-VALIDA CON LOS DATOS DE HOY. La línea trae un
     * porcentaje_techo generoso guardado: si el controller lo leyera de ahí en
     * vez de recalcular, este test pasaría igual y el sistema vendería bajo costo.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function un_porcentaje_por_encima_del_techo_de_hoy_devuelve_422_y_no_crea_nada()
    {
        $client = $this->cliente();
        $article = $this->articulo();
        $linea = $this->linea($article, $client, ['porcentaje_techo' => 95]);
        $techo = $this->techo_de($article, $client);
        $response = $this->activar($linea, ['porcentaje' => $techo + 1]);

        $response->assertStatus(422);
        $response->assertJsonPath('porcentaje_techo', $techo);
        $this->assertSame(0, ClientOffer::where('offer_suggestion_line_id', $linea->id)->count());
        $this->assertNull($linea->fresh()->client_offer_id);
        // Y justo EN el techo sí entra: el 422 es del punto de más, no del techo.
        $this->activar($linea, ['porcentaje' => $techo])->assertStatus(201);
    }

    /**
     * Tenencia: la línea se resuelve por el dueño de la CORRIDA (las líneas no
     * tienen user_id). Sin ese join, un id adivinado activaba una oferta sobre
     * el cliente de otro comercio.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function una_linea_de_otro_comercio_devuelve_404()
    {
        $otro = User::create([
            'name'     => 'Comercio ajeno ofertas',
            'email'    => 'zz-ofertas-ajeno-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);
        $linea = $this->linea($this->articulo(), $this->cliente(), [], $otro->id);
        $this->activar($linea)->assertStatus(404);
        $this->assertSame(0, ClientOffer::where('offer_suggestion_line_id', $linea->id)->count());
    }

    /**
     * @group motor-de-ofertas
     * @test
     */
    public function el_delete_cancela_la_oferta_y_no_borra_la_fila()
    {
        $linea = $this->linea($this->articulo(), $this->cliente());
        $this->activar($linea)->assertStatus(201);
        $offer = ClientOffer::where('offer_suggestion_line_id', $linea->id)->first();
        $this->delete('api/client-offer/' . $offer->id)->assertStatus(204);

        $this->assertNotNull(ClientOffer::find($offer->id), 'el historial no se borra');
        $this->assertSame('cancelada', ClientOffer::find($offer->id)->estado);
        // La línea vuelve a quedar activable desde la misma corrida.
        $this->assertNull($linea->fresh()->client_offer_id);
    }

    /**
     * Tramos inválidos: 422 y NADA creado (ni la oferta ni los tramos). Un hueco
     * deja al que lleva 6 unidades sin descuento aplicable, y la tienda no tiene
     * cómo adivinar si eso fue a propósito.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function tramos_invalidos_devuelven_422_sin_crear_nada()
    {
        $article = $this->articulo();
        $client = $this->cliente();
        $linea = $this->linea($article, $client, ['tipo_descuento' => 'cantidad']);
        $techo = $this->techo_de($article, $client);
        // En orden: hueco (5 y después 8), min decreciente, no arranca en 1, el
        // último con techo (nadie cubre de 10 en adelante), y un tramo por
        // encima del techo de hoy.
        $casos = [
            [['min' => 1, 'max' => 5, 'porcentaje' => 5], ['min' => 8, 'max' => null, 'porcentaje' => 10]],
            [['min' => 1, 'max' => 5, 'porcentaje' => 5], ['min' => 3, 'max' => null, 'porcentaje' => 10]],
            [['min' => 2, 'max' => null, 'porcentaje' => 5]],
            [['min' => 1, 'max' => 5, 'porcentaje' => 5], ['min' => 6, 'max' => 9, 'porcentaje' => 10]],
            [['min' => 1, 'max' => null, 'porcentaje' => $techo + 1]],
        ];

        foreach ($casos as $i => $tramos) {
            $this->activar($linea, ['tipo_descuento' => 'cantidad', 'tramos' => $tramos])
                ->assertStatus(422, 'el caso ' . $i . ' tenía que ser rechazado');
        }

        $this->assertSame(0, ClientOffer::where('offer_suggestion_line_id', $linea->id)->count());
        $this->assertSame(0, ClientOfferRange::whereIn('client_offer_id',
            ClientOffer::where('user_id', 500)->where('article_id', $article->id)->pluck('id'))->count());
    }

    /**
     * 🔴 EL PAYLOAD EXACTO DE LA SPA, TIPEADO ACÁ SIN PASAR POR EL HELPER: con 'cantidad',
     * ModalActivar.vue:324-339 manda `porcentaje: null` y el número adentro de cada tramo.
     *
     * Hasta el 15/8/2026 el controller casteaba y validaba el porcentaje ANTES de leer
     * tipo_descuento: input('porcentaje', 0) con la clave presente en null devuelve null
     * (el default de input() solo aplica si la clave FALTA), (int) null da 0 y el min:1
     * lo rechazaba. Resultado: NINGUNA oferta por cantidad se podía activar, 422 siempre,
     * y el toast hablaba de un campo que está detrás de un v-if y no se ve en pantalla.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function una_oferta_por_cantidad_se_activa_con_el_porcentaje_en_null_como_lo_manda_la_spa()
    {
        $article = $this->articulo();
        $client  = $this->cliente();
        $linea   = $this->linea($article, $client, ['tipo_descuento' => 'cantidad']);
        $techo   = $this->techo_de($article, $client);

        $response = $this->postJson('api/client-offer', [
            'offer_suggestion_line_id' => $linea->id,
            'porcentaje'               => null,
            'hasta'                    => Carbon::today()->addDays(10)->toDateString(),
            'tipo_descuento'           => 'cantidad',
            'tramos'                   => [
                ['min' => 1, 'max' => 5, 'porcentaje' => 5],
                ['min' => 6, 'max' => null, 'porcentaje' => min(12, $techo)],
            ],
        ]);

        $response->assertStatus(201);

        $offer = ClientOffer::where('offer_suggestion_line_id', $linea->id)->first();
        $this->assertNotNull($offer, 'con el payload real de la SPA la oferta tiene que quedar creada');
        $this->assertSame('cantidad', $offer->tipo_descuento);
        // 🔴 Y el porcentaje sigue guardándose NULL: el arreglo es del lado del controller,
        // el contrato de la SPA es el correcto y no se toca.
        $this->assertNull($offer->porcentaje);
        $this->assertSame(2, ClientOfferRange::where('client_offer_id', $offer->id)->count());
        $this->assertSame($offer->id, (int) $linea->fresh()->client_offer_id);

        // Y el porcentaje sigue siendo obligatorio en 'unidad', que es donde sí se usa.
        $this->postJson('api/client-offer', [
            'offer_suggestion_line_id' => $this->linea($article, $this->cliente())->id,
            'porcentaje'               => null,
            'hasta'                    => Carbon::today()->addDays(10)->toDateString(),
            'tipo_descuento'           => 'unidad',
        ])->assertStatus(422);
    }

    /**
     * 🔴 Al activar el mismo par desde otra corrida, la línea VIEJA tiene que quedar desapuntada.
     * Si conserva su client_offer_id sigue mostrando el badge "Activada" apuntando a una oferta
     * que ya está cancelada, y el comerciante cree que esa promoción está corriendo. destroy() ya
     * lo hacía; esta punta no.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function activar_el_mismo_par_de_nuevo_desapunta_la_linea_de_la_oferta_cancelada()
    {
        $article = $this->articulo();
        $client  = $this->cliente();
        $vieja   = $this->linea($article, $client);
        $this->activar($vieja)->assertStatus(201);
        $offer_vieja = ClientOffer::where('offer_suggestion_line_id', $vieja->id)->first();
        $this->assertSame($offer_vieja->id, (int) $vieja->fresh()->client_offer_id);

        // Otra corrida, el mismo (comercio, cliente, artículo).
        $nueva = $this->linea($article, $client);
        $this->activar($nueva)->assertStatus(201);
        $offer_nueva = ClientOffer::where('offer_suggestion_line_id', $nueva->id)->first();

        $this->assertSame('cancelada', ClientOffer::find($offer_vieja->id)->estado);
        $this->assertNull($vieja->fresh()->client_offer_id,
            'la línea vieja no puede seguir apuntando a una oferta que ya no corre');
        $this->assertSame($offer_nueva->id, (int) $nueva->fresh()->client_offer_id,
            'y la nueva sí queda apuntada: el desapuntado no se lleva puesta a la que acaba de activarse');
    }

    /**
     * 🔴 El invariante "una sola activa por par" es un UPDATE + INSERT sin unique en la base, así
     * que sin lock dos activaciones simultáneas del mismo par leen las dos "no hay activa", cancelan
     * cero filas y insertan las dos: quedan DOS activas y la tienda ve dos ofertas del mismo
     * artículo sin desempate. Una carrera real no se puede montar en phpunit con una sola conexión,
     * así que lo que se afirma es que el SELECT del par sale con FOR UPDATE: si alguien saca el
     * lockForUpdate(), esto se pone rojo.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function la_activacion_lockea_el_par_antes_de_cancelar_y_de_insertar()
    {
        $consultas = [];
        DB::listen(function ($query) use (&$consultas) {
            $consultas[] = strtolower($query->sql);
        });

        $this->activar($this->linea($this->articulo(), $this->cliente()))->assertStatus(201);

        $con_lock = array_filter($consultas, function ($sql) {
            return strpos($sql, 'client_offers') !== false
                && strpos($sql, 'select') === 0
                && strpos($sql, 'for update') !== false;
        });

        $this->assertNotEmpty($con_lock,
            'la transacción de la activación tiene que lockear el par de client_offers antes de escribirlo');
    }

    /**
     * 🔴 EL CONTRATO CON LA MISIÓN 5, corrido TAL CUAL está escrito en §A.6 y en
     * el docblock de la migración: si deja de devolver la oferta recién
     * activada, la tienda deja de mostrarla y de este lado nadie se entera.
     * También verifica lo que NO tiene que devolver: cancelada y vencida.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function la_query_del_contrato_de_la_mision_5_ve_la_oferta_recien_activada()
    {
        $article = $this->articulo();
        $client = $this->cliente();
        $linea = $this->linea($article, $client, ['tipo_descuento' => 'cantidad']);
        $techo = $this->techo_de($article, $client);
        $this->activar($linea, [
            'hasta'          => Carbon::today()->addDays(20)->toDateString(),
            'tipo_descuento' => 'cantidad',
            'tramos'         => [
                ['min' => 1, 'max' => 5, 'porcentaje' => 5],
                ['min' => 6, 'max' => null, 'porcentaje' => min(12, $techo)],
            ],
        ])->assertStatus(201);
        $filas = $this->ofertas_vigentes_de($client->id, $article->id, 'co.id, co.tipo_descuento, co.porcentaje');

        $this->assertCount(1, $filas, 'la tienda tiene que ver la oferta recién activada');
        $this->assertSame('cantidad', $filas[0]->tipo_descuento);
        // 🔴 En 'cantidad' el porcentaje va NULL: el número vive en los tramos.
        $this->assertNull($filas[0]->porcentaje);
        $tramos = DB::select(
            'SELECT r.client_offer_id, r.min, r.max, r.porcentaje
             FROM client_offer_ranges r WHERE r.client_offer_id IN (?) ORDER BY r.min ASC',
            [$filas[0]->id]
        );
        $this->assertCount(2, $tramos);
        $this->assertEquals(1, $tramos[0]->min);
        // max null = sin techo: es la convención que la tienda ya sabe leer.
        $this->assertNull($tramos[1]->max);

        ClientOffer::where('id', $filas[0]->id)->update(['estado' => 'cancelada']);
        $this->assertCount(0, $this->ofertas_vigentes_de($client->id, $article->id));
        ClientOffer::where('id', $filas[0]->id)->update([
            'estado' => 'activa',
            'hasta'  => Carbon::today()->subDay()->toDateString(),
        ]);
        $this->assertCount(0, $this->ofertas_vigentes_de($client->id, $article->id),
            'una oferta pasada de fecha deja de aplicarse aunque el barrido de higiene no haya corrido');
    }

    /**
     * La query de §A.6, textual: todos los filtros de la tienda están acá.
     * @param int $client_id @param int $article_id @param string $columnas
     * @return array
     */
    protected function ofertas_vigentes_de($client_id, $article_id, $columnas = 'co.id')
    {
        return DB::select(
            'SELECT ' . $columnas . ' FROM client_offers co
             WHERE co.user_id = ? AND co.client_id = ? AND co.estado = "activa"
               AND co.desde <= CURDATE() AND co.hasta >= CURDATE() AND co.article_id IN (?)',
            [500, $client_id, $article_id]
        );
    }
}
