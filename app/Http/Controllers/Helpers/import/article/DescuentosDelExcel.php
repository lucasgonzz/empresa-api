<?php

namespace App\Http\Controllers\Helpers\import\article;

/**
 * Lectura de la celda de descuentos (o recargos) tal como la escriben los distintos sistemas de
 * origen, sin IA: es un algoritmo determinístico que corre igual en el análisis previo y en la
 * importación (misión importacion-formula-precio, 20/11/2026).
 *
 * El sistema concatena descuentos con «_» («10_5» = 10 % y después 5 %). Otros sistemas usan
 * «+» o «;» («-25+-30+-17») y además escriben el descuento con signo negativo, porque para ellos
 * el número es la variación del costo: −25 = baja 25 %, +5 = sube 5 %. En el sistema un
 * descuento negativo hace lo contrario de lo que el usuario espera (suma al costo), así que la
 * importación ofrece una opción, «los descuentos negativos descuentan», que invierte el signo
 * de cada parte: −25 pasa a ser un descuento de 25 y un +5 pasa a ser −5, o sea un descuento
 * negativo, que el cálculo de precios aplica como un 5 % más (igual que el sistema de origen).
 *
 * 🔴 La celda se reescribe como TEXTO (no se parsea el número): los decimales siguen su camino
 * normal por ImportHelper::parseNumericValue() con la interpretación del punto que eligió el
 * usuario. Acá solo se separan las partes y se toca el signo.
 */
class DescuentosDelExcel
{
    /**
     * Separadores aceptados entre descuentos encadenados. «_» es el del sistema; «+» y «;» son
     * los que usan otros sistemas. La coma NO es separador: es la coma decimal («12,5»).
     */
    const PATRON_SEPARADORES = '/[_+;]/';

    /**
     * Separa una celda de descuentos en sus partes, limpias de «%», comillas y espacios.
     * «-25+-30+-17» → ['-25', '-30', '-17']; «10_5» → ['10', '5']; «'-33» → ['-33'].
     * El «+» que hace de signo de un positivo («+5») queda absorbido por el separador.
     *
     * @param  mixed $texto  Valor crudo de la celda.
     * @return string[]      Partes no vacías, como texto.
     */
    public static function separar($texto)
    {
        if (is_null($texto)) {
            return [];
        }

        // Una celda numérica real (float/int) es un único descuento.
        if (is_int($texto) || is_float($texto)) {
            return [self::numero_como_texto($texto)];
        }

        // Comilla de texto de Excel al principio, «%» y espacios no aportan nada al valor.
        $limpio = str_replace(['%', ' ', "\xC2\xA0"], '', trim((string) $texto));
        $limpio = ltrim($limpio, "'´`");

        // Partes separadas por cualquiera de los separadores aceptados.
        $partes = preg_split(self::PATRON_SEPARADORES, $limpio);

        // Se descartan las vacías («-25+» o un «+5» inicial dejan una parte vacía).
        $resultado = [];
        foreach ($partes as $parte) {
            if ($parte !== '') {
                $resultado[] = $parte;
            }
        }

        return $resultado;
    }

    /**
     * Reescribe la celda al formato del sistema («_» como separador) y, si el usuario lo pidió,
     * invierte el signo de cada parte para que un negativo descuente.
     *
     * @param  mixed $texto                     Valor crudo de la celda.
     * @param  bool  $negativos_descuentan      true = −25 descuenta 25 y +5 suma 5.
     * @return string|null                      null si la celda no trae ningún valor.
     */
    public static function normalizar_celda($texto, $negativos_descuentan = false)
    {
        $partes = self::separar($texto);

        if (empty($partes)) {
            return null;
        }

        if ($negativos_descuentan) {
            $invertidas = [];
            foreach ($partes as $parte) {
                $invertidas[] = self::invertir_signo($parte);
            }
            $partes = $invertidas;
        }

        return implode('_', $partes);
    }

    /**
     * Invierte el signo de una parte escrita como texto, sin parsearla.
     * «-25» → «25»; «5» → «-5»; «0» queda «0» (un descuento en cero no significa nada).
     *
     * @param  string $parte
     * @return string
     */
    public static function invertir_signo($parte)
    {
        if (substr($parte, 0, 1) === '-') {
            return substr($parte, 1);
        }

        // Un cero no cambia de signo: «-0» confundiría al parseo de números.
        if (preg_match('/^0+([.,]0*)?$/', $parte)) {
            return $parte;
        }

        return '-' . $parte;
    }

    /**
     * Valor numérico de una parte, para los análisis estadísticos (no para importar).
     * Tolera coma decimal. Devuelve null si la parte no es un número.
     *
     * @param  string $parte
     * @return float|null
     */
    public static function valor_aproximado($parte)
    {
        $texto = str_replace(',', '.', (string) $parte);

        if (!is_numeric($texto)) {
            return null;
        }

        return (float) $texto;
    }

    /**
     * Multiplicador que la celda le aplica al costo, según cómo se interpreten los signos.
     *  - 'con_signo':  cada parte es la variación del costo (−25 baja 25 %, +5 sube 5 %).
     *  - 'del_sistema': cada parte es un descuento (25 baja 25 %, −5 sube 5 %).
     * Se usa para deducir cuál de las dos lecturas reproduce el precio del Excel.
     *
     * @param  mixed  $texto
     * @param  string $lectura  'con_signo' | 'del_sistema'
     * @return float|null       null si alguna parte no es un número.
     */
    public static function multiplicador($texto, $lectura)
    {
        $multiplicador = 1.0;

        foreach (self::separar($texto) as $parte) {
            $valor = self::valor_aproximado($parte);

            if (is_null($valor)) {
                return null;
            }

            $multiplicador *= ($lectura === 'con_signo')
                ? (1 + $valor / 100)
                : (1 - $valor / 100);
        }

        return $multiplicador;
    }

    /**
     * Número de una celda numérica, como texto sin notación científica ni «.0» sobrante.
     *
     * @param  int|float $numero
     * @return string
     */
    protected static function numero_como_texto($numero)
    {
        if (is_int($numero) || floor($numero) == $numero) {
            return (string) (int) $numero;
        }

        return rtrim(rtrim(sprintf('%.10F', $numero), '0'), '.');
    }
}
