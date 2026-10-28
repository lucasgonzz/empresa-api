<?php

namespace Tests\Feature\DimensionSearch;

use App\Http\Controllers\Helpers\DimensionSearchRunHelper;
use App\Http\Controllers\Helpers\ImageAssignmentRunHelper;
use App\Models\Article;
use App\Models\DimensionSearchItem;
use App\Models\DimensionSearchRun;
use App\Models\User;
use App\Services\DimensionSearch\ArticleDimensionSearchEngine;
use App\Services\DimensionSearch\DimensionPageReader;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\EmpresaTestCase;
use Tests\Feature\DimensionSearch\Dobles\PaginasConDnsDePrueba;

/**
 * Base de los tests de la búsqueda masiva de peso y medidas (misión medidas-masivas-catalogo, 27/10/2026).
 *
 * 🔴 NINGÚN TEST LE PEGA A UNA API REAL NI A INTERNET. Todo pasa por un único Http::fake con un closure que
 * reparte por URL: Serper (resultados por consulta), las páginas (HTML armado en el test) y la IA (una
 * respuesta decidida por una función del test, que ve lo que el motor le mandó). El freno de internet de
 * Tests\TestCase hace fallar cualquier pedido que no esté falseado.
 *
 * Además: la guarda SSRF con DNS de prueba, claves de mentira en config y un comercio nuevo por test
 * (DatabaseTransactions de EmpresaTestCase lo borra).
 */
abstract class DimensionSearchTestCase extends EmpresaTestCase
{
    /** @var \App\Models\User Comercio nuevo de cada test. */
    protected $owner;

    /** @var array Consultas que recibió el Serper falso, en orden. */
    protected $consultas_serper = [];

    /** @var array Lo que recibió la IA falsa (cada pedido decodificado), en orden. */
    protected $pedidos_ia = [];

    /** @var array URLs de páginas que el motor pidió, en orden. */
    protected $paginas_pedidas = [];

    /** @var array URLs que el motor le pidió al scrape de Serper (el respaldo), en orden. */
    protected $scrapes_pedidos = [];

    /** @var array Las cabeceras X-API-KEY con las que llegaron los pedidos al scrape. */
    protected $claves_del_scrape = [];

    protected function setUp(): void
    {
        parent::setUp();

        // La guarda SSRF de producción, con DNS de prueba (los hosts .test no resuelven).
        app()->bind(DimensionPageReader::class, PaginasConDnsDePrueba::class);

        config([
            'services.serper.api_key'                          => 'SERPER-DE-PRUEBA',
            'services.deepseek.api_key'                        => 'DEEPSEEK-DE-PRUEBA',
            'services.deepseek.base_url'                       => 'https://ia.test/anthropic',
            'services.anthropic.api_key'                       => '',
            'services.medidas_masivas.segundos_por_tramo'      => 50,
            'services.medidas_masivas.tope_catalogo'           => 5000,
        ]);

        $this->owner = User::create([
            'name'         => 'Comercio medidas masivas',
            'company_name' => 'Repuestos medidas masivas',
            'email'        => 'medidas-masivas-'.uniqid().'@test.local',
            'password'     => Hash::make('secret'),
        ]);

        // 🔴 A propósito SIN la extensión `agentes_ia`: la búsqueda de medidas no la exige (decisión de producto
        // del 27/10/2026, Masquito no la tiene). Todos los tests de esta carpeta corren así.

        $this->actuar_como($this->owner);
    }

    /**
     * Autentica para las requests que siguen, opcionalmente con la sesión del acceso maestro.
     *
     * 🔴 El forgetGuards() no es decorativo: sanctum cachea el usuario que resolvió la primera vez dentro del mismo container.
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
     * Una página de producto de prueba.
     *
     * @param  string $titulo
     * @param  string $cuerpo  Texto plano (lo que se ve en la página).
     * @return string  HTML.
     */
    protected function pagina($titulo, $cuerpo)
    {
        return '<html><head><title>'.$titulo.'</title></head><body><nav>Menú</nav><main><h1>'.$titulo.'</h1><p>'.$cuerpo.'</p></main><footer>Pie</footer></body></html>';
    }

    /**
     * Un resultado orgánico de Serper.
     *
     * @param  string $url
     * @param  string $titulo
     * @return array
     */
    protected function resultado($url, $titulo = 'Producto de prueba')
    {
        return ['title' => $titulo, 'link' => $url, 'snippet' => 'Resultado de prueba', 'position' => 1];
    }

    /**
     * Un dato físico como lo devuelve la IA.
     *
     * @param  mixed  $valor
     * @param  string $unidad
     * @param  string $cita
     * @param  string $url
     * @param  string $alcance
     * @return array
     */
    protected function dato($valor, $unidad, $cita, $url, $alcance = 'producto')
    {
        return ['value' => $valor, 'unit' => $unidad, 'evidence' => $cita, 'source_url' => $url, 'scope' => $alcance];
    }

    /**
     * La respuesta de la IA para un producto: el match, la nota y los cuatro datos (null = sin evidencia).
     *
     * @param  string      $match
     * @param  array       $datos  peso, ancho, alto, largo => dato() | null.
     * @param  string|null $nota
     * @return array
     */
    protected function ficha($match, array $datos = [], $nota = null)
    {
        return [
            'match'  => $match,
            'nota'   => $nota,
            'name'   => 'Producto de prueba',
            'brand'  => 'Marca',
            'weight' => isset($datos['peso']) ? $datos['peso'] : null,
            'width'  => isset($datos['ancho']) ? $datos['ancho'] : null,
            'height' => isset($datos['alto']) ? $datos['alto'] : null,
            'length' => isset($datos['largo']) ? $datos['largo'] : null,
        ];
    }

    /**
     * El único Http::fake de cada test.
     *
     * @param  array                $serper   consulta => [resultados de resultado()]; 'error' => el mensaje de
     *                                        un 500 de Serper para TODAS las consultas.
     * @param  array                $paginas  url => HTML, o url => ['status' => 404] / ['html' => ..., 'tipo' => 'application/pdf'].
     * @param  array|string|callable $ia      Una ficha() fija, una función (array $pedido, int $n) que devuelve la
     *                                        ficha() según lo que se le mandó, o 'caida' (la IA responde 500) /
     *                                        'ilegible' (responde algo que no es JSON).
     * @param  array                $scrape   url => texto plano que devuelve scrape.serper.dev (créditos 2), o
     *                                        url => ['text' => ..., 'credits' => 4, 'title' => ...], o url => ['status' => 500].
     *                                        Una URL que no está acá responde 404 (el respaldo no la baja).
     * @return void
     */
    protected function falsear(array $serper, array $paginas, $ia, array $scrape = [])
    {
        $this->consultas_serper = [];
        $this->pedidos_ia       = [];
        $this->paginas_pedidas  = [];
        $this->scrapes_pedidos  = [];
        $this->claves_del_scrape = [];

        $test = $this;

        // Un cliente HTTP nuevo en cada llamada: Http::fake() ACUMULA los stubs y el primero que responde gana.
        Http::swap(new \Tests\Fakes\HttpFactorySinSalida(app('events')));

        Http::fake(function ($request) use ($test, $serper, $paginas, $ia, $scrape) {
            $url = $request->url();

            // El respaldo: scrape.serper.dev devuelve {text, metadata, credits} con el texto plano de la URL pedida.
            if (strpos($url, 'scrape.serper.dev') !== false) {
                $objetivo = isset($request->data()['url']) ? (string) $request->data()['url'] : '';
                $test->scrapes_pedidos[]   = $objetivo;
                $test->claves_del_scrape[] = implode(',', (array) $request->header('X-API-KEY'));

                if (!isset($scrape[$objetivo])) {
                    return Http::response(['message' => 'No se pudo leer (fake)'], 404);
                }

                $devuelto = $scrape[$objetivo];

                if (is_array($devuelto) && isset($devuelto['status'])) {
                    return Http::response(['message' => 'Error del respaldo (fake)'], $devuelto['status']);
                }

                return Http::response([
                    'text'     => is_array($devuelto) ? $devuelto['text'] : $devuelto,
                    'metadata' => ['title' => is_array($devuelto) && isset($devuelto['title']) ? $devuelto['title'] : 'Título del respaldo'],
                    'credits'  => is_array($devuelto) && isset($devuelto['credits']) ? $devuelto['credits'] : 2,
                ], 200);
            }

            if (strpos($url, 'google.serper.dev') !== false) {
                $consulta = isset($request->data()['q']) ? (string) $request->data()['q'] : '';
                $test->consultas_serper[] = $consulta;

                if (isset($serper['error'])) {
                    return Http::response(['message' => $serper['error'], 'statusCode' => 500], 500);
                }

                return Http::response(['organic' => isset($serper[$consulta]) ? array_values($serper[$consulta]) : []], 200);
            }

            if (strpos($url, 'ia.test') !== false) {
                $pedido = $request->data();
                $entrada = json_decode($pedido['messages'][0]['content'], true);
                $test->pedidos_ia[] = ['payload' => $pedido, 'entrada' => $entrada];

                if ($ia === 'caida') {
                    return Http::response(['error' => ['message' => 'Servicio no disponible (fake)']], 500);
                }

                $texto = 'No sé qué decir.';

                if ($ia !== 'ilegible') {
                    $ficha = is_callable($ia) ? $ia($entrada, count($test->pedidos_ia)) : $ia;
                    $texto = json_encode($ficha, JSON_UNESCAPED_UNICODE);
                }

                return Http::response([
                    'id'          => 'msg_prueba',
                    'type'        => 'message',
                    'role'        => 'assistant',
                    'model'       => 'deepseek-flash',
                    'content'     => [['type' => 'text', 'text' => $texto]],
                    'stop_reason' => 'end_turn',
                    'usage'       => ['input_tokens' => 1800, 'output_tokens' => 120],
                ], 200);
            }

            if (isset($paginas[$url])) {
                $test->paginas_pedidas[] = $url;
                $pagina = $paginas[$url];

                if (is_array($pagina) && isset($pagina['status'])) {
                    return Http::response('error', $pagina['status'], ['Content-Type' => 'text/html']);
                }

                $html = is_array($pagina) ? $pagina['html'] : $pagina;
                $tipo = is_array($pagina) && isset($pagina['tipo']) ? $pagina['tipo'] : 'text/html; charset=utf-8';

                return Http::response($html, 200, ['Content-Type' => $tipo]);
            }

            return Http::response('no encontrada', 404, ['Content-Type' => 'text/plain']);
        });
    }

    /**
     * Crea una corrida con esos artículos (sin despachar de verdad el job).
     *
     * @param  array $articulos
     * @param  array $opciones  origen, solo_con_stock
     * @return \App\Models\DimensionSearchRun
     */
    protected function corrida(array $articulos, array $opciones = [])
    {
        Queue::fake();

        $ids = [];

        foreach ($articulos as $articulo) {
            $ids[] = (int) $articulo->id;
        }

        return DimensionSearchRunHelper::crear(
            $this->owner,
            $ids,
            isset($opciones['origen']) ? $opciones['origen'] : DimensionSearchRun::ORIGEN_CATALOGO,
            $this->owner->id,
            ['solo_con_stock' => !empty($opciones['solo_con_stock'])]
        );
    }

    /**
     * Corre el motor sobre el item de un artículo (como lo haría el tramo, ya reclamado).
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @param  \App\Models\Article            $articulo
     * @return array  [item cerrado, resultado del motor]
     */
    protected function procesar(DimensionSearchRun $run, Article $articulo)
    {
        $item = DimensionSearchItem::where('run_id', $run->id)->where('article_id', $articulo->id)->firstOrFail();

        $item->status   = DimensionSearchItem::STATUS_PROCESANDO;
        $item->intentos = 1;
        $item->tramo    = 'tramo-de-prueba';
        $item->save();

        $resultado = (new ArticleDimensionSearchEngine($run->fresh(), $this->owner))->procesar($item->fresh());

        return [$item->fresh(), $resultado];
    }

    /**
     * Un artículo, su corrida y el motor ya corrido (atajo para los tests de clasificación).
     *
     * @param  \App\Models\Article $articulo
     * @return array  [item, resultado]
     */
    protected function buscar(Article $articulo)
    {
        return $this->procesar($this->corrida([$articulo]), $articulo);
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

    /**
     * Un item armado a mano en el estado pedido (para los tests de revisión).
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @param  \App\Models\Article            $articulo
     * @param  string                         $estado
     * @param  array                          $datos   Columnas del item.
     * @return \App\Models\DimensionSearchItem
     */
    protected function item_en($run, $articulo, $estado, array $datos = [])
    {
        return DimensionSearchItem::create(array_merge([
            'run_id'                => $run->id,
            'user_id'               => $run->user_id,
            'article_id'            => $articulo->id,
            'article_name'          => $articulo->name,
            'article_bar_code'      => $articulo->bar_code,
            'article_provider_code' => $articulo->provider_code,
            'orden'                 => 1,
            'status'                => $estado,
            'motivo'                => 'medidas_parciales',
            'peso_kg'               => 0.82,
            'alto_cm'               => 1.1,
            'ancho_cm'              => 7,
            'profundidad_cm'        => 10,
            'evidencia'             => [
                'peso'        => ['valor' => 0.82, 'unidad' => 'kg', 'valor_original' => 820, 'unidad_original' => 'g', 'convertido' => true, 'cita' => 'Peso: 820 g', 'url' => 'https://tienda.test/p', 'alcance' => 'producto'],
                'alto'        => ['valor' => 1.1, 'unidad' => 'cm', 'valor_original' => 11, 'unidad_original' => 'mm', 'convertido' => true, 'cita' => '70 x 11 x 100 mm', 'url' => 'https://tienda.test/p', 'alcance' => 'producto'],
                'ancho'       => ['valor' => 7, 'unidad' => 'cm', 'valor_original' => 70, 'unidad_original' => 'mm', 'convertido' => true, 'cita' => '70 x 11 x 100 mm', 'url' => 'https://tienda.test/p', 'alcance' => 'producto'],
                'profundidad' => ['valor' => 10, 'unidad' => 'cm', 'valor_original' => 100, 'unidad_original' => 'mm', 'convertido' => true, 'cita' => '70 x 11 x 100 mm', 'url' => 'https://tienda.test/p', 'alcance' => 'producto'],
            ],
            'fuentes'               => [['url' => 'https://tienda.test/p', 'titulo' => 'Producto']],
        ], $datos));
    }

    /**
     * Un comercio ajeno (para los tests de tenencia).
     *
     * @return \App\Models\User
     */
    protected function otro_comercio()
    {
        $otro = User::create([
            'name'         => 'Otro comercio',
            'company_name' => 'Otro comercio SRL',
            'email'        => 'otro-medidas-'.uniqid().'@test.local',
            'password'     => Hash::make('secret'),
        ]);

        return $otro;
    }
}
