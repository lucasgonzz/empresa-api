<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\CatalogoDeDatosIaHelper;
use Illuminate\Support\Facades\DB;

/**
 * LA AGREGACIÓN GENÉRICA DEL ASISTENTE: sumar, contar, promediar, mínimo y máximo, agrupando por
 * un campo, por una relación (con su etiqueta) o por período.
 *
 * Misión asistente-omnisciente (21/9/2026). Es la respuesta a la pregunta que originó la misión:
 * "¿qué producto le compro más a EL MAYORISTA DEL NORTE?" es `renglon_de_compra`, filtro
 * `provider_id contiene "mayorista del norte"`, agrupar por `article_id`, métricas `suma amount` y
 * `suma importe`. Hasta hoy `consultar_datos` paginaba filas y el modelo intentaba sumarlas a mano
 * sobre una página de veinte, que es la forma más segura de contestar mal con cara de exactitud.
 *
 * 🔴 `suma importe`, NO `suma cost`. La segunda pregunta de esa misma conversación —"¿y en plata?"—
 * se contestó con `SUM(cost)`, que suma COSTOS UNITARIOS: dio $8.130 donde la verdad era
 * $257.596,80. Un renglón guarda las unidades y el costo de una unidad en columnas distintas y la
 * plata no es ninguna de las dos, así que ahora el esquema declara `importe` como campo calculado
 * (`amount * cost`, con el descuento del renglón) y esta agregación lo suma como a cualquier otro
 * campo `number`. El detalle del mecanismo está en EsquemaDeDatosIaHelper.
 *
 * 🔴 MISMO CONJUNTO DE FILAS QUE consultar_datos: la consulta base (scope por dueño, condiciones
 * fijas, filtros traducidos) sale de CatalogoDeDatosIaHelper::consulta_base(). No hay una segunda
 * traducción de filtros acá: lo que se lista y lo que se suma es lo mismo por construcción.
 *
 * 🔴 LOS PESOS NO SE SUMAN CON LOS DÓLARES SIN AVISAR. Si la entidad tiene `moneda_id` (propio o
 * del padre) y ningún filtro lo nombra, la respuesta trae `en_otra_moneda` con cuántos registros
 * del conjunto filtrado no están en pesos (RecolectorBase::MONEDAS_PESOS más null): el modelo
 * puede volver a llamar con el filtro de moneda, pero no puede no enterarse.
 */
class ResumenDeDatosIaHelper
{
    /** Cuántos grupos viajan si el modelo no pide otra cosa. */
    const LIMITE_DEFAULT = 20;

    /** Techo duro de grupos por respuesta. */
    const TOPE_DE_GRUPOS = 100;

    /** Cuántos campos se pueden agrupar a la vez. */
    const MAX_CAMPOS_DE_AGRUPACION = 2;

    /** Funciones de agregación y el SQL de cada una. */
    const FUNCIONES = [
        'conteo'   => 'COUNT(*)',
        'suma'     => 'SUM(%s)',
        'promedio' => 'AVG(%s)',
        'minimo'   => 'MIN(%s)',
        'maximo'   => 'MAX(%s)',
    ];

    /** Períodos válidos de un campo de fecha y su expresión SQL. */
    const PERIODOS = [
        'dia'    => 'DATE(%s)',
        'semana' => 'YEARWEEK(%s, 3)',
        'mes'    => "DATE_FORMAT(%s, '%%Y-%%m')",
        'anio'   => 'YEAR(%s)',
    ];

    /**
     * RESUMIR.
     *
     * @param  int          $owner_id     Dueño. Nunca Auth: corre en un job sin sesión.
     * @param  string       $entidad      Una del catálogo.
     * @param  array        $filtros      Los mismos de consultar_datos.
     * @param  array        $agrupar_por  0 a 2 de [['campo' => 'created_at', 'por' => 'mes'], ['campo' => 'article_id']].
     * @param  array        $metricas     [['funcion' => 'suma', 'campo' => 'amount'], ['funcion' => 'conteo']]; vacío = conteo.
     * @param  array|null   $orden        ['por' => 'suma_amount' | 'grupo', 'direccion' => 'DESC']; default la primera métrica DESC.
     * @param  int          $limite       0 = LIMITE_DEFAULT; tope TOPE_DE_GRUPOS.
     * @return array<string, mixed>  Con la clave `error` cuando el pedido no se puede atender
     */
    public static function resumir(int $owner_id, string $entidad, array $filtros = [], array $agrupar_por = [], array $metricas = [], $orden = null, int $limite = 0): array
    {
        $entidad = trim($entidad);

        $declaracion = EsquemaDeDatosIaHelper::declaracion($entidad);

        if (is_null($declaracion)) {
            return CatalogoDeDatosIaHelper::no_existe_la_entidad($entidad);
        }

        $base = CatalogoDeDatosIaHelper::consulta_base($owner_id, $declaracion, $filtros);

        if (isset($base['error'])) {
            return $base;
        }

        $grupos = self::traducir_agrupacion($declaracion, $agrupar_por);

        if (isset($grupos['error'])) {
            return $grupos;
        }

        $medidas = self::traducir_metricas($declaracion, $metricas);

        if (isset($medidas['error'])) {
            return $medidas;
        }

        $orden_traducido = self::traducir_orden($declaracion, $grupos, $medidas, $orden);

        if (isset($orden_traducido['error'])) {
            return $orden_traducido;
        }

        if ($limite <= 0) {
            $limite = self::LIMITE_DEFAULT;
        }

        if ($limite > self::TOPE_DE_GRUPOS) {
            $limite = self::TOPE_DE_GRUPOS;
        }

        /** @var \Illuminate\Database\Query\Builder $query */
        $query = $base['query'];

        // El total general: las mismas métricas sin agrupar, sobre el conjunto filtrado entero.
        $total_general = self::fila_de_metricas(
            (clone $query)->selectRaw(self::select_de_metricas($medidas))->first(),
            $medidas
        );

        $respuesta = [
            'entidad'           => $entidad,
            'etiqueta'          => $declaracion['etiqueta'],
            'filtros_aplicados' => $base['aplicados'],
            'metricas'          => array_keys($medidas),
        ];

        if (empty($grupos)) {
            // Sin agrupar es el "cuánto suman" genérico: una sola fila de totales.
            $respuesta['agrupado_por'] = [];
            $respuesta['total_general'] = $total_general;
        } else {
            $respuesta['agrupado_por'] = array_map(function ($grupo) {
                return isset($grupo['por']) ? ['campo' => $grupo['campo'], 'por' => $grupo['por']] : ['campo' => $grupo['campo']];
            }, $grupos);

            $respuesta['orden'] = $orden_traducido['declarado'];

            $agrupada = (clone $query);

            foreach ($grupos as $indice => $grupo) {
                $agrupada->groupByRaw($grupo['sql']);
            }

            // Cuántos grupos hay EN TOTAL, antes del tope: el modelo tiene que saber si le faltan.
            $respuesta['grupos_encontrados'] = self::contar_grupos($agrupada, $grupos);

            $select = [];

            foreach ($grupos as $indice => $grupo) {
                $select[] = $grupo['sql'] . ' as `grupo_' . $indice . '`';
            }

            $select[] = self::select_de_metricas($medidas);

            $filas = $agrupada
                ->selectRaw(implode(', ', $select))
                ->orderByRaw($orden_traducido['sql'])
                ->limit($limite)
                ->get();

            $etiquetas = self::etiquetas_de_los_grupos($grupos, $filas);

            $lista = [];

            foreach ($filas as $fila) {
                $lista[] = self::fila_de_grupo($fila, $grupos, $medidas, $etiquetas);
            }

            $respuesta['grupos_en_esta_lista'] = count($lista);
            $respuesta['grupos'] = $lista;
            $respuesta['total_general'] = $total_general;
        }

        $en_otra_moneda = self::en_otra_moneda($declaracion, $base['aplicados'], $query);

        if (! is_null($en_otra_moneda)) {
            $respuesta['en_otra_moneda'] = $en_otra_moneda;
        }

        $en_dolares = self::en_dolares_por_renglon($declaracion, $base['aplicados'], $query);

        if (! is_null($en_dolares)) {
            $respuesta['importe_en_dolares'] = $en_dolares;
        }

        return $respuesta;
    }

    /**
     * Los campos de agrupación traducidos a expresiones SQL.
     *
     * - Un campo `date` puede llevar `por` (dia | semana | mes | anio); sin `por`, se agrupa por día.
     * - Un campo `search` se agrupa por el id y después se le pone la etiqueta.
     * - `por` sobre un campo que no es fecha es un error, no se ignora.
     *
     * @param  array  $declaracion
     * @param  array  $agrupar_por
     * @return array<int, array<string, mixed>>|array{error: string}
     */
    protected static function traducir_agrupacion(array $declaracion, array $agrupar_por): array
    {
        if (count($agrupar_por) > self::MAX_CAMPOS_DE_AGRUPACION) {
            return CatalogoDeDatosIaHelper::error('Se puede agrupar por ' . self::MAX_CAMPOS_DE_AGRUPACION . ' campos como maximo.', $declaracion);
        }

        $grupos = [];

        foreach ($agrupar_por as $grupo) {
            if (is_string($grupo)) {
                $grupo = ['campo' => $grupo];
            }

            if (! is_array($grupo) || ! isset($grupo['campo'])) {
                return CatalogoDeDatosIaHelper::error('Cada elemento de agrupar_por tiene que ser un objeto con `campo` (y `por` si es una fecha).', $declaracion);
            }

            $campo = trim((string) $grupo['campo']);
            $tipo = CatalogoDeDatosIaHelper::tipo_del_campo($declaracion, $campo);

            if (is_null($tipo)) {
                return CatalogoDeDatosIaHelper::error('No se puede agrupar por "' . $campo . '": la entidad no tiene ese campo.', $declaracion);
            }

            $col = CatalogoDeDatosIaHelper::columna_sql($declaracion, $campo);
            $por = isset($grupo['por']) && trim((string) $grupo['por']) !== '' ? strtolower(trim((string) $grupo['por'])) : null;

            if (! is_null($por) && $tipo !== 'date') {
                return CatalogoDeDatosIaHelper::error('`por` (' . $por . ') solo vale para un campo de fecha, y "' . $campo . '" es de tipo ' . $tipo . '.', $declaracion);
            }

            if ($tipo === 'date') {
                if (is_null($por)) {
                    $por = 'dia';
                }

                if (! isset(self::PERIODOS[$por])) {
                    return CatalogoDeDatosIaHelper::error('El periodo "' . $por . '" no existe: va dia, semana, mes o anio.', $declaracion);
                }

                $sql = sprintf(self::PERIODOS[$por], $col);
            } else {
                $sql = $col;
            }

            $grupos[] = [
                'campo'    => $campo,
                'tipo'     => $tipo,
                'por'      => $por,
                'sql'      => $sql,
                'relacion' => $tipo === 'search' ? CatalogoDeDatosIaHelper::relacion_del_campo($declaracion, $campo) : null,
            ];
        }

        return $grupos;
    }

    /**
     * Las métricas traducidas: alias => ['sql' => ..., 'funcion' => ..., 'campo' => ...].
     *
     * `suma`, `promedio`, `minimo` y `maximo` solo sobre campos `number`; `minimo` y `maximo`
     * también sobre `date`. Sin métricas, `conteo`.
     *
     * @param  array  $declaracion
     * @param  array  $metricas
     * @return array<string, array<string, mixed>>|array{error: string}
     */
    protected static function traducir_metricas(array $declaracion, array $metricas): array
    {
        if (empty($metricas)) {
            $metricas = [['funcion' => 'conteo']];
        }

        $medidas = [];

        foreach ($metricas as $metrica) {
            if (is_string($metrica)) {
                $metrica = ['funcion' => $metrica];
            }

            if (! is_array($metrica) || ! isset($metrica['funcion'])) {
                return CatalogoDeDatosIaHelper::error('Cada metrica tiene que ser un objeto con `funcion` (conteo, suma, promedio, minimo, maximo) y `campo` salvo para conteo.', $declaracion);
            }

            $funcion = strtolower(trim((string) $metrica['funcion']));

            if (! isset(self::FUNCIONES[$funcion])) {
                return CatalogoDeDatosIaHelper::error('La funcion "' . $funcion . '" no existe: va conteo, suma, promedio, minimo o maximo.', $declaracion);
            }

            if ($funcion === 'conteo') {
                $medidas['conteo'] = ['sql' => self::FUNCIONES['conteo'], 'funcion' => 'conteo', 'campo' => null, 'tipo' => 'number'];
                continue;
            }

            $campo = isset($metrica['campo']) ? trim((string) $metrica['campo']) : '';

            if ($campo === '') {
                return CatalogoDeDatosIaHelper::error('La metrica "' . $funcion . '" necesita un `campo`.', $declaracion);
            }

            $tipo = CatalogoDeDatosIaHelper::tipo_del_campo($declaracion, $campo);

            if (is_null($tipo)) {
                return CatalogoDeDatosIaHelper::error('No se puede calcular "' . $funcion . '" de "' . $campo . '": la entidad no tiene ese campo.', $declaracion);
            }

            $vale = $tipo === 'number' || ($tipo === 'date' && ($funcion === 'minimo' || $funcion === 'maximo'));

            if (! $vale) {
                return CatalogoDeDatosIaHelper::error(
                    'No se puede calcular "' . $funcion . '" de "' . $campo . '": es de tipo ' . $tipo . ' y ' . $funcion . ' va sobre campos numericos' . ($funcion === 'minimo' || $funcion === 'maximo' ? ' o de fecha' : '') . '. Para contar registros usa conteo.',
                    $declaracion
                );
            }

            $medidas[$funcion . '_' . $campo] = [
                'sql'     => sprintf(self::FUNCIONES[$funcion], CatalogoDeDatosIaHelper::columna_sql($declaracion, $campo)),
                'funcion' => $funcion,
                'campo'   => $campo,
                'tipo'    => $tipo,
            ];
        }

        return $medidas;
    }

    /**
     * El orden: por un alias de métrica o por `grupo`; default la primera métrica DESC.
     *
     * @param  array       $declaracion
     * @param  array       $grupos
     * @param  array       $medidas
     * @param  array|null  $orden
     * @return array<string, string>|array{error: string}
     */
    protected static function traducir_orden(array $declaracion, array $grupos, array $medidas, $orden): array
    {
        $por = null;
        $direccion = 'DESC';

        if (is_array($orden) && isset($orden['por']) && trim((string) $orden['por']) !== '') {
            $por = trim((string) $orden['por']);

            if (isset($orden['direccion'])) {
                $direccion = strtoupper(trim((string) $orden['direccion'])) === 'ASC' ? 'ASC' : 'DESC';
            }
        }

        if (is_null($por)) {
            $alias = array_keys($medidas);
            $por = $alias[0];
        }

        if ($por === 'grupo') {
            $tramos = [];

            foreach ($grupos as $indice => $grupo) {
                $tramos[] = '`grupo_' . $indice . '` ' . $direccion;
            }

            if (empty($tramos)) {
                return CatalogoDeDatosIaHelper::error('No se puede ordenar por grupo sin agrupar_por.', $declaracion);
            }

            return ['sql' => implode(', ', $tramos), 'declarado' => 'grupo ' . $direccion];
        }

        if (! isset($medidas[$por])) {
            return CatalogoDeDatosIaHelper::error(
                'No se puede ordenar por "' . $por . '": las metricas de esta consulta son ' . implode(', ', array_keys($medidas)) . ' (o "grupo").',
                $declaracion
            );
        }

        return ['sql' => '`' . $por . '` ' . $direccion, 'declarado' => $por . ' ' . $direccion];
    }

    /**
     * @param  array  $medidas
     * @return string
     */
    protected static function select_de_metricas(array $medidas): string
    {
        $partes = [];

        foreach ($medidas as $alias => $medida) {
            $partes[] = $medida['sql'] . ' as `' . $alias . '`';
        }

        return implode(', ', $partes);
    }

    /**
     * Cuántos grupos distintos hay, contando sobre la consulta agrupada como subconsulta (un
     * COUNT(DISTINCT a, b) saltearía las filas con NULL, que GROUP BY sí agrupa).
     *
     * @param  \Illuminate\Database\Query\Builder  $agrupada
     * @param  array  $grupos
     * @return int
     */
    protected static function contar_grupos($agrupada, array $grupos): int
    {
        $select = [];

        foreach ($grupos as $indice => $grupo) {
            $select[] = $grupo['sql'] . ' as `grupo_' . $indice . '`';
        }

        $sub = (clone $agrupada)->selectRaw(implode(', ', $select));

        return (int) DB::query()->fromSub($sub, 'grupos_del_resumen')->count();
    }

    /**
     * Las etiquetas de los grupos que son relaciones, en una consulta por relación.
     *
     * @param  array  $grupos
     * @param  mixed  $filas
     * @return array<int, array<int, string>>  índice del grupo => [id => etiqueta]
     */
    protected static function etiquetas_de_los_grupos(array $grupos, $filas): array
    {
        $etiquetas = [];

        foreach ($grupos as $indice => $grupo) {
            if (is_null($grupo['relacion'])) {
                continue;
            }

            $ids = [];

            foreach ($filas as $fila) {
                $valor = $fila->{'grupo_' . $indice};

                if (! is_null($valor) && (int) $valor > 0) {
                    $ids[] = (int) $valor;
                }
            }

            $etiquetas[$indice] = CatalogoDeDatosIaHelper::etiquetas_de_relacion($grupo['relacion'], array_values(array_unique($ids)));
        }

        return $etiquetas;
    }

    /**
     * Una fila de grupo legible: `grupo` (el valor, o un objeto campo => valor con dos campos),
     * `grupo_etiqueta` (para relaciones) y las métricas.
     *
     * @param  object  $fila
     * @param  array   $grupos
     * @param  array   $medidas
     * @param  array   $etiquetas
     * @return array<string, mixed>
     */
    protected static function fila_de_grupo($fila, array $grupos, array $medidas, array $etiquetas): array
    {
        $valores = [];
        $nombres = [];

        foreach ($grupos as $indice => $grupo) {
            $crudo = $fila->{'grupo_' . $indice};

            $valores[$grupo['campo']] = self::valor_del_grupo($crudo, $grupo);

            if (! is_null($grupo['relacion'])) {
                $nombres[$grupo['campo']] = CatalogoDeDatosIaHelper::etiqueta_de($etiquetas[$indice], $crudo);
            }
        }

        if (count($grupos) === 1) {
            $grupo = $grupos[0];
            $registro = ['grupo' => $valores[$grupo['campo']]];

            if (! is_null($grupo['relacion'])) {
                $registro['grupo_etiqueta'] = $nombres[$grupo['campo']];
            }
        } else {
            $registro = ['grupo' => $valores];

            if (! empty($nombres)) {
                $registro['grupo_etiqueta'] = $nombres;
            }
        }

        return array_merge($registro, self::fila_de_metricas($fila, $medidas));
    }

    /**
     * El valor de un grupo como lo lee la persona: la semana como "2026-S38", el día como
     * AAAA-MM-DD, un id como entero, el resto tal cual.
     *
     * @param  mixed  $crudo
     * @param  array  $grupo
     * @return mixed
     */
    protected static function valor_del_grupo($crudo, array $grupo)
    {
        if (is_null($crudo)) {
            return null;
        }

        if ($grupo['tipo'] === 'date') {
            if ($grupo['por'] === 'semana') {
                $texto = (string) $crudo;

                return substr($texto, 0, 4) . '-S' . substr($texto, 4);
            }

            if ($grupo['por'] === 'anio') {
                return (int) $crudo;
            }

            return (string) $crudo;
        }

        if ($grupo['tipo'] === 'search') {
            return (int) $crudo;
        }

        return CatalogoDeDatosIaHelper::valor_legible($crudo, $grupo['tipo']);
    }

    /**
     * Las métricas de una fila, casteadas: conteo entero, el resto float con dos decimales (o la
     * fecha legible para minimo/maximo de una fecha).
     *
     * @param  object|null  $fila
     * @param  array        $medidas
     * @return array<string, mixed>
     */
    protected static function fila_de_metricas($fila, array $medidas): array
    {
        $registro = [];

        foreach ($medidas as $alias => $medida) {
            $valor = is_null($fila) ? null : $fila->{$alias};

            if ($alias === 'conteo') {
                $registro[$alias] = (int) $valor;
            } elseif (is_null($valor)) {
                $registro[$alias] = null;
            } elseif ($medida['tipo'] === 'date') {
                $registro[$alias] = CatalogoDeDatosIaHelper::valor_legible($valor, 'date');
            } else {
                $registro[$alias] = round((float) $valor, 2);
            }
        }

        return $registro;
    }

    /**
     * El aviso de otra moneda: si la entidad tiene `moneda_id` y ningún filtro lo nombra, cuántos
     * registros del conjunto filtrado NO están en pesos. Null cuando no aplica o cuando son cero.
     *
     * @param  array  $declaracion
     * @param  array  $aplicados
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return array<string, mixed>|null
     */
    protected static function en_otra_moneda(array $declaracion, array $aplicados, $query)
    {
        if (! EsquemaDeDatosIaHelper::tiene_moneda($declaracion)) {
            return null;
        }

        foreach ($aplicados as $filtro) {
            if ($filtro['campo'] === 'moneda_id') {
                return null;
            }
        }

        $col = CatalogoDeDatosIaHelper::columna_sql($declaracion, 'moneda_id');
        $pesos = EsquemaDeDatosIaHelper::monedas_pesos();

        $cuantos = (int) (clone $query)
            ->whereRaw($col . ' IS NOT NULL AND ' . $col . ' NOT IN (' . implode(', ', array_fill(0, count($pesos), '?')) . ')', $pesos)
            ->count();

        if ($cuantos === 0) {
            return null;
        }

        return [
            'registros' => $cuantos,
            'aviso'     => $cuantos . ' registro(s) del conjunto no estan en pesos y entraron a las sumas tal cual: no mezcles monedas. Filtra por moneda_id (igual "pesos" o igual "dolares") y volve a llamar.',
        ];
    }

    /**
     * EL OTRO AVISO DE MONEDA, el que `en_otra_moneda` no puede dar.
     *
     * 🔴 Un renglón de compra puede tener el costo en dólares AUNQUE LA COMPRA ESTÉ EN PESOS:
     * `article_provider_order.cost_in_dollars` es una marca por renglón, no la moneda del
     * comprobante, así que `moneda_id` sale limpio y la suma de `importe` mezcla igual. Sin esto,
     * un importe en dólares entra a un total en pesos y nadie se entera — que es exactamente la
     * clase de error que esta misión vino a cerrar.
     *
     * Mismo criterio que `en_otra_moneda`: si el modelo ya filtró por esa marca, no hay nada que
     * avisar. El nombre de la columna sale de la declaración (una constante del esquema), nunca
     * del input.
     *
     * @param  array  $declaracion
     * @param  array  $aplicados
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return array<string, mixed>|null
     */
    protected static function en_dolares_por_renglon(array $declaracion, array $aplicados, $query)
    {
        $bandera = EsquemaDeDatosIaHelper::bandera_en_dolares($declaracion);

        if (is_null($bandera)) {
            return null;
        }

        foreach ($aplicados as $filtro) {
            if ($filtro['campo'] === $bandera['campo']) {
                return null;
            }
        }

        $col = CatalogoDeDatosIaHelper::columna_sql($declaracion, $bandera['campo']);

        if (is_null($col)) {
            return null;
        }

        $cuantos = (int) (clone $query)->whereRaw($col . ' = 1')->count();

        if ($cuantos === 0) {
            return null;
        }

        return [
            'registros' => $cuantos,
            'aviso'     => $cuantos . ' ' . $bandera['aviso'],
        ];
    }
}
