<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Base de la suite del asistente por WhatsApp (misión asistente-por-whatsapp, §3.8 del plan,
 * 16/9/2026).
 *
 * Arma un comercio propio con su empleado en vez de usar el fixture de la ferretería: lo que se
 * prueba acá es la tenencia y el canal, y para eso hace falta poder crear un SEGUNDO dueño y
 * comprobar que no se cruzan.
 *
 * 🔴 `app.USER_ID` se fija al comercio del test. No es un atajo: es la configuración real de una
 * instancia (config('app.USER_ID')), y es lo que hace que AsistenteCanalHelper::dueno() resuelva
 * sin ambigüedad. La base de testing tiene varios dueños —el fixture de la ferretería y los que
 * deje cada suite—, igual que una base compartida de producción, así que sin esto ningún test de
 * este archivo estaría probando el caso real.
 *
 * 🔴 La clave de Anthropic queda null: nada de esta suite sale a la red.
 */
abstract class AsistenteWhatsappTestCase extends TestCase
{
    use DatabaseTransactions;

    /** Clave que el admin manda en el header X-Admin-Api-Key. */
    const CLAVE = 'clave-del-admin-para-los-tests';

    /** Slug de la extensión que gatea el módulo IA. */
    const SLUG_ASISTENTE = 'asistente_ia';

    /** Slug de la extensión que gatea el escaneo de facturas de compra. */
    const SLUG_ESCANEO = 'escaneo_factura_compra';

    /** @var User El dueño de la instancia. */
    protected $comercio;

    /** @var User Un empleado del mismo comercio. */
    protected $empleado;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: los tests jamás salen a la red.
        config(['services.anthropic.api_key' => null]);

        config(['services.admin_api.api_key' => self::CLAVE]);

        /*
         * Apagado a propósito, que es como está en producción (informe del mostrador, 14/9): la
         * suite tiene que probar que el canal exige la clave IGUAL, porque la valida el
         * controlador y no el middleware.
         */
        config(['services.admin_api.require_api_key' => false]);

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp',
            'company_name' => 'Ferretería del canal',
            'email'        => 'wsp-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado whatsapp',
            'email'    => 'wsp-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        config(['app.USER_ID' => $this->comercio->id]);
    }

    /**
     * Asigna una extensión al comercio (el gate resuelve siempre al dueño).
     *
     * @param  string  $slug
     * @param  \App\Models\User|null  $duenio
     * @return void
     */
    protected function dar_extension($slug = self::SLUG_ASISTENTE, $duenio = null)
    {
        $duenio = is_null($duenio) ? $this->comercio : $duenio;

        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => $slug,
                'name' => $slug,
            ]);
        }

        $duenio->extencions()->attach($extencion->id);
        $duenio->load('extencions');
    }

    /**
     * Otro dueño, para los tests de aislamiento.
     *
     * @return \App\Models\User
     */
    protected function otro_dueno()
    {
        return User::create([
            'name'         => 'Otro comercio',
            'company_name' => 'Otro negocio',
            'email'        => 'wsp-otro-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Headers del admin, con la clave salvo que se pida sin ella.
     *
     * @param  bool  $con_clave
     * @return array
     */
    protected function headers($con_clave = true)
    {
        return $con_clave ? ['X-Admin-Api-Key' => self::CLAVE] : [];
    }

    /**
     * POST del mensaje entrante, tal como lo manda el admin.
     *
     * @param  array  $datos
     * @param  bool  $con_clave
     * @return \Illuminate\Testing\TestResponse
     */
    protected function mandar(array $datos = [], $con_clave = true)
    {
        $cuerpo = array_merge(['texto' => 'Hola, ¿cómo vengo hoy?', 'tipo' => 'texto'], $datos);

        return $this->postJson('api/admin-sync/asistente/mensajes', $cuerpo, $this->headers($con_clave));
    }

    /**
     * Una conversación del canal de WhatsApp del dueño.
     *
     * @param  mixed  $last_message_at
     * @param  \App\Models\User|null  $duenio
     * @return \App\Models\AiConversation
     */
    protected function conversacion_whatsapp($last_message_at = null, $duenio = null)
    {
        $duenio = is_null($duenio) ? $this->comercio : $duenio;

        return AiConversation::create([
            'user_id'         => $duenio->id,
            'auth_user_id'    => $duenio->id,
            'origen'          => AiConversation::ORIGEN_WHATSAPP,
            'last_message_at' => is_null($last_message_at) ? now() : $last_message_at,
        ]);
    }

    /**
     * Un mensaje de una conversación.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  string  $rol
     * @param  string  $estado
     * @param  array  $overrides
     * @return \App\Models\AiMessage
     */
    protected function mensaje($conversation, $rol = 'assistant', $estado = 'listo', array $overrides = [])
    {
        return AiMessage::create(array_merge([
            'ai_conversation_id' => $conversation->id,
            'rol'                => $rol,
            'contenido'          => $estado === 'pendiente' ? null : 'Un mensaje.',
            'estado'             => $estado,
            'canal'              => AiMessage::CANAL_WHATSAPP,
        ], $overrides));
    }
}
