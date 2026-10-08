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
 * Las subcategorías se comparan DENTRO de su categoría: la que resolvería la fila con la opción
 * preseleccionada. Como esa opción puede depender de la IA, las subcategorías se analizan contra
 * la categoría provisional (la de antes de la IA) y, si la IA termina eligiendo otra, se rehacen
 * contra la final solo con la capa determinista. Igual para localidad dentro de provincia.
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
     * @return array ['ia_consultada' => bool, 'items' => [...]]
     */
    protected static function chequear($user_id, array $simples, $tipo_padre, $tipo_hijo, array $hijos)
    {
        $grupos = [];

        foreach ($simples as $tipo => $valores) {
            $existentes = self::existentes($user_id, $tipo);
            $recordadas = EquivalenciasResolver::recordadas_del_tipo($user_id, $tipo);

            $grupos[] = [
                'tipo'     => $tipo,
                'padre'    => null,
                'padre_id' => null,
                'analisis' => RelacionesParecidasDetector::analizar_grupo(
                    $valores,
                    self::lista_de_existentes($existentes),
                    isset($recordadas[0]) ? $recordadas[0] : []
                ),
            ];
        }

        /* Hijos (subcategorías, localidades con provincia): contra el padre PROVISIONAL. */
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

            foreach ($hijos as $padre => $valores) {
                $padre = (string) $padre;

                $padre_id = is_null($indice_padre)
                    ? null
                    : self::padre_provisional($grupos[$indice_padre]['analisis'], $padre);

                $grupos[] = [
                    'tipo'     => $tipo_hijo,
                    'padre'    => $padre,
                    'padre_id' => $padre_id,
                    'analisis' => self::analizar_hijo($valores, $existentes_hijo, $recordadas_hijo, $tipo_hijo, $padre_id, true),
                ];
            }
        }

        /* Una sola llamada a la IA para todo. */
        $consultas = self::armar_consultas($grupos);

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
        $supuestos_padre = [];

        /* Primero los grupos sin padre: de ahí sale la categoría (o provincia) final de cada fila. */
        foreach ($grupos as $i => $grupo) {
            if (!is_null($grupo['padre'])) {
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
                $supuestos_padre = $cerrado['supuestos'];
            }

            $items[$grupo['tipo']] = $cerrado['items'];
        }

        $items_hijos = [];

        foreach ($grupos as $i => $grupo) {
            if (is_null($grupo['padre'])) {
                continue;
            }

            $padre = $grupo['padre'];
            $final = array_key_exists($padre, $supuestos_padre) ? $supuestos_padre[$padre] : null;

            $analisis   = $grupo['analisis'];
            $respuestas = isset($por_grupo[$i]) ? $por_grupo[$i]['respuestas'] : [];
            $enviados   = isset($por_grupo[$i]) ? $por_grupo[$i]['enviados'] : [];

            if ($final !== $grupo['padre_id']) {
                /*
                 * La IA eligió para el padre otra cosa que la provisional: lo que se comparó contra
                 * la provisional no vale. Se rehace contra la final, solo determinista.
                 */
                $analisis = self::analizar_hijo(
                    self::valores_del_analisis($analisis),
                    $existentes_hijo,
                    $recordadas_hijo,
                    $tipo_hijo,
                    $final,
                    false
                );
                $respuestas = [];
                $enviados = [];
            }

            $cerrado = RelacionesParecidasDetector::cerrar_grupo(
                $tipo_hijo,
                $analisis,
                $respuestas,
                $enviados,
                $ia_ok,
                $padre,
                $final
            );

            $items_hijos = array_merge($items_hijos, $cerrado['items']);
        }

        if (!is_null($tipo_hijo)) {
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
            'items'         => $todos,
        ];
    }

    /**
     * El id del padre que la fila resolvería ANTES de la IA (para comparar sus hijos): el idéntico,
     * el recordado, el de la misma escritura más viejo o el primer candidato parecido.
     *
     * @param  array  $analisis
     * @param  string $padre
     * @return int|null
     */
    protected static function padre_provisional(array $analisis, $padre)
    {
        if (!isset($analisis['valores'][$padre])) {
            return null;
        }

        $info = $analisis['valores'][$padre];

        switch ($info['estado']) {
            case 'identico':
            case 'recordado':
                return (int) $info['id'];
            case 'misma_escritura':
                return (int) $info['opciones'][0]['id'];
            case 'consultar':
                return (int) $info['candidatos'][0]['id'];
        }

        return null;
    }

    /**
     * Analiza los hijos de un padre contra los existentes de ESE padre (o contra nada si el padre
     * es nuevo: ahí solo queda la comparación entre valores nuevos).
     *
     * @param  array    $valores
     * @param  array    $existentes_hijo  salida de existentes()
     * @param  array    $recordadas_hijo  [contexto => [clave => id]]
     * @param  string   $tipo_hijo
     * @param  int|null $padre_id
     * @param  bool     $con_parecidos
     * @return array
     */
    protected static function analizar_hijo(array $valores, array $existentes_hijo, array $recordadas_hijo, $tipo_hijo, $padre_id, $con_parecidos)
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

        return RelacionesParecidasDetector::analizar_grupo($valores, self::lista_de_existentes($existentes), $recordadas, $con_parecidos);
    }

    /**
     * @param  array $analisis
     * @return array [valor => filas]
     */
    protected static function valores_del_analisis(array $analisis)
    {
        $valores = [];

        foreach ($analisis['valores'] as $valor => $info) {
            $valores[(string) $valor] = (int) $info['filas'];
        }

        return $valores;
    }

    /**
     * Arma las consultas para la IA: cada valor con candidatos existentes ("cargado") o del mismo
     * archivo ("archivo"). Los de más filas primero, hasta TOPE_ITEMS_IA.
     *
     * @param  array $grupos
     * @return array ['consultas' => [...], 'mapa' => [n => ['grupo' => i, 'valor' => v, 'c' => [c => eleccion]]]]
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

                $pendientes[] = ['grupo' => $i, 'valor' => (string) $valor, 'info' => $info, 'tipo' => $grupo['tipo']];
            }
        }

        usort($pendientes, function ($a, $b) {
            if ($a['info']['filas'] !== $b['info']['filas']) {
                return $b['info']['filas'] - $a['info']['filas'];
            }

            return strcmp($a['valor'], $b['valor']);
        });

        $pendientes = array_slice($pendientes, 0, self::TOPE_ITEMS_IA);

        $consultas = [];
        $mapa = [];
        $n = 0;

        foreach ($pendientes as $pendiente) {
            $n++;
            $c = 0;
            $candidatos = [];
            $elecciones = [];

            foreach ($pendiente['info']['candidatos'] as $candidato) {
                $c++;
                $candidatos[] = ['c' => $c, 'nombre' => $candidato['nombre'], 'origen' => 'cargado'];
                $elecciones[$c] = ['existente' => (int) $candidato['id']];
            }

            foreach ($pendiente['info']['candidatos_archivo'] as $candidato) {
                $c++;
                $candidatos[] = ['c' => $c, 'nombre' => $candidato['valor'], 'origen' => 'archivo'];
                $elecciones[$c] = ['archivo' => (string) $candidato['valor']];
            }

            $consultas[] = [
                'n'          => $n,
                'tipo'       => $pendiente['tipo'],
                'valor'      => $pendiente['valor'],
                'candidatos' => $candidatos,
            ];

            $mapa[$n] = [
                'grupo'      => $pendiente['grupo'],
                'valor'      => $pendiente['valor'],
                'elecciones' => $elecciones,
            ];
        }

        return ['consultas' => $consultas, 'mapa' => $mapa];
    }

    /**
     * Traduce [n => c|null] a, por grupo, [valor => eleccion] y [valor => enviado].
     *
     * @param  array $mapa
     * @param  array $respuestas_ia
     * @return array [grupo => ['respuestas' => [...], 'enviados' => [...]]]
     */
    protected static function respuestas_por_grupo(array $mapa, array $respuestas_ia)
    {
        $por_grupo = [];

        foreach ($mapa as $n => $entrada) {
            $i = $entrada['grupo'];

            if (!isset($por_grupo[$i])) {
                $por_grupo[$i] = ['respuestas' => [], 'enviados' => []];
            }

            $por_grupo[$i]['enviados'][$entrada['valor']] = true;

            if (!array_key_exists($n, $respuestas_ia)) {
                continue;
            }

            $c = $respuestas_ia[$n];

            $por_grupo[$i]['respuestas'][$entrada['valor']] = (is_null($c) || !isset($entrada['elecciones'][$c]))
                ? null
                : $entrada['elecciones'][$c];
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
