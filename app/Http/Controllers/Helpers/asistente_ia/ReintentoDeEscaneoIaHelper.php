<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\providerOrder\ProviderOrderScanAltaHelper;
use App\Http\Controllers\ProviderOrderScanController;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderScan;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * VOLVER A ESCANEAR LA FACTURA DE UNA COMPRA CUYO ESCANEO FALLÓ, con las fotos que ya están guardadas
 * (misión agente-mejoras-uso-real, E1 punto 8, 29/10/2026).
 *
 * 🔴 EL CASO REAL (Ferre Total, CONV 20 del 29/10/2026). El agente cargó la Compra N° 1358 de CAVALLFER
 * con la foto de la factura y el escaneo terminó en error («La IA no devolvió contenido de texto»). El
 * aviso le dijo a Pablo «podés volver a escanearla desde la compra»; Pablo hizo lo natural, que fue
 * mandarle la foto de nuevo al agente, y el agente creó OTRA compra (la 1361): la 1358 quedó vacía,
 * colgada, con su escaneo fallido. Dos arreglos, que van juntos:
 *   - la compra con factura reusa sola una compra de hoy cuyo único escaneo FALLÓ sin leer nada
 *     (PropuestaCompraConFacturaIaHelper::esta_limpia(), con `$ignora_fallidos`), y
 *   - esta herramienta: si la persona pide «volvé a escanearla», no hace falta que mande la foto. Las
 *     páginas del escaneo fallido siguen en el disco privado (`provider_order_scan_images`), y se
 *     vuelven a mandar a leer por el MISMO alta que usa la pantalla (ProviderOrderScanAltaHelper::crear).
 *
 * Es una carga inocua (no mueve plata ni stock: vuelve a correr una lectura sobre la misma compra), y
 * por eso entra en las auto-confirmables de "resuelto" y "directo", igual que la compra con factura
 * (HerramientasDeCarga::AUTO_CONFIRMABLES).
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class ReintentoDeEscaneoIaHelper
{
    /**
     * Herramienta proponer_reintento_de_escaneo.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  compra_num (si falta: la compra del último escaneo fallido de esta
     *                        conversación), reemplaza_a
     * @return array  Respuesta de la herramienta (propuesta creada o respuesta de negocio).
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input)
    {
        if (!PermisosIaHelper::puede($contexto->persona, PermisosIaHelper::COMPRAS)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('compras'));
        }

        if (!UserHelper::hasExtencion(PropuestaCompraConFacturaIaHelper::EXTENSION_ESCANEO, $contexto->owner)) {

            return RespuestaDeCargaIa::error('Esta cuenta no tiene contratado Agentes de IA (que incluye la lectura de facturas de compra).');
        }

        $compra = self::compra_del_pedido($contexto, EntradaDeCargaIa::valor($input, 'compra_num'));

        if (RespuestaDeCargaIa::es_negativa($compra)) {

            return $compra;
        }

        $fallido = self::escaneo_a_reintentar($compra);

        if (RespuestaDeCargaIa::es_negativa($fallido)) {

            return $fallido;
        }

        $paginas = self::paginas_guardadas($fallido);

        if (!count($paginas)) {

            return RespuestaDeCargaIa::error(
                'Las fotos del escaneo de la Compra N° ' . $compra->num . ' ya no están guardadas. Mandame la foto de la factura de nuevo y la cargo en esa misma compra.'
            );
        }

        $proveedor = Provider::where('user_id', $contexto->owner_id)->where('id', (int) $compra->provider_id)->first();

        $nombre_proveedor = is_null($proveedor) ? 'sin proveedor' : (string) $proveedor->name;

        $cuantas = count($paginas) === 1 ? '1 foto' : count($paginas) . ' fotos';

        $datos = [
            'provider_order_id'      => (int) $compra->id,
            'compra_num'             => (int) $compra->num,
            'provider_order_scan_id' => (int) $fallido->id,
            'paginas'                => count($paginas),
        ];

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_REINTENTO_ESCANEO,
            self::clave($compra->id),
            $datos,
            [
                'titulo'    => 'Volver a escanear la factura',
                'renglones' => [
                    ['etiqueta' => 'Compra', 'valor' => 'N° ' . $compra->num . ' de ' . $nombre_proveedor],
                    ['etiqueta' => 'Fotos de la factura', 'valor' => $cuantas . ' (las del escaneo que falló)'],
                    ['etiqueta' => 'Qué se hace', 'valor' => 'Se vuelve a leer la factura con las mismas fotos, en la misma compra'],
                ],
                'aviso'     => null,
            ],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        return AccionesIaHelper::respuesta_de_propuesta(
            $creada,
            'Volver a escanear la factura de la Compra N° ' . $compra->num . ' de ' . $nombre_proveedor . ' · ' . $cuantas
        );
    }

    /**
     * Identidad de la carga: un segundo pedido de reintento sobre la misma compra reemplaza la tarjeta.
     *
     * @param  int  $provider_order_id
     * @return string
     */
    public static function clave($provider_order_id)
    {
        return 'reintento_escaneo:' . (int) $provider_order_id;
    }

    /**
     * Manda a leer de nuevo la factura con las páginas del escaneo que falló. Corre adentro de la
     * transacción de EjecutorAccionesIaHelper (el job del escaneo sale recién al commit: ver el
     * `afterCommit()` de ProviderOrderScanAltaHelper::crear()).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta, provider_id, provider_order_id}
     *
     * @throws AccionIaException  422 si el reintento ya no se puede hacer.
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion)
    {
        if (!PermisosIaHelper::puede($contexto->persona, PermisosIaHelper::COMPRAS)) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_permiso('compras'));
        }

        if (!UserHelper::hasExtencion(PropuestaCompraConFacturaIaHelper::EXTENSION_ESCANEO, $contexto->owner)) {

            throw new AccionIaException(422, 'Esta cuenta ya no tiene contratado Agentes de IA (que incluye la lectura de facturas de compra).');
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $compra = ProviderOrder::where('user_id', $contexto->owner_id)
                                ->where('id', isset($datos['provider_order_id']) ? (int) $datos['provider_order_id'] : 0)
                                ->first();

        if (is_null($compra)) {

            throw new AccionIaException(422, 'Esa compra ya no existe. Pedímelo de nuevo.');
        }

        /* Se revalida: entre la tarjeta y el sí alguien pudo reescanearla desde la pantalla. */
        $fallido = self::escaneo_a_reintentar($compra);

        if (RespuestaDeCargaIa::es_negativa($fallido)) {

            throw new AccionIaException(422, (string) $fallido['error']);
        }

        $paginas = self::paginas_guardadas($fallido);

        if (!count($paginas)) {

            throw new AccionIaException(422, 'Las fotos de ese escaneo ya no están guardadas. Mandame la foto de la factura de nuevo.');
        }

        $avisa_por_whatsapp = PropuestaCompraConFacturaIaHelper::avisa_por_whatsapp($contexto, $accion);

        $resultado = ProviderOrderScanAltaHelper::crear($compra, $paginas, $contexto->persona, [
            'ai_conversation_id'  => is_null($contexto->conversation) ? null : (int) $contexto->conversation->id,
            'avisar_por_whatsapp' => $avisa_por_whatsapp,
        ]);

        if ((int) $resultado['status'] !== 202) {

            throw new AccionIaException(
                422,
                isset($resultado['body']['message']) ? (string) $resultado['body']['message'] : 'No se pudo empezar a leer la factura.'
            );
        }

        $proveedor = Provider::where('id', (int) $compra->provider_id)->first();

        $de = is_null($proveedor) ? '' : ' de ' . $proveedor->name;

        return [
            'texto'             => 'Volví a mandar a leer la factura de la Compra N° ' . $compra->num . $de . ' con las mismas '
                . (count($paginas) === 1 ? 'foto' : count($paginas) . ' fotos') . ', sin crear otra compra.'
                . PropuestaCompraConFacturaIaHelper::cierre_del_escaneo($contexto, $avisa_por_whatsapp),
            'ruta'              => [
                'name'   => 'provider',
                'params' => ['view' => 'compras'],
                'texto'  => 'Ver en Compras',
                'fecha'  => Carbon::parse($compra->created_at)->format('Y-m-d'),
            ],
            'provider_id'       => is_null($proveedor) ? null : (int) $proveedor->id,
            'provider_order_id' => (int) $compra->id,
        ];
    }

    /**
     * La compra del pedido: la del número que dijo la persona o, si no vino, la del último escaneo
     * fallido que nació en ESTA conversación (es «volvé a escanearla» después del aviso del error).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $compra_num
     * @return \App\Models\ProviderOrder|array
     */
    protected static function compra_del_pedido(ContextoDeCargaIa $contexto, $compra_num)
    {
        $num = (is_numeric($compra_num) && (int) $compra_num > 0) ? (int) $compra_num : 0;

        if ($num > 0) {

            $compra = ProviderOrder::where('user_id', $contexto->owner_id)->where('num', $num)->first();

            if (is_null($compra)) {

                return RespuestaDeCargaIa::error('No encontré ninguna Compra N° ' . $num . ' entre las tuyas. Fijate el número en Compras.');
            }

            return $compra;
        }

        if (!is_null($contexto->conversation)) {

            $escaneo = ProviderOrderScan::where('user_id', $contexto->owner_id)
                                        ->where('ai_conversation_id', (int) $contexto->conversation->id)
                                        ->where('estado', 'error')
                                        ->orderBy('id', 'DESC')
                                        ->first();

            if (!is_null($escaneo)) {

                $compra = ProviderOrder::where('user_id', $contexto->owner_id)->where('id', (int) $escaneo->provider_order_id)->first();

                if (!is_null($compra)) {

                    return $compra;
                }
            }
        }

        return RespuestaDeCargaIa::faltan(['el número de la compra cuya factura vuelvo a escanear']);
    }

    /**
     * El escaneo que hay que reintentar: el ÚLTIMO de la compra, si falló sin leer nada (en `error`, o
     * en curso pero abandonado: más viejo que la ventana de MINUTOS_ESCANEO_EN_CURSO). La respuesta
     * negativa con el motivo si el último terminó bien, sigue en curso o no hay ninguno.
     *
     * @param  \App\Models\ProviderOrder  $compra
     * @return \App\Models\ProviderOrderScan|array
     */
    public static function escaneo_a_reintentar(ProviderOrder $compra)
    {
        $ultimo = ProviderOrderScan::where('provider_order_id', $compra->id)
                                    ->orderBy('id', 'DESC')
                                    ->first();

        if (is_null($ultimo)) {

            return RespuestaDeCargaIa::error('La Compra N° ' . $compra->num . ' no tiene ningún escaneo de factura. Mandame la foto y la cargo en esa compra.');
        }

        if (self::fallo_sin_leer($ultimo)) {

            return $ultimo;
        }

        if (in_array((string) $ultimo->estado, ProviderOrderScanController::ESTADOS_EN_CURSO, true)) {

            return RespuestaDeCargaIa::error('La factura de la Compra N° ' . $compra->num . ' todavía se está escaneando. Esperá a que termine.');
        }

        return RespuestaDeCargaIa::error('El escaneo de la factura de la Compra N° ' . $compra->num . ' ya terminó bien: revisalo y confirmalo desde Compras.');
    }

    /**
     * true si el escaneo FALLÓ SIN LEER NADA: en `error` y sin resultado, o en curso pero abandonado
     * (nadie lo va a terminar: más viejo que la ventana de ProviderOrderScanController) y sin resultado.
     * Es la misma regla con la que la compra con factura deja de contar ese escaneo como «la compra
     * ya tiene una factura» (PropuestaCompraConFacturaIaHelper::esta_limpia()).
     *
     * @param  \App\Models\ProviderOrderScan  $escaneo
     * @return bool
     */
    public static function fallo_sin_leer(ProviderOrderScan $escaneo)
    {
        if (!empty($escaneo->resultado)) {

            return false;
        }

        if ((string) $escaneo->estado === 'error') {

            return true;
        }

        if (!in_array((string) $escaneo->estado, ProviderOrderScanController::ESTADOS_EN_CURSO, true)) {

            return false;
        }

        return !is_null($escaneo->created_at)
            && Carbon::parse($escaneo->created_at)->lt(Carbon::now()->subMinutes(ProviderOrderScanController::MINUTOS_ESCANEO_EN_CURSO));
    }

    /**
     * Las páginas del escaneo que siguen en el disco privado, en orden, con la forma que acepta
     * ProviderOrderScanAltaHelper::crear() (`binario` + `nombre_original`). Una página cuyo archivo ya
     * no está se saltea; si no queda ninguna, la lista vuelve vacía.
     *
     * @param  \App\Models\ProviderOrderScan  $escaneo
     * @return array<int, array{binario: string, nombre_original: string}>
     */
    protected static function paginas_guardadas(ProviderOrderScan $escaneo)
    {
        $paginas = [];

        foreach ($escaneo->images()->get() as $imagen) {

            $path = (string) $imagen->path;

            try {

                if ($path === '' || !Storage::disk('local')->exists($path)) {

                    continue;
                }

                $paginas[] = [
                    'binario'         => (string) Storage::disk('local')->get($path),
                    'nombre_original' => trim((string) $imagen->nombre_original) !== ''
                        ? (string) $imagen->nombre_original
                        : 'factura-' . (int) $imagen->orden . '.webp',
                ];

            } catch (\Throwable $e) {

                Log::warning('ReintentoDeEscaneoIaHelper: no se pudo leer una página del escaneo', [
                    'provider_order_scan_id' => (int) $escaneo->id,
                    'path'                   => $path,
                    'error'                  => $e->getMessage(),
                ]);
            }
        }

        return $paginas;
    }
}
