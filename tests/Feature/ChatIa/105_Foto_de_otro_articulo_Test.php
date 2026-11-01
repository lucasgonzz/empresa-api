<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\EjecutorAccionesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaFotoArticuloIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Image;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * Misión agente-mejoras-diario-30-10 (B3, 31/10/2026) — LA FOTO DE OTRO ARTÍCULO para muchos, y el «sin foto»
 * que lista a quiénes va.
 *
 * 🔴 EL CASO REAL (análisis diario del 30/10, problema #5; y el 29/10, #2). Pablo (Ferre Total): «tomá la
 * imagen del clavo de 1 1/2" y usala para todos». La herramienta de foto de artículo solo sabía tomar fotos
 * mandadas al chat (`ai_message_imagenes`); el agente contestaba que «no le llegó» ninguna foto y le pedía
 * que la mandara «de nuevo», cuando la foto ya estaba en el sistema, en otro artículo. Lo que este archivo fija:
 *
 *  - con `de_articulo_id` (o `de_articulo`) la foto sale de la PORTADA de ese artículo: UNA tarjeta con los
 *    destinos, el de origen excluido, que dice de qué artículo sale la foto;
 *  - al confirmar se COPIA el archivo, uno por artículo (ImageController borra por nombre de archivo:
 *    compartirlo haría que sacarle la foto a uno se la saque a todos), y el del origen queda intacto;
 *  - el origen con una URL externa o con el archivo ausente es un error, sin tarjeta;
 *  - `solo_sin_foto` deja afuera a los que ya tienen alguna foto;
 *  - el error «sin foto» pasa a `foto_por_llegar`: lista a quiénes va, trae `sin_foto` con el número que es,
 *    y no dice «no me llegó» ni «de nuevo».
 *
 * 🔴 Los archivos que crean los tests se borran en tearDown (los webp de las imágenes de los artículos del
 * comercio del test, incluido el del origen, y los sueltos que se registran a mano).
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 *
 * @group chat-ia
 */
class Foto_de_otro_articulo_Test extends TestCase
{
    use DatabaseTransactions;

    /** Prefijo de los nombres de los artículos del test, para que la búsqueda no encuentre otros. */
    const PREFIJO = 'zz-b3';

    /** Las nueve medidas de los clavos del test; la de origen es la segunda. */
    const MEDIDAS = ['1"', '1 1/2"', '2"', '2 1/2"', '3"', '3 1/2"', '4"', '5"', '6"'];

    /** @var User */
    protected $comercio;

    /** Archivos sueltos de storage/app/public que el test creó y que no cuelgan de una imagen. */
    protected $archivos_a_limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'             => 'Comercio foto de otro B3',
            'company_name'     => 'Ferreteria foto de otro B3',
            'email'            => 'foto-otro-b3-' . uniqid() . '@test.local',
            'password'         => Hash::make('secret'),
            'agente_confianza' => 'cauteloso',
        ]);
    }

    protected function tearDown(): void
    {
        /* Los webp de las imágenes de los artículos del comercio: los del origen y los que copió ejecutar(). */
        foreach (Image::where('imageable_type', 'article')->whereIn('imageable_id', Article::where('user_id', $this->comercio->id)->withTrashed()->pluck('id'))->get() as $image) {
            $this->archivos_a_limpiar[] = basename((string) parse_url((string) $image->hosting_url, PHP_URL_PATH));
        }

        foreach (array_unique($this->archivos_a_limpiar) as $nombre) {
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

    // ------------------------------------------------------------------------------- fixtures

    /**
     * Los nueve clavos punta París, con el prefijo del test, en el orden de MEDIDAS.
     *
     * @return array<int, Article>
     */
    protected function nueve_clavos()
    {
        $clavos = [];

        foreach (self::MEDIDAS as $medida) {
            $clavos[] = Article::create([
                'name'    => self::PREFIJO . ' CLAVOS PUNTA PARIS DE ' . $medida,
                'user_id' => $this->comercio->id,
                'status'  => 'active',
            ]);
        }

        return $clavos;
    }

    /**
     * Le cuelga al artículo una foto con un webp REAL en storage/app/public, como la deja la pantalla
     * (ImageController::setImage: `hosting_url` absoluta de esta instalación).
     *
     * @param  Article  $articulo
     * @return Image
     */
    protected function foto_real($articulo)
    {
        $nombre = 'zzb3_origen_' . uniqid() . '.webp';

        file_put_contents(storage_path() . '/app/public/' . $nombre, (string) (new ImageManager())->canvas(16, 16, '#C0392B')->encode('webp'));

        $this->archivos_a_limpiar[] = $nombre;

        return Image::create([
            'hosting_url'    => ApiUrlHelper::storage($nombre),
            'imageable_id'   => (int) $articulo->id,
            'imageable_type' => 'article',
        ]);
    }

    /**
     * Una fila de `images` sin archivo, para marcar que el artículo ya tiene foto (solo_sin_foto, sin_foto).
     *
     * @param  Article  $articulo
     * @return Image
     */
    protected function ya_tiene_foto($articulo)
    {
        return Image::create([
            'hosting_url'    => 'https://cdn.otro-host.test/fotos/zzb3_' . (int) $articulo->id . '.webp',
            'imageable_id'   => (int) $articulo->id,
            'imageable_type' => 'article',
        ]);
    }

    /**
     * La conversación con el pedido del dueño (sin fotos: la foto está en otro artículo) y el assistant
     * que propone, ya cerrado (la confirmación humana exige el mensaje 'listo').
     *
     * @param  string  $pedido
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($pedido)
    {
        $conversation = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $pedido, 'estado' => 'listo']);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'contenido'            => 'Te dejé la tarjeta.',
            'estado'               => 'listo',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * @param  AiConversation  $conversation
     * @param  int  $tarjeta_id
     * @return array
     */
    protected function confirmar(AiConversation $conversation, $tarjeta_id)
    {
        $this->actingAs($this->comercio);

        return EjecutorAccionesIaHelper::confirmar($conversation, (int) $tarjeta_id, $this->comercio, function () {
            return 1;
        });
    }

    /**
     * @param  Article  $articulo
     * @return \Illuminate\Support\Collection
     */
    protected function fotos_de(Article $articulo)
    {
        return Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->orderBy('id')->get();
    }

    /**
     * El archivo de storage/app/public de una imagen.
     *
     * @param  Image  $image
     * @return string
     */
    protected function archivo_de(Image $image)
    {
        return storage_path() . '/app/public/' . basename((string) parse_url((string) $image->hosting_url, PHP_URL_PATH));
    }

    /**
     * @param  AiConversation  $conversation
     * @return int
     */
    protected function tarjetas(AiConversation $conversation)
    {
        return AiMessageAction::where('ai_conversation_id', $conversation->id)->count();
    }

    // ---------------------------------------------------------------------------------- tests

    /**
     * 🔴 «Tomá la imagen del clavo de 1 1/2" y usala para todos»: UNA tarjeta con los otros ocho (el origen
     * afuera), y al confirmar los ocho quedan con SU archivo copiado y el del origen sigue intacto.
     *
     * @test
     */
    public function la_foto_de_un_articulo_va_a_los_otros_ocho_copiando_el_archivo()
    {
        $clavos = $this->nueve_clavos();
        $origen = $clavos[1];
        $foto_del_origen = $this->foto_real($origen);
        $archivo_del_origen = $this->archivo_de($foto_del_origen);
        $bytes_del_origen = file_get_contents($archivo_del_origen);

        list($conversation, $assistant) = $this->conversacion('Tomá la imagen del clavo de 1 1/2" y usala para todos los clavos punta París');

        $respuesta = PropuestaFotoArticuloIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, [
            'articulo'                => self::PREFIJO . ' clavos punta paris',
            'todos_los_que_coinciden' => true,
            'de_articulo_id'          => $origen->id,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $this->tarjetas($conversation), 'Una sola tarjeta.');

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);
        $datos = $tarjeta->datos;

        $this->assertCount(8, $datos['article_ids'], 'Los nueve menos el de origen.');
        $this->assertNotContains((int) $origen->id, $datos['article_ids'], 'El origen no es destino.');
        $this->assertSame('articulo', $datos['origen']);
        $this->assertSame((int) $foto_del_origen->id, (int) $datos['image_id']);
        $this->assertSame((int) $origen->id, (int) $datos['de_article_id']);
        $this->assertArrayNotHasKey('imagen_id', $datos, 'No es una foto del chat.');
        $this->assertSame('Foto de 8 artículos', $tarjeta->presentacion['titulo']);
        $renglon_de_la_foto = null;
        foreach ($tarjeta->presentacion['renglones'] as $renglon) {
            if ($renglon['etiqueta'] === 'Foto') {
                $renglon_de_la_foto = $renglon['valor'];
            }
        }
        $this->assertSame('La de ' . $origen->name . ' (su foto principal)', $renglon_de_la_foto, 'La tarjeta dice de qué artículo sale la foto.');
        $this->assertSame(ApiUrlHelper::url_publica_de_imagen($foto_del_origen->hosting_url), $tarjeta->presentacion['imagen_url']);

        // La respuesta lista a quiénes va y cuántos tienen foto.
        $this->assertSame(8, $respuesta['cuantos']);
        $this->assertSame(8, $respuesta['sin_foto']);
        $this->assertCount(8, $respuesta['articulos']);
        $this->assertFalse($respuesta['articulos'][0]['ya_tiene_foto']);

        $resultado = $this->confirmar($conversation, $respuesta['tarjeta_id']);
        $this->assertSame(200, $resultado['status'], json_encode($resultado['body'], JSON_UNESCAPED_UNICODE));

        $archivos = [];

        foreach ($clavos as $clavo) {

            if ((int) $clavo->id === (int) $origen->id) {
                continue;
            }

            $fotos = $this->fotos_de($clavo);
            $this->assertCount(1, $fotos, 'Le falta la foto a ' . $clavo->name);
            $this->assertFileExists($this->archivo_de($fotos[0]));
            $this->assertSame(0, mb_strpos($fotos[0]->hosting_url, ApiUrlHelper::storage('')), 'La URL es de esta instalación.');
            $archivos[] = $this->archivo_de($fotos[0]);
        }

        $this->assertCount(8, array_unique($archivos), '🔴 Un archivo por artículo: compartirlo haría que borrar la foto de uno se la saque a todos.');
        $this->assertNotContains($archivo_del_origen, $archivos, 'Se copia el archivo: no se reusa el del origen.');

        // El origen sigue con su única foto y su archivo intacto.
        $this->assertCount(1, $this->fotos_de($origen));
        $this->assertFileExists($archivo_del_origen);
        $this->assertSame($bytes_del_origen, file_get_contents($archivo_del_origen));

        $res = AiMessageAction::find($respuesta['tarjeta_id'])->resultado;
        $this->assertCount(8, $res->hechos);
        $this->assertCount(0, $res->no_hechos);
    }

    /**
     * Entre la tarjeta y el sí le sacaron la foto al origen: la confirmación corta con 422, sin colgarle nada a
     * nadie y sin dejar archivos.
     *
     * @test
     */
    public function si_la_foto_del_origen_se_borra_antes_del_si_no_se_pone_nada()
    {
        $clavos = $this->nueve_clavos();
        $foto_del_origen = $this->foto_real($clavos[1]);

        list($conversation, $assistant) = $this->conversacion('La del 1 1/2 para el de 2 y el de 3');

        $respuesta = PropuestaFotoArticuloIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, [
            'articulo_ids'   => [$clavos[2]->id, $clavos[4]->id],
            'de_articulo_id' => $clavos[1]->id,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta, JSON_UNESCAPED_UNICODE));

        $archivos_antes = count(glob(storage_path() . '/app/public/*'));

        // La pantalla le saca la foto: borra la fila y el archivo.
        @unlink($this->archivo_de($foto_del_origen));
        $foto_del_origen->delete();

        $resultado = $this->confirmar($conversation, $respuesta['tarjeta_id']);

        $this->assertSame(422, $resultado['status'], json_encode($resultado['body'], JSON_UNESCAPED_UNICODE));
        $this->assertCount(0, $this->fotos_de($clavos[2]));
        $this->assertCount(0, $this->fotos_de($clavos[4]));
        $this->assertSame($archivos_antes - 1, count(glob(storage_path() . '/app/public/*')), 'No quedó ningún archivo nuevo.');
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($respuesta['tarjeta_id'])->estado_guardado());
    }

    /**
     * El origen por su nombre (`de_articulo`), con destinos explícitos: también copia.
     *
     * @test
     */
    public function el_origen_por_su_nombre_tambien_sirve()
    {
        $clavos = $this->nueve_clavos();
        $this->foto_real($clavos[1]);

        list($conversation, $assistant) = $this->conversacion('La del clavo de 1 1/2 ponésela al de 2 y al de 3');

        $respuesta = PropuestaFotoArticuloIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, [
            'articulo_ids' => [$clavos[2]->id, $clavos[4]->id],
            'de_articulo'  => self::PREFIJO . ' CLAVOS PUNTA PARIS DE 1 1/2"',
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta, JSON_UNESCAPED_UNICODE));
        $this->assertSame((int) $clavos[1]->id, (int) AiMessageAction::find($respuesta['tarjeta_id'])->datos['de_article_id']);

        $this->assertSame(200, $this->confirmar($conversation, $respuesta['tarjeta_id'])['status']);
        $this->assertCount(1, $this->fotos_de($clavos[2]));
        $this->assertCount(1, $this->fotos_de($clavos[4]));
        $this->assertCount(0, $this->fotos_de($clavos[3]));
    }

    /**
     * El origen con una URL de otro host, con el archivo ausente o sin ninguna foto: error y cero tarjetas.
     *
     * @test
     */
    public function el_origen_externo_ausente_o_sin_foto_es_un_error_sin_tarjeta()
    {
        $clavos = $this->nueve_clavos();
        $contexto_de = function ($conversation) {
            return ContextoDeCargaIa::de_la_conversacion($conversation);
        };

        // URL externa (otro host): no hay archivo de este frente que copiar.
        $externa = Image::create([
            'hosting_url'    => 'https://cdn.otro-host.test/fotos/clavo.webp',
            'imageable_id'   => (int) $clavos[1]->id,
            'imageable_type' => 'article',
        ]);

        list($conversation, $assistant) = $this->conversacion('Usá la del clavo de 1 1/2 para todos');

        $respuesta = PropuestaFotoArticuloIaHelper::proponer($contexto_de($conversation), $assistant, [
            'articulo' => self::PREFIJO . ' clavos punta paris', 'todos_los_que_coinciden' => true, 'de_articulo_id' => $clavos[1]->id,
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('no está guardada en este sistema', $respuesta['error']);
        $this->assertSame(0, $this->tarjetas($conversation));

        // Archivo ausente: la fila apunta a storage de esta instalación, pero el archivo no está.
        $externa->hosting_url = ApiUrlHelper::storage('zzb3_no_existe_' . uniqid() . '.webp');
        $externa->save();

        $ausente = PropuestaFotoArticuloIaHelper::proponer($contexto_de($conversation), $assistant, [
            'articulo' => self::PREFIJO . ' clavos punta paris', 'todos_los_que_coinciden' => true, 'de_articulo_id' => $clavos[1]->id,
        ]);

        $this->assertFalse($ausente['ok']);
        $this->assertStringContainsString('no encuentro el archivo', $ausente['error']);
        $this->assertSame(0, $this->tarjetas($conversation));

        // Sin ninguna foto.
        $sin_foto = PropuestaFotoArticuloIaHelper::proponer($contexto_de($conversation), $assistant, [
            'articulo_ids' => [$clavos[2]->id], 'de_articulo_id' => $clavos[3]->id,
        ]);

        $this->assertFalse($sin_foto['ok']);
        $this->assertStringContainsString('no tiene ninguna foto', $sin_foto['error']);
        $this->assertSame(0, $this->tarjetas($conversation));

        // Un origen de otro negocio, tampoco.
        $ajeno = User::create(['name' => 'Otro B3', 'email' => 'otro-b3-' . uniqid() . '@test.local', 'password' => Hash::make('x')]);
        $de_otro = Article::create(['name' => 'Ajeno B3', 'user_id' => $ajeno->id, 'status' => 'active']);

        $otro = PropuestaFotoArticuloIaHelper::proponer($contexto_de($conversation), $assistant, [
            'articulo_ids' => [$clavos[2]->id], 'de_articulo_id' => $de_otro->id,
        ]);

        $this->assertFalse($otro['ok']);
        $this->assertSame(0, $this->tarjetas($conversation));
    }

    /**
     * `solo_sin_foto`: la tarjeta lleva solo a los que todavía no tienen ninguna foto.
     *
     * @test
     */
    public function solo_sin_foto_deja_afuera_a_los_que_ya_tienen()
    {
        $clavos = $this->nueve_clavos();
        $this->foto_real($clavos[1]);
        $this->ya_tiene_foto($clavos[0]);
        $this->ya_tiene_foto($clavos[5]);
        $this->ya_tiene_foto($clavos[8]);

        list($conversation, $assistant) = $this->conversacion('Usá la del clavo de 1 1/2 para los que no tienen foto');

        $respuesta = PropuestaFotoArticuloIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, [
            'articulo'                => self::PREFIJO . ' clavos punta paris',
            'todos_los_que_coinciden' => true,
            'de_articulo_id'          => $clavos[1]->id,
            'solo_sin_foto'           => true,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta, JSON_UNESCAPED_UNICODE));

        $ids = AiMessageAction::find($respuesta['tarjeta_id'])->datos['article_ids'];

        $this->assertCount(5, $ids, 'Ocho destinos menos los tres que ya tenían foto.');

        foreach ([$clavos[0], $clavos[1], $clavos[5], $clavos[8]] as $afuera) {
            $this->assertNotContains((int) $afuera->id, $ids, $afuera->name . ' no va.');
        }

        /*
         * La respuesta cuenta lo que va en la tarjeta. Ronda de arreglos (F3, 31/10/2026): antes contaba los ocho
         * destinos pedidos (`cuantos` 8) aunque la tarjeta llevara cinco; ahora el resumen se arma después del
         * filtro (este test es de la misma misión y no salió: se ajusta porque el diseño cambió).
         */
        $this->assertSame(5, $respuesta['cuantos']);
        $this->assertSame(5, $respuesta['sin_foto']);
        $this->assertCount(5, $respuesta['articulos']);

        // Sin solo_sin_foto, van los ocho.
        list($conversation2, $assistant2) = $this->conversacion('Usá la del clavo de 1 1/2 para todos');

        $todos = PropuestaFotoArticuloIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation2), $assistant2, [
            'articulo'                => self::PREFIJO . ' clavos punta paris',
            'todos_los_que_coinciden' => true,
            'de_articulo_id'          => $clavos[1]->id,
        ]);

        $this->assertCount(8, AiMessageAction::find($todos['tarjeta_id'])->datos['article_ids']);

        // Si todos ya tienen foto, no hay tarjeta.
        list($conversation3, $assistant3) = $this->conversacion('A los que no tienen foto');

        $ninguno = PropuestaFotoArticuloIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation3), $assistant3, [
            'articulo_ids'   => [$clavos[0]->id, $clavos[5]->id],
            'de_articulo_id' => $clavos[1]->id,
            'solo_sin_foto'  => true,
        ]);

        $this->assertFalse($ninguno['ok']);
        $this->assertSame(0, $ninguno['sin_foto']);
        $this->assertSame(0, $this->tarjetas($conversation3));
    }

    /**
     * 🔴 Sin foto en el chat y sin origen: `foto_por_llegar`, con a quiénes va y `sin_foto` con el número que
     * es, sin «no me llegó» ni «de nuevo».
     *
     * @test
     */
    public function sin_foto_el_error_dice_a_quienes_va_y_cuantos_no_tienen()
    {
        $clavos = $this->nueve_clavos();
        $this->ya_tiene_foto($clavos[0]);
        $this->ya_tiene_foto($clavos[1]);

        list($conversation, $assistant) = $this->conversacion('Ponele foto a todos los clavos punta París');

        $respuesta = PropuestaFotoArticuloIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, [
            'articulo'                => self::PREFIJO . ' clavos punta paris',
            'todos_los_que_coinciden' => true,
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertSame(PropuestaFotoArticuloIaHelper::CODIGO_FOTO_POR_LLEGAR, $respuesta['codigo']);
        $this->assertSame('foto_por_llegar', $respuesta['codigo']);
        $this->assertSame(9, $respuesta['cuantos']);
        $this->assertSame(7, $respuesta['sin_foto']);
        $this->assertCount(9, $respuesta['articulos']);

        $por_id = [];
        foreach ($respuesta['articulos'] as $articulo) {
            $por_id[(int) $articulo['article_id']] = $articulo['ya_tiene_foto'];
        }
        $this->assertTrue($por_id[(int) $clavos[0]->id]);
        $this->assertFalse($por_id[(int) $clavos[2]->id]);

        $error = $respuesta['error'];

        $this->assertStringContainsString('mandame la foto y se la pongo a estos 9:', mb_strtolower($error));
        $this->assertStringContainsString($clavos[4]->name, $error, 'Lista los artículos por nombre.');
        $this->assertStringContainsString('de_articulo_id', $error, 'Le dice al modelo cómo usar la foto de otro artículo.');
        $this->assertStringNotContainsString('no me lleg', mb_strtolower($error));
        $this->assertStringNotContainsString('de nuevo', mb_strtolower($error));
        $this->assertSame(0, $this->tarjetas($conversation));

        // Con solo_sin_foto, la lista son los siete sin foto.
        $solo = PropuestaFotoArticuloIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, [
            'articulo'                => self::PREFIJO . ' clavos punta paris',
            'todos_los_que_coinciden' => true,
            'solo_sin_foto'           => true,
        ]);

        $this->assertSame('foto_por_llegar', $solo['codigo']);
        $this->assertSame(7, $solo['sin_foto']);
        $this->assertStringContainsString('a estos 7:', mb_strtolower($solo['error']));
        $this->assertStringNotContainsString($clavos[0]->name, $solo['error']);
    }

    /**
     * `$cuantos` artículos «zz-b3 CLAVO n mm» del comercio del test, en orden.
     *
     * @param  int  $cuantos
     * @return array<int, Article>
     */
    protected function muchos_clavos($cuantos)
    {
        $clavos = [];

        for ($i = 1; $i <= $cuantos; $i++) {
            $clavos[] = Article::create([
                'name'    => self::PREFIJO . ' CLAVO ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . ' mm',
                'user_id' => $this->comercio->id,
                'status'  => 'active',
            ]);
        }

        return $clavos;
    }

    /**
     * 🔴 F2 de la ronda de arreglos (31/10/2026): 45 «clavo», el origen con foto y solo 8 sin foto. Con
     * todos_los_que_coinciden + solo_sin_foto, el filtro va en la consulta ANTES del tope: la tarjeta lleva los 8
     * (antes cortaba con «Hay más de 30 artículos…»). Y sin solo_sin_foto, el origen no cuenta para el tope: con
     * 31 que encajan, origen incluido, van los otros 30.
     *
     * @test
     */
    public function solo_sin_foto_filtra_en_la_consulta_antes_del_tope_y_el_origen_no_cuenta()
    {
        $clavos = $this->muchos_clavos(45);
        $origen = $clavos[0];
        $this->foto_real($origen);

        // Del 2 al 37 ya tienen foto (36); del 38 al 45 no (8).
        for ($i = 1; $i <= 36; $i++) {
            $this->ya_tiene_foto($clavos[$i]);
        }

        list($conversation, $assistant) = $this->conversacion('Usá la del clavo de 1 mm para todos los clavos que no tienen foto');

        $respuesta = PropuestaFotoArticuloIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, [
            'articulo'                => self::PREFIJO . ' clavo',
            'todos_los_que_coinciden' => true,
            'de_articulo_id'          => $origen->id,
            'solo_sin_foto'           => true,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta, JSON_UNESCAPED_UNICODE));

        $datos = AiMessageAction::find($respuesta['tarjeta_id'])->datos;

        $this->assertCount(8, $datos['article_ids']);
        $this->assertSame(array_map(function ($clavo) {
            return (int) $clavo->id;
        }, array_slice($clavos, 37)), array_map('intval', $datos['article_ids']), 'Los 8 sin foto, en orden de nombre.');
        $this->assertSame(8, $respuesta['cuantos']);
        $this->assertSame(8, $respuesta['sin_foto']);
        $this->assertTrue($datos['solo_sin_foto'], 'F4: viaja en datos.');

        // Sin solo_sin_foto, 31 que encajan con el origen adentro: van los otros 30, sin cortar por el tope.
        list($conversation2, $assistant2) = $this->conversacion('Usá la del clavo de 1 mm para todos');

        Article::whereIn('id', array_map(function ($clavo) {
            return (int) $clavo->id;
        }, array_slice($clavos, 31)))->delete();

        $treinta = PropuestaFotoArticuloIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation2), $assistant2, [
            'articulo'                => self::PREFIJO . ' clavo',
            'todos_los_que_coinciden' => true,
            'de_articulo_id'          => $origen->id,
        ]);

        $this->assertTrue($treinta['ok'], json_encode($treinta, JSON_UNESCAPED_UNICODE));
        $this->assertCount(30, AiMessageAction::find($treinta['tarjeta_id'])->datos['article_ids']);
        $this->assertNotContains((int) $origen->id, AiMessageAction::find($treinta['tarjeta_id'])->datos['article_ids']);
    }

    /**
     * F2: por ids, el origen tampoco cuenta para el tope y con solo_sin_foto el tope se mira después de filtrar.
     *
     * @test
     */
    public function por_ids_el_origen_y_los_que_tienen_foto_no_cuentan_para_el_tope()
    {
        $clavos = $this->muchos_clavos(35);
        $origen = $clavos[0];
        $this->foto_real($origen);

        for ($i = 1; $i <= 10; $i++) {
            $this->ya_tiene_foto($clavos[$i]);
        }

        $ids = array_map(function ($clavo) {
            return (int) $clavo->id;
        }, $clavos);

        list($conversation, $assistant) = $this->conversacion('La del 1 mm para los que no tienen foto');

        // 35 ids: el origen + 10 con foto + 24 sin foto → 24 en la tarjeta.
        $respuesta = PropuestaFotoArticuloIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, [
            'articulo_ids'   => $ids,
            'de_articulo_id' => $origen->id,
            'solo_sin_foto'  => true,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta, JSON_UNESCAPED_UNICODE));
        $this->assertCount(24, AiMessageAction::find($respuesta['tarjeta_id'])->datos['article_ids']);
        $this->assertSame(24, $respuesta['cuantos']);

        // 31 ids con el origen adentro, sin solo_sin_foto: van 30.
        list($conversation2, $assistant2) = $this->conversacion('La del 1 mm para estos 30');

        $treinta = PropuestaFotoArticuloIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation2), $assistant2, [
            'articulo_ids'   => array_slice($ids, 0, 31),
            'de_articulo_id' => $origen->id,
        ]);

        $this->assertTrue($treinta['ok'], json_encode($treinta, JSON_UNESCAPED_UNICODE));
        $this->assertCount(30, AiMessageAction::find($treinta['tarjeta_id'])->datos['article_ids']);
    }

    /**
     * 🔴 F3 de la ronda de arreglos (31/10/2026): 12 destinos, 7 con foto, solo_sin_foto → la respuesta cuenta
     * los 5 de la tarjeta (`cuantos` 5), y lo mismo la lista de foto_por_llegar.
     *
     * @test
     */
    public function el_resumen_cuenta_lo_que_va_en_la_tarjeta_despues_del_filtro()
    {
        $clavos = $this->muchos_clavos(13);
        $origen = $clavos[0];
        $this->foto_real($origen);

        $destinos = array_slice($clavos, 1);

        for ($i = 0; $i < 7; $i++) {
            $this->ya_tiene_foto($destinos[$i]);
        }

        $ids = array_map(function ($clavo) {
            return (int) $clavo->id;
        }, $destinos);

        list($conversation, $assistant) = $this->conversacion('La del 1 mm para estos, solo a los que no tienen foto');

        $respuesta = PropuestaFotoArticuloIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, [
            'articulo_ids'   => $ids,
            'de_articulo_id' => $origen->id,
            'solo_sin_foto'  => true,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta, JSON_UNESCAPED_UNICODE));
        $this->assertSame(5, $respuesta['cuantos']);
        $this->assertSame(5, $respuesta['sin_foto']);
        $this->assertCount(5, $respuesta['articulos']);
        $this->assertSame(array_map('intval', AiMessageAction::find($respuesta['tarjeta_id'])->datos['article_ids']), array_map(function ($fila) {
            return (int) $fila['article_id'];
        }, $respuesta['articulos']), 'Los mismos que la tarjeta.');

        // Sin origen ni foto del chat: foto_por_llegar con los 5.
        list($conversation2, $assistant2) = $this->conversacion('Ponele foto a estos, solo a los que no tienen');

        $por_llegar = PropuestaFotoArticuloIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation2), $assistant2, [
            'articulo_ids'  => $ids,
            'solo_sin_foto' => true,
        ]);

        $this->assertSame('foto_por_llegar', $por_llegar['codigo']);
        $this->assertSame(5, $por_llegar['cuantos']);
        $this->assertCount(5, $por_llegar['articulos']);
        $this->assertStringContainsString('a estos 5:', mb_strtolower($por_llegar['error']));
    }

    /**
     * 🔴 F4 de la ronda de arreglos (31/10/2026): con solo_sin_foto, el destino que recibió una foto entre la
     * tarjeta y el sí se saltea (no se le suma otra) y el resultado lo dice «ya tenía foto»; si ya la tienen
     * todos, la confirmación corta sin escribir nada.
     *
     * @test
     */
    public function al_confirmar_se_saltea_el_que_recibio_foto_despues_de_la_tarjeta()
    {
        $clavos = $this->nueve_clavos();
        $this->foto_real($clavos[1]);

        list($conversation, $assistant) = $this->conversacion('La del 1 1/2 para el de 2, el de 3 y el de 4, solo si no tienen foto');

        $respuesta = PropuestaFotoArticuloIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, [
            'articulo_ids'   => [$clavos[2]->id, $clavos[4]->id, $clavos[6]->id],
            'de_articulo_id' => $clavos[1]->id,
            'solo_sin_foto'  => true,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta, JSON_UNESCAPED_UNICODE));
        $this->assertTrue(AiMessageAction::find($respuesta['tarjeta_id'])->datos['solo_sin_foto']);

        // Entre la tarjeta y el sí, la pantalla le pone una foto al de 3.
        $this->ya_tiene_foto($clavos[4]);

        $resultado = $this->confirmar($conversation, $respuesta['tarjeta_id']);

        $this->assertSame(200, $resultado['status'], json_encode($resultado['body'], JSON_UNESCAPED_UNICODE));
        $this->assertCount(1, $this->fotos_de($clavos[2]));
        $this->assertCount(1, $this->fotos_de($clavos[4]), 'El que ya tenía foto se saltea: sigue con la suya sola.');
        $this->assertCount(1, $this->fotos_de($clavos[6]));

        $res = AiMessageAction::find($respuesta['tarjeta_id'])->resultado;

        $this->assertCount(2, $res->hechos);
        $this->assertCount(1, $res->no_hechos);
        $this->assertSame(PropuestaFotoArticuloIaHelper::MOTIVO_YA_TENIA_FOTO, $res->no_hechos[0]->motivo);
        $this->assertStringContainsString('No se la puse a 1 porque ya tenía foto: ' . $clavos[4]->name, $res->texto);
        $this->assertStringNotContainsString('NO se la pude poner', $res->texto, 'No es una falla.');

        // Si ya la tienen todos: corta sin escribir nada.
        list($conversation2, $assistant2) = $this->conversacion('La del 1 1/2 para el de 5, si no tiene');

        $otra = PropuestaFotoArticuloIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation2), $assistant2, [
            'articulo_ids'   => [$clavos[7]->id],
            'de_articulo_id' => $clavos[1]->id,
            'solo_sin_foto'  => true,
        ]);

        $this->assertTrue($otra['ok'], json_encode($otra, JSON_UNESCAPED_UNICODE));

        $this->ya_tiene_foto($clavos[7]);

        $corte = $this->confirmar($conversation2, $otra['tarjeta_id']);

        $this->assertSame(422, $corte['status'], json_encode($corte['body'], JSON_UNESCAPED_UNICODE));
        $this->assertCount(1, $this->fotos_de($clavos[7]));
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($otra['tarjeta_id'])->estado_guardado());
    }

    /**
     * Las propiedades nuevas van AL FINAL de las de proponer_foto_articulo (prefijo del caché), y la
     * herramienta se despacha igual por HerramientasDeCarga.
     *
     * @test
     */
    public function las_propiedades_nuevas_van_al_final_y_el_despacho_las_usa()
    {
        $definicion = null;

        foreach (HerramientasDeCarga::definiciones() as $una) {
            if ($una['name'] === 'proponer_foto_articulo') {
                $definicion = $una;
            }
        }

        $this->assertNotNull($definicion);

        $propiedades = array_keys($definicion['input_schema']['properties']);

        $this->assertSame(
            ['articulo', 'articulo_id', 'imagen_id', 'reemplaza_a', 'articulo_ids', 'todos_los_que_coinciden', 'de_articulo_id', 'de_articulo', 'solo_sin_foto'],
            $propiedades
        );
        $this->assertSame('integer', $definicion['input_schema']['properties']['de_articulo_id']['type']);
        $this->assertSame('boolean', $definicion['input_schema']['properties']['solo_sin_foto']['type']);

        // Por el despacho real (el de la herramienta del modelo).
        $clavos = $this->nueve_clavos();
        $this->foto_real($clavos[1]);

        list($conversation, $assistant) = $this->conversacion('Usá la del 1 1/2 para el de 2');

        $resultado = HerramientasDeCarga::ejecutar('proponer_foto_articulo', [
            'articulo_ids'   => [$clavos[2]->id],
            'de_articulo_id' => $clavos[1]->id,
        ], $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], $resultado['content']);

        $datos = json_decode($resultado['content'], true);

        $this->assertTrue($datos['ok'], $resultado['content']);
        $this->assertSame('articulo', AiMessageAction::find($datos['tarjeta_id'])->datos['origen']);
    }
}
