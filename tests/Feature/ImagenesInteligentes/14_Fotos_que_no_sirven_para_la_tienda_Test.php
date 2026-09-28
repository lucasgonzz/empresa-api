<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Models\Image;
use App\Models\ImageAssignmentItem;
use App\Services\ArticleImageValidationService;
use Illuminate\Support\Facades\Http;

/**
 * Fotos del producto correcto que NO sirven para la tienda (salen de la prueba real del 27/9/2026,
 * con Serper y Anthropic de verdad sobre 8 artículos):
 *
 *   - Aceite Cocinero 1,5 L (una unidad): la foto era el pack de 12 más una botella; la IA marcó
 *     `varias_unidades` con confianza alta y se asignó sola, porque ese problema no bloqueaba.
 *   - Cera Nic: la foto era la TAPA del frasco vista desde arriba; la IA no marcó nada y se asignó.
 *   - Martillo galponero: la foto era una FICHA TÉCNICA (el martillo, un dibujo con cotas y una tabla
 *     de medidas); la IA no marcó nada y fue a revisar solo por el tamaño.
 *
 * Desde ahí: `varias_unidades`, `vista_parcial` y `ficha_tecnica` están definidos con precisión en
 * el prompt de evaluar_candidatas() (validate() no se toca), el parseo los acepta, y los tres
 * BLOQUEAN la asignación sola: van a revisar con su motivo y su aviso, y en el ranking una limpia
 * les gana.
 *
 * La IA es falsa (Http::fake de ImagenesInteligentesTestCase): acá se prueba qué hace el sistema con
 * lo que la IA marca, no si la IA lo marca.
 */
class Fotos_que_no_sirven_para_la_tienda_Test extends ImagenesInteligentesTestCase
{
    /** Un EAN-13 de fábrica válido. */
    const CODIGO_REAL = '7791234567898';

    /**
     * Un artículo con UNA candidata que la IA da por buena (sí, confianza alta) pero con un problema.
     *
     * @param  string $nombre
     * @param  string $problema
     * @param  int    $lado
     * @return \App\Models\ImageAssignmentItem  El item ya procesado.
     */
    protected function procesar_con_problema($nombre, $problema, $lado = 900)
    {
        $articulo = $this->nuevo_articulo($nombre, self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        $this->falsear(
            [self::CODIGO_REAL => [$this->resultado($this->url_imagen('foto'), $lado, $lado, 1)]],
            [$this->url_imagen('foto') => $this->png($lado, $lado, 'rojo')],
            ['rojo' => $this->veredicto('si', 'high', [$problema])]
        );

        return $this->procesar($run, $articulo);
    }

    /**
     * Cocinero: el pack de 12 para un artículo de una unidad no se asigna solo.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function varias_unidades_no_se_asigna_sola()
    {
        $item = $this->procesar_con_problema('Aceite de girasol Cocinero 1,5 L', 'varias_unidades');

        $this->assertSame(ImageAssignmentItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('varias_unidades', $item->motivo);
        $this->assertContains('Muestra varias unidades', $item->imagen_meta['avisos']);
        $this->assertSame(['varias_unidades'], $item->imagen_meta['ia']['problemas']);
        $this->assertSame(0, Image::where('imageable_type', 'article')->where('imageable_id', $item->article_id)->count(), 'La tienda no la ve.');
    }

    /**
     * Nic: la tapa vista desde arriba (una parte del producto) no se asigna sola.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function vista_parcial_no_se_asigna_sola()
    {
        $item = $this->procesar_con_problema('Cera para pisos Nic 450 ml', 'vista_parcial');

        $this->assertSame(ImageAssignmentItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('vista_parcial', $item->motivo);
        $this->assertContains('Se ve solo una parte del producto', $item->imagen_meta['avisos']);
        $this->assertSame(['vista_parcial'], $item->imagen_meta['ia']['problemas'], 'El parseo lo acepta.');
        $this->assertSame(0, Image::where('imageable_type', 'article')->where('imageable_id', $item->article_id)->count());
    }

    /**
     * Martillo: la ficha técnica no se asigna sola, y es el motivo principal aunque además sea algo
     * chica (antes iba a revisar SOLO por el tamaño y nadie sabía que era una ficha).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function ficha_tecnica_no_se_asigna_sola_y_es_el_motivo_principal()
    {
        $item = $this->procesar_con_problema('Martillo galponero 27 mm mango de fibra', 'ficha_tecnica', 500);

        $this->assertSame(ImageAssignmentItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('ficha_tecnica', $item->motivo);
        $this->assertContains('Es una ficha técnica o de catálogo', $item->imagen_meta['avisos']);
        $this->assertContains('Imagen de 500 px', $item->imagen_meta['avisos']);
        $this->assertSame(['ficha_tecnica'], $item->imagen_meta['ia']['problemas'], 'El parseo lo acepta.');

        // Y con buen tamaño también bloquea.
        $grande = $this->procesar_con_problema('Martillo carpintero 500 g', 'ficha_tecnica', 1000);

        $this->assertSame(ImageAssignmentItem::STATUS_A_REVISAR, $grande->status);
        $this->assertSame('ficha_tecnica', $grande->motivo);
    }

    /**
     * En el ranking cuentan como problema: una limpia les gana. Si la limpia se puede asignar sola,
     * se asigna (aunque la otra sea más grande); si tampoco se puede (confianza media), la que se
     * propone para revisar es la limpia.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function una_limpia_le_gana_a_una_que_no_sirve_para_la_tienda()
    {
        // La limpia se puede asignar sola: se asigna la limpia, no el pack más grande.
        $articulo = $this->nuevo_articulo('Aceite de oliva 500 ml', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        $this->falsear(
            [self::CODIGO_REAL => [
                $this->resultado($this->url_imagen('pack'), 1200, 1200, 1),
                $this->resultado($this->url_imagen('limpia'), 700, 700, 2),
            ]],
            [
                $this->url_imagen('pack')   => $this->png(1200, 1200, 'rojo'),
                $this->url_imagen('limpia') => $this->png(700, 700, 'azul'),
            ],
            [
                'rojo' => $this->veredicto('si', 'high', ['varias_unidades']),
                'azul' => $this->veredicto('si', 'high'),
            ]
        );

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame(700, (int) $item->imagen_meta['ancho']);

        // Ninguna se puede asignar sola: la ficha tiene confianza alta y es más grande, pero se
        // propone la limpia (confianza media), que es la única que la tienda podría usar.
        $otro = $this->nuevo_articulo('Martillo de bola 16 oz', self::CODIGO_REAL);
        $run2 = $this->asignacion([$otro]);

        $this->falsear(
            [self::CODIGO_REAL => [
                $this->resultado($this->url_imagen('ficha'), 1200, 1200, 1),
                $this->resultado($this->url_imagen('limpia-media'), 700, 700, 2),
            ]],
            [
                $this->url_imagen('ficha')        => $this->png(1200, 1200, 'verde'),
                $this->url_imagen('limpia-media') => $this->png(700, 700, 'amarillo'),
            ],
            [
                'verde'    => $this->veredicto('si', 'high', ['ficha_tecnica']),
                'amarillo' => $this->veredicto('si', 'medium'),
            ]
        );

        $item2 = $this->procesar($run2, $otro);

        $this->assertSame(ImageAssignmentItem::STATUS_A_REVISAR, $item2->status);
        $this->assertSame('confianza_media', $item2->motivo);
        $this->assertSame(700, (int) $item2->imagen_meta['ancho'], 'Se propone la limpia, no la ficha.');
    }

    /**
     * El prompt de evaluar_candidatas() lleva las tres definiciones (y el de validate() no cambia).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function el_prompt_define_los_tres_problemas()
    {
        $this->procesar_con_problema('Cera para pisos Nic 450 ml', 'vista_parcial');

        $system = null;

        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), 'api.anthropic.com') !== false) {
                $system = (string) $par[0]->data()['system'];
            }
        }

        $this->assertNotNull($system, 'Se le preguntó a la IA.');
        $this->assertStringContainsString('"borrosa", "otro_producto", "vista_parcial", "ficha_tecnica".', $system);
        $this->assertStringContainsString('- "varias_unidades": la foto muestra MÁS unidades de las que describe el artículo.', $system);
        $this->assertStringContainsString('Si el artículo es un pack, una caja o un blíster de varias unidades, NO', $system);
        $this->assertStringContainsString('- "vista_parcial": se ve solo una parte del producto (la tapa vista desde arriba, un detalle,', $system);
        $this->assertStringContainsString('- "ficha_tecnica": es una ficha técnica o de catálogo: dibujos, cotas, tablas de medidas o', $system);

        // validate() (el lote viejo y la búsqueda por código del asistente) no se tocó.
        $metodo = new \ReflectionMethod(ArticleImageValidationService::class, 'build_system_prompt');
        $metodo->setAccessible(true);
        $prompt_de_validate = (string) $metodo->invoke(new ArticleImageValidationService());

        $this->assertStringNotContainsString('vista_parcial', $prompt_de_validate);
        $this->assertStringNotContainsString('ficha_tecnica', $prompt_de_validate);
    }
}
