<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\UnidadMedida;

/**
 * LA UNIDAD DE MEDIDA POR EL NOMBRE QUE DICE EL DUEÑO (misión agente-mejoras-uso-real, E2 punto 2,
 * 29/10/2026).
 *
 * 🔴 EL CASO REAL. Pablo (Ferre Total, CONV 14 del 29/10/2026) pidió «cambiale la unidad de medida a
 * kilos» y el agente le contestó que no existía ninguna unidad que se llamara "kilos" y le preguntó
 * cómo estaba cargada. La unidad «Kilo» existía (id 3): la masiva buscaba `name LIKE '%kilos%'`, que
 * contra "Kilo" no encaja nunca (lo buscado es más largo que lo cargado), y ante cero coincidencias
 * devolvía un error SIN las opciones, así que el modelo no tenía con qué elegir solo.
 *
 * Ahora la resolución es en escalones, del más estricto al más laxo, y el primero que encuentra algo
 * decide:
 *
 *   1. igual, sin mayúsculas, tildes, puntos ni espacios de más ("KG." = "kg");
 *   2. por alias: kg, kgs, kilos, kilogramo → Kilo; gr, grs, gramos → Gramo; lt, lts, litros → Litro;
 *      mt, mts, metros → Metro; cm, centímetros → Centimetro; u, un, unidades → Unidad. El alias se
 *      aplica a los DOS lados, así que una unidad cargada como "Kg" también encaja con "kilos";
 *   3. sin el plural ("rollos" → "rollo", "pares" → "par");
 *   4. una contiene a la otra, en las dos direcciones ("kilo" está en "kilogramo" y al revés).
 *
 * Un escalón con UNA coincidencia la devuelve; con varias devuelve `faltan` con esas; y si ningún
 * escalón encuentra nada devuelve `faltan` con TODAS las unidades del sistema, como hace
 * PropuestaGenericaIaHelper::resolver_relacion() con las relaciones: el modelo elige de la lista o
 * le pregunta a la persona mostrándosela, nunca "decime cómo está cargada".
 *
 * Lo comparten la actualización masiva (PropuestaActualizacionMasivaIaHelper::cambio_de_select) y la
 * carga genérica (PropuestaGenericaIaHelper::resolver_relacion, campo `unidad_medida_id`): una sola
 * forma de entender "kilos" en todo el asistente.
 *
 * `unidad_medidas` es un catálogo GLOBAL (sin `user_id`, lo siembra UnidadMedidaSeeder): se trae
 * entero (son una decena de filas) y se compara en PHP.
 *
 * PHP 7.4: sin match, str_contains, nullsafe, argumentos nombrados ni union types.
 */
class UnidadDeMedidaIaHelper
{
    /**
     * Alias → forma canónica. La clave es la forma canónica (ya normalizada) y los valores, todo lo
     * que la persona puede decir para nombrarla. La forma canónica también está en su propia lista
     * para que una unidad cargada con ese nombre se reconozca.
     *
     * @var array<string, array<int, string>>
     */
    const ALIAS = [
        'kilo'       => ['kilo', 'kilos', 'kg', 'kgs', 'kilogramo', 'kilogramos', 'kilogr', 'k'],
        'gramo'      => ['gramo', 'gramos', 'gr', 'grs', 'g', 'grm', 'grms'],
        'litro'      => ['litro', 'litros', 'lt', 'lts', 'l', 'ltr', 'ltrs'],
        'metro'      => ['metro', 'metros', 'mt', 'mts', 'm', 'mtr', 'mtrs'],
        'centimetro' => ['centimetro', 'centimetros', 'cm', 'cms'],
        'unidad'     => ['unidad', 'unidades', 'u', 'un', 'uni', 'unid', 'unids', 'ud', 'uds'],
    ];

    /** Cuántas unidades viajan como opciones cuando no hay ninguna coincidencia (hoy son 8). */
    const TOPE_DE_OPCIONES = 50;

    /**
     * La unidad de medida que nombró la persona, o la respuesta negativa con las opciones.
     *
     * @param  string  $texto  Lo que dijo la persona ("kilos", "kg", "Kilo").
     * @return \App\Models\UnidadMedida|array  El modelo, o `faltan` con `unidades_de_medida` [{id, nombre}].
     */
    public static function resolver($texto)
    {
        $texto = trim((string) $texto);

        // Sin nada que buscar se pregunta con la lista completa: es la única pregunta útil.
        $todas = UnidadMedida::orderBy('name')->orderBy('id')->limit(self::TOPE_DE_OPCIONES)->get();

        if ($texto === '') {

            return RespuestaDeCargaIa::faltan(
                ['qué unidad de medida hay que asignar'],
                ['unidades_de_medida' => self::opciones($todas)]
            );
        }

        $buscado = self::normalizar($texto);

        /*
         * Los cuatro escalones, en orden. Cada uno es una función que dice si UNA unidad encaja; el
         * primero que junta alguna decide (una → esa; varias → se pregunta entre esas).
         */
        $escalones = [
            // 1. Igual normalizado.
            function ($nombre) use ($buscado) {
                return $nombre === $buscado;
            },
            // 2. La misma forma canónica por alias, aplicada a los dos lados.
            function ($nombre) use ($buscado) {
                $canonica_buscada = self::canonica($buscado);

                return !is_null($canonica_buscada) && $canonica_buscada === self::canonica($nombre);
            },
            // 3. Igual sin el plural.
            function ($nombre) use ($buscado) {
                return self::sin_plural($nombre) === self::sin_plural($buscado);
            },
            /*
             * 4. Una contiene a la otra (en las dos direcciones), pero solo si lo que SOBRA son palabras
             * de relleno ("por kilo", "en litros"). 🔴 Revisión independiente (30/10/2026): sin esa
             * condición, «mililitros» se resolvía a Litro, «milímetros», «kilómetros» y «metros
             * cuadrados» a Metro y «miligramos» a Gramo: un prefijo pegado (mili, centi, kilo, deci)
             * o un calificador (cuadrado, cúbico) es OTRA unidad, y va a `faltan` con la lista. Ver
             * sobra_solo_relleno().
             */
            function ($nombre) use ($buscado) {
                if (mb_strlen($nombre) < 2 || mb_strlen($buscado) < 2) {

                    return false;
                }

                if (mb_strpos($buscado, $nombre) !== false) {

                    return self::sobra_solo_relleno($nombre, $buscado);
                }

                if (mb_strpos($nombre, $buscado) !== false) {

                    return self::sobra_solo_relleno($buscado, $nombre);
                }

                return false;
            },
        ];

        foreach ($escalones as $encaja) {

            $encontradas = [];

            foreach ($todas as $unidad) {

                if ($encaja(self::normalizar((string) $unidad->name))) {

                    $encontradas[] = $unidad;
                }
            }

            if (count($encontradas) === 1) {

                return $encontradas[0];
            }

            if (count($encontradas) > 1) {

                return RespuestaDeCargaIa::faltan(
                    ['cuál unidad de medida: hay ' . count($encontradas) . ' que encajan con "' . $texto . '"'],
                    ['unidades_de_medida' => self::opciones($encontradas)]
                );
            }
        }

        /*
         * Ninguna: TODAS las opciones, para que el modelo elija la que corresponde (si es evidente) o
         * se la muestre a la persona. Un error pelado era lo que dejaba al agente preguntando "cómo
         * se llama la unidad en tu sistema".
         */
        return RespuestaDeCargaIa::faltan(
            ['qué unidad de medida es: no hay ninguna que se llame como "' . $texto . '". Elegí una de las que hay (si una es evidente, usala; si no, mostráselas a la persona)'],
            ['unidades_de_medida' => self::opciones($todas)]
        );
    }

    /**
     * Minúsculas, sin tildes, sin puntos y con los espacios colapsados: "KG." → "kg", "Centímetro"
     * → "centimetro".
     *
     * @param  string  $texto
     * @return string
     */
    public static function normalizar($texto)
    {
        $texto = mb_strtolower(trim((string) $texto));

        $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', '.' => '']);

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    /**
     * Las palabras que pueden acompañar a una unidad sin cambiarla: "por kilo", "en litros", "x metro".
     *
     * @var array<int, string>
     */
    const RELLENO = ['por', 'x', 'en', 'de', 'del', 'el', 'la', 'los', 'las', 'a', 'al', 'un', 'una'];

    /**
     * true si `$largo` contiene a `$corto` y lo único que sobra son palabras de RELLENO (más el plural
     * pegado al final: "kilo" en "kilos"). Si lo que sobra está PEGADO adelante ("mili" en
     * "mililitro", "kilo" en "kilómetro") o es cualquier otra palabra ("cuadrados", "cúbicos", otra
     * unidad), no es la misma unidad: false. Los dos textos, ya normalizados.
     *
     * @param  string  $corto
     * @param  string  $largo
     * @return bool
     */
    protected static function sobra_solo_relleno($corto, $largo)
    {
        $posicion = mb_strpos($largo, $corto);

        if ($posicion === false) {

            return false;
        }

        $antes = mb_substr($largo, 0, $posicion);
        $despues = mb_substr($largo, $posicion + mb_strlen($corto));

        // Un prefijo pegado adelante (mili-, centi-, kilo-, deci-...): es otra unidad.
        if ($antes !== '' && preg_match('/\p{L}$/u', $antes)) {

            return false;
        }

        // El plural pegado atrás se acepta ("kilo" en "kilos", "unidad" en "unidades").
        $despues = (string) preg_replace('/^(es|s)(?=\s|$)/u', '', $despues);

        // Cualquier otra cosa pegada atrás ("litro" en "litrox"): otra palabra.
        if ($despues !== '' && preg_match('/^\p{L}/u', $despues)) {

            return false;
        }

        // Lo que queda suelto, a los costados, tiene que ser relleno ("por kilo", "en litros").
        foreach (preg_split('/\s+/u', trim($antes . ' ' . $despues), -1, PREG_SPLIT_NO_EMPTY) as $palabra) {

            if (!in_array($palabra, self::RELLENO, true)) {

                return false;
            }
        }

        return true;
    }

    /**
     * La forma canónica de un nombre ya normalizado según ALIAS, o null si no es ningún alias.
     *
     * @param  string  $normalizado
     * @return string|null
     */
    protected static function canonica($normalizado)
    {
        foreach (self::ALIAS as $canonica => $alias) {

            if (in_array($normalizado, $alias, true)) {

                return $canonica;
            }
        }

        return null;
    }

    /**
     * "kilos" → "kilo", "pares" → "par", "unidades" → "unidad". Solo palabras de letras y de más de
     * tres: "kg" o "ml" quedan como están.
     *
     * @param  string  $normalizado
     * @return string
     */
    protected static function sin_plural($normalizado)
    {
        if (!preg_match('/^\p{L}+$/u', $normalizado) || mb_strlen($normalizado) <= 3) {

            return $normalizado;
        }

        // "unidades" → "unidad", "pares" → "par": la "es" de después de una consonante.
        if (mb_substr($normalizado, -2) === 'es' && !in_array(mb_substr($normalizado, -3, 1), ['a', 'e', 'i', 'o', 'u'], true)) {

            return mb_substr($normalizado, 0, mb_strlen($normalizado) - 2);
        }

        if (mb_substr($normalizado, -1) === 's') {

            return mb_substr($normalizado, 0, mb_strlen($normalizado) - 1);
        }

        return $normalizado;
    }

    /**
     * Las unidades como opciones para el modelo: [{id, nombre}].
     *
     * @param  iterable  $unidades
     * @return array<int, array{id: int, nombre: string}>
     */
    protected static function opciones($unidades)
    {
        $opciones = [];

        foreach ($unidades as $unidad) {

            $opciones[] = ['id' => (int) $unidad->id, 'nombre' => (string) $unidad->name];
        }

        return $opciones;
    }
}
