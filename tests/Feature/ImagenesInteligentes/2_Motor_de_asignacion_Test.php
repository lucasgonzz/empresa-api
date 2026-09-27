<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Http\Controllers\Helpers\ImagenesAutomaticasHelper;
use App\Models\Image;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Services\ImageAssignment\ArticleImageAssignmentEngine;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * El motor por artículo (ArticleImageAssignmentEngine, plan §6.6): qué búsquedas hace, qué imagen
 * elige y en qué estado deja el artículo.
 *
 * Lo que protege, en el orden del pedido de Lucas: primero el código de barras y solo si es real;
 * si no sale una asignable, el nombre; la MEJOR imagen (primero tamaño, segundo fondo blanco); la IA
 * decide si corresponde y nada se asigna solo sin ella; y lo dudoso queda a revisar sin que la tienda
 * lo vea (sin fila en `images`).
 *
 * Todo con Http::fake (ver ImagenesInteligentesTestCase): Serper, las imágenes y Anthropic.
 */
class Motor_de_asignacion_Test extends ImagenesInteligentesTestCase
{
    /** Un EAN-13 de fábrica válido. */
    const CODIGO_REAL = '7791234567898';

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function con_codigo_real_asigna_por_codigo_con_una_sola_busqueda()
    {
        $articulo = $this->nuevo_articulo('Yerba mate 1 kg', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        $this->falsear(
            [self::CODIGO_REAL => [$this->resultado($this->url_imagen('yerba'), 1000, 1000, 1)]],
            [$this->url_imagen('yerba') => $this->png(1000, 1000, 'rojo')],
            ['rojo' => $this->veredicto('si', 'high')]
        );

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $item->status, (string) $item->motivo_detalle);
        $this->assertSame('codigo_de_barras', $item->criterio_usado);
        $this->assertSame(1, (int) $item->busquedas);
        $this->assertSame(1, (int) $item->validaciones_ia);
        $this->assertSame([self::CODIGO_REAL], $this->consultas_serper, 'Con una asignable por código no se busca por nombre.');

        // La tienda la ve: fila de images con la URL del archivo guardado.
        $imagenes = Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->get();
        $this->assertCount(1, $imagenes);
        $this->assertSame($item->imagen_url, $imagenes[0]->hosting_url);
        $this->assertSame((int) $imagenes[0]->id, (int) $item->image_id);

        // Nombre de siempre (<time><rand>.webp), cuadrada, lado máximo 1000.
        $this->assertMatchesRegularExpression('/^\d+\.webp$/', (string) $item->imagen_archivo);
        $this->assertTrue(Storage::disk('public')->exists($item->imagen_archivo));
        $medidas = getimagesize(Storage::disk('public')->path($item->imagen_archivo));
        $this->assertSame([1000, 1000], [$medidas[0], $medidas[1]]);

        $this->assertTrue((bool) $articulo->fresh()->needs_sync_with_tn);

        $codigo = $this->diagnostico_de($item, 'codigo_de_barras');
        $this->assertTrue($codigo['usado']);
        $this->assertSame(1, $codigo['busquedas']);
        $this->assertSame(1, $codigo['resultados']);
        $this->assertSame('elegida', $codigo['candidatas'][0]['resultado']);

        // Las claves exactas del contrato §5.2 (la SPA se construye contra ellas en paralelo). Como
        // conjunto y no en orden: la columna JSON de MySQL reordena las claves al guardarlas.
        $this->assertEqualsCanonicalizing(['criterio', 'consulta', 'usado', 'motivo_no_usado', 'busquedas', 'resultados', 'error', 'resumen', 'candidatas'], array_keys($codigo));
        $this->assertEqualsCanonicalizing(['posicion', 'url', 'miniatura', 'pagina', 'dominio', 'ancho', 'alto', 'resultado', 'fondo_blanco_ratio', 'motivo'], array_keys($codigo['candidatas'][0]));
        $this->assertSame($this->url_imagen('yerba'), $codigo['candidatas'][0]['url']);
        $this->assertSame('tienda.test', $codigo['candidatas'][0]['dominio']);

        $nombre = $this->diagnostico_de($item, 'nombre');
        $this->assertFalse($nombre['usado']);
        $this->assertSame(0, $nombre['busquedas']);
        $this->assertStringContainsString('No hizo falta', $nombre['motivo_no_usado']);

        $this->assertSame([], $item->imagen_meta['avisos']);
        $this->assertTrue($item->imagen_meta['fondo_blanco']);

        $run->refresh();
        $this->assertSame(1, (int) $run->procesados);
        $this->assertSame(1, (int) $run->busquedas);
        $this->assertSame(1, (int) $run->busquedas_codigo);
        $this->assertSame(0, (int) $run->busquedas_nombre);
        $this->assertSame(1, (int) $run->validaciones_ia);
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function con_codigo_inventado_no_busca_por_codigo_y_lo_explica()
    {
        $articulo = $this->nuevo_articulo('Martillo carpintero 16 oz');
        $articulo->bar_code = (string) $articulo->id;
        $articulo->save();

        $run = $this->asignacion([$articulo]);

        $this->falsear(
            ['Martillo carpintero 16 oz' => [$this->resultado($this->url_imagen('martillo'), 900, 900, 1)]],
            [$this->url_imagen('martillo') => $this->png(900, 900, 'azul')],
            ['azul' => $this->veredicto('si', 'high')]
        );

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $item->status, (string) $item->motivo_detalle);
        $this->assertSame('nombre', $item->criterio_usado);
        $this->assertSame(['Martillo carpintero 16 oz'], $this->consultas_serper);
        $this->assertSame(1, (int) $item->busquedas);

        $codigo = $this->diagnostico_de($item, 'codigo_de_barras');
        $this->assertFalse($codigo['usado']);
        $this->assertSame(0, $codigo['busquedas']);
        $this->assertSame('El código '.$articulo->id.' es el número interno del artículo, no un código de barras real.', $codigo['motivo_no_usado']);

        $run->refresh();
        $this->assertSame(0, (int) $run->busquedas_codigo);
        $this->assertSame(1, (int) $run->busquedas_nombre);
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function si_todas_son_chicas_no_se_baja_nada_ni_se_gasta_ia()
    {
        $articulo = $this->nuevo_articulo('Tornillo 3x20', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        $this->falsear(
            [
                self::CODIGO_REAL => [
                    $this->resultado($this->url_imagen('t1'), 300, 300, 1),
                    $this->resultado($this->url_imagen('t2'), 200, 350, 2),
                    $this->resultado($this->url_imagen('t3'), 399, 399, 3),
                ],
                'Tornillo 3x20'   => [
                    $this->resultado($this->url_imagen('t4'), 100, 100, 1),
                    $this->resultado($this->url_imagen('t5'), 350, 500, 2),
                ],
            ],
            [],
            []
        );

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_NO_ASIGNADA, $item->status);
        $this->assertSame('imagenes_chicas', $item->motivo);
        $this->assertSame(2, (int) $item->busquedas);
        $this->assertSame(0, (int) $item->validaciones_ia);
        $this->assertSame(0, $this->llamadas_ia, 'Con todas chicas por metadatos no se le pregunta nada a la IA.');
        $this->assertSame(0, $this->requests_a('imagenes.test'), 'Ni se descargan.');
        $this->assertStringContainsString('Por código de barras: 3 resultados, todas muy chicas.', $item->motivo_detalle);
        $this->assertStringContainsString('Por nombre: 2 resultados, todas muy chicas.', $item->motivo_detalle);
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function si_la_ia_dice_que_no_es_el_producto_queda_no_asignada_por_no_corresponder()
    {
        $articulo = $this->nuevo_articulo('Cable unipolar 2,5 mm', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        $this->falsear(
            [
                self::CODIGO_REAL        => [
                    $this->resultado($this->url_imagen('c1'), 800, 800, 1),
                    $this->resultado($this->url_imagen('c2'), 800, 800, 2),
                ],
                'Cable unipolar 2,5 mm' => [$this->resultado($this->url_imagen('c3'), 800, 800, 1)],
            ],
            [
                $this->url_imagen('c1') => $this->png(800, 800, 'rojo'),
                $this->url_imagen('c2') => $this->png(800, 800, 'azul'),
                $this->url_imagen('c3') => $this->png(800, 800, 'verde'),
            ],
            [
                'rojo'  => $this->veredicto('no'),
                'azul'  => $this->veredicto('no'),
                'verde' => $this->veredicto('no'),
            ]
        );

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_NO_ASIGNADA, $item->status);
        $this->assertSame('no_corresponden', $item->motivo);
        $this->assertSame(2, (int) $item->busquedas);
        $this->assertSame(2, (int) $item->validaciones_ia, 'Una llamada de IA por criterio (compara las candidatas juntas).');
        $this->assertStringContainsString('Por código de barras: 2 resultados, ninguno era el producto.', $item->motivo_detalle);
        $this->assertSame(0, Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->count());

        foreach ($this->diagnostico_de($item, 'codigo_de_barras')['candidatas'] as $candidata) {
            $this->assertSame('ia_no_corresponde', $candidata['resultado']);
        }
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function con_confianza_media_queda_a_revisar_sin_fila_en_images_y_con_el_archivo_candidato()
    {
        $articulo = $this->nuevo_articulo('Pava eléctrica 1,7 L', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        $this->falsear(
            [self::CODIGO_REAL => [$this->resultado($this->url_imagen('pava'), 900, 900, 1)]],
            [$this->url_imagen('pava') => $this->png(900, 900, 'rojo')],
            ['rojo' => $this->veredicto('si', 'medium')]
        );

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('confianza_media', $item->motivo);
        $this->assertContains('La IA lo reconoce con confianza media', $item->imagen_meta['avisos']);

        // La tienda NO la ve: sin fila en images, solo el archivo imgcand_*.
        $this->assertSame(0, Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->count());
        $this->assertNull($item->image_id);
        $this->assertMatchesRegularExpression('/^imgcand_[a-f0-9-]{36}\.webp$/', (string) $item->imagen_archivo);
        $this->assertTrue(Storage::disk('public')->exists($item->imagen_archivo));

        // Como por código no salió una asignable, también se buscó por nombre.
        $this->assertSame([self::CODIGO_REAL, 'Pava eléctrica 1,7 L'], $this->consultas_serper);
        $this->assertSame(2, (int) $item->busquedas);
    }

    /**
     * Mismo tamaño y misma confianza: gana la de fondo blanco aunque venga segunda.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function entre_dos_iguales_gana_la_de_fondo_blanco()
    {
        $articulo = $this->nuevo_articulo('Lavandina 1 L', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        $this->falsear(
            [self::CODIGO_REAL => [
                $this->resultado($this->url_imagen('celeste'), 900, 900, 1),
                $this->resultado($this->url_imagen('blanca'), 900, 900, 2),
            ]],
            [
                $this->url_imagen('celeste') => $this->png(900, 900, 'rojo', self::FONDO_CELESTE),
                $this->url_imagen('blanca')  => $this->png(900, 900, 'azul', self::FONDO_BLANCO),
            ],
            [
                'rojo' => $this->veredicto('si', 'high'),
                'azul' => $this->veredicto('si', 'high'),
            ]
        );

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $item->status);
        $this->assertTrue($item->imagen_meta['fondo_blanco']);
        $this->assertSame([], $item->imagen_meta['avisos']);

        $candidatas = $this->diagnostico_de($item, 'codigo_de_barras')['candidatas'];
        $this->assertSame('alternativa', $candidatas[0]['resultado'], 'La de fondo celeste era el producto pero perdió.');
        $this->assertSame('elegida', $candidatas[1]['resultado']);
        $this->assertLessThan(0.85, $candidatas[0]['fondo_blanco_ratio']);
        $this->assertGreaterThanOrEqual(0.85, $candidatas[1]['fondo_blanco_ratio']);

        // Y el archivo guardado es el de la azul (fondo blanco).
        $this->assertSame('azul', $this->color_del_centro($item->imagen_archivo));
    }

    /**
     * Primero tamaño, segundo fondo blanco (el orden que pidió Lucas): la de 1000 px con fondo de
     * color le gana a la de 650 px con fondo blanco, y se asigna igual, marcada.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function la_mejor_sin_fondo_blanco_se_asigna_igual_con_el_aviso()
    {
        $articulo = $this->nuevo_articulo('Termo acero 1 L', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        $this->falsear(
            [self::CODIGO_REAL => [
                $this->resultado($this->url_imagen('chica-blanca'), 650, 650, 1),
                $this->resultado($this->url_imagen('grande-celeste'), 1000, 1000, 2),
            ]],
            [
                $this->url_imagen('chica-blanca')   => $this->png(650, 650, 'azul', self::FONDO_BLANCO),
                $this->url_imagen('grande-celeste') => $this->png(1000, 1000, 'rojo', self::FONDO_CELESTE),
            ],
            [
                'azul' => $this->veredicto('si', 'high'),
                'rojo' => $this->veredicto('si', 'high'),
            ]
        );

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame(['Fondo no blanco'], $item->imagen_meta['avisos']);
        $this->assertFalse($item->imagen_meta['fondo_blanco']);
        $this->assertSame(1000, (int) $item->imagen_meta['ancho']);
        $this->assertStringContainsString('El fondo no es blanco.', $item->motivo_detalle);
        $this->assertSame('rojo', $this->color_del_centro($item->imagen_archivo));
    }

    /**
     * Por código solo salió una dudosa: se busca por nombre y gana la buena (2 búsquedas).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function si_por_codigo_solo_sale_una_dudosa_y_por_nombre_una_buena_gana_la_de_nombre()
    {
        $articulo = $this->nuevo_articulo('Aceite de girasol 1,5 L', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        $this->falsear(
            [
                self::CODIGO_REAL          => [$this->resultado($this->url_imagen('dudosa'), 900, 900, 1)],
                'Aceite de girasol 1,5 L' => [$this->resultado($this->url_imagen('buena'), 900, 900, 1)],
            ],
            [
                $this->url_imagen('dudosa') => $this->png(900, 900, 'rojo'),
                $this->url_imagen('buena')  => $this->png(900, 900, 'azul'),
            ],
            [
                'rojo' => $this->veredicto('dudoso', 'low'),
                'azul' => $this->veredicto('si', 'high'),
            ]
        );

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $item->status, (string) $item->motivo_detalle);
        $this->assertSame('nombre', $item->criterio_usado);
        $this->assertSame(2, (int) $item->busquedas);
        $this->assertSame(2, (int) $item->validaciones_ia);
        $this->assertSame('ia_dudosa', $this->diagnostico_de($item, 'codigo_de_barras')['candidatas'][0]['resultado']);
        $this->assertSame('elegida', $this->diagnostico_de($item, 'nombre')['candidatas'][0]['resultado']);
        $this->assertSame('azul', $this->color_del_centro($item->imagen_archivo));

        $run->refresh();
        $this->assertSame(1, (int) $run->busquedas_codigo);
        $this->assertSame(1, (int) $run->busquedas_nombre);
    }

    /**
     * Sin IA la imagen nunca se asigna sola: va a revisar (el fail-open de siempre asignaba igual).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function con_la_ia_caida_la_mejor_va_a_revisar()
    {
        $articulo = $this->nuevo_articulo('Birome azul trazo fino', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        $this->falsear(
            [
                self::CODIGO_REAL        => [$this->resultado($this->url_imagen('ia-1'), 900, 900, 1)],
                'Birome azul trazo fino' => [$this->resultado($this->url_imagen('ia-2'), 800, 800, 1)],
            ],
            [
                $this->url_imagen('ia-1') => $this->png(900, 900, 'rojo'),
                $this->url_imagen('ia-2') => $this->png(800, 800, 'azul'),
            ],
            'caida'
        );

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('sin_validacion_ia', $item->motivo);
        $this->assertContains('No se pudo validar con IA', $item->imagen_meta['avisos']);
        $this->assertSame('sin_evaluar', $item->imagen_meta['ia']['veredicto']);
        $this->assertSame(0, Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->count());
        $this->assertSame(2, (int) $item->busquedas);
        $this->assertSame(0, (int) $item->validaciones_ia, 'Llamadas que fallaron no son validaciones (no se pagan).');
        $this->assertSame(2, $this->llamadas_ia);
    }

    /**
     * Una botella 1:3 se guarda cuadrada SIN recortar: la tapa y la base siguen estando, con
     * relleno blanco a los costados.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function una_botella_alargada_se_guarda_cuadrada_sin_recortarla()
    {
        $articulo = $this->nuevo_articulo('Agua mineral 2 L', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        $botella = $this->png(700, 2100, 'rojo', self::FONDO_BLANCO, [
            'arriba' => self::COLORES['negro'],
            'abajo'  => self::COLORES['cian'],
        ]);

        $this->falsear(
            [self::CODIGO_REAL => [$this->resultado($this->url_imagen('botella'), 700, 2100, 1)]],
            [$this->url_imagen('botella') => $botella],
            ['rojo' => $this->veredicto('si', 'high')]
        );

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $item->status, (string) $item->motivo_detalle);

        $ruta    = Storage::disk('public')->path($item->imagen_archivo);
        $medidas = getimagesize($ruta);
        $this->assertSame([1000, 1000], [$medidas[0], $medidas[1]], 'Cuadrada y con el lado máximo de 1000 px.');

        $imagen = imagecreatefromstring(file_get_contents($ruta));

        // La franja de arriba (tapa) y la de abajo (base) siguen en la imagen final: no se recortó.
        $this->assertSame('negro', $this->nombre_del_color(imagecolorat($imagen, 500, 12)));
        $this->assertSame('cian', $this->nombre_del_color(imagecolorat($imagen, 500, 988)));
        $this->assertSame('rojo', $this->nombre_del_color(imagecolorat($imagen, 500, 500)));

        // Y a los costados, relleno blanco (webp tiene pérdida: blanco es "casi 255" en los tres canales).
        $this->assertGreaterThanOrEqual(245, min($this->rgb(imagecolorat($imagen, 60, 500))));
        $this->assertGreaterThanOrEqual(245, min($this->rgb(imagecolorat($imagen, 940, 500))));

        imagedestroy($imagen);
    }

    /**
     * La guarda SSRF: una "imagen" en un host que resuelve a una IP interna no se descarga.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function una_imagen_en_una_direccion_interna_no_se_descarga()
    {
        $articulo = $this->nuevo_articulo('Mecha para metal 8 mm', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        $this->falsear(
            [self::CODIGO_REAL => [$this->resultado('https://interno.test/foto.png', 900, 900, 1)]],
            ['https://interno.test/foto.png' => $this->png(900, 900, 'rojo')],
            ['rojo' => $this->veredicto('si', 'high')]
        );

        $item = $this->procesar($run, $articulo);

        $candidata = $this->diagnostico_de($item, 'codigo_de_barras')['candidatas'][0];
        $this->assertSame('no_descargable', $candidata['resultado']);
        $this->assertStringContainsString('no es un sitio público', $candidata['motivo']);
        $this->assertSame(0, $this->requests_a('interno.test'), 'La guarda frena antes de salir.');
        $this->assertSame(ImageAssignmentItem::STATUS_NO_ASIGNADA, $item->status);
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function un_articulo_borrado_o_sin_datos_no_gasta_busquedas()
    {
        $borrado    = $this->nuevo_articulo('Se va a borrar', self::CODIGO_REAL);
        $sin_datos  = $this->nuevo_articulo(null);
        $sin_datos->bar_code = (string) $sin_datos->id;
        $sin_datos->save();

        $run = $this->asignacion([$borrado, $sin_datos]);

        $borrado->delete();

        $this->falsear([], [], []);

        $item_borrado = $this->procesar($run, $borrado);
        $this->assertSame(ImageAssignmentItem::STATUS_NO_ASIGNADA, $item_borrado->status);
        $this->assertSame('articulo_borrado', $item_borrado->motivo);

        $item_sin_datos = $this->procesar($run, $sin_datos);
        $this->assertSame(ImageAssignmentItem::STATUS_NO_ASIGNADA, $item_sin_datos->status);
        $this->assertSame('sin_datos', $item_sin_datos->motivo);
        $this->assertSame(0, (int) $item_sin_datos->busquedas);

        $this->assertSame([], $this->consultas_serper);
    }

    /**
     * Entre las que la IA dio "si" manda el tamaño (plan §6.6): si ninguna se puede asignar sola,
     * la de 1200 px con confianza baja le gana a la de 450 px con confianza media, y va a revisar
     * como dudosa.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function entre_dos_si_que_no_se_pueden_asignar_gana_la_mas_grande()
    {
        $articulo = $this->nuevo_articulo('Balde de plástico 10 L', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        $this->falsear(
            [self::CODIGO_REAL => [
                $this->resultado($this->url_imagen('chica-media'), 450, 450, 1),
                $this->resultado($this->url_imagen('grande-baja'), 1200, 1200, 2),
            ]],
            [
                $this->url_imagen('chica-media') => $this->png(450, 450, 'azul'),
                $this->url_imagen('grande-baja') => $this->png(1200, 1200, 'rojo'),
            ],
            [
                'azul' => $this->veredicto('si', 'medium'),
                'rojo' => $this->veredicto('si', 'low'),
            ]
        );

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('ia_dudosa', $item->motivo, 'Una "si" con confianza baja se revisa como dudosa.');
        $this->assertSame(1200, (int) $item->imagen_meta['ancho']);
        $this->assertSame('rojo', $this->color_del_centro($item->imagen_archivo));
    }

    /**
     * 🔴 Un artículo que mientras se procesaba pasó a otro tramo (una reanudación lo devolvió a la
     * fila y lo reclamó otro) no se cierra dos veces: el motor tira, no queda ninguna fila de
     * images y el artículo sigue siendo del otro tramo. Las búsquedas que ya se pagaron igual
     * quedan contadas en la asignación.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function un_articulo_que_reclamo_otro_tramo_no_se_cierra_dos_veces()
    {
        $articulo = $this->nuevo_articulo('Escalera de aluminio 5 escalones', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        $item = ImageAssignmentItem::where('run_id', $run->id)->first();
        $item->update(['status' => ImageAssignmentItem::STATUS_PROCESANDO, 'intentos' => 1, 'tramo' => 'ficha-de-este-tramo']);

        $this->falsear(
            [self::CODIGO_REAL => [$this->resultado($this->url_imagen('escalera'), 1000, 1000, 1)]],
            [$this->url_imagen('escalera') => $this->png(1000, 1000, 'rojo')],
            ['rojo' => $this->veredicto('si', 'high')],
            function () use ($item) {
                // Mientras este tramo busca, otro tramo se queda con el artículo.
                ImageAssignmentItem::where('id', $item->id)->update(['tramo' => 'ficha-de-otro-tramo']);
            }
        );

        $excepcion = null;

        try {
            (new ArticleImageAssignmentEngine($run->fresh()))->procesar($item->fresh());
        } catch (\RuntimeException $e) {
            $excepcion = $e;
        }

        $this->assertNotNull($excepcion, 'El motor no puede cerrar un artículo que ya es de otro tramo.');
        $this->assertStringContainsString('ya no es de este tramo', $excepcion->getMessage());

        $this->assertSame(0, Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->count(), 'La fila de images se deshizo.');

        $this->assertSame([], Storage::disk('public')->allFiles(), 'El .webp que se había guardado se borró: no queda huérfano.');

        $item->refresh();
        $this->assertSame(ImageAssignmentItem::STATUS_PROCESANDO, $item->status);
        $this->assertSame('ficha-de-otro-tramo', $item->tramo);

        $run->refresh();
        $this->assertSame(0, (int) $run->procesados);
        $this->assertSame(1, (int) $run->busquedas, 'La búsqueda ya se pagó: se cuenta aunque el artículo no se cierre.');
        $this->assertSame(1, (int) $run->validaciones_ia);
    }

    /**
     * Una URL de más de 500 bytes con letras acentuadas justo en el corte no rompe el guardado
     * del diagnóstico (el corte es por borde de carácter, no por byte).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function una_url_larga_con_acentos_no_rompe_el_diagnostico()
    {
        $articulo = $this->nuevo_articulo('Tijera de podar', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        // 499 bytes y después "ñ" (2 bytes): un corte por byte en 500 la partiría al medio.
        $url = 'https://imagenes.test/'.str_repeat('a', 499 - strlen('https://imagenes.test/')).'ñandú-tijera.png';

        $this->falsear(
            [self::CODIGO_REAL => [$this->resultado($url, 200, 200, 1)]],
            [],
            []
        );

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_NO_ASIGNADA, $item->status);
        $this->assertSame('imagenes_chicas', $item->motivo);

        $guardada = $this->diagnostico_de($item, 'codigo_de_barras')['candidatas'][0]['url'];
        $this->assertLessThanOrEqual(500, strlen($guardada));
        $this->assertTrue(mb_check_encoding($guardada, 'UTF-8'), 'El recorte no dejó un UTF-8 inválido.');
    }

    /**
     * 🔴 Un timeout de Google trae la URL COMPLETA del pedido, con la clave y el cx, y ese texto
     * termina en el diagnóstico del artículo, que ve cualquier usuario del comercio: tiene que llegar
     * tapado (el error en sí se sigue viendo). Lo mismo si Serper nombrara su clave en un error.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function un_error_del_proveedor_nunca_deja_la_clave_a_la_vista()
    {
        config(['services.google_search.api_key' => 'AIzaCLAVE-DE-GOOGLE-DE-PRUEBA']);

        // Google: un timeout de Guzzle, que nombra la URL entera.
        $articulo = $this->nuevo_articulo('Pala ancha con cabo', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo], ['proveedor' => ImageAssignmentRun::PROVEEDOR_GOOGLE]);

        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::fake(function ($request) {
            if (strpos($request->url(), 'googleapis.com') !== false) {
                throw new ConnectException(
                    'cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received for '.$request->url(),
                    $request->toPsrRequest()
                );
            }

            return Http::response('no encontrada', 404, ['Content-Type' => 'text/plain']);
        });

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_NO_ASIGNADA, $item->status);
        $this->assertSame('error_de_busqueda', $item->motivo);

        $a_la_vista = json_encode($item->diagnostico, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).' '.$item->motivo_detalle;

        $this->assertStringContainsString('Operation timed out', $a_la_vista, 'El error se sigue viendo.');
        $this->assertStringContainsString('key=***', $a_la_vista);
        $this->assertStringNotContainsString('AIzaCLAVE-DE-GOOGLE-DE-PRUEBA', $a_la_vista);
        $this->assertStringNotContainsString(ImagenesAutomaticasHelper::CX, $a_la_vista);

        // Serper: un error que (hipotéticamente) nombra la clave.
        $otro     = $this->nuevo_articulo('Rastrillo de 14 dientes', $this->con_verificador('779000600001'));
        $otro_run = $this->asignacion([$otro]);

        $this->falsear(['error' => 'Invalid API key SERPER-DE-PRUEBA'], [], []);

        $otro_item = $this->procesar($otro_run, $otro);

        $a_la_vista = json_encode($otro_item->diagnostico, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).' '.$otro_item->motivo_detalle;

        $this->assertStringContainsString('Invalid API key', $a_la_vista);
        $this->assertStringNotContainsString('SERPER-DE-PRUEBA', $a_la_vista);
    }

    /**
     * El color (clave de COLORES) del centro de un archivo guardado.
     *
     * @param  string $archivo
     * @return string
     */
    protected function color_del_centro($archivo)
    {
        $imagen = imagecreatefromstring(Storage::disk('public')->get($archivo));
        $color  = $this->nombre_del_color(imagecolorat($imagen, (int) (imagesx($imagen) / 2), (int) (imagesy($imagen) / 2)));
        imagedestroy($imagen);

        return $color;
    }

    /**
     * El color de COLORES más cercano a un píxel (el webp tiene pérdida: no se compara exacto).
     *
     * @param  int $pixel
     * @return string
     */
    protected function nombre_del_color($pixel)
    {
        $rgb = $this->rgb($pixel);

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
     * @param  int $pixel
     * @return array
     */
    protected function rgb($pixel)
    {
        return [($pixel >> 16) & 0xFF, ($pixel >> 8) & 0xFF, $pixel & 0xFF];
    }
}
