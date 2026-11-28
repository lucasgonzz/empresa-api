<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use Illuminate\Support\Facades\DB;

/**
 * Búsqueda híbrida de artículos para el agente de WhatsApp (misión agente-busqueda-hibrida).
 *
 * 🔴 POR QUÉ EXISTE. El agente recuperaba el catálogo SÓLO por significado (top 5 de similitud de
 * coseno): "Busco una bordeadora económica" le llegaba con cortacercos y cuchillas, sin ninguna de
 * las bordeadoras que el negocio tenía en stock, porque la palabra del producto no pesaba. Este
 * helper suma al vectorial los artículos cuyo NOMBRE contiene la palabra de producto, y decide el
 * orden. Son funciones puras (salvo `candidatos`, una sola query): `ArticleEmbeddingService` pone
 * el embedding, el top vectorial y las similitudes.
 *
 * Reglas que NO hay que "simplificar" (cada una arregla un defecto medido en la revisión del plan):
 * - Una sola tokenización para la consulta y para los nombres (`tokens`), con conectores, letras
 *   sueltas y números: la posición de una palabra es su índice en la lista COMPLETA.
 * - La equivalencia singular/plural es de PALABRA ENTERA y simétrica (`misma_palabra`). No se usa
 *   `BusquedaPorPalabrasIaHelper::sin_plural`: recorta de un solo sentido ("llaves" → "llav" no
 *   es igual a "llave") y sirve para LIKE, no para igualdad. Nunca subcadena: "foto" no es FOTOCELULA.
 * - Un candidato sin ninguna coincidencia en una palabra DE LETRAS se descarta: los números
 *   ("1", "4" de "1/4") sólo suman cobertura o desempatan, nunca definen nivel ni cola.
 * - La similitud ordena SÓLO dentro de cada cola por palabra. Un orden global por similitud
 *   deshace el reparto entre palabras ("nylon para bordeadora": una tapa a la otra).
 */
class BusquedaHibridaDeArticulosHelper
{
    /**
     * Tope de candidatos léxicos a los que se les calcula similitud. Es estática y pública para que
     * el test la baje (y la restaure) sin sembrar más de 500 artículos.
     *
     * @var int
     */
    public static $tope_candidatos_con_vector = 500;

    /** Máximo de artículos léxicos en el resultado final. */
    const LEXICOS_MAX = 6;

    /** Vectoriales que siempre se conservan cuando hay lugar (el K de antes de la misión). */
    const VECTORIALES_MINIMOS = 5;

    /** Máximo de palabras de producto que se toman de una consulta. */
    const PALABRAS_MAX = 8;

    /** Conectores del español: nunca son producto. Propia, no se importa de otro helper. */
    const CONECTORES = [
        'de', 'del', 'la', 'las', 'el', 'los', 'lo', 'y', 'e', 'o', 'u', 'con', 'sin', 'para', 'por', 'x', 'a', 'al',
        'en', 'un', 'una', 'unos', 'unas', 'que', 'q', 'como', 'desde', 'entre', 'sobre', 'hasta',
    ];

    /**
     * Palabras de conversación (cerrada, normalizada, con plurales y voseo escritos). Se comparan por
     * igualdad exacta del token, SIN recortar antes: ninguna singularización puede deformar "tenes".
     */
    const PALABRAS_DE_CONVERSACION = [
        'hola', 'buen', 'buena', 'buenas', 'bueno', 'buenos', 'dia', 'dias', 'tarde', 'tardes', 'noche', 'noches',
        'busco', 'buscando', 'buscaba', 'tene', 'tenes', 'tenga', 'tengo', 'tenemos', 'tienen', 'tiene', 'tendras',
        'tendrian', 'tendran', 'hay', 'habra', 'quiero', 'queria', 'quisiera', 'necesito', 'necesitaria', 'preciso',
        'vende', 'venden', 'vendes', 'precio', 'precios', 'cuanto', 'cuanta', 'sale', 'salen', 'vale', 'valen',
        'cuesta', 'cuestan', 'gracias', 'favor', 'porfa', 'xfa', 'me', 'mi', 'mis', 'te', 'tu', 'tus', 'su', 'sus',
        'le', 'este', 'esta', 'esto', 'ese', 'esa', 'eso', 'algo', 'algun', 'alguna', 'alguno', 'economico',
        'economica', 'economicos', 'economicas', 'barato', 'barata', 'baratos', 'baratas', 'mejor', 'otro', 'otra',
        'otros', 'otras', 'mas', 'menos', 'dale', 'ok', 'si', 'no', 'mandame', 'mandar', 'manda', 'pasame', 'pasar',
        'enviame', 'foto', 'fotos', 'imagen', 'imagenes', 'consulta', 'consultar', 'info', 'informacion', 'ver',
        'saber', 'hacer', 'cual', 'cuales', 'donde',
        // Formas de pedir un envío ("¿me pasás la foto?"): sin esto "pasas" generaba un LIKE '%pasa%' de basura.
        'pasas', 'mandas', 'mostras', 'mostrame', 'mostrar', 'envias', 'enviar', 'podes', 'podrias',
    ];

    /** Tokens que, antes de la palabra, la dejan "relacionada" con otra (P/BORDEADORA: de qué es, no qué es). */
    const RELACIONALES = ['p', 'para', 'c', 'con', 'de', 'del', 's', 'sin', 'x', 'y', 't'];

    /**
     * Tokeniza un texto: minúsculas, sin tildes, partido en todo lo que no sea [a-z0-9].
     *
     * Lista ordenada, con repetidos y con TODO (conectores, letras sueltas, números). "P/BORDEADORA"
     * → `p`, `bordeadora`. Ningún token tiene `%`, `_` ni `\`, así que el LIKE no necesita escape.
     *
     * Un decimal de 1 o 2 dígitos es UN token: "2,5", "2.5" y "2,50" → `2d5`; "1X2,50" → `1x`, `2d5`;
     * "2,5mm" → `2d5`, `mm`; "2,0" → `2`. Se parte antes que nada porque "cable de 2,5" se tokenizaba
     * como `2` y `5` y los cables 1X2,50 quedaban detrás de acero de 2 MM y morsas de 5" (Ferretotal,
     * 26/11/2026). Con 3 dígitos de fracción ("1.400 W") es un separador de miles y se parte como
     * siempre. No se compactan las medidas con "x" ("60 x 60" sigue siendo tres tokens). Consulta y
     * nombres pasan por acá, así que los dos lados normalizan igual: no tocar uno sin el otro.
     *
     * @param string $texto
     * @return array<int, string>
     */
    public static function tokens(string $texto): array
    {
        $minusculas = mb_strtolower($texto, 'UTF-8');
        $sin_tildes = strtr($minusculas, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        // La fracción sin ceros finales: "2,50" y "2,5" deben dar el mismo token; "2,0" queda en "2".
        $con_decimales = preg_replace_callback('/(\d+)[.,](\d{1,2})(?!\d)/', function ($m) {
            $fraccion = rtrim($m[2], '0');

            return ' ' . $m[1] . ($fraccion === '' ? '' : 'd' . $fraccion) . ' ';
        }, $sin_tildes);

        $partes = preg_split('/[^a-z0-9]+/', $con_decimales === null ? $sin_tildes : $con_decimales, -1, PREG_SPLIT_NO_EMPTY);

        return $partes === false ? [] : $partes;
    }

    /**
     * Indica si un token es una palabra de letras (sin ningún dígito).
     *
     * @param string $token
     * @return bool
     */
    public static function es_de_letras(string $token): bool
    {
        return preg_match('/^[a-z]+$/', $token) === 1;
    }

    /**
     * Palabras de producto de una consulta, en orden de aparición, sin repetidos y como máximo 8.
     *
     * Descarta la palabra de conversación (igualdad exacta), el conector y la de letras con menos de
     * 4 caracteres. NO singulariza: "tenes bordeadoras?" → ['bordeadoras']; la equivalencia
     * singular/plural vive en la comparación (`misma_palabra`). Los tokens con dígito (medidas) se
     * conservan para sumar cobertura, pero nunca generan candidatos.
     *
     * @param string $consulta
     * @return array<int, string>
     */
    public static function palabras_de_producto(string $consulta): array
    {
        $palabras = [];

        foreach (self::tokens($consulta) as $token) {
            if (in_array($token, self::PALABRAS_DE_CONVERSACION, true) || in_array($token, self::CONECTORES, true)) {
                continue;
            }

            if (self::es_de_letras($token) && strlen($token) < 4) {
                continue;
            }

            if (in_array($token, $palabras, true)) {
                continue;
            }

            $palabras[] = $token;

            if (count($palabras) >= self::PALABRAS_MAX) {
                break;
            }
        }

        return $palabras;
    }

    /**
     * Formas singular/plural de una palabra, incluida ella misma.
     *
     * Sólo para palabras de letras con 4 o más letras: agrega `+s` y `+es`; si termina en `s`, sin la
     * `s`; si termina en `es`, sin `es`; si termina en `z`, `z` → `ces`; si termina en `ces`, `ces` → `z`.
     *
     * @param string $palabra
     * @return array<int, string>
     */
    public static function variantes(string $palabra): array
    {
        $formas = [$palabra];

        if (! self::es_de_letras($palabra) || strlen($palabra) < 4) {
            return $formas;
        }

        $formas[] = $palabra . 's';
        $formas[] = $palabra . 'es';

        if (substr($palabra, -1) === 's') {
            $formas[] = substr($palabra, 0, -1);
        }

        if (substr($palabra, -2) === 'es') {
            $formas[] = substr($palabra, 0, -2);
        }

        if (substr($palabra, -1) === 'z') {
            $formas[] = substr($palabra, 0, -1) . 'ces';
        }

        if (substr($palabra, -3) === 'ces') {
            $formas[] = substr($palabra, 0, -3) . 'z';
        }

        return $formas;
    }

    /**
     * Igualdad de palabra entera con singular/plural en los dos sentidos.
     *
     * Simétrica por definición: se prueban las dos direcciones, también cuando una de las dos tiene
     * menos de 4 letras (mes/meses). Nunca es subcadena: "foto" no encaja con "fotocelula".
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    public static function misma_palabra(string $a, string $b): bool
    {
        return in_array($b, self::variantes($a), true) || in_array($a, self::variantes($b), true);
    }

    /**
     * Raíz para el prefiltro `LIKE '%raiz%'`: contiene a toda variante de la palabra, así el LIKE nunca
     * deja afuera una forma que después encaja. Lo que trae de más (LLAVERO, BOCALLAVES) lo descarta
     * la igualdad de palabra entera.
     *
     * `ces` se quita; si no, `z` final; si no, `es`; si no, `s`. Si queda con menos de 3 letras se usa
     * la palabra entera.
     *
     * @param string $palabra
     * @return string
     */
    public static function raiz_para_like(string $palabra): string
    {
        if (substr($palabra, -3) === 'ces') {
            $raiz = substr($palabra, 0, -3);
        } elseif (substr($palabra, -1) === 'z') {
            $raiz = substr($palabra, 0, -1);
        } elseif (substr($palabra, -2) === 'es') {
            $raiz = substr($palabra, 0, -2);
        } elseif (substr($palabra, -1) === 's') {
            $raiz = substr($palabra, 0, -1);
        } else {
            $raiz = $palabra;
        }

        return strlen($raiz) < 3 ? $palabra : $raiz;
    }

    /**
     * TODOS los candidatos léxicos del dueño (sólo id y name), sin ORDER BY ni LIMIT: el corte se hace
     * después de rankear por nivel y cobertura, nunca antes (una máquina de id alto no puede quedar
     * afuera). Sólo palabras de letras generan LIKE; sin ninguna, lista vacía.
     *
     * @param int               $user_id  Dueño del catálogo.
     * @param array<int,string> $palabras Palabras de producto (`palabras_de_producto`).
     * @return array<int, object>         Filas con id y name.
     */
    public static function candidatos(int $user_id, array $palabras): array
    {
        $raices = [];

        foreach ($palabras as $palabra) {
            if (self::es_de_letras($palabra)) {
                $raices[self::raiz_para_like($palabra)] = true;
            }
        }

        if (empty($raices)) {
            return [];
        }

        $raices = array_keys($raices);

        return DB::table('articles')
            ->where('user_id', $user_id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->where(function ($query) use ($raices) {
                foreach ($raices as $raiz) {
                    $query->orWhere('name', 'like', '%' . $raiz . '%');
                }
            })
            ->select('id', 'name', 'stock')
            ->get()
            ->all();
    }

    /**
     * Nivel de una coincidencia según su posición en la lista COMPLETA de tokens del nombre: 0 si está
     * en la posición 0, o en la 1 con un primer token que no es relacional (NOBER BORDEADORA,
     * CARRETEL BORDEADORA); 1 en cualquier otro caso (CARRETEL P/BORDEADORA).
     *
     * @param int                $posicion
     * @param array<int,string>  $tokens_del_nombre
     * @return int
     */
    protected static function nivel_de_posicion(int $posicion, array $tokens_del_nombre): int
    {
        if ($posicion === 0) {
            return 0;
        }

        if ($posicion === 1 && ! in_array($tokens_del_nombre[0], self::RELACIONALES, true)) {
            return 0;
        }

        return 1;
    }

    /**
     * Coincidencias de cada candidato con las palabras de la consulta: cobertura, nivel y cola.
     *
     * - Una palabra encaja en la posición `i` si `misma_palabra(palabra, tokens[i])`.
     * - Cobertura = cantidad de palabras (de letras o números) que encajan en alguna posición.
     * - Nivel = el menor entre las palabras DE LETRAS que encajan (los números no definen nivel).
     * - Cola = índice (en `$palabras`) de la única palabra de letras que encajó, si la cobertura es 1;
     *   null en el grupo combinado (cobertura >= 2).
     * - Sin ninguna coincidencia en una palabra de letras → el candidato se descarta.
     *
     * Cada encaje lleva además `stock` (decimal o null si la fila no trae el dato), que `ordenar`
     * usa para poner primero lo que se puede vender.
     *
     * @param array<int, object|array> $filas    Candidatos con id, name y (opcional) stock.
     * @param array<int, string>       $palabras Palabras de la consulta (letras y números).
     * @return array<int, array{id:int, name:string, stock:?float, cobertura:int, nivel:int, cola:?int}>
     */
    public static function coincidencias(array $filas, array $palabras): array
    {
        $resultado = [];

        foreach ($filas as $fila) {
            $id     = (int) (is_array($fila) ? $fila['id'] : $fila->id);
            $nombre = (string) (is_array($fila) ? $fila['name'] : $fila->name);
            $tokens = self::tokens($nombre);

            // Stock de la fila (null si no viene o no tiene dato: sin control de stock).
            $stock_crudo = is_array($fila) ? (isset($fila['stock']) ? $fila['stock'] : null) : (isset($fila->stock) ? $fila->stock : null);
            $stock       = is_null($stock_crudo) ? null : (float) $stock_crudo;

            $cobertura    = 0;
            $nivel        = null;
            $cola         = null;
            $hay_de_letras = false;

            foreach ($palabras as $indice => $palabra) {
                $posicion_encontrada = null;

                foreach ($tokens as $posicion => $token) {
                    if (self::misma_palabra($palabra, $token)) {
                        $posicion_encontrada = $posicion;
                        break;
                    }
                }

                if (is_null($posicion_encontrada)) {
                    continue;
                }

                $cobertura++;

                if (self::es_de_letras($palabra)) {
                    $hay_de_letras = true;
                    $nivel_palabra = self::nivel_de_posicion($posicion_encontrada, $tokens);
                    $nivel         = is_null($nivel) ? $nivel_palabra : min($nivel, $nivel_palabra);
                    $cola          = $indice;
                }
            }

            // Sin coincidencia en una palabra de letras no hay candidato, aunque coincidan los números.
            if (! $hay_de_letras) {
                continue;
            }

            $resultado[] = [
                'id'        => $id,
                'name'      => $nombre,
                'stock'     => $stock,
                'cobertura' => $cobertura,
                'nivel'     => $nivel,
                'cola'      => $cobertura >= 2 ? null : $cola,
            ];
        }

        return $resultado;
    }

    /**
     * Ordena los candidatos: grupo combinado (cobertura >= 2) y después una cola por palabra, en
     * alternancia estricta. La similitud ordena SÓLO dentro del grupo o de cada cola; nunca mueve un
     * candidato de grupo, de cola, de nivel ni de turno.
     *
     * - Grupo: cobertura desc, nivel asc, stock (con stock, sin dato, agotado), similitud desc, id asc.
     * - Cola de cada palabra: nivel asc, stock (con stock, sin dato, agotado), similitud desc, id asc.
     *   El stock va antes que la similitud porque ofrecer una opción agotada no le sirve al cliente
     *   (ticket 172: Konan y Dogo, con stock, quedaban detrás de seis amoladoras sin stock).
     * - `alternar`: en cada vuelta toma la cabeza de cada cola no vacía, en el orden de la consulta.
     *
     * Con `$similitudes` vacío es el pre-orden (sin vectores) que decide qué candidatos sobreviven al tope.
     *
     * @param array<int, array> $encajes     Salida de `coincidencias`.
     * @param array<int, float> $similitudes id => similitud (ausente = 0).
     * @return array<int, array>
     */
    public static function ordenar(array $encajes, array $similitudes = []): array
    {
        $similitud_de = function ($encaje) use ($similitudes) {
            return isset($similitudes[$encaje['id']]) ? (float) $similitudes[$encaje['id']] : 0.0;
        };

        $combinado = [];
        $colas     = [];

        foreach ($encajes as $encaje) {
            if (is_null($encaje['cola'])) {
                $combinado[] = $encaje;
            } else {
                $colas[$encaje['cola']][] = $encaje;
            }
        }

        usort($combinado, function ($a, $b) use ($similitud_de) {
            if ($a['cobertura'] !== $b['cobertura']) {
                return $b['cobertura'] <=> $a['cobertura'];
            }
            if ($a['nivel'] !== $b['nivel']) {
                return $a['nivel'] <=> $b['nivel'];
            }
            if (self::nivel_de_stock($a) !== self::nivel_de_stock($b)) {
                return self::nivel_de_stock($a) <=> self::nivel_de_stock($b);
            }
            $sim_a = $similitud_de($a);
            $sim_b = $similitud_de($b);
            if ($sim_a !== $sim_b) {
                return $sim_b <=> $sim_a;
            }

            return $a['id'] <=> $b['id'];
        });

        ksort($colas);

        foreach ($colas as $indice => $cola) {
            usort($cola, function ($a, $b) use ($similitud_de) {
                if ($a['nivel'] !== $b['nivel']) {
                    return $a['nivel'] <=> $b['nivel'];
                }
                if (self::nivel_de_stock($a) !== self::nivel_de_stock($b)) {
                    return self::nivel_de_stock($a) <=> self::nivel_de_stock($b);
                }
                $sim_a = $similitud_de($a);
                $sim_b = $similitud_de($b);
                if ($sim_a !== $sim_b) {
                    return $sim_b <=> $sim_a;
                }

                return $a['id'] <=> $b['id'];
            });
            $colas[$indice] = $cola;
        }

        return array_merge($combinado, self::alternar(array_values($colas)));
    }

    /**
     * Nivel de stock de un encaje para ordenar: 0 si tiene stock (> 0), 1 si no hay dato (null: sin
     * control de stock, se puede ofrecer), 2 si está agotado (<= 0).
     *
     * @param array $encaje Encaje con la clave `stock` (ausente = sin dato).
     * @return int
     */
    protected static function nivel_de_stock(array $encaje): int
    {
        if (! isset($encaje['stock'])) {
            return 1;
        }

        return (float) $encaje['stock'] > 0 ? 0 : 2;
    }

    /**
     * Ronda estricta entre colas: en cada vuelta toma la cabeza de cada cola no vacía, en el orden dado.
     * Una palabra con cientos de coincidencias no tapa a la otra.
     *
     * @param array<int, array<int, array>> $colas
     * @return array<int, array>
     */
    public static function alternar(array $colas): array
    {
        $salida   = [];
        $posicion = 0;

        do {
            $tomo = false;

            foreach ($colas as $cola) {
                if (isset($cola[$posicion])) {
                    $salida[] = $cola[$posicion];
                    $tomo     = true;
                }
            }

            $posicion++;
        } while ($tomo);

        return $salida;
    }

    /**
     * Fusiona los léxicos ordenados con el top vectorial, conservando el orden de cada lista.
     *
     * `n0 = min(6, cantidad de nivel 0)`; `cupo1 = max(0, min(6 - n0, limit - 5 - n0))`. Se recorre `L`
     * EN ORDEN y se toma cada elemento si es nivel 0 y van menos de `n0`, o nivel 1 y van menos de
     * `cupo1` (filtro estable: no se re-ordena). Después, los vectoriales en su orden, sin repetidos,
     * hasta `$limit`. Sin léxicos: exactamente los primeros `$limit` del vectorial.
     *
     * @param array<int, array> $lexicos    `L`: salida de `ordenar` con similitudes.
     * @param array<int, int>   $vectoriales Ids del top vectorial, en su orden.
     * @param int               $limit
     * @return array<int, int>   Ids finales, en orden.
     */
    public static function fusionar(array $lexicos, array $vectoriales, int $limit): array
    {
        $nivel_cero = 0;
        foreach ($lexicos as $lexico) {
            if ($lexico['nivel'] === 0) {
                $nivel_cero++;
            }
        }

        $n0    = min(self::LEXICOS_MAX, $nivel_cero);
        $cupo1 = max(0, min(self::LEXICOS_MAX - $n0, $limit - self::VECTORIALES_MINIMOS - $n0));

        $tomados     = [];
        $tomados_cero = 0;
        $tomados_uno  = 0;

        foreach ($lexicos as $lexico) {
            if ($lexico['nivel'] === 0 && $tomados_cero < $n0) {
                $tomados[] = $lexico['id'];
                $tomados_cero++;
            } elseif ($lexico['nivel'] === 1 && $tomados_uno < $cupo1) {
                $tomados[] = $lexico['id'];
                $tomados_uno++;
            }
        }

        $salida = array_slice($tomados, 0, $limit);

        foreach ($vectoriales as $id) {
            if (count($salida) >= $limit) {
                break;
            }

            if (! in_array($id, $salida, true)) {
                $salida[] = $id;
            }
        }

        return $salida;
    }
}
