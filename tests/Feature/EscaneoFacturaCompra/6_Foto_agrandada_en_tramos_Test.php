<?php

namespace Tests\Feature\EscaneoFacturaCompra;

use App\Models\ProviderOrder;
use App\Models\ProviderOrderScan;
use App\Models\ProviderOrderScanImage;
use App\Models\User;
use App\Services\EscaneoFacturaCompraService;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;
use Tests\Feature\AgentesIa\OtorgaAgentesIa;

/**
 * Misión escaneo-factura-haiku: la foto se agranda y se corta en tramos antes de leerla.
 *
 * Agrandar es lo que arregló los códigos mal leídos del modelo económico (de 17 a 0 en la medición).
 * Lo que protege este archivo:
 *
 * - Que la geometría sea la decidida: ancho hasta 1560, escala hasta ×3, tramos de 1100 con 165 de
 *   solape. Con otra, los números de la misión dejan de valer sin que nada se rompa.
 * - 🔴 Que sin memoria la foto salga como siempre. Un error fatal de memoria no se atrapa: tumba el
 *   worker, y el escaneo queda colgado junto con todo lo que hubiera en la cola.
 * - Que `pagina` siga siendo la página real, no el tramo, y que `paginas_procesadas` cuente páginas.
 *
 * Las imágenes se generan con GD en cada test: acá no hay ninguna foto real. 🔴 SEGURO DE GASTO: igual
 * que en los otros archivos, cada test prende la clave en el mismo paso que registra su fake.
 */
class Foto_agrandada_en_tramos_Test extends EmpresaTestCase
{
    use OtorgaAgentesIa;

    /**
     * Dueño de los datos (la empresa del fixture).
     *
     * @var \App\Models\User
     */
    protected $owner;

    /**
     * Compra a la que pertenecen los escaneos de este archivo.
     *
     * @var \App\Models\ProviderOrder
     */
    protected $compra;

    /**
     * Rutas absolutas de las fotos escritas en disco por los tests, para borrarlas al terminar.
     *
     * @var array
     */
    protected $archivos_creados = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        Notification::fake();

        $this->owner = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->otorgar_agentes_ia($this->owner);

        $this->compra = ProviderOrder::create([
            'provider_id' => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS)->id,
            'user_id'     => $this->owner->id,
        ]);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->archivos_creados as $ruta) {
            if (is_file($ruta)) {
                @unlink($ruta);
                @rmdir(dirname($ruta));
            }
        }

        $this->archivos_creados = [];

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ *
     *  Helpers de fixture
     * ------------------------------------------------------------------ */

    /**
     * Crea un escaneo vacío de la compra del fixture.
     *
     * @return \App\Models\ProviderOrderScan
     */
    protected function escaneo()
    {
        return ProviderOrderScan::create([
            'uuid'              => Str::uuid()->toString(),
            'user_id'           => $this->owner->id,
            'auth_user_id'      => $this->owner->id,
            'provider_order_id' => $this->compra->id,
            'estado'            => 'pendiente',
            'progreso'          => 0,
        ]);
    }

    /**
     * Agrega una foto generada al escaneo. Cada fila tiene su propio color, así el solape entre
     * tramos se puede comparar pixel por pixel.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @param  int                            $orden
     * @param  int                            $ancho
     * @param  int                            $alto
     * @return void
     */
    protected function foto(ProviderOrderScan $scan, int $orden, int $ancho, int $alto)
    {
        $gd = imagecreatetruecolor($ancho, $alto);

        for ($y = 0; $y < $alto; $y++) {
            imageline($gd, 0, $y, $ancho - 1, $y, imagecolorallocate($gd, $y % 251, ($y * 3) % 251, ($y * 7) % 251));
        }

        ob_start();
        imagejpeg($gd, null, 95);
        $binario = ob_get_clean();

        imagedestroy($gd);

        $this->guardar_foto($scan, $orden, $binario, 'image/jpeg');
    }

    /**
     * Escribe el archivo de una foto y crea su fila.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @param  int                            $orden
     * @param  string                         $binario
     * @param  string                         $mime
     * @return void
     */
    protected function guardar_foto(ProviderOrderScan $scan, int $orden, string $binario, string $mime)
    {
        $ruta_relativa = 'provider_order_scans/' . $scan->user_id . '/' . $scan->uuid . '/' . $orden . '.jpg';
        $ruta_absoluta = storage_path('app/' . $ruta_relativa);

        if (!is_dir(dirname($ruta_absoluta))) {
            mkdir(dirname($ruta_absoluta), 0777, true);
        }

        file_put_contents($ruta_absoluta, $binario);

        $this->archivos_creados[] = $ruta_absoluta;

        ProviderOrderScanImage::create([
            'provider_order_scan_id' => $scan->id,
            'provider_order_id'      => $scan->provider_order_id,
            'user_id'                => $scan->user_id,
            'orden'                  => $orden,
            'path'                   => $ruta_relativa,
            'mime'                   => $mime,
            'bytes'                  => strlen($binario),
            'nombre_original'        => 'pagina' . $orden . '.jpg',
        ]);
    }

    /**
     * Prende la clave y registra una lectura que pasa el control.
     *
     * @param  array  $paginas_de_los_renglones  El campo `pagina` que "lee" el modelo en cada renglón
     * @return void
     */
    protected function fake_de_lectura_buena(array $paginas_de_los_renglones = [1])
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $articulos = [];

        foreach ($paginas_de_los_renglones as $indice => $pagina) {
            $articulos[] = [
                'fila'             => $indice + 1,
                'pagina'           => $pagina,
                'codigo_proveedor' => 'INV-' . $indice,
                'nombre'           => 'ARTICULO INVENTADO ' . $indice,
                'cantidad'         => 1,
                'costo_unitario'   => 100,
                'total_linea'      => 100,
            ];
        }

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model'       => 'claude-modelo-fake',
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => json_encode(['articulos' => $articulos])]],
                'usage'       => ['input_tokens' => 10, 'output_tokens' => 10],
            ], 200),
        ]);
    }

    /**
     * Imágenes de la primera llamada: rótulo que las precede, tipo, medidas y binario.
     *
     * @return array
     */
    protected function imagenes_mandadas(): array
    {
        $content = Http::recorded()->first()[0]->data()['messages'][0]['content'];

        $imagenes = [];

        foreach ($content as $indice => $bloque) {
            if ($bloque['type'] !== 'image') {
                continue;
            }

            $binario = base64_decode($bloque['source']['data']);
            $medidas = getimagesizefromstring($binario);

            $imagenes[] = [
                'rotulo'     => $content[$indice - 1]['text'],
                'media_type' => $bloque['source']['media_type'],
                'ancho'      => $medidas[0],
                'alto'       => $medidas[1],
                'binario'    => $binario,
            ];
        }

        return $imagenes;
    }

    /**
     * Texto de las instrucciones de la primera llamada (el último bloque).
     *
     * @return string
     */
    protected function instrucciones_mandadas(): string
    {
        $content = Http::recorded()->first()[0]->data()['messages'][0]['content'];

        return $content[count($content) - 1]['text'];
    }

    /* ------------------------------------------------------------------ *
     *  El agrandado
     * ------------------------------------------------------------------ */

    /**
     * Una foto chica se agranda ×3 y, si entra en un tramo, va entera y con el rótulo de siempre.
     *
     * @test
     * @return void
     */
    public function una_foto_chica_se_agranda_por_tres_y_entra_en_un_tramo()
    {
        $this->fake_de_lectura_buena();

        $scan = $this->escaneo();
        $this->foto($scan, 1, 120, 90);

        (new EscaneoFacturaCompraService())->escanear($scan);

        $imagenes = $this->imagenes_mandadas();

        $this->assertCount(1, $imagenes);
        $this->assertSame('image/png', $imagenes[0]['media_type']);
        $this->assertSame(360, $imagenes[0]['ancho']);
        $this->assertSame(270, $imagenes[0]['alto']);
        $this->assertSame('PÁGINA 1 de 1', $imagenes[0]['rotulo']);
    }

    /**
     * La escala nunca pasa de ×3, aunque el ancho quede lejos de 1560: 900 de ancho y dos tramos.
     *
     * @test
     * @return void
     */
    public function la_escala_no_pasa_de_tres()
    {
        $this->fake_de_lectura_buena();

        $scan = $this->escaneo();
        $this->foto($scan, 1, 300, 500);

        (new EscaneoFacturaCompraService())->escanear($scan);

        $imagenes = $this->imagenes_mandadas();

        $this->assertCount(2, $imagenes);
        $this->assertSame(900, $imagenes[0]['ancho']);
        $this->assertSame(1100, $imagenes[0]['alto']);
        $this->assertSame(900, $imagenes[1]['ancho']);
        $this->assertSame(565, $imagenes[1]['alto'], '1500 de alto: el segundo tramo arranca en 935.');
    }

    /**
     * El ancho llega a 1560 como máximo: 600 de ancho van ×2,6, no ×3.
     *
     * Y el solape es real: las últimas 165 filas de un tramo (el 15 % de su alto) son las primeras 165
     * del siguiente, pixel por pixel. Sin solape, un renglón que cae justo en el corte queda partido en
     * dos imágenes.
     *
     * @test
     * @return void
     */
    public function llega_hasta_el_ancho_maximo_y_corta_con_solape()
    {
        $this->fake_de_lectura_buena();

        $scan = $this->escaneo();
        $this->foto($scan, 1, 600, 800);

        (new EscaneoFacturaCompraService())->escanear($scan);

        $imagenes = $this->imagenes_mandadas();

        $this->assertCount(3, $imagenes, '2080 de alto: los tramos arrancan en 0, 935 y 1870.');
        $this->assertSame(1560, $imagenes[0]['ancho']);
        $this->assertSame(1100, $imagenes[0]['alto']);
        $this->assertSame(1560, $imagenes[1]['ancho']);
        $this->assertSame(1100, $imagenes[1]['alto']);
        $this->assertSame(210, $imagenes[2]['alto']);

        $this->assertSame('image/png', $imagenes[0]['media_type']);
        $this->assertSame('PÁGINA 1 de 1, TRAMO 1 de 3', $imagenes[0]['rotulo']);
        $this->assertSame('PÁGINA 1 de 1, TRAMO 2 de 3', $imagenes[1]['rotulo']);
        $this->assertSame('PÁGINA 1 de 1, TRAMO 3 de 3', $imagenes[2]['rotulo']);

        $primero = imagecreatefromstring($imagenes[0]['binario']);
        $segundo = imagecreatefromstring($imagenes[1]['binario']);

        foreach ([0, 80, 164] as $fila) {
            $this->assertSame(
                imagecolorat($primero, 700, 935 + $fila),
                imagecolorat($segundo, 700, $fila),
                'La fila ' . $fila . ' del segundo tramo tiene que ser la ' . (935 + $fila) . ' del primero.'
            );
        }

        imagedestroy($primero);
        imagedestroy($segundo);
    }

    /**
     * Una foto grande que no suma agrandar y entra en un tramo va exactamente como hoy: webp y con su
     * medida. Agrandar un 4 % solo sumaría píxeles borrosos y tokens.
     *
     * @test
     * @return void
     */
    public function una_foto_grande_no_se_agranda_y_va_como_hoy()
    {
        $this->fake_de_lectura_buena();

        $scan = $this->escaneo();
        $this->foto($scan, 1, 1500, 900);

        (new EscaneoFacturaCompraService())->escanear($scan);

        $imagenes = $this->imagenes_mandadas();

        $this->assertCount(1, $imagenes);
        $this->assertSame('image/webp', $imagenes[0]['media_type']);
        $this->assertSame(1500, $imagenes[0]['ancho']);
        $this->assertSame(900, $imagenes[0]['alto']);
        $this->assertSame('PÁGINA 1 de 1', $imagenes[0]['rotulo']);
    }

    /**
     * Una foto ancha y larga no se agranda pero sí se corta: es la que más se beneficia de los tramos.
     *
     * @test
     * @return void
     */
    public function una_foto_grande_y_larga_se_corta_sin_agrandar()
    {
        $this->fake_de_lectura_buena();

        $scan = $this->escaneo();
        $this->foto($scan, 1, 1500, 1400);

        (new EscaneoFacturaCompraService())->escanear($scan);

        $imagenes = $this->imagenes_mandadas();

        $this->assertCount(2, $imagenes);
        $this->assertSame(1500, $imagenes[0]['ancho']);
        $this->assertSame(1100, $imagenes[0]['alto']);
        $this->assertSame(1500, $imagenes[1]['ancho']);
        $this->assertSame(465, $imagenes[1]['alto'], '1400 de alto: el segundo tramo arranca en 935.');
    }

    /* ------------------------------------------------------------------ *
     *  🔴 Sin memoria, o si algo falla: la foto como hoy
     * ------------------------------------------------------------------ */

    /**
     * 🔴 Sin memoria para el bitmap agrandado no se intenta: un fatal de memoria no se atrapa y tumba el
     * worker. La foto va como hoy y el escaneo termina bien.
     *
     * @test
     * @return void
     */
    public function sin_memoria_la_foto_va_como_hoy()
    {
        $this->fake_de_lectura_buena();

        $scan = $this->escaneo();
        $this->foto($scan, 1, 600, 800);

        $servicio = new class () extends EscaneoFacturaCompraService {
            /** Lo que ya está en uso más un kilobyte: no entra ningún bitmap. */
            protected function limite_de_memoria(): int
            {
                return memory_get_usage(true) + 1024;
            }
        };

        $resultado = $servicio->escanear($scan);

        $imagenes = $this->imagenes_mandadas();

        $this->assertCount(1, $imagenes);
        $this->assertSame('image/webp', $imagenes[0]['media_type']);
        $this->assertSame(600, $imagenes[0]['ancho'], 'Sin memoria no se agranda.');
        $this->assertSame(800, $imagenes[0]['alto']);
        $this->assertCount(1, $resultado['articulos']);
    }

    /**
     * Si la memoria se acaba con la foto ya agrandada, tampoco queda a medias ni agrandada: va la foto
     * como hoy, con su medida original.
     *
     * @test
     * @return void
     */
    public function si_la_memoria_se_acaba_a_mitad_de_camino_va_la_foto_original()
    {
        $this->fake_de_lectura_buena();

        $scan = $this->escaneo();
        $this->foto($scan, 1, 600, 800);

        $servicio = new class () extends EscaneoFacturaCompraService {
            /** @var int */
            protected $consultas = 0;

            /** Alcanza para agrandar y no para el primer tramo. */
            protected function hay_memoria_para($bytes): bool
            {
                $this->consultas++;

                return $this->consultas === 1;
            }
        };

        $servicio->escanear($scan);

        $imagenes = $this->imagenes_mandadas();

        $this->assertCount(1, $imagenes);
        $this->assertSame('image/webp', $imagenes[0]['media_type']);
        $this->assertSame(600, $imagenes[0]['ancho']);
    }

    /**
     * Una página cuyos tramos no entran en el tope de bytes del escaneo va como hoy. Pasarse del tope
     * de la API por pedido voltearía las dos lecturas, y esta página más liviana igual se puede leer.
     *
     * @test
     * @return void
     */
    public function la_pagina_que_no_entra_en_el_tope_de_bytes_va_como_hoy()
    {
        $this->fake_de_lectura_buena();

        $scan = $this->escaneo();
        $this->foto($scan, 1, 120, 90);

        $servicio = new class () extends EscaneoFacturaCompraService {
            const MAX_BYTES_POR_ESCANEO = 1;
        };

        $servicio->escanear($scan);

        $imagenes = $this->imagenes_mandadas();

        $this->assertCount(1, $imagenes);
        $this->assertSame('image/webp', $imagenes[0]['media_type']);
        $this->assertSame(120, $imagenes[0]['ancho']);
    }

    /**
     * Un archivo que GD no abre sigue el camino de respaldo de siempre: el binario original con su mime.
     * Agrandar no puede romper lo que antes andaba.
     *
     * @test
     * @return void
     */
    public function un_archivo_que_no_se_puede_abrir_sigue_con_el_respaldo_de_siempre()
    {
        $this->fake_de_lectura_buena();

        $scan = $this->escaneo();
        $this->guardar_foto($scan, 1, 'esto no es una imagen', 'image/jpeg');

        (new EscaneoFacturaCompraService())->escanear($scan);

        $content = Http::recorded()->first()[0]->data()['messages'][0]['content'];

        $this->assertSame('image/jpeg', $content[1]['source']['media_type']);
        $this->assertSame('esto no es una imagen', base64_decode($content[1]['source']['data']));
    }

    /* ------------------------------------------------------------------ *
     *  Páginas y tramos
     * ------------------------------------------------------------------ */

    /**
     * 🔴 Varias páginas, una cortada: todas en la misma llamada, con el rótulo de su página real, y
     * `paginas_procesadas` cuenta páginas. Si contara imágenes, una factura de 2 páginas diría 3.
     *
     * @test
     * @return void
     */
    public function varias_paginas_con_tramos_van_en_una_sola_llamada_y_se_cuentan_como_paginas()
    {
        $this->fake_de_lectura_buena();

        $scan = $this->escaneo();
        $this->foto($scan, 1, 600, 800);
        $this->foto($scan, 2, 120, 90);

        $resultado = (new EscaneoFacturaCompraService())->escanear($scan);

        Http::assertSentCount(1);

        $imagenes = $this->imagenes_mandadas();

        $this->assertCount(4, $imagenes);
        $this->assertSame('PÁGINA 1 de 2, TRAMO 1 de 3', $imagenes[0]['rotulo']);
        $this->assertSame('PÁGINA 1 de 2, TRAMO 2 de 3', $imagenes[1]['rotulo']);
        $this->assertSame('PÁGINA 1 de 2, TRAMO 3 de 3', $imagenes[2]['rotulo']);
        $this->assertSame('PÁGINA 2 de 2', $imagenes[3]['rotulo']);

        $this->assertSame(2, $resultado['paginas_procesadas']);
        $this->assertStringContainsString('Páginas del comprobante que te mandé: 2.', $this->instrucciones_mandadas());
    }

    /**
     * El modelo se entera de los tramos solo si los hay, y se le dice qué hacer con el solape y con
     * `pagina`. Sin tramos, las instrucciones no cambian.
     *
     * @test
     * @return void
     */
    public function el_aviso_de_los_tramos_va_solo_si_hay_tramos()
    {
        $this->fake_de_lectura_buena();

        $scan = $this->escaneo();
        $this->foto($scan, 1, 600, 800);

        (new EscaneoFacturaCompraService())->escanear($scan);

        $instrucciones = $this->instrucciones_mandadas();

        $this->assertStringContainsString('TRAMOS', $instrucciones);
        $this->assertStringContainsString('UNA sola vez', $instrucciones);
        $this->assertStringContainsString('nunca el del tramo', $instrucciones);
    }

    /**
     * La otra mitad: una foto entera no lleva el aviso, porque le hablaría de algo que no le mandaron.
     *
     * @test
     * @return void
     */
    public function sin_tramos_no_hay_aviso()
    {
        $this->fake_de_lectura_buena();

        $scan = $this->escaneo();
        $this->foto($scan, 1, 120, 90);

        (new EscaneoFacturaCompraService())->escanear($scan);

        $this->assertStringNotContainsString('TRAMOS', $this->instrucciones_mandadas());
    }

    /**
     * `pagina` es la que dice el modelo. Y si dice una que no existe (el número de un tramo, en una
     * factura de una sola hoja), se acota a las páginas que hay: la pantalla de revisión nunca ve la
     * página 3 de un comprobante de dos.
     *
     * @test
     * @return void
     */
    public function la_pagina_de_cada_renglon_es_real_y_se_acota_a_las_que_hay()
    {
        $this->fake_de_lectura_buena([1, 2, 3, 0]);

        $scan = $this->escaneo();
        $this->foto($scan, 1, 600, 800);
        $this->foto($scan, 2, 120, 90);

        $resultado = (new EscaneoFacturaCompraService())->escanear($scan);

        $paginas = array_column($resultado['articulos'], 'pagina');

        $this->assertSame([1, 2, 2, 1], $paginas);
    }
}
