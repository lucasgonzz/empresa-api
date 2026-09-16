<?php

namespace App\Http\Controllers\Helpers;

use App\Models\AiConversation;
use App\Models\MostradorReporte;

/**
 * Lógica del mostrador del módulo IA del lado del dueño (misión modulo-ia-mostrador):
 * quién puede verlo, cómo se serializa un informe para el escritorio, y el texto de
 * contexto con el que nace la conversación de un informe.
 *
 * El controlador (MostradorController) solo enruta y responde; todo lo que decide
 * algo vive acá.
 */
class MostradorHelper
{
    /** Techo de caracteres del contexto de la conversación de un informe. */
    const MAX_CARACTERES_CONTEXTO = 20000;

    /** Cuántos ítems de cada lista de los hechos viajan en el contexto. */
    const TOPE_ITEMS_HECHOS = 6;

    /** Claves de los hechos que no le sirven al asistente para responder y se sacan. */
    const CLAVES_HECHOS_OMITIDAS = ['imagen_url'];

    /** Días hacia atrás que abarca la sección "anteriores" del escritorio. */
    const DIAS_ANTERIORES = 30;

    /** Nombre de cada tipo, como lo lee el dueño. */
    const NOMBRES_TIPO = [
        'dia'     => 'Rendimiento de ayer',
        'caja'    => 'Caja y vencimientos',
        'tienda'  => 'Tu tienda',
        'compras' => 'Compras',
        'stock'   => 'Stock',
    ];

    /**
     * Solo el dueño de la cuenta ve el mostrador: la persona autenticada tiene que ser
     * el dueño (userId(false) == userId(true)) o tener admin_access.
     *
     * @return bool
     */
    public static function puede_ver()
    {
        $persona = UserHelper::user(false);

        if (is_null($persona)) {
            return false;
        }

        if (self::es_el_dueno()) {
            return true;
        }

        return (bool) $persona->admin_access;
    }

    /**
     * true si la persona autenticada ES el dueño de la cuenta (no un empleado con
     * admin_access ni el acceso maestro). Es lo que decide si abrir un informe lo marca
     * leído: "leído" quiere decir leído por el dueño.
     *
     * @return bool
     */
    public static function es_el_dueno()
    {
        return (int) UserHelper::userId(false) === (int) UserHelper::userId(true);
    }

    /**
     * Los informes listos del dueño para el escritorio: `ultimos` (el más nuevo de cada
     * tipo, en el orden fijo de MostradorReporte::TIPOS: dia, caja, tienda, compras, stock) y
     * `anteriores` (el resto de
     * los últimos 30 días, por fecha descendente y tipo).
     *
     * @param int $owner_id
     * @param int $auth_user_id Persona autenticada, para resolver su conversación de cada informe
     * @return array{ultimos: array, anteriores: array}
     */
    public static function escritorio($owner_id, $auth_user_id)
    {
        // El más nuevo de cada tipo, sin ventana: la carpeta grande de cada tipo es su
        // último informe aunque tenga más de 30 días.
        $ultimos_por_tipo = [];

        foreach (MostradorReporte::TIPOS as $tipo) {
            $ultimo = MostradorReporte::where('user_id', $owner_id)
                ->listos()
                ->where('tipo', $tipo)
                ->orderByDesc('fecha')
                ->orderByDesc('id')
                ->first();

            if ($ultimo) {
                $ultimos_por_tipo[$tipo] = $ultimo;
            }
        }

        $ids_ultimos = [];

        foreach ($ultimos_por_tipo as $reporte) {
            $ids_ultimos[] = (int) $reporte->id;
        }

        // El resto de los últimos 30 días.
        $anteriores = MostradorReporte::where('user_id', $owner_id)
            ->listos()
            ->where('fecha', '>=', now()->subDays(self::DIAS_ANTERIORES)->format('Y-m-d'))
            ->when(!empty($ids_ultimos), function ($q) use ($ids_ultimos) {
                $q->whereNotIn('id', $ids_ultimos);
            })
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->get()
            ->all();

        $ids_todos = $ids_ultimos;

        foreach ($anteriores as $reporte) {
            $ids_todos[] = (int) $reporte->id;
        }

        $conversaciones = self::conversaciones_por_reporte($ids_todos, $auth_user_id);

        $ultimos = [];

        foreach (MostradorReporte::TIPOS as $tipo) {
            if (isset($ultimos_por_tipo[$tipo])) {
                $ultimos[] = self::serializar($ultimos_por_tipo[$tipo], $conversaciones, false);
            }
        }

        // usort no es estable: el índice de llegada (ya ordenado por fecha desc) desempata.
        $orden_tipo = array_flip(MostradorReporte::TIPOS);

        foreach ($anteriores as $indice => $reporte) {
            $anteriores[$indice] = ['_orden' => $indice, 'reporte' => $reporte];
        }

        usort($anteriores, function ($a, $b) use ($orden_tipo) {
            $fecha_a = $a['reporte']->fecha->format('Y-m-d');
            $fecha_b = $b['reporte']->fecha->format('Y-m-d');

            if ($fecha_a !== $fecha_b) {
                return strcmp($fecha_b, $fecha_a);
            }

            $tipo_a = isset($orden_tipo[$a['reporte']->tipo]) ? $orden_tipo[$a['reporte']->tipo] : 99;
            $tipo_b = isset($orden_tipo[$b['reporte']->tipo]) ? $orden_tipo[$b['reporte']->tipo] : 99;

            if ($tipo_a !== $tipo_b) {
                return $tipo_a <=> $tipo_b;
            }

            return $a['_orden'] <=> $b['_orden'];
        });

        $lista_anteriores = [];

        foreach ($anteriores as $item) {
            $lista_anteriores[] = self::serializar($item['reporte'], $conversaciones, false);
        }

        return [
            'ultimos'    => $ultimos,
            'anteriores' => $lista_anteriores,
        ];
    }

    /**
     * Id de la conversación de la persona para cada informe (null si no tiene).
     *
     * @param array $reporte_ids
     * @param int $auth_user_id
     * @return array Mapa reporte_id => conversation_id
     */
    public static function conversaciones_por_reporte(array $reporte_ids, $auth_user_id)
    {
        $mapa = [];

        if (empty($reporte_ids)) {
            return $mapa;
        }

        $conversaciones = AiConversation::where('origen', MostradorReporte::ORIGEN_CONVERSACION)
            ->where('auth_user_id', $auth_user_id)
            ->whereIn('referencia_id', $reporte_ids)
            ->orderBy('id')
            ->get(['id', 'referencia_id']);

        foreach ($conversaciones as $conversacion) {
            // Si por alguna carrera hubiera dos, gana la primera (la más vieja).
            if (!isset($mapa[(int) $conversacion->referencia_id])) {
                $mapa[(int) $conversacion->referencia_id] = (int) $conversacion->id;
            }
        }

        return $mapa;
    }

    /**
     * La conversación de la persona sobre un informe, o null.
     *
     * @param MostradorReporte $reporte
     * @param int $auth_user_id
     * @return AiConversation|null
     */
    public static function conversacion_de($reporte, $auth_user_id)
    {
        return AiConversation::where('origen', MostradorReporte::ORIGEN_CONVERSACION)
            ->where('referencia_id', $reporte->id)
            ->where('auth_user_id', $auth_user_id)
            ->orderBy('id')
            ->first();
    }

    /**
     * Forma de un informe para la SPA: sin `hechos` nunca, y con `contenido` solo cuando
     * se pide el informe abierto.
     *
     * @param MostradorReporte $reporte
     * @param array $conversaciones Mapa reporte_id => conversation_id
     * @param bool $con_contenido
     * @return array
     */
    public static function serializar($reporte, array $conversaciones, $con_contenido)
    {
        $datos = [
            'id'              => (int) $reporte->id,
            'tipo'            => $reporte->tipo,
            'fecha'           => $reporte->fecha->format('Y-m-d'),
            'titulo'          => $reporte->titulo,
            'resumen'         => $reporte->resumen,
            'generado_at'     => is_null($reporte->generado_at) ? null : $reporte->generado_at->toDateTimeString(),
            'leido_at'        => is_null($reporte->leido_at) ? null : $reporte->leido_at->toDateTimeString(),
            'conversation_id' => isset($conversaciones[(int) $reporte->id]) ? $conversaciones[(int) $reporte->id] : null,
        ];

        if ($con_contenido) {
            $datos['contenido'] = $reporte->contenido;
        }

        return $datos;
    }

    /**
     * Título de la conversación de un informe: "<titulo del informe> · <d/m>".
     *
     * @param MostradorReporte $reporte
     * @return string
     */
    public static function titulo_de_conversacion($reporte)
    {
        $titulo = trim((string) $reporte->titulo);

        if ($titulo === '') {
            $titulo = isset(self::NOMBRES_TIPO[$reporte->tipo]) ? self::NOMBRES_TIPO[$reporte->tipo] : 'Informe';
        }

        return mb_substr($titulo . ' · ' . $reporte->fecha->format('d/m'), 0, 150);
    }

    /**
     * Contexto de fondo de la conversación de un informe: título y fecha, el contenido
     * pasado a texto plano bloque por bloque (entero: es lo que el dueño tiene en
     * pantalla y sobre lo que pregunta), y los hechos COMPACTADOS en JSON (sin
     * imagen_url, listas recortadas a 6 ítems).
     *
     * 🔴 Nunca se corta un JSON a la mitad: un JSON truncado es basura para el asistente.
     * Si con los hechos compactados el contexto supera el techo, se sacan secciones
     * enteras del JSON (las últimas primero) hasta que entre; y si ni con "Hechos: {}"
     * entra, lo que se recorta es el texto plano, que sí admite un corte.
     *
     * @param MostradorReporte $reporte
     * @return string
     */
    public static function contexto_de_conversacion($reporte)
    {
        $nombre_tipo = isset(self::NOMBRES_TIPO[$reporte->tipo]) ? self::NOMBRES_TIPO[$reporte->tipo] : $reporte->tipo;

        $partes = [];
        $partes[] = 'Informe del mostrador: ' . trim((string) $reporte->titulo)
            . ' (' . $nombre_tipo . ', ' . $reporte->fecha->format('d/m/Y') . ')';

        $resumen = trim((string) $reporte->resumen);

        if ($resumen !== '') {
            $partes[] = $resumen;
        }

        $texto = self::contenido_a_texto_plano(is_array($reporte->contenido) ? $reporte->contenido : []);

        if ($texto !== '') {
            $partes[] = $texto;
        }

        $cabecera = implode("\n\n", $partes);

        $hechos = self::compactar_hechos(is_array($reporte->hechos) ? $reporte->hechos : []);

        // Se sacan secciones enteras (las últimas primero) hasta que el contexto entre.
        while (true) {
            $contexto = $cabecera . "\n\n" . 'Hechos: ' . self::hechos_a_json($hechos);

            if (mb_strlen($contexto) <= self::MAX_CARACTERES_CONTEXTO) {
                return $contexto;
            }

            $clave = self::ultima_seccion($hechos);

            if (is_null($clave)) {
                break;
            }

            unset($hechos[$clave]);
        }

        // Ni con los hechos pelados entra: se recorta el texto plano, que admite un corte.
        $cola = "\n\n" . 'Hechos: ' . self::hechos_a_json($hechos);
        $lugar = max(0, self::MAX_CARACTERES_CONTEXTO - mb_strlen($cola));

        return mb_substr($cabecera, 0, $lugar) . $cola;
    }

    /**
     * Los hechos sin lo que no ayuda a responder: se sacan las claves de
     * CLAVES_HECHOS_OMITIDAS en cualquier nivel y cada lista (array secuencial) queda
     * con sus primeros TOPE_ITEMS_HECHOS ítems.
     *
     * @param array $hechos
     * @return array
     */
    public static function compactar_hechos(array $hechos)
    {
        $es_lista = array_keys($hechos) === range(0, count($hechos) - 1);

        if ($es_lista && count($hechos) > self::TOPE_ITEMS_HECHOS) {
            $hechos = array_slice($hechos, 0, self::TOPE_ITEMS_HECHOS);
        }

        $resultado = [];

        foreach ($hechos as $clave => $valor) {
            if (!$es_lista && in_array((string) $clave, self::CLAVES_HECHOS_OMITIDAS, true)) {
                continue;
            }

            $resultado[$clave] = is_array($valor) ? self::compactar_hechos($valor) : $valor;
        }

        return $resultado;
    }

    /**
     * JSON de un array de hechos, siempre válido ('{}' si no se puede codificar).
     *
     * @param array $hechos
     * @return string
     */
    protected static function hechos_a_json(array $hechos)
    {
        if (empty($hechos)) {
            return '{}';
        }

        $json = json_encode($hechos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '{}' : $json;
    }

    /**
     * La última clave de primer nivel de los hechos que sea una sección (un array): es la
     * que se saca primero cuando el contexto no entra. Las claves escalares (aplica,
     * fecha, dia_semana) se quedan siempre. Null si no queda ninguna sección.
     *
     * @param array $hechos
     * @return string|int|null
     */
    protected static function ultima_seccion(array $hechos)
    {
        foreach (array_reverse(array_keys($hechos)) as $clave) {
            if (is_array($hechos[$clave])) {
                return $clave;
            }
        }

        return null;
    }

    /**
     * El contenido de bloques como texto plano, bloque por bloque, en el orden en que
     * el dueño lo lee en pantalla.
     *
     * @param array $contenido
     * @return string
     */
    public static function contenido_a_texto_plano(array $contenido)
    {
        $bloques = isset($contenido['bloques']) && is_array($contenido['bloques']) ? $contenido['bloques'] : [];
        $lineas = [];

        foreach ($bloques as $bloque) {
            if (!is_array($bloque) || !isset($bloque['tipo'])) {
                continue;
            }

            $titulo = isset($bloque['titulo']) ? trim((string) $bloque['titulo']) : '';
            $items = isset($bloque['items']) && is_array($bloque['items']) ? $bloque['items'] : [];

            switch ($bloque['tipo']) {
                case 'resumen':
                case 'parrafo':
                    $lineas[] = trim((string) (isset($bloque['texto']) ? $bloque['texto'] : ''));
                    break;

                case 'seccion':
                    $lineas[] = '== ' . $titulo;
                    break;

                case 'cifras':
                    foreach ($items as $item) {
                        $linea = trim((string) ($item['etiqueta'] ?? '')) . ': ' . trim((string) ($item['valor'] ?? ''));
                        $extras = [];

                        foreach (['detalle', 'variacion'] as $clave) {
                            if (!empty($item[$clave])) {
                                $extras[] = trim((string) $item[$clave]);
                            }
                        }

                        if (!empty($extras)) {
                            $linea .= ' (' . implode('; ', $extras) . ')';
                        }

                        $lineas[] = $linea;
                    }
                    break;

                case 'lista':
                    if ($titulo !== '') {
                        $lineas[] = $titulo . ':';
                    }

                    foreach ($items as $item) {
                        $lineas[] = '- ' . trim((string) ($item['texto'] ?? ''));
                    }
                    break;

                case 'tabla':
                    if ($titulo !== '') {
                        $lineas[] = $titulo . ':';
                    }

                    $columnas = isset($bloque['columnas']) && is_array($bloque['columnas']) ? $bloque['columnas'] : [];
                    $filas = isset($bloque['filas']) && is_array($bloque['filas']) ? $bloque['filas'] : [];

                    if (!empty($columnas)) {
                        $lineas[] = implode(' | ', array_map('strval', $columnas));
                    }

                    foreach ($filas as $fila) {
                        if (is_array($fila)) {
                            $lineas[] = implode(' | ', array_map('strval', $fila));
                        }
                    }
                    break;

                case 'articulos':
                    if ($titulo !== '') {
                        $lineas[] = $titulo . ':';
                    }

                    foreach ($items as $item) {
                        $partes = [trim((string) ($item['nombre'] ?? ''))];

                        foreach (['linea_1', 'linea_2'] as $clave) {
                            if (!empty($item[$clave])) {
                                $partes[] = trim((string) $item[$clave]);
                            }
                        }

                        $lineas[] = '- ' . implode(' — ', $partes);
                    }
                    break;

                case 'acciones':
                    if ($titulo !== '') {
                        $lineas[] = $titulo . ':';
                    }

                    foreach ($items as $item) {
                        $lineas[] = '- [' . trim((string) ($item['tipo'] ?? '')) . '] ' . trim((string) ($item['texto'] ?? ''));
                    }
                    break;
            }

            $lineas[] = '';
        }

        return trim(implode("\n", $lineas));
    }
}
