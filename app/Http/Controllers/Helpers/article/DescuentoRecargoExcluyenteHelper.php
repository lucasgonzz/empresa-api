<?php

namespace App\Http\Controllers\Helpers\article;

use Illuminate\Http\Request;

/**
 * Un descuento (o un recargo) de articulo lleva SOLO porcentaje o SOLO monto, nunca los dos.
 *
 * Por que no es cosmetico: `ArticlePricesHelper::aplicar_descuentos()` (y su par de recargos)
 * aplica el porcentaje si lo hay y solo mira el monto cuando NO hay porcentaje. O sea que hasta
 * hoy se podian guardar los dos y el monto quedaba inerte, ignorado en silencio.
 *
 * La SPA ya apaga el campo de al lado en vivo (clave declarativa `deshabilitado_si_hay` en
 * `ModelForm.vue`), pero eso no alcanza: `empresa-api` y `empresa-spa` se despliegan por separado
 * y nunca llegan juntas a produccion, y ademas cualquier cliente HTTP puede postear las dos
 * columnas. Esta es la guarda del lado de la API.
 */
class DescuentoRecargoExcluyenteHelper
{

    const MENSAJE = 'Un descuento o un recargo lleva solamente el porcentaje o solamente el monto, no los dos. Dejá vacío el que no uses.';

    /**
     * Codigo con el que el resto de los controllers de articulos contesta un error de validacion.
     */
    const STATUS = 422;

    /**
     * Si un valor mandado en el request cuenta como "cargado".
     *
     * 🔴 Se pregunta por el VACIO, no por null. `''` no es null: un input que el usuario limpio
     * llega como cadena vacia, `is_null('')` da false y `'' ?? 'x'` devuelve `''`. Preguntando por
     * null, limpiar el porcentaje dejaba el monto trabado para siempre.
     *
     * El `0` tambien cuenta como VACIO, a proposito y por la misma razon que en la SPA: un
     * descuento de 0% no descuenta nada y un recargo de $0 no recarga nada, asi que no tienen por
     * que chocar con el otro campo.
     *
     * @param  mixed $valor
     * @return bool
     */
    static function es_valor_cargado($valor)
    {
        if (is_null($valor)) {
            return false;
        }

        if (is_string($valor) && trim($valor) === '') {
            return false;
        }

        if (is_array($valor)) {
            return false;
        }

        return floatval($valor) != 0;
    }

    /**
     * Si el request trae porcentaje Y monto, los dos con un valor utilizable.
     *
     * 🔴 Se mira el CONTENIDO DEL REQUEST, nunca el estado de la fila guardada. Aca es donde
     * alguien va a estar tentado de preguntarle al modelo si ya tiene los dos campos cargados — no
     * lo hagas: las filas viejas que quedaron con los dos son legitimas y la regla rige para lo que
     * entra de ahora en adelante. Si se validara el estado de la fila, un `update()` que ni toca
     * estos campos (cambiar el tipo, el "mostrar en la tienda") reventaria sobre una fila vieja.
     *
     * Por eso mismo, si el request no trae alguna de las dos claves, no hay nada que decidir: una
     * actualizacion parcial que no menciona `percentage` o `amount` pasa derecho.
     *
     * @param  \Illuminate\Http\Request $request
     * @return bool
     */
    static function hay_conflicto(Request $request)
    {
        if (!$request->has('percentage') || !$request->has('amount')) {
            return false;
        }

        return self::es_valor_cargado($request->percentage)
            && self::es_valor_cargado($request->amount);
    }

    /**
     * La respuesta que se le devuelve al que mando los dos campos.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    static function respuesta_de_conflicto()
    {
        return response()->json(['message' => self::MENSAJE], self::STATUS);
    }
}
