<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Models\Image;
use App\Models\ImageAssignmentItem;
use App\Services\ArticleImageValidationService;
use Illuminate\Support\Facades\Http;

/**
 * Las tres preguntas de sí o no de evaluar_candidatas() (quinta pasada, 27/9/2026).
 *
 * En la segunda prueba real con Haiku, ya con las definiciones precisas de la cuarta pasada, la cera
 * Nic volvió a salir con la foto de la TAPA vista desde arriba, `problemas: []` y confianza alta: los
 * modelos contestan mucho más confiable una pregunta explícita de sí/no que un código opcional en una
 * lista. Desde ahí cada candidata trae tres booleanos obligatorios y el parseo deriva los problemas:
 *
 *   - se_ve_el_producto_entero === false            → vista_parcial
 *   - muestra_mas_unidades_que_el_articulo === true → varias_unidades
 *   - es_ficha_tecnica_o_catalogo === true          → ficha_tecnica
 *
 * unidos a los de la lista, sin duplicar. Si un campo falta o no es booleano no se inventa nada.
 *
 * La IA es falsa (Http::fake de ImagenesInteligentesTestCase): acá se prueba qué hace el sistema con
 * lo que la IA contesta, no si la IA contesta bien.
 */
class Preguntas_de_si_o_no_a_la_IA_Test extends ImagenesInteligentesTestCase
{
    /** Las tres respuestas de una foto que sirve: el producto entero, una unidad, una foto limpia. */
    const RESPUESTAS_DE_UNA_FOTO_QUE_SIRVE = [
        'se_ve_el_producto_entero'             => true,
        'muestra_mas_unidades_que_el_articulo' => false,
        'es_ficha_tecnica_o_catalogo'          => false,
    ];

    /** @var int Contador para que cada artículo tenga su propio código. */
    protected $codigos_usados = 0;

    /**
     * Un EAN-13 de fábrica válido y distinto en cada llamada. Un mismo código en 3 artículos del
     * comercio el motor lo toma, con razón, por un código copiado de relleno y no lo busca.
     *
     * @return string
     */
    protected function codigo_nuevo()
    {
        $this->codigos_usados++;

        $base = '779123456'.str_pad((string) $this->codigos_usados, 3, '0', STR_PAD_LEFT);
        $suma = 0;

        for ($i = 0; $i < 12; $i++) {
            $suma += (int) $base[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return $base.((10 - $suma % 10) % 10);
    }

    /**
     * Un artículo con UNA candidata grande que la IA da por buena (sí, confianza alta), con las
     * respuestas a las preguntas y la lista de problemas que se le pasen.
     *
     * @param  string $nombre
     * @param  array  $respuestas  Campos extra de la entrada de la candidata (las preguntas).
     * @param  array  $lista       La lista "problemas" que contesta la IA.
     * @return \App\Models\ImageAssignmentItem  El item ya procesado.
     */
    protected function procesar_con_respuestas($nombre, array $respuestas, array $lista = [])
    {
        $codigo   = $this->codigo_nuevo();
        $articulo = $this->nuevo_articulo($nombre, $codigo);
        $run      = $this->asignacion([$articulo]);

        $this->falsear(
            [$codigo => [$this->resultado($this->url_imagen('foto'), 900, 900, 1)]],
            [$this->url_imagen('foto') => $this->png(900, 900, 'rojo')],
            ['rojo' => array_merge($this->veredicto('si', 'high', $lista), $respuestas)]
        );

        return $this->procesar($run, $articulo);
    }

    /**
     * Cuántas imágenes le quedaron al artículo del item (lo que ve la tienda).
     *
     * @param  \App\Models\ImageAssignmentItem $item
     * @return int
     */
    protected function imagenes_del_articulo(ImageAssignmentItem $item)
    {
        return Image::where('imageable_type', 'article')->where('imageable_id', $item->article_id)->count();
    }

    /**
     * La cera Nic: "no se ve el producto entero" (la tapa desde arriba) deriva vista_parcial, aunque
     * la lista venga vacía, y la foto no se asigna sola.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function no_se_ve_el_producto_entero_deriva_vista_parcial_y_bloquea()
    {
        $item = $this->procesar_con_respuestas(
            'Cera para pisos Nic 450 ml',
            array_merge(self::RESPUESTAS_DE_UNA_FOTO_QUE_SIRVE, ['se_ve_el_producto_entero' => false])
        );

        $this->assertSame(ImageAssignmentItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('vista_parcial', $item->motivo);
        $this->assertContains('Se ve solo una parte del producto', $item->imagen_meta['avisos']);
        $this->assertSame(['vista_parcial'], $item->imagen_meta['ia']['problemas'], 'Derivado de la pregunta, con la lista vacía.');
        $this->assertSame(0, $this->imagenes_del_articulo($item), 'La tienda no la ve.');
    }

    /**
     * El Cocinero: "muestra más unidades que el artículo" (el pack de 12) deriva varias_unidades.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function muestra_mas_unidades_deriva_varias_unidades_y_bloquea()
    {
        $item = $this->procesar_con_respuestas(
            'Aceite de girasol Cocinero 1,5 L',
            array_merge(self::RESPUESTAS_DE_UNA_FOTO_QUE_SIRVE, ['muestra_mas_unidades_que_el_articulo' => true])
        );

        $this->assertSame(ImageAssignmentItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('varias_unidades', $item->motivo);
        $this->assertContains('Muestra varias unidades', $item->imagen_meta['avisos']);
        $this->assertSame(['varias_unidades'], $item->imagen_meta['ia']['problemas']);
        $this->assertSame(0, $this->imagenes_del_articulo($item));
    }

    /**
     * El martillo galponero: "es una ficha técnica o de catálogo" deriva ficha_tecnica.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function es_ficha_tecnica_deriva_ficha_tecnica_y_bloquea()
    {
        $item = $this->procesar_con_respuestas(
            'Martillo galponero 27 mm mango de fibra',
            array_merge(self::RESPUESTAS_DE_UNA_FOTO_QUE_SIRVE, ['es_ficha_tecnica_o_catalogo' => true])
        );

        $this->assertSame(ImageAssignmentItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('ficha_tecnica', $item->motivo);
        $this->assertContains('Es una ficha técnica o de catálogo', $item->imagen_meta['avisos']);
        $this->assertSame(['ficha_tecnica'], $item->imagen_meta['ia']['problemas']);
        $this->assertSame(0, $this->imagenes_del_articulo($item));
    }

    /**
     * El control de los tres de arriba: las respuestas de una foto que sirve no derivan nada y la
     * foto se asigna sola (la derivación solo salta con la respuesta que delata el problema).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function las_respuestas_de_una_foto_que_sirve_no_bloquean()
    {
        $item = $this->procesar_con_respuestas('Cera para pisos Nic 450 ml', self::RESPUESTAS_DE_UNA_FOTO_QUE_SIRVE);

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame([], $item->imagen_meta['ia']['problemas']);
        $this->assertSame(1, $this->imagenes_del_articulo($item));
    }

    /**
     * Un campo que falta o que no es un booleano de verdad no inventa nada: queda solo la lista.
     * Ni "false" como texto, ni 0/1, ni "si", ni null. Y la lista sigue funcionando igual.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function un_campo_ausente_o_que_no_es_booleano_no_inventa_nada()
    {
        // Sin los tres campos (un modelo que no los manda): se asigna, como antes de esta pasada.
        $sin_campos = $this->procesar_con_respuestas('Cera para pisos Nic 450 ml', []);

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $sin_campos->status);
        $this->assertSame([], $sin_campos->imagen_meta['ia']['problemas']);

        // Valores raros, todos del lado que "delataría" el problema si fueran booleanos.
        $raros = [
            'se_ve_el_producto_entero'             => 'false',
            'muestra_mas_unidades_que_el_articulo' => 1,
            'es_ficha_tecnica_o_catalogo'          => 'si',
        ];

        $con_raros = $this->procesar_con_respuestas('Aceite de girasol Cocinero 1,5 L', $raros);

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $con_raros->status, 'Una respuesta rara no rompe ni bloquea.');
        $this->assertSame([], $con_raros->imagen_meta['ia']['problemas']);

        // Los tres en null.
        $con_null = $this->procesar_con_respuestas('Martillo carpintero 500 g', [
            'se_ve_el_producto_entero'             => null,
            'muestra_mas_unidades_que_el_articulo' => null,
            'es_ficha_tecnica_o_catalogo'          => null,
        ]);

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $con_null->status);
        $this->assertSame([], $con_null->imagen_meta['ia']['problemas']);

        // Valores raros pero con un problema en la lista: queda exactamente la lista.
        $con_lista = $this->procesar_con_respuestas('Martillo de bola 16 oz', $raros, ['vista_parcial']);

        $this->assertSame(ImageAssignmentItem::STATUS_A_REVISAR, $con_lista->status);
        $this->assertSame('vista_parcial', $con_lista->motivo);
        $this->assertSame(['vista_parcial'], $con_lista->imagen_meta['ia']['problemas']);
    }

    /**
     * Lo derivado se une a la lista sin duplicar, y una respuesta "buena" no borra un problema que
     * la lista sí trae.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function se_unen_con_la_lista_sin_duplicar_y_no_borran_ninguno()
    {
        $item = $this->procesar_con_respuestas(
            'Cera para pisos Nic 450 ml',
            [
                'se_ve_el_producto_entero'             => false, // ya viene vista_parcial en la lista
                'muestra_mas_unidades_que_el_articulo' => false, // "buena", pero la lista trae varias_unidades
                'es_ficha_tecnica_o_catalogo'          => true,  // nuevo: ficha_tecnica
            ],
            ['vista_parcial', 'marca_de_agua', 'varias_unidades']
        );

        $this->assertSame(
            ['vista_parcial', 'marca_de_agua', 'varias_unidades', 'ficha_tecnica'],
            $item->imagen_meta['ia']['problemas'],
            'Primero la lista, en su orden; después lo derivado que faltaba. Sin duplicados.'
        );
        $this->assertSame(ImageAssignmentItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('marca_de_agua', $item->motivo, 'El motivo principal sigue el orden de siempre.');
        $this->assertContains('Es una ficha técnica o de catálogo', $item->imagen_meta['avisos']);
        $this->assertSame(0, $this->imagenes_del_articulo($item));
    }

    /**
     * El prompt que se manda lleva las tres preguntas tal cual, obligatorias, con un ejemplo de cada
     * una y los tres campos en la estructura; el pedido tiene margen de tokens para 4 candidatas; y
     * el prompt de validate() no cambia.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function el_prompt_lleva_las_tres_preguntas_tal_cual()
    {
        $this->procesar_con_respuestas('Cera para pisos Nic 450 ml', self::RESPUESTAS_DE_UNA_FOTO_QUE_SIRVE);

        $datos = null;

        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), 'api.anthropic.com') !== false) {
                $datos = $par[0]->data();
            }
        }

        $this->assertNotNull($datos, 'Se le preguntó a la IA.');

        $system = (string) $datos['system'];

        // Las tres preguntas, tal cual.
        $this->assertStringContainsString(
            '"se_ve_el_producto_entero": ¿Se ve el producto ENTERO, como se lo vería en una góndola o en una tienda online? (false si es solo la tapa vista desde arriba, un detalle, un corte o la etiqueta de cerca)',
            $system
        );
        $this->assertStringContainsString(
            '"muestra_mas_unidades_que_el_articulo": ¿La foto muestra MÁS unidades de las que describe el artículo? (true para el pack de 12 cuando el artículo es una botella; false si el artículo es un pack y se ve el pack)',
            $system
        );
        $this->assertStringContainsString(
            '"es_ficha_tecnica_o_catalogo": ¿Es una ficha técnica o de catálogo, con dibujos, cotas, tablas de medidas o texto alrededor, en vez de una foto limpia?',
            $system
        );

        // Obligatorias, en la estructura exacta, y con un ejemplo de cada una.
        $this->assertStringContainsString('OBLIGATORIOS en TODAS las candidatas, siempre true o false', $system);
        $this->assertStringContainsString(
            '"fondo_blanco": true, "se_ve_el_producto_entero": true, "muestra_mas_unidades_que_el_articulo": false, "es_ficha_tecnica_o_catalogo": false, "problemas": []',
            $system
        );
        $this->assertStringContainsString('Solo la tapa del frasco vista desde arriba = false.', $system);
        $this->assertStringContainsString('la foto es el pack de 12 botellas = true.', $system);
        $this->assertStringContainsString('El martillo solo, sobre fondo blanco = false.', $system);

        // El recordatorio al final del mensaje del usuario.
        $contenido = $datos['messages'][0]['content'];
        $ultimo    = end($contenido);
        $this->assertStringContainsString('con las tres preguntas de sí o no contestadas con true o false en cada una', (string) $ultimo['text']);

        // Margen de salida para 4 candidatas con los campos nuevos (eran 1000).
        $this->assertGreaterThanOrEqual(1500, (int) $datos['max_tokens']);

        // validate() (el lote viejo y la búsqueda por código del asistente) no se tocó.
        $metodo = new \ReflectionMethod(ArticleImageValidationService::class, 'build_system_prompt');
        $metodo->setAccessible(true);
        $prompt_de_validate = (string) $metodo->invoke(new ArticleImageValidationService());

        $this->assertStringNotContainsString('se_ve_el_producto_entero', $prompt_de_validate);
        $this->assertStringNotContainsString('muestra_mas_unidades_que_el_articulo', $prompt_de_validate);
        $this->assertStringNotContainsString('es_ficha_tecnica_o_catalogo', $prompt_de_validate);
    }
}
