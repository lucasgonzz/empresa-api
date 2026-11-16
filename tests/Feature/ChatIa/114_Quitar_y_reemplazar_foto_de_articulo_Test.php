<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Http\Controllers\Helpers\asistente_ia\EjecutorAccionesIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\Article;
use App\Models\Image;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * Misión asistente-mejoras-4-dias (16/11/2026), P6 — quitar y reemplazar la foto de un artículo.
 *
 * EL CASO REAL (V8, Truvari, conv 19 y 24): «eliminá la imagen y yo te envío una imagen que
 * corresponde». El agente dijo cuatro veces que no tenía herramienta para borrar fotos, sumó la nueva y
 * dejó la equivocada publicada. Lo que protege este archivo:
 *
 *  - quitar_foto_articulo arma una tarjeta que MUESTRA la foto que se saca, y con varias fotos pregunta cuál;
 *  - 🔴 nunca se ejecuta sola, ni con el dueño en "directo";
 *  - al confirmar se saca la fila de `images` (lo que lee la tienda) y el artículo se marca como cambiado;
 *  - proponer_foto_articulo con reemplazar_fotos: la nueva queda como principal y las de antes se van;
 *  - una foto encontrada en internet tampoco se pone sola en "directo" (V13).
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados, union types.
 *
 * @group chat-ia
 */
class Quitar_y_reemplazar_foto_de_articulo_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** Los archivos que el test dejó en storage/app/public. */
    protected $archivos = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'             => 'Vinoteca foto P6',
            'company_name'     => 'Vinoteca foto P6',
            'email'            => 'foto-p6-' . uniqid() . '@test.local',
            'password'         => Hash::make('secret'),
            'agente_confianza' => 'directo',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (Image::where('imageable_type', 'article')->whereIn('imageable_id', Article::where('user_id', $this->comercio->id)->withTrashed()->pluck('id'))->get() as $image) {
            $this->archivos[] = basename((string) parse_url((string) $image->hosting_url, PHP_URL_PATH));
        }

        foreach (array_unique($this->archivos) as $nombre) {
            $ruta = storage_path() . '/app/public/' . $nombre;
            if ($nombre !== '' && is_file($ruta)) {
                @unlink($ruta);
            }
        }

        try {
            Storage::disk('local')->deleteDirectory('asistente_imagenes/' . $this->comercio->id);
        } catch (\Throwable $e) {
            // best-effort
        }

        parent::tearDown();
    }

    protected function articulo($nombre)
    {
        return Article::create(['name' => 'zz-p6 ' . $nombre, 'user_id' => $this->comercio->id, 'status' => 'active']);
    }

    /**
     * Una foto colgada del artículo, como la deja la pantalla (fila en `images` + archivo en storage/app/public).
     */
    protected function foto_de(Article $articulo, $principal = false)
    {
        $nombre = 'zz-p6-' . uniqid() . '.webp';
        file_put_contents(storage_path() . '/app/public/' . $nombre, 'webp');
        $this->archivos[] = $nombre;

        $imagen = Image::create([
            'hosting_url'    => ApiUrlHelper::storage($nombre),
            'imageable_id'   => $articulo->id,
            'imageable_type' => 'article',
        ]);

        if ($principal) {
            DB::table('images')->where('id', $imagen->id)->update(['first' => 1]);
        }

        return $imagen;
    }

    protected function conversacion()
    {
        return AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);
    }

    protected function asistente(AiConversation $conversation)
    {
        return AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'contenido'            => 'Te dejé la tarjeta.',
            'estado'               => 'listo',
            'acciones_habilitadas' => true,
        ]);
    }

    /**
     * Una foto mandada al chat (por el dueño, o encontrada por el asistente: va colgada de su mensaje).
     */
    protected function foto_del_chat(AiConversation $conversation, $rol = 'user')
    {
        $mensaje = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => $rol, 'contenido' => 'foto', 'estado' => 'listo']);

        $binario = (string) (new ImageManager())->canvas(12, 12, '#7A1F2B')->encode('png');
        $path = 'asistente_imagenes/' . $this->comercio->id . '/' . $mensaje->id . '/1.png';
        Storage::disk('local')->put($path, $binario);

        return AiMessageImagen::create([
            'ai_message_id' => $mensaje->id,
            'user_id'       => $this->comercio->id,
            'orden'         => 1,
            'path'          => $path,
            'mime'          => 'image/png',
            'bytes'         => strlen($binario),
        ]);
    }

    protected function herramienta($conversation, $assistant, $nombre, array $input)
    {
        $this->actingAs($this->comercio);

        $resultado = HerramientasDeCarga::ejecutar($nombre, $input, $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    protected function confirmar(AiConversation $conversation, $tarjeta_id)
    {
        $this->actingAs($this->comercio);

        return EjecutorAccionesIaHelper::confirmar($conversation, (int) $tarjeta_id, $this->comercio, function () {
            return 1;
        });
    }

    /**
     * @test
     */
    public function la_herramienta_existe_y_dice_que_muestra_la_foto_y_no_tiene_vuelta()
    {
        $definicion = null;

        foreach (HerramientasDeCarga::definiciones() as $herramienta) {
            if ($herramienta['name'] === 'quitar_foto_articulo') {
                $definicion = $herramienta;
            }
        }

        $this->assertNotNull($definicion, 'quitar_foto_articulo no está entre las herramientas de carga.');
        $this->assertTrue(HerramientasDeCarga::maneja('quitar_foto_articulo'));
        $this->assertStringContainsString('NUNCA digas que no podés sacar', $definicion['description']);
    }

    /**
     * 🔴 V8: con dos fotos pregunta cuál; con la elegida, la tarjeta la muestra y espera el sí aun en directo.
     *
     * @test
     */
    public function quitar_una_foto_muestra_la_que_se_saca_y_espera_el_si()
    {
        $malbec = $this->articulo('KILLKA MALBEC');
        $buena = $this->foto_de($malbec, true);
        $blanca = $this->foto_de($malbec);

        $conversation = $this->conversacion();
        $assistant = $this->asistente($conversation);

        $r = $this->herramienta($conversation, $assistant, 'quitar_foto_articulo', ['articulo' => 'killka malbec']);

        $this->assertFalse($r['ok']);
        $this->assertNotEmpty($r['faltan'], json_encode($r));
        $this->assertCount(2, $r['opciones']['fotos']);
        $this->assertTrue($r['opciones']['fotos'][0]['principal']);

        $r = $this->herramienta($conversation, $assistant, 'quitar_foto_articulo', ['articulo_id' => $malbec->id, 'imagen_numero' => 2]);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertTrue($r['requiere_confirmacion']);

        $tarjeta = AiMessageAction::findOrFail($r['tarjeta_id']);

        $this->assertSame('propuesta', $tarjeta->estado, 'Sacar una foto nunca se hace solo, ni en directo.');
        $this->assertSame(ApiUrlHelper::url_publica_de_imagen($blanca->hosting_url), $tarjeta->presentacion['imagen_url'], 'La tarjeta muestra la foto QUE SE SACA.');
        $this->assertStringContainsString('no se puede deshacer', $tarjeta->presentacion['aviso']);
        $this->assertSame(2, Image::where('imageable_type', 'article')->where('imageable_id', $malbec->id)->count());

        DB::table('articles')->where('id', $malbec->id)->update(['updated_at' => now()->subDay()]);

        $resultado = $this->confirmar($conversation, $tarjeta->id);

        $this->assertSame(200, $resultado['status'], json_encode($resultado));
        $this->assertStringContainsString('le quedan 1', json_encode($resultado, JSON_UNESCAPED_UNICODE));
        $this->assertNull(Image::find($blanca->id), 'La foto equivocada ya no está (ni para la tienda).');
        $this->assertNotNull(Image::find($buena->id));
        $this->assertTrue($malbec->fresh()->updated_at->gt(now()->subHour()), 'El artículo queda marcado como cambiado para el sync.');
    }

    /**
     * Con una sola foto no pregunta; con «todas», van todas.
     *
     * @test
     */
    public function con_una_sola_foto_o_con_todas_no_pregunta()
    {
        $malbec = $this->articulo('GRAN ENEMIGO');
        $this->foto_de($malbec);
        $this->foto_de($malbec);

        $conversation = $this->conversacion();
        $assistant = $this->asistente($conversation);

        $r = $this->herramienta($conversation, $assistant, 'quitar_foto_articulo', ['articulo_id' => $malbec->id, 'todas' => true]);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(2, $r['fotos_que_se_sacan']);

        $this->assertSame(200, $this->confirmar($conversation, $r['tarjeta_id'])['status']);
        $this->assertSame(0, Image::where('imageable_type', 'article')->where('imageable_id', $malbec->id)->count());

        $sin_fotos = $this->herramienta($conversation, $assistant, 'quitar_foto_articulo', ['articulo_id' => $malbec->id]);

        $this->assertFalse($sin_fotos['ok']);
        $this->assertStringContainsString('no tiene ninguna foto', $sin_fotos['error']);
    }

    /**
     * «Reemplazá todas por esta»: la nueva queda como principal y las de antes se van.
     *
     * @test
     */
    public function reemplazar_fotos_deja_solo_la_nueva()
    {
        $malbec = $this->articulo('KAIKEN MALBEC');
        $vieja = $this->foto_de($malbec, true);

        $conversation = $this->conversacion();
        $this->foto_del_chat($conversation);
        $assistant = $this->asistente($conversation);

        $r = $this->herramienta($conversation, $assistant, 'proponer_foto_articulo', ['articulo_id' => $malbec->id, 'reemplazar_fotos' => true]);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertTrue($r['requiere_confirmacion']);

        $tarjeta = AiMessageAction::findOrFail($r['tarjeta_id']);

        $this->assertSame('propuesta', $tarjeta->estado);
        $this->assertContains('Fotos que se sacan', array_column($tarjeta->presentacion['renglones'], 'etiqueta'));

        $this->assertSame(200, $this->confirmar($conversation, $tarjeta->id)['status']);

        $fotos = Image::where('imageable_type', 'article')->where('imageable_id', $malbec->id)->get();

        $this->assertCount(1, $fotos);
        $this->assertNotSame($vieja->id, $fotos[0]->id);
        $this->assertNull(Image::find($vieja->id));
    }

    /**
     * 🔴 V13: una foto encontrada en internet no se asigna sola, aunque el dueño esté en directo. La del dueño sí.
     *
     * @test
     */
    public function la_foto_de_internet_espera_el_si_en_directo()
    {
        $malbec = $this->articulo('ALTOS MALBEC');

        $conversation = $this->conversacion();
        $de_internet = $this->foto_del_chat($conversation, 'assistant');
        $assistant = $this->asistente($conversation);

        $r = $this->herramienta($conversation, $assistant, 'proponer_foto_articulo', ['articulo_id' => $malbec->id, 'imagen_id' => $de_internet->id]);

        $this->assertTrue($r['requiere_confirmacion'], json_encode($r));
        $this->assertSame('propuesta', AiMessageAction::findOrFail($r['tarjeta_id'])->estado);
        $this->assertSame(0, Image::where('imageable_type', 'article')->where('imageable_id', $malbec->id)->count());
    }
}
