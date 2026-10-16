<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\asistente_ia\AvisosDelAsistenteHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\EjecutorAccionesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\EscaneoDeFacturaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaCompraConFacturaIaHelper;
use App\Http\Controllers\Helpers\providerOrder\ProviderOrderScanAltaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\Article;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderScan;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Misión asistente-aviso-escaneo-factura (16/10/2026) — el aviso por WhatsApp de que terminó el
 * escaneo de la factura, con el link a la revisión, y lo que lo rodea.
 *
 * Pedido de Lucas: «el agente personal debe dar de alta la compra o adjuntar la foto a una compra ya
 * cargada y avisarle al usuario por whatsapp cuando termine de escanearse para que revise el escaneo
 * desde el sistema, tambien quiero que le pase el link…».
 *
 * Lo que fija esta suite, en el orden del plan:
 *   1.a la compra que nombra el dueño (`compra_num`): con artículos se adjunta igual y se avisa;
 *       inexistente o con escaneo en curso, rechazo sin tarjeta; el proveedor de la compra manda.
 *   1.b/1.c el escaneo nace con su conversación, y pide el aviso solo por WhatsApp con un admin que
 *       anunció que avisa; el texto de la carga dice qué pasó y por dónde llega el aviso.
 *   1.d avisos-pendientes y avisos/avisado: el contrato con el admin, incluido el caso real de demo3.
 *   1.e consultar_escaneo_de_factura: listo, en proceso, error y sin escaneo.
 *
 * 🔴 Nada sale a la red: la cola está fakeada (el job del escaneo no corre) y el `resultado` del
 * escaneo se escribe a mano con la forma que deja EscaneoFacturaCompraService.
 */
class Aviso_del_escaneo_por_whatsapp_Test extends AsistenteWhatsappTestCase
{
    /** La base del SPA que manda el admin en estos tests. */
    const SPA_URL = 'https://ferreteria.comerciocity.com';

    /** @var \App\Models\Provider */
    protected $proveedor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension(self::SLUG_ASISTENTE);
        $this->dar_extension(self::SLUG_ESCANEO);

        Queue::fake();
        Storage::fake('local');

        // Sin SPA_URL en el .env: el link sale solo de lo que manda el admin.
        config(['services.mostrador.spa_url' => null]);

        $this->proveedor = $this->crear_proveedor('Ferretera del Sur');
    }

    /* ─────────────────────────────── escena ─────────────────────────────── */

    /**
     * Un proveedor como el de la pantalla, con sus cuentas corrientes (ver 6_Compra_con_factura_Test).
     *
     * @param  string  $nombre
     * @return \App\Models\Provider
     */
    protected function crear_proveedor($nombre)
    {
        $proveedor = Provider::create([
            'user_id' => $this->comercio->id,
            'name'    => $nombre,
        ]);

        CreditAccountHelper::crear_credit_accounts('provider', $proveedor->id, $this->comercio->id);

        return $proveedor;
    }

    /**
     * La conversación con la foto de la factura y el pedido del dueño, y el assistant que propone.
     *
     * @param  string  $pedido  Lo que escribió el dueño en el turno siguiente a la foto.
     * @return array  [AiConversation, AiMessage $assistant_pendiente]
     */
    protected function escena($pedido = 'Es de Ferretera del Sur')
    {
        $conversation = $this->conversacion_whatsapp();

        $del_dueno = $this->mensaje($conversation, 'user', 'listo', ['contenido' => '']);

        $this->guardar_foto($del_dueno);

        $this->mensaje($conversation, 'assistant', 'listo', ['contenido' => '¿De qué proveedor es?']);
        $this->mensaje($conversation, 'user', 'listo', ['contenido' => $pedido]);

        $generandose = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        return [$conversation, $generandose];
    }

    /**
     * Una foto sin gestionar colgada de un mensaje del dueño.
     *
     * @param  \App\Models\AiMessage  $mensaje
     * @return \App\Models\AiMessageImagen
     */
    protected function guardar_foto(AiMessage $mensaje)
    {
        $recurso = imagecreatetruecolor(40, 40);

        ob_start();
        imagepng($recurso);
        $binario = ob_get_clean();

        $path = 'asistente_imagenes/' . $this->comercio->id . '/' . $mensaje->id . '/1.webp';

        Storage::disk('local')->put($path, $binario);

        return AiMessageImagen::create([
            'ai_message_id' => $mensaje->id,
            'user_id'       => $this->comercio->id,
            'orden'         => 1,
            'path'          => $path,
            'mime'          => 'image/webp',
            'bytes'         => strlen($binario),
        ]);
    }

    /**
     * La herramienta, como la llama el loop.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $mensaje
     * @param  array  $input
     * @return array
     */
    protected function proponer($conversation, $mensaje, array $input = [])
    {
        return PropuestaCompraConFacturaIaHelper::proponer(
            ContextoDeCargaIa::de_la_conversacion($conversation),
            $mensaje,
            array_merge(['proveedor' => 'Ferretera del Sur'], $input)
        );
    }

    /**
     * Cierra el turno que propuso, contesta "sí" y confirma la tarjeta por texto (el camino de
     * WhatsApp). Devuelve lo que devuelve la confirmación.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $propuso
     * @param  int  $tarjeta_id
     * @return array
     */
    protected function confirmar($conversation, AiMessage $propuso, $tarjeta_id)
    {
        $propuso->estado = 'listo';
        $propuso->contenido = 'Te cargo la factura, ¿dale?';
        $propuso->save();

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Sí, dale']);

        $contestando = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        return ConfirmacionPorTextoIaHelper::confirmar($conversation, $contestando, $tarjeta_id);
    }

    /**
     * Una compra del comercio, con N artículos cargados.
     *
     * @param  int  $num
     * @param  int  $articulos
     * @param  \App\Models\Provider|null  $proveedor
     * @return \App\Models\ProviderOrder
     */
    protected function compra($num, $articulos = 0, $proveedor = null)
    {
        $proveedor = is_null($proveedor) ? $this->proveedor : $proveedor;

        $compra = ProviderOrder::create([
            'user_id'     => $this->comercio->id,
            'provider_id' => $proveedor->id,
            'num'         => $num,
        ]);

        for ($i = 1; $i <= $articulos; $i++) {

            $articulo = Article::create(['user_id' => $this->comercio->id, 'name' => 'Artículo ' . $num . '-' . $i]);

            $compra->articles()->attach($articulo->id, ['amount' => 1]);
        }

        return $compra;
    }

    /**
     * Una compra de OTRO comercio, con su propio proveedor.
     *
     * @param  \App\Models\User  $otro
     * @param  int  $num
     * @return \App\Models\ProviderOrder
     */
    protected function compra_de_otro($otro, $num)
    {
        $proveedor = Provider::create(['user_id' => $otro->id, 'name' => 'Proveedor ajeno']);

        return ProviderOrder::create(['user_id' => $otro->id, 'provider_id' => $proveedor->id, 'num' => $num]);
    }

    /**
     * Un renglón del resultado del escaneo, con la forma que deja EscaneoFacturaCompraService.
     *
     * @param  string  $nombre
     * @param  bool  $encontrado
     * @param  float  $cantidad
     * @param  float  $costo
     * @return array
     */
    protected function renglon($nombre, $encontrado, $cantidad, $costo)
    {
        return [
            'fila'             => 1,
            'pagina'           => 1,
            'bar_code'         => null,
            'codigo_proveedor' => null,
            'nombre'           => $nombre,
            'cantidad'         => $cantidad,
            'costo_unitario'   => $costo,
            'campos_dudosos'   => [],
            'match'            => $encontrado
                ? ['estado' => 'encontrado', 'article_id' => 1, 'criterio' => 'name', 'nombre_en_catalogo' => $nombre]
                : ['estado' => 'sin_match', 'article_id' => null, 'criterio' => null, 'nombre_en_catalogo' => null, 'candidatos' => []],
        ];
    }

    /**
     * El `resultado` del caso real de demo3 (16/10/2026): Ferretera del Sur, 4 renglones, 3
     * reconocidos, 1 sin match, total $294.582,56.
     *
     * @return array
     */
    protected function resultado_de_demo3()
    {
        return [
            'version'   => 1,
            'articulos' => [
                $this->renglon('Martillo galponero 500 g', true, 10, 8500),
                $this->renglon('Destornillador Phillips 6"', true, 20, 3200),
                $this->renglon('Cinta métrica 5 m', true, 12, 4100),
                $this->renglon('Llave francesa 10" cromada', false, 6, 12000),
            ],
            'factura'   => [
                'es_factura_afip'     => true,
                'tipo_comprobante'    => 'A',
                'punto_venta'         => '0003',
                'numero'              => '00001234',
                'emisor_razon_social' => 'Ferretera del Sur S.R.L.',
                'emisor_cuit'         => '30712345678',
                'neto_gravado'        => 243456.66,
                'total_iva'           => 51125.90,
                'total'               => 294582.56,
                'campos_dudosos'      => [],
            ],
            'avisos'    => [],
        ];
    }

    /**
     * Un escaneo terminado (o no) de una compra, tal como lo dejaría el job.
     *
     * @param  \App\Models\ProviderOrder  $compra
     * @param  array  $columnas
     * @return \App\Models\ProviderOrderScan
     */
    protected function escaneo(ProviderOrder $compra, array $columnas = [])
    {
        return ProviderOrderScan::create(array_merge([
            'uuid'                => Str::uuid()->toString(),
            'user_id'             => $this->comercio->id,
            'auth_user_id'        => $this->comercio->id,
            'provider_order_id'   => $compra->id,
            'estado'              => 'listo',
            'progreso'            => 100,
            'resultado'           => $this->resultado_de_demo3(),
            'avisar_por_whatsapp' => 1,
        ], $columnas));
    }

    /**
     * Le cambia el `updated_at` a una fila sin que Eloquent lo pise.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @param  \Carbon\Carbon  $cuando
     * @return void
     */
    protected function terminado_hace(ProviderOrderScan $scan, Carbon $cuando)
    {
        DB::table('provider_order_scans')->where('id', $scan->id)->update(['updated_at' => $cuando, 'created_at' => $cuando]);
    }

    /**
     * GET avisos-pendientes.
     *
     * @param  string  $query
     * @param  bool  $con_clave
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pendientes($query = '', $con_clave = true)
    {
        return $this->getJson('api/admin-sync/asistente/avisos-pendientes' . $query, $this->headers($con_clave));
    }

    /**
     * POST avisos/avisado.
     *
     * @param  array  $cuerpo
     * @return \Illuminate\Testing\TestResponse
     */
    protected function avisado(array $cuerpo)
    {
        return $this->postJson('api/admin-sync/asistente/avisos/avisado', $cuerpo, $this->headers());
    }

    /**
     * El renglón "Qué se hace" de una tarjeta.
     *
     * @param  int  $tarjeta_id
     * @return string
     */
    protected function que_se_hace($tarjeta_id)
    {
        foreach (AiMessageAction::find($tarjeta_id)->presentacion['renglones'] as $renglon) {
            if ($renglon['etiqueta'] === 'Qué se hace') {
                return (string) $renglon['valor'];
            }
        }

        return '';
    }

    /* ─────────────────────── 1.a la compra que nombra el dueño ─────────────────────── */

    /**
     * «Adjuntala a la compra 12», y la 12 ya tiene artículos: se usa igual, la tarjeta lo avisa y al
     * confirmar el escaneo va a ESA compra, sin crear otra.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_compra_num_adjunta_aunque_tenga_articulos_y_lo_avisa()
    {
        $cargada = $this->compra(12, 2);

        list($conversation, $generandose) = $this->escena('Adjuntala a la compra 12');

        $respuesta = $this->proponer($conversation, $generandose, ['compra_num' => 12]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertTrue($accion->datos['compra_indicada']);
        $this->assertSame((int) $cargada->id, (int) $accion->datos['reusar_provider_order']);
        $this->assertStringContainsString('Se adjunta la factura a la compra N° 12', $this->que_se_hace($accion->id));
        $this->assertStringContainsString('ya tiene 2 artículos', (string) $accion->presentacion['aviso']);
        $this->assertStringContainsString('elegís qué renglones sumar', (string) $accion->presentacion['aviso']);

        $resultado = $this->confirmar($conversation, $generandose, $respuesta['tarjeta_id']);

        $this->assertTrue($resultado['ok'], json_encode($resultado));

        $this->assertSame(1, ProviderOrder::where('user_id', $this->comercio->id)->count(), 'No se crea otra compra: la factura va a la 12.');
        $this->assertSame(1, ProviderOrderScan::where('provider_order_id', $cargada->id)->count());
        $this->assertSame(2, $cargada->articles()->count(), 'Los artículos que tenía quedan como estaban.');

        $this->assertStringContainsString('Adjunté la factura a la Compra N° 12', $resultado['resultado']);
        $this->assertStringContainsString('con 2 artículos', $resultado['resultado']);
    }

    /**
     * Una compra que no existe es un rechazo recuperable, sin tarjeta.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_compra_num_inexistente_rechaza_sin_tarjeta()
    {
        list($conversation, $generandose) = $this->escena('Adjuntala a la compra 99');

        $respuesta = $this->proponer($conversation, $generandose, ['compra_num' => 99]);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('No encontré ninguna compra N° 99', $respuesta['error']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * Una compra de OTRO comercio con ese número no se encuentra: el número se busca en el dueño.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_compra_num_de_otro_comercio_no_la_encuentra()
    {
        $otro = $this->otro_dueno();

        $this->compra_de_otro($otro, 12);

        list($conversation, $generandose) = $this->escena('Adjuntala a la compra 12');

        $respuesta = $this->proponer($conversation, $generandose, ['compra_num' => 12]);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('No encontré ninguna compra N° 12', $respuesta['error']);
    }

    /**
     * Con un escaneo en curso, el mismo corte que en el resto del sistema.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_compra_num_y_escaneo_en_curso_corta()
    {
        $compra = $this->compra(12);

        $this->escaneo($compra, ['estado' => 'procesando', 'resultado' => null, 'avisar_por_whatsapp' => 0]);

        list($conversation, $generandose) = $this->escena('Adjuntala a la compra 12');

        $respuesta = $this->proponer($conversation, $generandose, ['compra_num' => 12]);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('escaneo en curso', $respuesta['error']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * Si el dueño nombra otro proveedor que el de la compra, manda el de la compra y la tarjeta lo dice.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_compra_num_de_otro_proveedor_manda_el_de_la_compra()
    {
        $otro = $this->crear_proveedor('Bulonera Norte');

        $compra = $this->compra(12, 0, $otro);

        list($conversation, $generandose) = $this->escena('Es de Ferretera del Sur, adjuntala a la compra 12');

        $respuesta = $this->proponer($conversation, $generandose, ['compra_num' => 12]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame((int) $otro->id, (int) $accion->datos['provider_id']);
        $this->assertStringContainsString('es de Bulonera Norte', (string) $accion->presentacion['aviso']);

        $this->confirmar($conversation, $generandose, $respuesta['tarjeta_id']);

        $this->assertSame(1, ProviderOrderScan::where('provider_order_id', $compra->id)->count());
    }

    /* ───────────── 1.b / 1.c el escaneo nace sabiendo si avisar, y el texto lo dice ───────────── */

    /**
     * Por WhatsApp y con un admin que anunció que avisa: el escaneo nace con su conversación y con el
     * aviso pedido, y el texto promete el aviso por acá.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function por_whatsapp_con_admin_que_avisa_pide_el_aviso_y_lo_promete()
    {
        AvisosDelAsistenteHelper::anunciar($this->comercio->id, '1', self::SPA_URL);

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose);

        $resultado = $this->confirmar($conversation, $generandose, $respuesta['tarjeta_id']);

        $this->assertTrue($resultado['ok'], json_encode($resultado));

        $scan = ProviderOrderScan::where('user_id', $this->comercio->id)->orderBy('id', 'DESC')->first();

        $this->assertSame((int) $conversation->id, (int) $scan->ai_conversation_id);
        $this->assertTrue((bool) $scan->avisar_por_whatsapp);
        $this->assertNull($scan->avisado_por_whatsapp_at);

        $compra = ProviderOrder::find($scan->provider_order_id);

        $this->assertStringContainsString('Creé la Compra N° ' . $compra->num . ' de Ferretera del Sur', $resultado['resultado']);
        $this->assertStringContainsString('te aviso por acá con el link', $resultado['resultado']);
        $this->assertStringNotContainsString('no por WhatsApp', $resultado['resultado']);
    }

    /**
     * Con un admin viejo (sin anuncio) el escaneo guarda la conversación pero NO pide el aviso, y el
     * texto queda como antes: el aviso es en la pantalla.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_anuncio_del_admin_no_pide_el_aviso_y_el_texto_queda_como_antes()
    {
        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose);

        $resultado = $this->confirmar($conversation, $generandose, $respuesta['tarjeta_id']);

        $scan = ProviderOrderScan::where('user_id', $this->comercio->id)->orderBy('id', 'DESC')->first();

        $this->assertSame((int) $conversation->id, (int) $scan->ai_conversation_id);
        $this->assertFalse((bool) $scan->avisar_por_whatsapp);

        $this->assertStringContainsString('en el sistema', $resultado['resultado']);
        $this->assertStringContainsString('no por WhatsApp', $resultado['resultado']);
        $this->assertStringNotContainsString('te aviso por acá', $resultado['resultado']);
    }

    /**
     * Una tarjeta propuesta desde el canal del sistema no pide aviso por WhatsApp aunque el admin avise.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_tarjeta_que_no_vino_por_whatsapp_no_pide_el_aviso()
    {
        AvisosDelAsistenteHelper::anunciar($this->comercio->id, '1', self::SPA_URL);

        list($conversation, $generandose) = $this->escena();

        $generandose->canal = AiMessage::CANAL_SISTEMA;
        $generandose->save();

        $respuesta = $this->proponer($conversation, $generandose);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $generandose->estado = 'listo';
        $generandose->contenido = 'Te cargo la factura.';
        $generandose->save();

        /*
         * Una tarjeta del canal del sistema se confirma con el BOTÓN (la confirmación por texto la
         * rechaza a propósito): es el camino de EjecutorAccionesIaHelper::confirmar.
         */
        $this->actingAs($this->comercio);

        $confirmada = EjecutorAccionesIaHelper::confirmar($conversation, (int) $respuesta['tarjeta_id'], $this->comercio, function () {
            return 1;
        });

        $this->assertSame(200, (int) $confirmada['status'], json_encode($confirmada));

        $scan = ProviderOrderScan::where('user_id', $this->comercio->id)->orderBy('id', 'DESC')->first();

        $this->assertNotNull($scan);
        $this->assertFalse((bool) $scan->avisar_por_whatsapp);
        $this->assertStringContainsString('no por WhatsApp', json_encode($confirmada['body'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Reusar la compra vacía reciente (la regla automática) también se cuenta como «adjunté».
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_compra_vacia_reusada_se_cuenta_como_adjuntada()
    {
        $vacia = $this->compra(77);

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose);

        $resultado = $this->confirmar($conversation, $generandose, $respuesta['tarjeta_id']);

        $this->assertSame(1, ProviderOrderScan::where('provider_order_id', $vacia->id)->count());
        $this->assertStringContainsString('Adjunté la factura a la Compra N° 77', $resultado['resultado']);
        $this->assertStringContainsString('sin artículos', $resultado['resultado']);
    }

    /**
     * Por la pantalla (sin `$origen`) el escaneo nace como siempre: sin conversación ni aviso.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function por_la_pantalla_el_escaneo_nace_sin_conversacion_ni_aviso()
    {
        $compra = $this->compra(5);

        $recurso = imagecreatetruecolor(40, 40);
        ob_start();
        imagepng($recurso);
        $binario = ob_get_clean();

        $respuesta = ProviderOrderScanAltaHelper::crear($compra, [['binario' => $binario, 'nombre_original' => 'f.png']], $this->comercio);

        $this->assertSame(202, $respuesta['status'], json_encode($respuesta));

        $scan = ProviderOrderScan::where('uuid', $respuesta['body']['uuid'])->first();

        $this->assertNull($scan->ai_conversation_id);
        $this->assertFalse((bool) $scan->avisar_por_whatsapp);
    }

    /**
     * El POST de mensajes guarda el anuncio del admin, y un mensaje sin los campos (admin viejo) lo borra.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_post_de_mensajes_guarda_y_borra_el_anuncio_del_admin()
    {
        $this->mandar(['avisos' => '1', 'spa_url' => self::SPA_URL . '/'])->assertStatus(202);

        $this->assertTrue(AvisosDelAsistenteHelper::admin_avisa($this->comercio->id));
        $this->assertSame(self::SPA_URL, AvisosDelAsistenteHelper::base_del_link($this->comercio->id));

        $this->mandar(['whatsapp_message_id' => 'wamid.otro'])->assertStatus(202);

        $this->assertFalse(AvisosDelAsistenteHelper::admin_avisa($this->comercio->id));
        $this->assertNull(AvisosDelAsistenteHelper::base_del_link($this->comercio->id));
    }

    /**
     * Una spa_url que no es http/https con host no se guarda.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_spa_url_invalida_no_se_guarda()
    {
        $this->mandar(['avisos' => '1', 'spa_url' => 'javascript:alert(1)'])->assertStatus(202);

        $this->assertTrue(AvisosDelAsistenteHelper::admin_avisa($this->comercio->id));
        $this->assertNull(AvisosDelAsistenteHelper::base_del_link($this->comercio->id));
    }

    /* ─────────────────────────── 1.d avisos-pendientes ─────────────────────────── */

    /**
     * Sin la clave del admin, 401.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function avisos_pendientes_sin_clave_da_401()
    {
        $this->pendientes('', false)->assertStatus(401);
    }

    /**
     * El caso real de demo3: Ferretera del Sur, 4 renglones, 3 reconocidos, 1 sin match, total
     * $294.582,56. El contrato completo, con la ruta y la url armada con la spa_url de la query.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function avisos_pendientes_devuelve_el_escaneo_listo_de_demo3()
    {
        $conversation = $this->conversacion_whatsapp();

        $compra = $this->compra(12);

        $scan = $this->escaneo($compra, ['ai_conversation_id' => $conversation->id]);

        $respuesta = $this->pendientes('?spa_url=' . urlencode(self::SPA_URL));

        $respuesta->assertStatus(200);

        $avisos = $respuesta->json('avisos');

        $this->assertCount(1, $avisos);

        $ruta = '/proveedores/compras?compra=' . $compra->id . '&escaneo=' . $scan->uuid;

        $this->assertSame([
            'id'                 => 'escaneo_factura:' . $scan->id,
            'tipo'               => 'escaneo_factura',
            'ai_conversation_id' => (int) $conversation->id,
            'texto'              => $avisos[0]['texto'],
            'ruta'               => $ruta,
            'url'                => self::SPA_URL . $ruta,
        ], $avisos[0]);

        $texto = $avisos[0]['texto'];

        $this->assertStringContainsString('*Ferretera del Sur*', $texto);
        $this->assertStringContainsString('compra N° 12', $texto);
        $this->assertStringContainsString('Factura A 0003-00001234', $texto);
        $this->assertStringContainsString('$294.582,56', $texto);
        $this->assertStringContainsString('Trae 4 renglones: 3 reconocidos y 1 sin match', $texto);
        $this->assertStringContainsString('para dar de alta o asociar', $texto);
        $this->assertStringNotContainsString('http', $texto, 'El link lo agrega el admin, no viaja en el texto.');
        $this->assertStringNotContainsString('en la factura figura', $texto, '«Ferretera del Sur S.R.L.» es el mismo proveedor.');
    }

    /**
     * Sin spa_url en la query se usa la que anunció el admin; sin ninguna, `url` es null y la ruta viaja igual.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_url_sale_de_la_query_del_anuncio_o_es_null()
    {
        $scan = $this->escaneo($this->compra(12));

        $sin_base = $this->pendientes()->json('avisos')[0];

        $this->assertNull($sin_base['url']);
        $this->assertSame(EscaneoDeFacturaIaHelper::ruta_de_revision($scan), $sin_base['ruta']);

        AvisosDelAsistenteHelper::anunciar($this->comercio->id, '1', 'https://anunciada.comerciocity.com');

        $this->assertSame('https://anunciada.comerciocity.com' . $sin_base['ruta'], $this->pendientes()->json('avisos')[0]['url']);

        $this->assertSame(
            self::SPA_URL . $sin_base['ruta'],
            $this->pendientes('?spa_url=' . urlencode(self::SPA_URL))->json('avisos')[0]['url'],
            'La de la query gana.'
        );
    }

    /**
     * Un escaneo con error se avisa con su motivo.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function avisos_pendientes_devuelve_el_error_con_su_motivo()
    {
        $this->escaneo($this->compra(12), ['estado' => 'error', 'resultado' => null, 'error' => 'La foto está borrosa.']);

        $avisos = $this->pendientes()->json('avisos');

        $this->assertCount(1, $avisos);
        $this->assertSame(
            'No pude leer la factura de *Ferretera del Sur* (compra N° 12): La foto está borrosa. Podés volver a escanearla desde la compra.',
            $avisos[0]['texto']
        );
    }

    /**
     * Lo que NO se avisa: sin aviso pedido (pantalla o admin viejo), ya avisado, en curso, de hace
     * más de 24 h, ya gestionado y de otro dueño.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function avisos_pendientes_no_devuelve_lo_que_no_corresponde()
    {
        $compra = $this->compra(12);

        $this->escaneo($compra, ['avisar_por_whatsapp' => 0]);
        $this->escaneo($compra, ['avisado_por_whatsapp_at' => now()]);
        $this->escaneo($compra, ['estado' => 'procesando', 'resultado' => null]);
        $this->escaneo($compra, ['gestionado_at' => now(), 'resultado_gestion' => 'confirmado']);

        $viejo = $this->escaneo($compra);
        $this->terminado_hace($viejo, now()->subHours(25));

        $otro = $this->otro_dueno();
        $de_otro = $this->compra_de_otro($otro, 1);
        ProviderOrderScan::create([
            'uuid'                => Str::uuid()->toString(),
            'user_id'             => $otro->id,
            'provider_order_id'   => $de_otro->id,
            'estado'              => 'listo',
            'resultado'           => $this->resultado_de_demo3(),
            'avisar_por_whatsapp' => 1,
        ]);

        $this->pendientes()->assertStatus(200)->assertExactJson(['avisos' => []]);
    }

    /**
     * Del más viejo al más nuevo.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function avisos_pendientes_van_del_mas_viejo_al_mas_nuevo()
    {
        $nuevo = $this->escaneo($this->compra(13));
        $viejo = $this->escaneo($this->compra(12));
        $this->terminado_hace($viejo, now()->subHours(2));

        $ids = array_column($this->pendientes()->json('avisos'), 'id');

        $this->assertSame(['escaneo_factura:' . $viejo->id, 'escaneo_factura:' . $nuevo->id], $ids);
    }

    /* ─────────────────────────── 1.d avisos/avisado ─────────────────────────── */

    /**
     * Sella el aviso y deja UN mensaje del asistente en la conversación con el texto y el link; una
     * segunda llamada no duplica nada.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function avisado_sella_y_deja_un_solo_mensaje_en_la_conversacion()
    {
        $conversation = $this->conversacion_whatsapp(now()->subHours(3));

        $scan = $this->escaneo($this->compra(12), ['ai_conversation_id' => $conversation->id]);

        $url = self::SPA_URL . EscaneoDeFacturaIaHelper::ruta_de_revision($scan);

        $texto = AvisosDelAsistenteHelper::texto_del_aviso($scan);

        $this->avisado(['id' => 'escaneo_factura:' . $scan->id, 'url' => $url, 'whatsapp_message_id' => 'wamid.aviso'])
            ->assertStatus(200)
            ->assertExactJson(['ok' => true]);

        $this->avisado(['id' => 'escaneo_factura:' . $scan->id, 'url' => $url])->assertStatus(200);

        $this->assertNotNull($scan->fresh()->avisado_por_whatsapp_at);

        $mensajes = AiMessage::where('ai_conversation_id', $conversation->id)->get();

        $this->assertCount(1, $mensajes, 'Una sola vez aunque el admin llame dos veces.');
        $this->assertSame('assistant', $mensajes[0]->rol);
        $this->assertSame(AiMessage::CANAL_WHATSAPP, $mensajes[0]->canal);
        $this->assertSame('listo', $mensajes[0]->estado);
        $this->assertSame($texto . "\n\n" . $url, $mensajes[0]->contenido);
        $this->assertSame('wamid.aviso', $mensajes[0]->whatsapp_message_id);

        $this->assertTrue(
            Carbon::parse($conversation->fresh()->last_message_at)->gt(now()->subMinute()),
            'La conversación queda como la más reciente: un «dale, ¿qué trae?» sin cita cae acá.'
        );

        $this->assertSame([], $this->pendientes()->json('avisos'), 'Ya avisado, no vuelve a salir.');
    }

    /**
     * Un id desconocido, mal formado o de otro dueño es 404.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function avisado_de_un_id_ajeno_o_desconocido_da_404()
    {
        $otro = $this->otro_dueno();
        $de_otro = $this->compra_de_otro($otro, 1);
        $ajeno = ProviderOrderScan::create([
            'uuid'                => Str::uuid()->toString(),
            'user_id'             => $otro->id,
            'provider_order_id'   => $de_otro->id,
            'estado'              => 'listo',
            'avisar_por_whatsapp' => 1,
        ]);

        $this->avisado(['id' => 'escaneo_factura:' . $ajeno->id])->assertStatus(404);
        $this->avisado(['id' => 'escaneo_factura:999999999'])->assertStatus(404);
        $this->avisado(['id' => 'informe:1'])->assertStatus(404);
        $this->avisado([])->assertStatus(404);

        $this->assertNull($ajeno->fresh()->avisado_por_whatsapp_at);
    }

    /**
     * Sin la clave, 401 (no se sella nada).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function avisado_sin_clave_da_401()
    {
        $scan = $this->escaneo($this->compra(12));

        $this->postJson('api/admin-sync/asistente/avisos/avisado', ['id' => 'escaneo_factura:' . $scan->id], $this->headers(false))
            ->assertStatus(401);

        $this->assertNull($scan->fresh()->avisado_por_whatsapp_at);
    }

    /* ─────────────────────── 1.e consultar_escaneo_de_factura ─────────────────────── */

    /**
     * Listo: totales, conteos, renglones y el link.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function consultar_un_escaneo_listo()
    {
        AvisosDelAsistenteHelper::anunciar($this->comercio->id, '1', self::SPA_URL);

        $conversation = $this->conversacion_whatsapp();

        $scan = $this->escaneo($this->compra(12), ['ai_conversation_id' => $conversation->id]);

        $datos = EscaneoDeFacturaIaHelper::consultar($this->comercio->id, $conversation, null);

        $this->assertTrue($datos['ok']);
        $this->assertSame('listo', $datos['estado']);
        $this->assertSame(12, $datos['compra_num']);
        $this->assertSame('Ferretera del Sur', $datos['proveedor']);
        $this->assertSame('Factura A 0003-00001234', $datos['comprobante']);
        $this->assertEquals(294582.56, $datos['total']);
        $this->assertEquals(243456.66, $datos['neto_gravado']);
        $this->assertEquals(51125.90, $datos['total_iva']);
        $this->assertSame(4, $datos['renglones']);
        $this->assertSame(3, $datos['reconocidos']);
        $this->assertSame(1, $datos['sin_match']);
        $this->assertCount(4, $datos['articulos']);
        $this->assertFalse($datos['articulos'][3]['reconocido']);
        $this->assertSame('Llave francesa 10" cromada', $datos['articulos'][3]['nombre_leido']);
        $this->assertSame(self::SPA_URL . EscaneoDeFacturaIaHelper::ruta_de_revision($scan), $datos['url']);

        // Por número de compra, igual.
        $por_numero = EscaneoDeFacturaIaHelper::consultar($this->comercio->id, $conversation, 12);

        $this->assertSame('listo', $por_numero['estado']);
    }

    /**
     * En proceso: lo dice, sin renglones.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function consultar_un_escaneo_en_proceso()
    {
        $conversation = $this->conversacion_whatsapp();

        $this->escaneo($this->compra(12), ['estado' => 'procesando', 'resultado' => null, 'ai_conversation_id' => $conversation->id]);

        $datos = EscaneoDeFacturaIaHelper::consultar($this->comercio->id, $conversation, null);

        $this->assertSame('en_proceso', $datos['estado']);
        $this->assertStringContainsString('todavía se está leyendo', $datos['mensaje']);
        $this->assertArrayNotHasKey('articulos', $datos);
        $this->assertNull($datos['url'], 'Sin base del SPA no hay link.');
    }

    /**
     * Error: el motivo.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function consultar_un_escaneo_con_error()
    {
        $conversation = $this->conversacion_whatsapp();

        $this->escaneo($this->compra(12), ['estado' => 'error', 'resultado' => null, 'error' => 'La foto está borrosa.', 'ai_conversation_id' => $conversation->id]);

        $datos = EscaneoDeFacturaIaHelper::consultar($this->comercio->id, $conversation, null);

        $this->assertSame('error', $datos['estado']);
        $this->assertSame('La foto está borrosa', $datos['error']);
    }

    /**
     * Sin escaneo: lo dice; y una compra sin escaneo, también.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function consultar_sin_escaneo()
    {
        $conversation = $this->conversacion_whatsapp();

        $datos = EscaneoDeFacturaIaHelper::consultar($this->comercio->id, $conversation, null);

        $this->assertTrue($datos['ok']);
        $this->assertSame('sin_escaneo', $datos['estado']);

        $this->compra(12);

        $de_la_compra = EscaneoDeFacturaIaHelper::consultar($this->comercio->id, $conversation, 12);

        $this->assertSame('sin_escaneo', $de_la_compra['estado']);
        $this->assertStringContainsString('compra N° 12', $de_la_compra['mensaje']);

        $inexistente = EscaneoDeFacturaIaHelper::consultar($this->comercio->id, $conversation, 99);

        $this->assertFalse($inexistente['ok']);
    }

    /**
     * La herramienta está en el registro de lectura, y por lo tanto en todos los canales.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_herramienta_esta_declarada_en_la_lectura()
    {
        $this->assertContains('consultar_escaneo_de_factura', (new AsistenteIaService())->nombres_de_lectura());
    }
}
