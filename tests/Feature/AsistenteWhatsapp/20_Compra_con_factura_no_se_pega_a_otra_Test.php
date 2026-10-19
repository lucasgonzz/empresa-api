<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaCompraConFacturaIaHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\Article;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderScan;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Misión asistente-arreglos-segunda-prueba (19/10/2026), N4 — la factura nueva que quedó pegada a una
 * compra vieja.
 *
 * Lo que pasó en la segunda prueba real contra demo3 (`_video/agente-personal/registro-19-10.md`, B1):
 * el dueño mandó la foto de la Factura A 0003-00012398 de hoy y el asistente la adjuntó a la Compra
 * 13 del 16/10, que ya tenía escaneada OTRA factura (la 0003-00012345). La compra 13 no tenía
 * artículos porque el escaneo estaba `listo` sin revisar, y la regla automática de entonces solo
 * miraba «sin artículos, de los últimos 7 días y sin un escaneo EN CURSO».
 *
 * Lo que fija este archivo (la regla nueva de PropuestaCompraConFacturaIaHelper::es_reusable_sin_nombrarla):
 *   - Se reusa en silencio SOLO una compra del mismo proveedor, de HOY, sin artículos, sin NINGÚN
 *     escaneo (en cualquier estado) y sin factura cargada a mano.
 *   - Una compra vacía y limpia de 1 a 7 días atrás no se reusa ni se ignora: se PREGUNTA antes de
 *     crear nada (`faltan` con la compra), y la foto sigue disponible en el turno siguiente para
 *     «nueva» (`compra_nueva: true`) o «sumala a la 12» (`compra_num`).
 *   - La confirmación revalida con el mismo método: si la candidata dejó de servir entre la tarjeta y
 *     el sí, la factura va a una compra nueva y el resultado lo dice.
 *   - `compra_num` sigue adjuntando aunque la compra ya tenga otro escaneo, y lo avisa.
 *
 * 🔴 Nada sale a la red: la cola está fakeada (el job del escaneo no corre) y el disco también.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Compra_con_factura_no_se_pega_a_otra_Test extends AsistenteWhatsappTestCase
{
    /** @var \App\Models\Provider */
    protected $proveedor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension(self::SLUG_ASISTENTE);
        $this->dar_extension(self::SLUG_ESCANEO);

        Queue::fake();
        Storage::fake('local');

        $this->proveedor = $this->crear_proveedor('Ferretera del Sur');
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    /**
     * Un proveedor con sus cuentas corrientes, como lo deja la pantalla (ver el mismo helper en
     * 6_Compra_con_factura_Test: sin las cuentas, el alta de la compra revienta).
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
     * Una compra del proveedor, creada hace `$dias` días (0 = hoy), opcionalmente con artículos.
     *
     * @param  int  $num
     * @param  int  $dias
     * @param  int  $articulos
     * @return \App\Models\ProviderOrder
     */
    protected function compra($num, $dias = 0, $articulos = 0)
    {
        $compra = ProviderOrder::create([
            'user_id'     => $this->comercio->id,
            'provider_id' => $this->proveedor->id,
            'num'         => $num,
        ]);

        if ($dias > 0) {
            $this->mover_al_pasado($compra, $dias);
        }

        for ($i = 1; $i <= $articulos; $i++) {
            $articulo = Article::create(['user_id' => $this->comercio->id, 'name' => 'Artículo P19 ' . $num . '-' . $i]);
            $compra->articles()->attach($articulo->id, ['amount' => 1]);
        }

        return $compra->fresh();
    }

    /**
     * Le cambia el `created_at` a la compra sin que Eloquent lo pise: N días atrás, a la misma hora.
     *
     * @param  \App\Models\ProviderOrder  $compra
     * @param  int  $dias
     * @return void
     */
    protected function mover_al_pasado(ProviderOrder $compra, $dias)
    {
        DB::table('provider_orders')->where('id', $compra->id)->update(['created_at' => Carbon::now()->subDays($dias)]);
    }

    /**
     * Un escaneo de la compra con la factura que el escaneo leyó (la 0003-00012345 de la compra 13
     * de demo3, por defecto), en el estado que se pida.
     *
     * @param  \App\Models\ProviderOrder  $compra
     * @param  string  $estado
     * @param  string  $numero
     * @return \App\Models\ProviderOrderScan
     */
    protected function escaneo(ProviderOrder $compra, $estado = 'listo', $numero = '00012345')
    {
        return ProviderOrderScan::create([
            'uuid'                => Str::uuid()->toString(),
            'user_id'             => $this->comercio->id,
            'auth_user_id'        => $this->comercio->id,
            'provider_order_id'   => $compra->id,
            'estado'              => $estado,
            'progreso'            => $estado === 'listo' ? 100 : 10,
            'resultado'           => $estado === 'listo' ? [
                'version'   => 1,
                'articulos' => [],
                'factura'   => ['es_factura_afip' => true, 'tipo_comprobante' => 'A', 'punto_venta' => '0003', 'numero' => $numero],
                'avisos'    => [],
            ] : null,
            'avisar_por_whatsapp' => 0,
        ]);
    }

    /**
     * Una foto de factura sin gestionar colgada de un mensaje del dueño.
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
     * El mensaje real de B1: la foto de la factura con su texto, y el assistant que propone.
     *
     * @return array{0: \App\Models\AiConversation, 1: \App\Models\AiMessage, 2: \App\Models\AiMessageImagen}
     */
    protected function escena()
    {
        $conversation = $this->conversacion_whatsapp();

        $del_dueno = $this->mensaje($conversation, 'user', 'listo', [
            'contenido' => 'te paso la factura de ferretera del sur q me llego hoy, cargala',
        ]);

        $foto = $this->guardar_foto($del_dueno);

        $generandose = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        return [$conversation, $generandose, $foto];
    }

    /**
     * La herramienta, llamada directo al helper (sin la puerta de la auto-confirmación).
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
     * Cierra el turno del assistant con el texto dado, el dueño contesta y devuelve el assistant del
     * turno siguiente (ver el 🔴 de cerrar_turno en 6_Compra_con_factura_Test: una tarjeta de un
     * mensaje 'pendiente' no se puede confirmar).
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $propuso
     * @param  string  $dijo_el_asistente
     * @param  string  $contesta_el_dueno
     * @return \App\Models\AiMessage
     */
    protected function siguiente_turno($conversation, AiMessage $propuso, $dijo_el_asistente, $contesta_el_dueno)
    {
        $propuso->estado = 'listo';
        $propuso->contenido = $dijo_el_asistente;
        $propuso->save();

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => $contesta_el_dueno]);

        return $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);
    }

    /**
     * Propone, el dueño dice «sí» y se confirma por texto (el camino de WhatsApp).
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $propuso
     * @param  int  $tarjeta_id
     * @return array
     */
    protected function confirmar($conversation, AiMessage $propuso, $tarjeta_id)
    {
        $contestando = $this->siguiente_turno($conversation, $propuso, 'Te cargo la factura, ¿dale?', 'Sí, dale');

        return ConfirmacionPorTextoIaHelper::confirmar($conversation, $contestando, $tarjeta_id);
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

    /**
     * Cuántas compras tiene el proveedor.
     *
     * @return int
     */
    protected function compras_del_proveedor()
    {
        return ProviderOrder::where('user_id', $this->comercio->id)->where('provider_id', $this->proveedor->id)->count();
    }

    // =====================================================================
    // 1. La regla de la propuesta
    // =====================================================================

    /**
     * 🔴 EL CASO REAL DEL 19/10 (B1). La Compra 13 del 16/10 tiene su escaneo `listo` sin revisar (la
     * 0003-00012345) y ningún artículo. Antes era «reusable» y la factura de hoy se le pegaba; ahora
     * es una compra nueva, sin preguntar nada (un escaneo cualquiera la descarta, y no es el caso
     * dudoso porque no está limpia).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_compra_del_16_10_con_su_escaneo_sin_revisar_no_recibe_la_factura_de_hoy()
    {
        $compra_13 = $this->compra(13, 3);

        $this->escaneo($compra_13, 'listo', '00012345');

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame('Se crea una compra nueva', $this->que_se_hace($respuesta['tarjeta_id']));
        $this->assertNull(AiMessageAction::find($respuesta['tarjeta_id'])->datos['reusar_provider_order']);

        $resultado = $this->confirmar($conversation, $generandose, $respuesta['tarjeta_id']);

        $this->assertTrue($resultado['ok'], json_encode($resultado));
        $this->assertSame(2, $this->compras_del_proveedor(), 'La factura de hoy va a una compra nueva.');
        $this->assertSame(1, ProviderOrderScan::where('provider_order_id', $compra_13->id)->count(), 'La compra 13 se queda con su única factura.');
        $this->assertStringContainsString('Creé la Compra N°', $resultado['resultado']);
    }

    /**
     * Una compra de HOY con un escaneo `listo` sin revisar tampoco se reusa: tiene otra factura.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_compra_de_hoy_con_un_escaneo_listo_sin_revisar_no_se_reusa()
    {
        $hoy = $this->compra(40);

        $this->escaneo($hoy, 'listo', '00012001');

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame('Se crea una compra nueva', $this->que_se_hace($respuesta['tarjeta_id']));

        // Y uno con error, o uno viejo abandonado: también cuenta como «ya tuvo una factura».
        ProviderOrderScan::where('provider_order_id', $hoy->id)->update(['estado' => 'error']);

        list($conversation, $generandose) = $this->escena();

        $otra = $this->proponer($conversation, $generandose);

        $this->assertSame('Se crea una compra nueva', $this->que_se_hace($otra['tarjeta_id']));
    }

    /**
     * La que sí se reusa en silencio: vacía, de hoy, sin escaneo ni factura. Es la compra que el dueño
     * armó en la pantalla y a la que le manda la factura.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_compra_vacia_de_hoy_sin_escaneo_se_reusa()
    {
        $hoy = $this->compra(41);

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame('Se usa la compra N° 41, que está vacía', $this->que_se_hace($respuesta['tarjeta_id']));

        $resultado = $this->confirmar($conversation, $generandose, $respuesta['tarjeta_id']);

        $this->assertTrue($resultado['ok'], json_encode($resultado));
        $this->assertSame(1, $this->compras_del_proveedor());
        $this->assertSame(1, ProviderOrderScan::where('provider_order_id', $hoy->id)->count());
        $this->assertStringContainsString('Adjunté la factura a la Compra N° 41', $resultado['resultado']);
    }

    /**
     * Una compra de hoy con una factura cargada A MANO (en la pantalla de la compra) no se reusa: ni
     * con su fila en `provider_order_afip_tickets` ni con la fecha del comprobante cargada.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_compra_de_hoy_con_factura_cargada_a_mano_no_se_reusa()
    {
        $con_ticket = $this->compra(42);

        ProviderOrderAfipTicket::create([
            'provider_order_id' => $con_ticket->id,
            'user_id'           => $this->comercio->id,
            'code'              => '0003-00011111',
            'issued_at'         => Carbon::now(),
            'total'             => 1000,
        ]);

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose);

        $this->assertSame('Se crea una compra nueva', $this->que_se_hace($respuesta['tarjeta_id']));

        ProviderOrderAfipTicket::where('provider_order_id', $con_ticket->id)->delete();

        DB::table('provider_orders')->where('id', $con_ticket->id)->update(['fecha_emision_comprobante' => Carbon::now()]);

        list($conversation, $generandose) = $this->escena();

        $otra = $this->proponer($conversation, $generandose);

        $this->assertSame('Se crea una compra nueva', $this->que_se_hace($otra['tarjeta_id']));
    }

    // =====================================================================
    // 2. El caso dudoso: se pregunta antes de crear nada
    // =====================================================================

    /**
     * Una compra vacía y limpia de hace 3 días: no se reusa en silencio ni se ignora. La herramienta
     * devuelve `faltan` con esa compra (número y fecha) y no deja tarjeta ni sella la foto.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_compra_vacia_de_hace_tres_dias_pregunta_sin_crear_nada()
    {
        $vieja = $this->compra(12, 3);

        list($conversation, $generandose, $foto) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose);

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertCount(1, $respuesta['faltan']);
        $this->assertStringContainsString('Compra N° 12', $respuesta['faltan'][0]);
        $this->assertStringContainsString(Carbon::parse($vieja->created_at)->format('d/m'), $respuesta['faltan'][0]);
        $this->assertSame(12, $respuesta['opciones']['compra_vacia']['compra_num']);
        $this->assertStringContainsString('compra_nueva', $respuesta['opciones']['como_seguir']);
        $this->assertStringContainsString('compra_num', $respuesta['opciones']['como_seguir']);

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count(), 'Se pregunta ANTES de crear la tarjeta.');
        $this->assertNull(AiMessageImagen::find($foto->id)->gestionada_at, 'La pregunta no sella la foto.');
        $this->assertSame(1, $this->compras_del_proveedor());
    }

    /**
     * ⚠️ LA VERIFICACIÓN QUE PIDIÓ EL PLAN ANTES DE PREGUNTAR: en el turno siguiente («nueva», sin
     * foto) la foto del mensaje anterior del dueño se sigue encontrando (la última tanda de
     * FotosDeLaConversacionIaHelper saltea los mensajes del dueño sin fotos del final), y con
     * `compra_nueva: true` sale la tarjeta de la compra nueva con ESA foto, sin volver a preguntar.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function nueva_en_el_turno_siguiente_arma_la_compra_nueva_con_la_misma_foto()
    {
        $vieja = $this->compra(12, 3);

        list($conversation, $generandose, $foto) = $this->escena();

        $pregunta = $this->proponer($conversation, $generandose);

        $this->assertFalse($pregunta['ok']);

        $otro_turno = $this->siguiente_turno(
            $conversation,
            $generandose,
            '¿La sumo a la Compra N° 12 del ' . Carbon::parse($vieja->created_at)->format('d/m') . ', que está vacía, o armo una compra nueva?',
            'nueva'
        );

        $respuesta = $this->proponer($conversation, $otro_turno, ['compra_nueva' => true]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame('Se crea una compra nueva', $this->que_se_hace($tarjeta->id));
        $this->assertSame([(int) $foto->id], array_map('intval', $tarjeta->datos['imagen_ids']), 'La foto del mensaje anterior sigue disponible.');

        $resultado = $this->confirmar($conversation, $otro_turno, $tarjeta->id);

        $this->assertTrue($resultado['ok'], json_encode($resultado));
        $this->assertSame(2, $this->compras_del_proveedor());
        $this->assertSame(0, ProviderOrderScan::where('provider_order_id', $vieja->id)->count(), 'La compra vacía de hace 3 días queda como estaba.');
        $this->assertNotNull(AiMessageImagen::find($foto->id)->gestionada_at);
    }

    /**
     * La otra respuesta: «sumala a la 12» → compra_num 12, y la factura va a esa compra con la foto
     * del mensaje anterior.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sumala_a_la_12_en_el_turno_siguiente_la_adjunta_con_la_misma_foto()
    {
        $vieja = $this->compra(12, 3);

        list($conversation, $generandose, $foto) = $this->escena();

        $this->proponer($conversation, $generandose);

        $otro_turno = $this->siguiente_turno($conversation, $generandose, '¿La sumo a la Compra N° 12 o armo una nueva?', 'sumala a la 12');

        $respuesta = $this->proponer($conversation, $otro_turno, ['compra_num' => 12]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame([(int) $foto->id], array_map('intval', AiMessageAction::find($respuesta['tarjeta_id'])->datos['imagen_ids']));

        $resultado = $this->confirmar($conversation, $otro_turno, $respuesta['tarjeta_id']);

        $this->assertTrue($resultado['ok'], json_encode($resultado));
        $this->assertSame(1, $this->compras_del_proveedor());
        $this->assertSame(1, ProviderOrderScan::where('provider_order_id', $vieja->id)->count());
        $this->assertStringContainsString('Adjunté la factura a la Compra N° 12', $resultado['resultado']);
    }

    /**
     * En "resuelto" la compra con factura se ejecuta sola: la pregunta tiene que salir ANTES, sin
     * compra ni escaneo creados. Por el despacho real de la herramienta.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function en_resuelto_la_pregunta_sale_antes_de_ejecutar_nada()
    {
        User::where('id', $this->comercio->id)->update(['agente_confianza' => 'resuelto']);

        $vieja = $this->compra(12, 2);

        list($conversation, $generandose) = $this->escena();

        $resultado = HerramientasDeCarga::ejecutar('proponer_compra_con_factura', ['proveedor' => 'Ferretera del Sur'], $conversation, $generandose);

        $this->assertFalse($resultado['is_error'], $resultado['content']);

        $respuesta = json_decode($resultado['content'], true);

        $this->assertFalse($respuesta['ok'], $resultado['content']);
        $this->assertNotEmpty($respuesta['faltan']);
        $this->assertSame(1, $this->compras_del_proveedor(), 'No se creó ninguna compra.');
        $this->assertSame(0, ProviderOrderScan::where('provider_order_id', $vieja->id)->count());
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * Fuera de la ventana: una vacía de hace más de DIAS_DE_REUSO días es una compra que quedó abierta
     * de otra cosa (la misma frontera que 6_Compra_con_factura_Test::no_reusa…mas_de_una_semana): ni
     * se reusa ni se pregunta. Y una vacía de hace 3 días con un escaneo tampoco se pregunta.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function fuera_de_la_ventana_o_con_escaneo_no_se_pregunta()
    {
        $this->compra(90, PropuestaCompraConFacturaIaHelper::DIAS_DE_REUSO + 1);

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame('Se crea una compra nueva', $this->que_se_hace($respuesta['tarjeta_id']));

        $con_escaneo = $this->compra(91, 3);

        $this->escaneo($con_escaneo, 'error');

        list($conversation, $generandose) = $this->escena();

        $otra = $this->proponer($conversation, $generandose);

        $this->assertTrue($otra['ok'], json_encode($otra));
        $this->assertSame('Se crea una compra nueva', $this->que_se_hace($otra['tarjeta_id']));
    }

    /**
     * `compra_nueva: true` es «la persona pidió una compra nueva»: no se pregunta y tampoco se reusa la
     * vacía de hoy. En 0/false/"" es como si no hubiera venido.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function compra_nueva_true_no_pregunta_ni_reusa_y_false_es_como_si_no_viniera()
    {
        $this->compra(43);

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose, ['compra_nueva' => true]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame('Se crea una compra nueva', $this->que_se_hace($respuesta['tarjeta_id']));

        foreach ([false, 0, '', 'false', null] as $valor) {

            list($conversation, $generandose) = $this->escena();

            $sin = $this->proponer($conversation, $generandose, ['compra_nueva' => $valor]);

            $this->assertSame('Se usa la compra N° 43, que está vacía', $this->que_se_hace($sin['tarjeta_id']), 'compra_nueva = ' . var_export($valor, true));
        }
    }

    // =====================================================================
    // 3. La confirmación revalida con el mismo método
    // =====================================================================

    /**
     * La tarjeta prometió la vacía de hoy y, antes del sí, le apareció un escaneo (otra factura cargada
     * desde la pantalla, u otra tarjeta): la factura va a una compra nueva y el resultado dice por qué
     * no fue a la prometida.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_escaneo_que_aparece_antes_del_si_manda_la_factura_a_una_compra_nueva()
    {
        $prometida = $this->compra(44);

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose);

        $this->assertSame('Se usa la compra N° 44, que está vacía', $this->que_se_hace($respuesta['tarjeta_id']));

        $this->escaneo($prometida, 'listo', '00012399');

        $resultado = $this->confirmar($conversation, $generandose, $respuesta['tarjeta_id']);

        $this->assertTrue($resultado['ok'], json_encode($resultado));
        $this->assertSame(2, $this->compras_del_proveedor());
        $this->assertSame(1, ProviderOrderScan::where('provider_order_id', $prometida->id)->count(), 'A la prometida no se le suma el segundo escaneo.');
        $this->assertStringContainsString('Creé la Compra N°', $resultado['resultado']);
        $this->assertStringContainsString('Compra N° 44 que había elegido', $resultado['resultado']);
        $this->assertStringNotContainsString('tu sí', $resultado['resultado'], 'En resuelto o directo no hubo sí: el texto no lo nombra.');
    }

    /**
     * Cambió el día entre la tarjeta y el sí (la compra prometida ya no es «de hoy»): compra nueva.
     * Se simula moviendo la compra a ayer, que es lo mismo que ve es_reusable_sin_nombrarla() a las
     * 00:05 del día siguiente, sin depender de la hora a la que corre el test.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function si_al_confirmar_la_compra_ya_no_es_de_hoy_va_a_una_nueva()
    {
        $prometida = $this->compra(45);

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose);

        $this->assertSame('Se usa la compra N° 45, que está vacía', $this->que_se_hace($respuesta['tarjeta_id']));

        $this->mover_al_pasado($prometida, 1);

        $resultado = $this->confirmar($conversation, $generandose, $respuesta['tarjeta_id']);

        $this->assertTrue($resultado['ok'], json_encode($resultado));
        $this->assertSame(2, $this->compras_del_proveedor());
        $this->assertSame(0, ProviderOrderScan::where('provider_order_id', $prometida->id)->count());
    }

    /**
     * Y si le cargaron artículos entre la tarjeta y el sí, lo mismo (el caso que ya cuidaba la
     * versión anterior, ahora por el método único).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function si_al_confirmar_la_compra_tiene_articulos_va_a_una_nueva()
    {
        $prometida = $this->compra(46);

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose);

        $articulo = Article::create(['user_id' => $this->comercio->id, 'name' => 'Artículo P19 tardío']);
        $prometida->articles()->attach($articulo->id, ['amount' => 2]);

        $resultado = $this->confirmar($conversation, $generandose, $respuesta['tarjeta_id']);

        $this->assertTrue($resultado['ok'], json_encode($resultado));
        $this->assertSame(2, $this->compras_del_proveedor());
        $this->assertSame(0, ProviderOrderScan::where('provider_order_id', $prometida->id)->count());
    }

    // =====================================================================
    // 4. compra_num sigue mandando, y avisa del otro escaneo
    // =====================================================================

    /**
     * «Adjuntala a la compra 13»: aunque la 13 ya tenga escaneada la 0003-00012345, la factura va ahí
     * (la nombró el dueño), y la tarjeta y el resultado dicen que ya tenía otra factura escaneada.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function compra_num_sobre_una_compra_con_escaneo_adjunta_y_avisa()
    {
        $compra_13 = $this->compra(13, 3);

        $this->escaneo($compra_13, 'listo', '00012345');

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose, ['compra_num' => 13]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertTrue($tarjeta->datos['compra_indicada']);
        $this->assertStringContainsString('ya tenía escaneada otra factura', (string) $tarjeta->presentacion['aviso']);
        $this->assertStringContainsString('Factura A 0003-00012345', (string) $tarjeta->presentacion['aviso']);
        $this->assertStringContainsString('ya tenía escaneada otra factura', (string) $respuesta['nota']);

        $resultado = $this->confirmar($conversation, $generandose, $tarjeta->id);

        $this->assertTrue($resultado['ok'], json_encode($resultado));
        $this->assertSame(1, $this->compras_del_proveedor());
        $this->assertSame(2, ProviderOrderScan::where('provider_order_id', $compra_13->id)->count());
        $this->assertStringContainsString('Adjunté la factura a la Compra N° 13', $resultado['resultado']);
        $this->assertStringContainsString('ya tenía escaneada otra factura', $resultado['resultado']);
        $this->assertStringContainsString('otra factura, la Factura A 0003-00012345', $resultado['resultado']);
        $this->assertStringNotContainsString('factura (Factura', $resultado['resultado'], 'Sin paréntesis adentro del paréntesis.');
    }

    // =====================================================================
    // 6. Lo que encontró el revisor independiente (19/10/2026)
    // =====================================================================

    /**
     * La herramienta por el despacho real, con la confianza del dueño en el modo pedido: es lo que
     * decide si la tarjeta se ejecuta sola ("resuelto"/"directo") o espera el sí.
     *
     * @param  string  $modo
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $mensaje
     * @param  array  $input
     * @return array
     */
    protected function herramienta_en($modo, $conversation, $mensaje, array $input = [])
    {
        User::where('id', $this->comercio->id)->update(['agente_confianza' => $modo]);

        $resultado = HerramientasDeCarga::ejecutar(
            'proponer_compra_con_factura',
            array_merge(['proveedor' => 'Ferretera del Sur'], $input),
            $conversation,
            $mensaje
        );

        $this->assertFalse($resultado['is_error'], $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    /**
     * 🔴 `compra_num` QUE EL DUEÑO NO DIJO, SOBRE UNA COMPRA QUE YA TIENE OTRA FACTURA. El dueño escribe
     * «cargala» sin nombrar compra y el modelo deduce la 13 del historial (de un «Creé la Compra N° 13»
     * anterior, o de una charla vieja). En "resuelto" eso se ejecutaba solo y la 13 quedaba con dos
     * escaneos. Ahora queda tarjeta (`requiere_confirmacion`), la tarjeta dice que ya tiene otra
     * factura, y no se escanea nada. Lo que el dueño dijo ANTES de la última respuesta del asistente
     * no cuenta como "la nombró ahora".
     *
     * @group asistente-whatsapp
     * @test
     */
    public function compra_num_no_dicho_sobre_una_compra_con_escaneo_queda_tarjeta_en_resuelto()
    {
        $compra_13 = $this->compra(13, 3);

        $this->escaneo($compra_13, 'listo', '00012345');

        $conversation = $this->conversacion_whatsapp();

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'mirá la compra 13 de la semana pasada']);
        $this->mensaje($conversation, 'assistant', 'listo', ['contenido' => 'La Compra N° 13 de Ferretera del Sur ya tiene la factura escaneada.']);

        $del_dueno = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'cargala']);
        $this->guardar_foto($del_dueno);

        $generandose = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $respuesta = $this->herramienta_en('resuelto', $conversation, $generandose, ['compra_num' => 13]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertTrue(!empty($respuesta['requiere_confirmacion']), 'Queda tarjeta aunque el modo sea resuelto: ' . json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado());
        $this->assertStringContainsString('ya tiene otra factura', (string) $tarjeta->presentacion['aviso']);
        $this->assertStringContainsString('ya tiene otra factura', (string) $respuesta['nota']);
        $this->assertSame(1, ProviderOrderScan::where('provider_order_id', $compra_13->id)->count(), 'No se escaneó nada todavía.');
    }

    /**
     * Lo mismo con "directo" y una factura cargada A MANO (sin escaneo): también es "otra factura".
     *
     * @group asistente-whatsapp
     * @test
     */
    public function compra_num_no_dicho_sobre_una_compra_con_factura_a_mano_queda_tarjeta_en_directo()
    {
        $compra_13 = $this->compra(13, 3);

        ProviderOrderAfipTicket::create([
            'provider_order_id' => $compra_13->id,
            'user_id'           => $this->comercio->id,
            'code'              => '0003-00012345',
            'issued_at'         => Carbon::now()->subDays(3),
            'total'             => 1000,
        ]);

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->herramienta_en('directo', $conversation, $generandose, ['compra_num' => 13]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertTrue(!empty($respuesta['requiere_confirmacion']), json_encode($respuesta));
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($respuesta['tarjeta_id'])->estado_guardado());
        $this->assertSame(0, ProviderOrderScan::where('provider_order_id', $compra_13->id)->count());
    }

    /**
     * Si el dueño SÍ nombró la compra en lo que escribió desde la última respuesta del asistente
     * («es de la compra 13»), la factura va ahí en el acto, como siempre: la nombró él.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function compra_num_dicho_por_el_dueno_se_ejecuta_en_resuelto_aunque_tenga_otra_factura()
    {
        $compra_13 = $this->compra(13, 3);

        $this->escaneo($compra_13, 'listo', '00012345');

        $conversation = $this->conversacion_whatsapp();

        $del_dueno = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'te paso otra factura']);
        $this->guardar_foto($del_dueno);

        $this->mensaje($conversation, 'assistant', 'listo', ['contenido' => '¿A qué compra va?']);
        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'es de la compra 13']);

        $generandose = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $respuesta = $this->herramienta_en('resuelto', $conversation, $generandose, ['compra_num' => 13]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertTrue(empty($respuesta['requiere_confirmacion']), json_encode($respuesta));
        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::find($respuesta['tarjeta_id'])->estado_guardado());
        $this->assertSame(2, ProviderOrderScan::where('provider_order_id', $compra_13->id)->count());
    }

    /**
     * `compra_num` y `compra_nueva` juntos se contradicen: el revisor midió que ganaba compra_num y
     * la factura iba a la compra vacía de otro día aunque el dueño había dicho «nueva». Ahora es un
     * error para el modelo (empieza con "No se armó ninguna tarjeta"), sin tarjeta ni compra.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function compra_num_y_compra_nueva_juntos_es_un_error_para_el_modelo()
    {
        $this->compra(12, 3);

        list($conversation, $generandose, $foto) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose, ['compra_num' => 12, 'compra_nueva' => true]);

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertStringStartsWith('No se armó ninguna tarjeta', (string) $respuesta['error']);
        $this->assertStringContainsString('mandá uno solo', (string) $respuesta['error']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
        $this->assertNull(AiMessageImagen::find($foto->id)->gestionada_at);

        // compra_nueva en falso no cuenta como "los dos": es como si no viniera.
        $sola = $this->proponer($conversation, $generandose, ['compra_num' => 12, 'compra_nueva' => false]);

        $this->assertTrue($sola['ok'], json_encode($sola));
    }

    /**
     * El número de factura escrito a mano en la compra (`numero_comprobante`) también es "otra
     * factura": la pantalla de la compra no carga `fecha_emision_comprobante` (está comentado en el
     * modelo de la SPA) y el campo que el dueño sí llena es el número. El revisor midió que una compra
     * de hoy con '0003-00012345' se reusaba como vacía. Uno vacío o con espacios no cuenta.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_compra_de_hoy_con_numero_de_comprobante_a_mano_no_se_reusa()
    {
        $hoy = $this->compra(47);

        DB::table('provider_orders')->where('id', $hoy->id)->update(['numero_comprobante' => '0003-00012345']);

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame('Se crea una compra nueva', $this->que_se_hace($respuesta['tarjeta_id']));

        DB::table('provider_orders')->where('id', $hoy->id)->update(['numero_comprobante' => '  ']);

        list($conversation, $generandose) = $this->escena();

        $vacio = $this->proponer($conversation, $generandose);

        $this->assertSame('Se usa la compra N° 47, que está vacía', $this->que_se_hace($vacio['tarjeta_id']));
    }

    /**
     * `compra_nueva` se hereda en una corrección con reemplaza_a: el dueño dijo «nueva», quedó la
     * tarjeta, y después corrige otra cosa («en la sucursal 2»). Sin heredarlo, la corrección volvía
     * a preguntar por la compra vacía de otro día.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function compra_nueva_se_hereda_en_una_correccion_con_reemplaza_a()
    {
        $this->compra(12, 3);

        list($conversation, $generandose) = $this->escena();

        $primera = $this->proponer($conversation, $generandose, ['compra_nueva' => true]);

        $this->assertTrue($primera['ok'], json_encode($primera));
        $this->assertTrue((bool) AiMessageAction::find($primera['tarjeta_id'])->datos['compra_nueva']);

        $otro_turno = $this->siguiente_turno($conversation, $generandose, 'Te armo una compra nueva con la factura, ¿la registro?', 'ah, y que entre en la sucursal 2');

        $correccion = $this->proponer($conversation, $otro_turno, ['reemplaza_a' => $primera['tarjeta_id'], 'sucursal' => 'sucursal 2']);

        $this->assertTrue($correccion['ok'], 'La corrección no vuelve a preguntar: ' . json_encode($correccion));
        $this->assertSame('Se crea una compra nueva', $this->que_se_hace($correccion['tarjeta_id']));
    }

    /**
     * Al confirmar, «de hoy» es el día en que se creó la TARJETA, no el del sí: tarjeta a las 23:59
     * con la compra vacía que el dueño armó ese día, sí a las 00:01 → la factura va a esa compra (antes
     * iba a una compra nueva y dejaba sin usar la que el dueño había armado para esa factura). Se
     * simula moviendo la compra y la tarjeta a ayer a la noche, sin depender de la hora del test.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_revalidacion_compara_contra_el_dia_de_la_tarjeta()
    {
        $de_anoche = $this->compra(48);

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose);

        $this->assertSame('Se usa la compra N° 48, que está vacía', $this->que_se_hace($respuesta['tarjeta_id']));

        DB::table('provider_orders')->where('id', $de_anoche->id)->update(['created_at' => Carbon::yesterday()->setTime(23, 50)]);
        DB::table('ai_message_actions')->where('id', $respuesta['tarjeta_id'])->update(['created_at' => Carbon::yesterday()->setTime(23, 59)]);

        $resultado = $this->confirmar($conversation, $generandose, $respuesta['tarjeta_id']);

        $this->assertTrue($resultado['ok'], json_encode($resultado));
        $this->assertSame(1, $this->compras_del_proveedor(), 'La factura va a la compra de anoche, no a una nueva.');
        $this->assertSame(1, ProviderOrderScan::where('provider_order_id', $de_anoche->id)->count());
        $this->assertStringContainsString('Adjunté la factura a la Compra N° 48', $resultado['resultado']);
    }

    /**
     * Una conversación con UN mensaje del dueño (con la foto de la factura) y el assistant que propone.
     *
     * @param  string  $texto
     * @return array{0: \App\Models\AiConversation, 1: \App\Models\AiMessage}
     */
    protected function con_el_mensaje($texto)
    {
        $conversation = $this->conversacion_whatsapp();

        $del_dueno = $this->mensaje($conversation, 'user', 'listo', ['contenido' => $texto]);

        $this->guardar_foto($del_dueno);

        $generandose = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        return [$conversation, $generandose];
    }

    /**
     * 🔴 LO QUE ENGAÑABA A la_nombro_el_dueno() (segunda revisión independiente, medido en resuelto):
     * el número buscado suelto se encontraba en una fecha («del 13/10»), en un monto («$14.500») y en
     * una negación («no es de la 15»), y la factura se pegaba sola a esa compra. Ahora el número
     * cuenta solo con contexto de compra («la compra 13», «N° 13», «nro 13», «#13», «número 13») o si
     * es el mensaje entero, nunca seguido de /.,: y dígito ni precedido de $, y con una negación cerca
     * no cuenta. «es de la compra 13» y «13» solos sí la nombran.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_numero_cuenta_solo_con_contexto_de_compra_y_sin_negacion()
    {
        foreach ([13 => '00012345', 14 => '00012346', 15 => '00012347', 21 => '00012351', 22 => '00012352', 23 => '00012353', 24 => '00012354'] as $num => $factura) {
            $this->escaneo($this->compra($num, 3), 'listo', $factura);
        }

        $no_la_nombran = [
            [13, 'te paso la factura del 13/10 de ferretera del sur, cargala'],
            [14, 'son $14.500, cargala'],
            [15, 'no es de la 15, cargala aparte'],
            [15, 'no es de la compra 15, cargala aparte'],
            [13, 'llegaron 13 cajas, cargala'],
        ];

        foreach ($no_la_nombran as $caso) {

            list($conversation, $generandose) = $this->con_el_mensaje($caso[1]);

            $respuesta = $this->herramienta_en('resuelto', $conversation, $generandose, ['compra_num' => $caso[0]]);

            $this->assertTrue($respuesta['ok'], json_encode($respuesta));
            $this->assertTrue(!empty($respuesta['requiere_confirmacion']), '«' . $caso[1] . '» no nombra la compra ' . $caso[0] . ': ' . json_encode($respuesta));
        }

        $la_nombran = [
            [21, 'es de la compra 21'],
            [22, '22'],
            [23, 'va en la N° 23'],
            [24, 'nro 24, cargala'],
        ];

        foreach ($la_nombran as $caso) {

            list($conversation, $generandose) = $this->con_el_mensaje($caso[1]);

            $respuesta = $this->herramienta_en('resuelto', $conversation, $generandose, ['compra_num' => $caso[0]]);

            $this->assertTrue($respuesta['ok'], json_encode($respuesta));
            $this->assertTrue(empty($respuesta['requiere_confirmacion']), '«' . $caso[1] . '» sí nombra la compra ' . $caso[0] . ': ' . json_encode($respuesta));
        }
    }

    // =====================================================================
    // 5. La herramienta y el prompt
    // =====================================================================

    /**
     * `compra_nueva` va AL FINAL de las propiedades (regla del prefijo del caché), después de
     * `compra_num`, como boolean, y la descripción de la herramienta y el prompt nombran la regla.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_herramienta_y_el_prompt_traen_compra_nueva()
    {
        $definicion = null;

        foreach (HerramientasDeCarga::definiciones(true) as $una) {
            if ($una['name'] === 'proponer_compra_con_factura') {
                $definicion = $una;
            }
        }

        $this->assertNotNull($definicion);

        $claves = array_keys($definicion['input_schema']['properties']);

        $this->assertSame(['proveedor', 'sucursal', 'reemplaza_a', 'cuit', 'compra_num', 'compra_nueva'], $claves);
        $this->assertSame('boolean', $definicion['input_schema']['properties']['compra_nueva']['type']);
        $this->assertStringContainsString('compra_nueva', $definicion['description']);
        $this->assertStringContainsString('de hoy', $definicion['description']);

        $prompt = (new \App\Services\AsistenteIa\AsistenteIaService())->build_system_prompt($this->conversacion_whatsapp(), $this->comercio->fresh(), true, true);

        $this->assertStringContainsString('compra_nueva', $prompt);
    }
}
