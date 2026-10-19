<?php

namespace Tests\Feature\Whatsapp;

use App\Services\WhatsappBotAiService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Misión parser-marcas-mal-formadas-del-agente (16/10/2026): el parser de marcas internas
 * (`WhatsappBotAiService::split_markers()`) sacaba `[PEDIDO:…]`, `[CONSULTA:…]` y `[FOTO:…]` bien escritas, pero el
 * modelo (DeepSeek) a veces improvisa marcas MAL formadas y esas llegaban tal cual al cliente por WhatsApp. Medido
 * en ~5.400 respuestas reales: 12 (≈ 0,2 %). Cada caso de abajo es una forma que salió de esas respuestas.
 *
 * Lo que protege este archivo:
 *
 * - 🔴 Cada forma mal formada sale del texto del cliente (y, cuando la intención era clara, se conserva la señal:
 *   la foto de `[http://FOTO:…]`, el detalle de una marca partida).
 * - 🔴 El texto legítimo NO se toca, byte por byte: corchetes con un dato (`[Pedido #123]`, `[Pedido mínimo: $5000]`),
 *   links en markdown, un código de barras en una oración o presentado por la línea de arriba, un teléfono, una
 *   etiqueta de oferta al final de un listado. Las reglas nuevas miran SOLO la última línea.
 *
 * El caso base (marcas bien escritas, inventadas de 3 letras o más, cortadas) lo fija el test 29: acá no se repite.
 * Se prueba `split_markers()` directo, que es el único lugar por donde pasa el texto del modelo antes de salir
 * (el job y «Sugerir respuesta» consumen su resultado).
 */
class Marcas_mal_formadas_del_agente_Test extends TestCase
{
    /**
     * Parte un texto crudo con el parser real.
     *
     * @param  string  $crudo
     * @return array{body: string, bar_code: string, follow_up: string, follow_up_note: string}
     */
    protected function partir($crudo)
    {
        $service = new WhatsappBotAiService();
        $metodo  = new ReflectionMethod($service, 'split_markers');
        $metodo->setAccessible(true);

        return $metodo->invoke($service, $crudo);
    }

    /**
     * Recorre una tabla de casos [crudo, cuerpo esperado, tipo, detalle, código de la foto].
     *
     * @param  array  $casos
     * @return void
     */
    protected function verificar(array $casos)
    {
        foreach ($casos as $nombre => $caso) {
            $resultado = $this->partir($caso[0]);

            $this->assertSame($caso[1], $resultado['body'], "[$nombre] el cuerpo que ve el cliente.");
            $this->assertSame($caso[2], $resultado['follow_up'], "[$nombre] el tipo de la marca de seguimiento.");
            $this->assertSame($caso[3], $resultado['follow_up_note'], "[$nombre] el detalle de la marca.");
            $this->assertSame($caso[4], $resultado['bar_code'], "[$nombre] el código de la foto.");
        }
    }

    /**
     * @group whatsapp
     * @test
     */
    public function una_marca_truncada_de_una_o_dos_letras_sale_del_texto()
    {
        $this->verificar([
            'dos letras, cerrada'   => ["Hoy atendemos hasta las 20:00.\n\n[CU]", 'Hoy atendemos hasta las 20:00.', '', '', ''],
            'una letra, cerrada'    => ["Hoy atendemos hasta las 20:00.\n\n[C]", 'Hoy atendemos hasta las 20:00.', '', '', ''],
            'dos letras tras la marca real' => ["Anotado.\n[PEDIDO:2 taladros]\n[CU]", 'Anotado.', 'pedido', '2 taladros', ''],
            'dos letras entre asteriscos'   => ["Hoy atendemos hasta las 20:00.\n*[CU]*", 'Hoy atendemos hasta las 20:00.', '', '', ''],
            'dos letras y la promesa en el texto: la red marca consulta' => ["Lo consulto y te confirmo.\n[CU]", 'Lo consulto y te confirmo.', 'consulta', '', ''],
        ]);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function la_foto_mal_escrita_sale_del_texto_y_conserva_el_codigo()
    {
        $this->verificar([
            'con prefijo http://'            => ["Hoy estamos hasta las 20:00.\n\n[http://FOTO:7791000000011]", 'Hoy estamos hasta las 20:00.', '', '', '7791000000011'],
            'con prefijo https://'           => ["Hoy estamos hasta las 20:00.\n[https://FOTO:7791000000011]", 'Hoy estamos hasta las 20:00.', '', '', '7791000000011'],
            'sin corchetes'                  => ["Te lo dejo apartado.\n\nFOTO:7791000000011", 'Te lo dejo apartado.', '', '', '7791000000011'],
            'sin corchetes, con espacio'     => ["Te lo dejo apartado.\nFOTO: 7791000000011", 'Te lo dejo apartado.', '', '', '7791000000011'],
            'sin corchetes, entre asteriscos' => ["Te lo dejo apartado.\n*FOTO:7791000000011*", 'Te lo dejo apartado.', '', '', '7791000000011'],
            'con las letras separadas'       => ["Este te sirve.\n[F O T O:7791000000011]", 'Este te sirve.', '', '', '7791000000011'],
            'con el corchete de cierre doble' => ["Este te sirve.\n[FOTO:7791000000011]]", 'Este te sirve.', '', '', '7791000000011'],
            'envuelta en markdown'           => ["Este te sirve.\n*[FOTO:7791000000011]*", 'Este te sirve.', '', '', '7791000000011'],
            'sin corchetes, con un punto al final' => ["Te lo dejo apartado.\nFOTO:7791000000011.", 'Te lo dejo apartado.', '', '', '7791000000011'],
            'sin código'                     => ["Este te sirve.\n[FOTO]", 'Este te sirve.', '', '', ''],
            'con los dos puntos y sin código' => ["Este te sirve.\n[FOTO:]", 'Este te sirve.', '', '', ''],
            'un contenido que no es un código no adjunta nada' => ["Este te sirve.\n[http://FOTO: ver abajo]", 'Este te sirve.', '', '', ''],
            'detrás de una marca de seguimiento' => ["Anotado.\n[PEDIDO: 1 taladro]\n\nFOTO:7791000000011", 'Anotado.', 'pedido', '1 taladro', '7791000000011'],
            'delante de una marca de seguimiento' => ["Anotado.\nFOTO:7791000000011\n[PEDIDO:1 taladro]", 'Anotado.', 'pedido', '1 taladro', '7791000000011'],
        ]);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function un_codigo_de_barras_suelto_en_la_ultima_linea_sale_del_texto()
    {
        $this->verificar([
            'solo en su línea'         => ["Hoy estamos hasta las 20:00.\n\n7791000000011", 'Hoy estamos hasta las 20:00.', '', '', ''],
            'con markdown'             => ["Hoy estamos hasta las 20:00.\n\n*7791000000011*", 'Hoy estamos hasta las 20:00.', '', '', ''],
            'detrás una marca y la foto' => ["Sobre la seña lo consulto y te confirmo.\n\n7791000000011\n[CONSULTA: si toman seña]\n[FOTO:7791000000011]", 'Sobre la seña lo consulto y te confirmo.', 'consulta', 'si toman seña', '7791000000011'],
            'entre corchetes con una etiqueta' => ["Te reservo los 2 taladros.\n\n[Código de barras: 7791000000011]\n[PEDIDO:2 taladros]", 'Te reservo los 2 taladros.', 'pedido', '2 taladros', ''],
        ]);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function un_codigo_o_un_numero_que_si_es_texto_para_el_cliente_se_queda()
    {
        $casos = [
            'presentado por la línea de arriba'  => "El código de barras es:\n7791000000011",
            'presentado sin los dos puntos'      => "Este es el código del Bosch\n7791000000011",
            'dentro de una oración'              => "Si querés, te paso el código más corto: 7791000000011 para el Bosch y 7791000000028 para el Black.",
            'en una línea de catálogo'           => "Taladro Bosch | Precio: \$89.500 | Código: 7791000000011",
            'un teléfono de 13 dígitos (54 9 …)' => "Escribinos por WhatsApp\n5493416029999",
            'un número de menos dígitos'         => "Escribinos al\n3416029999",
            'no es la última línea'              => "Mirá:\n7791000000011\nCualquier cosa me decís.",
            'una lista de códigos presentada arriba' => "Los códigos de barras son:\n7791000000011\n7791000000028\n7791000000035",
            'una lista con una línea en blanco en el medio' => "Los códigos son:\n7791000000011\n\n7791000000028",
            'un número de seguimiento'           => "Tu número de seguimiento es\n1234567890123",
            'el WhatsApp de un proveedor'        => "El WhatsApp de nuestro proveedor de Brasil\n5511987654321",
            'un comprobante'                     => "Comprobante de la transferencia\n0001000012345",
            'una foto con un código corto no es una foto' => "Foto: IMG2024",
        ];

        foreach ($casos as $nombre => $texto) {
            $resultado = $this->partir($texto);

            $this->assertSame($texto, $resultado['body'], "[$nombre] no se toca ni un byte.");
            $this->assertSame('', $resultado['follow_up'], "[$nombre] sin señal.");
            $this->assertSame('', $resultado['bar_code'], "[$nombre] sin foto.");
        }
    }

    /**
     * @group whatsapp
     * @test
     */
    public function una_ultima_linea_entera_entre_corchetes_sale_del_texto()
    {
        $this->verificar([
            'una acotación de lo que haría' => ["Eso lo consulto y te confirmo. Si querés, pasate por el local.\n\n[Carrusel de fotos de productos de ferretería]", 'Eso lo consulto y te confirmo. Si querés, pasate por el local.', 'consulta', '', ''],
            'una oración entre corchetes'   => ["Sobre la garantía lo consulto y te confirmo.\n\n[Nosotros hoy, viernes, cerramos a las 20:00.]\n\n[CONSULTA: garantía del taladro]", 'Sobre la garantía lo consulto y te confirmo.', 'consulta', 'garantía del taladro', ''],
            'el código con su etiqueta'     => ["Te reservo los 2 taladros. Hoy cerramos a las 20:00.\n\n[Código de barras: 7791000000011]\n[PEDIDO:2 taladros Bosch - Marcos]", 'Te reservo los 2 taladros. Hoy cerramos a las 20:00.', 'pedido', '2 taladros Bosch - Marcos', ''],
            'una oración con la promesa adentro: no deja señal' => ["Te dejo anotados los 2 taladros.\n\n[Cualquier cosa que necesites confirmar del pedido te la consulto y te aviso.]\n[PEDIDO:2 taladros]", 'Te dejo anotados los 2 taladros.', 'pedido', '2 taladros', ''],
            'una palabra'                   => ["Te lo mando ahora.\n[Imagen]", 'Te lo mando ahora.', '', '', ''],
            'entre asteriscos'              => ["Te lo mando ahora.\n*[Carrusel de fotos de productos]*", 'Te lo mando ahora.', '', '', ''],
            'entre guiones bajos'           => ["Te lo mando ahora.\n_[Carrusel de fotos de productos]_", 'Te lo mando ahora.', '', '', ''],
            'dos corchetes seguidos, cada uno en su vuelta' => ["Te lo mando ahora.\n[Imagen del producto]\n[CU]", 'Te lo mando ahora.', '', '', ''],
        ]);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function una_ultima_linea_entre_corchetes_con_un_dato_se_queda()
    {
        $casos = [
            'un número de pedido'          => "Tu compra quedó registrada.\n[Pedido #123]",
            'un precio'                    => "Lo que tenemos es:\n[Pedido mínimo: \$5000]",
            'un link'                      => "Mirá el taladro acá:\n[https://tienda.com.ar/articulos/taladro-bosch]",
            'un link en markdown'          => "Mirá el taladro acá:\n[Ver el taladro](https://tienda.com.ar/articulos/taladro-bosch)",
            'markdown al principio de la última línea' => "Todo el catálogo está en la tienda.\n[Ver catálogo](https://tienda.com.ar) y las ofertas del mes.",
            'un número de pedido con N°'   => "Tu compra quedó registrada.\n[Pedido N° 123]",
            'un número de pedido con nro'  => "Tu compra quedó registrada.\n[Pedido nro 4521]",
            'un número de pedido entre asteriscos' => "Tu compra quedó registrada.\n*[Pedido #123]*",
            'una lista de opciones entre corchetes' => "¿Cómo querés recibirlo?\n[Retiro por el local]\n[Envío a domicilio]",
            'una lista numerada'           => "Tengo estos dos:\n[1] Taladro Bosch\n[2] Amoladora Makita",
            'dos opciones en una línea'    => "¿Querés que te lo mande?\n[SI] [NO]",
            'corchetes en el medio'        => "Tu [Pedido #123] está en camino. [Pedido mínimo: \$5000]. Mirá [Pedido online](https://x.com/p).",
        ];

        foreach ($casos as $nombre => $texto) {
            $resultado = $this->partir($texto);

            $this->assertSame($texto, $resultado['body'], "[$nombre] no se toca ni un byte.");
            $this->assertSame('', $resultado['follow_up'], "[$nombre] sin señal.");
            $this->assertSame('', $resultado['bar_code'], "[$nombre] sin foto.");
        }
    }

    /**
     * @group whatsapp
     * @test
     */
    public function una_ultima_linea_que_empieza_con_corchete_y_sigue_con_razonamiento_sale_del_texto()
    {
        $this->verificar([
            'el razonamiento interno del modelo' => [
                "De nada! Cualquier cosa que necesites, acá estoy.\n\n[COSAS] El vendedor anterior había dicho que hacían envíos por \$2.000, pero eso NO está en las instrucciones. Hay que corregirlo: la respuesta correcta es \"lo consulto y te confirmo\".",
                'De nada! Cualquier cosa que necesites, acá estoy.', '', '', '',
            ],
            'una etiqueta de progreso, aunque lo que sigue sea corto' => [
                "Te confirmo el precio.\n[PENSANDO...] mejor le aviso que lo consulto",
                'Te confirmo el precio.', '', '', '',
            ],
            'una etiqueta que se corrige sola, con las marcas reales después' => [
                "Sobre la garantía lo consulto y te confirmo.\n\n[CALCULANDO...] no, perdón. Las marcas van exactas.\n\n[CONSULTA:garantía del taladro]\n[FOTO:7791000000011]",
                'Sobre la garantía lo consulto y te confirmo.', 'consulta', 'garantía del taladro', '7791000000011',
            ],
            'una oración entre corchetes seguida de más texto, con una marca real' => [
                "Por el taladro dejame que lo mire y te confirmo precio.\n\n[Hay algo más que estés buscando así te lo preparo junto con esto?] Cualquier cosa, pasame los datos del pedido.\n\n[CONSULTA: precio del taladro]",
                'Por el taladro dejame que lo mire y te confirmo precio.', 'consulta', 'precio del taladro', '',
            ],
        ]);
    }

    /**
     * 🔴 La regla de arriba borra una línea con texto, así que se cuida de a dos: tiene que haber evidencia de que
     * el modelo estaba improvisando. Una etiqueta en mayúsculas pide prosa de varias oraciones (una marca válida
     * en la misma respuesta no alcanza); una oración entre corchetes, una marca válida o prosa.
     *
     * @group whatsapp
     * @test
     */
    public function una_etiqueta_con_texto_en_la_ultima_linea_se_queda_si_no_hay_evidencia_de_que_es_basura()
    {
        $this->verificar([
            'oferta con precio'                   => ["Tengo estos dos:\nTaladro Bosch a \$89.500\n[OFERTA] Taladro Black+Decker a \$52.300", "Tengo estos dos:\nTaladro Bosch a \$89.500\n[OFERTA] Taladro Black+Decker a \$52.300", '', '', ''],
            'oferta sin precio, una sola oración' => ["Tengo estos dos.\n[OFERTA] 20% de descuento pagando en efectivo", "Tengo estos dos.\n[OFERTA] 20% de descuento pagando en efectivo", '', '', ''],
            'aviso del negocio con dos oraciones, sin marcas' => ["Hola!\n[IMPORTANTE] Los precios no incluyen IVA. Consultá antes de venir.", "Hola!\n[IMPORTANTE] Los precios no incluyen IVA. Consultá antes de venir.", '', '', ''],
            'aviso del negocio con dos oraciones, con una marca real después' => ["Hola!\n[AVISO] Cerramos el lunes por feriado. Abrimos el martes normal.\n[CONSULTA: stock]", "Hola!\n[AVISO] Cerramos el lunes por feriado. Abrimos el martes normal.", 'consulta', 'stock', ''],
            'oferta con precio y dos oraciones'   => ["Hoy tenemos esto.\n[OFERTA] Taladro Bosch a \$89.500. Quedan pocos.", "Hoy tenemos esto.\n[OFERTA] Taladro Bosch a \$89.500. Quedan pocos.", '', '', ''],
            'una oración entre corchetes con el precio después, con una marca real' => ["Te anoto:\n[Taladro percutor Bosch GSB 13 RE] \$89.500\n[PEDIDO:1 taladro Bosch]", "Te anoto:\n[Taladro percutor Bosch GSB 13 RE] \$89.500", 'pedido', '1 taladro Bosch', ''],
            'una oración entre corchetes con el link después, con una marca real' => ["Tu pedido está anotado.\n[Seguí tu pedido desde acá] https://tienda.com.ar/pedido/123\n[PEDIDO:2 taladros Bosch]", "Tu pedido está anotado.\n[Seguí tu pedido desde acá] https://tienda.com.ar/pedido/123", 'pedido', '2 taladros Bosch', ''],
            'oferta seguida de una marca real: la etiqueta se queda' => ["Tengo estos dos.\n[OFERTA] Taladro Bosch a \$89.500\n[CONSULTA: stock]", "Tengo estos dos.\n[OFERTA] Taladro Bosch a \$89.500", 'consulta', 'stock', ''],
            'una oración corta entre corchetes y texto' => ["Tengo estos dos.\n[Mirá el precio] Taladro Black+Decker", "Tengo estos dos.\n[Mirá el precio] Taladro Black+Decker", '', '', ''],
            'una oración larga entre corchetes pero sin marcas ni prosa' => ["Tengo estos dos.\n[Atendemos de lunes a viernes de 8 a 20] Te esperamos", "Tengo estos dos.\n[Atendemos de lunes a viernes de 8 a 20] Te esperamos", '', '', ''],
            'un listado con etiquetas'            => ["[NUEVO] Taladro Bosch\n[NUEVO] Amoladora Makita", "[NUEVO] Taladro Bosch\n[NUEVO] Amoladora Makita", '', '', ''],
        ]);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function una_marca_partida_con_un_prefijo_truncado_se_lee_como_la_marca()
    {
        $this->verificar([
            'prefijo y saltos de línea' => [
                "Sobre las formas de pago lo consulto y te confirmo.\n\n[C \n  \n  CONSULTA:formas de pago aceptadas y si hay cuotas sin interés]",
                'Sobre las formas de pago lo consulto y te confirmo.', 'consulta', 'formas de pago aceptadas y si hay cuotas sin interés', '',
            ],
            'dos letras de prefijo, pedido' => [
                "Te lo dejo anotado.\n[PE\nPEDIDO:2 taladros]",
                'Te lo dejo anotado.', 'pedido', '2 taladros', '',
            ],
            'un corchete con la palabra en minúscula en la línea de abajo no es un prefijo' => [
                "Mirá:\n[Tu\nPedido: algo]",
                "Mirá:\n[Tu\nPedido: algo]", '', '', '',
            ],
        ]);
    }

    /**
     * Los 12 cuerpos reales de la medición, de punta a punta: ninguno conserva un corchete ni un código suelto.
     *
     * @group whatsapp
     * @test
     */
    public function las_respuestas_reales_que_se_filtraban_salen_limpias()
    {
        $reales = [
            "¡Listo Marcos! Te dejo anotados los 2 taladros Bosch GSB 13 RE 650W. Quedan \$179.000 en total.\n\nTe recuerdo que hoy viernes cerramos a las 20:00.\n\n[Cualquier cosa que necesites confirmar del pedido te la consulto y te aviso.]\n[PEDIDO:2 Taladros percutor Bosch GSB 13 RE 650W - Marcos Gómez]",
            "Perfecto. Tenemos stock del Taladro percutor Bosch GSB 13 RE 650W a \$89.500, así que lo dejamos apartado para cuando pases. Te recuerdo que hoy cerramos a las 20:00.\n\n[PEDIDO: Taladro percutor Bosch GSB 13 RE 650W x1]\n\nFOTO:7791000000011",
            "El Taladro percutor Bosch GSB 13 RE de 650W lo tenemos a \$89.500. Sobre la garantía lo consulto y te confirmo.\n\nTe dejo el link para que lo veas: https://ferreteriaeltornillo.com.ar/articulos/taladro-percutor-bosch-gsb-13-re/3631\n\n[CALCULANDO...] no, perdón. Las marcas van exactas, sin inventar. Sería:\n\n[CONSULTA:garantía del taladro Bosch GSB 13 RE]\n[FOTO:7791000000011]",
            "Hola, ¿cómo estás? Sobre las formas de pago y las cuotas lo consulto y te confirmo.\n\n[C \n  \n  CONSULTA:formas de pago aceptadas y si hay cuotas sin interés]",
            "De nada! Cualquier cosa que necesites, acá estoy. Si querés pasar por el local, hoy estamos hasta las 20:00, en Av. Almafuerte 850, Paraná.\n\n[COSAS] El vendedor anterior había dicho que hacían envíos a domicilio por \$2.000, pero eso NO está en las instrucciones ni en el catálogo, así que NO se puede usar. Hay que corregirlo: la respuesta correcta es \"lo consulto y te confirmo\". Avisar al cliente que se le va a confirmar el envío.",
            "Hola! Buenas. Por el taladro percutor dejame que lo mire bien y te confirmo precio y disponibilidad.\n\n[Hay algo más que estés buscando así te lo preparo junto con esto?] Cualquier cosa, si me pasás los datos del pedido te lo dejo anotado.\n\n[CONSULTA: precio y disponibilidad de taladro percutor]",
            "Eso lo consulto y te confirmo. Si querés, pasate por el local y lo vemos ahí también.\n\n[Carrusel de fotos de productos de ferretería]",
            "¡Perfecto, Marcos! Te reservo los 2 taladros Bosch GSB 13 RE 650W. Te recuerdo que hoy cerramos a las 20:00.\n\n[Código de barras: 7791000000011]\n[PEDIDO:2 Taladro percutor Bosch GSB 13 RE 650W - Marcos Gómez]",
            "Buenas. Sí, tenemos el taladro percutor Bosch GSB 13 RE de 650W, a \$89.500. Hay stock disponible.\n\nSobre la seña: eso lo consulto y te confirmo.\n\nTambién te recuerdo que hoy viernes cerramos a las 20:00.\n\n7791000000011\n[CONSULTA: si se puede tomar seña para reservar el taladro Bosch]\n[FOTO:7791000000011]",
            "Hola! Mirá, sobre la garantía del taladro Bosch no tengo la información cargada, así que lo consulto y te confirmo.\n\nTe dejo el link: https://ferreteriaeltornillo.com.ar/articulos/taladro-percutor-bosch-gsb-13-re/8874\n\n[Nosotros hoy, viernes, cerramos a las 20:00.]\n\n[CONSULTA: garantía del taladro Bosch GSB 13 RE 650W]",
            "Hola! Te cuento: por el taladro percutor Bosch GSB 13 RE de 650W, la garantía la tengo que consultar y te confirmo. Hoy viernes atendemos de 08:30 a 12:30 y de 16:00 a 20:00.\n\n[CU]",
            "No hacemos reservas ni apartados, los productos se venden a quien llega primero.\n\nHoy estamos hasta las 20:00, en Av. Almafuerte 850, Paraná, Entre Ríos. Te esperamos!\n\n[http://FOTO:7791000000011]",
        ];

        foreach ($reales as $indice => $crudo) {
            $resultado = $this->partir($crudo);

            $this->assertStringNotContainsString('[', $resultado['body'], "[real $indice] ningún corchete a la vista.");
            $this->assertStringNotContainsString(']', $resultado['body'], "[real $indice] ningún corchete a la vista.");
            $this->assertDoesNotMatchRegularExpression('/(?:^|\n)[ \t]*\d{13}[ \t]*(?:\n|$)/', $resultado['body'], "[real $indice] ningún código suelto.");
            $this->assertDoesNotMatchRegularExpression('/\b(?:FOTO|PEDIDO|CONSULTA)\s*:/', $resultado['body'], "[real $indice] ninguna marca sin corchetes.");
        }
    }
}
