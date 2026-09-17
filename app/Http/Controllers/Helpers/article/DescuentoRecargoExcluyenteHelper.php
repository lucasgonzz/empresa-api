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
     * Esta es la guarda de `store()`: una fila nueva no tiene nada legado que respetar, asi que
     * alcanza con mirar el contenido del request.
     *
     * Si el request no trae alguna de las dos claves, no hay nada que decidir: una actualizacion
     * parcial que no menciona `percentage` o `amount` pasa derecho.
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
     * La guarda de `update()`: si el request INTRODUCE el conflicto sobre la fila que ya existe.
     *
     * 🔴 Aca si se mira el estado previo, y en `store()` no. No es una asimetria al descuido, y
     * antes de "unificar las dos ramas" hay que leer esto:
     *
     * `aplicar_descuentos()` viene aceptando filas con porcentaje Y monto desde siempre, asi que
     * hay comercios que ya las tienen cargadas. Rechazar todo request que traiga los dos convertia
     * un guardado inocente —abrir un descuento viejo, no tocar nada, apretar Guardar— en un error
     * sobre un dato que el sistema mismo dejo entrar. El usuario no hizo nada malo y se le rompe un
     * flujo que venia andando.
     *
     * La regla no es "validar el estado de la fila", es NO EMPEORAR LO QUE YA ESTABA:
     *
     *   - la fila NO estaba en conflicto y el request la deja en conflicto  -> se rechaza
     *   - la fila YA estaba en conflicto y el request no cambia ninguno de los dos valores -> pasa
     *   - la fila YA estaba en conflicto y el request cambia alguno y sigue en conflicto -> se
     *     rechaza: eso ya es editar el conflicto, que es justamente lo que se quiere impedir
     *
     * @param  \Illuminate\Http\Request $request
     * @param  mixed $model Fila que se esta actualizando (null si no se encontro).
     * @return bool
     */
    static function introduce_conflicto(Request $request, $model)
    {
        if (!self::hay_conflicto($request)) {
            return false;
        }

        if (is_null($model) || !self::fila_en_conflicto($model)) {
            // No habia conflicto antes y lo hay ahora: lo introduce este request.
            return true;
        }

        return self::cambia_alguno_de_los_dos($request, $model);
    }

    /**
     * Si la fila guardada ya tenia los dos campos cargados. Mismo criterio de "vacio" que el
     * request: null, cadena vacia y 0 no cuentan.
     *
     * @param  mixed $model
     * @return bool
     */
    static function fila_en_conflicto($model)
    {
        return self::es_valor_cargado($model->percentage)
            && self::es_valor_cargado($model->amount);
    }

    /**
     * Si el request cambia el porcentaje o el monto respecto de lo que hay guardado.
     *
     * Se compara con `ArticleProviderDiscountHelper::normalizar_porcentaje()`, que es lo mismo que
     * usa el bloque de `editado_a_mano` de `ArticleDiscountController::update()` y por el mismo
     * motivo: sin normalizar, `"10"`, `10.0` y `"10.00"` cuentan como distintos y un guardado
     * inocente entraria por la rama equivocada.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  mixed $model
     * @return bool
     */
    static function cambia_alguno_de_los_dos(Request $request, $model)
    {
        if (ArticleProviderDiscountHelper::normalizar_porcentaje($model->percentage)
            !== ArticleProviderDiscountHelper::normalizar_porcentaje($request->percentage)) {
            return true;
        }

        return ArticleProviderDiscountHelper::normalizar_porcentaje($model->amount)
            !== ArticleProviderDiscountHelper::normalizar_porcentaje($request->amount);
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
