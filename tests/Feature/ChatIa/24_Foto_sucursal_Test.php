<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaFotoSucursalIaHelper;
use App\Models\Address;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * Misión foto-sucursal-y-asistente-configurable — la foto que el agente asigna a una sucursal.
 *
 * Protege: solo el dueño/admin puede; sin sucursal o sin foto, respuesta de negocio; al proponer se
 * crea la tarjeta; al ejecutar se asigna `addresses.image_url` y se sella la foto; y la auto-ejecución
 * solo ocurre en modo "resuelto" (en "cauteloso" queda una tarjeta para confirmar a mano).
 */
class Foto_sucursal_Test extends TestCase
{
    use DatabaseTransactions;

    const SLUG = 'asistente_ia';

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    /** Owners cuyas carpetas de fotos hay que limpiar en tearDown. */
    protected $owners_a_limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'         => 'Comercio foto P24',
            'company_name' => 'Ferreteria P24',
            'email'        => 'foto-p24-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'agente_confianza' => 'cauteloso',
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado foto P24',
            'email'    => 'foto-p24-emp-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);
    }

    protected function tearDown(): void
    {
        // Borra las fotos sembradas en el disco local y los PNG que ejecutar() dejó en public.
        foreach ($this->owners_a_limpiar as $owner_id) {
            try {
                Storage::disk('local')->deleteDirectory('asistente_imagenes/' . $owner_id);
            } catch (\Throwable $e) {
                // best-effort
            }
        }

        foreach (Address::where('user_id', $this->comercio->id)->get() as $address) {
            $url = (string) $address->image_url;
            if ($url !== '') {
                $nombre = basename(parse_url($url, PHP_URL_PATH));
                $ruta = storage_path() . '/app/public/' . $nombre;
                if ($nombre !== '' && is_file($ruta)) {
                    @unlink($ruta);
                }
            }
        }

        parent::tearDown();
    }

    /**
     * Conversación del dueño (o del empleado si se pasa), con un mensaje del usuario que trae una
     * foto sin gestionar y el assistant pendiente que propone.
     *
     * @param  \App\Models\User|null  $persona  auth_user_id de la conversación (default: el dueño).
     * @return array{0: AiConversation, 1: AiMessage, 2: AiMessageImagen}
     */
    protected function conversacion_con_foto($persona = null)
    {
        $persona = is_null($persona) ? $this->comercio : $persona;

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $persona->id,
        ]);

        $user_message = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Esta es la foto de la sucursal',
            'estado'             => 'listo',
        ]);

        $binario = (new ImageManager())->canvas(12, 12, '#0B84F8')->encode('png');
        $path = 'asistente_imagenes/' . $this->comercio->id . '/' . $user_message->id . '/1.png';
        Storage::disk('local')->put($path, (string) $binario);
        $this->owners_a_limpiar[] = $this->comercio->id;

        $imagen = AiMessageImagen::create([
            'ai_message_id' => $user_message->id,
            'user_id'       => $this->comercio->id,
            'orden'         => 1,
            'path'          => $path,
            'mime'          => 'image/png',
            'bytes'         => strlen((string) $binario),
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant, $imagen];
    }

    /**
     * @param  \App\Models\User  $duenio
     * @return Address
     */
    protected function sucursal($nombre = 'Casa Central')
    {
        return Address::create(['street' => $nombre, 'user_id' => $this->comercio->id]);
    }

    /** @test */
    public function solo_el_dueno_puede_asignar_la_foto()
    {
        $this->sucursal();
        list($conversation, $assistant) = $this->conversacion_con_foto($this->empleado);

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);
        $respuesta = PropuestaFotoSucursalIaHelper::proponer($contexto, $assistant, []);

        $this->assertFalse($respuesta['ok']);
        $this->assertSame(PropuestaFotoSucursalIaHelper::MENSAJE_SIN_PERMISO, $respuesta['error']);
    }

    /** @test */
    public function sin_ninguna_sucursal_da_error()
    {
        list($conversation, $assistant) = $this->conversacion_con_foto();

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);
        $respuesta = PropuestaFotoSucursalIaHelper::proponer($contexto, $assistant, []);

        $this->assertFalse($respuesta['ok']);
        $this->assertNotNull($respuesta['error']);
    }

    /** @test */
    public function con_una_sucursal_y_foto_crea_la_tarjeta()
    {
        $sucursal = $this->sucursal();
        list($conversation, $assistant) = $this->conversacion_con_foto();

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);
        $respuesta = PropuestaFotoSucursalIaHelper::proponer($contexto, $assistant, []);

        $this->assertTrue($respuesta['ok']);
        $this->assertSame(AiMessageAction::TIPO_FOTO_SUCURSAL, $respuesta['tipo']);

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertNotNull($accion);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $accion->estado_guardado());
        $this->assertSame((int) $sucursal->id, (int) $accion->datos['address_id']);
    }

    /** @test */
    public function modo_resuelto_auto_asigna_la_foto_sin_tarjeta_para_confirmar()
    {
        $this->comercio->agente_confianza = 'resuelto';
        $this->comercio->save();

        $sucursal = $this->sucursal();
        list($conversation, $assistant) = $this->conversacion_con_foto();

        HerramientasDeCarga::ejecutar('proponer_foto_sucursal', [], $conversation, $assistant);

        // Auto-ejecutó: la sucursal quedó con su foto y la tarjeta, confirmada.
        $this->assertNotNull($sucursal->fresh()->image_url);
        $this->assertNotSame('', (string) $sucursal->fresh()->image_url);

        $accion = AiMessageAction::where('ai_conversation_id', $conversation->id)
            ->where('tipo', AiMessageAction::TIPO_FOTO_SUCURSAL)->first();
        $this->assertNotNull($accion);
        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $accion->estado_guardado());
    }

    /** @test */
    public function modo_cauteloso_deja_la_tarjeta_sin_ejecutar()
    {
        $this->comercio->agente_confianza = 'cauteloso';
        $this->comercio->save();

        $sucursal = $this->sucursal();
        list($conversation, $assistant) = $this->conversacion_con_foto();

        HerramientasDeCarga::ejecutar('proponer_foto_sucursal', [], $conversation, $assistant);

        // NO auto-ejecutó: la foto sigue sin asignarse y la tarjeta queda propuesta.
        $this->assertTrue(is_null($sucursal->fresh()->image_url) || (string) $sucursal->fresh()->image_url === '');

        $accion = AiMessageAction::where('ai_conversation_id', $conversation->id)
            ->where('tipo', AiMessageAction::TIPO_FOTO_SUCURSAL)->first();
        $this->assertNotNull($accion);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $accion->estado_guardado());
    }
}
