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
     * La instrucción que acompaña a la respuesta de las tres propuestas de envío.
     *
     * 🔴 POR QUÉ HACE FALTA, ADEMÁS DE LA NOTA DE SIEMPRE. Por WhatsApp la nota de una propuesta dice
     * "decí en una línea qué vas a hacer" (HerramientasDeCarga::NOTA_PROPUESTA_POR_TEXTO), y para un
     * gasto alcanza. Para un envío a clientes no: una línea ("le mando el recordatorio a 12 clientes")
     * no le deja al dueño ver a quiénes ni qué texto sale, y el sí que da no vale como decisión. Esta
     * instrucción viaja en la misma respuesta y le dice al modelo qué mostrar, con qué datos y en qué
     * orden.
     */
    const COMO_MOSTRARLO = 'ESTO ES UN ENVÍO A CLIENTES Y SIEMPRE ESPERA EL SÍ DE LA PERSONA, en cualquier modo. '
        . 'Antes de preguntar, mostrale con los datos de `para_mostrar`, tal cual y sin resumir: '
        . '1) a quiénes les llega (`destinatarios`) y cuántos son (`cantidad`); '
        . '2) a quiénes NO y por qué (`no_les_llega`), si hay alguno; '
        . '3) el texto EXACTO que sale (`texto_que_sale`), palabra por palabra, y la aclaración si viene. '
        . 'Recién después preguntale si lo mandás. Todavía NO salió nada y no digas que salió: en el sistema '
        . 'lo manda ella con el botón Mandar; por un canal sin botones (WhatsApp), cuando te conteste que sí, '
        . 'llamás a confirmar_carga_pendiente con este tarjeta_id.';

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
