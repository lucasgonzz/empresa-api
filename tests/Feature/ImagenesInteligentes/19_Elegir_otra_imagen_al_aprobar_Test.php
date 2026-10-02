<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Models\Image;
use App\Models\ImageAssignmentItem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Elegir a mano otra de las imágenes que se encontraron, en la tarjeta de "a revisar" (misión
 * imagenes-elegir-candidata, 2/10/2026).
 *
 * Lo que protege, sobre todo:
 *  - Que la URL que se baja salga SIEMPRE del diagnóstico del item y nunca del pedido (SSRF).
 *  - Que un 422 (no se pudo bajar, no se podía elegir) deje el item EXACTAMENTE como estaba: a
 *    revisar, con la propuesta original y su archivo en el disco.
 *  - Que sin `candidata` todo siga igual que antes (compatibilidad hacia atrás).
 */
class Elegir_otra_imagen_al_aprobar_Test extends ImagenesInteligentesTestCase
{
    /** Un EAN-13 de fábrica válido. */
    const CODIGO_REAL = '7791234567898';

    /**
     * Una candidata del diagnóstico, con la forma que escribe el motor.
     *
     * @param  int    $posicion
     * @param  string $url
     * @param  string $resultado
     * @param  array  $extra
     * @return array
     */
    protected function candidata($posicion, $url, $resultado, array $extra = [])
    {
        return array_merge([
            'posicion'           => $posicion,
            'url'                => $url,
            'url_completa'       => $url,
            'miniatura'          => $url.'?miniatura',
            'pagina'             => 'https://tienda.test/producto-'.$posicion,
            'dominio'            => 'tienda.test',
            'titulo'             => 'Producto de prueba '.$posicion,
            'ancho'              => 800,
            'alto'               => 800,
            'resultado'          => $resultado,
            'fondo_blanco_ratio' => 0.95,
            'motivo'             => null,
        ], $extra);
    }

    /**
     * Un artículo con una imagen "a revisar" (su propuesta SÍ está en este disco) y el diagnóstico
     * con la propuesta + las otras candidatas que se le pasen.
     *
     * @param  array $candidatas  Las candidatas del criterio por código de barras, además de la elegida.
     * @return \App\Models\ImageAssignmentItem
     */
    protected function a_revisar_con_alternativas(array $candidatas)
    {
        $articulo = $this->nuevo_articulo('Taladro inalámbrico 18 V', self::CODIGO_REAL, ['provider_code' => 'TAL-18V-X']);
        $run      = $this->asignacion([$articulo]);
        $item     = ImageAssignmentItem::where('run_id', $run->id)->first();
        $archivo  = ImageAssignmentItem::PREFIJO_CANDIDATA.(string) Str::uuid().'.webp';

        Storage::disk('public')->put($archivo, $this->webp(800, 800, 'verde'));

        $elegida = $this->candidata(1, $this->url_imagen('propuesta'), 'elegida', ['motivo' => 'La IA la reconoce con confianza media.']);

        $item->update([
            'status'         => ImageAssignmentItem::STATUS_A_REVISAR,
            'motivo'         => 'confianza_media',
            'criterio_usado' => 'codigo_de_barras',
            'imagen_archivo' => $archivo,
            'imagen_url'     => 'https://api.empresa.test/storage/'.$archivo,
            'imagen_meta'    => [
                'ancho'              => 800,
                'alto'               => 800,
                'fondo_blanco'       => true,
                'fondo_blanco_ratio' => 0.97,
                'ia'                 => ['veredicto' => 'dudoso', 'confianza' => 'medium', 'problemas' => [], 'motivo' => 'No está segura.'],
                'avisos'             => ['La IA lo reconoce con confianza media'],
            ],
            'diagnostico'    => [[
                'criterio'   => 'codigo_de_barras',
                'consulta'   => self::CODIGO_REAL,
                'usado'      => true,
                'busquedas'  => 1,
                'resultados' => 1 + count($candidatas),
                'error'      => null,
                'resumen'    => 'Prueba.',
                'candidatas' => array_merge([$elegida], $candidatas),
            ]],
        ]);

        return $item->fresh();
    }

    /**
     * Lo que no puede cambiar en un item cuando aprobar da 422.
     *
     * @param  \App\Models\ImageAssignmentItem $item
     * @return array
     */
    protected function huella(ImageAssignmentItem $item)
    {
        $fresco = $item->fresh();

        return [
            $fresco->status,
            $fresco->motivo,
            $fresco->criterio_usado,
            $fresco->imagen_archivo,
            $fresco->imagen_url,
            $fresco->imagen_meta,
            $fresco->diagnostico,
            $fresco->image_id,
            $fresco->revisado_at,
        ];
    }

    /**
     * La solapa "a revisar" trae el código de proveedor y SOLO las candidatas elegibles (ni la
     * propuesta, ni las descartadas), cada una con su clave.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function la_solapa_a_revisar_trae_el_codigo_de_proveedor_y_las_alternativas_elegibles()
    {
        $item = $this->a_revisar_con_alternativas([
            $this->candidata(2, $this->url_imagen('otra-a'), 'alternativa', ['motivo' => 'También era el producto.']),
            $this->candidata(3, $this->url_imagen('otra-b'), 'ia_dudosa'),
            $this->candidata(4, $this->url_imagen('otra-c'), 'no_evaluada'),
            $this->candidata(5, $this->url_imagen('chica'), 'chica'),
            $this->candidata(6, $this->url_imagen('texto'), 'descartada_por_texto'),
            $this->candidata(7, $this->url_imagen('rota'), 'no_descargable'),
            $this->candidata(8, $this->url_imagen('repetida'), 'duplicada'),
        ]);

        $respuesta = $this->getJson('api/image-assignment-runs/'.$item->run_id.'/items?solapa=a_revisar')
            ->assertStatus(200)
            ->assertJsonPath('models.data.0.id', $item->id)
            ->assertJsonPath('models.data.0.article_bar_code', self::CODIGO_REAL)
            ->assertJsonPath('models.data.0.article_provider_code', 'TAL-18V-X');

        $alternativas = $respuesta->json('models.data.0.alternativas');

        $this->assertSame(
            ['codigo_de_barras:2', 'codigo_de_barras:3', 'codigo_de_barras:4'],
            array_column($alternativas, 'clave'),
            'Solo las que se pueden elegir, sin la propuesta ni las descartadas.'
        );

        $this->assertSame($this->url_imagen('otra-a'), $alternativas[0]['url']);
        $this->assertSame($this->url_imagen('otra-a').'?miniatura', $alternativas[0]['miniatura']);
        $this->assertSame('alternativa', $alternativas[0]['resultado']);
    }

    /**
     * Un artículo sin código de proveedor lo manda en null, y fuera de "a revisar" no hay
     * alternativas (no hay nada que elegir).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function sin_codigo_de_proveedor_va_null_y_fuera_de_a_revisar_no_hay_alternativas()
    {
        $item = $this->a_revisar_con_alternativas([$this->candidata(2, $this->url_imagen('otra-a'), 'alternativa')]);

        \App\Models\Article::where('id', $item->article_id)->update(['provider_code' => null]);
        $item->update(['status' => ImageAssignmentItem::STATUS_NO_ASIGNADA]);

        $this->getJson('api/image-assignment-runs/'.$item->run_id.'/items?solapa=no_asignadas')
            ->assertStatus(200)
            ->assertJsonPath('models.data.0.article_provider_code', null)
            ->assertJsonPath('models.data.0.alternativas', []);
    }

    /**
     * Elegir otra: se baja ESA imagen, queda asignada al artículo, la propuesta anterior se borra del
     * disco y el diagnóstico refleja quién ganó.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function aprobar_con_candidata_asigna_la_imagen_elegida_y_no_la_propuesta()
    {
        $item = $this->a_revisar_con_alternativas([
            $this->candidata(2, $this->url_imagen('otra-a'), 'alternativa'),
            $this->candidata(3, $this->url_imagen('otra-b'), 'ia_dudosa'),
        ]);

        $propuesta_anterior = $item->imagen_archivo;

        $this->falsear([], [$this->url_imagen('otra-b') => $this->png(700, 700, 'azul')], []);

        $this->postJson('api/image-assignment-items/'.$item->id.'/aprobar', ['candidata' => 'codigo_de_barras:3'])
            ->assertStatus(200)
            ->assertJsonPath('model.status', 'aprobada');

        $aprobado = $item->fresh();

        $this->assertSame(ImageAssignmentItem::STATUS_APROBADA, $aprobado->status);
        $this->assertNotSame($propuesta_anterior, $aprobado->imagen_archivo);
        $this->assertTrue(Storage::disk('public')->exists($aprobado->imagen_archivo));
        $this->assertFalse(Storage::disk('public')->exists($propuesta_anterior), 'La propuesta anterior se borró.');
        $this->assertSame(1, Image::where('imageable_type', 'article')->where('imageable_id', $item->article_id)->count());
        $this->assertSame(1, $this->requests_a($this->url_imagen('otra-b')));
        $this->assertSame(0, $this->requests_a($this->url_imagen('otra-a')), 'No se bajó ninguna otra.');

        // El archivo guardado es el de la imagen elegida (azul), no la propuesta (verde).
        $guardada = imagecreatefromstring(Storage::disk('public')->get($aprobado->imagen_archivo));
        $rgb      = imagecolorsforindex($guardada, imagecolorat($guardada, (int) (imagesx($guardada) / 2), (int) (imagesy($guardada) / 2)));
        $this->assertGreaterThan($rgb['red'], $rgb['blue'], 'El centro de la imagen guardada es azul, no verde.');
        $this->assertGreaterThan($rgb['green'], $rgb['blue'], 'El centro de la imagen guardada es azul, no verde.');

        // El diagnóstico: la elegida a mano pasó a `elegida` y la que proponía el sistema a `alternativa`.
        $resultados = [];
        foreach ($aprobado->diagnostico[0]['candidatas'] as $candidata) {
            $resultados[$candidata['posicion']] = $candidata['resultado'];
        }

        $this->assertSame([1 => 'alternativa', 2 => 'alternativa', 3 => 'elegida'], $resultados);
    }

    /**
     * Sin `candidata` aprobar es lo de siempre: la propuesta del sistema, sin bajar nada de afuera.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function aprobar_sin_candidata_aprueba_la_propuesta_sin_bajar_nada()
    {
        $item = $this->a_revisar_con_alternativas([$this->candidata(2, $this->url_imagen('otra-a'), 'alternativa')]);

        $propuesta = $item->imagen_archivo;

        $this->falsear([], [], []);

        $this->postJson('api/image-assignment-items/'.$item->id.'/aprobar')
            ->assertStatus(200)
            ->assertJsonPath('model.status', 'aprobada');

        $this->assertSame(0, $this->requests_a('imagenes.test'), 'No salió ningún pedido a internet.');
        $this->assertFalse(Storage::disk('public')->exists($propuesta), 'La propuesta se copió a su nombre definitivo y la candidata se borró.');
        $this->assertSame(1, Image::where('imageable_type', 'article')->where('imageable_id', $item->article_id)->count());
    }

    /**
     * 🔴 SSRF: la URL jamás sale del pedido. Una clave que no existe en el diagnóstico es 422, sin
     * ningún pedido a internet y sin tocar el item, y una URL mandada en el cuerpo se ignora.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function una_clave_que_no_esta_en_el_diagnostico_es_422_y_una_url_del_pedido_se_ignora()
    {
        $item = $this->a_revisar_con_alternativas([$this->candidata(2, $this->url_imagen('otra-a'), 'alternativa')]);

        $antes = $this->huella($item);

        $this->falsear([], [
            $this->url_imagen('otra-a') => $this->png(700, 700, 'azul'),
            'https://interno.test/metadata' => $this->png(700, 700, 'rojo'),
        ], []);

        $this->postJson('api/image-assignment-items/'.$item->id.'/aprobar', ['candidata' => 'codigo_de_barras:99'])
            ->assertStatus(422);

        $this->postJson('api/image-assignment-items/'.$item->id.'/aprobar', ['candidata' => 'https://interno.test/metadata'])
            ->assertStatus(422);

        $this->assertSame($antes, $this->huella($item), 'Los dos 422 no tocaron el item.');

        // Una URL en el cuerpo, sola, no cambia nada: es una aprobación común de la propuesta.
        $this->postJson('api/image-assignment-items/'.$item->id.'/aprobar', ['url' => 'https://interno.test/metadata'])
            ->assertStatus(200);

        $this->assertSame(0, $this->requests_a('interno.test'), 'Nunca se le pidió nada a una dirección que vino en el pedido.');
        $this->assertSame(0, $this->requests_a('otra-a'));
    }

    /**
     * Las que el sistema descartó (chica, texto, no se pudo descargar, repetida) no se pueden elegir
     * aunque se mande su clave: 422 y el item queda igual.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function una_candidata_descartada_no_se_puede_elegir()
    {
        $item = $this->a_revisar_con_alternativas([
            $this->candidata(2, $this->url_imagen('chica'), 'chica'),
            $this->candidata(3, $this->url_imagen('texto'), 'descartada_por_texto'),
        ]);

        $antes = $this->huella($item);

        $this->falsear([], [
            $this->url_imagen('chica') => $this->png(700, 700, 'azul'),
            $this->url_imagen('texto') => $this->png(700, 700, 'azul'),
        ], []);

        foreach (['codigo_de_barras:2', 'codigo_de_barras:3'] as $clave) {
            $this->postJson('api/image-assignment-items/'.$item->id.'/aprobar', ['candidata' => $clave])
                ->assertStatus(422);
        }

        $this->assertSame($antes, $this->huella($item), 'El item no se tocó.');
        $this->assertSame(0, $this->requests_a('imagenes.test'));
    }

    /**
     * Si la imagen elegida ya no se puede bajar (404), 422 con un motivo legible y el item sigue a
     * revisar, con su propuesta y su archivo intactos: no se pierde nada.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function si_la_elegida_no_se_puede_bajar_el_item_queda_exactamente_como_estaba()
    {
        $item = $this->a_revisar_con_alternativas([$this->candidata(2, $this->url_imagen('rota'), 'alternativa')]);

        $antes = $this->huella($item);

        // No está entre las imágenes servidas: el fake contesta 404.
        $this->falsear([], [], []);

        $respuesta = $this->postJson('api/image-assignment-items/'.$item->id.'/aprobar', ['candidata' => 'codigo_de_barras:2'])
            ->assertStatus(422);

        $this->assertStringContainsString('No se pudo usar esa imagen', (string) $respuesta->json('message'));
        $this->assertSame($antes, $this->huella($item));
        $this->assertTrue(Storage::disk('public')->exists($item->imagen_archivo), 'La propuesta sigue en el disco.');
        $this->assertSame(0, Image::where('imageable_type', 'article')->where('imageable_id', $item->article_id)->count());
    }

    /**
     * El tamaño se mide de nuevo al bajarla: si lo que hay hoy en esa dirección es más chico que el
     * mínimo, no se asigna (aunque el buscador haya dicho otra cosa).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function una_imagen_que_hoy_es_demasiado_chica_no_se_asigna()
    {
        $item = $this->a_revisar_con_alternativas([$this->candidata(2, $this->url_imagen('achicada'), 'alternativa')]);

        $antes = $this->huella($item);

        $this->falsear([], [$this->url_imagen('achicada') => $this->png(120, 120, 'azul')], []);

        $this->postJson('api/image-assignment-items/'.$item->id.'/aprobar', ['candidata' => 'codigo_de_barras:2'])
            ->assertStatus(422);

        $this->assertSame($antes, $this->huella($item));
    }

    /**
     * Elegir como candidata la que ya es la propuesta es una aprobación normal (no baja nada).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function elegir_la_propuesta_es_aprobarla_sin_bajar_nada()
    {
        $item = $this->a_revisar_con_alternativas([$this->candidata(2, $this->url_imagen('otra-a'), 'alternativa')]);

        $this->falsear([], [], []);

        $this->postJson('api/image-assignment-items/'.$item->id.'/aprobar', ['candidata' => 'codigo_de_barras:1'])
            ->assertStatus(200)
            ->assertJsonPath('model.status', 'aprobada');

        $this->assertSame(0, $this->requests_a('imagenes.test'));
    }
}
