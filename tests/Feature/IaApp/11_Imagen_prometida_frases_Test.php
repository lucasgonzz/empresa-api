<?php

namespace Tests\Feature\IaApp;

use App\Http\Controllers\Helpers\asistente_ia\ImagenPrometidaIaHelper;
use PHPUnit\Framework\TestCase;

/**
 * Misión app-ia-imagen-prometida (29/10/2026), corrección del revisor independiente — las frases.
 *
 * ImagenPrometidaIaHelper decide dos cosas que dependen solo del texto: si la persona pidió generar una
 * imagen nueva, y si la respuesta del asistente la da por pedida o en curso. Acá están, una por una, las
 * frases que el revisor probó contra el primer arreglo (c49fb2e5): las que tienen que disparar y, sobre
 * todo, las que NO (gráficos, diseños de PDF, historias de compras, presupuestos y ventas con «la imagen»,
 * fotos del catálogo, ofrecimientos y preguntas del asistente). Sin base ni red: el helper es puro.
 */
class Imagen_prometida_frases_Test extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public function pedidos_de_generar_una_imagen()
    {
        return $this->como_casos([
            'Haceme una imagen cuadrada para Instagram promocionando el chaleco de polar azul, con estilo moderno y fondo claro',
            'diseñame un flyer para la promo',
            'armame un posteo para Instagram',
            'necesito una placa para historias',
            'creá una imagen de la vidriera',
            'hacé una imagen del chaleco',
            'generá una placa con el 20% off',
            '¿me podés hacer una imagen para Instagram?',
            'quiero que me hagas una imagen del local',
            'haceme una imagen del local para redes',
            'necesito una imagen para promocionar los chalecos',
            'Quiero una imagen para Instagram',
            'dame una imagen para instagram del chaleco',
            'una placa para el día del padre',
            'imagen para redes del chaleco porfa',
            'me armás un flyer?',
            'Te pido una imagen para Instagram',
            'podés hacerme una placa?',
            'haceme una foto para instagram del chaleco',
            'Hacé un flyer de la promo',
            'Generame un flyer del chaleco',
            'creame una imagen de la vidriera del local',
            'haceme una imagen de la sucursal para Instagram',
            'quiero un banner para la tienda',
            'Haceme una imagen del chaleco, mandásela a mis clientes por WhatsApp',
            'Haceme un arte para redes',
        ]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function pedidos_que_no_son_de_generar_una_imagen()
    {
        return $this->como_casos([
            // Lo que el revisor marcó como falso positivo.
            'haceme una gráfica de las ventas del mes',
            'armame una gráfica de ventas por vendedor',
            '¿me hacés un gráfico de ventas?',
            'necesito un diseño de factura con mi logo',
            'quiero un diseño nuevo para el presupuesto',
            '¿podés cambiar el diseño del PDF?',
            'haceme el diseño del ticket más chico',
            'me armás una portada para el catálogo?',
            'necesito una portada para el catálogo pdf',
            '¿me hacés el presupuesto con la imagen?',
            'me hacés la historia de compras de Juan?',
            'haceme una historia de ventas del cliente Juan',
            'haceme una venta a Juan y mandale la imagen del comprobante',
            'armame un post para facebook con el texto de la promo',
            'armame un post-venta para los clientes',
            'armame el texto de un posteo para Instagram',
            'escribime un posteo para Instagram del chaleco',
            'necesito un texto para una historia de Instagram',
            'haceme un banner de texto para la tienda',
            'podés armar un banner en la tienda?',
            // Las fotos e imágenes del catálogo, los comprobantes y las preguntas sueltas.
            'mandame la foto del chaleco',
            'ponele una imagen a la categoría remeras',
            'buscá imágenes para los artículos de Bosch',
            'quiero ver la imagen del artículo',
            'quiero una imagen del artículo chaleco en la tienda',
            'quiero una imagen para el artículo 123',
            'armame una imagen para cada artículo sin foto',
            'ponele una foto al artículo chaleco',
            'necesito una foto del chaleco azul para el catálogo',
            'Haceme un presupuesto para Marcos con la foto del producto',
            'armame la venta del chaleco, el de la foto',
            'armame una promo por WhatsApp con la imagen del chaleco',
            'me hacés un resumen de ventas de la historia del mes?',
            'haceme la historia clínica del cliente',
            'cambiá el diseño del pdf del presupuesto',
            'quiero un diseño nuevo de factura',
            'haceme el diseño del presupuesto con el logo',
            'haceme una lista',
            'hacé la factura',
            'el sistema genera una imagen?',
            'hace un rato te mandé una foto',
        ]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function textos_que_afirman_la_imagen()
    {
        return $this->como_casos([
            'La imagen la puse en marcha con el chaleco a $27.680,42. De paso te preparé una promoción por WhatsApp del chaleco a 25 clientes.',
            'Vendiste $50.000 hoy. La imagen la estoy generando.',
            'La imagen quedó pedida; aparece acá cuando esté lista.',
            'Ya pedí la imagen; aparece acá cuando esté lista.',
            'La imagen se está generando.',
            'Te estoy preparando la imagen.',
            'Listo, te diseñé un flyer con el texto: 20% off',
            'La imagen va a aparecer acá.',
            'La imagen está en camino.',
            'Te hice la imagen del chaleco.',
        ]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function textos_que_no_afirman_la_imagen()
    {
        return $this->como_casos([
            // 🔴 Ofrecimientos y preguntas: no son hechos (el primer arreglo los leía como tales).
            '¿Querés que te genere una imagen para Instagram con el más vendido?',
            '¿Querés que te arme una placa para Instagram?',
            '¿Querés que te prepare un flyer con la oferta?',
            '¿Querés que te diseñe un banner para la promo?',
            'Si querés, te genero una placa.',
            'Si querés, te armo una placa para Instagram.',
            'Vendiste $80.000 hoy. ¿Querés que te arme una placa para Instagram con el chaleco?',
            'Hoy vendiste $120.000. Si querés, te genero una imagen para Instagram con el chaleco.',
            'Te dejé la tarjeta de la promoción. Si querés, también te hago una imagen para acompañarla.',
            '¿Querés que te genere una imagen para Instagram?',
            'Puedo generar una imagen para Instagram: ¿la hago?',
            '¿Querés que la imagen sea cuadrada o vertical? Cuando me digas, la pido.',
            'Para la imagen necesito saber: ¿cuadrada o vertical?',
            // Gráficos, diseños de PDF, presupuestos, historias de compras y textos de posteos.
            'Te armé la gráfica.',
            'Te armé la gráfica de ventas del mes: subieron un 20%.',
            'Te armé el gráfico de ventas del mes.',
            'Te preparé el diseño nuevo de la factura con tu logo: confirmalo en la tarjeta.',
            'El nuevo diseño de PDF está listo para confirmar.',
            'Te dejé la tarjeta para cambiar el diseño del ticket.',
            'El diseño del PDF quedó listo.',
            'Te preparé el diseño del presupuesto.',
            'La tarjeta del diseño de PDF está lista para confirmar.',
            'Te armé el presupuesto con la imagen del chaleco.',
            'Te armé una historia de ventas del cliente: compró 3 veces.',
            'La historia de compras del cliente está lista.',
            'Te hice la historia de compras de Juan: compró 4 veces este año.',
            'Te armé el texto del posteo: "Llegó el chaleco polar..."',
            'Te preparé el texto para la historia de Instagram:',
            'Te preparé el posteo para Instagram: ...',
            'Te dejo el texto del posteo para Instagram: "Chaleco polar azul..."',
            'Te hice el post de Instagram con el texto: "..."',
            // El catálogo, la tienda y lo que ya estaba de antes.
            'Te armé la promoción por WhatsApp para 25 clientes.',
            'Ya te mandé la imagen del artículo.',
            'La foto del artículo quedó asignada.',
            'Le puse la imagen a la categoría remeras.',
            'Te armé las imágenes de los artículos sin foto para que elijas.',
            'Busqué imágenes para los artículos de Bosch: están en camino las propuestas.',
            'Las imágenes de los artículos están en camino.',
            'El banner de la tienda quedó listo.',
            'Te armé la promo; la imagen del chaleco ya está en el artículo.',
            'Vendiste 30 chalecos. El banner de la tienda en breve se actualiza.',
            'Ya tenés una imagen generada en el mensaje de arriba.',
            'La imagen que te pedí ayer ya está lista arriba.',
            'No pude pedir la imagen. ¿Me la pedís de nuevo?',
            // Sin un pedido de imagen en este turno o el anterior, «la pedí» no dice qué se pidió.
            'La pedí; aparece acá cuando esté lista.',
            'Listo, la pedí. Mientras, te cuento: el chaleco se vendió 12 veces este mes.',
        ]);
    }

    /**
     * @dataProvider pedidos_de_generar_una_imagen
     */
    public function test_el_pedido_de_generar_una_imagen_se_reconoce($pedido)
    {
        $this->assertTrue(ImagenPrometidaIaHelper::pide_generar_una_imagen($pedido));
    }

    /**
     * @dataProvider pedidos_que_no_son_de_generar_una_imagen
     */
    public function test_lo_que_no_es_un_pedido_de_generar_una_imagen_no_dispara($pedido)
    {
        $this->assertFalse(ImagenPrometidaIaHelper::pide_generar_una_imagen($pedido));
    }

    /**
     * @dataProvider textos_que_afirman_la_imagen
     */
    public function test_el_texto_que_da_la_imagen_por_pedida_se_reconoce($texto)
    {
        $this->assertTrue(ImagenPrometidaIaHelper::afirma_una_imagen($texto));
        $this->assertTrue(ImagenPrometidaIaHelper::afirma_una_imagen($texto, true), 'Con un pedido de imagen, también.');
    }

    /**
     * @dataProvider textos_que_no_afirman_la_imagen
     */
    public function test_el_texto_que_no_da_la_imagen_por_pedida_no_dispara($texto)
    {
        $this->assertFalse(ImagenPrometidaIaHelper::afirma_una_imagen($texto));
    }

    public function test_la_pedi_cuenta_solo_si_la_persona_pidio_una_imagen()
    {
        foreach (['La pedí; aparece acá cuando esté lista.', 'Listo, la pedí. Mientras, te cuento: el chaleco se vendió 12 veces este mes.'] as $texto) {
            $this->assertFalse(ImagenPrometidaIaHelper::afirma_una_imagen($texto, false), $texto);
            $this->assertTrue(ImagenPrometidaIaHelper::afirma_una_imagen($texto, true), $texto);
        }

        // Con el pedido, las preguntas, los ofrecimientos y lo que no es la imagen siguen sin contar.
        foreach ([
            '¿Querés que la imagen sea cuadrada o vertical? Cuando me digas, la pido.',
            'Te armé la gráfica.',
            'Te armé el presupuesto con la imagen del chaleco.',
            'No pude pedir la imagen. ¿Me la pedís de nuevo?',
        ] as $texto) {
            $this->assertFalse(ImagenPrometidaIaHelper::afirma_una_imagen($texto, true), $texto);
        }
    }

    public function test_sin_lo_prometido_cambia_solo_las_oraciones_de_la_imagen_y_deja_los_datos()
    {
        $this->assertSame(
            "Vendiste \$50.000 hoy. El más vendido fue el chaleco.\n\n" . ImagenPrometidaIaHelper::TEXTO_HONESTO,
            ImagenPrometidaIaHelper::sin_lo_prometido('Vendiste $50.000 hoy. La imagen la estoy generando. El más vendido fue el chaleco.', false)
        );

        $this->assertSame(
            "Te preparé una promoción por WhatsApp del chaleco a 25 clientes.\n\n" . ImagenPrometidaIaHelper::TEXTO_HONESTO,
            ImagenPrometidaIaHelper::sin_lo_prometido('La imagen la puse en marcha con el chaleco a $27.680,42. Te preparé una promoción por WhatsApp del chaleco a 25 clientes.', false)
        );

        // Los renglones también se respetan.
        $this->assertSame(
            "Resumen de hoy:\n- Ventas: \$50.000\n\n" . ImagenPrometidaIaHelper::TEXTO_HONESTO,
            ImagenPrometidaIaHelper::sin_lo_prometido("Resumen de hoy:\n- Ventas: \$50.000\nLa imagen se está generando.", false)
        );

        // Si no queda nada, va solo el aviso; y el aviso puede ser otro (el error del bloque).
        $this->assertSame(ImagenPrometidaIaHelper::TEXTO_HONESTO, ImagenPrometidaIaHelper::sin_lo_prometido('La imagen se está generando.', false));
        $this->assertSame('No está disponible.', ImagenPrometidaIaHelper::sin_lo_prometido('La pedí; aparece acá cuando esté lista.', true, 'No está disponible.'));

        // Una oración con «;» se saca entera si un tramo afirma la imagen (no queda un «aparece acá» suelto).
        $this->assertSame(
            "Hoy vendiste 3 chalecos.\n\n" . ImagenPrometidaIaHelper::TEXTO_HONESTO,
            ImagenPrometidaIaHelper::sin_lo_prometido('Hoy vendiste 3 chalecos. Si querés, te cuento más; la imagen se está generando.', false)
        );

        // «La pedí» sin pedido de imagen no se toca.
        $this->assertSame(
            "La pedí; aparece acá cuando esté lista.\n\n" . ImagenPrometidaIaHelper::TEXTO_HONESTO,
            ImagenPrometidaIaHelper::sin_lo_prometido('La pedí; aparece acá cuando esté lista.', false)
        );
    }

    public function test_termina_preguntando()
    {
        $this->assertTrue(ImagenPrometidaIaHelper::termina_preguntando('Dale. ¿La querés cuadrada o vertical?'));
        $this->assertTrue(ImagenPrometidaIaHelper::termina_preguntando('¿Qué producto tiene que mostrar? Decime y la pido'));
        $this->assertFalse(ImagenPrometidaIaHelper::termina_preguntando('¿Cuadrada? Listo, la pedí.'));
        $this->assertFalse(ImagenPrometidaIaHelper::termina_preguntando('Te preparé una promoción por WhatsApp.'));
        $this->assertFalse(ImagenPrometidaIaHelper::termina_preguntando(''));
    }

    public function test_las_herramientas_de_otra_funcion_excluyen_la_guarda()
    {
        foreach ([
            'mostrar_grafico', 'mostrar_tabla', 'proponer_cambio_en_diseno_pdf', 'consultar_disenos_de_pdf',
            'proponer_venta', 'proponer_presupuesto', 'proponer_envio_de_comprobante',
            'mostrar_imagenes_de_articulos', 'proponer_imagenes_para_articulos', 'proponer_imagenes_para_categorias',
            'proponer_foto_articulo', 'proponer_foto_sucursal', 'consultar_categorias_sin_imagen',
        ] as $herramienta) {
            $this->assertTrue(ImagenPrometidaIaHelper::hablo_de_otra_cosa(['buscar_articulos', $herramienta]), $herramienta);
        }

        $this->assertFalse(ImagenPrometidaIaHelper::hablo_de_otra_cosa([]));
        $this->assertFalse(ImagenPrometidaIaHelper::hablo_de_otra_cosa(['buscar_articulos', 'consultar_resumen_de_ventas']));
    }

    public function test_la_nota_del_reintento_no_ordena_llamar_a_ciegas()
    {
        $nota = ImagenPrometidaIaHelper::nota_para_reintentar();

        $this->assertStringNotContainsString('AHORA', $nota);
        $this->assertStringContainsString('Si tenés los datos', $nota);
        $this->assertStringContainsString('si te falta algo, preguntáselo a la persona', $nota);
        $this->assertStringStartsWith('[El sistema', $nota);
    }

    /**
     * @param  array<int, string>  $frases
     * @return array<string, array{0: string}>
     */
    protected function como_casos(array $frases)
    {
        $casos = [];

        foreach ($frases as $frase) {
            $casos[$frase] = [$frase];
        }

        return $casos;
    }
}
