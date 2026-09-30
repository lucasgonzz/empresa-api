<?php

namespace Tests\Feature\ModelosIa;

use App\Http\Controllers\Helpers\ImageAssignmentRunHelper;
use App\Jobs\ProcessImageAssignmentRunJob;
use App\Models\AiTokenUsage;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Models\ImageServiceCall;
use App\Services\ArticleImageValidationService;
use App\Services\CategoriaImagenValidacionService;
use App\Services\ImageAssignment\ImageServiceCallLogger;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ImagenesInteligentes\ImagenesInteligentesTestCase;

/**
 * Misión modelos-ia-por-cliente (30/9/2026) — la verificación de imágenes con DeepSeek.
 *
 * Protege: con la clave de DeepSeek y la opción por defecto (DeepSeek Flash), las tres llamadas de
 * visión (evaluar_candidatas del motor, validate() individual y la de categorías) pegan a
 * `api.deepseek.com/anthropic/v1/messages` con la clave de DeepSeek, el modelo Flash y el thinking
 * apagado; `image_service_calls.proveedor` y `ai_token_usages.proveedor` dicen `deepseek`; el corte
 * de 5 artículos seguidos sigue andando con los errores de DeepSeek (402 saldo insuficiente, 500) y
 * el motivo trae el status real. Y el fallback legado: sin clave de DeepSeek, la llamada es la de
 * siempre (Anthropic, el Haiku de `article_image_validation.model`, sin clave `thinking`).
 *
 * Usa la siembra de ImagenesInteligentesTestCase (comercio, artículos, Serper e imágenes falsas, la
 * IA que contesta por el color de la miniatura) con un fake propio que además atiende DeepSeek.
 *
 * 🔴 Ningún test sale a la red.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Imagenes_con_deepseek_Test extends ImagenesInteligentesTestCase
{
    /** Un código de barras válido para las búsquedas. */
    const CODIGO = '7791234567898';

    /** @var array Requests que llegaron a la IA (url, headers, body), en orden. */
    protected $requests_ia = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.deepseek.api_key'               => 'DEEPSEEK-DE-PRUEBA',
            'services.deepseek.model_agil'            => 'deepseek-flash-test',
            'services.article_image_validation.model' => 'claude-haiku-legado-test',
        ]);
    }

    /**
     * Como falsear() del padre, pero la IA puede estar en DeepSeek o en Anthropic, y la caída puede
     * ser con cualquier status.
     *
     * @param  array        $serper
     * @param  array        $imagenes
     * @param  array|int    $ia  color => veredicto, o un status HTTP de error.
     * @param  string       $mensaje_de_error
     * @return void
     */
    protected function falsear_con_deepseek(array $serper, array $imagenes, $ia, $mensaje_de_error = 'Error (fake)')
    {
        $this->requests_ia = [];

        $test = $this;

        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));

        Http::fake(function ($request) use ($test, $serper, $imagenes, $ia, $mensaje_de_error) {
            $url = $request->url();

            if (strpos($url, 'google.serper.dev') !== false) {
                $consulta = isset($request->data()['q']) ? (string) $request->data()['q'] : '';

                return Http::response(['images' => isset($serper[$consulta]) ? array_values($serper[$consulta]) : []], 200);
            }

            if (strpos($url, 'api.deepseek.com') !== false || strpos($url, 'api.anthropic.com') !== false) {
                $test->requests_ia[] = ['url' => $url, 'headers' => $request->headers(), 'body' => $request->data()];

                if (is_int($ia)) {
                    return Http::response(['error' => ['message' => $mensaje_de_error, 'type' => 'error_de_prueba']], $ia);
                }

                return $test->respuesta_de_ia($request, $ia);
            }

            if (isset($imagenes[$url])) {
                return Http::response($imagenes[$url], 200, ['Content-Type' => 'image/png']);
            }

            return Http::response('no encontrada', 404, ['Content-Type' => 'text/plain']);
        });
    }

    /**
     * Un artículo con una candidata roja que la IA confirma.
     *
     * @return array [run, articulo]
     */
    protected function un_articulo_con_candidata()
    {
        $articulo = $this->nuevo_articulo('Destornillador Phillips 6 mm', self::CODIGO);
        $run      = $this->asignacion([$articulo]);

        $this->falsear_con_deepseek(
            [self::CODIGO => [$this->resultado($this->url_imagen('destornillador'), 1000, 1000, 1)]],
            [$this->url_imagen('destornillador') => $this->png(1000, 1000, 'rojo')],
            ['rojo' => $this->veredicto('si', 'high')]
        );

        return [$run, $articulo];
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function con_la_clave_de_deepseek_el_motor_valida_con_deepseek_flash_y_lo_registra()
    {
        list($run, $articulo) = $this->un_articulo_con_candidata();

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $item->status);
        $this->assertCount(1, $this->requests_ia);

        $request = $this->requests_ia[0];

        $this->assertSame('https://api.deepseek.com/anthropic/v1/messages', $request['url']);
        $this->assertSame(['DEEPSEEK-DE-PRUEBA'], $request['headers']['x-api-key'], 'La clave es la de DeepSeek.');
        $this->assertSame('deepseek-flash-test', $request['body']['model']);
        $this->assertSame(['type' => 'disabled'], $request['body']['thinking'], 'Flash no razona: thinking apagado.');

        $consulta = ImageServiceCall::where('run_id', $run->id)->where('tipo', ImageServiceCall::TIPO_VALIDACION_IA)->first();

        $this->assertSame(ImageServiceCall::PROVEEDOR_DEEPSEEK, $consulta->proveedor);
        $this->assertSame('deepseek', $consulta->proveedor);

        $fila = AiTokenUsage::where('user_id', $this->owner->id)->where('proceso', 'validacion_imagen_articulo')->first();

        $this->assertNotNull($fila);
        $this->assertSame('deepseek', $fila->proveedor, 'El gasto se registra con el proveedor que contestó.');
    }

    /**
     * 🔴 Sin clave de DeepSeek (la producción de hoy): la llamada es EXACTAMENTE la de antes.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function sin_clave_de_deepseek_la_validacion_es_la_de_siempre_en_anthropic()
    {
        config(['services.deepseek.api_key' => null]);

        list($run, $articulo) = $this->un_articulo_con_candidata();

        $this->procesar($run, $articulo);

        $this->assertCount(1, $this->requests_ia);

        $request = $this->requests_ia[0];

        $this->assertSame('https://api.anthropic.com/v1/messages', $request['url']);
        $this->assertSame(['ANTHROPIC-DE-PRUEBA'], $request['headers']['x-api-key']);
        $this->assertSame('claude-haiku-legado-test', $request['body']['model'], 'El modelo legado de la tarea, no el Haiku del catálogo.');
        $this->assertArrayNotHasKey('thinking', $request['body'], 'A Anthropic no se le manda ninguna clave thinking.');
        $this->assertSame(1500, $request['body']['max_tokens']);

        $consulta = ImageServiceCall::where('run_id', $run->id)->where('tipo', ImageServiceCall::TIPO_VALIDACION_IA)->first();

        $this->assertSame('anthropic', $consulta->proveedor);
        $this->assertSame('anthropic', AiTokenUsage::where('user_id', $this->owner->id)->where('proceso', 'validacion_imagen_articulo')->first()->proveedor);
    }

    /**
     * Elegir Claude Sonnet para imágenes, con las dos claves: va a Anthropic con el Sonnet del
     * catálogo (no con el legado, que es solo para el fallback).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function eligiendo_claude_sonnet_va_a_anthropic_con_el_modelo_del_catalogo()
    {
        config(['services.anthropic.model_equilibrado' => 'claude-sonnet-catalogo-test']);

        $this->owner->ia_modelo_imagenes = 'claude_sonnet';
        $this->owner->save();

        list($run, $articulo) = $this->un_articulo_con_candidata();

        $this->procesar($run, $articulo);

        $this->assertSame('https://api.anthropic.com/v1/messages', $this->requests_ia[0]['url']);
        $this->assertSame('claude-sonnet-catalogo-test', $this->requests_ia[0]['body']['model']);
    }

    /**
     * El corte de 5 artículos seguidos sin IA sigue andando con DeepSeek: un 402 de saldo
     * insuficiente es `sin_servicio` como cualquier non-2xx, y el motivo trae el status real.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function con_deepseek_sin_saldo_402_cinco_articulos_seguidos_la_asignacion_frena()
    {
        $this->cinco_seguidos_con_error(402, 'Insufficient Balance');
    }

    /**
     * Lo mismo con un 500 de DeepSeek.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function con_deepseek_caido_500_cinco_articulos_seguidos_la_asignacion_frena()
    {
        $this->cinco_seguidos_con_error(500, 'Service Unavailable');
    }

    /**
     * validate() (la validación individual: búsqueda por código del asistente y lote viejo) también
     * va a DeepSeek y lo registra.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function validate_individual_va_a_deepseek_y_registra_el_proveedor()
    {
        $articulo = $this->nuevo_articulo('Yerba mate 1 kg', self::CODIGO);

        $this->requests_ia = [];
        $test = $this;

        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::fake(function ($request) use ($test) {
            $test->requests_ia[] = ['url' => $request->url(), 'body' => $request->data()];

            return Http::response([
                'model'   => 'deepseek-flash-test',
                'content' => [['type' => 'text', 'text' => json_encode([
                    'es_el_producto' => true,
                    'tipo'           => 'producto',
                    'confianza'      => 'high',
                    'motivo'         => 'Se ve el paquete (prueba).',
                ])]],
                'usage'   => ['input_tokens' => 800, 'output_tokens' => 60],
            ], 200);
        });

        $veredicto = (new ArticleImageValidationService())->validate($this->png(600, 600, 'rojo'), $articulo, $this->owner->id);

        $this->assertTrue($veredicto['evaluated']);
        $this->assertSame('https://api.deepseek.com/anthropic/v1/messages', $this->requests_ia[0]['url']);

        $fila = ImageServiceCall::where('user_id', $this->owner->id)->orderBy('id', 'desc')->first();

        $this->assertSame(ImageServiceCall::ORIGEN_VALIDACION_INDIVIDUAL, $fila->origen);
        $this->assertSame('deepseek', $fila->proveedor);
        $this->assertSame('deepseek-flash-test', $fila->modelo);
    }

    /**
     * La validación de imágenes de categorías sigue la misma tarea.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function la_validacion_de_categorias_va_a_deepseek()
    {
        $this->requests_ia = [];
        $test = $this;

        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::fake(function ($request) use ($test) {
            $test->requests_ia[] = ['url' => $request->url(), 'body' => $request->data()];

            return Http::response([
                'model'   => 'deepseek-flash-test',
                'content' => [['type' => 'text', 'text' => json_encode([
                    'representa'   => true,
                    'fondo_blanco' => true,
                    'calidad'      => 'alta',
                    'confianza'    => 'high',
                    'motivo'       => 'Ollas sobre fondo blanco (prueba).',
                ])]],
                'usage'   => ['input_tokens' => 700, 'output_tokens' => 50],
            ], 200);
        });

        $resultado = (new CategoriaImagenValidacionService())->validar_para_categoria($this->png(600, 600, 'rojo'), 'Bazar', $this->owner->id, 1);

        $this->assertTrue($resultado['evaluated']);
        $this->assertSame('usar', $resultado['veredicto']);
        $this->assertSame('https://api.deepseek.com/anthropic/v1/messages', $this->requests_ia[0]['url']);
        $this->assertSame('deepseek-flash-test', $this->requests_ia[0]['body']['model']);

        $fila = AiTokenUsage::where('user_id', $this->owner->id)->where('proceso', 'validacion_imagen_categoria')->first();

        $this->assertSame('deepseek', $fila->proveedor);
    }

    /**
     * ia_disponible(): con solo la clave de DeepSeek alcanza; sin ninguna, el motivo arranca como
     * antes y nombra las dos variables.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function la_ia_esta_disponible_con_cualquiera_de_las_dos_claves()
    {
        config(['services.anthropic.api_key' => null]);

        $this->assertTrue(ImageAssignmentRunHelper::ia_disponible($this->owner)['configurada'], 'Con solo DeepSeek alcanza.');
        $this->assertTrue(ImageAssignmentRunHelper::ia_disponible()['configurada'], 'Sin dueño, igual.');

        config(['services.deepseek.api_key' => null]);

        $ia = ImageAssignmentRunHelper::ia_disponible($this->owner);

        $this->assertFalse($ia['configurada']);
        $this->assertStringStartsWith('Falta la clave de la IA (ANTHROPIC_API_KEY)', $ia['motivo']);
        $this->assertStringContainsString('DEEPSEEK_API_KEY', $ia['motivo']);
    }

    /**
     * El dueño se resuelve UNA vez por instancia: la segunda validación con el mismo user_id no
     * vuelve a consultar `users` (antes eran una o dos consultas por artículo).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function el_dueno_se_consulta_una_sola_vez_por_instancia()
    {
        $articulo = $this->nuevo_articulo('Yerba mate 1 kg', self::CODIGO);

        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::fake(function ($request) {
            return Http::response([
                'model'   => 'deepseek-flash-test',
                'content' => [['type' => 'text', 'text' => json_encode(['es_el_producto' => true, 'tipo' => 'producto', 'confianza' => 'high', 'motivo' => 'ok'])]],
                'usage'   => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200);
        });

        $servicio = new ArticleImageValidationService();
        $imagen   = $this->png(300, 300, 'rojo');

        $servicio->validate($imagen, $articulo, $this->owner->id);

        \DB::flushQueryLog();
        \DB::enableQueryLog();

        $servicio->validate($imagen, $articulo, $this->owner->id);

        $consultas_a_users = array_filter(\DB::getQueryLog(), function ($consulta) {
            return preg_match('/from [`"]?users[`"]?/i', $consulta['query']) === 1;
        });

        \DB::disableQueryLog();

        $this->assertCount(0, $consultas_a_users, 'La segunda validación de la misma instancia no vuelve a leer al dueño.');
    }

    /**
     * La clave de DeepSeek se tacha del texto que se guarda en el registro de consultas, igual que
     * las otras claves de config.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function sin_claves_tacha_tambien_la_clave_de_deepseek()
    {
        config(['services.deepseek.api_key' => 'sk-deepseek-CLAVE-DE-PRUEBA-123']);

        $limpio = ImageServiceCallLogger::sin_claves('Falló con la clave sk-deepseek-CLAVE-DE-PRUEBA-123 en el header.');

        $this->assertSame('Falló con la clave *** en el header.', $limpio);
    }

    /**
     * Siete artículos con la IA devolviendo `$status`: a los 5 seguidos la asignación frena con la
     * causa real (el status y el mensaje de DeepSeek).
     *
     * @param  int    $status
     * @param  string $mensaje
     * @return void
     */
    protected function cinco_seguidos_con_error($status, $mensaje)
    {
        $articulos = [];
        $serper    = [];
        $imagenes  = [];

        for ($i = 0; $i < 7; $i++) {
            $codigo = $this->con_verificador('77900090000'.$i);
            $url    = $this->url_imagen('deepseek-caido-'.$i);

            $articulos[] = $this->nuevo_articulo('Producto sin IA '.$i, $codigo);
            $serper[$codigo] = [$this->resultado($url, 900, 900, 1)];
            $imagenes[$url]  = $this->png(900, 900, 'rojo');
        }

        $run = $this->asignacion($articulos);

        $this->falsear_con_deepseek($serper, $imagenes, $status, $mensaje);

        Queue::fake();

        (new ProcessImageAssignmentRunJob($run->id))->handle();

        $run->refresh();

        $this->assertSame(ImageAssignmentRun::STATUS_FALLIDA, $run->status);
        $this->assertSame(5, (int) $run->errores_ia_seguidos);
        $this->assertStringContainsString('la IA respondió con error HTTP '.$status.': '.$mensaje, (string) $run->motivo_estado);

        foreach ($this->requests_ia as $request) {
            $this->assertStringContainsString('api.deepseek.com', $request['url']);
        }

        $consulta = ImageServiceCall::where('run_id', $run->id)->where('tipo', ImageServiceCall::TIPO_VALIDACION_IA)->first();

        $this->assertSame('deepseek', $consulta->proveedor);
        $this->assertSame($status, $consulta->http_status);
    }
}
