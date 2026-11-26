<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Lógica de las formas de envío propias (`delivery_zones`) que no va en el controlador.
 *
 * Misión truvari-carrito-entrega-pago (26/11/2026): una forma puede ser gratis desde un monto de
 * compra (`envio_gratis_desde`) y/o cuando el pedido tiene un combo o promoción
 * (`envio_gratis_con_combo`).
 */
class DeliveryZoneHelper
{
    /**
     * Atributos de envío gratis que el alta o la edición tienen que guardar.
     *
     * Reglas:
     *  - Una columna que la base todavía no tiene (la ventana del deploy antes de migrar) no se escribe.
     *  - Con `costo_a_convenir` el costo lo arregla el vendedor: no hay "gratis" que ofrecer, así que se
     *    guarda null y 0.
     *  - Si el request no trae la clave (pantalla abierta desde antes del deploy) se conserva lo guardado
     *    (null / 0 en un alta).
     *  - `envio_gratis_desde`: un número mayor que 0 se guarda redondeado a 2 decimales; vacío, 0,
     *    negativo o no numérico se guarda null.
     *  - `envio_gratis_con_combo`: se guarda 1 o 0; una clave en null cuenta como ausente.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\DeliveryZone|null  $model  La forma guardada (null en un alta).
     * @param  bool  $costo_a_convenir  Si la forma queda con el costo a convenir.
     * @return array<string, mixed>  Atributos para escribir en la forma.
     */
    public static function atributos_de_envio_gratis(Request $request, $model, $costo_a_convenir)
    {
        $atributos = [];

        $hay_desde = Schema::hasColumn('delivery_zones', 'envio_gratis_desde');
        $hay_con_combo = Schema::hasColumn('delivery_zones', 'envio_gratis_con_combo');

        if ($hay_desde) {
            if ($costo_a_convenir) {
                $atributos['envio_gratis_desde'] = null;
            } elseif ($request->has('envio_gratis_desde')) {
                $atributos['envio_gratis_desde'] = self::normalizar_monto($request->input('envio_gratis_desde'));
            } else {
                $atributos['envio_gratis_desde'] = $model ? $model->envio_gratis_desde : null;
            }
        }

        if ($hay_con_combo) {
            if ($costo_a_convenir) {
                $atributos['envio_gratis_con_combo'] = 0;
            } elseif ($request->has('envio_gratis_con_combo') && !is_null($request->input('envio_gratis_con_combo'))) {
                $atributos['envio_gratis_con_combo'] = $request->boolean('envio_gratis_con_combo') ? 1 : 0;
            } else {
                $atributos['envio_gratis_con_combo'] = $model ? (int) $model->envio_gratis_con_combo : 0;
            }
        }

        return $atributos;
    }

    /**
     * Normaliza el monto mínimo de envío gratis.
     *
     * @param  mixed  $valor  Lo que mandó el formulario.
     * @return float|null  El monto redondeado a 2 decimales, o null si no es un número mayor que 0.
     */
    protected static function normalizar_monto($valor)
    {
        if (!is_numeric($valor) || (float) $valor <= 0) {
            return null;
        }

        return round((float) $valor, 2);
    }
}
