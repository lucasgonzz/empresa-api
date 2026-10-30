<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

/**
 * La imagen que el texto promete tiene que estar pedida (misión app-ia-imagen-prometida, 29/10/2026).
 *
 * Caso real (demo3, conversación 13, 4.3.25, canal app, Rápido = DeepSeek Flash): a «Haceme una imagen
 * cuadrada para Instagram promocionando el chaleco de polar azul…» el asistente contestó «La imagen la
 * puse en marcha con el chaleco a $27.680,42» y armó una promoción por WhatsApp que nadie pidió, pero
 * NUNCA llamó a pedir_imagen: ni pedido en ia_imagen_pedidos ni bloque `imagen_generada`. El dueño
 * quedó esperando una imagen que no existía.
 *
 * Es la misma clase que la tarjeta y la carga prometidas (TarjetaPrometidaIaHelper) y se resuelve con el
 * mismo mecanismo en AsistenteIaService: si el turno no llamó a pedir_imagen y el texto da la imagen por
 * pedida (o la persona pidió claramente generar una y el modelo no preguntó por un dato de la imagen),
 * UN reintento con la nota de acá; si después el texto sigue prometiéndola sin pedido, se saca SOLO esa
 * oración y se suma TEXTO_HONESTO (sin_lo_prometido()): el resto de la respuesta —una venta, unos
 * datos— se queda.
 *
 * 🔴 LAS CORRECCIONES DE LA REVISIÓN QUE HAY QUE CUIDAR:
 *   - El texto del ASISTENTE se lee CON TILDES: sin ellas, «¿querés que te genere una placa?» (subjuntivo,
 *     un ofrecimiento) queda igual a «te generé una placa» (pasado, un hecho). Cuenta como afirmación solo
 *     una oración afirmativa en pasado o progresivo de la imagen ya pedida, y NUNCA una pregunta o un
 *     ofrecimiento («¿querés que…?», «si querés te…», «puedo…»).
 *   - Las palabras de OTRAS funciones no son imágenes generadas: la gráfica o el gráfico (mostrar_grafico),
 *     el diseño de un PDF, la historia de compras de un cliente, el post-venta, la portada del catálogo, el
 *     banner de la tienda, ni la imagen de un artículo, una categoría o un comprobante. Ni el pedido ni el
 *     texto cuentan cuando hablan de eso, y si el turno usó una herramienta de esas, la guarda no mira.
 *   - 🔴 «Placa» y «banner» son PRODUCTOS en ferreterías y corralones (placa de yeso, placa de durlock,
 *     banner con ojales): sin contexto de redes o de promo en la misma frase («para Instagram», «para el
 *     estado», «para historias», «con la promo», «20% off») no son una imagen, ni en el pedido ni en el
 *     texto. «Imagen» y «flyer» no necesitan contexto. Sin esto, cada consulta de stock de Ferre Total
 *     disparaba un reintento.
 */
class ImagenPrometidaIaHelper
{
    /** Lo que se le dice a la persona cuando el modelo prometió una imagen que no pidió. */
    const TEXTO_HONESTO = 'No pude pedir la imagen. ¿Me la pedís de nuevo?';

    /** La herramienta que pide la imagen (HerramientasDeLaApp::PEDIR_IMAGEN). */
    const HERRAMIENTA = 'pedir_imagen';

    /**
     * Herramientas que, si el turno las usó, dicen que se estaba hablando de OTRA cosa: las imágenes del
     * catálogo, los gráficos y las tablas, el diseño de un PDF, y las tarjetas de venta, presupuesto o
     * comprobante (que pueden llevar «la imagen del comprobante»).
     */
    const HERRAMIENTAS_QUE_EXCLUYEN = [
        'mostrar_imagenes_de_articulos',
        'proponer_imagenes_para_articulos',
        'proponer_imagenes_para_categorias',
        'proponer_foto_articulo',
        'proponer_foto_sucursal',
        'consultar_categorias_sin_imagen',
        'mostrar_grafico',
        'mostrar_tabla',
        'proponer_cambio_en_diseno_pdf',
        'consultar_disenos_de_pdf',
        'proponer_venta',
        'proponer_presupuesto',
        'proponer_envio_de_comprobante',
    ];

    /** Dónde va lo que se genera, en los patrones (se reemplaza por QUE_SE_PIDE / QUE_SE_GENERA y sus pares). */
    const QUE = '{QUE}';

    /*
     * ------------------------------------------------------------------------------------------------
     * El PEDIDO de la persona (minúscula, sin tildes; el imperativo de voseo con tilde se marca antes)
     * ------------------------------------------------------------------------------------------------
     */

    /**
     * Lo que se genera, en un pedido, sin necesitar contexto. Sin `grafica`, `diseno`, `portada` ni `post`
     * suelto (son de otras funciones), con la historia y el estado SOLO de redes, y sin `placa` ni `banner`
     * (ver QUE_SE_PIDE_CON_CONTEXTO).
     */
    const QUE_SE_PIDE = '(?:imagen|imagenes|flyer|flyers|posteo|posteos|arte para (?:redes|instagram|facebook)|foto para (?:redes|instagram|facebook|whatsapp|una publicacion|(?:el estado|la historia|una historia)(?! (?:de cuenta|clinica|de compras|de ventas|del cliente)))|historias? (?:de|para) instagram)';

    /** Lo que es un producto del catálogo tanto como una imagen: cuenta solo con CONTEXTO_DEL_PEDIDO. */
    const QUE_SE_PIDE_CON_CONTEXTO = '(?:placa|placas|banner|banners)';

    /** Las redes o una promo, en la misma frase del pedido: lo que hace de «una placa» una imagen. */
    const CONTEXTO_DEL_PEDIDO = '/(*UCP)\b(?:instagram|insta|facebook|tiktok|redes|posteo|posteos|publicar|publicacion|promocionar|promocionando|reels?)\b|\b(?:para|con)\s+(?:la |una |el |un |esta |nuestra |mi )?(?:promo|promos|promocion|promociones)\b|\bpara\s+(?:el |los |un |mi |mis )?(?:estado|estados|whatsapp|wpp)\b|\bpara\s+(?:la |las |una |mis )?historias?\b|%\s*off\b|\bdia del? (?:padre|madre|nino|ninos|amigo|enamorados)\b|\b(?:navidad|black friday|hot sale|cyber monday)\b/u';

    /** Lo que es del catálogo, de un comprobante o de la tienda, y no se genera. */
    const PEDIDO_DE_OTRA_COSA = '/(*UCP)\b(?:foto|fotos|imagen|imagenes)\s+(?:del|de la|de los|de las|de|al|a la|a los|a las|para el|para la|para los|para las|para|en el|en la)\s+(?:(?:este|esta|ese|esa|cada|todos los|todas las|el|la|los|las)\s+)?(?:articulo|articulos|categoria|categorias|subcategoria|subcategorias|catalogo|comprobante|comprobantes|factura|facturas|presupuesto|presupuestos|venta|ventas)\b|\b(?:foto|fotos)\s+(?:de la|del|de las|de los)\s+(?:sucursal|sucursales|local|locales)\b|\b(?:articulos?|categorias?)\s+sin\s+(?:foto|fotos|imagen|imagenes)\b|\bbanners?\s+(?:de texto|en la tienda|de la tienda|del ecommerce)\b|\btexto (?:de|del|para) (?:un|una|el|la)?\s*(?:posteo|historia|post)\b/u';

    /** El imperativo de voseo con tilde: sin tilde, «genera» o «crea» también son la tercera persona. */
    const IMPERATIVO_CON_TILDE = '/(?<!\p{L})(?:hacé|generá|creá|diseñá|armá|prepará|dibujá)(?!\p{L})/u';

    /** La marca con que se reemplaza el imperativo con tilde (ver IMPERATIVO_CON_TILDE). */
    const MARCA_DE_IMPERATIVO = 'pedidoimperativo';

    /**
     * Un pedido de generar: verbo de pedido + artículo INDEFINIDO + lo que se genera («haceme UNA imagen»,
     * «me armás UN flyer»). Con el definido («¿me hacés el presupuesto con LA imagen?», «mandale LA imagen
     * del comprobante») es una imagen que ya existe. Y un mensaje que arranca con lo que se genera («un
     * flyer para la promo», «imagen para redes del chaleco»). `{QUE}` es QUE_SE_PIDE o
     * QUE_SE_PIDE_CON_CONTEXTO.
     */
    const PATRONES_DEL_PEDIDO = [
        '/(*UCP)\b(?:haceme|hacenos|hacele|generame|generanos|generale|creame|creanos|disename|disenanos|armame|armanos|armale|preparame|preparanos|dibujame|dame|danos|mandame|' . self::MARCA_DE_IMPERATIVO . ')\s+(?:(?:una|un|unas|unos|otra|otro)\s+)(?:\S+\s+){0,2}?' . self::QUE . '\b/u',
        '/(*UCP)\b(?:me (?:haces|hace|generas|genera|creas|crea|disenas|disena|armas|arma|preparas|prepara|dibujas|podes hacer|podes generar|podes crear|podes armar|podrias hacer|podrias generar|podrias armar)|(?:podes|podrias|podra|puede|podran) (?:hacer|generar|crear|disenar|armar|preparar|dibujar)(?:me|nos)?|se puede (?:hacer|generar|crear|armar)|te pido|les pido|le pido|pido)\s+(?:(?:una|un|unas|unos|otra|otro)\s+)(?:\S+\s+){0,2}?' . self::QUE . '\b/u',
        '/(*UCP)\b(?:quiero|necesito|queria|necesitaria|quisiera)\s+(?:que\s+(?:me\s+)?(?:hagas|generes|crees|disenes|armes|prepares|dibujes)\s+)?(?:una|un|unas|unos|otra|otro)\s+(?:\S+\s+){0,2}?' . self::QUE . '\b/u',
        '/(*UCP)^(?:(?:una|un|otra|otro)\s+)?' . self::QUE . '\s+(?:para|de|del|con)\b/u',
    ];

    /**
     * Otra versión de la imagen que ya se pidió en la vuelta anterior («hacé otra igual pero vertical»,
     * «haceme otra más clara», «otra con fondo blanco», «hacela vertical», «¿y en vertical?»). Solo se mira
     * si la vuelta anterior pidió una imagen de verdad. «Otra» tiene que ir sola o seguida de algo de la
     * imagen: «haceme otra venta» u «otro presupuesto» no son otra imagen.
     */
    const OTRA_VERSION = '/(*UCP)\b(?:haceme|hacemela|hacenos|generame|generamela|creame|disename|armame|armamela|preparame|dame|mandame|pasame|quiero|necesito|' . self::MARCA_DE_IMPERATIVO . ')\s+(?:(?:una|la)\s+)?(?:otra|otro|version)(?=[\s.!?,]*$|\s+(?:igual|iguales|mas|pero|version|en|con|sin|vertical|horizontal|cuadrada|parecida|parecido|similar|asi|imagen|placa|flyer|banner|como|de esas|por favor|porfa)\b)|^[¿¡]?\s*(?:y\s+)?(?:otra|otro)\s+(?:igual|mas|pero|version|en|con|sin|vertical|horizontal|cuadrada|parecida|similar|asi)\b|\b(?:hacela|hacemela|pasala|pasamela|ponela|rehacela|dejala|generala|armala)\s+(?:\S+\s+){0,2}?(?:vertical|horizontal|cuadrada|mas clara|mas oscura|mas grande|mas chica|con otro|con otra|sin el|sin la|de nuevo)\b|^[¿¡]?\s*(?:y\s+)?(?:en|a|ahora en)\s+(?:version\s+)?(?:vertical|horizontal|cuadrada)\b/u';

    /*
     * ------------------------------------------------------------------------------------------------
     * El TEXTO del asistente (minúscula, CON tildes)
     * ------------------------------------------------------------------------------------------------
     */

    /** Lo que se generó, en el texto del asistente, sin necesitar contexto (el posteo puede ser un texto). */
    const QUE_SE_GENERA = '(?:imagen|imágenes|flyer|flyers|arte para redes)';

    /** Lo que es un producto tanto como una imagen («ya pedí las placas de durlock»): solo con CONTEXTO_DEL_TEXTO. */
    const QUE_SE_GENERA_CON_CONTEXTO = '(?:placa|placas|banner|banners)';

    /** Las redes o una promo, en la misma oración del texto (ver CONTEXTO_DEL_PEDIDO). */
    const CONTEXTO_DEL_TEXTO = '/(*UCP)\b(?:instagram|insta|facebook|tiktok|redes|posteo|posteos|publicar|publicación|promocionar|promocionando|reels?)\b|\b(?:para|con)\s+(?:la |una |el |un |esta |tu )?(?:promo|promos|promoción|promociones)\b|\bpara\s+(?:el |los |un |tu |tus )?(?:estado|estados|whatsapp)\b|\bpara\s+(?:la |las |una |tus )?historias?\b|%\s*off\b|\bdía del? (?:padre|madre|niño|niños|amigo|enamorados)\b|\b(?:navidad|black friday|hot sale|cyber monday)\b/u';

    /**
     * La imagen ya pedida, en pasado o en progresivo: lo que se afirma DESPUÉS del sustantivo («la imagen
     * la puse en marcha», «la imagen se está generando», «la imagen quedó pedida», «la imagen está en
     * camino», «la imagen va a aparecer acá») o el verbo con el sustantivo como objeto, pegado («ya pedí la
     * imagen», «te diseñé un flyer», «te estoy preparando la placa»). `{QUE}` es QUE_SE_GENERA o
     * QUE_SE_GENERA_CON_CONTEXTO.
     */
    const PATRONES_DEL_TEXTO = [
        '/(*UCP)\b' . self::QUE . '\b(?:[^.!?\n]){0,40}?(?:la puse en marcha|lo puse en marcha|puse en marcha|está en marcha|en marcha|se está (?:generando|haciendo|armando|creando|preparando|diseñando)|la estoy (?:generando|haciendo|armando|creando|preparando|diseñando)|está en camino|ya está pedida|quedó pedida|está pedida|la pedí|ya la pedí|va a aparecer|aparece (?:acá|sola|en este))/u',
        '/(*UCP)(?:puse en marcha|mandé a (?:generar|hacer)|encargué|ya pedí|pedí|estoy (?:generando|haciendo|armando|creando|preparando|diseñando)|te (?:estoy )?(?:generando|haciendo|armando|creando|preparando|diseñando)|te (?:generé|hice|armé|creé|diseñé|preparé)|ya (?:te )?(?:generé|hice|armé|creé|diseñé|preparé)|generé|diseñé)\s+(?:(?:la|las|una|unas|un|el|tu|nueva|otra)\s+){0,2}' . self::QUE . '\b/u',
    ];

    /**
     * Las frases SIN sustantivo que afirman la imagen pedida («la pedí; aparece acá cuando esté lista»,
     * la que la herramienta misma le dicta al modelo). Solo cuentan si la persona había pedido una imagen
     * en este turno o en el inmediatamente anterior: si no, «la pedí» puede ser cualquier cosa.
     */
    const PATRONES_CON_PEDIDO_DE_IMAGEN = [
        '/(*UCP)(?:^|[\s,;:¡!])(?:ya\s+)?la\s+pedí\b/u',
        '/(*UCP)\baparece (?:acá|aquí|sola)\b(?:[^.!?\n]){0,30}?cuando (?:esté|este) lista\b/u',
        '/(*UCP)\bla estoy (?:generando|haciendo|armando|creando|preparando|diseñando)\b/u',
        '/(*UCP)\bse está (?:generando|haciendo|armando|creando|preparando|diseñando)\b/u',
        '/(*UCP)\b(?:la )?puse en marcha\b/u',
    ];

    /**
     * Lo que dice en qué ESTADO está la imagen ya pedida («la pedí», «aparece acá», «se está generando»,
     * «está en camino»). Si la vuelta anterior la pidió de verdad y la persona no pidió otra, una oración
     * así habla de esa imagen y no es una promesa sin pedido. «Te hice la imagen» no es un estado.
     */
    const ESTADO_DE_LA_YA_PEDIDA = '/(*UCP)(?:^|[\s,;:¡!])(?:ya\s+)?(?:la\s+)?pedí\b|\b(?:quedó pedida|está pedida|ya está pedida|se está (?:generando|haciendo|armando|creando|preparando|diseñando)|la estoy (?:generando|haciendo|armando|creando|preparando|diseñando)|está en camino|va a aparecer|aparece (?:acá|aquí|sola|en este)|en marcha)\b/u';

    /** Lo que dice que es OTRA imagen y no la ya pedida («la imagen vertical», «la nueva placa», «otra versión»). */
    const OTRA_IMAGEN = '/(*UCP)\b(?:otra|otras|otro|otros|nueva|nuevas|nuevo|segunda|versión|distinta|vertical|horizontal|cuadrada)\b/u';

    /** Lo que, en la oración, dice que NO se hizo: «no pude pedir la imagen», «todavía no la pedí». */
    const NEGACION = '/(*UCP)\b(?:no pude|no puedo|no se pudo|no la pedí|no la puse|no está disponible|no hay forma|no logré|todavía no|aún no|no se generó|no se está)\b/u';

    /** Una oración que pregunta u ofrece: no afirma nada («¿querés que te arme…?», «si querés te…», «puedo…»). */
    const PREGUNTA_U_OFRECIMIENTO = '/(*UCP)[¿?]|\b(?:si querés|si queres|si te sirve|si te parece|si preferís|querés que|queres que|te gustaría|puedo|podría|podés|cuando me (?:digas|confirmes|pases)|avisame|decime)\b/u';

    /** Lo que, después de una pregunta, solo espera la respuesta («Decime y la pido», «Cuando me digas, la armo»). */
    const ESPERA_LA_RESPUESTA = '/(*UCP)^(?:y\s+)?(?:cuando me (?:digas|confirmes|pases|contestes)|decime|avisame|confirmame|contame|pasame|con eso)\b/u';

    /**
     * Lo que hace de una pregunta una pregunta POR LA IMAGEN: un dato de la imagen (formato, tamaño,
     * orientación, texto, producto, colores, estilo, fondo, logo) o la imagen misma. «¿La confirmás?» o
     * «¿Algo más?» no lo son.
     */
    const DATO_DE_LA_IMAGEN = '/(*UCP)\b(?:imagen|imágenes|placa|placas|flyer|flyers|banner|banners|posteo|arte|formato|tamaño|medida|medidas|cuadrada|cuadrado|vertical|horizontal|historia|historias|feed|instagram|redes|texto|frase|leyenda|título|titulo|producto|productos|artículo|artículos|color|colores|estilo|fondo|logo|foto|fotos|precio|precios|tipografía|letra|letras)\b/u';

    /** Una oración que habla de antes («la imagen que te pedí ayer», «la del mensaje de arriba»). */
    const DE_ANTES = '/(*UCP)\b(?:ayer|arriba|anteriormente|en el mensaje anterior|de antes|el otro día|la semana pasada|hace un rato)\b/u';

    /** Una oración que habla de otra función (el catálogo, la tienda, un comprobante, un gráfico, un PDF). */
    const TEXTO_DE_OTRA_COSA = '/(*UCP)\b(?:foto|fotos|imagen|imágenes)\s+(?:del|de la|de los|de las|de|al|a la|a los|a las|para el|para la|para los|para las|en el|en la)\s+(?:(?:cada|todos los|todas las|el|la|los|las)\s+)?(?:artículo|artículos|categoría|categorías|subcategoría|catálogo|comprobante|factura|presupuesto|venta)\b|\b(?:foto|fotos)\s+(?:de la|del)\s+(?:sucursal|local)\b|\bbanners?\s+(?:de la tienda|en la tienda|del ecommerce)\b|\b(?:gráfica|gráfico|diseño|presupuesto|venta)\b/u';

    /**
     * true si la persona pidió claramente GENERAR una imagen («haceme una imagen para Instagram…»). No
     * cuenta si habla de las imágenes del catálogo, de un comprobante, del banner de la tienda o de algo que
     * no es una imagen (una gráfica, un diseño de PDF, la historia de compras), ni una placa o un banner
     * sin contexto de redes o de promo («quiero una placa de durlock»).
     *
     * @param  string  $pedido
     * @return bool
     */
    public static function pide_generar_una_imagen($pedido): bool
    {
        foreach (self::oraciones(self::pedido_normalizado($pedido)) as $oracion) {

            if (preg_match(self::PEDIDO_DE_OTRA_COSA, $oracion)) {
                continue;
            }

            if (self::coincide(self::PATRONES_DEL_PEDIDO, self::QUE_SE_PIDE, $oracion)) {

                return true;
            }

            if (self::coincide(self::PATRONES_DEL_PEDIDO, self::QUE_SE_PIDE_CON_CONTEXTO, $oracion)
                && preg_match(self::CONTEXTO_DEL_PEDIDO, $oracion)) {

                return true;
            }
        }

        return false;
    }

    /**
     * true si la persona pide OTRA versión de una imagen («hacé otra igual pero vertical», «haceme otra
     * más clara»). El servicio lo mira solo cuando la vuelta anterior pidió una imagen de verdad.
     *
     * @param  string  $pedido
     * @return bool
     */
    public static function pide_otra_version($pedido): bool
    {
        foreach (self::oraciones(self::pedido_normalizado($pedido)) as $oracion) {

            if (preg_match(self::OTRA_VERSION, $oracion)) {

                return true;
            }
        }

        return false;
    }

    /**
     * true si el texto da por pedida (o hecha) una imagen nueva: alguna oración la afirma (ver
     * oracion_afirma()).
     *
     * @param  string  $texto
     * @param  bool  $hubo_pedido_de_imagen  true si la persona pidió una imagen en este turno o en el
     *                                       anterior: ahí «la pedí; aparece acá cuando esté lista» cuenta.
     * @param  bool  $hay_una_ya_pedida  true si la vuelta anterior pidió una imagen de verdad y la persona
     *                                   no pidió otra: las oraciones que solo dicen en qué estado está esa
     *                                   imagen («la pedí», «se está generando», «aparece acá») no cuentan.
     * @return bool
     */
    public static function afirma_una_imagen($texto, $hubo_pedido_de_imagen = false, $hay_una_ya_pedida = false): bool
    {
        return self::alguna_afirma(mb_strtolower((string) $texto, 'UTF-8'), $hubo_pedido_de_imagen, $hay_una_ya_pedida);
    }

    /**
     * El texto sin las oraciones que afirman la imagen, con el aviso al final (o solo el aviso, si no
     * queda nada). Así una respuesta con datos («Vendiste $50.000 hoy») conserva los datos y pierde solo
     * la promesa falsa.
     *
     * @param  string  $texto
     * @param  bool  $hubo_pedido_de_imagen
     * @param  string  $aviso  TEXTO_HONESTO, o el error del bloque si la imagen quedó no disponible.
     * @param  bool  $hay_una_ya_pedida  Ver afirma_una_imagen().
     * @return string
     */
    public static function sin_lo_prometido($texto, $hubo_pedido_de_imagen, $aviso = self::TEXTO_HONESTO, $hay_una_ya_pedida = false): string
    {
        $renglones = [];

        foreach (preg_split('/\n/u', (string) $texto) as $renglon) {

            $partes   = preg_split('/(?<=[.!?])\s+/u', $renglon);
            $quedan   = [];

            foreach (is_array($partes) ? $partes : [] as $parte) {

                if (trim($parte) === '') {
                    continue;
                }

                /*
                 * Se mira cada tramo entre «;» igual que afirma_una_imagen(), pero se saca la oración
                 * entera: «La imagen quedó pedida; aparece acá cuando esté lista.» no deja un «aparece
                 * acá» suelto, y un «Si querés, te cuento; la imagen se está generando.» no se salva por
                 * el ofrecimiento del primer tramo.
                 */
                if (self::alguna_afirma(mb_strtolower($parte, 'UTF-8'), $hubo_pedido_de_imagen, $hay_una_ya_pedida)) {
                    continue;
                }

                $quedan[] = $parte;
            }

            $renglones[] = implode(' ', $quedan);
        }

        $resto = trim(preg_replace("/\n{3,}/u", "\n\n", implode("\n", $renglones)));

        return $resto === '' ? (string) $aviso : $resto . "\n\n" . $aviso;
    }

    /**
     * true si el texto TERMINA preguntando por un dato de la IMAGEN (el formato, el tamaño, el texto de la
     * placa, el producto, los colores, el estilo) o por la imagen misma. Un pedido de imagen contestado así
     * no se reintenta: el modelo hizo lo que tenía que hacer. Cuenta la última oración con «?», o una
     * pregunta seguida solo de la espera de la respuesta («¿Cuadrada o vertical? Decime y la pido.»).
     * 🔴 Cualquier otra pregunta final no alcanza: «Te preparé una promoción por WhatsApp. ¿La confirmás?»
     * ante «Generá una imagen…» se reintenta.
     *
     * @param  string  $texto
     * @return bool
     */
    public static function termina_preguntando_por_la_imagen($texto): bool
    {
        $oraciones = self::oraciones((string) $texto);

        if (!count($oraciones)) {

            return false;
        }

        $pregunta = (string) end($oraciones);

        if (mb_strpos($pregunta, '?') === false) {

            $anterior = count($oraciones) > 1 ? (string) $oraciones[count($oraciones) - 2] : '';

            if (mb_strpos($anterior, '?') === false
                || !preg_match(self::ESPERA_LA_RESPUESTA, mb_strtolower($pregunta, 'UTF-8'))) {

                return false;
            }

            $pregunta = $anterior;
        }

        return (bool) preg_match(self::DATO_DE_LA_IMAGEN, mb_strtolower($pregunta, 'UTF-8'));
    }

    /**
     * true si el turno usó una herramienta de otra función (ver HERRAMIENTAS_QUE_EXCLUYEN).
     *
     * @param  array<int, string>  $herramientas_pedidas
     * @return bool
     */
    public static function hablo_de_otra_cosa(array $herramientas_pedidas): bool
    {
        return count(array_intersect($herramientas_pedidas, self::HERRAMIENTAS_QUE_EXCLUYEN)) > 0;
    }

    /**
     * La nota del reintento. Arranca con "[El sistema", como las otras: si el modelo la repitiera,
     * TextoFinalIaHelper saca ese renglón. No le ordena llamar a la herramienta a ciegas: si le falta un
     * dato, lo correcto es preguntarlo.
     *
     * @return string
     */
    public static function nota_para_reintentar(): string
    {
        return '[El sistema revisó tu respuesta: la persona pidió que se genere una imagen (o tu respuesta dice que la pediste), '
            . 'pero en este turno NO llamaste a pedir_imagen, así que NO se pidió ninguna imagen. '
            . 'Si tenés los datos (qué tiene que mostrar y el tamaño), llamá a pedir_imagen con un prompt detallado; si te falta algo, preguntáselo a la persona. '
            . 'Si la pedís, decí en una línea que la pediste y que aparece acá cuando esté lista, sin prometer tiempos; si no, no digas que la pediste. '
            . 'Pedir una imagen no autoriza a preparar promociones, envíos ni mensajes a clientes: no los armes si la persona no los pidió. '
            . 'No le menciones este aviso a la persona.]';
    }

    /**
     * true si UNA oración (en minúscula, con tildes) afirma la imagen pedida.
     *
     * @param  string  $oracion
     * @param  bool  $hubo_pedido_de_imagen
     * @param  bool  $hay_una_ya_pedida
     * @return bool
     */
    protected static function oracion_afirma($oracion, $hubo_pedido_de_imagen, $hay_una_ya_pedida = false): bool
    {
        if (preg_match(self::PREGUNTA_U_OFRECIMIENTO, $oracion)
            || preg_match(self::NEGACION, $oracion)
            || preg_match(self::DE_ANTES, $oracion)
            || preg_match(self::TEXTO_DE_OTRA_COSA, $oracion)) {

            return false;
        }

        $afirma = self::coincide(self::PATRONES_DEL_TEXTO, self::QUE_SE_GENERA, $oracion)
            || (self::coincide(self::PATRONES_DEL_TEXTO, self::QUE_SE_GENERA_CON_CONTEXTO, $oracion)
                && preg_match(self::CONTEXTO_DEL_TEXTO, $oracion))
            || ($hubo_pedido_de_imagen && self::coincide(self::PATRONES_CON_PEDIDO_DE_IMAGEN, '', $oracion));

        if (!$afirma) {

            return false;
        }

        /*
         * La imagen de la vuelta anterior se pidió de verdad: «la pedí», «se está generando» o «aparece
         * acá» hablan de esa. Salvo que digan que es otra («la imagen vertical se está generando»).
         */
        return !($hay_una_ya_pedida
            && preg_match(self::ESTADO_DE_LA_YA_PEDIDA, $oracion)
            && !preg_match(self::OTRA_IMAGEN, $oracion));
    }

    /**
     * true si alguna de las oraciones del texto (cortado como en afirma_una_imagen()) afirma la imagen.
     *
     * @param  string  $texto  En minúscula.
     * @param  bool  $hubo_pedido_de_imagen
     * @param  bool  $hay_una_ya_pedida
     * @return bool
     */
    protected static function alguna_afirma($texto, $hubo_pedido_de_imagen, $hay_una_ya_pedida = false): bool
    {
        foreach (self::oraciones($texto) as $oracion) {

            if (self::oracion_afirma($oracion, $hubo_pedido_de_imagen, $hay_una_ya_pedida)) {

                return true;
            }
        }

        return false;
    }

    /**
     * true si alguno de los patrones (con `{QUE}` reemplazado por $que) coincide con la oración.
     *
     * @param  array<int, string>  $patrones
     * @param  string  $que
     * @param  string  $oracion
     * @return bool
     */
    protected static function coincide(array $patrones, $que, $oracion): bool
    {
        foreach ($patrones as $patron) {

            if (preg_match(str_replace(self::QUE, (string) $que, $patron), $oracion)) {

                return true;
            }
        }

        return false;
    }

    /**
     * El pedido en minúscula, con el imperativo de voseo con tilde marcado y después sin tildes.
     *
     * @param  string  $pedido
     * @return string
     */
    protected static function pedido_normalizado($pedido): string
    {
        $marcado = (string) preg_replace(self::IMPERATIVO_CON_TILDE, ' ' . self::MARCA_DE_IMPERATIVO . ' ', mb_strtolower((string) $pedido, 'UTF-8'));

        return self::sin_tildes($marcado);
    }

    /**
     * Las oraciones de un texto (por punto, signo o renglón). El punto de un número («$27.680,42») no corta:
     * hace falta un espacio después.
     *
     * @param  string  $texto
     * @return array<int, string>
     */
    protected static function oraciones($texto): array
    {
        $partes = preg_split('/(?<=[.!?;])\s+|\n+/u', (string) $texto);

        return array_values(array_filter(array_map('trim', is_array($partes) ? $partes : []), function ($parte) {
            return $parte !== '';
        }));
    }

    /**
     * Sin tildes y con la ñ como n (solo para el PEDIDO: el texto del asistente se lee con tildes).
     *
     * @param  string  $texto
     * @return string
     */
    protected static function sin_tildes($texto): string
    {
        return strtr(trim((string) $texto), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
    }
}
