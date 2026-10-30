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
 * pedida (o la persona pidió claramente generar una), UN reintento con la nota de acá; si después del
 * reintento el texto sigue prometiéndola sin pedido, se cambia por TEXTO_HONESTO.
 *
 * 🔴 LOS FALSOS POSITIVOS SON LAS IMÁGENES DEL CATÁLOGO. «La foto del artículo», «la imagen de la
 * categoría», «buscale fotos a los artículos sin imagen» o la foto de la sucursal no son pedidos de
 * GENERAR una imagen: son las herramientas de imágenes del catálogo (mostrar_imagenes_de_articulos,
 * proponer_imagenes_para_articulos, proponer_imagenes_para_categorias, proponer_foto_*). Ni el pedido ni
 * el texto cuentan cuando hablan de eso, y si el turno llamó a una de esas herramientas la guarda no mira.
 *
 * Trabaja sobre el texto en minúscula y sin tildes (normalizar()).
 */
class ImagenPrometidaIaHelper
{
    /** Lo que se le contesta a la persona cuando el modelo prometió una imagen que no pidió. */
    const TEXTO_HONESTO = 'No pude pedir la imagen. ¿Me la pedís de nuevo?';

    /** La herramienta que pide la imagen (HerramientasDeLaApp::PEDIR_IMAGEN). */
    const HERRAMIENTA = 'pedir_imagen';

    /**
     * Las herramientas de imágenes del CATÁLOGO: si el turno llamó a una, se estaba hablando de las fotos
     * de los artículos, las categorías o la sucursal, no de generar una imagen nueva.
     */
    const HERRAMIENTAS_DEL_CATALOGO = [
        'mostrar_imagenes_de_articulos',
        'proponer_imagenes_para_articulos',
        'proponer_imagenes_para_categorias',
        'proponer_foto_articulo',
        'proponer_foto_sucursal',
        'consultar_categorias_sin_imagen',
    ];

    /** Lo que se genera (el sustantivo), en minúscula y sin tildes. */
    const QUE = '(?:imagen|imagenes|placa|placas|flyer|flyers|banner|banners|posteo|posteos|post|historia|historias|portada|ilustracion|disenio|diseno|grafica|arte para redes|foto para (?:redes|instagram|facebook|whatsapp|el estado|la historia|una historia|una publicacion))';

    /** Lo que es del catálogo y no se genera: la foto o la imagen DE un artículo, una categoría, la sucursal. */
    const DEL_CATALOGO = '/\b(?:foto|fotos|imagen|imagenes)\s+(?:del|de la|de los|de las|de|al|a la|a los|a las|para el|para la|para los|para las|para)\s+(?:(?:este|esta|ese|esa|cada|todos los|todas las|el|la|los|las)\s+)?(?:articulo|articulos|categoria|categorias|subcategoria|subcategorias|sucursal|sucursales|catalogo|local)\b|\b(?:articulos?|categorias?)\s+sin\s+(?:foto|fotos|imagen|imagenes)\b/u';

    /**
     * El texto da la imagen por pedida, en marcha o hecha: «la puse en marcha», «la estoy generando»,
     * «ya la pedí», «aparece acá en unos minutos», «te armé la placa».
     */
    const PATRONES_DEL_TEXTO = [
        '/\b' . self::QUE . '\b(?:[^.!?\n]){0,80}?\b(?:puse en marcha|en marcha|se esta generando|se esta haciendo|se esta armando|esta en camino|en camino|generandose|la estoy (?:generando|haciendo|armando|creando|preparando|disenando)|estoy (?:generando|haciendo|armando|creando|preparando|disenando)|(?:ya )?la pedi|ya esta pedida|quedo pedida|esta pedida|va a aparecer|aparece (?:sola|aca|en este|en unos)|en unos minutos|en un rato|en breve|ya esta lista|quedo lista|esta lista)\b/u',
        '/\b(?:puse en marcha|mande a generar|mande a hacer|encargue|estoy (?:generando|haciendo|armando|creando|preparando|disenando)|te (?:estoy|la estoy) (?:generando|haciendo|armando|creando|preparando|disenando)|te (?:genere|hice|arme|cree|disene|prepare)|ya (?:te )?(?:genere|hice|arme|cree|disene|prepare)|genere|disene)\b(?:[^.!?\n]){0,60}?\b' . self::QUE . '\b/u',
    ];

    /** Lo que, en la misma oración, dice que NO se pidió: «no pude pedir la imagen», «todavía no la pedí». */
    const NEGACION = '/\b(?:no pude|no puedo|no se pudo|no la pedi|no la puse|no esta disponible|no hay forma|no logre|todavia no|aun no|no se genero|no se esta)\b/u';

    /**
     * El imperativo con tilde de voseo («hacé», «generá», «creá», «diseñá», «armá», «prepará»,
     * «dibujá»), que se marca ANTES de sacar las tildes: sin tilde, «genera» o «crea» también son la
     * tercera persona («el sistema genera una imagen?») y no un pedido. Y «hace» sin tilde es casi siempre
     * «hace un rato».
     */
    const IMPERATIVO_CON_TILDE = '/(?<!\p{L})(?:hacé|generá|creá|diseñá|armá|prepará|dibujá)(?!\p{L})/u';

    /** La marca con que se reemplaza el imperativo con tilde (ver IMPERATIVO_CON_TILDE). */
    const MARCA_DE_IMPERATIVO = 'pedidoimperativo';

    /**
     * El pedido de la persona es GENERAR una imagen: un verbo de pedido y, cerca, lo que se genera. Solo
     * formas que no se confunden: el imperativo con pronombre («haceme», «generame», «armame»), el de
     * voseo con tilde (marcado), la pregunta («¿me hacés…?», «¿podés generar…?») y «quiero / necesito una
     * imagen…». «Quiero ver la imagen que te mandé» o «hace un rato te mandé una foto» no lo son.
     */
    const PATRONES_DEL_PEDIDO = [
        '/\b(?:haceme|hacenos|hacele|hacelo|generame|generanos|generale|creame|creanos|disename|disenanos|armame|armanos|armale|preparame|preparanos|dibujame|' . self::MARCA_DE_IMPERATIVO . ')\b(?:[^.!?\n]){0,50}?\b' . self::QUE . '\b/u',
        '/\b(?:me (?:haces|hace|generas|genera|creas|crea|disenas|disena|armas|arma|preparas|prepara|dibujas)|(?:podes|podrias|podra|puede|podran) (?:hacer|generar|crear|disenar|armar|preparar|dibujar)(?:me|nos)?|se puede (?:hacer|generar|crear|armar))\b(?:[^.!?\n]){0,50}?\b' . self::QUE . '\b/u',
        '/\b(?:quiero|necesito|queria|necesitaria|quisiera)\s+(?:que\s+(?:me\s+)?(?:hagas|generes|crees|disenes|armes|prepares|dibujes)\s+)?(?:una|un|unas|unos|otra|otro)\s+(?:\S+\s+){0,2}?' . self::QUE . '\b/u',
    ];

    /**
     * true si el texto da por pedida (o hecha) una imagen nueva. Una oración que lo niega o que habla de
     * las fotos del catálogo no cuenta.
     *
     * @param  string  $texto
     * @return bool
     */
    public static function afirma_una_imagen($texto): bool
    {
        foreach (self::oraciones(self::normalizar($texto)) as $oracion) {

            if (preg_match(self::NEGACION, $oracion) || preg_match(self::DEL_CATALOGO, $oracion)) {
                continue;
            }

            foreach (self::PATRONES_DEL_TEXTO as $patron) {

                if (preg_match($patron, $oracion)) {

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * true si la persona pidió claramente GENERAR una imagen («haceme una imagen para Instagram…»). No
     * cuenta si habla de las imágenes del catálogo («la foto del artículo», «las categorías sin imagen»).
     *
     * @param  string  $pedido
     * @return bool
     */
    public static function pide_generar_una_imagen($pedido): bool
    {
        $marcado = preg_replace(self::IMPERATIVO_CON_TILDE, ' ' . self::MARCA_DE_IMPERATIVO . ' ', mb_strtolower((string) $pedido, 'UTF-8'));

        foreach (self::oraciones(self::normalizar((string) $marcado)) as $oracion) {

            if (preg_match(self::DEL_CATALOGO, $oracion)) {
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
     * true si el turno llamó a una herramienta de imágenes del catálogo (ver HERRAMIENTAS_DEL_CATALOGO).
     *
     * @param  array<int, string>  $herramientas_pedidas
     * @return bool
     */
    public static function hablo_del_catalogo(array $herramientas_pedidas): bool
    {
        return count(array_intersect($herramientas_pedidas, self::HERRAMIENTAS_DEL_CATALOGO)) > 0;
    }

    /**
     * La nota del reintento: el texto (o el pedido) es de una imagen y en este turno no se llamó a
     * pedir_imagen. Arranca con "[El sistema", como las otras notas: si el modelo la repitiera,
     * TextoFinalIaHelper saca ese renglón.
     *
     * @return string
     */
    public static function nota_para_reintentar(): string
    {
        return '[El sistema revisó tu respuesta: la persona pidió que se genere una imagen (o tu respuesta dice que la pediste), '
            . 'pero en este turno NO llamaste a pedir_imagen, así que NO se pidió ninguna imagen. '
            . 'Llamá AHORA a pedir_imagen con un prompt detallado y el tamaño; si te falta un dato imprescindible, preguntalo en vez de pedirla. '
            . 'Después decí en una línea que la pediste y que aparece acá cuando esté lista, sin prometer tiempos. '
            . 'Pedir una imagen no autoriza a preparar promociones, envíos ni mensajes a clientes: no los armes si la persona no los pidió. '
            . 'No le menciones este aviso a la persona.]';
    }

    /**
     * Las oraciones de un texto (por punto, signo o renglón).
     *
     * @param  string  $texto
     * @return array<int, string>
     */
    protected static function oraciones($texto): array
    {
        $partes = preg_split('/(?<=[.!?])\s+|\n+/u', (string) $texto);

        return array_values(array_filter(array_map('trim', is_array($partes) ? $partes : []), function ($parte) {
            return $parte !== '';
        }));
    }

    /**
     * Minúscula y sin tildes (como TarjetaPrometidaIaHelper::normalizar()), con la ñ como n: «diseñá» y
     * «disena» son lo mismo.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function normalizar($texto): string
    {
        $minuscula = mb_strtolower(trim((string) $texto), 'UTF-8');

        return strtr($minuscula, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
    }
}
