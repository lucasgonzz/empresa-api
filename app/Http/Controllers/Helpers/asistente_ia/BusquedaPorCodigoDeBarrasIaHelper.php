<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\AiTokenUsageHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiTokenUsage;
use App\Models\Article;
use App\Models\User;
use App\Services\BusquedaPorCodigoDeBarrasService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * La herramienta `buscar_producto_por_codigo_de_barras` del asistente (misión
 * asistente-fotos-barras-y-compras, 24/9/2026).
 *
 * El caso de Lucas en demo3 (conv 12): mandó la foto de un producto con su código de barras y pidió
 * cargarlo; el asistente contestó "no puedo leer el código ni buscar en internet". El modelo SÍ lee
 * los dígitos impresos debajo de las barras (tiene visión); lo que faltaba era con qué buscarlos.
 *
 * Los pasos, en este orden y por este motivo:
 *   1. El código se valida con el dígito verificador GS1. Un dígito mal leído de la foto casi
 *      siempre lo rompe, y es mejor pedirle el código a la persona que buscar otro producto.
 *   2. Si el negocio ya tiene un artículo con ese código, se devuelve ése y no se busca nada.
 *   3. Open Food Facts / Open Beauty Facts: gratis, sin clave. Si está, Claude solo redacta nombre
 *      y descripción en español a partir de la ficha. NO cuenta para el tope.
 *   4. Si no está: Claude Haiku con búsqueda web, en todo el mundo, descripción siempre en
 *      español. ESTA es la que cuesta y la que cuenta para el tope diario.
 *   5. La foto: la de la base abierta, la `og:image` de las fuentes y, si ninguna pasa la
 *      validación por visión, la búsqueda de imágenes de Google de siempre. La elegida se guarda
 *      colgada del mensaje ASSISTANT en curso y se devuelve su `imagen_id`, que es lo que el modelo
 *      le pasa a `proponer_alta` para que el artículo nazca con foto.
 *   6. El consumo queda en `ai_token_usages`.
 *
 * 🔴 EL TOPE CUENTA SOLO LAS BÚSQUEDAS WEB (proceso `busqueda_codigo_barras`), una fila por búsqueda
 * aunque haya habido reenvíos por `pause_turn`. La redacción desde una base abierta se registra con
 * otro proceso (`busqueda_codigo_barras_off`) justamente para que no se cuente: es un Haiku sin
 * búsqueda, cuesta centavos, y el tope existe por la búsqueda web.
 *
 * El tope efectivo es el del plan que empujó el admin (users.plan_ia_tope_busquedas_web_diarias)
 * y, si no hay (null o 0), el defecto de config: 30 por día (decisión de Lucas, 24/9/2026).
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class BusquedaPorCodigoDeBarrasIaHelper
{
    /** El proceso de ai_token_usages de una búsqueda web: es el que cuenta para el tope. */
    const PROCESO_WEB = 'busqueda_codigo_barras';

    /** El de la redacción a partir de Open Food/Beauty Facts: no cuenta para el tope. */
    const PROCESO_BASE_ABIERTA = 'busqueda_codigo_barras_off';

    /** El tope diario si no hay ni plan ni config (decisión de Lucas, 24/9/2026). */
    const TOPE_DIARIO_DEFECTO = 30;

    /**
     * Busca el producto de un código de barras. Devuelve los datos crudos para el tool_result.
     *
     * @param  int  $owner_id
     * @param  string  $codigo  Lo que leyó el modelo debajo de las barras o lo que dictó la persona.
     * @param  \App\Models\AiConversation|null  $conversation
     * @param  \App\Models\AiMessage|null  $assistant_message  El assistant en curso: de él cuelga la foto.
     * @return array
     */
    public static function buscar($owner_id, $codigo, $conversation = null, $assistant_message = null)
    {
        $owner    = User::find((int) $owner_id);
        $servicio = new BusquedaPorCodigoDeBarrasService($owner);

        $leido = trim((string) $codigo);
        $ean   = $servicio->normalizar($leido);

        if (! $servicio->es_valido($ean)) {
            return [
                'codigo' => $leido,
                'error'  => 'Leí "' . $leido . '" y no es un código de barras válido (el dígito verificador no cierra o no tiene 8, 12, 13 o 14 dígitos). '
                          . 'Pedile a la persona que te lo dicte o que mande una foto más nítida del código.',
            ];
        }

        $existente = self::articulo_existente((int) $owner_id, $ean);

        if (! is_null($existente)) {
            return [
                'codigo'     => $ean,
                'ya_existe'  => [
                    'article_id' => (int) $existente->id,
                    'nombre'     => (string) $existente->name,
                    'bar_code'   => (string) $existente->bar_code,
                ],
                'aviso'      => 'Ese código ya está cargado en el negocio: no lo des de alta de nuevo. Contale a la persona cuál es el artículo y preguntale si quiere cambiarle algo.',
            ];
        }

        $auth_user_id    = ($conversation instanceof AiConversation) ? $conversation->auth_user_id : null;
        $conversation_id = ($conversation instanceof AiConversation) ? (int) $conversation->id : null;

        /* 3. Las bases abiertas, gratis. */
        $hallazgo = $servicio->buscar_en_bases_abiertas($ean);

        $datos = null;

        if (! is_null($hallazgo)) {
            $redaccion = $servicio->redactar_desde_base_abierta($ean, $hallazgo);

            if (! is_null($redaccion['body'])) {
                AiTokenUsageHelper::registrar([
                    'user_id'            => (int) $owner_id,
                    'auth_user_id'       => $auth_user_id,
                    'ai_conversation_id' => $conversation_id,
                    'proceso'            => self::PROCESO_BASE_ABIERTA,
                    'body'               => $redaccion['body'],
                    'modelo'             => $redaccion['modelo'],
                ]);
            }

            $datos = [
                'nombre'      => $redaccion['nombre'],
                'marca'       => $redaccion['marca'],
                'descripcion' => $redaccion['descripcion'],
                'fuentes'     => [$hallazgo['url']],
                'origen'      => $hallazgo['base'],
                'fotos'       => is_null($hallazgo['imagen_url']) ? [] : [$hallazgo['imagen_url']],
                'paginas'     => [],
            ];
        }

        /* 4. La búsqueda web, que es la que cuenta para el tope. */
        if (is_null($datos)) {
            $tope   = self::tope_diario($owner);
            $usadas = self::busquedas_web_de_hoy((int) $owner_id);

            if ($usadas >= $tope) {
                return [
                    'codigo' => $ean,
                    'error'  => 'Llegaste al tope de ' . $tope . ' búsquedas por código de barras de hoy. '
                              . 'Mañana se renueva; mientras tanto, pedile a la persona el nombre del producto y cargalo con eso.',
                ];
            }

            $web = $servicio->buscar_en_la_web($ean);

            if (is_null($web)) {
                return [
                    'codigo' => $ean,
                    'error'  => 'No pude buscar el código en internet en este momento. Pedile a la persona el nombre del producto y cargalo con eso.',
                ];
            }

            AiTokenUsageHelper::registrar([
                'user_id'            => (int) $owner_id,
                'auth_user_id'       => $auth_user_id,
                'ai_conversation_id' => $conversation_id,
                'proceso'            => self::PROCESO_WEB,
                'usage'              => $web['usage'],
                'modelo'             => $web['modelo'],
            ]);

            if (is_null($web['nombre'])) {
                return [
                    'codigo'                  => $ean,
                    'nombre'                  => null,
                    'aviso'                   => 'Busqué el código en internet y no encontré con certeza qué producto es. No inventes uno: pedile a la persona el nombre.',
                    'busquedas_restantes_hoy' => self::busquedas_restantes_hoy($owner),
                ];
            }

            /* Primero las páginas que citó el modelo; después el resto de lo que vio. */
            $paginas = array_values(array_unique(array_merge($web['fuentes'], $web['resultados'])));

            $datos = [
                'nombre'      => $web['nombre'],
                'marca'       => $web['marca'],
                'descripcion' => $web['descripcion'],
                'fuentes'     => array_slice($web['fuentes'], 0, 5),
                'origen'      => 'busqueda_web',
                'fotos'       => [],
                'paginas'     => $paginas,
            ];
        }

        /* 5. La foto, solo si hay un mensaje de donde colgarla (en el MCP no lo hay). */
        $imagen = self::foto($servicio, $datos, $ean, (int) $owner_id, $assistant_message);

        $resultado = [
            'codigo'                  => $ean,
            'nombre'                  => $datos['nombre'],
            'marca'                   => $datos['marca'],
            'descripcion'             => $datos['descripcion'],
            'fuentes'                 => $datos['fuentes'],
            'origen_de_los_datos'     => $datos['origen'],
            'imagen_id'               => is_null($imagen) ? null : (int) $imagen['fila']->id,
            'imagen_origen'           => is_null($imagen) ? null : $imagen['origen'],
            'imagen_fuente'           => is_null($imagen) ? null : $imagen['url'],
            'busquedas_restantes_hoy' => self::busquedas_restantes_hoy($owner),
        ];

        if (is_null($imagen)) {
            $resultado['aviso_foto'] = 'No encontré una foto confiable del producto: proponé el alta sin foto y decile que puede mandar una.';
        }

        return $resultado;
    }

    /**
     * El tope de búsquedas web por día de este negocio: el del plan si es > 0, si no el de config.
     *
     * @param  \App\Models\User|null  $owner
     * @return int
     */
    public static function tope_diario($owner)
    {
        $del_plan = is_null($owner) ? 0 : (int) $owner->plan_ia_tope_busquedas_web_diarias;

        if ($del_plan > 0) {
            return $del_plan;
        }

        $defecto = (int) config('services.asistente_ia.busqueda_codigo_barras.tope_diario_defecto', self::TOPE_DIARIO_DEFECTO);

        return $defecto > 0 ? $defecto : self::TOPE_DIARIO_DEFECTO;
    }

    /**
     * Cuántas búsquedas web hizo hoy el negocio (día de la zona de la app, igual que el tope de
     * interacciones de TopeDeTokensHelper).
     *
     * @param  int  $owner_id
     * @return int
     */
    public static function busquedas_web_de_hoy($owner_id)
    {
        return (int) AiTokenUsage::where('user_id', (int) $owner_id)
            ->where('proceso', self::PROCESO_WEB)
            ->whereBetween('created_at', [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()])
            ->count();
    }

    /**
     * @param  \App\Models\User|null  $owner
     * @return int
     */
    public static function busquedas_restantes_hoy($owner)
    {
        $owner_id = is_null($owner) ? 0 : (int) $owner->id;

        return max(0, self::tope_diario($owner) - self::busquedas_web_de_hoy($owner_id));
    }

    /**
     * Un artículo vivo del negocio con ese código (sin espacios, como lo guarda la pantalla o no).
     *
     * @param  int  $owner_id
     * @param  string  $ean
     * @return \App\Models\Article|null
     */
    protected static function articulo_existente($owner_id, $ean)
    {
        return Article::where('user_id', $owner_id)
            ->where(function ($query) use ($ean) {
                $query->where('bar_code', $ean)
                      ->orWhereRaw("REPLACE(bar_code, ' ', '') = ?", [$ean]);
            })
            ->orderBy('id')
            ->first();
    }

    /**
     * Elige la foto y la cuelga del mensaje assistant en curso. Null si no hay mensaje, si ninguna
     * candidata pasó o si no se pudo guardar: en todos los casos el alta sigue sin foto.
     *
     * @param  \App\Services\BusquedaPorCodigoDeBarrasService  $servicio
     * @param  array  $datos
     * @param  string  $ean
     * @param  int  $owner_id
     * @param  \App\Models\AiMessage|null  $assistant_message
     * @return array|null  ['fila' => AiMessageImagen, 'origen' => string, 'url' => string]
     */
    protected static function foto(BusquedaPorCodigoDeBarrasService $servicio, array $datos, $ean, $owner_id, $assistant_message)
    {
        if (! ($assistant_message instanceof AiMessage) || (int) $assistant_message->id <= 0) {
            return null;
        }

        try {
            $elegida = $servicio->elegir_foto($datos['fotos'], $datos['paginas'], (string) $datos['nombre'], $ean, (string) $datos['origen']);

            if (is_null($elegida)) {
                $elegida = $servicio->foto_de_google($ean, (string) $datos['nombre']);
            }
        } catch (\Throwable $e) {
            Log::warning('BusquedaPorCodigoDeBarras: falló la búsqueda de la foto', ['ean' => $ean, 'error' => $e->getMessage()]);

            return null;
        }

        if (is_null($elegida)) {
            Log::info('BusquedaPorCodigoDeBarras: ninguna foto pasó', ['ean' => $ean, 'descartes' => $servicio->descartes()]);

            return null;
        }

        $fila = AsistenteImagenHelper::guardar_binario($assistant_message, $elegida['binario'], $owner_id);

        if (is_null($fila)) {
            return null;
        }

        return ['fila' => $fila, 'origen' => $elegida['origen'], 'url' => $elegida['url']];
    }
}
