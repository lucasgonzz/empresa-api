<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\EjecutorAccionesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaFotoArticuloIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\Article;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\Image;
use App\Models\InventoryLinkage;
use App\Models\InventoryLinkageScope;
use App\Models\SyncToMeliArticle;
use App\Models\SyncToTNArticle;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use App\Services\MercadoLibre\ProductService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * Misión asistente-ventas-y-fotos (21/9/2026), bloque B — la foto que el asistente le cuelga a un
 * ARTÍCULO.
 *
 * Lo que protege, y cada cosa está acá porque romperla no se nota mirando la pantalla:
 *
 *  - Que un nombre inexacto que encaja con varios artículos PREGUNTE cuál (`faltan`) en vez de
 *    agarrar el primero: el destino de esta foto se publica.
 *  - Que el archivo se guarde como **webp** y no como png. El png es la excepción de los logos que
 *    imprime FPDF (`user` y `address`), no el formato de un artículo.
 *  - Que dispare **los cuatro efectos** de ImageController::setImage() y no solo la fila de
 *    `images`: la vinculación de inventarios, `needs_sync_with_tn` con el `updated_at` bumpeado,
 *    Mercado Libre y Tienda Nube. Sin ellos la foto queda en el sistema y no llega a la tienda.
 *  - Que **no se auto-confirme** aunque el dueño esté en "resuelto" (decisión de Lucas, 21/9/2026).
 *
 * 🔴 Las fotos se siembran con `imageable_type = 'article'`, el alias del morph map: con la clase
 * la fila se inserta igual y la relación devuelve vacío en silencio.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 *
 * @group chat-ia
 */
class Foto_de_articulo_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    /** Owners cuyas carpetas de fotos hay que limpiar en tearDown. */
    protected $owners_a_limpiar = [];

    /** Nombres de archivo que ejecutar() dejó en storage/app/public. */
    protected $archivos_a_limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'             => 'Comercio foto art B',
            'company_name'     => 'Ferreteria foto art B',
            'email'            => 'foto-art-b-' . uniqid() . '@test.local',
            'password'         => Hash::make('secret'),
            'agente_confianza' => 'cauteloso',
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado foto art B',
            'email'    => 'foto-art-b-emp-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->owners_a_limpiar as $owner_id) {
            try {
                Storage::disk('local')->deleteDirectory('asistente_imagenes/' . $owner_id);
            } catch (\Throwable $e) {
                // best-effort
            }
        }

        foreach ($this->archivos_a_limpiar as $nombre) {
            $ruta = storage_path() . '/app/public/' . $nombre;
            if ($nombre !== '' && is_file($ruta)) {
                @unlink($ruta);
            }
        }

        parent::tearDown();
    }

    /**
     * Conversación con un mensaje del usuario que trae una foto sin gestionar y el assistant que
     * propone. `$estado_assistant` es 'listo' por defecto porque la confirmación humana exige que
     * el mensaje que propuso ya esté cerrado (EjecutorAccionesIaHelper::verificar_que_siga_propuesta).
     *
     * @param  \App\Models\User|null  $persona  auth_user_id de la conversación (default: el dueño).
     * @param  bool  $con_foto
     * @param  string  $estado_assistant
     * @return array{0: AiConversation, 1: AiMessage, 2: AiMessageImagen|null}
     */
    protected function conversacion_con_foto($persona = null, $con_foto = true, $estado_assistant = 'listo')
    {
        $persona = is_null($persona) ? $this->comercio : $persona;

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $persona->id,
        ]);

        $user_message = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Ponele esta foto al artículo',
            'estado'             => 'listo',
        ]);

        $imagen = null;

        if ($con_foto) {
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
        }

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => $estado_assistant,
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant, $imagen];
    }

    /**
     * @param  string  $nombre
     * @param  array  $extra
     * @return Article
     */
    protected function articulo($nombre, array $extra = [])
    {
        return Article::create(array_merge([
            'name'    => $nombre,
            'user_id' => $this->comercio->id,
            'status'  => 'active',
        ], $extra));
    }

    /**
     * Confirma la tarjeta como lo hace el botón: autenticado como la persona y por el ejecutor.
     *
     * @param  AiConversation  $conversation
     * @param  int  $tarjeta_id
     * @param  \App\Models\User|null  $persona
     * @return array  ['status' => int, 'body' => array]
     */
    protected function confirmar(AiConversation $conversation, $tarjeta_id, $persona = null)
    {
        $persona = is_null($persona) ? $this->comercio : $persona;

        $this->actingAs($persona);

        return EjecutorAccionesIaHelper::confirmar($conversation, (int) $tarjeta_id, $persona, function () {
            return 1;
        });
    }

    /**
     * El nombre del archivo que quedó colgado del artículo, para poder borrarlo en tearDown.
     *
     * @param  Image  $image
     * @return string
     */
    protected function nombre_de_archivo(Image $image)
    {
        $nombre = basename((string) parse_url((string) $image->hosting_url, PHP_URL_PATH));

        $this->archivos_a_limpiar[] = $nombre;

        return $nombre;
    }

    /**
     * Un nombre que encaja con dos artículos no elige por su cuenta: devuelve `faltan` con los
     * candidatos para que el modelo pregunte cuál. Es la guarda que evita publicar una foto en el
     * artículo equivocado.
     *
     * @test
     */
    public function un_nombre_ambiguo_pide_desambiguar_con_los_candidatos()
    {
        $uno = $this->articulo('zz-b44 Destornillador Phillips chico');
        $dos = $this->articulo('zz-b44 Destornillador Phillips grande');

        list($conversation, $assistant) = $this->conversacion_con_foto();

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);
        $respuesta = PropuestaFotoArticuloIaHelper::proponer($contexto, $assistant, ['articulo' => 'zz-b44 Destornillador Phillips']);

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertNull($respuesta['error']);
        $this->assertCount(1, $respuesta['faltan']);

        $ids = [];

        foreach ($respuesta['opciones']['articulos'] as $opcion) {
            $ids[] = (int) $opcion['articulo_id'];
        }

        sort($ids);

        $this->assertSame([(int) $uno->id, (int) $dos->id], $ids);

        // Y no dejó ninguna tarjeta: no hay nada que confirmar todavía.
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * El nombre escrito completo le gana a los parciales: "Phillips chico" es uno solo aunque
     * "Phillips" encaje con dos.
     *
     * @test
     */
    public function el_nombre_exacto_gana_sobre_los_parciales()
    {
        $chico = $this->articulo('zz-b44 Pinza');
        $this->articulo('zz-b44 Pinza de punta');

        list($conversation, $assistant) = $this->conversacion_con_foto();

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);
        $respuesta = PropuestaFotoArticuloIaHelper::proponer($contexto, $assistant, ['articulo' => 'ZZ-B44 PINZA']);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertSame((int) $chico->id, (int) $accion->datos['article_id']);
    }

    /**
     * Sin foto sin gestionar en los últimos mensajes no hay nada que asignar, y se dice con el
     * motivo (respuesta de negocio, no falla técnica).
     *
     * @test
     */
    public function sin_foto_en_los_ultimos_mensajes_da_error()
    {
        $this->articulo('zz-b44 Martillo');

        list($conversation, $assistant) = $this->conversacion_con_foto(null, false);

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);
        $respuesta = PropuestaFotoArticuloIaHelper::proponer($contexto, $assistant, ['articulo' => 'zz-b44 Martillo']);

        $this->assertFalse($respuesta['ok']);
        $this->assertNotNull($respuesta['error']);
    }

    /**
     * Un empleado sin `article.update` no puede: mismo criterio que la pantalla, donde no ve el
     * botón de editar el artículo.
     *
     * @test
     */
    public function un_empleado_sin_permiso_no_puede_ponerle_la_foto()
    {
        $this->articulo('zz-b44 Llave francesa');

        list($conversation, $assistant) = $this->conversacion_con_foto($this->empleado);

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);
        $respuesta = PropuestaFotoArticuloIaHelper::proponer($contexto, $assistant, ['articulo' => 'zz-b44 Llave francesa']);

        $this->assertFalse($respuesta['ok']);
        $this->assertNotNull($respuesta['error']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * 🔴 EL ARCHIVO ES WEBP. El png de ImageController::setImage() es la excepción de los logos que
     * imprime FPDF (`user` y `address`); un artículo va webp. Y la fila de `images` se cuelga por el
     * ALIAS del morph map ('article'), que es lo que lee la relación `images()`.
     *
     * @test
     */
    public function al_confirmar_guarda_la_foto_como_webp_colgada_del_articulo()
    {
        $articulo = $this->articulo('zz-b44 Taladro percutor');

        list($conversation, $assistant, $foto) = $this->conversacion_con_foto();

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);
        $respuesta = PropuestaFotoArticuloIaHelper::proponer($contexto, $assistant, ['articulo' => 'zz-b44 Taladro percutor']);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(AiMessageAction::TIPO_FOTO_ARTICULO, $respuesta['tipo']);

        $resultado = $this->confirmar($conversation, $respuesta['tarjeta_id']);

        $this->assertSame(200, $resultado['status'], json_encode($resultado['body']));

        $image = Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->first();

        $this->assertNotNull($image, 'No quedó ninguna fila en `images` colgada del artículo.');

        $nombre = $this->nombre_de_archivo($image);

        $this->assertSame('webp', strtolower((string) pathinfo($nombre, PATHINFO_EXTENSION)), 'La foto de un artículo se guarda como webp: el png es la excepción de los logos que imprime FPDF.');
        $this->assertFileExists(storage_path() . '/app/public/' . $nombre);

        // La relación del modelo la encuentra: si se hubiera guardado con la FQCN, esto daría 0.
        $this->assertCount(1, $articulo->fresh()->images);

        // Y la foto quedó sellada: ninguna otra tarjeta se la puede llevar.
        $this->assertNotNull($foto->fresh()->gestionada_at);
    }

    /**
     * 🔴 LOS CUATRO EFECTOS DE LA PANTALLA, COMPLETOS. Omitir cualquiera deja un resultado PARECIDO
     * al de ImageController::setImage() y no igual: la foto queda en el sistema y no llega a la
     * tienda del cliente vinculado, ni a Tienda Nube, ni a Mercado Libre.
     *
     * `USA_TIENDA_NUBE` se prende acá y al dueño se le da la extensión `usa_mercado_libre`: los dos
     * servicios salen temprano si su módulo está apagado, así que con el `.env.testing` de siempre este
     * test pasaría sin probar nada. Desde la misión mercado-libre-integracion (commit 8bf091f7) Mercado
     * Libre se prende por la extensión del dueño y ya no por `USA_MERCADO_LIBRE`: este test seguía
     * prendiendo el flag y por eso dejó de ver el efecto de Mercado Libre (rojo del gate del 27/10/2026).
     *
     * @test
     */
    public function al_confirmar_dispara_los_cuatro_efectos_de_la_pantalla()
    {
        $flags_previos = [
            'USA_TIENDA_NUBE' => $this->prender_flag('USA_TIENDA_NUBE'),
        ];

        // Mercado Libre se prende por la extensión del dueño (la transacción del test la deshace). El módulo
        // guarda su estado en una caché estática: se la olvida al prender y al terminar (finally).
        $extension_ml = ExtencionEmpresa::where('slug', 'usa_mercado_libre')->first();

        if (!$extension_ml) {
            $extension_ml = ExtencionEmpresa::forceCreate(['name' => 'usa_mercado_libre', 'slug' => 'usa_mercado_libre']);
        }

        $this->comercio->extencions()->syncWithoutDetaching([$extension_ml->id]);
        ProductService::olvidar_cache_del_modulo();

        try {

            $articulo = $this->articulo('zz-b44 Amoladora angular', [
                'disponible_tienda_nube' => 1,
                'mercado_libre'          => 1,
                'meli_category_id'       => 'MLA1234',
                'stock'                  => 5,
            ]);

            /* El artículo nace con el updated_at de ahora: se lo atrasa para poder ver el bump. */
            Article::where('id', $articulo->id)->update([
                'updated_at' => now()->subDays(3),
                'needs_sync_with_tn' => 0,
            ]);

            $updated_at_viejo = Article::find($articulo->id)->updated_at;

            /* Efecto 1: un comercio vinculado, con su artículo espejo apuntando al nuestro. */
            $comercio_vinculado = User::create([
                'name'         => 'Comercio vinculado B44',
                'company_name' => 'Vinculado B44',
                'email'        => 'foto-art-b44-vinc-' . uniqid() . '@test.local',
                'password'     => Hash::make('secret'),
            ]);

            $cliente = Client::create([
                'user_id'               => $this->comercio->id,
                'name'                  => 'zz-b44 Cliente vinculado',
                'comercio_city_user_id' => $comercio_vinculado->id,
            ]);

            /* `inventory_linkage_scope_id` no tiene default en la tabla: se siembra el alcance. */
            $alcance = InventoryLinkageScope::firstOrCreate(['name' => 'zz-b44 Todo el catálogo']);

            InventoryLinkage::create([
                'user_id'                     => $this->comercio->id,
                'client_id'                   => $cliente->id,
                'inventory_linkage_scope_id'  => $alcance->id,
            ]);

            $espejo = Article::create([
                'name'                 => 'zz-b44 Amoladora angular (espejo)',
                'user_id'              => $comercio_vinculado->id,
                'status'               => 'active',
                'provider_article_id'  => $articulo->id,
            ]);

            list($conversation, $assistant) = $this->conversacion_con_foto();

            $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);
            $respuesta = PropuestaFotoArticuloIaHelper::proponer($contexto, $assistant, ['articulo_id' => $articulo->id]);

            $this->assertTrue($respuesta['ok'], json_encode($respuesta));

            $resultado = $this->confirmar($conversation, $respuesta['tarjeta_id']);

            $this->assertSame(200, $resultado['status'], json_encode($resultado['body']));

            $image = Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->first();
            $this->assertNotNull($image);
            $this->nombre_de_archivo($image);

            // 1) La vinculación de inventarios replicó la imagen en el artículo espejo del cliente.
            $this->assertSame(
                1,
                Image::where('imageable_type', 'article')->where('imageable_id', $espejo->id)->count(),
                'InventoryLinkageHelper::check_created_image() no corrió: el comercio vinculado se queda sin la foto.'
            );

            // 2) La marca de sync con la tienda, y el updated_at BUMPEADO (de eso depende el sync
            // incremental del front: sin bump, el listado no vuelve a bajar el artículo con su foto).
            $fresco = Article::find($articulo->id);
            $this->assertTrue((bool) $fresco->needs_sync_with_tn, 'needs_sync_with_tn quedó apagado.');
            $this->assertTrue(
                $fresco->updated_at->gt($updated_at_viejo),
                'El save() no bumpeó updated_at: el sync incremental de la SPA no vuelve a bajar el artículo.'
            );

            // 3) Mercado Libre.
            $this->assertSame(
                1,
                SyncToMeliArticle::where('article_id', $articulo->id)->where('status', 'pendiente')->count(),
                'ProductService::add_article_to_sync() no corrió: la foto no llega a Mercado Libre.'
            );

            // 4) Tienda Nube.
            $this->assertSame(
                1,
                SyncToTNArticle::where('article_id', $articulo->id)->where('status', 'pendiente')->count(),
                'TiendaNubeSyncArticleService::add_article_to_sync() no corrió: la foto no llega a la tienda.'
            );

        } finally {

            foreach ($flags_previos as $clave => $anterior) {
                $this->restaurar_flag($clave, $anterior);
            }

            ProductService::olvidar_cache_del_modulo();
        }
    }

    /**
     * Prende una variable de entorno para este test y devuelve lo que había.
     *
     * 🔴 HAY QUE TOCAR LAS TRES FUENTES, no alcanza con `$_ENV`. El repositorio de phpdotenv que usa
     * `env()` lee en orden `$_SERVER`, `$_ENV` y `putenv`, y se queda con la PRIMERA que tenga la
     * clave: `.env.testing` deja `USA_TIENDA_NUBE=false` en `$_SERVER`, así que escribiendo solo
     * `$_ENV` el flag seguía apagado y el test pasaba sin probar nada (medido el 21/9/2026: la
     * aserción de Tienda Nube daba 0 mientras la de Mercado Libre daba 1, porque ese otro flag ya
     * venía en `true` desde el archivo).
     *
     * @param  string  $clave
     * @return array{server: mixed, env: mixed, putenv: string|false}
     */
    protected function prender_flag($clave)
    {
        $anterior = [
            'server' => array_key_exists($clave, $_SERVER) ? $_SERVER[$clave] : null,
            'env'    => array_key_exists($clave, $_ENV) ? $_ENV[$clave] : null,
            'putenv' => getenv($clave),
        ];

        $_SERVER[$clave] = 'true';
        $_ENV[$clave] = 'true';
        putenv($clave . '=true');

        return $anterior;
    }

    /**
     * Deja la variable como estaba antes de prender_flag().
     *
     * @param  string  $clave
     * @param  array  $anterior
     * @return void
     */
    protected function restaurar_flag($clave, array $anterior)
    {
        if (is_null($anterior['server'])) {
            unset($_SERVER[$clave]);
        } else {
            $_SERVER[$clave] = $anterior['server'];
        }

        if (is_null($anterior['env'])) {
            unset($_ENV[$clave]);
        } else {
            $_ENV[$clave] = $anterior['env'];
        }

        if ($anterior['putenv'] === false) {
            putenv($clave);
        } else {
            putenv($clave . '=' . $anterior['putenv']);
        }
    }

    /**
     * 🔴 NO SE AUTO-CONFIRMA EN "RESUELTO" (decisión de Lucas, 21/9/2026). La de SUCURSAL sí,
     * porque no puede equivocarse de destino; ésta infiere el artículo de un nombre que puede venir
     * inexacto, y una foto mal asignada se PUBLICA.
     *
     * ⚠️ LO QUE SE FIJA ACÁ ES EL COMPORTAMIENTO, NO LA FORMA. Hasta el 22/9/2026 este test decía
     * que además se fijaba "el `case` del despacho sin quizas_auto_confirmar()", y eso quedó
     * vencido con la misión asistente-capacidades-y-hilos: ese `case` SÍ pasa por la puerta, porque
     * ahora es la puerta la que mira el modo del dueño y la foto de un artículo sí se auto-ejecuta
     * en "directo". Lo que sigue siendo verdad, y es lo que este test mide, es que con el dueño en
     * "resuelto" la tarjeta queda propuesta: el tipo no está en AUTO_CONFIRMABLES. El modo directo
     * lo cubre el test 51.
     *
     * @test
     */
    public function nunca_se_auto_confirma_aunque_el_dueno_este_en_resuelto()
    {
        $this->assertNotContains(
            AiMessageAction::TIPO_FOTO_ARTICULO,
            HerramientasDeCarga::AUTO_CONFIRMABLES,
            'La foto de un artículo se publica en la tienda: la confirma siempre la persona.'
        );

        $this->comercio->agente_confianza = 'resuelto';
        $this->comercio->save();

        $articulo = $this->articulo('zz-b44 Sierra caladora');

        list($conversation, $assistant, $foto) = $this->conversacion_con_foto(null, true, 'pendiente');

        HerramientasDeCarga::ejecutar('proponer_foto_articulo', ['articulo' => 'zz-b44 Sierra caladora'], $conversation, $assistant);

        $accion = AiMessageAction::where('ai_conversation_id', $conversation->id)
            ->where('tipo', AiMessageAction::TIPO_FOTO_ARTICULO)
            ->first();

        $this->assertNotNull($accion, 'No quedó la tarjeta.');
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $accion->estado_guardado(), 'Se auto-confirmó: el modo "resuelto" no puede publicar una foto sin que la persona la vea.');

        // Nada se escribió: ni la imagen del artículo ni el sellado de la foto.
        $this->assertSame(0, Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->count());
        $this->assertNull($foto->fresh()->gestionada_at);
    }

    /** @test */
    public function imagen_profesional_explicita_se_publica_sin_tomar_la_etiqueta_del_usuario()
    {
        $articulo = $this->articulo('zz-b44 Taco Fischer');
        list($conversacion, $assistant, $etiqueta) = $this->conversacion_con_foto();
        $foto = \App\Http\Controllers\Helpers\asistente_ia\AsistenteImagenHelper::guardar_binario(
            $assistant, (string) (new ImageManager())->canvas(18, 14, '#ff1100')->encode('png'), $this->comercio->id
        );
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversacion);
        $respuesta = PropuestaFotoArticuloIaHelper::proponer($contexto, $assistant, ['articulo_id' => $articulo->id, 'imagen_id' => $foto->id]);
        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $accion = AiMessageAction::findOrFail($respuesta['tarjeta_id']);
        $this->assertSame($foto->id, $accion->datos['imagen_id']);
        $this->assertStringContainsString('internet', json_encode($accion->presentacion));
        $this->assertSame($assistant->id, $accion->presentacion['imagen_mensaje']['ai_message_id']);
        $this->confirmar($conversacion, $accion->id);
        $publicada = Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->firstOrFail();
        $archivo = $this->nombre_de_archivo($publicada);
        $dimensiones = getimagesize(storage_path('app/public/' . $archivo));
        $this->assertSame(18, $dimensiones[0]);
        $this->assertSame(14, $dimensiones[1]);
        $this->assertNotNull($foto->fresh()->gestionada_at);
        $this->assertNull($etiqueta->fresh()->gestionada_at);
    }

    /** @test */
    public function imagen_explicita_ajena_usada_o_invalida_no_cae_en_la_ultima_del_usuario()
    {
        $articulo = $this->articulo('zz-b44 Taco aislado');
        list($conversacion, $assistant, $etiqueta) = $this->conversacion_con_foto();
        list($otro_hilo, $otro_assistant, $foto_otra) = $this->conversacion_con_foto();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversacion);
        $dueno_ajeno = User::create(['name' => 'Otro dueño foto explícita', 'email' => 'foto-ajena-' . uniqid() . '@test.local', 'password' => Hash::make('secret')]);
        $hilo_ajeno = AiConversation::create(['user_id' => $dueno_ajeno->id, 'auth_user_id' => $dueno_ajeno->id]);
        $mensaje_ajeno = AiMessage::create(['ai_conversation_id' => $hilo_ajeno->id, 'rol' => 'assistant', 'estado' => 'listo']);
        $foto_ajena = AiMessageImagen::create(['ai_message_id' => $mensaje_ajeno->id, 'user_id' => $dueno_ajeno->id, 'orden' => 1, 'path' => 'no-se-debe-leer.png', 'mime' => 'image/png', 'bytes' => 1]);
        foreach ([$foto_otra->id, $foto_ajena->id, 999999999, '1.5', '999999999999999999999999', false, null, []] as $id) {
            $respuesta = PropuestaFotoArticuloIaHelper::proponer($contexto, $assistant, ['articulo_id' => $articulo->id, 'imagen_id' => $id]);
            $this->assertFalse($respuesta['ok'], 'Una foto explícita inválida no puede reemplazarse silenciosamente.');
        }
        $etiqueta->gestionada_at = now();
        $etiqueta->save();
        $respuesta = PropuestaFotoArticuloIaHelper::proponer($contexto, $assistant, ['articulo_id' => $articulo->id, 'imagen_id' => $etiqueta->id]);
        $this->assertFalse($respuesta['ok']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversacion->id)->count());
    }

    /** @test */
    public function imagen_explicita_se_vuelve_a_validar_contra_la_conversacion_al_confirmar()
    {
        $articulo = $this->articulo('zz-b44 Foto en otra conversación');
        list($conversacion, $assistant, $foto) = $this->conversacion_con_foto();
        list($otro_hilo, $otro_assistant) = $this->conversacion_con_foto(null, false);
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversacion);
        $respuesta = PropuestaFotoArticuloIaHelper::proponer($contexto, $assistant, ['articulo_id' => $articulo->id, 'imagen_id' => $foto->id]);
        $this->assertTrue($respuesta['ok']);
        $foto->ai_message_id = $otro_assistant->id;
        $foto->save();
        $resultado = $this->confirmar($conversacion, $respuesta['tarjeta_id']);
        $this->assertSame(422, $resultado['status']);
        $this->assertSame(0, Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->count());
        $this->assertNull($foto->fresh()->gestionada_at);
    }
}
