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
     * vacío. Desde la ronda de arreglos de la misión (F5, 31/10/2026) también un `0` (o `'0'`, `'00'`…:
     * sólo ceros): DeepSeek rellena los opcionales con 0, y un «CUIT 0» es «no vino», no un CUIT mal
     * escrito que haya que pedirle a la persona. Ver es_vacio().
     *
     * @param  mixed  $valor
     * @return string|null
     */
    public static function motivo_si_no_sirve($valor)
    {
        if (self::es_vacio($valor)) {

            return null;
        }

        // Sólo los dígitos: los separadores con que se escribe un CUIT no cuentan.
        $digitos = preg_replace('/\D+/', '', trim((string) $valor));

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

    /**
     * true si el valor cuenta como «no vino» para la validación (ronda de arreglos de la misión, F5,
     * 31/10/2026): null, un booleano, una lista o un objeto (eso lo rechaza quien llama con su propio
     * mensaje), un texto vacío, o un valor que tiene dígitos y son todos ceros (`0`, `'0'`, `'00-0'`).
     *
     * Un texto sin ningún dígito («sin CUIT») NO es vacío: sigue siendo un CUIT que no sirve.
     *
     * @param  mixed  $valor
     * @return bool
     */
    public static function es_vacio($valor)
    {
        if (is_null($valor) || is_array($valor) || is_object($valor) || is_bool($valor)) {

            return true;
        }

        $texto = trim((string) $valor);

        if ($texto === '') {

            return true;
        }

        return (bool) preg_match('/^0+$/', (string) preg_replace('/\D+/', '', $texto));
    }

    /**
     * true si `$nuevo` es el MISMO CUIT/CUIL que `$actual`, el que ya tiene guardado la fila que se edita
     * (ronda de arreglos de la misión, F5, 31/10/2026). Se comparan sólo los dígitos: «20-16603682-3» y
     * «20166036823» son el mismo número escrito distinto. Sin dígitos en alguno de los dos, se comparan
     * los textos recortados. Un `$actual` vacío nunca es el mismo que un `$nuevo` con datos.
     *
     * 🔴 PARA QUÉ. En una EDICIÓN la validación corre solo si el CUIT cambia: un cliente con un CUIT
     * inválido YA guardado (lo cargó la pantalla, o es de antes de esta validación) no tiene que quedar sin
     * poder editarse el teléfono porque el modelo, al mandar los cambios, repitió el CUIT que ya estaba.
     *
     * @param  mixed  $nuevo
     * @param  mixed  $actual
     * @return bool
     */
    public static function es_el_mismo($nuevo, $actual)
    {
        if (self::es_vacio($actual) || is_array($nuevo) || is_object($nuevo) || is_bool($nuevo) || is_null($nuevo)) {

            return false;
        }

        $digitos_nuevo = (string) preg_replace('/\D+/', '', (string) $nuevo);
        $digitos_actual = (string) preg_replace('/\D+/', '', (string) $actual);

        if ($digitos_nuevo !== '' && $digitos_actual !== '') {

            return $digitos_nuevo === $digitos_actual;
        }

        return trim((string) $nuevo) === trim((string) $actual);
    }
}
