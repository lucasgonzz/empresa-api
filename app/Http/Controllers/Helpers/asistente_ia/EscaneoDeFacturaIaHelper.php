<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\ProviderOrderScanController;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderScan;
use App\Models\User;
use Carbon\Carbon;

/**
 * Lo que el asistente sabe decir de un escaneo de factura de compra (misión
 * asistente-aviso-escaneo-factura, 16/10/2026).
 *
 * Medido en demo3 (4.3.13) antes de esta misión: el dueño mandaba la foto, la compra se creaba y el
 * escaneo quedaba `listo` en 17 segundos, pero a «y? ya la leiste? que trae» el asistente contestaba
 * que «la factura no la leo yo… lo determina el escaneo». Tenía razón a medias: la foto NO la
 * transcribe él (eso sigue prohibido en el prompt, un número suyo se lee como un dato confirmado),
 * pero el RESULTADO del escaneo ya existía en `provider_order_scans.resultado` y nadie se lo daba.
 *
 * Este helper es la única puerta del asistente a ese resultado:
 *   - consultar(): la herramienta de lectura `consultar_escaneo_de_factura`.
 *   - conteo() / estado_para_la_persona() / comprobante(): las piezas que comparte con el aviso
 *     por WhatsApp (AvisosDelAsistenteHelper), para que el aviso y la respuesta a «¿qué trae?»
 *     cuenten lo mismo con los mismos números.
 *   - ruta_de_revision(): el link que abre la revisión en la SPA.
 *
 * 🔴 `provider_order_scans` SIGUE EXCLUIDA DEL ESQUEMA GENÉRICO (EsquemaDeDatosIaHelper::
 * TABLAS_EXCLUIDAS). La lectura va por acá a propósito: `resultado` es un JSON de decenas de KB con
 * candidatos, tokens y confianzas que el modelo no tiene por qué ver crudo, y lo que sí le sirve
 * (renglones, matches, totales) se arma acá con nombres que no hay que adivinar.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains.
 */
class EscaneoDeFacturaIaHelper
{
    /**
     * Permiso para leer un escaneo: el de ver el listado de Compras, que es donde vive el botón
     * «Revisar escaneo» (PermissionsTableSeeder, `provider_order.index`).
     */
    const PERMISO_VER_COMPRAS = 'provider_order.index';

    /** Slug de la extensión del escaneo de facturas de compra (la misma que la carga). */
    const EXTENSION_ESCANEO = PropuestaCompraConFacturaIaHelper::EXTENSION_ESCANEO;

    /** Sin número de compra, se busca el último escaneo de estas últimas horas. */
    const HORAS_ULTIMO_ESCANEO = 48;

    /** Cuántos renglones viajan al modelo como mucho; el resto se cuenta en `renglones_omitidos`. */
    const TOPE_RENGLONES = 30;

    /** Los estados que ve la persona (y el modelo), traducidos de estado + resultado_gestion. */
    const ESTADO_EN_PROCESO = 'en_proceso';
    const ESTADO_LISTO      = 'listo';
    const ESTADO_ERROR      = 'error';
    const ESTADO_REVISADO   = 'revisado';
    const ESTADO_DESCARTADO = 'descartado';

    /**
     * Herramienta consultar_escaneo_de_factura.
     *
     * Con `compra_num`, el último escaneo de esa compra del dueño. Sin él, el último escaneo de esta
     * conversación de las últimas HORAS_ULTIMO_ESCANEO horas y, si no hay, el último del dueño en esa
     * misma ventana: «¿qué trae la factura?» casi siempre habla de la que se mandó en esta charla, y
     * un escaneo subido desde la pantalla también sirve si es lo último que hay.
     *
     * @param  int  $owner_id
     * @param  \App\Models\AiConversation|null  $conversation  Para resolver quién pregunta (permisos).
     * @param  mixed  $compra_num  El N° de compra que dijo la persona, o null.
     * @return array<string, mixed>
     */
    public static function consultar($owner_id, $conversation, $compra_num = null): array
    {
        $owner_id = (int) $owner_id;

        $persona = is_null($conversation) ? null : ContextoDeCargaIa::de_la_conversacion($conversation)->persona;

        if (!PermisosIaHelper::puede($persona, self::PERMISO_VER_COMPRAS)) {

            return self::no_se_puede('No tenés permiso para ver las compras desde tu usuario.');
        }

        $owner = User::find($owner_id);

        if (is_null($owner) || !UserHelper::hasExtencion(self::EXTENSION_ESCANEO, $owner)) {

            return self::no_se_puede('Esta cuenta no tiene contratado Agentes de IA (que incluye la lectura de facturas de compra), así que no hay escaneos para mirar.');
        }

        $numero = self::numero_o_null($compra_num);

        if (!is_null($numero)) {

            $compra = ProviderOrder::where('user_id', $owner_id)->where('num', $numero)->first();

            if (is_null($compra)) {

                return self::no_se_puede('No encontré ninguna compra N° ' . $numero . ' en este negocio.');
            }

            $scan = ProviderOrderScan::where('user_id', $owner_id)
                                        ->where('provider_order_id', $compra->id)
                                        ->orderBy('id', 'DESC')
                                        ->first();

            if (is_null($scan)) {

                return [
                    'ok'         => true,
                    'estado'     => 'sin_escaneo',
                    'compra_num' => (int) $compra->num,
                    'mensaje'    => 'La compra N° ' . $compra->num . ' no tiene ninguna factura escaneada.',
                ];
            }

            return self::detalle($scan);
        }

        $scan = self::ultimo_escaneo($owner_id, $conversation);

        if (is_null($scan)) {

            return [
                'ok'      => true,
                'estado'  => 'sin_escaneo',
                'mensaje' => 'No hay ninguna factura escaneada en las últimas ' . self::HORAS_ULTIMO_ESCANEO . ' horas.',
            ];
        }

        return self::detalle($scan);
    }

    /**
     * El último escaneo de la conversación en la ventana y, si no hay, el último del dueño.
     *
     * @param  int  $owner_id
     * @param  \App\Models\AiConversation|null  $conversation
     * @return \App\Models\ProviderOrderScan|null
     */
    protected static function ultimo_escaneo($owner_id, $conversation)
    {
        $desde = Carbon::now()->subHours(self::HORAS_ULTIMO_ESCANEO);

        if (!is_null($conversation)) {

            $de_la_charla = ProviderOrderScan::where('user_id', $owner_id)
                                                ->where('ai_conversation_id', (int) $conversation->id)
                                                ->where('created_at', '>=', $desde)
                                                ->orderBy('id', 'DESC')
                                                ->first();

            if (!is_null($de_la_charla)) {

                return $de_la_charla;
            }
        }

        return ProviderOrderScan::where('user_id', $owner_id)
                                    ->where('created_at', '>=', $desde)
                                    ->orderBy('id', 'DESC')
                                    ->first();
    }

    /**
     * Lo que la herramienta devuelve de un escaneo: estado, compra, comprobante, totales, conteos,
     * los renglones (con tope) y el link para revisarlo.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @return array<string, mixed>
     */
    public static function detalle(ProviderOrderScan $scan): array
    {
        $compra = $scan->provider_order;

        $estado = self::estado_para_la_persona($scan);

        $ruta = self::ruta_de_revision($scan);

        $base = AvisosDelAsistenteHelper::base_del_link((int) $scan->user_id);

        $datos = [
            'ok'                 => true,
            'estado'             => $estado,
            'compra_num'         => is_null($compra) ? null : (int) $compra->num,
            'fecha_de_la_compra' => (is_null($compra) || is_null($compra->created_at)) ? null : $compra->created_at->format('Y-m-d'),
            'proveedor'          => self::nombre_del_proveedor($scan),
            /*
             * El link viaja TAL CUAL o en null: sin base de la SPA cargada (ni en el admin ni en el
             * .env) no hay link, y el modelo no tiene que armar uno por su cuenta.
             */
            'url'                => is_null($base) ? null : $base . $ruta,
        ];

        if ($estado === self::ESTADO_EN_PROCESO) {

            $datos['mensaje'] = 'La factura todavía se está leyendo' . (is_null($scan->paso) || trim((string) $scan->paso) === '' ? '' : ' (' . trim((string) $scan->paso) . ')')
                . '. Cuando termine se puede revisar desde Compras.';

            return $datos;
        }

        if ($estado === self::ESTADO_ERROR) {

            $datos['error'] = self::motivo_del_error($scan);
            $datos['mensaje'] = 'No se pudo leer la factura. Se puede volver a escanear desde la compra.';

            return $datos;
        }

        $resultado = is_array($scan->resultado) ? $scan->resultado : [];

        $factura = isset($resultado['factura']) && is_array($resultado['factura']) ? $resultado['factura'] : [];

        $conteo = self::conteo($scan);

        $datos['comprobante'] = self::comprobante($factura);
        $datos['emisor_razon_social'] = self::valor($factura, 'emisor_razon_social');
        $datos['emisor_cuit'] = self::valor($factura, 'emisor_cuit');
        $datos['fecha_del_comprobante'] = self::valor($factura, 'issued_at');
        $datos['total'] = self::valor($factura, 'total');
        $datos['neto_gravado'] = self::valor($factura, 'neto_gravado');
        $datos['total_iva'] = self::valor($factura, 'total_iva');
        $datos['renglones'] = $conteo['renglones'];
        $datos['reconocidos'] = $conteo['reconocidos'];
        $datos['sin_match'] = $conteo['sin_match'];
        $datos['campos_dudosos'] = isset($factura['campos_dudosos']) && is_array($factura['campos_dudosos']) ? array_values($factura['campos_dudosos']) : [];

        list($articulos, $omitidos) = self::renglones_para_el_modelo($resultado);

        $datos['articulos'] = $articulos;
        $datos['renglones_omitidos'] = $omitidos;

        /*
         * Un escaneo revisado guarda en `resultado` lo que el dueño DEJÓ, no lo que se leyó (ver el
         * docblock del modelo): se lo aclara al modelo para que no lo cuente como "la lectura".
         */
        if ($estado === self::ESTADO_REVISADO) {

            $datos['mensaje'] = 'Este escaneo ya fue revisado y confirmado en la compra: los renglones son los que quedaron cargados.';

        } elseif ($estado === self::ESTADO_DESCARTADO) {

            $datos['mensaje'] = 'Este escaneo fue descartado al revisarlo: no se cargó nada en la compra.';

        } else {

            $datos['mensaje'] = 'La factura ya está leída y espera que la revisen y confirmen desde Compras (botón «Revisar escaneo»).';
        }

        return $datos;
    }

    /**
     * Cuántos renglones leyó el escaneo, cuántos reconoció entre los artículos del negocio y
     * cuántos quedaron sin match.
     *
     * 🔴 `renglones` sale de ProviderOrderScan::contexto_para_frontend()['cantidad_articulos'], que
     * es el mismo número que muestra el aviso de la pantalla: si el WhatsApp dijera 4 y la campanita
     * 5, el dueño no sabría a cuál creerle. Los reconocidos y sin match salen del `match.estado`
     * que deja EscaneoFacturaCompraService::matchear_articulos (`encontrado` | `sin_match`); un
     * renglón sin `match` (un resultado viejo o a medio armar) no cuenta para ninguno de los dos.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @return array{renglones:int, reconocidos:int, sin_match:int, con_dudas:int}
     */
    public static function conteo(ProviderOrderScan $scan): array
    {
        $resultado = is_array($scan->resultado) ? $scan->resultado : [];

        $articulos = isset($resultado['articulos']) && is_array($resultado['articulos']) ? $resultado['articulos'] : [];

        $reconocidos = 0;
        $sin_match = 0;
        $con_dudas = 0;

        foreach ($articulos as $articulo) {

            $estado_match = self::estado_del_match($articulo);

            if ($estado_match === 'encontrado') {

                $reconocidos++;

            } elseif ($estado_match === 'sin_match') {

                $sin_match++;
            }

            if (is_array($articulo) && isset($articulo['campos_dudosos']) && is_array($articulo['campos_dudosos']) && count($articulo['campos_dudosos'])) {

                $con_dudas++;
            }
        }

        $contexto = $scan->contexto_para_frontend();

        return [
            'renglones'   => (int) $contexto['cantidad_articulos'],
            'reconocidos' => $reconocidos,
            'sin_match'   => $sin_match,
            'con_dudas'   => $con_dudas,
        ];
    }

    /**
     * El estado del escaneo como lo cuenta el asistente: los cuatro del modelo más cómo terminó
     * la revisión.
     *
     * - pendiente / procesando (y recientes) → en_proceso
     * - pendiente / procesando abandonados (más viejos que la ventana de vencimiento del
     *   controlador) → error: no hay worker que lo vaya a terminar, y decir "se está leyendo" sería
     *   hacerle esperar al dueño algo que no llega.
     * - error → error
     * - listo + resultado_gestion confirmado → revisado; descartado → descartado; si no → listo.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @return string
     */
    public static function estado_para_la_persona(ProviderOrderScan $scan): string
    {
        $estado = (string) $scan->estado;

        if (in_array($estado, ProviderOrderScanController::ESTADOS_EN_CURSO, true)) {

            $vence = Carbon::now()->subMinutes(ProviderOrderScanController::MINUTOS_ESCANEO_EN_CURSO);

            if (!is_null($scan->created_at) && Carbon::parse($scan->created_at)->lt($vence)) {

                return self::ESTADO_ERROR;
            }

            return self::ESTADO_EN_PROCESO;
        }

        if ($estado === ProviderOrderScan::ESTADO_LISTO) {

            if ((string) $scan->resultado_gestion === 'confirmado') {

                return self::ESTADO_REVISADO;
            }

            if ((string) $scan->resultado_gestion === 'descartado') {

                return self::ESTADO_DESCARTADO;
            }

            /* Gestionado sin marca de cómo (no debería pasar): para la persona ya no está para revisar. */
            if (!is_null($scan->gestionado_at)) {

                return self::ESTADO_REVISADO;
            }

            return self::ESTADO_LISTO;
        }

        return self::ESTADO_ERROR;
    }

    /**
     * El path de la SPA que abre Compras en el día de la compra con la revisión de ESTE escaneo
     * (lo resuelve empresa-spa: componente de Compras que lee `compra` y `escaneo` de la query).
     *
     * Relativo a propósito: la base (https://<cliente>.comerciocity.com) la conoce mejor el admin
     * que este API (ver AvisosDelAsistenteHelper::base_del_link).
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @return string
     */
    public static function ruta_de_revision(ProviderOrderScan $scan): string
    {
        return '/proveedores/compras?compra=' . (int) $scan->provider_order_id . '&escaneo=' . rawurlencode((string) $scan->uuid);
    }

    /**
     * El comprobante leído, como lo diría una persona: «Factura A 0003-00001234». Null si el
     * escaneo no leyó ni el tipo ni el número.
     *
     * @param  array  $factura  El bloque `factura` de `resultado`.
     * @return string|null
     */
    public static function comprobante(array $factura)
    {
        $tipo = trim((string) self::valor($factura, 'tipo_comprobante'));
        $punto_venta = trim((string) self::valor($factura, 'punto_venta'));
        $numero = trim((string) self::valor($factura, 'numero'));

        $partes = [];

        if ($tipo !== '') {

            /* La IA devuelve la letra sola ("A"); con la letra sola se dice "Factura A". */
            $partes[] = mb_strlen($tipo) <= 2 ? 'Factura ' . mb_strtoupper($tipo) : $tipo;
        }

        if ($numero !== '') {

            $partes[] = $punto_venta !== '' ? $punto_venta . '-' . $numero : $numero;
        }

        return count($partes) ? implode(' ', $partes) : null;
    }

    /**
     * El nombre del proveedor de la compra del escaneo (el que manda, no el emisor leído).
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @return string|null
     */
    public static function nombre_del_proveedor(ProviderOrderScan $scan)
    {
        $contexto = $scan->contexto_para_frontend();

        return isset($contexto['provider_nombre']) && trim((string) $contexto['provider_nombre']) !== ''
            ? trim((string) $contexto['provider_nombre'])
            : null;
    }

    /**
     * El motivo de un escaneo que no terminó bien, corto y sin punto final, para meterlo en una
     * frase. Un escaneo abandonado en curso no tiene `error`: se lo dice así.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @return string
     */
    public static function motivo_del_error(ProviderOrderScan $scan): string
    {
        $motivo = trim((string) $scan->error);

        if ($motivo === '') {

            return in_array((string) $scan->estado, ProviderOrderScanController::ESTADOS_EN_CURSO, true)
                ? 'la lectura se cortó y no terminó'
                : 'la lectura no terminó bien';
        }

        if (mb_strlen($motivo) > 200) {

            $motivo = rtrim(mb_substr($motivo, 0, 197)) . '...';
        }

        return rtrim($motivo, " .\t\n\r");
    }

    /**
     * Los renglones tal como le sirven al modelo, con tope: nombre leído, cantidad, costo, si se
     * reconoció y con qué artículo del negocio.
     *
     * @param  array  $resultado
     * @return array  [array<int, array<string, mixed>> $renglones, int $omitidos]
     */
    protected static function renglones_para_el_modelo(array $resultado): array
    {
        $articulos = isset($resultado['articulos']) && is_array($resultado['articulos']) ? array_values($resultado['articulos']) : [];

        $renglones = [];

        foreach (array_slice($articulos, 0, self::TOPE_RENGLONES) as $articulo) {

            if (!is_array($articulo)) {

                continue;
            }

            $match = isset($articulo['match']) && is_array($articulo['match']) ? $articulo['match'] : [];

            $renglones[] = [
                'nombre_leido'         => self::valor($articulo, 'nombre'),
                'codigo_leido'         => !is_null(self::valor($articulo, 'bar_code')) ? self::valor($articulo, 'bar_code') : self::valor($articulo, 'codigo_proveedor'),
                'cantidad'             => self::valor($articulo, 'cantidad'),
                'costo_unitario'       => self::valor($articulo, 'costo_unitario'),
                'reconocido'           => self::estado_del_match($articulo) === 'encontrado',
                'articulo_del_negocio' => self::valor($match, 'nombre_en_catalogo'),
                'campos_dudosos'       => isset($articulo['campos_dudosos']) && is_array($articulo['campos_dudosos']) ? array_values($articulo['campos_dudosos']) : [],
            ];
        }

        $omitidos = count($articulos) > self::TOPE_RENGLONES ? count($articulos) - self::TOPE_RENGLONES : 0;

        return [$renglones, $omitidos];
    }

    /**
     * `encontrado` | `sin_match` | null, leído del renglón.
     *
     * @param  mixed  $articulo
     * @return string|null
     */
    protected static function estado_del_match($articulo)
    {
        if (!is_array($articulo) || !isset($articulo['match']) || !is_array($articulo['match'])) {

            return null;
        }

        return isset($articulo['match']['estado']) ? (string) $articulo['match']['estado'] : null;
    }

    /**
     * Un valor del array o null, sin avisos de índice inexistente.
     *
     * @param  array  $datos
     * @param  string  $clave
     * @return mixed
     */
    protected static function valor(array $datos, $clave)
    {
        return array_key_exists($clave, $datos) ? $datos[$clave] : null;
    }

    /**
     * El número de compra como entero positivo, o null.
     *
     * @param  mixed  $valor
     * @return int|null
     */
    protected static function numero_o_null($valor)
    {
        if (is_null($valor) || $valor === '' || !is_numeric($valor) || (int) $valor <= 0) {

            return null;
        }

        return (int) $valor;
    }

    /**
     * La respuesta de "no se puede", con la misma forma que el resto de las lecturas
     * (LinkDePdfIaHelper::no_se_puede).
     *
     * @param  string  $motivo
     * @return array
     */
    protected static function no_se_puede($motivo): array
    {
        return [
            'ok'    => false,
            'error' => (string) $motivo,
        ];
    }
}
