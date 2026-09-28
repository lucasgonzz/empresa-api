<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Http\Controllers\Helpers\ImagenesAutomaticasHelper;
use App\Models\AiTokenUsage;
use App\Models\Article;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Models\ImageServiceCall;
use App\Models\User;
use App\Services\ArticleImageValidationService;
use App\Services\ImageAssignment\ImageServiceCallLogger;
use Carbon\Carbon;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

/**
 * El registro de TODAS las consultas del circuito de imágenes (plan §12.1): una fila de
 * `image_service_calls` por cada búsqueda a Serper / Google y por cada llamada a la IA, haya salido
 * bien o mal, con lo que el admin muestra por cliente.
 *
 * Lo que protege: que cada búsqueda del motor y cada llamada de evaluar_candidatas() dejen su fila
 * (con estado HTTP, duración, resultados, tokens y si se cobró), que validate() —la búsqueda por
 * código del asistente y el lote viejo— registre como `validacion_individual` sin cambiar lo que
 * devuelve, que el registro NUNCA guarde una clave, que nunca tumbe lo que lo llama, y que se purgue
 * a los 180 días.
 *
 * Todo con Http::fake (ver ImagenesInteligentesTestCase): ninguna prueba le pega a una API real.
 */
class Registro_de_consultas_Test extends ImagenesInteligentesTestCase
{
    /** Un EAN-13 de fábrica válido. */
    const CODIGO_REAL = '7791234567898';

    /** El modelo que contesta la IA falsa de ImagenesInteligentesTestCase. */
    const MODELO_FALSO = 'claude-haiku-4-5-20251001';

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function una_busqueda_y_una_llamada_a_la_ia_quedan_registradas_con_todo()
    {
        $articulo = $this->nuevo_articulo('Destornillador Phillips 6 mm', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        $this->falsear(
            [self::CODIGO_REAL => [$this->resultado($this->url_imagen('destornillador'), 1000, 1000, 1)]],
            [$this->url_imagen('destornillador') => $this->png(1000, 1000, 'rojo')],
            ['rojo' => $this->veredicto('si', 'high')]
        );

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame(2, ImageServiceCall::where('run_id', $run->id)->count(), 'Una búsqueda y una llamada a la IA (la descarga de la imagen no es una consulta).');

        // La búsqueda.
        $busqueda = ImageServiceCall::where('run_id', $run->id)->where('tipo', ImageServiceCall::TIPO_BUSQUEDA)->first();

        $this->assertSame((int) $this->owner->id, $busqueda->user_id);
        $this->assertSame((int) $item->id, $busqueda->item_id);
        $this->assertSame((int) $articulo->id, $busqueda->article_id);
        $this->assertSame('Destornillador Phillips 6 mm', $busqueda->article_name);
        $this->assertSame(ImageServiceCall::ORIGEN_ASIGNACION, $busqueda->origen);
        $this->assertSame('serper', $busqueda->proveedor);
        $this->assertNull($busqueda->modelo);
        $this->assertSame('codigo_de_barras', $busqueda->criterio);
        $this->assertSame(self::CODIGO_REAL, $busqueda->consulta);
        $this->assertTrue($busqueda->ok);
        $this->assertTrue($busqueda->cobrada);
        $this->assertSame(200, $busqueda->http_status);
        $this->assertNull($busqueda->error);
        $this->assertSame(1, $busqueda->resultados);
        $this->assertSame('1 resultado', $busqueda->resumen);
        $this->assertNotNull($busqueda->duracion_ms);
        $this->assertNull($busqueda->tokens_entrada, 'Una búsqueda no tiene tokens.');

        // La llamada a la IA.
        $ia = ImageServiceCall::where('run_id', $run->id)->where('tipo', ImageServiceCall::TIPO_VALIDACION_IA)->first();

        $this->assertSame((int) $item->id, $ia->item_id);
        $this->assertSame((int) $articulo->id, $ia->article_id);
        $this->assertSame(ImageServiceCall::ORIGEN_ASIGNACION, $ia->origen);
        $this->assertSame('anthropic', $ia->proveedor);
        $this->assertSame(self::MODELO_FALSO, $ia->modelo, 'El modelo que devolvió Anthropic (el que tiene precio).');
        $this->assertSame('codigo_de_barras', $ia->criterio, 'De qué búsqueda salieron las candidatas.');
        $this->assertSame(self::CODIGO_REAL, $ia->consulta);
        $this->assertTrue($ia->ok);
        $this->assertTrue($ia->cobrada);
        $this->assertSame(200, $ia->http_status);
        $this->assertSame(1, $ia->candidatas);
        $this->assertSame('1 sí', $ia->resumen);
        $this->assertSame(1200, $ia->tokens_entrada);
        $this->assertSame(150, $ia->tokens_salida);
        $this->assertSame(0, $ia->tokens_cache_escritura);
        $this->assertSame(0, $ia->tokens_cache_lectura);
        $this->assertNotNull($ia->duracion_ms);

        // El consumo de tokens sigue anotándose donde siempre.
        $this->assertSame(1, AiTokenUsage::where('user_id', $this->owner->id)->where('proceso', 'validacion_imagen_articulo')->count());
    }

    /**
     * Un proveedor caído deja una fila por cada intento, sin cobrar, con el estado HTTP y el error.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function una_busqueda_que_falla_queda_registrada_sin_cobrar()
    {
        $articulo = $this->nuevo_articulo('Cinta métrica 5 m', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        $this->falsear(['error' => 'Not enough credits'], [], []);

        $this->procesar($run, $articulo);

        $filas = ImageServiceCall::where('run_id', $run->id)->orderBy('id')->get();

        $this->assertCount(2, $filas, 'Por código y por nombre: las dos se intentaron.');
        $this->assertSame(['codigo_de_barras', 'nombre'], $filas->pluck('criterio')->all());

        foreach ($filas as $fila) {
            $this->assertSame(ImageServiceCall::TIPO_BUSQUEDA, $fila->tipo);
            $this->assertFalse($fila->ok);
            $this->assertFalse($fila->cobrada, 'Lo que el proveedor rechazó no se cobra.');
            $this->assertSame(500, $fila->http_status);
            $this->assertStringContainsString('Not enough credits', (string) $fila->error);
            $this->assertNull($fila->resultados);
            $this->assertSame('El proveedor respondió con error', $fila->resumen);
        }
    }

    /**
     * Con Google (el respaldo de los clientes sin Serper) el registro también lleva el estado HTTP:
     * el trait no lo devuelve, lo anota el proveedor con un middleware sobre el mismo cliente.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function una_busqueda_de_google_registra_su_estado_http()
    {
        config(['services.google_search.api_key' => 'AIzaCLAVE-DE-GOOGLE-DE-PRUEBA']);

        $bien = $this->nuevo_articulo('Balde 10 L', self::CODIGO_REAL);
        $mal  = $this->nuevo_articulo('Balde 12 L', $this->con_verificador('779000700001'));
        $run  = $this->asignacion([$bien, $mal], ['proveedor' => ImageAssignmentRun::PROVEEDOR_GOOGLE]);

        $test   = $this;
        $imagen = $this->png(1000, 1000, 'rojo');
        $url    = $this->url_imagen('balde');

        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::fake(function ($request) use ($test, $imagen, $url) {
            if (strpos($request->url(), 'googleapis.com') !== false) {
                // El artículo "bien" responde con un resultado; cualquier otra consulta, 403.
                if (strpos(urldecode($request->url()), 'q='.self::CODIGO_REAL) !== false) {
                    return Http::response([
                        'items' => [[
                            'link'        => $url,
                            'title'       => 'Balde 10 L',
                            'displayLink' => 'tienda.test',
                            'image'       => ['width' => 1000, 'height' => 1000, 'thumbnailLink' => $url.'?miniatura', 'contextLink' => 'https://tienda.test/balde'],
                        ]],
                        'searchInformation' => ['totalResults' => '1'],
                    ], 200);
                }

                return Http::response(['error' => ['code' => 403, 'message' => 'Requests from referer <empty> are blocked.']], 403);
            }

            if (strpos($request->url(), 'api.anthropic.com') !== false) {
                return $test->respuesta_de_ia($request, ['rojo' => $test->veredicto_publico('si', 'high')]);
            }

            if ($request->url() === $url) {
                return Http::response($imagen, 200, ['Content-Type' => 'image/png']);
            }

            return Http::response('no encontrada', 404, ['Content-Type' => 'text/plain']);
        });

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $this->procesar($run, $bien)->status);
        $this->procesar($run, $mal);

        $buena = ImageServiceCall::where('run_id', $run->id)->where('article_id', $bien->id)->where('tipo', ImageServiceCall::TIPO_BUSQUEDA)->first();

        $this->assertSame('google', $buena->proveedor);
        $this->assertTrue($buena->ok);
        $this->assertSame(200, $buena->http_status);
        $this->assertSame(1, $buena->resultados);
        $this->assertNotNull($buena->duracion_ms);

        $rechazadas = ImageServiceCall::where('run_id', $run->id)->where('article_id', $mal->id)->where('tipo', ImageServiceCall::TIPO_BUSQUEDA)->get();

        $this->assertCount(2, $rechazadas);

        foreach ($rechazadas as $rechazada) {
            $this->assertFalse($rechazada->ok);
            $this->assertFalse($rechazada->cobrada);
            $this->assertSame(403, $rechazada->http_status);
            $this->assertStringContainsString('referer <empty> are blocked', (string) $rechazada->error);
        }
    }

    /**
     * Para los closures del Http::fake (veredicto() es protegido).
     *
     * @param  string $es_el_producto
     * @param  string $confianza
     * @return array
     */
    public function veredicto_publico($es_el_producto, $confianza = 'high')
    {
        return $this->veredicto($es_el_producto, $confianza);
    }

    /**
     * La IA caída también queda: sin cobrar, sin tokens, con el error.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function una_llamada_a_la_ia_que_falla_queda_registrada_sin_tokens()
    {
        $articulo = $this->nuevo_articulo('Pinza de punta', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        $this->falsear(
            [self::CODIGO_REAL => [$this->resultado($this->url_imagen('pinza'), 1000, 1000, 1)]],
            [$this->url_imagen('pinza') => $this->png(1000, 1000, 'azul')],
            'caida'
        );

        $this->procesar($run, $articulo);

        $ia = ImageServiceCall::where('run_id', $run->id)->where('tipo', ImageServiceCall::TIPO_VALIDACION_IA)->first();

        $this->assertNotNull($ia);
        $this->assertFalse($ia->ok);
        $this->assertFalse($ia->cobrada);
        $this->assertSame(500, $ia->http_status);
        $this->assertStringContainsString('Servicio no disponible (fake)', (string) $ia->error);
        $this->assertSame(1, $ia->candidatas);
        $this->assertNull($ia->tokens_entrada);
        $this->assertNull($ia->resumen);
        $this->assertSame(0, AiTokenUsage::where('user_id', $this->owner->id)->count(), 'Nada que cobrar.');
    }

    /**
     * validate() —la búsqueda por código del asistente y el lote viejo— registra con origen
     * `validacion_individual`, sin asignación ni item, y `article_id` solo si el artículo existe. Lo
     * que devuelve no cambia.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function validate_registra_la_validacion_individual_sin_cambiar_lo_que_devuelve()
    {
        $servicio = new ArticleImageValidationService();
        $imagen   = $this->png(600, 600, 'rojo');

        $this->falsear_validate(200);

        // Un artículo de la base.
        $articulo  = $this->nuevo_articulo('Yerba mate 1 kg', self::CODIGO_REAL);
        $veredicto = $servicio->validate($imagen, $articulo, $this->owner->id);

        $this->assertTrue($veredicto['evaluated']);
        $this->assertTrue($veredicto['accepted']);

        $fila = ImageServiceCall::where('user_id', $this->owner->id)->orderBy('id', 'desc')->first();

        $this->assertSame(ImageServiceCall::ORIGEN_VALIDACION_INDIVIDUAL, $fila->origen);
        $this->assertSame(ImageServiceCall::TIPO_VALIDACION_IA, $fila->tipo);
        $this->assertSame('anthropic', $fila->proveedor);
        $this->assertSame(self::MODELO_FALSO, $fila->modelo);
        $this->assertNull($fila->run_id);
        $this->assertNull($fila->item_id);
        $this->assertSame((int) $articulo->id, $fila->article_id);
        $this->assertSame('Yerba mate 1 kg', $fila->article_name);
        $this->assertNull($fila->criterio);
        $this->assertTrue($fila->ok);
        $this->assertTrue($fila->cobrada);
        $this->assertSame(200, $fila->http_status);
        $this->assertSame(1, $fila->candidatas);
        $this->assertSame('Aceptada · es el producto · confianza alta', $fila->resumen);
        $this->assertSame(800, $fila->tokens_entrada);
        $this->assertSame(60, $fila->tokens_salida);
        $this->assertSame(1, AiTokenUsage::where('user_id', $this->owner->id)->where('proceso', 'validacion_imagen_articulo')->count());

        // El de la búsqueda por código del asistente: un Article SIN GUARDAR.
        $sin_guardar           = new Article();
        $sin_guardar->name     = 'Producto con código '.self::CODIGO_REAL;
        $sin_guardar->bar_code = self::CODIGO_REAL;

        $servicio->validate($imagen, $sin_guardar, $this->owner->id);

        $fila = ImageServiceCall::where('user_id', $this->owner->id)->orderBy('id', 'desc')->first();

        $this->assertNull($fila->article_id, 'No existe en la base: no se inventa un id.');
        $this->assertSame('Producto con código '.self::CODIGO_REAL, $fila->article_name);
        $this->assertSame((int) $this->owner->id, $fila->user_id);

        // Anthropic rechaza la llamada: queda sin cobrar, y validate() devuelve lo de siempre.
        $this->falsear_validate(529);

        $veredicto = $servicio->validate($imagen, $articulo, $this->owner->id);

        $this->assertFalse($veredicto['evaluated']);
        $this->assertTrue($veredicto['accepted'], 'Fail-open de siempre: se asigna igual para revisar a mano.');

        $fila = ImageServiceCall::where('user_id', $this->owner->id)->orderBy('id', 'desc')->first();

        $this->assertFalse($fila->ok);
        $this->assertFalse($fila->cobrada);
        $this->assertSame(529, $fila->http_status);
        $this->assertSame('Overloaded (fake)', $fila->error);
        $this->assertNull($fila->tokens_entrada);
        $this->assertSame(3, ImageServiceCall::where('user_id', $this->owner->id)->count());
        $this->assertSame(2, AiTokenUsage::where('user_id', $this->owner->id)->count(), 'La rechazada no suma tokens.');
    }

    /**
     * 🔴 Nada de lo que se registra lleva una clave: ni la de Google que trae un timeout en la URL,
     * ni una de Anthropic o de Google que aparezca suelta en un mensaje.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function el_registro_nunca_guarda_una_clave()
    {
        config(['services.google_search.api_key' => 'AIzaCLAVE-DE-GOOGLE-DE-PRUEBA']);

        $articulo = $this->nuevo_articulo('Llave francesa 10 pulgadas', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo], ['proveedor' => ImageAssignmentRun::PROVEEDOR_GOOGLE]);

        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::fake(function ($request) {
            if (strpos($request->url(), 'googleapis.com') !== false) {
                throw new ConnectException(
                    'cURL error 28: Operation timed out after 15001 milliseconds for '.$request->url(),
                    $request->toPsrRequest()
                );
            }

            return Http::response('no encontrada', 404, ['Content-Type' => 'text/plain']);
        });

        $this->procesar($run, $articulo);

        $filas = ImageServiceCall::where('run_id', $run->id)->get();

        $this->assertCount(2, $filas);

        foreach ($filas as $fila) {
            $this->assertSame('google', $fila->proveedor);
            $this->assertFalse($fila->ok);
            $this->assertNull($fila->http_status, 'No llegó a responder.');
            $this->assertStringContainsString('Operation timed out', (string) $fila->error);
            $this->assertStringContainsString('key=***', (string) $fila->error);
            $this->assertStringNotContainsString('AIzaCLAVE-DE-GOOGLE-DE-PRUEBA', (string) $fila->error);
            $this->assertStringNotContainsString(ImagenesAutomaticasHelper::CX, (string) $fila->error);
        }

        // Claves con forma conocida que aparezcan sueltas en cualquier mensaje.
        $fila = ImageServiceCallLogger::registrar([
            'user_id'   => $this->owner->id,
            'tipo'      => ImageServiceCall::TIPO_VALIDACION_IA,
            'proveedor' => 'anthropic',
            'error'     => 'Falló con sk-ant-api03-AbCdEf123456_xyz y AIzaSyZZZZZZZZZZZZZZZZZZZZ en el medio.',
        ]);

        $this->assertSame('Falló con sk-ant-*** y AIza*** en el medio.', $fila->error);

        // Y la de Serper de config, aunque no tenga una forma reconocible.
        config(['services.serper.api_key' => 'serper-clave-larga-123']);

        $fila = ImageServiceCallLogger::registrar([
            'user_id'   => $this->owner->id,
            'tipo'      => ImageServiceCall::TIPO_BUSQUEDA,
            'proveedor' => 'serper',
            'error'     => 'Rechazada la clave serper-clave-larga-123.',
        ]);

        $this->assertSame('Rechazada la clave ***.', $fila->error);
    }

    /**
     * El registro es contabilidad de fondo: si no puede grabar, avisa en el log y sigue. Nunca tumba
     * la búsqueda de una imagen.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function el_registro_nunca_lanza()
    {
        // Sin dueño o sin tipo: no se graba, no explota.
        $this->assertNull(ImageServiceCallLogger::registrar([]));
        $this->assertNull(ImageServiceCallLogger::registrar(['user_id' => $this->owner->id, 'tipo' => 'otra_cosa', 'proveedor' => 'serper']));

        // La base falla al grabar.
        ImageServiceCall::creating(function () {
            throw new \RuntimeException('La base no está (prueba).');
        });

        try {
            $this->assertNull(ImageServiceCallLogger::registrar([
                'user_id'   => $this->owner->id,
                'tipo'      => ImageServiceCall::TIPO_BUSQUEDA,
                'proveedor' => 'serper',
            ]));

            // Y el motor sigue: el artículo se procesa aunque el registro no pueda grabar.
            $articulo = $this->nuevo_articulo('Martillo carpintero', self::CODIGO_REAL);
            $run      = $this->asignacion([$articulo]);

            $this->falsear(
                [self::CODIGO_REAL => [$this->resultado($this->url_imagen('martillo'), 1000, 1000, 1)]],
                [$this->url_imagen('martillo') => $this->png(1000, 1000, 'verde')],
                ['verde' => $this->veredicto('si', 'high')]
            );

            $item = $this->procesar($run, $articulo);

            $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $item->status);
            $this->assertSame(0, ImageServiceCall::where('run_id', $run->id)->count());
        } finally {
            ImageServiceCall::flushEventListeners();
        }
    }

    /**
     * Retención de 180 días: se purga al crear una asignación, SOLO lo del dueño que la crea y como
     * mucho una vez por día (plan §13, S5: en una base compartida, crear una asignación no paga el
     * borrado de los demás comercios; antes este test afirmaba que se purgaban todos).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function crear_una_asignacion_purga_el_registro_de_mas_de_180_dias_del_dueno()
    {
        Cache::flush();

        $otro = User::create([
            'name'     => 'Otro comercio de la base',
            'email'    => 'otro-registro-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
        ]);

        $viejo_propio = $this->consulta_registrada($this->owner->id, Carbon::now()->subDays(181));
        $viejo_ajeno  = $this->consulta_registrada($otro->id, Carbon::now()->subDays(200));
        $reciente     = $this->consulta_registrada($this->owner->id, Carbon::now()->subDays(179));

        $this->asignacion([$this->nuevo_articulo('Serrucho', self::CODIGO_REAL)]);

        $this->assertNull(ImageServiceCall::find($viejo_propio->id));
        $this->assertNotNull(ImageServiceCall::find($viejo_ajeno->id), 'El de otro dueño de la base no se toca.');
        $this->assertNotNull(ImageServiceCall::find($reciente->id));

        // Una segunda asignación el mismo día no vuelve a purgar.
        $otro_viejo = $this->consulta_registrada($this->owner->id, Carbon::now()->subDays(190));

        $this->asignacion([$this->nuevo_articulo('Serrucho de costilla', self::CODIGO_REAL)]);

        $this->assertNotNull(ImageServiceCall::find($otro_viejo->id), 'Ya se purgó hoy: sale mañana.');
    }

    /**
     * Una fila del registro con fecha puesta a mano.
     *
     * @param  int    $user_id
     * @param  Carbon $fecha
     * @return \App\Models\ImageServiceCall
     */
    protected function consulta_registrada($user_id, Carbon $fecha)
    {
        return ImageServiceCall::create([
            'user_id'    => $user_id,
            'origen'     => ImageServiceCall::ORIGEN_ASIGNACION,
            'tipo'       => ImageServiceCall::TIPO_BUSQUEDA,
            'proveedor'  => 'serper',
            'ok'         => true,
            'cobrada'    => true,
            'created_at' => $fecha,
            'updated_at' => $fecha,
        ]);
    }

    /**
     * Anthropic falso para validate() (su formato: un solo veredicto).
     *
     * @param  int $estado  200 = acepta; otro = error con ese estado.
     * @return void
     */
    protected function falsear_validate($estado)
    {
        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));

        Http::fake(function ($request) use ($estado) {
            if (strpos($request->url(), 'api.anthropic.com') === false) {
                return Http::response('no encontrada', 404, ['Content-Type' => 'text/plain']);
            }

            if ($estado !== 200) {
                return Http::response(['error' => ['message' => 'Overloaded (fake)']], $estado);
            }

            return Http::response([
                'id'          => 'msg_prueba',
                'type'        => 'message',
                'role'        => 'assistant',
                'model'       => self::MODELO_FALSO,
                'content'     => [['type' => 'text', 'text' => json_encode([
                    'es_el_producto' => true,
                    'tipo'           => 'producto',
                    'confianza'      => 'high',
                    'motivo'         => 'Se ve el paquete de frente (prueba).',
                ])]],
                'stop_reason' => 'end_turn',
                'usage'       => ['input_tokens' => 800, 'output_tokens' => 60],
            ], 200);
        });
    }
}
