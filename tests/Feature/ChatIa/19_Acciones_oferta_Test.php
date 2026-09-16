<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Mail\ComercioCityMail;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Client;
use App\Models\ClientOffer;
use App\Models\ClientOfferRange;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\OfertasClientes\TechoDeDescuentoService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\EmpresaTestCase;

/**
 * Misión agente-ia-mano-derecha — la oferta por cliente que propone el asistente y lo que pasa al
 * confirmarla.
 *
 * 🔴 ESTE ARCHIVO ES EL QUE CUIDA EL CONTRATO CON LA TIENDA. `client_offers` y
 * `client_offer_ranges` las lee `tienda-api` directo de la base compartida, con la query que está
 * escrita textual en la migración; acá se corre esa query TAL CUAL contra una oferta creada desde el
 * chat. Y el invariante "una sola oferta ACTIVA por (comercio, cliente, artículo)" no está en la
 * base a propósito: vive en el lockForUpdate de ClientOfertaAltaHelper::activar(), así que este
 * archivo lo fija desde el camino nuevo.
 *
 * Lo demás que protege: que la tarjeta muestre cliente, artículo, tramos y vigencia; que lo que
 * falta se pregunte sin dejar tarjeta; que el techo de descuento de HOY frene el pedido en vez de
 * recortarlo en silencio; que un tramo con hueco no pase; que el segundo clic no active dos veces;
 * y que desde el chat NO le salga ningún mail al cliente.
 *
 * 🔴 Nada de porcentajes hardcodeados: el techo sale del servicio real, porque la base del slot
 * tiene sale_taxes con apply_to_all y un número fijo daría rojo o —peor— verde por casualidad.
 *
 * @group chat-ia
 */
class Acciones_oferta_Test extends EmpresaTestCase
{
    /** ivas.id de la alícuota del 21%. */
    const IVA_21 = 2;

    /** @var User */
    protected $dueno;

    /** @var AsistenteIaService */
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();

        // 🔴 Sin esto algo del camino podría salir a la API real de Anthropic: la clave vive en el
        // .env.testing y la cola de phpunit corre en sync.
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->dueno = User::find(500);

        if (is_null($this->dueno)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->extencion('asistente_ia', 'Asistente IA');
        $this->extencion('motor_de_ofertas', 'Motor de ofertas');

        $this->dueno->load('extencions');

        $this->service = new AsistenteIaService();
    }

    /**
     * Le asegura la extensión al dueño (el rollback de la transacción la saca al terminar).
     *
     * @param string $slug
     * @param string $nombre
     * @return ExtencionEmpresa
     */
    protected function extencion($slug, $nombre)
    {
        // forceCreate y no firstOrCreate: ExtencionEmpresa no declara $fillable.
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $nombre]);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);

        return $extencion;
    }

    /**
     * Conversación del dueño con su assistant pendiente y las acciones habilitadas.
     *
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $this->dueno->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Hacele una oferta',
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * Llama a una herramienta por el mismo camino que el loop del servicio.
     *
     * @param AiConversation $conversation
     * @param AiMessage $assistant
     * @param array $input
     * @return array
     */
    protected function proponer($conversation, $assistant, array $input)
    {
        $resultados = $this->service->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_' . uniqid(),
            'name'  => 'proponer_oferta',
            'input' => $input,
        ]], $conversation, $assistant);

        $this->assertArrayNotHasKey('is_error', $resultados[0], 'La herramienta devolvió una falla técnica: ' . $resultados[0]['content']);

        return json_decode($resultados[0]['content'], true);
    }

    /**
     * Deja el mensaje del assistant en 'listo': la SPA recién ahí muestra la tarjeta.
     *
     * @param AiMessage $assistant
     * @return void
     */
    protected function mensaje_listo($assistant)
    {
        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();
    }

    /**
     * Artículo pasado por el camino REAL (setFinalPrice con $guardar_cambios = true) para que
     * costo_real y final_price queden persistidos como en producción. Molde copiado de
     * tests/Feature/MotorDeOfertas/6_Activacion_Test.php. Se llama `articulo_nuevo` y no
     * `articulo` porque EmpresaTestCase::articulo($nombre) ya existe y busca uno del fixture.
     *
     * @param array $atributos
     * @return Article
     */
    protected function articulo_nuevo(array $atributos = [])
    {
        $article = Article::create(array_merge([
            'name'            => 'zz-oferta-ia-' . uniqid(),
            'user_id'         => 500,
            'cost'            => 1000,
            'percentage_gain' => 100,
            'aplicar_iva'     => 1,
            'iva_id'          => self::IVA_21,
        ], $atributos));
        ArticleHelper::setFinalPrice($article, null, $this->dueno, null, true);

        return Article::find($article->id);
    }

    /**
     * @param array $atributos
     * @return Client
     */
    protected function cliente(array $atributos = [])
    {
        return Client::create(array_merge(['name' => 'zz-cli-oferta-ia-' . uniqid(), 'user_id' => 500], $atributos));
    }

    /**
     * El techo determinista de HOY, calculado con el servicio real.
     *
     * @param Article $article
     * @param Client|null $client
     * @return int
     */
    protected function techo_de($article, $client = null)
    {
        return TechoDeDescuentoService::calcular($article, $client, $this->dueno)['techo'];
    }

    /**
     * Todos los renglones de la presentación con esa etiqueta, en orden.
     *
     * @param array $presentacion
     * @param string $etiqueta
     * @return array<int,string>
     */
    protected function renglones(array $presentacion, $etiqueta)
    {
        $valores = [];

        foreach ($presentacion['renglones'] as $renglon) {
            if ($renglon['etiqueta'] === $etiqueta) {
                $valores[] = $renglon['valor'];
            }
        }

        return $valores;
    }

    /**
     * El primer renglón con esa etiqueta.
     *
     * @param array $presentacion
     * @param string $etiqueta
     * @return string|null
     */
    protected function renglon(array $presentacion, $etiqueta)
    {
        $valores = $this->renglones($presentacion, $etiqueta);

        return count($valores) ? $valores[0] : null;
    }

    /**
     * La query de la tienda, textual como está en el docblock de la migración: todos sus filtros
     * están acá.
     *
     * @param int $client_id
     * @param int $article_id
     * @param string $columnas
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

    /**
     * @test
     */
    public function una_propuesta_por_tramos_deja_la_tarjeta_con_el_cliente_el_articulo_y_los_tramos()
    {
        $article = $this->articulo_nuevo();
        $client = $this->cliente();
        $techo = $this->techo_de($article, $client);
        $hasta = Carbon::today()->addDays(20);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, [
            'cliente_id'  => $client->id,
            'articulo_id' => $article->id,
            'hasta'       => $hasta->toDateString(),
            'tramos'      => [
                ['min' => 1, 'max' => 5, 'porcentaje' => 5],
                ['min' => 6, 'porcentaje' => min(12, $techo)],
            ],
        ]);

        $this->assertTrue($respuesta['ok'], 'La propuesta tenía que quedar armada: ' . json_encode($respuesta));
        $this->assertEquals('oferta', $respuesta['tipo']);
        $this->assertEquals($techo, $respuesta['techo_de_descuento']);
        $this->assertStringContainsString($client->name, $respuesta['resumen']);
        $this->assertStringContainsString('2 tramos', $respuesta['resumen']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals('propuesta', $tarjeta->estado);
        $this->assertEquals('oferta:' . $client->id . ':' . $article->id, $tarjeta->clave);

        $presentacion = $tarjeta->presentacion;

        $this->assertEquals('Oferta', $presentacion['titulo']);
        $this->assertEquals($client->name, $this->renglon($presentacion, 'Cliente'));
        $this->assertEquals($article->name, $this->renglon($presentacion, 'Artículo'));
        $this->assertEquals(
            ['1 a 5 unidades: 5%', '6 o más unidades: ' . min(12, $techo) . '%'],
            $this->renglones($presentacion, 'Descuento')
        );
        $this->assertStringContainsString($hasta->format('d/m/Y'), $this->renglon($presentacion, 'Hasta'));

        // 🔴 El aviso dice que desde el chat NO le sale ningún mensaje al cliente: la pantalla sí
        // se lo manda, y no decirlo haría que el dueño creyera que el cliente ya se enteró.
        $this->assertStringContainsString('no se le manda ningún mail ni WhatsApp', $presentacion['aviso']);

        $this->assertEquals('cantidad', $tarjeta->datos['tipo_descuento']);
        $this->assertNull($tarjeta->datos['porcentaje']);
        // El `max` del último tramo queda en null: es la convención que la tienda ya sabe leer.
        $this->assertNull($tarjeta->datos['tramos'][1]['max']);

        // Nada se activó todavía.
        $this->assertEquals(0, ClientOffer::where('client_id', $client->id)->where('article_id', $article->id)->count());
    }

    /**
     * @test
     */
    public function sin_cliente_y_sin_descuento_pregunta_las_dos_cosas_y_no_crea_tarjeta()
    {
        $article = $this->articulo_nuevo();

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, [
            'articulo_id' => $article->id,
            'hasta'       => Carbon::today()->addDays(10)->toDateString(),
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertEquals(['a qué cliente es la oferta', 'de cuánto es el descuento'], $respuesta['faltan']);
        $this->assertNull($respuesta['error']);
        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * 🔴 EL TECHO NO SE RECORTA EN SILENCIO. El dueño pide un número y tiene que enterarse de que
     * no se puede, no descubrir después que salió otro: recortar callado es exactamente cómo se
     * vende bajo costo sin que nadie lo note hasta el cierre del mes.
     *
     * @test
     */
    public function un_porcentaje_por_encima_del_techo_de_hoy_devuelve_error_y_no_crea_tarjeta()
    {
        $article = $this->articulo_nuevo();
        $client = $this->cliente();
        $techo = $this->techo_de($article, $client);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, [
            'cliente_id'  => $client->id,
            'articulo_id' => $article->id,
            'hasta'       => Carbon::today()->addDays(10)->toDateString(),
            'porcentaje'  => $techo + 1,
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertEquals('Con el costo y el precio de hoy, el descuento máximo de este artículo es ' . $techo . '%.', $respuesta['error']);
        $this->assertEquals($techo, $respuesta['opciones']['porcentaje_maximo']);
        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        // Y justo EN el techo sí entra: el error es del punto de más, no del techo.
        $en_el_techo = $this->proponer($conversation, $assistant, [
            'cliente_id'  => $client->id,
            'articulo_id' => $article->id,
            'hasta'       => Carbon::today()->addDays(10)->toDateString(),
            'porcentaje'  => $techo,
        ]);

        $this->assertTrue($en_el_techo['ok'], json_encode($en_el_techo));
    }

    /**
     * Un hueco (max 5 y el siguiente min 8) deja al comprador que lleva 6 unidades sin ningún
     * descuento aplicable, y la tienda no tiene cómo saber si eso fue a propósito.
     *
     * @test
     */
    public function tramos_con_un_hueco_devuelven_error_y_no_crean_tarjeta()
    {
        $article = $this->articulo_nuevo();
        $client = $this->cliente();

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, [
            'cliente_id'  => $client->id,
            'articulo_id' => $article->id,
            'hasta'       => Carbon::today()->addDays(10)->toDateString(),
            'tramos'      => [
                ['min' => 1, 'max' => 5, 'porcentaje' => 5],
                ['min' => 8, 'porcentaje' => 10],
            ],
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('contiguos', $respuesta['error']);
        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * 🔴 EL CAMINO COMPLETO, con el contrato de la tienda corrido TAL CUAL: confirmar deja la
     * promoción vigente con sus tramos, la query de `tienda-api` la ve, y NO le sale ningún mail al
     * cliente (es la única diferencia deliberada con la pantalla).
     *
     * @test
     */
    public function confirmar_activa_la_oferta_con_sus_tramos_y_sin_avisarle_al_cliente()
    {
        $article = $this->articulo_nuevo();
        $client = $this->cliente(['email' => 'zz-oferta-ia@test.local', 'phone' => '1126322965']);
        $techo = $this->techo_de($article, $client);
        $hasta = Carbon::today()->addDays(20);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, [
            'cliente_id'  => $client->id,
            'articulo_id' => $article->id,
            'hasta'       => $hasta->toDateString(),
            'tramos'      => [
                ['min' => 1, 'max' => 5, 'porcentaje' => 5],
                ['min' => 6, 'porcentaje' => min(12, $techo)],
            ],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $this->mensaje_listo($assistant);

        $confirmar = $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar');

        $confirmar->assertStatus(200);
        $this->assertEquals('confirmada', $confirmar->json('model.estado'));
        $this->assertEquals('ofertas', $confirmar->json('model.resultado.ruta.name'));
        $this->assertEquals('Ver en Promociones', $confirmar->json('model.resultado.ruta.texto'));
        $this->assertStringContainsString($client->name, $confirmar->json('model.resultado.texto'));

        // (1) 🔴 La query del contrato con la tienda, textual.
        $filas = $this->ofertas_vigentes_de($client->id, $article->id, 'co.id, co.tipo_descuento, co.porcentaje');

        $this->assertCount(1, $filas, 'la tienda tiene que ver la oferta que activó el asistente');
        $this->assertSame('cantidad', $filas[0]->tipo_descuento);
        // En 'cantidad' el porcentaje va NULL: el número vive en los tramos.
        $this->assertNull($filas[0]->porcentaje);

        $tramos = DB::select(
            'SELECT r.client_offer_id, r.min, r.max, r.porcentaje
             FROM client_offer_ranges r WHERE r.client_offer_id IN (?) ORDER BY r.min ASC',
            [$filas[0]->id]
        );

        $this->assertCount(2, $tramos);
        $this->assertEquals(1, $tramos[0]->min);
        $this->assertEquals(5, $tramos[0]->max);
        $this->assertNull($tramos[1]->max, 'el último tramo va sin techo, que es lo que la tienda sabe leer');

        // (2) La oferta no nació de ninguna corrida del motor: la trazabilidad queda en null.
        $offer = ClientOffer::find($filas[0]->id);
        $this->assertNull($offer->offer_suggestion_line_id);
        $this->assertSame($hasta->toDateString(), Carbon::parse($offer->hasta)->toDateString());

        // (3) 🔴 Y NO le salió nada al cliente, aunque tiene mail y teléfono cargados.
        Mail::assertNothingQueued();
        Mail::assertNotSent(ComercioCityMail::class);
        $this->assertNull($offer->notificada_email_at);
        $this->assertNull($offer->email_destino);
        $this->assertNull($offer->whatsapp_url);
    }

    /**
     * 🔴 EL INVARIANTE, DESDE EL CAMINO NUEVO: dos activaciones del mismo par no pueden dejar dos
     * ofertas activas. No hay unique en la base a propósito (una cancelada tiene que poder convivir
     * con una activa nueva), así que esto vive en el lockForUpdate de ClientOfertaAltaHelper y en
     * nada más. Si la tienda ve dos activas del mismo artículo, no tiene desempate.
     *
     * @test
     */
    public function dos_ofertas_del_mismo_par_dejan_una_sola_activa()
    {
        $article = $this->articulo_nuevo();
        $client = $this->cliente();

        foreach ([5, 8] as $porcentaje) {

            list($conversation, $assistant) = $this->conversacion();

            $respuesta = $this->proponer($conversation, $assistant, [
                'cliente_id'  => $client->id,
                'articulo_id' => $article->id,
                'hasta'       => Carbon::today()->addDays(10)->toDateString(),
                'porcentaje'  => $porcentaje,
            ]);

            $this->assertTrue($respuesta['ok'], json_encode($respuesta));

            $this->mensaje_listo($assistant);

            $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar')
                ->assertStatus(200);
        }

        $del_par = ClientOffer::where('user_id', 500)->where('client_id', $client->id)->where('article_id', $article->id);

        $this->assertSame(1, (clone $del_par)->where('estado', 'activa')->count(),
            'no puede quedar más de una oferta activa por (comercio, cliente, artículo)');
        // La anterior no se borra: queda como historial.
        $this->assertSame(1, (clone $del_par)->where('estado', 'cancelada')->count());
        // Y la que quedó viva es la última.
        $this->assertEquals(8, (int) (clone $del_par)->where('estado', 'activa')->first()->porcentaje);
        $this->assertCount(1, $this->ofertas_vigentes_de($client->id, $article->id));
    }

    /**
     * 🔴 El candado contra el segundo clic: la segunda confirmación avisa que la tarjeta ya se
     * resolvió y NO deja una segunda oferta.
     *
     * @test
     */
    public function confirmar_dos_veces_avisa_que_ya_se_resolvio_y_no_activa_dos_ofertas()
    {
        $article = $this->articulo_nuevo();
        $client = $this->cliente();

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, [
            'cliente_id'  => $client->id,
            'articulo_id' => $article->id,
            'hasta'       => Carbon::today()->addDays(10)->toDateString(),
            'porcentaje'  => 7,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $this->mensaje_listo($assistant);

        $ruta = 'api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar';

        $this->postJson($ruta)->assertStatus(200);

        $segunda = $this->postJson($ruta);

        $segunda->assertStatus(409);
        $this->assertEquals('accion_resuelta', $segunda->json('code'));
        $this->assertEquals('confirmada', $segunda->json('model.estado'));

        $offers = ClientOffer::where('user_id', 500)->where('client_id', $client->id)->where('article_id', $article->id)->get();

        $this->assertCount(1, $offers, 'el segundo clic no puede dejar dos filas en la tabla que lee la tienda');
        $this->assertEquals(7, (int) $offers[0]->porcentaje);
        // Es 'unidad': el porcentaje vive en la fila y no hay tramos que la tienda tenga que leer.
        $this->assertSame(0, ClientOfferRange::where('client_offer_id', $offers[0]->id)->count());
    }

    /**
     * Si ya hay una oferta activa de ese par, la tarjeta lo dice ANTES de que la persona confirme:
     * al confirmar, la vieja queda cancelada y eso no se puede descubrir después.
     *
     * @test
     */
    public function la_tarjeta_avisa_cuando_ya_hay_una_oferta_activa_de_ese_par()
    {
        $article = $this->articulo_nuevo();
        $client = $this->cliente();

        ClientOffer::create([
            'user_id'        => 500,
            'client_id'      => $client->id,
            'article_id'     => $article->id,
            'tipo_descuento' => 'unidad',
            'porcentaje'     => 5,
            'desde'          => Carbon::today()->toDateString(),
            'hasta'          => Carbon::today()->addDays(5)->toDateString(),
            'estado'         => 'activa',
        ]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, [
            'cliente_id'  => $client->id,
            'articulo_id' => $article->id,
            'hasta'       => Carbon::today()->addDays(10)->toDateString(),
            'porcentaje'  => 8,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $aviso = AiMessageAction::find($respuesta['tarjeta_id'])->presentacion['aviso'];

        $this->assertStringContainsString('Ya hay una oferta activa de este artículo para este cliente', $aviso);
    }

    /**
     * Sin la extensión `motor_de_ofertas` el API directamente no expone `POST client-offer`
     * (el grupo de rutas está gateado por check_extencion_empresa). El asistente espeja ese gate.
     *
     * @test
     */
    public function sin_la_extencion_del_motor_de_ofertas_la_herramienta_corta_con_el_motivo()
    {
        $article = $this->articulo_nuevo();
        $client = $this->cliente();

        $motor = ExtencionEmpresa::where('slug', 'motor_de_ofertas')->first();
        $this->dueno->extencions()->detach($motor->id);
        $this->dueno->load('extencions');

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer($conversation, $assistant, [
            'cliente_id'  => $client->id,
            'articulo_id' => $article->id,
            'hasta'       => Carbon::today()->addDays(10)->toDateString(),
            'porcentaje'  => 5,
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertEquals('Tu cuenta no tiene activado el módulo de Promociones.', $respuesta['error']);
        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }
}
