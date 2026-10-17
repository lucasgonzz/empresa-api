<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiMessageAction;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Carbon\Carbon;

/**
 * Cómo se le muestra al dueño A QUIÉNES les va a llegar un envío del asistente, antes del sí (misión
 * asistente-envios-a-clientes, 16/10/2026).
 *
 * Las tres herramientas de envío (comprobante, recordatorios de cobro y promoción) le escriben a
 * clientes del negocio, y lo que se manda no se desmanda. Por eso las tres esperan el sí del dueño en
 * los tres modos (HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES), y el sí solo vale si antes vio, con
 * las mismas palabras en la tarjeta del sistema y en el texto de WhatsApp:
 *
 *   1. A QUIÉNES: hasta QUINCE nombres y "y N más" (una lista de 200 nombres no la lee nadie, y por
 *      WhatsApp ni entra en un mensaje);
 *   2. CUÁNTOS: el número exacto de los que reciben;
 *   3. A QUIÉNES NO, Y POR QUÉ: agrupados por motivo ("3 sin teléfono cargado: A, B, C"), para que el
 *      dueño pueda arreglar lo que se arregla (cargar un teléfono) sin leer cliente por cliente;
 *   4. EL TEXTO EXACTO que sale.
 *
 * Este helper es el único lugar donde se arman esas piezas, así la tarjeta y la respuesta al modelo
 * no pueden decir cosas distintas.
 *
 * PHP 7.4: sin match, sin str_contains, sin nullsafe.
 */
class DestinatariosIaHelper
{
    /** Cuántos nombres se muestran antes del "y N más". */
    const MAXIMO_DE_NOMBRES = 15;

    /** Cuántos nombres se muestran por cada motivo de salteo. */
    const MAXIMO_DE_NOMBRES_POR_MOTIVO = 5;

    /** Etiqueta del botón de la tarjeta: lo que hace el sí es MANDAR, no "confirmar" una carga. */
    const TEXTO_CONFIRMAR = 'Mandar';

    /**
     * Largo máximo del bloque de un envío (M1 del segundo chequeo). WhatsApp corta los mensajes largos y
     * el bloque va al final del mensaje del asistente: si no entra, el dueño ve la lista cortada y su sí
     * no vale. Con más de esto se rearma con menos nombres; si igual no entra, no se propone.
     */
    const LARGO_MAXIMO_DEL_BLOQUE = 3000;

    /** Largo máximo del mensaje entero por WhatsApp (el texto del modelo más el bloque). */
    const LARGO_MAXIMO_DEL_MENSAJE = 3500;

    /** Las listas que se prueban, de más a menos, hasta que el bloque entra: [nombres, nombres por motivo]. */
    const LISTAS_QUE_SE_PRUEBAN = [[15, 5], [8, 3], [3, 1]];

    /** Lo que se le contesta al modelo si el bloque no entra ni con las listas más cortas. */
    const MENSAJE_BLOQUE_LARGO = 'No te lo propongo: el texto es tan largo que no entra en un WhatsApp junto con '
        . 'a quiénes les llega, y el dueño tiene que poder leer todo antes de decir que sí. Pedile un texto más corto.';

    /**
     * La pregunta con la que cierra el bloque que el sistema le agrega al mensaje por WhatsApp.
     */
    const PREGUNTA = '¿Lo mando? Respondé sí para mandarlo.';

    /**
     * La instrucción que acompaña a la respuesta de las tres propuestas de envío (reemplaza a la nota
     * de siempre).
     *
     * 🔴 DESDE EL CHEQUEO DEL 16/10/2026 LO QUE SE MUESTRA NO DEPENDE DEL MODELO. Por WhatsApp (y por
     * cualquier canal que confirma por texto) el SISTEMA le agrega al final del mensaje el bloque ya
     * armado (bloque(): a quiénes, cuántos, a quiénes no y por qué, el texto exacto y la pregunta); en el
     * sistema lo muestra la tarjeta. Por eso esta instrucción le pide al modelo lo contrario de antes:
     * UNA frase corta de qué va a mandar, sin repetir la lista ni el texto (saldrían duplicados) y sin
     * hacer la pregunta (ya va en el bloque).
     */
    const COMO_MOSTRARLO = 'ESTO ES UN ENVÍO A CLIENTES Y SIEMPRE ESPERA EL SÍ DE LA PERSONA, en cualquier modo. '
        . 'Todavía NO salió nada: no digas que salió. A quiénes les llega, cuántos, a quiénes no y el texto '
        . 'exacto los muestra el sistema solo (en el sistema, la tarjeta; por WhatsApp, un bloque que se agrega '
        . 'al final de tu mensaje, con la pregunta). Vos decí en UNA frase corta qué vas a mandar (por ejemplo '
        . '"Te preparo el recordatorio de cobro para 12 clientes.") y nada más: NO repitas la lista, ni la '
        . 'cantidad de no enviados, ni el texto, y NO hagas la pregunta. Cuando te conteste que sí en un '
        . 'mensaje suyo, llamás a confirmar_carga_pendiente con este tarjeta_id.';

    /**
     * El bloque que se le muestra al dueño ANTES del sí, en texto plano (la conversación también se lee
     * desde el panel, que no interpreta markdown): lo guarda cada propuesta en `datos.bloque` y
     * AsistenteIaService lo agrega al final del mensaje en los canales que confirman por texto.
     *
     *   Mandar el presupuesto N° 900004 a Marcos Perez
     *   A quiénes (12): Ana, Beto, … y 3 más
     *   A quiénes no (2):
     *   No tiene teléfono cargado (1): Carla
     *   El texto que sale:
     *   …
     *   ¿Lo mando? Respondé sí para mandarlo.
     *
     * @param  string  $titulo
     * @param  array<int, string>  $destinos  Líneas de "a quién / por dónde", ya armadas.
     * @param  array  $grupos  agrupar_por_motivo() de los que no reciben.
     * @param  string  $titulo_del_texto  "El texto que sale (el de Juan Perez)".
     * @param  string  $texto
     * @param  string|null  $aclaracion
     * @return string
     */
    public static function bloque($titulo, array $destinos, array $grupos, $titulo_del_texto, $texto, $aclaracion = null)
    {
        $lineas = [(string) $titulo];

        foreach ($destinos as $destino) {
            $lineas[] = (string) $destino;
        }

        if (count($grupos)) {

            $total = 0;

            foreach ($grupos as $grupo) {
                $total += (int) $grupo['cantidad'];
            }

            $lineas[] = 'A quiénes no (' . $total . '):';

            foreach ($grupos as $grupo) {
                $lineas[] = rtrim((string) $grupo['mensaje'], '.') . ' (' . $grupo['cantidad'] . '): ' . $grupo['clientes'];
            }
        }

        $lineas[] = '';
        $lineas[] = (string) $titulo_del_texto . ':';
        $lineas[] = (string) $texto;

        if (!is_null($aclaracion) && trim((string) $aclaracion) !== '') {
            $lineas[] = '';
            $lineas[] = (string) $aclaracion;
        }

        $lineas[] = '';
        $lineas[] = self::PREGUNTA;

        return implode("\n", $lineas);
    }

    /**
     * La línea "A quiénes (N): …" del bloque.
     *
     * @param  array<int, string>  $nombres
     * @param  int|null  $maximo
     * @return string
     */
    public static function linea_de_destinatarios(array $nombres, $maximo = null)
    {
        return 'A quiénes (' . count($nombres) . '): ' . self::lista_de_nombres($nombres, $maximo);
    }

    /**
     * El bloque de un envío a muchos (recordatorios, promoción), con las listas tan largas como
     * entren en LARGO_MAXIMO_DEL_BLOQUE (M1 del segundo chequeo): primero 15 nombres y 5 por motivo,
     * después menos. null si ni con las más cortas entra (el que llama no propone).
     *
     * @param  string  $titulo
     * @param  array<int, string>  $nombres  De los que reciben.
     * @param  array<int, string>  $otras_lineas  Las que van después de "A quiénes" (filtro, cómo sale, link).
     * @param  array  $salteados  Crudos: [{nombre, motivo, mensaje}].
     * @param  string  $titulo_del_texto
     * @param  string  $texto
     * @param  string|null  $aclaracion
     * @return string|null
     */
    public static function bloque_que_entra($titulo, array $nombres, array $otras_lineas, array $salteados, $titulo_del_texto, $texto, $aclaracion = null)
    {
        foreach (self::LISTAS_QUE_SE_PRUEBAN as $listas) {

            $destinos = array_merge([self::linea_de_destinatarios($nombres, $listas[0])], $otras_lineas);

            $bloque = self::bloque($titulo, $destinos, self::agrupar_por_motivo($salteados, $listas[1]), $titulo_del_texto, $texto, $aclaracion);

            if (mb_strlen($bloque) <= self::LARGO_MAXIMO_DEL_BLOQUE) {
                return $bloque;
            }
        }

        return null;
    }

    /**
     * Lo que vuelve al MODELO de una propuesta de envío (M4 del segundo chequeo): la cantidad y UNA
     * línea, nada más. A quiénes, a quiénes no y el texto los pone el sistema (la tarjeta o el bloque):
     * si el modelo los recibiera enteros, DeepSeek los repetía y el dueño los leía dos veces.
     *
     * @param  array  $creada  Lo que devolvió AccionesIaHelper::crear().
     * @param  string  $resumen
     * @param  int  $cantidad  Cuántos reciben.
     * @param  string  $linea  "Promoción por WhatsApp a 12 clientes; a 3 no les llega."
     * @param  array<int, int>  $reemplazadas  Las otras propuestas de envío que esta dejó reemplazadas.
     * @return array
     */
    public static function respuesta_al_modelo(array $creada, $resumen, $cantidad, $linea, array $reemplazadas = [])
    {
        $extra = [
            'cantidad' => (int) $cantidad,
            'linea'    => (string) $linea,
            // Reemplaza a la nota de siempre: ver COMO_MOSTRARLO.
            'nota'     => self::COMO_MOSTRARLO,
        ];

        if (count($reemplazadas)) {
            $extra['reemplazo'] = array_values(array_unique(array_merge($creada['reemplazadas'], $reemplazadas)));
            $extra['nota'] = self::COMO_MOSTRARLO . ' ' . self::NOTA_DE_REEMPLAZO;
        }

        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen, $extra);
    }

    /** Lo que se le dice al modelo cuando la propuesta nueva dejó reemplazada otra de envío. */
    const NOTA_DE_REEMPLAZO = 'La propuesta de envío que estaba esperando el sí quedó reemplazada por ésta: los envíos se '
        . 'confirman de a uno. Si la otra también hacía falta, proponela de nuevo después de que el dueño conteste ésta.';

    /**
     * 🔴 UN ENVÍO ESPERANDO EL SÍ POR CONVERSACIÓN (B1 del segundo chequeo). Al proponer un envío, las
     * otras propuestas de envío de la conversación que siguen esperando pasan a 'reemplazada'. Si no, un
     * "sí" del dueño podía quedar contestando dos bloques a la vez —o una tarjeta vieja que el dueño ya
     * había corregido— y salir lo que no miró. ConfirmacionPorTextoIaHelper::rechazo() lo vuelve a mirar
     * como cinturón.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  int  $accion_id  La recién creada (no se toca).
     * @return array<int, int>  Las que quedaron reemplazadas.
     */
    public static function reemplazar_otros_envios(ContextoDeCargaIa $contexto, $accion_id)
    {
        $ids = AiMessageAction::where('ai_conversation_id', $contexto->conversation->id)
            ->where('id', '!=', (int) $accion_id)
            ->whereIn('tipo', HerramientasDeCarga::TIPOS_DE_ENVIO)
            ->where('estado', AiMessageAction::ESTADO_PROPUESTA)
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();

        if (!count($ids)) {
            return [];
        }

        AiMessageAction::whereIn('id', $ids)
            ->where('estado', AiMessageAction::ESTADO_PROPUESTA)
            ->update([
                'estado'      => AiMessageAction::ESTADO_REEMPLAZADA,
                'resuelta_at' => Carbon::now(),
            ]);

        return $ids;
    }

    /**
     * "Ana, Beto, Carla y 12 más": hasta MAXIMO_DE_NOMBRES nombres y el resto contado.
     *
     * @param  array<int, string>  $nombres
     * @param  int|null  $maximo
     * @return string
     */
    public static function lista_de_nombres(array $nombres, $maximo = null)
    {
        $maximo = is_null($maximo) ? self::MAXIMO_DE_NOMBRES : (int) $maximo;

        $nombres = array_values(array_map(function ($nombre) {
            $nombre = trim((string) $nombre);

            return $nombre === '' ? 'Cliente sin nombre' : $nombre;
        }, $nombres));

        if (!count($nombres)) {
            return 'nadie';
        }

        $visibles = array_slice($nombres, 0, $maximo);

        $resto = count($nombres) - count($visibles);

        if ($resto > 0) {
            return implode(', ', $visibles) . ' y ' . $resto . ' más';
        }

        if (count($visibles) === 1) {
            return $visibles[0];
        }

        $ultimo = array_pop($visibles);

        return implode(', ', $visibles) . ' y ' . $ultimo;
    }

    /**
     * Los salteados agrupados por motivo, en el orden en que aparece cada motivo por primera vez.
     *
     * @param  array<int, array{nombre: string, motivo: string, mensaje: string}>  $salteados
     * @param  int|null  $maximo_por_motivo  Cuántos nombres por motivo (MAXIMO_DE_NOMBRES_POR_MOTIVO si no viene).
     * @return array<int, array{motivo: string, mensaje: string, cantidad: int, clientes: string}>
     */
    public static function agrupar_por_motivo(array $salteados, $maximo_por_motivo = null)
    {
        $maximo_por_motivo = is_null($maximo_por_motivo) ? self::MAXIMO_DE_NOMBRES_POR_MOTIVO : (int) $maximo_por_motivo;

        $grupos = [];

        foreach ($salteados as $salteado) {

            $motivo = isset($salteado['motivo']) ? (string) $salteado['motivo'] : 'otro';

            if (!isset($grupos[$motivo])) {
                $grupos[$motivo] = [
                    'motivo'  => $motivo,
                    'mensaje' => isset($salteado['mensaje']) ? (string) $salteado['mensaje'] : '',
                    'nombres' => [],
                ];
            }

            $grupos[$motivo]['nombres'][] = isset($salteado['nombre']) ? (string) $salteado['nombre'] : '';
        }

        $resultado = [];

        foreach ($grupos as $grupo) {
            $resultado[] = [
                'motivo'   => $grupo['motivo'],
                'mensaje'  => $grupo['mensaje'],
                'cantidad' => count($grupo['nombres']),
                'clientes' => self::lista_de_nombres($grupo['nombres'], $maximo_por_motivo),
            ];
        }

        return $resultado;
    }

    /**
     * Los renglones de la tarjeta para los salteados: uno por motivo, "3 — No tiene teléfono
     * cargado: A, B y C".
     *
     * @param  array<int, array{motivo: string, mensaje: string, cantidad: int, clientes: string}>  $grupos
     * @return array<int, array{etiqueta: string, valor: string}>
     */
    public static function renglones_de_salteados(array $grupos)
    {
        $renglones = [];

        foreach ($grupos as $grupo) {
            $renglones[] = [
                'etiqueta' => 'No le llega a ' . $grupo['cantidad'],
                'valor'    => rtrim($grupo['mensaje'], '.') . ': ' . $grupo['clientes'],
            ];
        }

        return $renglones;
    }

    /**
     * Lo que va en `datos.para_mostrar` de la tarjeta (desde el segundo chequeo ya no viaja al modelo,
     * ver respuesta_al_modelo()): la misma información que el bloque, en partes.
     *
     * @param  array<int, string>  $nombres  De los que reciben, en orden.
     * @param  array  $grupos  agrupar_por_motivo() de los que no.
     * @param  string  $texto_que_sale
     * @param  string|null  $aclaracion
     * @return array<string, mixed>
     */
    public static function para_mostrar(array $nombres, array $grupos, $texto_que_sale, $aclaracion = null)
    {
        $para_mostrar = [
            'destinatarios'  => self::lista_de_nombres($nombres),
            'cantidad'       => count($nombres),
            'no_les_llega'   => $grupos,
            'texto_que_sale' => (string) $texto_que_sale,
        ];

        if (!is_null($aclaracion) && trim((string) $aclaracion) !== '') {
            $para_mostrar['aclaracion'] = (string) $aclaracion;
        }

        return $para_mostrar;
    }
}
