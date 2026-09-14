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
    const MAX_CARACTERES_CONTEXTO = 12000;

    /** Días hacia atrás que abarca la sección "anteriores" del escritorio. */
    const DIAS_ANTERIORES = 30;

    /** Nombre de cada tipo, como lo lee el dueño. */
    const NOMBRES_TIPO = [
        'dia'     => 'Rendimiento de ayer',
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

        if ((int) UserHelper::userId(false) === (int) UserHelper::userId(true)) {
            return true;
        }

        return (bool) $persona->admin_access;
    }

    /**
     * Los informes listos del dueño para el escritorio: `ultimos` (el más nuevo de cada
     * tipo, en el orden fijo dia, tienda, compras, stock) y `anteriores` (el resto de
     * los últimos 30 días, por fecha descendente y tipo).
     *
     * @param int $owner_id
     * @param int $auth_user_id Persona autenticada, para resolver su conversación de cada informe
     * @return array{ultimos: array, anteriores: array}
     */
    public static function escritorio($owner_id, $auth_user_id)
    {
        $reportes = MostradorReporte::where('user_id', $owner_id)
            ->listos()
            ->whereDate('fecha', '>=', now()->subDays(self::DIAS_ANTERIORES)->format('Y-m-d'))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->get();

        $ultimos_por_tipo = [];
        $anteriores = [];

        foreach ($reportes as $reporte) {
            if (!isset($ultimos_por_tipo[$reporte->tipo])) {
                $ultimos_por_tipo[$reporte->tipo] = $reporte;
                continue;
            }

            $anteriores[] = $reporte;
        }

        $conversaciones = self::conversaciones_por_reporte($reportes->pluck('id')->all(), $auth_user_id);

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
     * pasado a texto plano bloque por bloque, y los hechos en JSON; recortado a 12.000
     * caracteres (el JSON de hechos es lo que se corta, el texto va entero primero).
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

        $hechos = is_array($reporte->hechos) ? $reporte->hechos : [];
        $json_hechos = json_encode($hechos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $partes[] = 'Hechos: ' . ($json_hechos === false ? '{}' : $json_hechos);

        return mb_substr(implode("\n\n", $partes), 0, self::MAX_CARACTERES_CONTEXTO);
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
