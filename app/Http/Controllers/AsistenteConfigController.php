<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ProveedorIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\TopeDeTokensHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La configuración del asistente de IA del negocio y el consumo de su plan (misión
 * foto-sucursal-y-asistente-configurable, 17/9/2026).
 *
 * Las tres rutas van con el gate del chat (auth:sanctum + check_extencion_empresa:agentes_ia +
 * solo_el_dueno_ia): es la misma decisión de Lucas de que solo el dueño (o admin_access / acceso
 * maestro) toca el módulo IA.
 *
 * 🔴 LA CONFIG SE GUARDA Y SE LEE DEL DUEÑO, NO DE LA PERSONA. A diferencia de
 * UserController::set_chat_ia_preferencias() —que guarda con Auth::user() porque una coordenada de
 * pantalla es de cada persona—, la confianza y el modo de pensamiento del agente son del COMERCIO:
 * el asistente contesta lo mismo abra quien lo abra. Por eso se resuelve con UserHelper::user(true),
 * que devuelve el dueño aunque quien esté autenticado sea un admin_access.
 *
 * El agente usa DeepSeek. La API conserva el campo proveedor para las versiones anteriores de la SPA.
 */
class AsistenteConfigController extends Controller
{
    /** Valores válidos de `agente_confianza` (cauteloso, resuelto, directo). */
    const CONFIANZAS = ConfianzaDelAgenteIaHelper::MODOS;

    /**
     * La UNIÓN de los valores de `agente_pensamiento` de todos los proveedores, para el mensaje de
     * validación del enum. La validez POR proveedor la decide ProveedorIaHelper::pensamientos_de().
     */
    const PENSAMIENTOS = ['agil', 'equilibrado', 'profundo'];

    /** Default de la confianza (coincide con el default de la columna). */
    const CONFIANZA_POR_DEFECTO = ConfianzaDelAgenteIaHelper::POR_DEFECTO;

    /** Default del modelo (coincide con el default de la columna y con el del helper). */
    const PENSAMIENTO_POR_DEFECTO = ProveedorIaHelper::PENSAMIENTO_POR_DEFECTO;

    /**
     * GET api/user/asistente-config → la config del dueño (ver config_de()).
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        $owner = UserHelper::user(true);

        if (is_null($owner)) {

            return response()->json(['message' => 'No se pudo resolver el dueño de la cuenta.'], 409);
        }

        return response()->json($this->config_de($owner), 200);
    }

    /**
     * PUT api/user/asistente-config → valida los enums y guarda en el dueño.
     *
     * La confianza y el pensamiento son obligatorios. Las SPA anteriores pueden seguir
     * enviando el proveedor y el modo Equilibrado.
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'confianza'   => 'required|in:' . implode(',', self::CONFIANZAS),
            'pensamiento' => 'required|in:' . implode(',', self::PENSAMIENTOS),
            'proveedor'   => 'nullable|in:' . implode(',', ProveedorIaHelper::PROVEEDORES),
            'alta_bebidas' => 'sometimes|boolean',
        ]);

        $owner = UserHelper::user(true);

        if (is_null($owner)) {

            return response()->json(['message' => 'No se pudo resolver el dueño de la cuenta.'], 409);
        }

        // Los clientes anteriores todavía pueden enviar proveedor y el modo Equilibrado.
        if ($request->has('alta_bebidas')) {
            if ($request->boolean('alta_bebidas') && !\App\Http\Controllers\Helpers\asistente_ia\AltaBebidasIaHelper::disponible($owner)) {
                return response()->json(['message' => 'El alta completa de bebidas requiere Vinoteca.'], 422);
            }
            $owner->agente_alta_bebidas = $request->boolean('alta_bebidas');
        }
        $pensamiento = (string) $request->input('pensamiento');
        $owner->agente_confianza = (string) $request->input('confianza');
        $owner->agente_pensamiento = $pensamiento === 'equilibrado'
            ? ProveedorIaHelper::PENSAMIENTO_POR_DEFECTO
            : $pensamiento;
        $owner->agente_proveedor = ProveedorIaHelper::DEEPSEEK;
        $owner->save();

        return response()->json($this->config_de($owner), 200);
    }

    /**
     * GET api/mi-consumo-ia → lo que muestra el footer del panel: consumo del mes contra el tope del
     * plan, más el modo activo del agente (proveedor, pensamiento y el id del modelo efectivo).
     *
     * Desde la misión agente-memoria-y-sin-techo (ronda de arreglos R7) suma, de forma aditiva,
     * `supero_tokens` y `supero_interacciones` (cuál de los dos topes se pasó).
     *
     * 🔴 Misión plan-ia-por-gasto (2/11/2026): ESTA RESPUESTA NO LLEVA DÓLARES NI PRECIOS, la ve el dueño. Con
     * tope de gasto suma `porcentaje_del_plan` (entero de 0 a 100; null sin ese tope, y ahí el SPA sigue con
     * los tokens) y `plan.tope_tokens_mensual` sale null, para que un SPA viejo saque su barra en vez de
     * mostrar un tope que ya no corta.
     *
     * @return JsonResponse
     */
    public function mi_consumo(): JsonResponse
    {
        $owner = UserHelper::user(true);

        if (is_null($owner)) {

            return response()->json(['message' => 'No se pudo resolver el dueño de la cuenta.'], 409);
        }

        $estado = TopeDeTokensHelper::estado($owner);
        $modelo = ProveedorIaHelper::modelo_del_asistente($owner);

        return response()->json([
            'consumo_mes' => [
                'tokens'        => $estado['consumo_tokens'],
                'interacciones' => $estado['consumo_interacciones'],
            ],
            'plan' => [
                'nombre'                     => is_null($owner->plan_ia_nombre) ? null : (string) $owner->plan_ia_nombre,
                'tope_tokens_mensual'        => $estado['tope_tokens'],
                'tope_interacciones_diarias' => $estado['tope_interacciones'],
            ],
            'cerca'       => $estado['cerca'],
            'supero'      => $estado['supero'],
            /*
             * Misión agente-memoria-y-sin-techo (ronda de arreglos R7, 30/10/2026): CUÁL tope se pasó,
             * aditivo, para que las SPA puedan decir «hoy» (interacciones, se renueva mañana) o «este mes»
             * (tokens). `supero` sigue siendo el O de los dos; una SPA vieja no las lee.
             */
            'supero_tokens'        => $estado['supero_tokens'] || $estado['supero_gasto'],
            'supero_interacciones' => $estado['supero_interacciones'],
            /*
             * Misión plan-ia-por-gasto: 🔴 `supero_tokens` acá quiere decir «se pasó el tope del MES», sea de
             * tokens o de gasto, y no se suma una `supero_gasto`. Los dos SPA (este y ia-spa) dicen «mañana se
             * renueva» con `supero_interacciones && !supero_tokens`: con los dos topes pasados, un SPA viejo le
             * mentiría al dueño si esta clave quedara en false.
             *
             * `cerca_del_mes` es para decir de qué tope se está cerca: `cerca` también se prende por las
             * interacciones de hoy, y «Usaste 12 % de tu plan · te estás acercando al límite» no se entiende.
             */
            'porcentaje_del_plan'  => TopeDeTokensHelper::porcentaje_del_plan($estado),
            'cerca_del_mes'        => $estado['cerca_del_mes'],
            /*
             * Misión proveedores-ia-deepseek: el footer describe con qué CORRE el asistente, así que
             * los tres salen de modelo_del_asistente(): el proveedor efectivo (con el fallback de
             * clave), el pensamiento válido para ese proveedor y el id del modelo. Si el dueño guardó
             * `equilibrado` y el asistente cayó a DeepSeek, acá dice `agil`, que es lo que corre.
             */
            'pensamiento' => $modelo['pensamiento'],
            'confianza'   => $this->confianza_de($owner),
            'proveedor'   => $modelo['proveedor'],
            'modelo'      => $modelo['modelo'],
        ], 200);
    }

    /**
     * La config del dueño, con los defaults por si alguna columna quedó en null:
     *
     *   { confianza, pensamiento, proveedor, proveedores_disponibles: [...],
     *     pensamientos_por_proveedor: {anthropic: [...], deepseek: [...]},
     *     modelo: {proveedor, modelo, pensamiento} }
     *
     * Los campos de proveedor se conservan para las versiones anteriores de la SPA.
     *
     * @param  \App\Models\User  $owner
     * @return array
     */
    protected function config_de($owner): array
    {
        $modelo = ProveedorIaHelper::modelo_del_asistente($owner);

        return [
            'alta_bebidas' => \App\Http\Controllers\Helpers\asistente_ia\AltaBebidasIaHelper::activa($owner),
            'alta_bebidas_disponible' => \App\Http\Controllers\Helpers\asistente_ia\AltaBebidasIaHelper::disponible($owner),
            'confianza'                  => $this->confianza_de($owner),
            'pensamiento'                => $this->pensamiento_de($owner),
            'proveedor'                  => ProveedorIaHelper::proveedor_elegido($owner),
            'proveedores_disponibles'    => ProveedorIaHelper::hay_credenciales(ProveedorIaHelper::DEEPSEEK)
                ? [ProveedorIaHelper::DEEPSEEK]
                : [],
            'pensamientos_por_proveedor' => ProveedorIaHelper::PENSAMIENTOS_POR_PROVEEDOR,
            'modelo'                     => [
                'proveedor'   => $modelo['proveedor'],
                'modelo'      => $modelo['modelo'],
                'pensamiento' => $modelo['pensamiento'],
            ],
        ];
    }

    /**
     * La confianza del dueño, cayendo al default si viene vacía o fuera del enum.
     *
     * @param  \App\Models\User  $owner
     * @return string
     */
    protected function confianza_de($owner): string
    {
        return ConfianzaDelAgenteIaHelper::con_default($owner);
    }

    /**
     * El modo de pensamiento del dueño, válido para SU proveedor (el elegido): cae al default si
     * viene vacío, fuera del enum o si ese proveedor no lo tiene — así el footer nunca muestra
     * `equilibrado` para un dueño en DeepSeek.
     *
     * @param  \App\Models\User  $owner
     * @return string
     */
    protected function pensamiento_de($owner): string
    {
        return ProveedorIaHelper::pensamiento_de($owner, ProveedorIaHelper::proveedor_elegido($owner));
    }
}
