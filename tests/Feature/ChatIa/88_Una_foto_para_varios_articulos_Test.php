<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\AsistenteImagenHelper;
use App\Http\Controllers\Helpers\asistente_ia\CategoriasDelNegocioIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\EjecutorAccionesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaFotoArticuloIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\Article;
use App\Models\Image;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * Misión agente-mejoras-uso-real (E1, puntos 1 y 2, 29/10/2026) — UNA FOTO PARA VARIOS ARTÍCULOS, y
 * la misma foto de nuevo sin pedírsela al dueño.
 *
 * 🔴 EL CASO REAL QUE FIJA (Ferre Total, CONV 15). Pablo mandó UNA foto: «ponele esta imagen a los
 * clavos punta París, que todos tengan la misma». Tenía 10 clavos punta París; el agente se la puso a
 * UNO, le dijo que «la foto ya quedó usada» y le pidió que la mandara otra vez por cada tamaño. Pablo
 * la reenvió, le dijo «la misma para los otros 8» y «tomá la misma imagen para todos», y el agente
 * siguió contestando que cada foto va a un solo artículo. Lo que este archivo fija:
 *
 *  - UNA tarjeta con los N artículos (`todos_los_que_coinciden` o `articulo_ids`), un ARCHIVO por
 *    artículo (ImageController borra el archivo al sacarle la foto a uno: compartirlo rompería a los
 *    otros) y el resultado con `hechos` / `no_hechos` por nombre.
 *  - Un artículo que se borró entre la tarjeta y el sí se saltea y se informa: no voltea a los demás.
 *  - Las tarjetas viejas (`{article_id, articulo, imagen_id}`) se siguen ejecutando.
 *  - La foto que ya se puso en un artículo de ESTA conversación se puede volver a usar por su
 *    imagen_id, y el historial le muestra ese id al modelo. Una foto de una compra con factura o una
 *    sellada sin haber ido a ningún artículo, no.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 *
 * @group chat-ia
 */
class Una_foto_para_varios_articulos_Test extends TestCase
{
    use DatabaseTransactions;

    /** Prefijo de los nombres de los artículos del test, para que la búsqueda no encuentre otros. */
    const PREFIJO = 'zz-e1';

    /** @var User */
    protected $comercio;

    /** Owners cuyas carpetas de fotos hay que limpiar en tearDown. */
    protected $owners_a_limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'             => 'Comercio foto varios E1',
            'company_name'     => 'Ferreteria foto varios E1',
            'email'            => 'foto-varios-e1-' . uniqid() . '@test.local',
            'password'         => Hash::make('secret'),
            'agente_confianza' => 'cauteloso',
        ]);

        $this->owners_a_limpiar[] = $this->comercio->id;
    }

    protected function tearDown(): void
    {
        /* Los webp que ejecutar() dejó en storage/app/public, uno por artículo. */
        foreach (Image::where('imageable_type', 'article')->whereIn('imageable_id', Article::where('user_id', $this->comercio->id)->withTrashed()->pluck('id'))->get() as $image) {
            $nombre = basename((string) parse_url((string) $image->hosting_url, PHP_URL_PATH));
            $ruta = storage_path() . '/app/public/' . $nombre;
            if ($nombre !== '' && is_file($ruta)) {
                @unlink($ruta);
            }
        }

        foreach ($this->owners_a_limpiar as $owner_id) {
            try {
                Storage::disk('local')->deleteDirectory('asistente_imagenes/' . $owner_id);
            } catch (\Throwable $e) {
                // best-effort
            }
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------- fixtures

    /**
     * Los diez clavos punta París de Ferre Total, con el prefijo del test.
     *
     * @return array<int, Article>
     */
    protected function diez_clavos()
    {
        $medidas = ['1"', '1 1/2"', '2"', '2 1/2"', '3"', '3 1/2"', '4"', '5"', '6"', '7"'];

        $clavos = [];

        foreach ($medidas as $medida) {
            $clavos[] = Article::create([
                'name'    => self::PREFIJO . ' CLAVOS PUNTA PARIS DE ' . $medida,
                'user_id' => $this->comercio->id,
                'status'  => 'active',
            ]);
        }

        return $clavos;
    }

    /**
     * @param  string  $nombre
     * @return Article
     */
    protected function articulo($nombre)
    {
        return Article::create(['name' => self::PREFIJO . ' ' . $nombre, 'user_id' => $this->comercio->id, 'status' => 'active']);
    }

    /**
     * @return AiConversation
     */
    protected function conversacion()
    {
        return AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);
    }

    /**
     * Un mensaje del dueño con `$fotos` fotos de verdad en el disco privado.
     *
     * @param  AiConversation  $conversation
     * @param  string  $texto
     * @param  int  $fotos
     * @return array{0: AiMessage, 1: array<int, AiMessageImagen>}
     */
    protected function del_dueno(AiConversation $conversation, $texto, $fotos = 1)
    {
        $mensaje = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $texto, 'estado' => 'listo']);

        $imagenes = [];

        for ($orden = 1; $orden <= $fotos; $orden++) {
            $binario = (string) (new ImageManager())->canvas(12, 12, '#0B84F8')->encode('png');
            $path = 'asistente_imagenes/' . $this->comercio->id . '/' . $mensaje->id . '/' . $orden . '.png';
            Storage::disk('local')->put($path, $binario);

            $imagenes[] = AiMessageImagen::create([
                'ai_message_id' => $mensaje->id,
                'user_id'       => $this->comercio->id,
                'orden'         => $orden,
                'path'          => $path,
                'mime'          => 'image/png',
                'bytes'         => strlen($binario),
            ]);
        }

        return [$mensaje, $imagenes];
    }

    /**
     * El assistant que propone, ya cerrado (la confirmación humana exige el mensaje 'listo').
     *
     * @param  AiConversation  $conversation
     * @return AiMessage
     */
    protected function del_asistente(AiConversation $conversation)
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
     * @return int
     */
    protected function fotos_de(Article $articulo)
    {
        return Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->count();
    }

    // ---------------------------------------------------------------------------------- tests

    /**
     * 🔴 CONV 15: una foto, «a los clavos punta París, que todos tengan la misma» → UNA tarjeta con
     * los diez, y al confirmar los diez quedan con su foto, cada uno con SU archivo.
     *
     * @test
     */
    public function una_foto_va_a_todos_los_que_coinciden_en_una_sola_tarjeta()
    {
        $clavos = $this->diez_clavos();
        $conversation = $this->conversacion();
        list(, $fotos) = $this->del_dueno($conversation, 'Esta imagen ponele a los clavos punta París, que todos tengan la misma');
        $assistant = $this->del_asistente($conversation);

        $respuesta = PropuestaFotoArticuloIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, [
            'articulo'                => self::PREFIJO . ' clavos punta paris',
            'todos_los_que_coinciden' => true,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(1, AiMessageAction::where('ai_conversation_id', $conversation->id)->count(), 'Una sola tarjeta para los diez.');

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertCount(10, $tarjeta->datos['article_ids']);
        $this->assertCount(10, $tarjeta->datos['articulos']);
        $this->assertSame((int) $fotos[0]->id, (int) $tarjeta->datos['imagen_id']);
        $this->assertFalse($tarjeta->datos['reusa_usada']);
        $this->assertSame('Foto de 10 artículos', $tarjeta->presentacion['titulo']);
        $this->assertStringContainsString('Artículos (10)', json_encode($tarjeta->presentacion, JSON_UNESCAPED_UNICODE));

        $ids = $tarjeta->datos['article_ids'];
        sort($ids);
        $this->assertSame('foto_articulo:' . sha1(implode(',', $ids)), $tarjeta->clave);

        $resultado = $this->confirmar($conversation, $respuesta['tarjeta_id']);
        $this->assertSame(200, $resultado['status'], json_encode($resultado['body']));

        $archivos = [];

        foreach ($clavos as $clavo) {
            $this->assertSame(1, $this->fotos_de($clavo), 'Le falta la foto a ' . $clavo->name);
            $image = Image::where('imageable_type', 'article')->where('imageable_id', $clavo->id)->first();
            $nombre = basename((string) parse_url((string) $image->hosting_url, PHP_URL_PATH));
            $this->assertFileExists(storage_path() . '/app/public/' . $nombre);
            $archivos[] = $nombre;
        }

        $this->assertCount(10, array_unique($archivos), '🔴 Un archivo por artículo: si lo compartieran, sacarle la foto a uno se la borraría a los demás.');
        $this->assertNotNull($fotos[0]->fresh()->gestionada_at, 'La foto queda sellada al final.');

        $res = AiMessageAction::find($respuesta['tarjeta_id'])->resultado;
        $this->assertCount(10, $res->hechos);
        $this->assertCount(0, $res->no_hechos);
        $this->assertStringContainsString('10 artículos', $res->texto);
    }

    /**
     * Un artículo borrado entre la tarjeta y el sí se saltea y se informa por nombre: los otros nueve
     * quedan con su foto y la transacción no se cae.
     *
     * @test
     */
    public function un_articulo_que_ya_no_existe_se_saltea_y_se_informa()
    {
        $clavos = $this->diez_clavos();
        $conversation = $this->conversacion();
        $this->del_dueno($conversation, 'Ponele esta a todos los clavos punta París');
        $assistant = $this->del_asistente($conversation);

        $respuesta = PropuestaFotoArticuloIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, [
            'articulo'                => self::PREFIJO . ' clavos punta paris',
            'todos_los_que_coinciden' => true,
        ]);
        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $borrado = $clavos[3];
        $borrado->delete();

        $resultado = $this->confirmar($conversation, $respuesta['tarjeta_id']);
        $this->assertSame(200, $resultado['status'], json_encode($resultado['body']));

        $con_foto = 0;
        foreach ($clavos as $clavo) {
            $con_foto += $this->fotos_de($clavo);
        }
        $this->assertSame(9, $con_foto);

        $res = AiMessageAction::find($respuesta['tarjeta_id'])->resultado;
        $this->assertCount(9, $res->hechos);
        $this->assertCount(1, $res->no_hechos);
        $this->assertSame((int) $borrado->id, (int) $res->no_hechos[0]->article_id);
        $this->assertStringContainsString($borrado->name, $res->texto, 'El texto dice cuál NO quedó, por nombre.');
    }

    /**
     * `articulo_ids` explícitos: la tarjeta lleva esos y nada más. Un id que no es del negocio corta
     * sin tarjeta (es para el modelo), y más de 30 también.
     *
     * @test
     */
    public function con_articulo_ids_van_esos_y_un_id_ajeno_o_de_mas_corta()
    {
        $a = $this->articulo('Martillo 500 g');
        $b = $this->articulo('Martillo 750 g');
        $c = $this->articulo('Maza 1 kg');
        $conversation = $this->conversacion();
        $this->del_dueno($conversation, 'Esta foto a los tres martillos');
        $assistant = $this->del_asistente($conversation);
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaFotoArticuloIaHelper::proponer($contexto, $assistant, ['articulo_ids' => [$a->id, $b->id, $c->id]]);
        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame([(int) $a->id, (int) $b->id, (int) $c->id], AiMessageAction::find($respuesta['tarjeta_id'])->datos['article_ids']);

        $ajeno = User::create(['name' => 'Otro E1', 'email' => 'otro-e1-' . uniqid() . '@test.local', 'password' => Hash::make('x')]);
        $de_otro = Article::create(['name' => 'Ajeno', 'user_id' => $ajeno->id, 'status' => 'active']);

        $con_ajeno = PropuestaFotoArticuloIaHelper::proponer($contexto, $assistant, ['articulo_ids' => [$a->id, $de_otro->id]]);
        $this->assertFalse($con_ajeno['ok']);
        $this->assertStringStartsWith(CategoriasDelNegocioIaHelper::NO_SE_ARMO, (string) $con_ajeno['error']);

        $muchos = range(1, PropuestaFotoArticuloIaHelper::TOPE_ARTICULOS + 1);
        $de_mas = PropuestaFotoArticuloIaHelper::proponer($contexto, $assistant, ['articulo_ids' => $muchos]);
        $this->assertFalse($de_mas['ok']);
        $this->assertStringContainsString((string) PropuestaFotoArticuloIaHelper::TOPE_ARTICULOS, (string) $de_mas['error']);
    }

    /**
     * 🔴 Compatibilidad: una tarjeta guardada con el formato de antes (`{article_id, articulo,
     * imagen_id}`) se sigue confirmando igual, y su resultado también trae hechos/no_hechos.
     *
     * @test
     */
    public function una_tarjeta_vieja_de_un_solo_articulo_se_sigue_ejecutando()
    {
        $articulo = $this->articulo('Taladro viejo');
        $conversation = $this->conversacion();
        list(, $fotos) = $this->del_dueno($conversation, 'Ponele esta foto');
        $assistant = $this->del_asistente($conversation);

        $vieja = AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $assistant->id,
            'user_id'            => $this->comercio->id,
            'auth_user_id'       => $this->comercio->id,
            'tipo'               => AiMessageAction::TIPO_FOTO_ARTICULO,
            'clave'              => 'foto_articulo:' . $articulo->id,
            'estado'             => AiMessageAction::ESTADO_PROPUESTA,
            'datos'              => ['article_id' => (int) $articulo->id, 'articulo' => $articulo->name, 'imagen_id' => (int) $fotos[0]->id],
            'presentacion'       => ['titulo' => 'Foto del artículo', 'renglones' => [], 'aviso' => null],
        ]);

        $resultado = $this->confirmar($conversation, $vieja->id);
        $this->assertSame(200, $resultado['status'], json_encode($resultado['body']));
        $this->assertSame(1, $this->fotos_de($articulo));
        $this->assertStringContainsString('Foto agregada al artículo', AiMessageAction::find($vieja->id)->resultado->texto);
    }

    /**
     * 🔴 CONV 15, segunda mitad: «la misma para los otros 8». La foto que ya se puso en un artículo de
     * esta conversación se vuelve a usar por su imagen_id, sin pedírsela de nuevo.
     *
     * @test
     */
    public function la_foto_ya_puesta_en_un_articulo_se_reusa_por_su_id_para_los_otros()
    {
        $clavos = $this->diez_clavos();
        $conversation = $this->conversacion();
        list(, $fotos) = $this->del_dueno($conversation, 'Ponele esta al de 1 1/2');
        $assistant = $this->del_asistente($conversation);
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $primera = PropuestaFotoArticuloIaHelper::proponer($contexto, $assistant, ['articulo_id' => $clavos[1]->id]);
        $this->assertTrue($primera['ok'], json_encode($primera));
        $this->assertSame(200, $this->confirmar($conversation, $primera['tarjeta_id'])['status']);
        $this->assertNotNull($fotos[0]->fresh()->gestionada_at);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'La misma para los otros 8', 'estado' => 'listo']);
        $otro_assistant = $this->del_asistente($conversation);

        $otros = [];
        foreach ($clavos as $i => $clavo) {
            if ($i !== 1) {
                $otros[] = (int) $clavo->id;
            }
        }

        $respuesta = PropuestaFotoArticuloIaHelper::proponer($contexto, $otro_assistant, ['articulo_ids' => $otros, 'imagen_id' => $fotos[0]->id]);
        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertTrue(AiMessageAction::find($respuesta['tarjeta_id'])->datos['reusa_usada']);

        $resultado = $this->confirmar($conversation, $respuesta['tarjeta_id']);
        $this->assertSame(200, $resultado['status'], json_encode($resultado['body']));

        foreach ($clavos as $clavo) {
            $this->assertSame(1, $this->fotos_de($clavo), $clavo->name);
        }
    }

    /**
     * Sin imagen_id y sin ninguna foto sin usar, pero con una ya puesta en un artículo de esta charla:
     * no se toma sola (podría ser otra), se le dice al MODELO con qué id volver a llamar.
     *
     * @test
     */
    public function sin_foto_nueva_le_ofrece_al_modelo_la_ya_usada_sin_tomarla_sola()
    {
        $a = $this->articulo('Pala ancha');
        $b = $this->articulo('Pala angosta');
        $conversation = $this->conversacion();
        list(, $fotos) = $this->del_dueno($conversation, 'Esta a la pala ancha');
        $assistant = $this->del_asistente($conversation);
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $primera = PropuestaFotoArticuloIaHelper::proponer($contexto, $assistant, ['articulo_id' => $a->id]);
        $this->assertSame(200, $this->confirmar($conversation, $primera['tarjeta_id'])['status']);

        $sin_id = PropuestaFotoArticuloIaHelper::proponer($contexto, $this->del_asistente($conversation), ['articulo_id' => $b->id]);
        $this->assertFalse($sin_id['ok']);
        $this->assertStringStartsWith(CategoriasDelNegocioIaHelper::NO_SE_ARMO, (string) $sin_id['error']);
        $this->assertSame((int) $fotos[0]->id, (int) $sin_id['opciones']['imagen_id']);
        $this->assertSame(0, $this->fotos_de($b));
    }

    /**
     * 🔴 No se reusa: la foto de una factura (entró en una compra con factura) ni una sellada que no
     * fue a ningún artículo. Una factura no puede terminar publicada en la tienda.
     *
     * @test
     */
    public function la_foto_de_una_compra_con_factura_no_se_reusa_en_un_articulo()
    {
        $articulo = $this->articulo('Destornillador');
        $conversation = $this->conversacion();
        list($mensaje, $fotos) = $this->del_dueno($conversation, 'Cargá la compra de Distribuidora Sur');
        $assistant = $this->del_asistente($conversation);

        AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $assistant->id,
            'user_id'            => $this->comercio->id,
            'auth_user_id'       => $this->comercio->id,
            'tipo'               => AiMessageAction::TIPO_COMPRA_CON_FACTURA,
            'clave'              => 'compra_con_factura:1',
            'estado'             => AiMessageAction::ESTADO_CONFIRMADA,
            'datos'              => ['imagen_ids' => [(int) $fotos[0]->id], 'imagen_ids_miradas' => [(int) $fotos[0]->id]],
            'presentacion'       => ['titulo' => 'Compra con factura', 'renglones' => [], 'aviso' => null],
            'resuelta_at'        => now(),
        ]);
        $fotos[0]->gestionada_at = now();
        $fotos[0]->save();

        $respuesta = PropuestaFotoArticuloIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $this->del_asistente($conversation), [
            'articulo_id' => $articulo->id,
            'imagen_id'   => $fotos[0]->id,
        ]);

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(0, AiMessageAction::where('tipo', AiMessageAction::TIPO_FOTO_ARTICULO)->where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * 🔴 Ronda de arreglos (30/10/2026), arreglo 10: si la ejecución falla A MITAD (acá, el quinto clavo
     * revienta al guardarse), no quedan webp huérfanos en el disco de los cuatro que ya habían pasado, y
     * la foto no queda sellada.
     *
     * @test
     */
    public function si_falla_a_mitad_no_quedan_archivos_huerfanos()
    {
        $clavos = $this->diez_clavos();
        $conversation = $this->conversacion();
        list(, $fotos) = $this->del_dueno($conversation, 'Ponele esta a todos los clavos punta París');
        $assistant = $this->del_asistente($conversation);

        $respuesta = PropuestaFotoArticuloIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, [
            'articulo'                => self::PREFIJO . ' clavos punta paris',
            'todos_los_que_coinciden' => true,
        ]);
        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $orden = AiMessageAction::find($respuesta['tarjeta_id'])->datos['article_ids'];
        $el_quinto = (int) $orden[4];

        Article::saving(function ($articulo) use ($el_quinto) {
            if ((int) $articulo->id === $el_quinto) {
                throw new \RuntimeException('falla de prueba al guardar el quinto clavo');
            }
        });

        $resultado = $this->confirmar($conversation, $respuesta['tarjeta_id']);
        $this->assertNotSame(200, $resultado['status']);

        foreach ($clavos as $clavo) {
            $this->assertSame([], glob(storage_path() . '/app/public/*_' . $clavo->id . '.webp'), 'Quedó un webp huérfano de ' . $clavo->name);
        }

        $this->assertNull($fotos[0]->fresh()->gestionada_at, 'La foto no se selló.');
    }

    /**
     * 🔴 Ronda de arreglos (30/10/2026), arreglo 9: la foto se bloquea solo si la compra con factura
     * donde aparece se CONFIRMÓ. Una tarjeta de compra cancelada (o reemplazada, o descartada) no se
     * llevó la foto a ningún lado: la foto que se puso en un artículo se sigue pudiendo reusar.
     *
     * @test
     */
    public function una_tarjeta_de_compra_cancelada_no_bloquea_el_reuso_de_la_foto()
    {
        $a = $this->articulo('Balde 10 L');
        $b = $this->articulo('Balde 12 L');
        $conversation = $this->conversacion();
        list(, $fotos) = $this->del_dueno($conversation, 'Esta al balde de 10');
        $assistant = $this->del_asistente($conversation);
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        foreach ([AiMessageAction::ESTADO_CANCELADA, AiMessageAction::ESTADO_REEMPLAZADA, AiMessageAction::ESTADO_DESCARTADA] as $i => $estado) {
            AiMessageAction::create([
                'ai_conversation_id' => $conversation->id,
                'ai_message_id'      => $assistant->id,
                'user_id'            => $this->comercio->id,
                'auth_user_id'       => $this->comercio->id,
                'tipo'               => AiMessageAction::TIPO_COMPRA_CON_FACTURA,
                'clave'              => 'compra_con_factura:e1r-' . $i,
                'estado'             => $estado,
                'datos'              => ['imagen_ids' => [(int) $fotos[0]->id], 'imagen_ids_miradas' => [(int) $fotos[0]->id]],
                'presentacion'       => ['titulo' => 'Compra con factura', 'renglones' => [], 'aviso' => null],
            ]);
        }

        $primera = PropuestaFotoArticuloIaHelper::proponer($contexto, $assistant, ['articulo_id' => $a->id]);
        $this->assertSame(200, $this->confirmar($conversation, $primera['tarjeta_id'])['status']);

        $reuso = PropuestaFotoArticuloIaHelper::proponer($contexto, $this->del_asistente($conversation), ['articulo_id' => $b->id, 'imagen_id' => $fotos[0]->id]);
        $this->assertTrue($reuso['ok'], json_encode($reuso));
        $this->assertTrue(AiMessageAction::find($reuso['tarjeta_id'])->datos['reusa_usada']);
    }

    /**
     * El historial le muestra al modelo el id de cada foto de los mensajes anteriores («[Foto adjunta]
     * [imagen_id N]»), y el de las fotos del mensaje actual cuando son varias.
     *
     * @test
     */
    public function el_historial_le_muestra_al_modelo_el_id_de_las_fotos()
    {
        $conversation = $this->conversacion();
        list(, $fotos) = $this->del_dueno($conversation, 'Ponele esta a los clavos');
        $this->del_asistente($conversation);
        list(, $nuevas) = $this->del_dueno($conversation, 'Y estas tres', 3);

        $payload = (new AsistenteIaService())->build_messages_payload($conversation);

        $this->assertTrue(is_string($payload[0]['content']));
        $this->assertStringContainsString('[Foto adjunta]', $payload[0]['content'], 'La marca de siempre sigue.');
        $this->assertStringContainsString('imagen_id ' . $fotos[0]->id, $payload[0]['content']);

        $ultimo = end($payload);
        $texto = '';
        foreach ($ultimo['content'] as $bloque) {
            if ($bloque['type'] === 'text') {
                $texto .= $bloque['text'];
            }
        }
        $this->assertStringContainsString('imagen_id ' . $nuevas[0]->id . ', ' . $nuevas[1]->id . ', ' . $nuevas[2]->id, $texto);
    }

    /**
     * Diez fotos en un mensaje se guardan todas, pero al modelo le viajan como mucho
     * MAX_FOTOS_AL_MODELO, y el texto le dice cuántas quedaron guardadas sin mostrarse.
     *
     * @test
     */
    public function al_modelo_le_viajan_como_mucho_las_fotos_del_tope()
    {
        $this->assertSame(10, AsistenteImagenHelper::MAX_IMAGENES);
        $this->assertSame(6, AsistenteImagenHelper::MAX_FOTOS_AL_MODELO);

        $conversation = $this->conversacion();
        list(, $fotos) = $this->del_dueno($conversation, 'Estas son las diez', 10);

        $payload = (new AsistenteIaService())->build_messages_payload($conversation);

        $ultimo = end($payload);
        $imagenes = 0;
        $texto = '';
        foreach ($ultimo['content'] as $bloque) {
            if ($bloque['type'] === 'image') {
                $imagenes++;
            } else {
                $texto .= $bloque['text'];
            }
        }

        $this->assertSame(6, $imagenes);
        $this->assertStringContainsString(', ' . $fotos[9]->id . ']', $texto, 'El id de las que no se mostraron también va.');
        $this->assertStringContainsString('las otras 4 quedaron guardadas', $texto);
        $this->assertSame(10, AiMessageImagen::where('user_id', $this->comercio->id)->count());
    }

    /**
     * La herramienta declara los parámetros nuevos AL FINAL de sus propiedades, y el prompt trae la
     * regla de UNA llamada para varios artículos y la de cerrar diciendo qué quedó hecho y qué no.
     *
     * @test
     */
    public function la_herramienta_y_el_prompt_traen_las_reglas_nuevas()
    {
        $definicion = null;
        foreach (HerramientasDeCarga::definiciones() as $una) {
            if ($una['name'] === 'proponer_foto_articulo') {
                $definicion = $una;
            }
        }

        $this->assertNotNull($definicion);
        $claves = array_keys($definicion['input_schema']['properties']);
        $this->assertSame(['articulo_ids', 'todos_los_que_coinciden'], array_slice($claves, -2), 'Al final, por la regla del prefijo del caché.');
        $this->assertStringContainsString('articulo_ids', $definicion['description']);

        $prompt = (new AsistenteIaService())->build_system_prompt($this->conversacion(), $this->comercio, true, true);
        $this->assertStringContainsString('UNA sola llamada con articulo_ids', $prompt);
        $this->assertStringContainsString('cuáles quedaron hechos y cuáles no', $prompt);
    }
}
