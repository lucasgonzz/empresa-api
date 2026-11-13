<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La tarjeta que prende o apaga los avisos del dueño por WhatsApp (misión asistente-truvari-pedidos-fallidos,
 * C2, 13/11/2026): pedidos nuevos de la tienda («al toque» o en horas fijas) y artículos que llegan a stock
 * cero. Lo que manda los avisos es AvisosDelDuenoIaHelper (por el canal de recordatorios del admin); esto solo
 * cambia las preferencias en `users` del dueño, con confirmación como cualquier otra carga.
 *
 * 🔴 SE PROMETE LO QUE EL ADMIN ANUNCIÓ (misma regla que la hora de una tarea, PropuestaTareaIaHelper): si el
 * admin no consultó los recordatorios en las últimas horas, el resultado dice que todavía no van a llegar; si
 * consulta pero no tiene la plantilla de Meta, que solo llegan mientras la persona le haya escrito al
 * asistente en las últimas 24 h.
 *
 * 🔴 EL CURSOR ARRANCA AL CONFIRMAR. Prender un aviso (o cambiar el modo o las horas de los pedidos) deja el
 * `*_desde` en ese momento: nunca se le avisa al dueño un pedido o un movimiento de stock anterior.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains.
 */
class PropuestaAvisosDelDuenoIaHelper
{
    /** Lo que se le dice cuando el admin no consulta los recordatorios. */
    const AVISO_SIN_ADMIN = 'Todavía no te van a llegar: el canal de avisos por WhatsApp no está activo para tu sistema. Quedan guardados y empiezan a salir cuando se active.';

    /** Lo que se le dice cuando el admin consulta pero no tiene la plantilla para la ventana cerrada. */
    const AVISO_SIN_PLANTILLA = 'Te llegan mientras me hayas escrito por acá en las últimas 24 horas; fuera de eso WhatsApp no deja mandarlos y esos avisos no salen.';

    /**
     * Arma la tarjeta. Devuelve la respuesta para el modelo (RespuestaDeCargaIa / respuesta_de_propuesta).
     *
     * @param  \App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @param  array  $input  `pedidos_tienda` (apagado|al_toque|horas), `horas`, `stock_cero`, `reemplaza_a`.
     * @return array
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input): array
    {
        try {
            self::validar_contexto($contexto);
            $cambios = self::cambios($input, AvisosDelDuenoIaHelper::preferencias($contexto->owner));
        } catch (AccionIaException $e) {
            return RespuestaDeCargaIa::error($e->getMessage());
        }

        if (isset($cambios['faltan'])) {

            return RespuestaDeCargaIa::faltan($cambios['faltan']);
        }

        $actuales = AvisosDelDuenoIaHelper::preferencias($contexto->owner);
        $nuevas = self::aplicadas($actuales, $cambios);

        $renglones = [
            ['etiqueta' => 'Pedidos nuevos de la tienda', 'valor' => self::como_se_lee_pedidos($actuales) . ' → ' . self::como_se_lee_pedidos($nuevas)],
            ['etiqueta' => 'Artículos sin stock', 'valor' => self::como_se_lee_stock($actuales['stock_cero']) . ' → ' . self::como_se_lee_stock($nuevas['stock_cero'])],
        ];

        $aviso = self::aviso_del_canal($contexto->owner_id);

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_AVISOS_DEL_DUENO,
            'avisos_del_dueno:' . $contexto->owner_id,
            ['cambios' => $cambios],
            array_filter([
                'titulo'    => 'Avisos por WhatsApp',
                'renglones' => $renglones,
                'aviso'     => $aviso,
            ], function ($valor) {
                return !is_null($valor);
            }),
            isset($input['reemplaza_a']) ? $input['reemplaza_a'] : null
        );

        return AccionesIaHelper::respuesta_de_propuesta(
            $creada,
            'Avisos por WhatsApp: pedidos nuevos de la tienda ' . self::como_se_lee_pedidos($nuevas) . '; artículos sin stock ' . self::como_se_lee_stock($nuevas['stock_cero']) . '.',
            array_filter([
                'pedidos_tienda' => $nuevas['pedidos'],
                'horas'          => $nuevas['pedidos'] === AvisosDelDuenoIaHelper::PEDIDOS_HORAS ? $nuevas['horas'] : null,
                'stock_cero'     => $nuevas['stock_cero'],
                'aviso'          => $aviso,
                'como_llegan'    => 'Llegan por este mismo WhatsApp. Los pedidos y el stock se cuentan desde que se confirma: lo anterior no se avisa. En horas fijas solo llega un mensaje si entró algún pedido desde la hora anterior.',
            ], function ($valor) {
                return !is_null($valor);
            })
        );
    }

    /**
     * Guarda las preferencias (lo que corre al confirmar la tarjeta).
     *
     * @param  \App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array{texto: string}
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion): array
    {
        self::validar_contexto($contexto);

        $datos = is_array($accion->datos) ? $accion->datos : [];
        $cambios = isset($datos['cambios']) && is_array($datos['cambios']) ? $datos['cambios'] : [];

        User::where('id', $contexto->owner_id)->lockForUpdate()->first();

        $actuales = AvisosDelDuenoIaHelper::preferencias($contexto->owner);
        $nuevas = self::aplicadas($actuales, $cambios);
        $ahora = Carbon::now();

        $columnas = [];

        $cambian_los_pedidos = $nuevas['pedidos'] !== $actuales['pedidos'] || $nuevas['horas'] !== $actuales['horas'];

        if ($cambian_los_pedidos) {
            $columnas['agente_aviso_pedidos'] = $nuevas['pedidos'];
            $columnas['agente_aviso_pedidos_horas'] = count($nuevas['horas']) ? implode(',', $nuevas['horas']) : null;
            $columnas['agente_aviso_pedidos_desde'] = $nuevas['pedidos'] === AvisosDelDuenoIaHelper::PEDIDOS_APAGADO ? null : $ahora->format('Y-m-d H:i:s');
        }

        if ($nuevas['stock_cero'] !== $actuales['stock_cero']) {
            $columnas['agente_aviso_stock_cero'] = $nuevas['stock_cero'] ? 1 : 0;
            $columnas['agente_aviso_stock_desde'] = $nuevas['stock_cero'] ? $ahora->format('Y-m-d H:i:s') : null;
        }

        if (count($columnas)) {
            /* Por la tabla y sin tocar `updated_at` del dueño: es una preferencia, no un cambio de su ficha. */
            DB::table('users')->where('id', $contexto->owner_id)->update($columnas);
        }

        $texto = 'Listo. Pedidos nuevos de la tienda: ' . self::como_se_lee_pedidos($nuevas) . '. Artículos sin stock: ' . self::como_se_lee_stock($nuevas['stock_cero']) . '.';

        if ($nuevas['pedidos'] !== AvisosDelDuenoIaHelper::PEDIDOS_APAGADO || $nuevas['stock_cero']) {
            $texto .= ' Llegan por WhatsApp, desde ahora.';

            $aviso = self::aviso_del_canal($contexto->owner_id);

            if (!is_null($aviso)) {
                $texto .= ' ' . $aviso;
            }
        }

        return ['texto' => $texto];
    }

    /**
     * Qué dice el canal sobre si los avisos van a llegar, o null si llegan siempre.
     *
     * @param  int  $owner_id
     * @return string|null
     */
    public static function aviso_del_canal($owner_id)
    {
        if (!RecordatoriosDelAsistenteHelper::admin_avisa($owner_id)) {

            return self::AVISO_SIN_ADMIN;
        }

        if (!RecordatoriosDelAsistenteHelper::admin_avisa_con_plantilla($owner_id)) {

            return self::AVISO_SIN_PLANTILLA;
        }

        return null;
    }

    /**
     * Los cambios pedidos, validados; o ['faltan' => [...]] si falta un dato.
     *
     * @param  array  $input
     * @param  array  $actuales  AvisosDelDuenoIaHelper::preferencias()
     * @return array
     *
     * @throws AccionIaException
     */
    protected static function cambios(array $input, array $actuales): array
    {
        $cambios = [];

        if (array_key_exists('pedidos_tienda', $input) && !is_null($input['pedidos_tienda'])) {

            $modo = (string) $input['pedidos_tienda'];

            if (!in_array($modo, AvisosDelDuenoIaHelper::MODOS_DE_PEDIDOS, true)) {
                throw new AccionIaException(422, 'pedidos_tienda tiene que ser apagado, al_toque u horas.');
            }

            $cambios['pedidos'] = $modo;
        }

        if (array_key_exists('horas', $input) && !is_null($input['horas'])) {

            $horas = AvisosDelDuenoIaHelper::normalizar_horas($input['horas']);

            if (!count($horas)) {
                throw new AccionIaException(422, 'No entendí las horas. Mandalas como "08:00", "12:00", etc.');
            }

            $cambios['horas'] = $horas;

            if (!isset($cambios['pedidos'])) {
                $cambios['pedidos'] = AvisosDelDuenoIaHelper::PEDIDOS_HORAS;
            }
        }

        if (array_key_exists('stock_cero', $input) && !is_null($input['stock_cero'])) {

            if (!is_bool($input['stock_cero'])) {
                throw new AccionIaException(422, 'stock_cero tiene que ser true (prender) o false (apagar).');
            }

            $cambios['stock_cero'] = $input['stock_cero'];
        }

        if (!count($cambios)) {

            return ['faltan' => ['qué aviso querés prender o apagar (pedidos nuevos de la tienda, al toque o en horas fijas, o artículos que se quedan sin stock)']];
        }

        if (isset($cambios['pedidos']) && $cambios['pedidos'] === AvisosDelDuenoIaHelper::PEDIDOS_HORAS && !isset($cambios['horas']) && !count($actuales['horas'])) {

            return ['faltan' => ['a qué horas revisás la tienda (por ejemplo 08:00, 12:00, 16:00 y 22:00)']];
        }

        return $cambios;
    }

    /**
     * Las preferencias que quedan con los cambios aplicados.
     *
     * @param  array  $actuales
     * @param  array  $cambios
     * @return array
     */
    protected static function aplicadas(array $actuales, array $cambios): array
    {
        $nuevas = $actuales;

        if (isset($cambios['horas']) && is_array($cambios['horas'])) {
            $nuevas['horas'] = AvisosDelDuenoIaHelper::normalizar_horas($cambios['horas']);
        }

        if (isset($cambios['pedidos']) && in_array($cambios['pedidos'], AvisosDelDuenoIaHelper::MODOS_DE_PEDIDOS, true)) {
            $nuevas['pedidos'] = $cambios['pedidos'];
        }

        /* Las horas solo valen en el modo de horas fijas. */
        if ($nuevas['pedidos'] !== AvisosDelDuenoIaHelper::PEDIDOS_HORAS) {
            $nuevas['horas'] = [];
        }

        if (array_key_exists('stock_cero', $cambios)) {
            $nuevas['stock_cero'] = (bool) $cambios['stock_cero'];
        }

        return $nuevas;
    }

    /** «apagado», «al toque» o «a las 08:00, 12:00 y 22:00». */
    protected static function como_se_lee_pedidos(array $preferencias): string
    {
        if ($preferencias['pedidos'] === AvisosDelDuenoIaHelper::PEDIDOS_AL_TOQUE) {

            return 'al toque, uno por pedido';
        }

        if ($preferencias['pedidos'] === AvisosDelDuenoIaHelper::PEDIDOS_HORAS && count($preferencias['horas'])) {

            $horas = $preferencias['horas'];
            $ultima = array_pop($horas);

            return 'un resumen a las ' . (count($horas) ? implode(', ', $horas) . ' y ' : '') . $ultima;
        }

        return 'apagado';
    }

    protected static function como_se_lee_stock($prendido): string
    {
        return $prendido ? 'prendido (uno por artículo y por día)' : 'apagado';
    }

    /**
     * Solo el dueño o quien puede usar el asistente (los avisos le llegan al dueño), y con la versión del
     * sistema que tiene las columnas.
     *
     * @throws AccionIaException
     */
    protected static function validar_contexto(ContextoDeCargaIa $contexto)
    {
        if (!$contexto->owner || !$contexto->persona) {
            throw new AccionIaException(422, 'No pude identificar el negocio de esta conversación.');
        }

        if ((int) $contexto->persona->id !== (int) $contexto->owner_id && !IaAppHelper::puede_usar_el_asistente($contexto->persona)) {
            throw new AccionIaException(422, 'Los avisos por WhatsApp los configura el dueño o un administrador.');
        }

        if (!AvisosDelDuenoIaHelper::hay_columnas()) {
            throw new AccionIaException(422, 'Esta versión del sistema todavía no tiene los avisos por WhatsApp. Pedile a soporte que revise la actualización.');
        }
    }
}
