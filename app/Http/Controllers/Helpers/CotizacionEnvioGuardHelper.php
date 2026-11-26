<?php

namespace App\Http\Controllers\Helpers;

/**
 * Filtro de salida del agente de WhatsApp (misión truvari-envio-coherente v2): saca del mensaje toda oración que
 * cotice un envío o un total, y deja una frase fija que avisa que lo confirma el equipo.
 *
 * 🔴 POR QUÉ EXISTE: el agente es UNA llamada al modelo, sin herramientas ni carrito. Las cantidades, la localidad y
 * las tarifas sólo viven en texto libre, así que cuando el modelo «calcula» un envío o un total lo hace de
 * cabeza y puede contradecirse en el mismo mensaje (caso real: dos totales y una comparación falsa contra un
 * umbral). Mientras no haya un dato estructurado ni reglas comerciales confirmadas por el dueño, lo único que
 * garantiza que el cliente no reciba un monto inventado es no informar ninguno.
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

    /** Palabras de envío. `\b` y /u: «envío» con y sin tilde, plural, y los recargos que viajan con él. */
    const PATRON_ENVIO = '/\b(?:env[ií]os?|fletes?|peajes?|recargos?|despachos?)\b/iu';

    /** Afirmaciones de gratuidad. */
    const PATRON_GRATUIDAD = '/\b(?:gratis|gratuit[oa]s?|sin\s+(?:cargo|costo)|bonificad[oa]s?)\b/iu';

    /** La palabra «total» (también «totales»). */
    const PATRON_TOTAL = '/\btotal(?:es)?\b/iu';

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

    /** Corte en oraciones: salto de línea (se conserva) o espacio después de un cierre de oración. */
    const PATRON_CORTE = '/(\n+|(?<=[.!?…])[ \t]+)/u';

    /**
     * Revisa el cuerpo que está por salir. Sin ninguna oración de cotización devuelve el texto tal cual, byte por
     * byte, con `derivo = false`.
     *
     * @param  string  $cuerpo  Texto ya sin marcadores internos.
     * @return array{body: string, derivo: bool, frases: int}
     *         `frases` es la cantidad de oraciones sacadas.
     */
    public static function revisar(string $cuerpo): array
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

        $conservadas = [];
        $frases      = 0;

        foreach ($unidades as $unidad) {
            list($texto, $separador) = $unidad;

            if (trim($texto) === '' || ! self::es_cotizacion($texto)) {
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

        return [
            'body'   => $resto === '' ? self::FRASE_FIJA : $resto . "\n\n" . self::FRASE_FIJA,
            'derivo' => true,
            'frases' => $frases,
        ];
    }

    /**
     * ¿La oración cotiza un envío o un total? Cualquiera de estas condiciones alcanza:
     *  (a) habla de envío (o peaje, flete…) y trae un importe o afirma gratuidad (cubre «envío gratis» sin importe);
     *  (b) dice «total» y trae un importe;
     *  (c) compara contra un umbral de compra y trae un importe («como supera los $…»).
     *
     * @param  string  $oracion
     * @return bool
     */
    private static function es_cotizacion(string $oracion): bool
    {
        $importe = (bool) preg_match(self::PATRON_IMPORTE, $oracion);
        $numero  = $importe || (bool) preg_match(self::PATRON_NUMERO_PELADO, $oracion);

        if (preg_match(self::PATRON_ENVIO, $oracion)
            && ($numero || preg_match(self::PATRON_GRATUIDAD, $oracion))) {
            return true;
        }

        if ($numero && preg_match(self::PATRON_TOTAL, $oracion)) {
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
}
