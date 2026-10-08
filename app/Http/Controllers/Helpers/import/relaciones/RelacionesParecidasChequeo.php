<?php

namespace App\Http\Controllers\Helpers\import\relaciones;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Helpers\import\article\ArticleImportColumnsNormalizer;
use App\Http\Controllers\Helpers\import\excel\ExcelHeaderDetector;
use App\Http\Controllers\Helpers\import\excel\ExcelWorkbookReader;

/**
 * El chequeo de nombres parecidos de la importación de Excel (misión
 * importacion-relaciones-parecidas, 8/10/2026): arma `relaciones_parecidas`, la lista que el paso 3
 * del modal le muestra al usuario antes de importar.
 *
 * Por cada columna que es una relación con otro modelo (proveedor, categoría, subcategoría y marca
 * en artículos; provincia, localidad, vendedor y lista de precios en clientes; localidad en
 * proveedores):
 *   1. lee los valores distintos del Excel (misma hoja y mismo encabezado que el paso 2);
 *   2. carga los existentes del comercio (sin borrados) y sus equivalencias recordadas;
 *   3. le pasa todo a RelacionesParecidasDetector (determinista);
 *   4. junta los candidatos parecidos de TODAS las columnas y se los manda a la IA en UNA llamada
 *      (tope TOPE_ITEMS_IA, los de más filas primero); sin candidatos no hay llamada;
 *   5. cierra cada grupo con lo que contestó la IA.
 *
 * Las subcategorías se comparan DENTRO de su categoría, una vez por cada resolución posible de la
 * categoría (`padre_ref`: "id:X" por cada existente que el paso 3 le ofrece, "nuevo:<x>" por cada
 * forma de crearla): si el usuario cambia la decisión de la categoría, los hijos de la opción que
 * eligió también están revisados. En cada resolución se juntan las subcategorías de todas las
 * escrituras del Excel que terminan en esa misma categoría ("Látex" bajo "Pinturería" con "Latex"
 * bajo "Pintureria"). Igual para localidad dentro de provincia.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class RelacionesParecidasChequeo
{
    /** Valores que se le mandan a la IA como máximo en una corrida. */
    const TOPE_ITEMS_IA = 150;

    /** Valor que la importación de clientes y proveedores ignora a propósito (LocalImportHelper). */
    const SIN_ESPECIFICAR = 'Sin especificar';

    /* =====================================================================
     * Entradas, una por modelo de importación
     * ================================================================== */

    /**
     * @param  int    $user_id          dueño del comercio
     * @param  string $excel_full_path
     * @param  array  $payload          payload de la corrida de recomendación
     * @return array  ['ia_consultada' => bool, 'items' => [...]]
     */
    public static function para_articulos($user_id, $excel_full_path, array $payload)
    {
        $columnas = ArticleImportColumnsNormalizer::normalize(self::mapa_de_columnas($payload));

        /*
         * Con un proveedor elegido para toda la importación, la columna proveedor no se usa
         * (ArticleImport::set_providers() no corre): no hay nada que preguntar sobre ella.
         */
        $provider_id = isset($payload['provider_id']) && is_numeric($payload['provider_id']) ? (int) $payload['provider_id'] : 0;
        $usa_proveedor = $provider_id <= 0 && isset($columnas['proveedor']);

        $valores = ['proveedor' => [], 'categoria' => [], 'marca' => []];
        $subs = [];

        self::recorrer_filas($excel_full_path, $payload, function ($fila) use ($columnas, $usa_proveedor, &$valores, &$subs) {
            /* Misma condición que ArticleImport::checkRow(): una fila sin nada que la identifique no se importa. */
            if (
                is_null(ImportHelper::getColumnValueByAliases($fila, ['nombre', 'descripcion'], $columnas))
                && is_null(ImportHelper::getColumnValueByAliases($fila, ['codigo_de_proveedor', 'codigo_proveedor'], $columnas))
                && is_null(ImportHelper::getColumnValueByAliases($fila, ['codigo_de_barras', 'codigo_barras'], $columnas))
                && is_null(ImportHelper::getColumnValue($fila, 'numero', $columnas))
            ) {
                return;
            }

            if ($usa_proveedor) {
                self::sumar($valores['proveedor'], ImportHelper::getColumnValue($fila, 'proveedor', $columnas));
            }

            $categoria = ImportHelper::getColumnValue($fila, 'categoria', $columnas);

            if (ImportHelper::usa_columna($categoria)) {
                self::sumar($valores['categoria'], $categoria);

                $sub = ImportHelper::getColumnValue($fila, 'sub_categoria', $columnas);

                if (ImportHelper::usa_columna($sub)) {
                    if (!isset($subs[$categoria])) {
                        $subs[$categoria] = [];
                    }

                    self::sumar($subs[$categoria], $sub);
                }
            }

            $marca = ImportHelper::getColumnValue($fila, 'marca', $columnas);

            /* ProcessRow::get_brand_id() saltea cualquier valor "falsy" (incluido "0"): acá igual. */
            if ($marca) {
                self::sumar($valores['marca'], $marca);
            }
        });

        $simples = [];

        foreach (['proveedor', 'categoria', 'marca'] as $tipo) {
            if (count($valores[$tipo]) > 0) {
                $simples[$tipo] = $valores[$tipo];
            }
        }

        return self::chequear($user_id, $simples, 'categoria', 'sub_categoria', $subs);
    }

    /**
     * @param  int    $user_id
     * @param  string $excel_full_path
     * @param  array  $payload
     * @return array
     */
    public static function para_clientes($user_id, $excel_full_path, array $payload)
    {
        $columnas = self::mapa_de_columnas($payload);

        $valores = ['provincia' => [], 'vendedor' => [], 'tipo_de_precio' => [], 'localidad' => []];
        $localidades_con_provincia = [];

        self::recorrer_filas($excel_full_path, $payload, function ($fila) use ($columnas, &$valores, &$localidades_con_provincia) {
            /* ClientImport::checkRow(): sin nombre la fila no se importa. */
            if (is_null(ImportHelper::getColumnValue($fila, 'nombre', $columnas))) {
                return;
            }

            $provincia = self::util(ImportHelper::getColumnValue($fila, 'provincia', $columnas));
            $localidad = self::util(ImportHelper::getColumnValue($fila, 'localidad', $columnas));

            if (!is_null($provincia)) {
                self::sumar($valores['provincia'], $provincia);
            }

            /*
             * Como ClientImport::saveModel(): con provincia en la fila, la localidad se busca
             * dentro de esa provincia; sin provincia, contra todas las localidades del comercio.
             */
            if (!is_null($localidad)) {
                if (!is_null($provincia)) {
                    if (!isset($localidades_con_provincia[$provincia])) {
                        $localidades_con_provincia[$provincia] = [];
                    }

                    self::sumar($localidades_con_provincia[$provincia], $localidad);
                } else {
                    self::sumar($valores['localidad'], $localidad);
                }
            }

            self::sumar($valores['vendedor'], self::util(ImportHelper::getColumnValue($fila, 'vendedor', $columnas)));
            self::sumar($valores['tipo_de_precio'], self::util(ImportHelper::getColumnValueByAliases($fila, ['tipo_de_precio', 'tipo de precio'], $columnas)));
        });

        $simples = [];

        foreach (['provincia', 'localidad', 'vendedor', 'tipo_de_precio'] as $tipo) {
            if (count($valores[$tipo]) > 0) {
                $simples[$tipo] = $valores[$tipo];
            }
        }

        return self::chequear($user_id, $simples, 'provincia', 'localidad', $localidades_con_provincia);
    }

    /**
     * @param  int    $user_id
     * @param  string $excel_full_path
     * @param  array  $payload
     * @return array
     */
    public static function para_proveedores($user_id, $excel_full_path, array $payload)
    {
        $columnas = self::mapa_de_columnas($payload);

        $valores = [];

        self::recorrer_filas($excel_full_path, $payload, function ($fila) use ($columnas, &$valores) {
            if (is_null(ImportHelper::getColumnValue($fila, 'nombre', $columnas))) {
                return;
            }

            self::sumar($valores, self::util(ImportHelper::getColumnValue($fila, 'localidad', $columnas)));
        });

        $simples = count($valores) > 0 ? ['localidad' => $valores] : [];

        return self::chequear($user_id, $simples, null, null, []);
    }

    /* =====================================================================
     * El chequeo en sí
     * ================================================================== */

    /**
     * @param  int         $user_id
     * @param  array       $simples      [tipo => [valor => filas]] — grupos sin padre
     * @param  string|null $tipo_padre   'categoria' | 'provincia' | null
     * @param  string|null $tipo_hijo    'sub_categoria' | 'localidad' | null
     * @param  array       $hijos        [valor del padre => [valor => filas]]
     * @return array ['ia_consultada' => bool, 'recortado' => bool, 'items' => [...]]
     */
    protected static function chequear($user_id, array $simples, $tipo_padre, $tipo_hijo, array $hijos)
    {
        /* Un solo presupuesto de comparaciones para toda la corrida (ver el detector). */
        $presupuesto = RelacionesParecidasDetector::nuevo_presupuesto();
        $recortado = false;

        $grupos = [];

        foreach ($simples as $tipo => $valores) {
            $existentes = self::existentes($user_id, $tipo);
            $recordadas = EquivalenciasResolver::recordadas_del_tipo($user_id, $tipo);

            $analisis = RelacionesParecidasDetector::analizar_grupo(
                $valores,
                self::lista_de_existentes($existentes),
                isset($recordadas[0]) ? $recordadas[0] : [],
                true,
                $presupuesto
            );

            $recortado = $recortado || !empty($analisis['recortado']);

            $grupos[] = [
                'tipo'      => $tipo,
                'es_hijo'   => false,
                'prioridad' => 0,
                'analisis'  => $analisis,
            ];
        }

        /*
         * Hijos (subcategorías, localidades con provincia): se analizan una vez por cada
         * RESOLUCIÓN POSIBLE del padre (`padre_ref`), porque el usuario puede cambiar la decisión
         * del padre y los hijos tienen que estar revisados para la que elija. "Látex" bajo
         * "Pintureria" y "Latex" bajo "Pinturería" se comparan juntos en la resolución en la que
         * las dos categorías terminan siendo la misma (`nuevo:Pinturería`), y cada uno solo en la
         * de "crear aparte" de su categoría.
         *
         * Antes de la IA se arman los grupos con las resoluciones que el padre PODRÍA tener; después
         * se arman con las que efectivamente le quedaron de opciones. Un grupo se aprovecha (con lo
         * que contestó la IA) solo si tiene exactamente los mismos padres en los dos momentos; si no,
         * se rehace solo con la capa determinista.
         */
        $existentes_hijo = [];
        $recordadas_hijo = [];
        $indice_padre = null;

        if (!is_null($tipo_hijo) && count($hijos) > 0) {
            $existentes_hijo = self::existentes($user_id, $tipo_hijo);
            $recordadas_hijo = EquivalenciasResolver::recordadas_del_tipo($user_id, $tipo_hijo);

            foreach ($grupos as $i => $grupo) {
                if ($grupo['tipo'] === $tipo_padre) {
                    $indice_padre = $i;
                }
            }

            $refs_previas = is_null($indice_padre)
                ? []
                : self::refs_de_padre_previas($grupos[$indice_padre]['analisis']);

            $preseleccionadas = is_null($indice_padre)
                ? []
                : self::refs_preseleccionadas_previas($grupos[$indice_padre]['analisis']);

            foreach (self::agrupar_hijos_por_ref($hijos, $refs_previas) as $ref => $grupo_hijo) {
                $analisis = self::analizar_hijo($grupo_hijo['valores'], $existentes_hijo, $recordadas_hijo, self::id_de_ref($ref), true, $presupuesto);

                $recortado = $recortado || !empty($analisis['recortado']);

                /*
                 * Prioridad para la IA: los hijos bajo la resolución que el padre tiene (muy
                 * probablemente) preseleccionada van antes que los de las resoluciones alternativas.
                 */
                $es_preseleccionada = false;

                foreach ($grupo_hijo['padres'] as $padre) {
                    if (isset($preseleccionadas[$padre][(string) $ref])) {
                        $es_preseleccionada = true;
                    }
                }

                $grupos[] = [
                    'tipo'      => $tipo_hijo,
                    'es_hijo'   => true,
                    'prioridad' => $es_preseleccionada ? 1 : 2,
                    'ref'       => (string) $ref,
                    'padres'    => $grupo_hijo['padres'],
                    'analisis'  => $analisis,
                ];
            }
        }

        /* Una sola llamada a la IA para todo, con las consultas idénticas una sola vez. */
        $consultas = self::armar_consultas($grupos);

        $recortado = $recortado || $consultas['recortado'];

        $ia_ok = true;
        $respuestas_ia = [];

        if (count($consultas['consultas']) > 0) {
            $respuestas_ia = RelacionesParecidasIa::juzgar($user_id, $consultas['consultas']);

            if (is_null($respuestas_ia)) {
                $ia_ok = false;
                $respuestas_ia = [];
            }
        }

        $por_grupo = self::respuestas_por_grupo($consultas['mapa'], $respuestas_ia);

        $items = [];
        $items_padre = [];

        /* Primero los grupos sin padre: de ahí salen las opciones de la categoría (o provincia). */
        foreach ($grupos as $i => $grupo) {
            if ($grupo['es_hijo']) {
                continue;
            }

            $cerrado = RelacionesParecidasDetector::cerrar_grupo(
                $grupo['tipo'],
                $grupo['analisis'],
                isset($por_grupo[$i]) ? $por_grupo[$i]['respuestas'] : [],
                isset($por_grupo[$i]) ? $por_grupo[$i]['enviados'] : [],
                $ia_ok
            );

            if ($grupo['tipo'] === $tipo_padre) {
                $items_padre = $cerrado['items'];
            }

            $items[$grupo['tipo']] = $cerrado['items'];
        }

        if (!is_null($tipo_hijo) && count($hijos) > 0) {
            $refs_finales = self::refs_de_padre_finales(
                array_keys($hijos),
                is_null($indice_padre) ? ['valores' => []] : $grupos[$indice_padre]['analisis'],
                $items_padre
            );

            $previos = [];

            foreach ($grupos as $i => $grupo) {
                if ($grupo['es_hijo']) {
                    $previos[$grupo['ref']] = $i;
                }
            }

            $items_hijos = [];

            foreach (self::agrupar_hijos_por_ref($hijos, $refs_finales) as $ref => $grupo_hijo) {
                $ref = (string) $ref;
                $padre_id = self::id_de_ref($ref);

                $i = isset($previos[$ref]) ? $previos[$ref] : null;

                if (!is_null($i) && self::mismos_padres($grupos[$i]['padres'], $grupo_hijo['padres'])) {
                    $analisis   = $grupos[$i]['analisis'];
                    $respuestas = isset($por_grupo[$i]) ? $por_grupo[$i]['respuestas'] : [];
                    $enviados   = isset($por_grupo[$i]) ? $por_grupo[$i]['enviados'] : [];
                } else {
                    $analisis   = self::analizar_hijo($grupo_hijo['valores'], $existentes_hijo, $recordadas_hijo, $padre_id, false, $presupuesto);
                    $respuestas = [];
                    $enviados   = [];
                }

                $cerrado = RelacionesParecidasDetector::cerrar_grupo($tipo_hijo, $analisis, $respuestas, $enviados, $ia_ok, null, $padre_id);

                $items_hijos = array_merge($items_hijos, self::items_por_fila($cerrado['items'], $grupo_hijo['padres'], $hijos, $tipo_hijo, $ref));
            }

            $items[$tipo_hijo] = array_merge(isset($items[$tipo_hijo]) ? $items[$tipo_hijo] : [], $items_hijos);
        }

        $todos = [];

        foreach (['proveedor', 'categoria', 'sub_categoria', 'marca', 'provincia', 'localidad', 'vendedor', 'tipo_de_precio'] as $tipo) {
            if (isset($items[$tipo])) {
                $todos = array_merge($todos, $items[$tipo]);
            }
        }

        return [
            /*
             * false SOLO cuando hacía falta la IA y no se pudo consultar: es lo que dispara el
             * aviso del paso 3. Sin candidatos no hay llamada y no hay nada que avisar.
             */
            'ia_consultada' => $ia_ok,

            /*
             * true si alguna capa se acotó: una columna con demasiados valores, más consultas de las
             * que entran en la IA o el presupuesto agotado (de comparaciones o de bloqueo). El paso 3
             * avisa que puede haber otros parecidos que no aparecen.
             */
            'recortado'     => $recortado || (bool) $presupuesto->recortado,
            'items'         => $todos,
        ];
    }

    /**
     * Las resoluciones que cada padre PODRÍA tener después de la IA (un superconjunto): "id:<id>"
     * por cada existente que el paso 3 podría ofrecerle y "nuevo:<x>" por cada forma de crearlo
     * (él mismo, el canónico de su misma escritura entre los nuevos, o un valor del archivo con el
     * que la IA podría unirlo).
     *
     * @param  array $analisis  analizar_grupo() del padre
     * @return array [padre => [ref => true]]
     */
    protected static function refs_de_padre_previas(array $analisis)
    {
        $refs = [];
        $nuevos_por_fuerte = [];

        foreach ($analisis['valores'] as $padre => $info) {
            $padre = (string) $padre;
            $refs[$padre] = [];

            switch ($info['estado']) {
                case 'identico':
                    $refs[$padre]['id:' . (int) $info['id']] = true;
                    break;

                case 'recordado':
                case 'misma_escritura':
                    foreach ($info['opciones'] as $opcion) {
                        $refs[$padre]['id:' . (int) $opcion['id']] = true;
                    }

                    $refs[$padre]['nuevo:' . $padre] = true;
                    break;

                default:
                    foreach ($info['candidatos'] as $candidato) {
                        $refs[$padre]['id:' . (int) $candidato['id']] = true;
                    }

                    foreach ($info['candidatos_archivo'] as $candidato) {
                        $refs[$padre]['nuevo:' . (string) $candidato['valor']] = true;
                    }

                    $refs[$padre]['nuevo:' . $padre] = true;
                    $nuevos_por_fuerte[RelacionesParecidasDetector::clave_fuerte($padre)][] = $padre;
                    break;
            }
        }

        foreach ($nuevos_por_fuerte as $miembros) {
            usort($miembros, function ($a, $b) use ($analisis) {
                return RelacionesParecidasDetector::comparar_canonico($a, $analisis['valores'][$a]['filas'], $b, $analisis['valores'][$b]['filas']);
            });

            foreach ($miembros as $padre) {
                $refs[$padre]['nuevo:' . $miembros[0]] = true;
            }
        }

        return $refs;
    }

    /**
     * Las resoluciones que cada padre puede terminar teniendo PRESELECCIONADAS, antes de la IA. Solo
     * ordena las consultas a la IA (ver armar_consultas()): no decide nada.
     *
     * Sin parecidos es una sola: el idéntico, el recordado, el de la misma escritura más viejo o,
     * si es nuevo, el canónico de su misma escritura. Con parecidos depende de lo que conteste la
     * IA, que todavía no contestó, así que son dos: su PRIMER candidato (el que la IA confirma
     * cuando está segura; los cargados van primero porque el prompt los prefiere) y "crear" (lo
     * que queda si duda o no elige). Los demás candidatos son las resoluciones alternativas.
     *
     * @param  array $analisis  analizar_grupo() del padre
     * @return array [padre => [ref => true]]
     */
    protected static function refs_preseleccionadas_previas(array $analisis)
    {
        $refs = [];
        $nuevos_por_fuerte = [];

        foreach ($analisis['valores'] as $padre => $info) {
            $padre = (string) $padre;
            $refs[$padre] = [];

            switch ($info['estado']) {
                case 'identico':
                case 'recordado':
                    $refs[$padre]['id:' . (int) $info['id']] = true;
                    break;

                case 'misma_escritura':
                    $refs[$padre]['id:' . (int) $info['opciones'][0]['id']] = true;
                    break;

                default:
                    if (count($info['candidatos']) > 0) {
                        $refs[$padre]['id:' . (int) $info['candidatos'][0]['id']] = true;
                    } elseif (count($info['candidatos_archivo']) > 0) {
                        $refs[$padre]['nuevo:' . (string) $info['candidatos_archivo'][0]['valor']] = true;
                    }

                    $nuevos_por_fuerte[RelacionesParecidasDetector::clave_fuerte($padre)][] = $padre;
                    break;
            }
        }

        foreach ($nuevos_por_fuerte as $miembros) {
            usort($miembros, function ($a, $b) use ($analisis) {
                return RelacionesParecidasDetector::comparar_canonico($a, $analisis['valores'][$a]['filas'], $b, $analisis['valores'][$b]['filas']);
            });

            foreach ($miembros as $padre) {
                $refs[$padre]['nuevo:' . $miembros[0]] = true;
            }
        }

        return $refs;
    }

    /**
     * Las resoluciones que cada padre tiene DESPUÉS de la IA, según su ítem del paso 3: una por
     * opción (`usar` X → "id:X", `crear` → "nuevo:<el padre>", `unir` con C → "nuevo:<C>"). Un
     * padre sin ítem tiene una sola: "id:X" si es idéntico a un existente, "nuevo:<él mismo>" si no.
     *
     * @param  array $padres       los padres (como vinieron en el Excel)
     * @param  array $analisis     analizar_grupo() del padre
     * @param  array $items_padre  cerrar_grupo()['items'] del padre
     * @return array [padre => [ref => true]]
     */
    protected static function refs_de_padre_finales(array $padres, array $analisis, array $items_padre)
    {
        $por_valor = [];

        foreach ($items_padre as $item) {
            $por_valor[(string) $item['valor']] = $item;
        }

        $refs = [];

        foreach ($padres as $padre) {
            $padre = (string) $padre;
            $refs[$padre] = [];

            if (isset($por_valor[$padre])) {
                foreach ($por_valor[$padre]['opciones'] as $opcion) {
                    $refs[$padre][self::ref_de_opcion($opcion, $padre)] = true;
                }

                continue;
            }

            $info = isset($analisis['valores'][$padre]) ? $analisis['valores'][$padre] : null;

            if (!is_null($info) && $info['estado'] === 'identico') {
                $refs[$padre]['id:' . (int) $info['id']] = true;
            } else {
                $refs[$padre]['nuevo:' . $padre] = true;
            }
        }

        return $refs;
    }

    /**
     * El `padre_ref` de una opción de un ítem padre (el mismo que calcula el SPA para mostrar los
     * hijos de la opción elegida).
     *
     * @param  array  $opcion
     * @param  string $padre
     * @return string
     */
    protected static function ref_de_opcion(array $opcion, $padre)
    {
        if ($opcion['accion'] === 'usar') {
            return 'id:' . (int) $opcion['id'];
        }

        if ($opcion['accion'] === 'unir') {
            return 'nuevo:' . (string) $opcion['con'];
        }

        return 'nuevo:' . (string) $padre;
    }

    /**
     * Junta los hijos de los padres que comparten una resolución: [ref => ['padres' => [...],
     * 'valores' => [valor => filas sumadas]]]. Un padre con varias resoluciones aporta sus hijos a
     * cada una.
     *
     * @param  array $hijos  [padre => [valor => filas]]
     * @param  array $refs   [padre => [ref => true]]
     * @return array
     */
    protected static function agrupar_hijos_por_ref(array $hijos, array $refs)
    {
        $agrupados = [];

        foreach ($hijos as $padre => $valores) {
            $padre = (string) $padre;
            $refs_del_padre = isset($refs[$padre]) && count($refs[$padre]) > 0 ? $refs[$padre] : ['nuevo:' . $padre => true];

            foreach ($refs_del_padre as $ref => $nada) {
                $ref = (string) $ref;

                if (!isset($agrupados[$ref])) {
                    $agrupados[$ref] = ['padres' => [], 'valores' => []];
                }

                $agrupados[$ref]['padres'][] = $padre;

                foreach ($valores as $valor => $filas) {
                    $valor = (string) $valor;

                    if (!isset($agrupados[$ref]['valores'][$valor])) {
                        $agrupados[$ref]['valores'][$valor] = 0;
                    }

                    $agrupados[$ref]['valores'][$valor] += (int) $filas;
                }
            }
        }

        return $agrupados;
    }

    /**
     * @param  string $ref  "id:<id>" | "nuevo:<valor>"
     * @return int|null
     */
    protected static function id_de_ref($ref)
    {
        $ref = (string) $ref;

        return strpos($ref, 'id:') === 0 ? (int) substr($ref, 3) : null;
    }

    /**
     * @param  array $a
     * @param  array $b
     * @return bool
     */
    protected static function mismos_padres(array $a, array $b)
    {
        $a = array_map('strval', $a);
        $b = array_map('strval', $b);

        sort($a);
        sort($b);

        return $a === $b;
    }

    /**
     * Los ítems de un grupo de hijos se analizaron por valor, sumando las filas de todos los padres
     * del grupo; acá se abren en un ítem por cada par (padre como vino en SU fila, valor), con su
     * `padre_ref`. La clave es `tipo|padre|valor|padre_ref`: el mismo hijo aparece una vez por cada
     * resolución posible de su padre, y el SPA muestra solo la de la opción elegida.
     *
     * @param  array  $items
     * @param  array  $padres
     * @param  array  $hijos      [padre => [valor => filas]]
     * @param  string $tipo_hijo
     * @param  string $ref
     * @return array
     */
    protected static function items_por_fila(array $items, array $padres, array $hijos, $tipo_hijo, $ref)
    {
        $abiertos = [];

        foreach ($items as $item) {
            foreach ($padres as $padre) {
                $padre = (string) $padre;

                if (!isset($hijos[$padre][$item['valor']])) {
                    continue;
                }

                $copia = $item;
                $copia['padre'] = $padre;
                $copia['padre_ref'] = (string) $ref;
                $copia['filas'] = (int) $hijos[$padre][$item['valor']];
                $copia['clave'] = $tipo_hijo . '|' . $padre . '|' . $item['valor'] . '|' . $ref;

                $abiertos[] = $copia;
            }
        }

        return $abiertos;
    }

    /**
     * Analiza los hijos de una resolución del padre contra los existentes de ESE padre (o contra
     * nada si el padre es nuevo: ahí solo queda la comparación entre valores nuevos).
     *
     * @param  array     $valores
     * @param  array     $existentes_hijo  salida de existentes()
     * @param  array     $recordadas_hijo  [contexto => [clave => id]]
     * @param  int|null  $padre_id
     * @param  bool      $con_parecidos
     * @param  \stdClass $presupuesto
     * @return array
     */
    protected static function analizar_hijo(array $valores, array $existentes_hijo, array $recordadas_hijo, $padre_id, $con_parecidos, $presupuesto)
    {
        $existentes = [];
        $recordadas = [];

        if (!is_null($padre_id)) {
            foreach ($existentes_hijo as $existente) {
                if ((int) $existente['contexto'] === (int) $padre_id) {
                    $existentes[] = $existente;
                }
            }

            $recordadas = isset($recordadas_hijo[(int) $padre_id]) ? $recordadas_hijo[(int) $padre_id] : [];
        }

        return RelacionesParecidasDetector::analizar_grupo($valores, self::lista_de_existentes($existentes), $recordadas, $con_parecidos, $presupuesto);
    }

    /**
     * Arma las consultas para la IA: cada valor con candidatos existentes ("cargado") o del mismo
     * archivo ("archivo"), hasta TOPE_ITEMS_IA.
     *
     * 🔴 El orden antes de cortar: primero los valores sin padre (proveedor, categoría, marca...),
     * después los hijos bajo la resolución PRESELECCIONADA de su padre y al final los hijos bajo
     * las resoluciones alternativas; dentro de cada tramo, los de más filas primero. Con
     * `padre_ref` un padre con tres candidatos multiplica sus hijos por cuatro, y sin este orden
     * esos hijos alternativos se comían el tope y se recortaban las consultas que importan.
     *
     * Las consultas IDÉNTICAS (mismo tipo, mismo valor, mismos candidatos) van una sola vez: con los
     * hijos analizados una vez por cada resolución posible del padre, la misma pregunta aparece en
     * varios grupos, y pagarla varias veces sería tirar tokens.
     *
     * @param  array $grupos
     * @return array ['consultas' => [...], 'mapa' => [n => [['grupo' => i, 'valor' => v, 'elecciones' => [c => eleccion]], ...]],
     *                'recortado' => bool]
     */
    protected static function armar_consultas(array $grupos)
    {
        $pendientes = [];

        foreach ($grupos as $i => $grupo) {
            foreach ($grupo['analisis']['valores'] as $valor => $info) {
                if (!in_array($info['estado'], ['consultar', 'nuevo'], true)) {
                    continue;
                }

                if (count($info['candidatos']) === 0 && count($info['candidatos_archivo']) === 0) {
                    continue;
                }

                $candidatos = [];
                $elecciones = [];
                $c = 0;

                foreach ($info['candidatos'] as $candidato) {
                    $c++;
                    $candidatos[] = ['c' => $c, 'nombre' => $candidato['nombre'], 'origen' => 'cargado'];
                    $elecciones[$c] = ['existente' => (int) $candidato['id']];
                }

                foreach ($info['candidatos_archivo'] as $candidato) {
                    $c++;
                    $candidatos[] = ['c' => $c, 'nombre' => $candidato['valor'], 'origen' => 'archivo'];
                    $elecciones[$c] = ['archivo' => (string) $candidato['valor']];
                }

                $clave = $grupo['tipo'] . "\x1F" . (string) $valor . "\x1F" . json_encode($candidatos);

                $prioridad = isset($grupo['prioridad']) ? (int) $grupo['prioridad'] : 0;

                if (!isset($pendientes[$clave])) {
                    $pendientes[$clave] = [
                        'tipo'       => $grupo['tipo'],
                        'valor'      => (string) $valor,
                        'filas'      => 0,
                        'prioridad'  => $prioridad,
                        'candidatos' => $candidatos,
                        'entradas'   => [],
                    ];
                }

                $pendientes[$clave]['filas'] = max($pendientes[$clave]['filas'], (int) $info['filas']);
                $pendientes[$clave]['prioridad'] = min($pendientes[$clave]['prioridad'], $prioridad);
                $pendientes[$clave]['entradas'][] = ['grupo' => $i, 'valor' => (string) $valor, 'elecciones' => $elecciones];
            }
        }

        $pendientes = array_values($pendientes);

        usort($pendientes, function ($a, $b) {
            if ($a['prioridad'] !== $b['prioridad']) {
                return $a['prioridad'] - $b['prioridad'];
            }

            if ($a['filas'] !== $b['filas']) {
                return $b['filas'] - $a['filas'];
            }

            return strcmp($a['valor'], $b['valor']);
        });

        $recortado = count($pendientes) > self::TOPE_ITEMS_IA;

        $pendientes = array_slice($pendientes, 0, self::TOPE_ITEMS_IA);

        $consultas = [];
        $mapa = [];
        $n = 0;

        foreach ($pendientes as $pendiente) {
            $n++;

            $consultas[] = [
                'n'          => $n,
                'tipo'       => $pendiente['tipo'],
                'valor'      => $pendiente['valor'],
                'candidatos' => $pendiente['candidatos'],
            ];

            $mapa[$n] = $pendiente['entradas'];
        }

        return ['consultas' => $consultas, 'mapa' => $mapa, 'recortado' => $recortado];
    }

    /**
     * Traduce [n => ['c', 'seguro'] | null] a, por grupo, [valor => eleccion] y [valor => enviado].
     * La elección lleva su `seguro`: ['existente' => id, 'seguro' => bool] o
     * ['archivo' => valor, 'seguro' => bool].
     *
     * @param  array $mapa
     * @param  array $respuestas_ia
     * @return array [grupo => ['respuestas' => [...], 'enviados' => [...]]]
     */
    protected static function respuestas_por_grupo(array $mapa, array $respuestas_ia)
    {
        $por_grupo = [];

        foreach ($mapa as $n => $entradas) {
            foreach ($entradas as $entrada) {
                $i = $entrada['grupo'];

                if (!isset($por_grupo[$i])) {
                    $por_grupo[$i] = ['respuestas' => [], 'enviados' => []];
                }

                $por_grupo[$i]['enviados'][$entrada['valor']] = true;

                if (!array_key_exists($n, $respuestas_ia)) {
                    continue;
                }

                $respuesta = $respuestas_ia[$n];

                if (!is_array($respuesta) || !isset($respuesta['c']) || !isset($entrada['elecciones'][$respuesta['c']])) {
                    $por_grupo[$i]['respuestas'][$entrada['valor']] = null;
                    continue;
                }

                $por_grupo[$i]['respuestas'][$entrada['valor']] = $entrada['elecciones'][$respuesta['c']] + [
                    'seguro' => isset($respuesta['seguro']) && $respuesta['seguro'] === true,
                ];
            }
        }

        return $por_grupo;
    }

    /* =====================================================================
     * Lectura del Excel y de la base
     * ================================================================== */

    /**
     * Mapa propiedad => índice de columna (0-based) a partir del column_mapping del paso 2.
     *
     * @param  array $payload
     * @return array
     */
    protected static function mapa_de_columnas(array $payload)
    {
        $mapa = [];

        $column_mapping = isset($payload['column_mapping']) && is_array($payload['column_mapping'])
            ? $payload['column_mapping']
            : [];

        foreach ($column_mapping as $columna) {
            if (!is_array($columna)) {
                continue;
            }

            $propiedad = isset($columna['system_property']) && is_string($columna['system_property'])
                ? trim($columna['system_property'])
                : '';

            if ($propiedad === '' || !isset($columna['excel_column_index']) || !is_numeric($columna['excel_column_index'])) {
                continue;
            }

            /* Si dos columnas dicen ser la misma propiedad, gana la primera (como en el normalizador). */
            if (!isset($mapa[$propiedad])) {
                $mapa[$propiedad] = (int) $columna['excel_column_index'];
            }
        }

        return $mapa;
    }

    /**
     * Recorre las filas de datos de la hoja elegida, debajo del encabezado (el mismo que usó el
     * paso 2: el corregido a mano o el detectado), y le pasa cada una a $procesar como array de
     * celdas 0-based, igual que las lee la importación.
     *
     * @param  string   $excel_full_path
     * @param  array    $payload
     * @param  callable $procesar
     * @return void
     */
    protected static function recorrer_filas($excel_full_path, array $payload, $procesar)
    {
        $hoja = isset($payload['hoja']) && is_numeric($payload['hoja']) ? (int) $payload['hoja'] : 0;

        $fila_encabezado = isset($payload['header_row']) && is_numeric($payload['header_row']) && (int) $payload['header_row'] >= 1
            ? (int) $payload['header_row']
            : (int) ExcelHeaderDetector::detectar_en($excel_full_path, $hoja)['fila'];

        /* Con las filas vacías preservadas: el contador es la fila física, la misma del detector. */
        $lectura = ExcelWorkbookReader::abrir($excel_full_path, $hoja, true);

        try {
            $numero = 0;

            foreach ($lectura->filas() as $fila) {
                $numero++;

                if ($numero <= $fila_encabezado) {
                    continue;
                }

                $celdas = [];

                foreach ($fila->getCells() as $celda) {
                    $valor = $celda->getValue();

                    if ($valor instanceof \DateTime) {
                        $valor = $valor->format('Y-m-d');
                    }

                    $celdas[] = $valor;
                }

                call_user_func($procesar, $celdas);
            }
        } finally {
            $lectura->cerrar();
        }
    }

    /**
     * Suma una aparición del valor (ya con trim, como lo deja ImportHelper::getColumnValue()).
     *
     * @param  array $acumulado
     * @param  mixed $valor
     * @return void
     */
    protected static function sumar(array &$acumulado, $valor)
    {
        if (is_null($valor)) {
            return;
        }

        $valor = trim((string) $valor);

        if ($valor === '') {
            return;
        }

        if (!isset($acumulado[$valor])) {
            $acumulado[$valor] = 0;
        }

        $acumulado[$valor]++;
    }

    /**
     * null para lo que la importación de clientes y proveedores ignora ("Sin especificar").
     *
     * @param  mixed $valor
     * @return string|null
     */
    protected static function util($valor)
    {
        if (is_null($valor) || $valor === '' || $valor === self::SIN_ESPECIFICAR) {
            return null;
        }

        return $valor;
    }

    /**
     * Los modelos de un tipo del comercio, sin borrados, ordenados por id.
     *
     * @param  int    $user_id
     * @param  string $tipo
     * @return array [['id' => int, 'nombre' => string, 'contexto' => int], ...]
     */
    protected static function existentes($user_id, $tipo)
    {
        $consulta = EquivalenciasResolver::consulta_del_tipo($tipo, $user_id);

        if (is_null($consulta)) {
            return [];
        }

        $existentes = [];

        foreach ($consulta->get() as $modelo) {
            $existentes[] = [
                'id'       => (int) $modelo->id,
                'nombre'   => (string) $modelo->name,
                'contexto' => EquivalenciasResolver::contexto_del_modelo($tipo, $modelo),
            ];
        }

        return $existentes;
    }

    /**
     * @param  array $existentes
     * @return array [['id' => int, 'nombre' => string], ...]
     */
    protected static function lista_de_existentes(array $existentes)
    {
        $lista = [];

        foreach ($existentes as $existente) {
            $lista[] = ['id' => $existente['id'], 'nombre' => $existente['nombre']];
        }

        return $lista;
    }
}
