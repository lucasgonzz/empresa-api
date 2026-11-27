<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ArchivosDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteImagenHelper;
use App\Http\Controllers\Helpers\asistente_ia\EjecutorAccionesIaHelper;
use App\Models\AiArchivo;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageImagen;
use App\Models\Article;
use App\Models\Image;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use App\Services\AsistenteIa\Mcp\McpHerramientasHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * Misión asistente-diario-20261119 (rama C, M3) — la foto del dueño en un tamaño exacto.
 *
 * EL CASO REAL (Truvari, conv. 42, mensajes 644-645): «Esta imagen la podés convertir en 500 px x 500 px?»
 * y el agente contestó que no tenía herramienta para redimensionar. Lo que protege este archivo:
 *
 *  - 🔴 ajustar_foto_a_medida deja la foto EXACTAMENTE en ancho × alto, tanto el archivo del link como la
 *    imagen nueva de la conversación;
 *  - encuadrar deja la foto entera con fondo BLANCO en lo que sobra; recortar llena todo;
 *  - el link baja la foto sin sesión, con su Content-Type de imagen;
 *  - una foto de OTRO negocio no se puede usar por su id;
 *  - el imagen_id nuevo sirve para proponer_foto_articulo y el artículo queda con la foto de ese tamaño;
 *  - la herramienta está al final del registro y fuera del MCP (por ahí no hay fotos de la conversación).
 *
 * 🔴 Sin red: Storage `local` falso y sin llamada al modelo. Los webp de los artículos van a
 * storage/app/public (disco real) y se borran en el tearDown.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados, union types.
 *
 * @group chat-ia
 */
class T191_Foto_a_medida_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $otro;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);
        config(['broadcasting.default' => 'null']);

        Storage::fake('local');

        $this->comercio = User::create([
            'name'             => 'Vinoteca foto M3',
            'company_name'     => 'Vinoteca foto M3',
            'email'            => 'foto-m3-' . uniqid() . '@test.local',
            'password'         => Hash::make('secret'),
            'api_url'          => 'https://api-test.comerciocity.com',
            'agente_confianza' => 'cauteloso',
        ]);

        $this->otro = User::create([
            'name'     => 'Otro negocio foto M3',
            'email'    => 'foto-m3-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    protected function tearDown(): void
    {
        // Los webp que la tarjeta de foto de artículo escribió en storage/app/public (disco real).
        $ids = Article::where('user_id', $this->comercio->id)->pluck('id');

        foreach (Image::where('imageable_type', 'article')->whereIn('imageable_id', $ids)->get() as $image) {
            $nombre = basename((string) parse_url((string) $image->hosting_url, PHP_URL_PATH));
            $ruta = storage_path() . '/app/public/' . $nombre;
            if ($nombre !== '' && is_file($ruta)) {
                @unlink($ruta);
            }
        }

        parent::tearDown();
    }

    /**
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion(User $dueno = null)
    {
        $dueno = is_null($dueno) ? $this->comercio : $dueno;

        $conversation = AiConversation::create(['user_id' => $dueno->id, 'auth_user_id' => $dueno->id]);

        return [$conversation, $this->asistente($conversation)];
    }

    protected function asistente(AiConversation $conversation)
    {
        return AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'contenido'            => 'Listo.',
            'estado'               => 'listo',
            'acciones_habilitadas' => true,
        ]);
    }

    /**
     * Una foto mandada por la persona: 300 × 200, roja (más ancha que alta), como webp en el disco local.
     */
    protected function foto_del_dueno(AiConversation $conversation, $owner_id)
    {
        $mensaje = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Esta imagen la podes convertir en 500 px x 500px ?',
            'estado'             => 'listo',
        ]);

        $binario = (string) (new ImageManager())->canvas(300, 200, '#cc0000')->encode('webp', 90);
        $path = 'asistente_imagenes/' . (int) $owner_id . '/' . $mensaje->id . '/1.webp';
        Storage::disk('local')->put($path, $binario);

        return AiMessageImagen::create([
            'ai_message_id' => $mensaje->id,
            'user_id'       => (int) $owner_id,
            'orden'         => 1,
            'path'          => $path,
            'mime'          => 'image/webp',
            'bytes'         => strlen($binario),
        ]);
    }

    protected function herramienta(AiConversation $conversation, AiMessage $assistant, $nombre, array $input)
    {
        $this->actingAs($this->comercio);

        $resultado = HerramientasDeCarga::ejecutar($nombre, $input, $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    /**
     * El color de un píxel como [r, g, b].
     */
    protected function color($binario, $x, $y)
    {
        $c = (new ImageManager())->make($binario)->pickColor($x, $y);

        return [(int) $c[0], (int) $c[1], (int) $c[2]];
    }

    /**
     * @param  array  $rgb
     * @return bool
     */
    protected function es_blanco(array $rgb)
    {
        return $rgb[0] > 240 && $rgb[1] > 240 && $rgb[2] > 240;
    }

    /**
     * @param  array  $rgb
     * @return bool
     */
    protected function es_rojo(array $rgb)
    {
        return $rgb[0] > 150 && $rgb[1] < 80 && $rgb[2] < 80;
    }

    /**
     * 🔴 El caso de Truvari: 500 × 500 exactos, entera y con fondo blanco, en el link y en la conversación.
     *
     * @test
     */
    public function la_foto_queda_exactamente_en_500_por_500_encuadrada_con_fondo_blanco()
    {
        list($conversation, $assistant) = $this->conversacion();
        $original = $this->foto_del_dueno($conversation, $this->comercio->id);

        $r = $this->herramienta($conversation, $assistant, 'ajustar_foto_a_medida', ['ancho' => 500, 'alto' => 500]);

        $this->assertTrue($r['ok'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame((int) $original->id, $r['imagen_original'], 'Sin imagen_id toma la última que mandó la persona.');
        $this->assertTrue($r['agrandada'], 'La foto de 300 px se agrandó para llenar el lado.');
        $this->assertStringContainsString('Nunca digas que no podés cambiar el tamaño', $r['nota']);

        // El archivo del link: 500 × 500, jpg.
        list($uuid, $token) = $this->partes_del_link($r['link']);
        $archivo = AiArchivo::where('uuid', $uuid)->firstOrFail();

        $this->assertSame(AiArchivo::TIPO_IMAGEN, $archivo->tipo);
        $this->assertSame((int) $this->comercio->id, (int) $archivo->user_id);
        $this->assertSame('asistente_archivos/' . $this->comercio->id . '/' . $uuid . '.jpg', $archivo->path);

        $del_link = Storage::disk(ArchivosDelAgenteIaHelper::DISCO)->get($archivo->path);
        $medidas = getimagesizefromstring($del_link);

        $this->assertSame(500, $medidas[0]);
        $this->assertSame(500, $medidas[1]);
        $this->assertSame('image/jpeg', $medidas['mime']);

        // Encuadrada: la foto (300 × 200) se escala a 500 × 333 y queda centrada; arriba y abajo, blanco.
        $this->assertTrue($this->es_blanco($this->color($del_link, 250, 10)), 'Arriba sobra: fondo blanco.');
        $this->assertTrue($this->es_blanco($this->color($del_link, 250, 490)), 'Abajo sobra: fondo blanco.');
        $this->assertTrue($this->es_rojo($this->color($del_link, 250, 250)), 'En el medio, la foto.');
        $this->assertTrue($this->es_rojo($this->color($del_link, 5, 250)), 'A los costados la foto llega al borde: no se deformó ni quedó chiquita.');

        // La imagen nueva de la conversación: también 500 × 500, colgada del mensaje del asistente.
        $nueva = AiMessageImagen::findOrFail($r['imagen_id']);

        $this->assertSame((int) $assistant->id, (int) $nueva->ai_message_id);
        $this->assertNull($nueva->gestionada_at);

        $medidas_nueva = getimagesizefromstring(AsistenteImagenHelper::binario($nueva));
        $this->assertSame(500, $medidas_nueva[0]);
        $this->assertSame(500, $medidas_nueva[1]);

        // El link baja la foto sin sesión, como imagen.
        $this->app['auth']->forgetGuards();

        $descarga = $this->get('/asistente/archivo/' . $uuid . '?t=' . $token);
        $descarga->assertStatus(200);
        $this->assertSame('image/jpeg', $descarga->headers->get('content-type'));

        $this->get('/asistente/archivo/' . $uuid . '?t=' . str_repeat('a', ArchivosDelAgenteIaHelper::LARGO_DEL_TOKEN))->assertStatus(404);
    }

    /**
     * Recortar llena todo el lienzo (sin blanco) y sigue siendo del tamaño exacto; un tamaño rectangular
     * también.
     *
     * @test
     */
    public function recortar_llena_el_lienzo_y_respeta_un_tamano_rectangular()
    {
        list($conversation, $assistant) = $this->conversacion();
        $this->foto_del_dueno($conversation, $this->comercio->id);

        $r = $this->herramienta($conversation, $assistant, 'ajustar_foto_a_medida', ['ancho' => 400, 'alto' => 600, 'modo' => 'recortar', 'formato' => 'png']);

        $this->assertTrue($r['ok'], json_encode($r, JSON_UNESCAPED_UNICODE));

        $archivo = AiArchivo::where('uuid', $this->partes_del_link($r['link'])[0])->firstOrFail();
        $binario = Storage::disk(ArchivosDelAgenteIaHelper::DISCO)->get($archivo->path);
        $medidas = getimagesizefromstring($binario);

        $this->assertSame([400, 600, 'image/png'], [$medidas[0], $medidas[1], $medidas['mime']]);
        $this->assertTrue($this->es_rojo($this->color($binario, 2, 2)), 'Recortada: la esquina es foto, no fondo.');
        $this->assertSame('image/png', $archivo->content_type());

        $nueva = getimagesizefromstring(AsistenteImagenHelper::binario(AiMessageImagen::findOrFail($r['imagen_id'])));
        $this->assertSame([400, 600], [$nueva[0], $nueva[1]]);
    }

    /**
     * Un imagen_id de OTRO negocio no se usa; un tamaño fuera de rango tampoco; sin foto, se dice.
     *
     * @test
     */
    public function una_foto_ajena_o_un_tamano_invalido_vuelven_con_error()
    {
        list($ajena_conv) = $this->conversacion($this->otro);
        $ajena = $this->foto_del_dueno($ajena_conv, $this->otro->id);

        list($conversation, $assistant) = $this->conversacion();

        $r = $this->herramienta($conversation, $assistant, 'ajustar_foto_a_medida', ['ancho' => 500, 'alto' => 500, 'imagen_id' => $ajena->id]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('No encontré esa foto', $r['error']);

        $sin_foto = $this->herramienta($conversation, $assistant, 'ajustar_foto_a_medida', ['ancho' => 500, 'alto' => 500]);
        $this->assertFalse($sin_foto['ok'], 'En esta conversación todavía no hay ninguna foto.');

        $this->foto_del_dueno($conversation, $this->comercio->id);

        $grande = $this->herramienta($conversation, $assistant, 'ajustar_foto_a_medida', ['ancho' => 9000, 'alto' => 500]);
        $this->assertFalse($grande['ok']);
        $this->assertStringContainsString('entre', $grande['error']);

        /*
         * Revisión independiente (19/11/2026): 3000 × 3000 daba un fatal de memoria en GD (no lo agarra ningún
         * catch y se caía el turno). Ahora el tope es 2000 y vuelve con un error prolijo, sin tocar GD.
         */
        $tres_mil = $this->herramienta($conversation, $assistant, 'ajustar_foto_a_medida', ['ancho' => 3000, 'alto' => 3000]);
        $this->assertFalse($tres_mil['ok']);
        $this->assertStringContainsString('2000', $tres_mil['error']);
        $this->assertSame(2000, \App\Http\Controllers\Helpers\asistente_ia\FotoAMedidaIaHelper::LADO_MAXIMO);

        $this->assertSame(0, AiArchivo::where('user_id', $this->comercio->id)->count(), 'Ningún error deja un archivo.');
    }

    /**
     * Revisión independiente (19/11/2026): el tope (2000 × 2000) anda con la memoria libre de un worker de 128M, y sin memoria
     * libre suficiente la herramienta corta ANTES de GD con un error prolijo (nunca un fatal).
     *
     * @test
     */
    public function el_tope_anda_con_la_memoria_de_un_worker_y_sin_memoria_corta_antes_de_gd()
    {
        $limite_antes = ini_get('memory_limit');

        try {
            /*
             * Lo libre que deja un worker con memory_limit = 128M (≈ 100 MB después de arrancar Laravel),
             * sobre lo que ya usa este proceso de PHPUnit: un límite fijo de 128M quedaría por debajo.
             */
            ini_set('memory_limit', (string) (memory_get_usage(true) + 100 * 1024 * 1024));

            list($conversation, $assistant) = $this->conversacion();
            $this->foto_del_dueno($conversation, $this->comercio->id);

            $r = $this->herramienta($conversation, $assistant, 'ajustar_foto_a_medida', ['ancho' => 2000, 'alto' => 2000]);
            $this->assertTrue($r['ok'], json_encode($r, JSON_UNESCAPED_UNICODE));

            $nueva = getimagesizefromstring(AsistenteImagenHelper::binario(AiMessageImagen::findOrFail($r['imagen_id'])));
            $this->assertSame([2000, 2000], [$nueva[0], $nueva[1]]);

            // Con un límite apenas por encima de lo que ya se usa, no alcanza: se dice, sin fatal.
            ini_set('memory_limit', (string) (memory_get_usage(true) + 4 * 1024 * 1024));

            $binario = (string) (new ImageManager())->canvas(300, 200, '#cc0000')->encode('webp', 90);
            $this->assertFalse(\App\Http\Controllers\Helpers\asistente_ia\FotoAMedidaIaHelper::alcanza_la_memoria($binario, 2000, 2000));

            $sin_memoria = $this->herramienta($conversation, $assistant, 'ajustar_foto_a_medida', ['ancho' => 2000, 'alto' => 2000]);
            $this->assertFalse($sin_memoria['ok']);
            $this->assertStringContainsString('más de lo que el sistema puede procesar', $sin_memoria['error']);
        } finally {
            ini_set('memory_limit', $limite_antes);
        }
    }

    /**
     * El imagen_id nuevo va a proponer_foto_articulo y el artículo queda con la foto de 500 × 500.
     *
     * @test
     */
    public function la_foto_ajustada_se_pone_en_un_articulo_que_ya_existe()
    {
        $malbec = Article::create(['name' => 'LAGARDE HERMANAS MALBEC M3', 'user_id' => $this->comercio->id, 'status' => 'active']);

        list($conversation, $assistant) = $this->conversacion();
        $this->foto_del_dueno($conversation, $this->comercio->id);

        $r = $this->herramienta($conversation, $assistant, 'ajustar_foto_a_medida', ['ancho' => 500, 'alto' => 500]);
        $this->assertTrue($r['ok']);

        $tarjeta = $this->herramienta($conversation, $assistant, 'proponer_foto_articulo', ['articulo_id' => $malbec->id, 'imagen_id' => $r['imagen_id']]);
        $this->assertTrue($tarjeta['ok'], json_encode($tarjeta, JSON_UNESCAPED_UNICODE));

        $this->actingAs($this->comercio);

        $resultado = EjecutorAccionesIaHelper::confirmar($conversation, (int) $tarjeta['tarjeta_id'], $this->comercio, function () {
            return 1;
        });

        $this->assertSame(200, $resultado['status'], json_encode($resultado, JSON_UNESCAPED_UNICODE));

        $imagen = Image::where('imageable_type', 'article')->where('imageable_id', $malbec->id)->firstOrFail();
        $nombre = basename((string) parse_url((string) $imagen->hosting_url, PHP_URL_PATH));
        $medidas = getimagesize(storage_path() . '/app/public/' . $nombre);

        $this->assertSame([500, 500], [$medidas[0], $medidas[1]], 'La foto del artículo es la ajustada, del tamaño pedido.');
    }

    /**
     * @test
     */
    public function la_herramienta_va_al_final_y_queda_fuera_del_mcp()
    {
        $this->assertTrue(HerramientasDeCarga::maneja('ajustar_foto_a_medida'));
        // asistente-diario-20261121 agrega proponer_destacado_articulo detrás: la foto a medida pasa a ser la anteúltima.
        $this->assertSame('ajustar_foto_a_medida', array_slice(HerramientasDeCarga::nombres(), -2, 1)[0]);
        $this->assertSame('proponer_destacado_articulo', array_slice(HerramientasDeCarga::nombres(), -1)[0]);
        $this->assertContains('ajustar_foto_a_medida', McpHerramientasHelper::EXCLUIDAS);
        $this->assertFalse(McpHerramientasHelper::existe('ajustar_foto_a_medida'));
    }

    /**
     * [uuid, token] de un link del agente.
     */
    protected function partes_del_link($link): array
    {
        $path = (string) parse_url((string) $link, PHP_URL_PATH);
        parse_str((string) parse_url((string) $link, PHP_URL_QUERY), $query);

        return [basename($path), isset($query['t']) ? (string) $query['t'] : ''];
    }
}
