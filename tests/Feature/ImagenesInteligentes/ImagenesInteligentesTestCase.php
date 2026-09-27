<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Http\Controllers\Helpers\ImageAssignmentRunHelper;
use App\Models\Article;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Models\User;
use App\Services\BusquedaPorCodigoDeBarrasService;
use App\Services\ImageAssignment\ArticleImageAssignmentEngine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\EmpresaTestCase;
use Tests\Feature\ImagenesInteligentes\Dobles\GuardaSsrfConDnsDePrueba;

/**
 * Base de los tests de las asignaciones inteligentes de imágenes (misión imagenes-catalogo-completo,
 * 27/9/2026).
 *
 * 🔴 NINGÚN TEST LE PEGA A UNA API REAL. Todo pasa por un único Http::fake con un closure que
 * reparte por URL: Serper (resultados por consulta), las imágenes (PNG generados con GD, de distintos
 * tamaños y fondos) y Anthropic (un veredicto por candidata, decidido por el COLOR del producto que
 * se ve en la miniatura que el motor le manda a la IA: así el fake contesta por la imagen de verdad
 * y no por el orden en que llegan).
 *
 * Además: el disco público falseado (Storage::fake), la guarda SSRF con DNS de prueba, claves de
 * mentira en config y un comercio nuevo por test (DatabaseTransactions de EmpresaTestCase lo borra).
 */
abstract class ImagenesInteligentesTestCase extends EmpresaTestCase
{
    /** Colores de "producto" de las imágenes de prueba: cada imagen se reconoce por el suyo. */
    const COLORES = [
        'rojo'     => [200, 30, 30],
        'azul'     => [30, 30, 200],
        'verde'    => [30, 160, 30],
        'amarillo' => [230, 200, 20],
        'violeta'  => [140, 30, 160],
        'naranja'  => [240, 120, 10],
        'negro'    => [20, 20, 20],
        'cian'     => [20, 180, 200],
    ];

    /** Fondo blanco y un fondo celeste (no blanco) para las imágenes de prueba. */
    const FONDO_BLANCO  = [255, 255, 255];
    const FONDO_CELESTE = [170, 210, 240];

    /** @var \App\Models\User Comercio nuevo de cada test. */
    protected $owner;

    /** @var array Consultas que recibió el Serper falso, en orden. */
    protected $consultas_serper = [];

    /** @var int Llamadas que recibió la IA falsa. */
    protected $llamadas_ia = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        // La guarda SSRF de producción, con DNS de prueba (los hosts .test no resuelven).
        app()->bind(BusquedaPorCodigoDeBarrasService::class, GuardaSsrfConDnsDePrueba::class);

        config([
            'services.serper.api_key'                          => 'SERPER-DE-PRUEBA',
            'services.anthropic.api_key'                       => 'ANTHROPIC-DE-PRUEBA',
            'services.article_image_validation.enabled'        => true,
            'services.article_image_validation.max_calls_batch' => 300,
            'services.imagenes_inteligentes.segundos_por_tramo' => 50,
            'services.imagenes_inteligentes.tope_catalogo'     => 5000,
        ]);

        $this->owner = User::create([
            'name'         => 'Comercio imágenes inteligentes',
            'company_name' => 'Ferretería imágenes inteligentes',
            'email'        => 'imagenes-inteligentes-'.uniqid().'@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->actuar_como($this->owner);
    }

    /**
     * Autentica para las requests que siguen, opcionalmente con la sesión del acceso maestro.
     *
     * 🔴 El forgetGuards() no es decorativo: sanctum cachea el usuario que resolvió la primera vez
     * dentro del mismo container (documentado en tests/Feature/Alertas/1_Ventas_sin_cobrar_dias_Test).
     *
     * @param  \App\Models\User $user
     * @param  bool $maestro
     * @return void
     */
    protected function actuar_como($user, $maestro = false)
    {
        Auth::forgetGuards();

        $this->actingAs($user, 'web');

        if ($maestro) {
            $this->withSession([ImageAssignmentRunHelper::CLAVE_DE_SESION_MAESTRO => true]);
        }
    }

    /**
     * Un artículo activo del comercio del test.
     *
     * @param  string      $nombre
     * @param  string|null $bar_code
     * @param  array       $extra
     * @return \App\Models\Article
     */
    protected function nuevo_articulo($nombre, $bar_code = null, array $extra = [])
    {
        return Article::create(array_merge([
            'name'     => $nombre,
            'bar_code' => $bar_code,
            'user_id'  => $this->owner->id,
            'status'   => 'active',
        ], $extra));
    }

    /**
     * Completa el dígito verificador GS1 de un cuerpo de 7, 11, 12 o 13 dígitos.
     *
     * @param  string $cuerpo
     * @return string
     */
    protected function con_verificador($cuerpo)
    {
        $suma = 0;

        for ($i = strlen($cuerpo) - 1, $posicion = 1; $i >= 0; $i--, $posicion++) {
            $suma += ((int) $cuerpo[$i]) * ($posicion % 2 === 1 ? 3 : 1);
        }

        return $cuerpo.((10 - $suma % 10) % 10);
    }

    /**
     * Una imagen PNG generada: fondo de un color y un "producto" rectangular en el centro (del 30 %
     * al 70 % del ancho y del 20 % al 80 % del alto), del color que la identifica.
     *
     * @param  int    $ancho
     * @param  int    $alto
     * @param  string $color  Clave de COLORES.
     * @param  array  $fondo  RGB del fondo.
     * @param  array  $bandas Opcional: ['arriba' => rgb, 'abajo' => rgb] franjas de 3 % en los bordes
     *                        de arriba y de abajo del producto (para probar que no se recorta).
     * @return string
     */
    protected function png($ancho, $alto, $color = 'rojo', array $fondo = self::FONDO_BLANCO, array $bandas = [])
    {
        $imagen = imagecreatetruecolor($ancho, $alto);

        $color_fondo = imagecolorallocate($imagen, $fondo[0], $fondo[1], $fondo[2]);
        imagefilledrectangle($imagen, 0, 0, $ancho - 1, $alto - 1, $color_fondo);

        $rgb = self::COLORES[$color];
        $color_producto = imagecolorallocate($imagen, $rgb[0], $rgb[1], $rgb[2]);
        imagefilledrectangle($imagen, (int) ($ancho * 0.3), (int) ($alto * 0.2), (int) ($ancho * 0.7), (int) ($alto * 0.8), $color_producto);

        if (isset($bandas['arriba'])) {
            $c = imagecolorallocate($imagen, $bandas['arriba'][0], $bandas['arriba'][1], $bandas['arriba'][2]);
            imagefilledrectangle($imagen, (int) ($ancho * 0.3), 0, (int) ($ancho * 0.7), (int) ($alto * 0.03), $c);
        }

        if (isset($bandas['abajo'])) {
            $c = imagecolorallocate($imagen, $bandas['abajo'][0], $bandas['abajo'][1], $bandas['abajo'][2]);
            imagefilledrectangle($imagen, (int) ($ancho * 0.3), (int) ($alto * 0.97), (int) ($ancho * 0.7), $alto - 1, $c);
        }

        ob_start();
        imagepng($imagen);
        $datos = ob_get_clean();

        imagedestroy($imagen);

        return $datos;
    }

    /**
     * URL de una imagen de prueba.
     *
     * @param  string $nombre
     * @return string
     */
    protected function url_imagen($nombre)
    {
        return 'https://imagenes.test/'.$nombre.'.png';
    }

    /**
     * Un resultado de Serper (la forma de `images[]`).
     *
     * @param  string   $url
     * @param  int|null $ancho
     * @param  int|null $alto
     * @param  int      $posicion
     * @param  string   $titulo
     * @return array
     */
    protected function resultado($url, $ancho, $alto, $posicion, $titulo = 'Producto de prueba')
    {
        return array_filter([
            'title'        => $titulo,
            'imageUrl'     => $url,
            'imageWidth'   => $ancho,
            'imageHeight'  => $alto,
            'thumbnailUrl' => $url.'?miniatura',
            'link'         => 'https://tienda.test/producto-'.$posicion,
            'domain'       => 'tienda.test',
            'position'     => $posicion,
        ], function ($valor) {
            return !is_null($valor);
        });
    }

    /**
     * Un veredicto de la IA falsa para una candidata.
     *
     * @param  string $es_el_producto  si | no | dudoso
     * @param  string $confianza       high | medium | low
     * @param  array  $problemas
     * @return array
     */
    protected function veredicto($es_el_producto, $confianza = 'high', array $problemas = [])
    {
        return [
            'es_el_producto' => $es_el_producto,
            'confianza'      => $confianza,
            'fondo_blanco'   => true,
            'problemas'      => $problemas,
            'motivo'         => 'Veredicto de prueba: '.$es_el_producto.' ('.$confianza.').',
        ];
    }

    /**
     * El único Http::fake de cada test.
     *
     * @param  array                 $serper     consulta => [resultados de resultado()]; 'error' => el
     *                                           mensaje de un 500 de Serper para TODAS las consultas.
     * @param  array                 $imagenes   url => binario, o url => ['redirige_a' => Location]
     * @param  array|string|callable $ia         color => veredicto(); 'caida' = Anthropic responde 500.
     * @param  callable|null         $al_buscar  Se llama con cada consulta a Serper (para simular algo
     *                                           que pasa en el medio de una corrida).
     * @return void
     */
    protected function falsear(array $serper, array $imagenes, $ia, $al_buscar = null)
    {
        $this->consultas_serper = [];
        $this->llamadas_ia      = 0;

        $test = $this;

        // Un cliente HTTP nuevo en cada llamada: Http::fake() ACUMULA los stubs y el primero que
        // responde gana, así que un segundo falsear() en el mismo test no pisaría al primero.
        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));

        Http::fake(function ($request) use ($test, $serper, $imagenes, $ia, $al_buscar) {
            $url = $request->url();

            if (strpos($url, 'google.serper.dev') !== false) {
                $consulta = isset($request->data()['q']) ? (string) $request->data()['q'] : '';
                $test->consultas_serper[] = $consulta;

                if (!is_null($al_buscar)) {
                    $al_buscar($consulta);
                }

                if (isset($serper['error'])) {
                    return Http::response(['message' => $serper['error'], 'statusCode' => 500], 500);
                }

                return Http::response(['images' => isset($serper[$consulta]) ? array_values($serper[$consulta]) : []], 200);
            }

            if (strpos($url, 'api.anthropic.com') !== false) {
                $test->llamadas_ia++;

                if ($ia === 'caida') {
                    return Http::response(['error' => ['message' => 'Servicio no disponible (fake)']], 500);
                }

                return $test->respuesta_de_ia($request, $ia);
            }

            if (isset($imagenes[$url])) {
                // ['redirige_a' => 'destino'] = un 302 con ese Location (tal cual, relativo o absoluto).
                if (is_array($imagenes[$url]) && isset($imagenes[$url]['redirige_a'])) {
                    return Http::response('', 302, ['Location' => $imagenes[$url]['redirige_a']]);
                }

                return Http::response($imagenes[$url], 200, ['Content-Type' => 'image/png']);
            }

            return Http::response('no encontrada', 404, ['Content-Type' => 'text/plain']);
        });
    }

    /**
     * La respuesta de la IA falsa: por cada "Candidata N:" mira el color de la miniatura que le
     * mandaron y contesta el veredicto de ese color (o "no" si el color no está en el mapa).
     *
     * @param  \Illuminate\Http\Client\Request $request
     * @param  array|callable $mapa
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function respuesta_de_ia($request, $mapa)
    {
        $contenido = $request->data()['messages'][0]['content'];

        $veredictos = [];
        $indice     = null;

        foreach ($contenido as $bloque) {
            if ($bloque['type'] === 'text' && preg_match('/^Candidata (\d+):$/', $bloque['text'], $coincidencia)) {
                $indice = (int) $coincidencia[1];
                continue;
            }

            if ($bloque['type'] === 'image' && !is_null($indice)) {
                $color = $this->color_de_la_miniatura($bloque['source']['data']);

                $veredicto = is_callable($mapa)
                    ? $mapa($color)
                    : (isset($mapa[$color]) ? $mapa[$color] : $this->veredicto('no'));

                $veredictos[] = array_merge(['indice' => $indice], $veredicto);
                $indice = null;
            }
        }

        return Http::response([
            'id'          => 'msg_prueba',
            'type'        => 'message',
            'role'        => 'assistant',
            'model'       => 'claude-haiku-4-5-20251001',
            'content'     => [['type' => 'text', 'text' => json_encode(['candidatas' => $veredictos])]],
            'stop_reason' => 'end_turn',
            'usage'       => ['input_tokens' => 1200, 'output_tokens' => 150],
        ], 200);
    }

    /**
     * El color (clave de COLORES) del centro de una miniatura en base64.
     *
     * @param  string $base64
     * @return string
     */
    protected function color_de_la_miniatura($base64)
    {
        $imagen = imagecreatefromstring(base64_decode($base64));

        $pixel = imagecolorat($imagen, (int) (imagesx($imagen) / 2), (int) (imagesy($imagen) / 2));
        $rgb   = [($pixel >> 16) & 0xFF, ($pixel >> 8) & 0xFF, $pixel & 0xFF];

        imagedestroy($imagen);

        $mejor     = null;
        $distancia = null;

        foreach (self::COLORES as $nombre => $referencia) {
            $d = pow($rgb[0] - $referencia[0], 2) + pow($rgb[1] - $referencia[1], 2) + pow($rgb[2] - $referencia[2], 2);

            if (is_null($distancia) || $d < $distancia) {
                $mejor     = $nombre;
                $distancia = $d;
            }
        }

        return $mejor;
    }

    /**
     * Crea una asignación con esos artículos (sin despachar de verdad el job).
     *
     * @param  array $articulos
     * @param  array $opciones  origen, aplica_tope_diario, proveedor
     * @return \App\Models\ImageAssignmentRun
     */
    protected function asignacion(array $articulos, array $opciones = [])
    {
        Queue::fake();

        $ids = [];

        foreach ($articulos as $articulo) {
            $ids[] = (int) $articulo->id;
        }

        return ImageAssignmentRunHelper::crear(
            $this->owner,
            $ids,
            isset($opciones['origen']) ? $opciones['origen'] : ImageAssignmentRun::ORIGEN_SELECCION,
            $this->owner->id,
            [
                'proveedor'          => isset($opciones['proveedor']) ? $opciones['proveedor'] : ImageAssignmentRun::PROVEEDOR_SERPER,
                'aplica_tope_diario' => isset($opciones['aplica_tope_diario']) ? $opciones['aplica_tope_diario'] : false,
            ]
        );
    }

    /**
     * Corre el motor sobre el item de un artículo (como lo haría el tramo, ya reclamado).
     *
     * @param  \App\Models\ImageAssignmentRun $run
     * @param  \App\Models\Article            $articulo
     * @return \App\Models\ImageAssignmentItem  El item ya cerrado.
     */
    protected function procesar(ImageAssignmentRun $run, Article $articulo)
    {
        $item = ImageAssignmentItem::where('run_id', $run->id)->where('article_id', $articulo->id)->firstOrFail();

        $item->status   = ImageAssignmentItem::STATUS_PROCESANDO;
        $item->intentos = 1;
        $item->save();

        (new ArticleImageAssignmentEngine($run->fresh()))->procesar($item);

        return $item->fresh();
    }

    /**
     * La entrada del diagnóstico de un criterio.
     *
     * @param  \App\Models\ImageAssignmentItem $item
     * @param  string $criterio  codigo_de_barras | nombre
     * @return array|null
     */
    protected function diagnostico_de(ImageAssignmentItem $item, $criterio)
    {
        foreach ((array) $item->diagnostico as $entrada) {
            if ($entrada['criterio'] === $criterio) {
                return $entrada;
            }
        }

        return null;
    }

    /**
     * Cuántas requests salieron a una URL que contiene ese fragmento.
     *
     * @param  string $fragmento
     * @return int
     */
    protected function requests_a($fragmento)
    {
        $cantidad = 0;

        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), $fragmento) !== false) {
                $cantidad++;
            }
        }

        return $cantidad;
    }
}
