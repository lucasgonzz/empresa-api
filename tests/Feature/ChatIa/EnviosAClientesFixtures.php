<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\WhatsappTemplateStandardHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\ExtencionEmpresa;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappTemplate;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Lo que comparten los tests de los envíos a clientes del asistente (misión
 * asistente-envios-a-clientes, 16/10/2026): un negocio PROPIO del test (no el de la ferretería
 * sembrada, para que sus clientes y sus chats no se mezclen con los del test), con WhatsApp
 * conectado en ENVÍO SIMULADO (en `testing` cuenta como demo: nada sale a Kapso), las plantillas
 * estándar aprobadas, y las dos vueltas de una conversación por WhatsApp: la que propone y la del sí.
 *
 * 🔴 Nada de esto sale a internet: el modo simulado devuelve un `wa_message_id` ficticio y el
 * `TestCase` corta cualquier pedido HTTP real. Los mails se miran con `Mail::fake()`.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados, union types, promoción de
 * constructor, readonly, enum ni #[...].
 */
trait EnviosAClientesFixtures
{
    /** @var User El dueño del negocio del test. */
    protected $dueno;

    /** @var WhatsappBotConfig */
    protected $config;

    /**
     * Arma el negocio del test: dueño con tienda, WhatsApp en envío simulado, las extensiones de
     * WhatsApp y de mails a clientes, y las plantillas estándar aprobadas.
     *
     * @param  array  $extras  Columnas de más para el dueño.
     * @return void
     */
    protected function armar_negocio(array $extras = [])
    {
        // 🔴 Nunca las claves reales del .env.testing, y nada de Pusher de verdad.
        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        config(['broadcasting.default' => 'null']);

        $this->dueno = User::create(array_merge([
            'name'         => 'ZZE Ferreteria Envios',
            'company_name' => 'ZZE Ferreteria Envios',
            'email'        => 'zze-envios-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'api_url'      => 'https://api-test.comerciocity.com',
            'online'       => 'tienda-zze.test',
            'dias_alertar_administradores_ventas_no_cobradas' => 0,
            'dias_alertar_empleados_ventas_no_cobradas'       => 0,
        ], $extras));

        $this->config = WhatsappBotConfig::create([
            'user_id'            => $this->dueno->id,
            'kapso_api_key'      => 'DEMO-SIN-CLAVE-ENVIO-SIMULADO',
            'phone_number_id'    => 'DEMO-000000000000000',
            'webhook_secret'     => 'secreto-envios-zze',
            'is_active'          => true,
            'ai_enabled_default' => false,
            'envio_simulado'     => true,
        ]);

        foreach (['whatsapp', 'enviar_mail_a_clientes'] as $slug) {
            $this->dar_extension($slug);
        }

        WhatsappTemplateStandardHelper::aprobar_estandar_para($this->dueno->id);

        $this->actuar_como($this->dueno);
    }

    /**
     * @param  string  $slug
     * @return void
     */
    protected function dar_extension($slug)
    {
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $slug]);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->dueno->load('extencions');
    }

    /**
     * @param  string  $slug
     * @return void
     */
    protected function sacar_extension($slug)
    {
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (!is_null($extencion)) {
            $this->dueno->extencions()->detach($extencion->id);
        }

        $this->dueno = $this->dueno->fresh();
        $this->dueno->load('extencions');
    }

    /**
     * La plantilla estándar vuelve a "pendiente de Meta" (el caso del día 1 de un negocio).
     *
     * @param  string  $meta_template_name
     * @return void
     */
    protected function desaprobar_plantilla($meta_template_name)
    {
        WhatsappTemplate::where('user_id', $this->dueno->id)
            ->where('meta_template_name', $meta_template_name)
            ->update(['status' => 'pendiente_meta']);
    }

    /**
     * @param  User  $user
     * @return void
     */
    protected function actuar_como($user)
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');

        $this->actingAs($user, 'web');
    }

    /**
     * Un cliente del negocio con su cuenta corriente en pesos y un movimiento (el PDF de la cuenta
     * corriente que lleva la plantilla del recordatorio necesita al menos uno).
     *
     * @param  string  $nombre
     * @param  string|null  $phone
     * @param  string|null  $email
     * @param  int|null  $owner_id  Para armar un cliente de OTRO negocio.
     * @return Client
     */
    protected function cliente($nombre, $phone = null, $email = null, $owner_id = null)
    {
        $owner_id = is_null($owner_id) ? $this->dueno->id : $owner_id;

        $client = Client::create([
            'name'    => $nombre,
            'user_id' => $owner_id,
            'phone'   => $phone,
            'email'   => $email,
        ]);

        $credit_account = CreditAccount::create([
            'user_id'    => $owner_id,
            'model_name' => 'client',
            'model_id'   => $client->id,
            'moneda_id'  => 1,
            'saldo'      => 0,
        ]);

        CurrentAcount::create([
            'user_id'           => $owner_id,
            'client_id'         => $client->id,
            'credit_account_id' => $credit_account->id,
            'moneda_id'         => 1,
            'debe'              => 0,
            'haber'             => 0,
            'saldo'             => 0,
            'status'            => 'pagado',
        ]);

        return $client;
    }

    /**
     * Una venta impaga del cliente, con la antigüedad pedida (deuda en su cuenta corriente).
     *
     * @param  Client  $client
     * @param  int  $dias_de_antiguedad
     * @param  float  $monto
     * @param  int|null  $num
     * @return Sale
     */
    protected function venta_impaga(Client $client, $dias_de_antiguedad, $monto, $num = null)
    {
        $creada_at = now()->subDays($dias_de_antiguedad);

        $sale = Sale::create([
            'user_id'    => $client->user_id,
            'client_id'  => $client->id,
            'num'        => $num,
            'moneda_id'  => 1,
            'total'      => $monto,
            'terminada'  => 1,
            'created_at' => $creada_at,
            'updated_at' => $creada_at,
        ]);

        $credit_account = CreditAccount::where('model_name', 'client')->where('model_id', $client->id)->first();

        CurrentAcount::create([
            'user_id'           => $client->user_id,
            'client_id'         => $client->id,
            'sale_id'           => $sale->id,
            'credit_account_id' => is_null($credit_account) ? null : $credit_account->id,
            'moneda_id'         => 1,
            'debe'              => $monto,
            'haber'             => 0,
            'saldo'             => $monto,
            'status'            => 'sin_pagar',
            'created_at'        => $creada_at,
            'updated_at'        => $creada_at,
        ]);

        return $sale;
    }

    /**
     * El chat del cliente con la ventana de 24 h ABIERTA (escribió hace un rato).
     *
     * @param  Client  $client
     * @return WhatsappChat
     */
    protected function chat_abierto(Client $client)
    {
        return WhatsappChat::create([
            'user_id'         => $this->dueno->id,
            'client_id'       => $client->id,
            'phone'           => $client->phone,
            'ai_enabled'      => false,
            'unread_count'    => 0,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);
    }

    /**
     * La primera vuelta de una conversación por WhatsApp: el pedido del dueño y el assistant que
     * se está generando (con las acciones habilitadas).
     *
     * @param  string  $pedido
     * @param  AiConversation|null  $conversation  Para seguir una que ya existe.
     * @param  string|null  $canal  null = el panel del sistema.
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function vuelta($pedido, $conversation = null, $canal = AiMessage::CANAL_WHATSAPP)
    {
        if (is_null($conversation)) {
            $conversation = AiConversation::create([
                'user_id'      => $this->dueno->id,
                'auth_user_id' => $this->dueno->id,
            ]);
        }

        $user = ['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $pedido, 'estado' => 'listo'];
        $assistant = ['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true];

        if (!is_null($canal)) {
            $user['canal'] = $canal;
            $assistant['canal'] = $canal;
        }

        AiMessage::create($user);

        return [$conversation, AiMessage::create($assistant)];
    }

    /**
     * Ejecuta una herramienta de carga por el despacho real y devuelve lo que ve el modelo.
     *
     * @param  AiConversation  $conversation
     * @param  AiMessage  $assistant
     * @param  string  $herramienta
     * @param  array  $input
     * @return array
     */
    protected function herramienta($conversation, $assistant, $herramienta, array $input)
    {
        $resultado = HerramientasDeCarga::ejecutar($herramienta, $input, $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], 'La herramienta devolvió una falla técnica: ' . $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    /**
     * El "sí" del dueño en el mensaje siguiente y la confirmación por texto, como lo haría el modelo:
     * el mensaje que propuso queda 'listo' (ya le llegó al dueño), entra el "sí" y el assistant nuevo
     * llama a confirmar_carga_pendiente.
     *
     * @param  AiConversation  $conversation
     * @param  AiMessage  $propuso
     * @param  int  $tarjeta_id
     * @param  string  $si
     * @return array  Lo que ve el modelo.
     */
    protected function confirmar_en_la_vuelta_siguiente($conversation, AiMessage $propuso, $tarjeta_id, $si = 'sí, mandalo')
    {
        $this->cerrar_la_vuelta($propuso);

        list($conversation, $siguiente) = $this->vuelta($si, $conversation);

        return $this->herramienta($conversation, $siguiente, 'confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]);
    }

    /**
     * Deja 'listo' el mensaje que propuso, con el contenido que le llegó al dueño: la frase del modelo
     * y, como lo agrega AsistenteIaService, el bloque de cada envío que quedó esperando el sí. Sin el
     * bloque adentro, el sí no confirma (ConfirmacionPorTextoIaHelper::envio_no_visto()).
     *
     * @param  AiMessage  $propuso
     * @param  bool  $con_el_bloque
     * @return void
     */
    protected function cerrar_la_vuelta(AiMessage $propuso, $con_el_bloque = true)
    {
        $contenido = 'Te preparo el envío.';

        if ($con_el_bloque) {

            foreach (\App\Models\AiMessageAction::where('ai_message_id', $propuso->id)->where('estado', \App\Models\AiMessageAction::ESTADO_PROPUESTA)->get() as $tarjeta) {

                $datos = is_array($tarjeta->datos) ? $tarjeta->datos : [];

                if (isset($datos['bloque'])) {
                    $contenido .= "\n\n" . $datos['bloque'];
                }
            }
        }

        $propuso->estado = 'listo';
        $propuso->contenido = $contenido;
        $propuso->save();
    }

    /**
     * Lo que se le muestra al dueño de una propuesta, en partes (`datos.para_mostrar` de la tarjeta):
     * desde el segundo chequeo no viaja en la respuesta al modelo.
     *
     * @param  array  $respuesta  La respuesta de la herramienta proponer_.
     * @return array
     */
    protected function para_mostrar(array $respuesta)
    {
        $this->assertArrayNotHasKey('para_mostrar', $respuesta, 'Al modelo no le llega el bloque entero (M4).');

        $tarjeta = \App\Models\AiMessageAction::find($respuesta['tarjeta_id']);

        return $tarjeta->datos['para_mostrar'];
    }

    /**
     * El bloque que el sistema agrega al mensaje por WhatsApp.
     *
     * @param  array  $respuesta
     * @return string
     */
    protected function bloque(array $respuesta)
    {
        return (string) \App\Models\AiMessageAction::find($respuesta['tarjeta_id'])->datos['bloque'];
    }
}
