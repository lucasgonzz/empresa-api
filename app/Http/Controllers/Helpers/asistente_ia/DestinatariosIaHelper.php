<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

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
     *   1, no tiene teléfono cargado: Carla
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
                $lineas[] = $grupo['cantidad'] . ', ' . lcfirst(rtrim((string) $grupo['mensaje'], '.')) . ': ' . $grupo['clientes'];
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
     * @return string
     */
    public static function linea_de_destinatarios(array $nombres)
    {
        return 'A quiénes (' . count($nombres) . '): ' . self::lista_de_nombres($nombres);
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
     * @return array<int, array{motivo: string, mensaje: string, cantidad: int, clientes: string}>
     */
    public static function agrupar_por_motivo(array $salteados)
    {
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
                'clientes' => self::lista_de_nombres($grupo['nombres'], self::MAXIMO_DE_NOMBRES_POR_MOTIVO),
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
     * Lo que va en `para_mostrar` de la respuesta al modelo: la misma información que la tarjeta.
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
