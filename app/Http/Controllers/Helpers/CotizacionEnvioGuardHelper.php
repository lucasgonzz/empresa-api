<?php

namespace App\Http\Controllers\Helpers;

/**
 * Filtro de salida del agente de WhatsApp (misión truvari-envio-coherente v2): saca del mensaje toda oración que
 * cotice un envío o un total, y deja una frase fija que avisa que lo confirma el equipo.
 *
 * 🔴 POR QUÉ EXISTE: el agente es UNA llamada al modelo, sin herramientas ni carrito. Las cantidades, la localidad y
 * las tarifas sólo viven en texto libre, así que cuando el modelo «calcula» un envío o un total lo hace de
 * cabeza y puede contradecirse en el mismo mensaje (caso real: dos totales y una comparación falsa contra un
 * umbral). Mientras no haya un dato estructurado ni reglas comerciales confirmadas por el dueño, lo que se puede
 * hacer es reducir el riesgo: sacar del texto las oraciones que cotizan un envío o un total.
 *
 * 🔴 NO ES UNA GARANTÍA ABSOLUTA. Es un filtro por patrones sobre texto libre: lo que no reconoce, pasa. Ya dejó
 * pasar formas que nadie había previsto (el total del pedido sin la palabra «total», «delivery», «traslado»,
 * «no tiene costo», una viñeta con el importe solo). Quedan sin cubrir, por ejemplo, un importe escrito en
 * palabras sin «mil» («tres quinientos»), una gratuidad dicha con otras palabras y un total sin ninguna de las
 * palabras de arriba. Cada hueco que aparezca se cierra agregando su patrón y su test; la capa de prompt y la
 * revisión humana del chat (consulta para el equipo) son parte de la defensa, no un adorno.
 *
 * Límites conocidos de la segmentación (2º rechazo del QA): la lista de abreviaturas que no cortan la oración es
 * cerrada («Av.», «Gral.», «Sta.», «aprox.»…); una abreviatura que no esté, seguida de mayúscula, vuelve a cortar
 * y el importe puede quedar suelto. Un rótulo («Costo de envío:») sólo se une con la línea siguiente si el rótulo
 * mismo habla de envío o de total, (con «:» aunque lo envuelva negrita; la línea en blanco entre ambos no
 * impide la unión).
 *
 * Contexto de envío entre oraciones (3er rechazo del QA): la oración o línea inmediatamente anterior que habla de
 * envío o de total le da contexto a la siguiente SI ésta trae un importe y no tiene sujeto propio («Hacemos
 * envíos. Sale $X.», «Te sale $X», «El costo es $X», «Son $X», un importe solo). Un rótulo corto de costo sin punto
 * («Costo de envío») también une «Localidad: $X». Es local y conservador: sólo la vecina inmediata. Límites:
 * (1) un envío nombrado dos oraciones atrás («Hacemos envíos. Tenemos Fernet. Sale $X.») no llega; (2) una
 * oración con sujeto propio que en realidad es el envío («La entrega a Río Cuarto sale $X» sí, por la palabra
 * entrega; «Llevarlo sale $X», no) depende de la palabra de envío; (3) falsos positivos: «Hacemos envíos. Son
 * $X las 2 botellas.» o «Sale el Fernet $X» se derivan porque arrancan sin sujeto. Un valor en otra
 * forma sin rótulo ni palabra de envío en ninguna vecina no se puede reconocer.
 *
 * 🔴 NO SE «SIMPLIFICA» A UNA MARCA DEL MODELO (`[COTIZACION:…]` y similares): una garantía que depende de que
 * el modelo marque bien es la misma garantía que ya falló. Este filtro mira el TEXTO que sale, no lo que el
 * modelo dijo que estaba haciendo. Tampoco lleva cifras, localidades ni nombres de negocio: la política
 * comercial (umbrales, tarifas, peajes) es del dueño y se carga cuando la confirme; acá no se codifica ninguna.
 *
 * Es una clase pura: no lee la base ni el request, así que se prueba sin Laravel.
 *
 * Se prefiere pecar por amplio: un falso positivo cuesta una demora (lo contesta una persona), un falso
 * negativo cuesta un total equivocado en manos del cliente. Lo que NO se toca son los precios de catálogo ni los
 * subtotales de producto («las 2 botellas serían $…»).
 */
class CotizacionEnvioGuardHelper
{
    /** Frase impersonal a propósito: encaja con «usted» y con «vos». No es un texto de negocio. */
    const FRASE_FIJA = 'El costo de envío y el total final los confirma el equipo en breve.';

    /**
     * Palabras de envío. `\b` y /u: «envío» con y sin tilde, plural, los recargos que viajan con él y los sinónimos
     * de traslado de mercadería (domicilio, delivery, entrega, reparto…). Un sinónimo nuevo se suma acá.
     */
    const PATRON_ENVIO = '/\b(?:env[ií]os?|fletes?|peajes?|recargos?|despachos?|domicilio|delivery|traslados?|entregas?|repart(?:o|os|a|en)|acarreos?|transporte)\b/iu';

    /** Afirmaciones de gratuidad (con una palabra de envío en la oración, o la de «por nuestra cuenta», que sola alcanza). */
    const PATRON_GRATUIDAD = '/\b(?:gratis|gratuit[oa]s?|sin\s+(?:cargo|costo|recargo|adicional)|bonificad[oa]s?|libre\s+de\s+(?:costo|cargo)|no\s+(?:tiene|tendr[aá]|tendr[ií]a|conlleva|lleva|implica)\s+(?:ning[uú]n\s+)?(?:costo|cargo|recargo|adicional)|no\s+(?:le\s+|te\s+|les\s+|se\s+)?cobr(?:amos|a|an|ar[aá]|ar[ií]amos|e|o))\b/iu';

    /** «Por nuestra cuenta» y similares: afirman que lo paga el negocio. Alcanzan sin la palabra de envío. */
    const PATRON_A_CARGO_DEL_NEGOCIO = '/\b(?:por\s+(?:nuestra|mi)\s+cuenta|a\s+(?:nuestro|mi)\s+cargo|a\s+cargo\s+(?:nuestro|del\s+(?:local|negocio|comercio)))\b/iu';

    /** Palabras que nombran lo que se paga entero: el pedido, la compra, la cuenta. */
    const PATRON_PEDIDO = '/\b(?:pedidos?|compras?|cuentas?|[oó]rdenes|orden|presupuestos?|facturas?|todo\s+junto|con\s+todo)\b/iu';

    /** Verbos con los que se dice cuánto da el pedido («quedaría en», «sale», «suma», «asciende a»). */
    const PATRON_RESULTADO = '/\b(?:qued(?:a|an|ar[ií]a|ar[ií]an|ar[aá]|ar[aá]n)|sal(?:e|en|dr[ií]a|dr[ií]an|dr[aá]|dr[aá]n)|result(?:a|an|ar[ií]a|ar[aá])|sum(?:a|an|ar[ií]a)|asciende|ascender[ií]a|da|dan|ser[ií]a|ser[ií]an|ser[aá]|ser[aá]n|es\s+de|ser[ií]a\s+de)\b/iu';

    /** Verbos de pagar: «tendría que abonar $…» es un total aunque no diga «total». */
    const PATRON_PAGAR = '/\b(?:abon(?:ar|a|ás|as|aría|arías|ando)|pag(?:ar|a|ás|as|aría|arías|ando))\b/iu';

    /** Una línea que es sólo un importe con su formato de viñeta o negrita («- *$X*»): una cifra sin rótulo. */
    const PATRON_LINEA_SOLO_IMPORTE = '/^[\s\-*_•·▪◦>~`–—:]*(?:\$\s*)?\d[\d.,]*[\s*_`]*(?:pesos|ars)?[\s.*_`]*$/iu';

    /**
     * La palabra «total» (también «totales») y sus equivalentes: «precio final», «a pagar». Es amplio a propósito:
     * un «precio final» de un producto suelto también se deriva (un falso positivo cuesta una demora).
     */
    const PATRON_TOTAL = '/\b(?:total(?:es)?|final(?:es)?|a\s+pagar)\b/iu';

    /** Cuentas escritas a la vista: «$A + $B = $C». Un importe pegado a un `+` o a un `=`. */
    const PATRON_ARITMETICA = '/(?:\$\s*\d[\d.,]*\s*[+=]|[+=]\s*\$\s*\d)/u';

    /**
     * Un importe seguido de «más» como adicional («son $X más»). No toca «$X más IVA» ni «más barato»:
     * «más» sólo cuenta si cierra la oración o le sigue un importe, el peaje o el envío.
     */
    const PATRON_IMPORTE_ADICIONAL = '/(?:\$\s*\d[\d.,]*|\d\s*pesos)\s*(?:m[aá]s|adicionales?|extra)(?=\s*(?:[.,;!?)]|$)|\s+(?:\$|peaje|env))/iu';

    /**
     * Importe «fuerte»: no se confunde con una cantidad. `$` seguido de un número (con o sin punto de miles y
     * decimales), un número con puntos de miles, un número seguido de pesos/lucas/mil/k, o un número en palabras
     * seguido de «mil».
     */
    const PATRON_IMPORTE = '/(?:\$\s*\d|\bars\s*\d|\b\d{1,3}(?:\.\d{3})+(?:,\d+)?\b|\b\d+(?:[.,]\d+)?\s*(?:pesos|ars|lucas|mil)\b|\b\d+k\b|\bmil\s+pesos\b|\b(?:un|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce|quince|veinte|treinta|cuarenta|cincuenta|sesenta|setenta|ochenta|noventa|cien|ciento|doscientos|trescientos|quinientos)\s+mil\b)/iu';

    /**
     * Número pelado de 4 a 8 cifras («envío 1500»). Sólo cuenta como importe en una oración que ya habla de envío
     * o de un total: en otra parte puede ser una cantidad, un código o un año.
     */
    const PATRON_NUMERO_PELADO = '/\b\d{4,8}\b/u';

    /** Comparaciones contra un umbral que no pueden ser otra cosa que un umbral de compra. */
    const PATRON_UMBRAL_DURO = '/\b(?:super(?:a|an|ás|as|ar|ando)|alcanz(?:a|an|ás|as|ar)|lleg(?:a|á)s?\s+a)\b/iu';

    /**
     * Comparaciones que también aparecen en un precio de catálogo («taladros desde $…»). Sólo son umbral si la
     * oración habla de una compra.
     */
    const PATRON_UMBRAL_BLANDO = '/\b(?:m[aá]s\s+de|desde)\b/iu';

    /** Contexto de compra que vuelve umbral a una comparación «blanda». */
    const PATRON_CONTEXTO_COMPRA = '/\b(?:compr(?:a|as|ás|es|ando)|pedidos?|gast(?:a|as|ás|ando)|monto|importe)\b/iu';

    /**
     * Oración o línea que continúa el costo de la anterior sin nombrarlo (3º rechazo del QA): arranca con un verbo
     * de costo sin sujeto («Sale $X», «Te sale $X», «Cuesta $X», «Son $X», «Queda en $X»), con un sujeto genérico
     * («El costo es $X», «El valor…») o con el importe mismo. Un sujeto propio («El Fernet sale $X», «Cada botella
     * sale $X») NO entra: es un precio de catálogo.
     */
    const PATRON_CONTINUACION_DE_COSTO = '/^[\s*_\-•·▪◦>]*(?:(?:te\s+|le\s+|les\s+|se\s+|me\s+)?(?:sal(?:e|en|dr[ií]a|dr[aá])|cuest(?:a|an)|val(?:e|en|dr[ií]a)|qued(?:a|an|ar[ií]a|ar[aá])|son|es|ser[ií]a|ser[aá]|cobramos|cobra|cobran|asciende|suma)\b|(?:el|la)\s+(?:costo|valor|importe|tarifa|monto|precio)\s+(?:es|ser[ií]a|ser[aá]|son|sale|queda|est[aá])\b|(?:\$\s*)?\d)/iu';

    /** Un valor en forma «Localidad: $X» (una línea corta con dos puntos y el importe). Sólo bajo un rótulo de costo. */
    const PATRON_LINEA_ROTULADA = '/^[\s*_\-•·▪◦>]*[^.!?:$\n]{1,40}:[\s*_]*\$\s*\d/u';

    /** Palabras que vuelven «de costo» a un rótulo corto de envío: sólo con ellas se une una línea «Localidad: $X». */
    const PATRON_ROTULO_DE_COSTO = '/\b(?:costos?|precios?|tarifas?|valor(?:es)?|importes?|total(?:es)?|cu[aá]nto|a\s+pagar)\b/iu';

    /**
     * Corte en oraciones: salto de línea (se conserva) o espacio después de un cierre de oración. Un punto que
     * cierra una abreviatura («Av.», «Gral.», «Sta.», «aprox.», una inicial suelta como «J. M. Paz») NO corta:
     * si cortara, el importe quedaría en una oración sin la palabra de envío y pasaría el filtro. Cada
     * lookbehind es de largo fijo (PCRE lo exige). Una abreviatura nueva se suma acá. Si una oración termina en
     * una de estas palabras («…hasta las 18 hs. Luego…») las dos oraciones se evalúan juntas: es el lado seguro.
     */
    const PATRON_CORTE = '/(\n+|(?<=[.!?…])(?<!\b\p{L}\.)(?<!\bav\.)(?<!\bavda\.)(?<!\bgral\.)(?<!\bsta\.)(?<!\bsto\.)(?<!\bcnel\.)(?<!\btte\.)(?<!\bpte\.)(?<!\bpres\.)(?<!\bdr\.)(?<!\bdra\.)(?<!\bing\.)(?<!\blic\.)(?<!\bprof\.)(?<!\bsr\.)(?<!\bsra\.)(?<!\bsrta\.)(?<!\bbv\.)(?<!\bblvd\.)(?<!\bpje\.)(?<!\bctra\.)(?<!\bcno\.)(?<!\bdto\.)(?<!\bmza\.)(?<!\burb\.)(?<!\bkm\.)(?<!\bdpto\.)(?<!\bdepto\.)(?<!\besq\.)(?<!\btel\.)(?<!\bnro\.)(?<!\bn°\.)(?<!\baprox\.)(?<!\bapr\.)(?<!\bhs\.)(?<!\bmáx\.)(?<!\bmín\.)(?<!\bmax\.)(?<!\bmin\.)(?<!\bc\/u\.)(?<!\bud\.)(?<!\buds\.)(?<!\bvs\.)(?<!\bcp\.)[ \t]+)/iu';

    /**
     * Los importes con su cifra (con signo $, con puntos de miles, «N pesos», «N mil», «ARS N»): el grupo captura
     * el número. Sirve para comparar lo que dijo el modelo con lo que dijo el comercio (misión
     * asistente-diario-20261128, P8b); no reemplaza a PATRON_IMPORTE, que es el que decide si hay cotización.
     */
    const PATRON_CIFRA_DEL_IMPORTE = '/(?:\$\s*|\bars\s*)(\d[\d.,]*)|\b(\d{1,3}(?:\.\d{3})+(?:,\d+)?)\b|\b(\d+(?:[.,]\d+)?)\s*(?:pesos|ars|lucas|mil)\b/iu';

    /**
     * Revisa el cuerpo que está por salir. Sin ninguna oración de cotización devuelve el texto tal cual, byte por
     * byte, con `derivo = false`.
     *
     * Misión asistente-diario-20261128 (P8): lo que el COMERCIO ya dijo en el chat (una persona del negocio, no la IA)
     * es información del negocio y no una cotización inventada por el modelo. Una oración que el filtro sacaría se
     * CONSERVA si repite una gratuidad que dijo el comercio o si todos sus importes son los que el comercio dijo
     * para el envío; un importe distinto sigue derivándose. Y la frase fija no se suma si el texto que quedó ya dice
     * que el equipo confirma el envío y el total (si no, se leía dos veces).
     *
     * @param  string  $cuerpo  Texto ya sin marcadores internos.
     * @param  array<int, string>  $dichos_del_negocio  Textos de los mensajes que escribió una persona del negocio en
     *                                                  este chat (vacío = el filtro de siempre).
     * @return array{body: string, derivo: bool, frases: int}
     *         `frases` es la cantidad de oraciones sacadas.
     */
    public static function revisar(string $cuerpo, array $dichos_del_negocio = []): array
    {
        $sin_cambios = ['body' => $cuerpo, 'derivo' => false, 'frases' => 0];

        if (trim($cuerpo) === '') {
            return $sin_cambios;
        }

        $partes = preg_split(self::PATRON_CORTE, $cuerpo, -1, PREG_SPLIT_DELIM_CAPTURE);

        // Un texto que no es UTF-8 válido no se puede analizar. La respuesta del modelo llega decodificada de
        // JSON, así que no pasa; si pasara, se deja como estaba (igual que `split_markers`).
        if ($partes === false) {
            return $sin_cambios;
        }

        // Unidades [texto, separador que le sigue]: el separador es el espacio o el salto de línea posterior.
        $unidades = [];
        for ($i = 0, $total = count($partes); $i < $total; $i += 2) {
            $unidades[] = [$partes[$i], isset($partes[$i + 1]) ? $partes[$i + 1] : ''];
        }

        $unidades = self::unir_rotulos($unidades);

        $conservadas = [];
        $frases      = 0;

        $previo = '';

        foreach ($unidades as $unidad) {
            list($texto, $separador) = $unidad;

            // `$previo` es la unidad anterior TAL COMO LLEGÓ (aunque se haya sacado): el contexto de envío vale
            // para la vecina inmediata, no más lejos.
            $anterior = $previo;
            $previo   = $texto;

            if (trim($texto) === '' || ! self::es_cotizacion($texto, $anterior) || self::lo_dijo_el_comercio($texto, $dichos_del_negocio)) {
                $conservadas[] = [$texto, $separador];
                continue;
            }

            // La oración sacada se lleva su separador. Si era un salto de línea y lo anterior seguía en la misma
            // línea (o en un salto más corto), el salto pasa a la oración que quedó antes: así no se pegan dos
            // líneas ni se pierde un corte de párrafo.
            $frases++;
            if (strpos($separador, "\n") !== false && ! empty($conservadas)) {
                $ultima = count($conservadas) - 1;
                if (substr_count($separador, "\n") > substr_count($conservadas[$ultima][1], "\n")) {
                    $conservadas[$ultima][1] = $separador;
                }
            }
        }

        if ($frases === 0) {
            return $sin_cambios;
        }

        $resto = '';
        foreach ($conservadas as $unidad) {
            $resto .= $unidad[0] . $unidad[1];
        }

        // Sólo en el camino que modificó el texto se limpian los restos de la costura.
        $resto = preg_replace('/[ \t]+\n/', "\n", $resto);
        $resto = trim((string) preg_replace('/\n{3,}/', "\n\n", (string) $resto));

        // La frase fija sólo se suma si lo que quedó no dice ya lo mismo (P8a): si no, el cliente la leía dos veces.
        $ya_lo_dice = $resto !== '' && self::ya_dice_que_lo_confirma_el_equipo($conservadas);

        return [
            'body'   => $resto === '' ? self::FRASE_FIJA : ($ya_lo_dice ? $resto : $resto . "\n\n" . self::FRASE_FIJA),
            'derivo' => true,
            'frases' => $frases,
        ];
    }

    /**
     * ¿Alguna oración que quedó ya dice que el equipo confirma el envío o el total? Una forma de «confirmar», «el
     * equipo» y una palabra de envío o de total en la MISMA oración («el costo de envío y el total final los
     * confirma el equipo»). Con eso la frase fija sobra.
     *
     * @param  array<int, array{0: string, 1: string}>  $unidades  [texto, separador] de lo que quedó.
     * @return bool
     */
    private static function ya_dice_que_lo_confirma_el_equipo(array $unidades): bool
    {
        foreach ($unidades as $unidad) {

            $texto = $unidad[0];

            if (preg_match('/\bconfirm\w*/iu', $texto)
                && preg_match('/\bequipo\b/iu', $texto)
                && (preg_match(self::PATRON_ENVIO, $texto) || preg_match(self::PATRON_TOTAL, $texto))) {

                return true;
            }
        }

        return false;
    }

    /**
     * Palabras que vuelven un importe un UMBRAL o un total y no el costo del envío («mínimo de compra de $X», «si no
     * supera los $X», «a partir de $X»). Se miran en el tramo de texto que precede a cada importe.
     */
    const PATRON_UMBRAL_DEL_IMPORTE = '/\b(?:m[ií]nimo|m[ií]n|supera\w*|superior\w*|a\s+partir|desde|m[aá]s\s+de|mayor\w*|menor\w*|menos\s+de|hasta|alcanz\w*|lleg\w*|total|compras?|pedidos?|[oó]rdenes|orden)\b/iu';

    /**
     * Condiciones, umbrales o excepciones que una oración del comercio puede traer («si…», «salvo…», «mínimo de
     * compra», «supera», «desde», «hasta», «más de», «por pedido»). Una oración del comercio con alguna de ellas NO
     * habilita a rescatar una afirmación del modelo: el modelo la repetiría sin la condición.
     */
    const PATRON_CONDICION_DE_GRATUIDAD = '/\b(?:si|siempre\s+que|cuando|salvo|excepto|menos|pero|aunque|solo|s[oó]lo|solamente|m[ií]nimo|a\s+partir|desde|hasta|m[aá]s\s+de|menos\s+de|mayor\w*|menor\w*|superior\w*|supera\w*|alcanz\w*|lleg\w*|compras?|pedidos?)\b/iu';

    /** Referencia genérica a la zona del propio cliente («para su zona», «en tu localidad»): no nombra ningún lugar. */
    const PATRON_ZONA_GENERICA = '/\b(?:su|tu|sus)\s+(?:zona|localidad|domicilio|barrio|ciudad|direcci[oó]n)\b/iu';

    /** Palabras que cortan un nombre de lugar y las que, al empezarlo, indican que no es un lugar («tu pedido»). */
    const PALABRAS_QUE_CORTAN_UN_LUGAR = ['el', 'la', 'los', 'las', 'lo', 'un', 'una', 'y', 'e', 'o', 'u', 'no', 'sin', 'con', 'por', 'que', 'se', 'es', 'son', 'sale', 'salen', 'queda', 'quedan', 'cuesta', 'cuestan', 'vale', 'valen', 'tiene', 'tienen', 'esta', 'estan', 'cobra', 'cobramos', 'cobran', 'del', 'de', 'al', 'a', 'en', 'para', 'hasta', 'hacia', 'desde', 'como', 'si', 'pero', 'asi', 'gratis', 'gratuito', 'costo', 'precio', 'envio', 'envios', 'total', 'sera', 'seria', 'saldria', 'va', 'van', 'tu', 'su', 'mi', 'tus', 'sus', 'mis', 'este', 'esta', 'ese', 'esa', 'esto', 'eso', 'todo', 'toda', 'cada', 'ahi', 'aca', 'aqui', 'breve', 'ahora', 'hoy'];

    /**
     * ¿La oración que el filtro sacaría repite lo que ya dijo una persona del negocio en este chat?
     *
     * Criterio (decisión del Coordinador, rondas 2 y 3): ante la duda, se deriva. Sólo se rescata si:
     *  (0) es la misma oración del comercio, tal cual (salvo mayúsculas, espacios y puntuación final); o
     *  (a) afirma gratuidad SIN ninguna cifra, y hay una oración del comercio que habla de envío, afirma gratuidad
     *      sin condición ni cifra y contiene los lugares que nombra el modelo (o habla de «su zona»); o
     *  (b) trae importes y NO es una gratuidad: todos sus importes se tratan como costo de envío (en la oración del
     *      modelo no se clasifica el papel) y cada cifra aparece como costo de envío en UNA oración del comercio que
     *      habla de envío, SIN condición, y que contiene los lugares que nombra el modelo (o habla de «su zona»).
     * Un total no se rescata con un importe de envío.
     *
     * @param  string  $oracion  La oración del modelo.
     * @param  array<int, string>  $dichos_del_negocio  Mensajes que escribió una persona del negocio.
     * @return bool
     */
    private static function lo_dijo_el_comercio(string $oracion, array $dichos_del_negocio): bool
    {
        if (! count($dichos_del_negocio)) {
            return false;
        }

        // En la oración del MODELO no se clasifica el papel: todo importe suyo se trata como costo de envío.
        $importes = self::importes_con_papel($oracion, false);

        // Si quedó algún importe que no se pudo leer como cifra («siete mil»), no se puede comparar: se deriva.
        $sin_cifras = preg_replace(self::PATRON_CIFRA_DEL_IMPORTE, ' ', $oracion);

        if (preg_match(self::PATRON_IMPORTE, (string) $sin_cifras)) {
            return false;
        }

        $es_gratuidad = ! count($importes) && preg_match(self::PATRON_GRATUIDAD, $oracion);
        $es_importe   = count($importes) && ! preg_match(self::PATRON_GRATUIDAD, $oracion)
            && preg_match(self::PATRON_ENVIO, $oracion) && ! preg_match(self::PATRON_TOTAL, $oracion);

        $zonas    = self::zonas_nombradas($oracion);
        $textual  = self::normalizar_oracion($oracion);

        foreach ($dichos_del_negocio as $dicho) {

            $frases = preg_split(self::PATRON_CORTE, (string) $dicho, -1, PREG_SPLIT_NO_EMPTY);

            if ($frases === false) {
                continue;
            }

            foreach ($frases as $frase) {

                // (0) La misma oración que escribió el comercio.
                if ($textual !== '' && $textual === self::normalizar_oracion($frase)) {
                    return true;
                }

                if (! ($es_gratuidad || $es_importe) || ! preg_match(self::PATRON_ENVIO, $frase)
                    || ! self::zonas_en_la_frase($zonas, $frase)
                    || preg_match(self::PATRON_CONDICION_DE_GRATUIDAD, $frase)) {
                    continue;
                }

                // (a) Gratuidad sin condición ni cifra en la oración del comercio.
                if ($es_gratuidad && preg_match(self::PATRON_GRATUIDAD, $frase) && ! count(self::importes_con_papel($frase))) {
                    return true;
                }

                // (b) Cada cifra del modelo, como costo, en esta misma oración del comercio.
                if ($es_importe && self::importes_incluidos($importes, self::importes_con_papel($frase))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * La oración en minúsculas, sin acentos, con los espacios colapsados y sin signos al principio ni al final: sirve
     * para saber si dos oraciones son la misma.
     *
     * @param  string  $texto
     * @return string
     */
    private static function normalizar_oracion(string $texto): string
    {
        $texto = self::sin_acentos(mb_strtolower($texto));
        $texto = (string) preg_replace('/\s+/u', ' ', $texto);

        return trim($texto, " \t\n\r.!?…¡¿*_-•·");
    }

    /**
     * El texto sin tildes ni diéresis (la ñ se conserva), para comparar lugares escritos con o sin acento.
     *
     * @param  string  $texto
     * @return string
     */
    private static function sin_acentos(string $texto): string
    {
        return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
    }

    /**
     * Los importes de una oración con su papel: «costo» (lo que cuesta el envío) o «umbral» (mínimo de compra, tope,
     * total). El papel sale del tramo de texto entre el importe anterior (o el inicio) y éste.
     *
     * @param  string  $texto
     * @param  bool  $clasificar  false = todo importe es «costo» (así se tratan las oraciones del modelo: una palabra
     *                         como «pedido» o «hasta» no lo vuelve umbral); true = se distingue (oraciones del comercio).
     * @return array<int, array{cifra: string, papel: string}>
     */
    private static function importes_con_papel(string $texto, bool $clasificar = true): array
    {
        $resultado = [];

        if (! preg_match_all(self::PATRON_CIFRA_DEL_IMPORTE, $texto, $encontrados, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $resultado;
        }

        // Dónde terminó el importe anterior: el tramo que precede al siguiente empieza ahí.
        $desde = 0;

        foreach ($encontrados as $encontrado) {

            $inicio = $encontrado[0][1];
            $tramo  = substr($texto, $desde, $inicio - $desde);

            $numero = '';

            for ($i = 1; $i <= 3; $i++) {
                if (isset($encontrado[$i]) && $encontrado[$i][0] !== '') {
                    $numero = $encontrado[$i][0];
                }
            }

            $cifra = preg_replace('/\D/', '', $numero);

            if ($cifra !== '') {
                $resultado[] = ['cifra' => $cifra, 'papel' => ($clasificar && preg_match(self::PATRON_UMBRAL_DEL_IMPORTE, $tramo)) ? 'umbral' : 'costo'];
            }

            $desde = $inicio + strlen($encontrado[0][0]);
        }

        return $resultado;
    }

    /**
     * ¿Todos los importes (cifra y papel) del modelo están entre los de la oración del comercio?
     *
     * @param  array<int, array{cifra: string, papel: string}>  $del_modelo
     * @param  array<int, array{cifra: string, papel: string}>  $del_comercio
     * @return bool
     */
    private static function importes_incluidos(array $del_modelo, array $del_comercio): bool
    {
        foreach ($del_modelo as $importe) {

            $esta = false;

            foreach ($del_comercio as $otro) {
                if ($otro['cifra'] === $importe['cifra'] && $otro['papel'] === $importe['papel']) {
                    $esta = true;
                    break;
                }
            }

            if (! $esta) {
                return false;
            }
        }

        return true;
    }

    /**
     * Los lugares que nombra la oración del modelo, sin importar mayúsculas ni acentos: lo que sigue a «a», «al»,
     * «en», «para», «hacia», «hasta», «de» o «del» («la calera», «el interior», «otra provincia»), sin el artículo.
     * Lo que no es un lugar («tu pedido») se ignora; lo que no es un lugar pero no se puede distinguir se incluye, y
     * entonces la oración se deriva (ante la duda, derivar).
     *
     * @param  string  $oracion
     * @return array<int, string>  En minúsculas y sin acentos.
     */
    private static function zonas_nombradas(string $oracion): array
    {
        $zonas = [];

        $texto = self::sin_acentos(mb_strtolower($oracion));

        if (! preg_match_all('/\b(?:a|al|en|para|hacia|hasta|del|de)\s+(\p{L}+(?:\s+\p{L}+){0,3})/u', $texto, $encontradas)) {
            return $zonas;
        }

        foreach ($encontradas[1] as $candidato) {

            $palabras = preg_split('/\s+/', $candidato, -1, PREG_SPLIT_NO_EMPTY);

            // Un artículo al principio no cuenta: «a la calera» es «calera».
            if (count($palabras) && in_array($palabras[0], ['la', 'el', 'los', 'las'], true)) {
                array_shift($palabras);
            }

            $lugar = [];

            foreach ($palabras as $palabra) {

                if (in_array($palabra, self::PALABRAS_QUE_CORTAN_UN_LUGAR, true)) {
                    break;
                }

                $lugar[] = $palabra;
            }

            if (count($lugar)) {
                $zonas[] = implode(' ', $lugar);
            }
        }

        return $zonas;
    }

    /**
     * ¿Cada lugar que nombró el modelo está en la oración del comercio? Si la oración del comercio habla de «su
     * zona» (sin nombrar lugares), vale para cualquier zona del cliente.
     *
     * @param  array<int, string>  $zonas  Ya normalizadas (zonas_nombradas()).
     * @param  string  $frase
     * @return bool
     */
    private static function zonas_en_la_frase(array $zonas, string $frase): bool
    {
        if (! count($zonas) || preg_match(self::PATRON_ZONA_GENERICA, $frase)) {
            return true;
        }

        $normalizada = self::sin_acentos(mb_strtolower($frase));

        foreach ($zonas as $zona) {
            if (mb_strpos($normalizada, $zona) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Une un rótulo que termina en «:» con la línea que le sigue («Costo de envío:» + «$X (localidad)»): por
     * separado, el valor no tiene la palabra de envío y pasaría. El rótulo tiene que hablar de envío o de total, y
     * entonces también absorbe las viñetas que le siguen («Opciones de envío:» + «- Domicilio: $X» + «- Express: $Y»).
     *
     * @param  array<int, array{0: string, 1: string}>  $unidades  [texto, separador que le sigue]
     * @return array<int, array{0: string, 1: string}>
     */
    private static function unir_rotulos(array $unidades): array
    {
        $unidos = [];

        for ($i = 0, $total = count($unidades); $i < $total; $i++) {
            list($texto, $separador) = $unidades[$i];

            // Sólo se une un rótulo que habla de envío o de total: «Le detallo:» no arrastra a su vecino y la
            // viñeta de abajo se evalúa sola, como siempre.
            $habla_de_cotizacion = preg_match(self::PATRON_ENVIO, $texto) || preg_match(self::PATRON_TOTAL, $texto);

            if (! $habla_de_cotizacion || ! preg_match('/:[\s*_]*$/u', $texto) || strpos($separador, "\n") === false || $i + 1 >= $total) {
                $unidos[] = [$texto, $separador];
                continue;
            }

            do {
                $i++;
                $texto .= $separador . $unidades[$i][0];
                $separador = $unidades[$i][1];
                $sigue_vineta = $habla_de_cotizacion
                    && $i + 1 < $total
                    && strpos($separador, "\n") !== false
                    && preg_match('/^\s*[-*•·▪◦]/u', $unidades[$i + 1][0]);
            } while ($sigue_vineta);

            $unidos[] = [$texto, $separador];
        }

        return $unidos;
    }

    /**
     * ¿La oración cotiza un envío o un total? Cualquiera de estas condiciones alcanza:
     *  (a) habla de envío (o peaje, flete…) y trae un importe o afirma gratuidad (cubre «envío gratis» sin importe);
     *  (b) dice «total» y trae un importe;
     *  (c) compara contra un umbral de compra y trae un importe («como supera los $…»).
     *
     *  (d) contexto de la vecina de arriba (3º rechazo del QA): si la oración o línea anterior habla de envío o de
     *      total y ésta trae un importe y continúa su costo sin sujeto propio («Hacemos envíos. Sale $X.»), se
     *      cuenta como cotización. Falso positivo esperable: «Hacemos envíos. Son $X las 2 botellas.»
     *
     * @param  string  $oracion
     * @param  string  $anterior  Oración o línea inmediatamente anterior, tal como llegó ('' si no hay).
     * @return bool
     */
    private static function es_cotizacion(string $oracion, string $anterior = ''): bool
    {
        $importe = (bool) preg_match(self::PATRON_IMPORTE, $oracion);
        $numero  = $importe || (bool) preg_match(self::PATRON_NUMERO_PELADO, $oracion);

        if ($importe && self::continua_costo_de_la_anterior($oracion, $anterior)) {
            return true;
        }

        if (preg_match(self::PATRON_ENVIO, $oracion)
            && ($numero || preg_match(self::PATRON_GRATUIDAD, $oracion))) {
            return true;
        }

        if ($numero && preg_match(self::PATRON_TOTAL, $oracion)) {
            return true;
        }

        // «Lo paga el negocio» no necesita la palabra de envío: «Eso corre por nuestra cuenta» ya es una promesa.
        if (preg_match(self::PATRON_A_CARGO_DEL_NEGOCIO, $oracion)) {
            return true;
        }

        // Cuentas a la vista y «$X más» como adicional: no hace falta ninguna palabra de envío.
        if (preg_match(self::PATRON_ARITMETICA, $oracion) || preg_match(self::PATRON_IMPORTE_ADICIONAL, $oracion)) {
            return true;
        }

        // Una línea que es sólo un importe («- *$X*») es una cifra sin rótulo: no se puede saber qué es.
        if ($importe && preg_match(self::PATRON_LINEA_SOLO_IMPORTE, trim($oracion))) {
            return true;
        }

        // Total del pedido sin la palabra «total»: «el pedido quedaría en $X», «la compra suma $X».
        // El subtotal de un producto («las 2 botellas serían $34.000») no nombra el pedido y no entra.
        if ($importe && preg_match(self::PATRON_PEDIDO, $oracion) && preg_match(self::PATRON_RESULTADO, $oracion)) {
            return true;
        }

        if ($importe && preg_match(self::PATRON_PAGAR, $oracion)) {
            return true;
        }

        if ($importe) {
            if (preg_match(self::PATRON_UMBRAL_DURO, $oracion)) {
                return true;
            }

            // «desde $…» y «más de $…» también son precios de catálogo: sólo cuentan si se habla de una compra.
            if (preg_match(self::PATRON_UMBRAL_BLANDO, $oracion) && preg_match(self::PATRON_CONTEXTO_COMPRA, $oracion)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿La oración continúa el costo de envío o de total que nombró la anterior? Contexto LOCAL: sólo la vecina
     * inmediata, y sólo si la actual no trae sujeto propio. Un precio de catálogo con sujeto («El Fernet sale
     * $X») queda afuera. Con un rótulo corto sin punto final que habla de costo («Costo de envío») también se
     * une una línea «Localidad: $X». No cruza dos oraciones: «Hacemos envíos. Tenemos Fernet. Sale $X.» pasa.
     *
     * @param  string  $oracion
     * @param  string  $anterior
     * @return bool
     */
    private static function continua_costo_de_la_anterior(string $oracion, string $anterior): bool
    {
        $anterior = trim($anterior);

        if ($anterior === '' || ! (preg_match(self::PATRON_ENVIO, $anterior) || preg_match(self::PATRON_TOTAL, $anterior))) {
            return false;
        }

        if (preg_match(self::PATRON_CONTINUACION_DE_COSTO, ltrim($oracion))) {
            return true;
        }

        $sin_formato = trim($anterior, " \t*_");
        $es_rotulo   = mb_strlen($sin_formato) <= 60
            && ! preg_match('/[.!?]$/u', $sin_formato)
            && preg_match(self::PATRON_ROTULO_DE_COSTO, $sin_formato);

        return $es_rotulo && preg_match(self::PATRON_LINEA_ROTULADA, $oracion);
    }
}
