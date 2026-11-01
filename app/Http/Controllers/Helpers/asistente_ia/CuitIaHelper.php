<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

/**
 * Si un CUIT o un CUIL que el asistente está por cargar sirve (misión agente-mejoras-diario, análisis
 * del 31/10/2026, #2).
 *
 * 🔴 POR QUÉ EXISTE. El dueño mandó la foto de una planilla con CUIT válidos y el modelo, al
 * releerlos, "corrigió" dígitos y propuso CUIT que no existen: la carga genérica los dejaba pasar
 * porque `cuit` es un texto más (caía en el `trim` por defecto de
 * PropuestaGenericaIaHelper::coercionar) y en todo el repo no había un validador de CUIT. Un CUIT
 * con el dígito verificador mal es, sin excepción, un número mal leído o mal escrito: no se propone,
 * se le pide el número a la persona.
 *
 * 🔴 UN DNI NO ES UN CUIT MAL ESCRITO. Con 7 u 8 dígitos el valor es un DNI (consumidor final, un
 * cliente sin CUIT), y la pantalla lo deja cargar en el mismo campo: no se rechaza.
 *
 * Vale para el alta y para la edición, y para las dos columnas (`cuit` y `cuil`): el algoritmo del
 * dígito verificador es el mismo (es el módulo 11 de AFIP/ARCA).
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class CuitIaHelper
{
    /** Las columnas de la carga genérica que se validan como CUIT/CUIL. */
    const COLUMNAS = ['cuit', 'cuil'];

    /**
     * Los pesos del módulo 11 para los diez primeros dígitos (el undécimo es el verificador).
     *
     * @var array<int, int>
     */
    const PESOS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    /** Largos que se toman como DNI y no se validan como CUIT. */
    const LARGOS_DE_DNI = [7, 8];

    /** Largo de un CUIT/CUIL sin separadores. */
    const LARGO_DE_CUIT = 11;

    /**
     * true si la columna de la carga genérica es una de las que se validan (COLUMNAS).
     *
     * @param  string  $columna
     * @return bool
     */
    public static function es_columna_de_cuit($columna)
    {
        return in_array(strtolower(trim((string) $columna)), self::COLUMNAS, true);
    }

    /**
     * El motivo por el que el valor NO sirve como CUIT/CUIL, o null si sirve.
     *
     * Se sacan los separadores (puntos, guiones, espacios, barras: todo lo que no es dígito) y:
     *   - 11 dígitos: se calcula el verificador con PESOS sobre los diez primeros,
     *     `11 - (suma % 11)`; si da 11 el verificador es 0, y si da 10 el número no existe (AFIP
     *     nunca lo asigna: le cambia el prefijo). Tiene que coincidir con el undécimo dígito.
     *   - 7 u 8 dígitos: es un DNI, sirve (null).
     *   - cualquier otro largo: no sirve.
     *
     * Un valor vacío no se juzga acá (null): lo resuelve quien llama, como resuelve cualquier campo
     * vacío.
     *
     * @param  mixed  $valor
     * @return string|null
     */
    public static function motivo_si_no_sirve($valor)
    {
        if (is_null($valor) || is_array($valor) || is_object($valor) || is_bool($valor)) {

            return null;
        }

        $texto = trim((string) $valor);

        if ($texto === '') {

            return null;
        }

        // Sólo los dígitos: los separadores con que se escribe un CUIT no cuentan.
        $digitos = preg_replace('/\D+/', '', $texto);

        $largo = strlen($digitos);

        if (in_array($largo, self::LARGOS_DE_DNI, true)) {

            return null;
        }

        if ($largo !== self::LARGO_DE_CUIT) {

            return 'tiene ' . $largo . ' dígitos y un CUIT tiene 11 (o 7 u 8 si es un DNI)';
        }

        $suma = 0;

        foreach (self::PESOS as $posicion => $peso) {

            $suma += (int) $digitos[$posicion] * $peso;
        }

        $verificador = 11 - ($suma % 11);

        if ($verificador === 11) {

            $verificador = 0;
        }

        if ($verificador === 10 || $verificador !== (int) $digitos[10]) {

            return 'el dígito verificador no da';
        }

        return null;
    }
}
