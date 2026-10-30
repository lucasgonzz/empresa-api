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
 * pedida (o la persona pidió claramente generar una y el modelo no preguntó nada), UN reintento con la
 * nota de acá; si después el texto sigue prometiéndola sin pedido, se saca SOLO esa oración y se suma
 * TEXTO_HONESTO (sin_lo_prometido()): el resto de la respuesta —una venta, unos datos— se queda.
 *
 * 🔴 DOS CORRECCIONES DE LA REVISIÓN QUE HAY QUE CUIDAR:
 *   - El texto del ASISTENTE se lee CON TILDES: sin ellas, «¿querés que te genere una placa?» (subjuntivo,
 *     un ofrecimiento) queda igual a «te generé una placa» (pasado, un hecho). Cuenta como afirmación solo
 *     una oración afirmativa en pasado o progresivo de la imagen ya pedida, y NUNCA una pregunta o un
 *     ofrecimiento («¿querés que…?», «si querés te…», «puedo…»).
 *   - Las palabras de OTRAS funciones no son imágenes generadas: la gráfica o el gráfico (mostrar_grafico),
 *     el diseño de un PDF, la historia de compras de un cliente, el post-venta, la portada del catálogo, el
 *     banner de la tienda, ni la imagen de un artículo, una categoría o un comprobante. Ni el pedido ni el
 *     texto cuentan cuando hablan de eso, y si el turno usó una herramienta de esas, la guarda no mira.
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

    /*
     * ------------------------------------------------------------------------------------------------
     * El PEDIDO de la persona (minúscula, sin tildes; el imperativo de voseo con tilde se marca antes)
     * ------------------------------------------------------------------------------------------------
     */

    /**
     * Lo que se genera, en un pedido. Sin `grafica`, `diseno`, `portada` ni `post` suelto (son de otras
     * funciones) y con la historia SOLO de Instagram («historia de Instagram», «para historias»).
     */
    const QUE_SE_PIDE = '(?:imagen|imagenes|placa|placas|flyer|flyers|banner|banners|posteo|posteos|arte para (?:redes|instagram|facebook)|foto para (?:redes|instagram|facebook|whatsapp|el estado|la historia|una historia|una publicacion)|historias? (?:de|para) instagram)';

    /** Lo que es del catálogo, de un comprobante o de la tienda, y no se genera. */
    const PEDIDO_DE_OTRA_COSA = '/(*UCP)\b(?:foto|fotos|imagen|imagenes)\s+(?:del|de la|de los|de las|de|al|a la|a los|a las|para el|para la|para los|para las|para|en el|en la)\s+(?:(?:este|esta|ese|esa|cada|todos los|todas las|el|la|los|las)\s+)?(?:articulo|articulos|categoria|categorias|subcategoria|subcategorias|catalogo|comprobante|comprobantes|factura|facturas|presupuesto|presupuestos|venta|ventas)\b|\b(?:foto|fotos)\s+(?:de la|del|de las|de los)\s+(?:sucursal|sucursales|local|locales)\b|\b(?:articulos?|categorias?)\s+sin\s+(?:foto|fotos|imagen|imagenes)\b|\bbanners?\s+(?:de texto|en la tienda|de la tienda|del ecommerce)\b|\btexto (?:de|del|para) (?:un|una|el|la)?\s*(?:posteo|historia|post)\b/u';

    /** El imperativo de voseo con tilde: sin tilde, «genera» o «crea» también son la tercera persona. */
    const IMPERATIVO_CON_TILDE = '/(?<!\p{L})(?:hacé|generá|creá|diseñá|armá|prepará|dibujá)(?!\p{L})/u';

    /** La marca con que se reemplaza el imperativo con tilde (ver IMPERATIVO_CON_TILDE). */
    const MARCA_DE_IMPERATIVO = 'pedidoimperativo';

    /**
     * Un pedido de generar: verbo de pedido + artículo INDEFINIDO + lo que se genera («haceme UNA imagen»,
     * «me armás UN flyer»). Con el definido («¿me hacés el presupuesto con LA imagen?», «mandale LA imagen
     * del comprobante») es una imagen que ya existe. Y un mensaje que arranca con lo que se genera («una
     * placa para el día del padre», «imagen para redes del chaleco»).
     */
    const PATRONES_DEL_PEDIDO = [
        '/(*UCP)\b(?:haceme|hacenos|hacele|generame|generanos|generale|creame|creanos|disename|disenanos|armame|armanos|armale|preparame|preparanos|dibujame|dame|danos|mandame|' . self::MARCA_DE_IMPERATIVO . ')\s+(?:(?:una|un|unas|unos|otra|otro)\s+)(?:\S+\s+){0,2}?' . self::QUE_SE_PIDE . '\b/u',
        '/(*UCP)\b(?:me (?:haces|hace|generas|genera|creas|crea|disenas|disena|armas|arma|preparas|prepara|dibujas|podes hacer|podes generar|podes crear|podes armar|podrias hacer|podrias generar|podrias armar)|(?:podes|podrias|podra|puede|podran) (?:hacer|generar|crear|disenar|armar|preparar|dibujar)(?:me|nos)?|se puede (?:hacer|generar|crear|armar)|te pido|les pido|le pido|pido)\s+(?:(?:una|un|unas|unos|otra|otro)\s+)(?:\S+\s+){0,2}?' . self::QUE_SE_PIDE . '\b/u',
        '/(*UCP)\b(?:quiero|necesito|queria|necesitaria|quisiera)\s+(?:que\s+(?:me\s+)?(?:hagas|generes|crees|disenes|armes|prepares|dibujes)\s+)?(?:una|un|unas|unos|otra|otro)\s+(?:\S+\s+){0,2}?' . self::QUE_SE_PIDE . '\b/u',
        '/(*UCP)^(?:(?:una|un|otra|otro)\s+)?' . self::QUE_SE_PIDE . '\s+(?:para|de|del|con)\b/u',
    ];

    /*
     * ------------------------------------------------------------------------------------------------
     * El TEXTO del asistente (minúscula, CON tildes)
     * ------------------------------------------------------------------------------------------------
     */

    /** Lo que se generó, en el texto del asistente: solo lo visual (el posteo puede ser un texto). */
    const QUE_SE_GENERA = '(?:imagen|imágenes|placa|placas|flyer|flyers|banner|banners|arte para redes)';

    /**
     * La imagen ya pedida, en pasado o en progresivo: lo que se afirma DESPUÉS del sustantivo («la imagen
     * la puse en marcha», «la imagen se está generando», «la imagen quedó pedida», «la imagen está en
     * camino», «la imagen va a aparecer acá») o el verbo con el sustantivo como objeto, pegado («ya pedí la
     * imagen», «te diseñé un flyer», «te estoy preparando la placa»).
     */
    const PATRONES_DEL_TEXTO = [
        '/(*UCP)\b' . self::QUE_SE_GENERA . '\b(?:[^.!?\n]){0,40}?(?:la puse en marcha|lo puse en marcha|puse en marcha|está en marcha|en marcha|se está (?:generando|haciendo|armando|creando|preparando|diseñando)|la estoy (?:generando|haciendo|armando|creando|preparando|diseñando)|está en camino|ya está pedida|quedó pedida|está pedida|la pedí|ya la pedí|va a aparecer|aparece (?:acá|sola|en este))/u',
        '/(*UCP)(?:puse en marcha|mandé a (?:generar|hacer)|encargué|ya pedí|pedí|estoy (?:generando|haciendo|armando|creando|preparando|diseñando)|te (?:estoy )?(?:generando|haciendo|armando|creando|preparando|diseñando)|te (?:generé|hice|armé|creé|diseñé|preparé)|ya (?:te )?(?:generé|hice|armé|creé|diseñé|preparé)|generé|diseñé)\s+(?:(?:la|las|una|unas|un|el|tu|nueva|otra)\s+){0,2}' . self::QUE_SE_GENERA . '\b/u',
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

    /** Lo que, en la oración, dice que NO se hizo: «no pude pedir la imagen», «todavía no la pedí». */
    const NEGACION = '/(*UCP)\b(?:no pude|no puedo|no se pudo|no la pedí|no la puse|no está disponible|no hay forma|no logré|todavía no|aún no|no se generó|no se está)\b/u';

    /** Una oración que pregunta u ofrece: no afirma nada («¿querés que te arme…?», «si querés te…», «puedo…»). */
    const PREGUNTA_U_OFRECIMIENTO = '/(*UCP)[¿?]|\b(?:si querés|si queres|si te sirve|si te parece|si preferís|querés que|queres que|te gustaría|puedo|podría|podés|cuando me (?:digas|confirmes|pases)|avisame|decime)\b/u';

    /** Lo que, después de una pregunta, solo espera la respuesta («Decime y la pido», «Cuando me digas, la armo»). */
    const ESPERA_LA_RESPUESTA = '/(*UCP)^(?:y\s+)?(?:cuando me (?:digas|confirmes|pases|contestes)|decime|avisame|confirmame|contame|pasame|con eso)\b/u';

    /** Una oración que habla de antes («la imagen que te pedí ayer», «la del mensaje de arriba»). */
    const DE_ANTES = '/(*UCP)\b(?:ayer|arriba|anteriormente|en el mensaje anterior|de antes|el otro día|la semana pasada|hace un rato)\b/u';

    /** Una oración que habla de otra función (el catálogo, la tienda, un comprobante, un gráfico, un PDF). */
    const TEXTO_DE_OTRA_COSA = '/(*UCP)\b(?:foto|fotos|imagen|imágenes)\s+(?:del|de la|de los|de las|de|al|a la|a los|a las|para el|para la|para los|para las|en el|en la)\s+(?:(?:cada|todos los|todas las|el|la|los|las)\s+)?(?:artículo|artículos|categoría|categorías|subcategoría|catálogo|comprobante|factura|presupuesto|venta)\b|\b(?:foto|fotos)\s+(?:de la|del)\s+(?:sucursal|local)\b|\bbanners?\s+(?:de la tienda|en la tienda|del ecommerce)\b|\b(?:gráfica|gráfico|diseño|presupuesto|venta)\b/u';

    /**
     * true si la persona pidió claramente GENERAR una imagen («haceme una imagen para Instagram…»). No
     * cuenta si habla de las imágenes del catálogo, de un comprobante, del banner de la tienda o de algo que
     * no es una imagen (una gráfica, un diseño de PDF, la historia de compras).
     *
     * @param  string  $pedido
     * @return bool
     */
    public static function pide_generar_una_imagen($pedido): bool
    {
        $marcado = (string) preg_replace(self::IMPERATIVO_CON_TILDE, ' ' . self::MARCA_DE_IMPERATIVO . ' ', mb_strtolower((string) $pedido, 'UTF-8'));

        foreach (self::oraciones(self::sin_tildes($marcado)) as $oracion) {

            if (preg_match(self::PEDIDO_DE_OTRA_COSA, $oracion)) {
                continue;
            }

            foreach (self::PATRONES_DEL_PEDIDO as $patron) {

                if (preg_match($patron, $oracion)) {

                    return true;
                }
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
     * @return bool
     */
    public static function afirma_una_imagen($texto, $hubo_pedido_de_imagen = false): bool
    {
        return self::alguna_afirma(mb_strtolower((string) $texto, 'UTF-8'), $hubo_pedido_de_imagen);
    }

    /**
     * El texto sin las oraciones que afirman la imagen, con el aviso al final (o solo el aviso, si no
     * queda nada). Así una respuesta con datos («Vendiste $50.000 hoy») conserva los datos y pierde solo
     * la promesa falsa.
     *
     * @param  string  $texto
     * @param  bool  $hubo_pedido_de_imagen
     * @param  string  $aviso  TEXTO_HONESTO, o el error del bloque si la imagen quedó no disponible.
     * @return string
     */
    public static function sin_lo_prometido($texto, $hubo_pedido_de_imagen, $aviso = self::TEXTO_HONESTO): string
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
                if (self::alguna_afirma(mb_strtolower($parte, 'UTF-8'), $hubo_pedido_de_imagen)) {
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
     * true si el texto TERMINA preguntando (por un dato que falta: el formato, el texto de la placa, el
     * producto). Un pedido de imagen contestado así no se reintenta: el modelo hizo lo que tenía que hacer.
     * Cuenta la última oración con «?», o una pregunta seguida solo de la espera de la respuesta
     * («¿Cuadrada o vertical? Decime y la pido.»).
     *
     * @param  string  $texto
     * @return bool
     */
    public static function termina_preguntando($texto): bool
    {
        $oraciones = self::oraciones((string) $texto);

        if (!count($oraciones)) {

            return false;
        }

        $ultima = (string) end($oraciones);

        if (mb_strpos($ultima, '?') !== false) {

            return true;
        }

        return count($oraciones) > 1
            && mb_strpos((string) $oraciones[count($oraciones) - 2], '?') !== false
            && (bool) preg_match(self::ESPERA_LA_RESPUESTA, mb_strtolower($ultima, 'UTF-8'));
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
     * @return bool
     */
    protected static function oracion_afirma($oracion, $hubo_pedido_de_imagen): bool
    {
        if (preg_match(self::PREGUNTA_U_OFRECIMIENTO, $oracion)
            || preg_match(self::NEGACION, $oracion)
            || preg_match(self::DE_ANTES, $oracion)
            || preg_match(self::TEXTO_DE_OTRA_COSA, $oracion)) {

            return false;
        }

        foreach (self::PATRONES_DEL_TEXTO as $patron) {

            if (preg_match($patron, $oracion)) {

                return true;
            }
        }

        if ($hubo_pedido_de_imagen) {

            foreach (self::PATRONES_CON_PEDIDO_DE_IMAGEN as $patron) {

                if (preg_match($patron, $oracion)) {

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * true si alguna de las oraciones del texto (cortado como en afirma_una_imagen()) afirma la imagen.
     *
     * @param  string  $texto  En minúscula.
     * @param  bool  $hubo_pedido_de_imagen
     * @return bool
     */
    protected static function alguna_afirma($texto, $hubo_pedido_de_imagen): bool
    {
        foreach (self::oraciones($texto) as $oracion) {

            if (self::oracion_afirma($oracion, $hubo_pedido_de_imagen)) {

                return true;
            }
        }

        return false;
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
