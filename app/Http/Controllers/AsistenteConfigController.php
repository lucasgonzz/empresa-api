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
 * Las tres rutas van con el gate del chat (auth:sanctum + check_extencion_empresa:asistente_ia +
 * solo_el_dueno_ia): es la misma decisión de Lucas de que solo el dueño (o admin_access / acceso
 * maestro) toca el módulo IA.
 *
 * 🔴 LA CONFIG SE GUARDA Y SE LEE DEL DUEÑO, NO DE LA PERSONA. A diferencia de
 * UserController::set_chat_ia_preferencias() —que guarda con Auth::user() porque una coordenada de
 * pantalla es de cada persona—, la confianza, el proveedor y el modelo del agente son del COMERCIO:
 * el asistente contesta lo mismo abra quien lo abra. Por eso se resuelve con UserHelper::user(true),
 * que devuelve el dueño aunque quien esté autenticado sea un admin_access.
 *
 * Misión proveedores-ia-deepseek (22/9/2026): se suma el PROVEEDOR (`users.agente_proveedor`,
 * Claude o DeepSeek). Los enums de proveedor y de pensamiento POR proveedor viven en
 * ProveedorIaHelper y no se duplican acá: DeepSeek no tiene `equilibrado`, y un proveedor sin clave
 * en la instalación no se puede elegir (422 con mensaje). `proveedor` es OPCIONAL en el PUT porque
 * una SPA vieja no lo manda y tiene que seguir guardando confianza y pensamiento sin tocarlo.
 *
 * Misión asistente-capacidades-y-hilos (22/9/2026): la confianza pasa a tener TRES niveles, con
 * `directo` como el más suelto (ver ConfianzaDelAgenteIaHelper). El enum y el default no se
 * duplican acá: salen de ese helper, que es el que además leen la puerta de auto-confirmación y el
 * prompt. El default sigue siendo `resuelto`, así que ninguna cuenta cambia de comportamiento sin
 * que alguien prenda el modo directo a mano.
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
     * `confianza` y `pensamiento` son obligatorias: el modal las manda juntas. `proveedor` es
     * opcional (una SPA vieja no lo manda y se conserva el guardado). Todo se valida ANTES de
     * guardar nada: un valor fuera del enum, un proveedor sin clave en la instalación o un
     * pensamiento que ese proveedor no tiene cortan con 422 y la config queda como estaba.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'confianza'   => 'required|in:' . implode(',', self::CONFIANZAS),
            'pensamiento' => 'required|in:' . implode(',', self::PENSAMIENTOS),
            'proveedor'   => 'nullable|in:' . implode(',', ProveedorIaHelper::PROVEEDORES),
        ]);

        $owner = UserHelper::user(true);

        if (is_null($owner)) {

            return response()->json(['message' => 'No se pudo resolver el dueño de la cuenta.'], 409);
        }

        /*
         * El proveedor resultante: el que vino, o el guardado si la SPA no lo manda. Si vino uno
         * sin clave en esta instalación, no se puede elegir (el modal lo muestra como no disponible;
         * esto es la guarda del lado del servidor).
         */
        $vino_proveedor = $request->filled('proveedor');
        $proveedor      = $vino_proveedor
            ? (string) $request->input('proveedor')
            : ProveedorIaHelper::proveedor_elegido($owner);

        if ($vino_proveedor && ! ProveedorIaHelper::hay_credenciales($proveedor)) {

            return response()->json([
                'message' => ProveedorIaHelper::nombre_de($proveedor)
                           . ' no está disponible en esta instalación: falta cargar la clave de la API.',
            ], 422);
        }

        $pensamiento = (string) $request->input('pensamiento');

        if (! in_array($pensamiento, ProveedorIaHelper::pensamientos_de($proveedor), true)) {

            return response()->json([
                'message' => ProveedorIaHelper::nombre_de($proveedor) . ' no tiene el modo de pensamiento "'
                           . $pensamiento . '". Elegí uno de: '
                           . implode(', ', ProveedorIaHelper::pensamientos_de($proveedor)) . '.',
            ], 422);
        }

        $owner->agente_confianza = (string) $request->input('confianza');
        $owner->agente_pensamiento = $pensamiento;

        if ($vino_proveedor) {
            $owner->agente_proveedor = $proveedor;
        }

        $owner->save();

        return response()->json($this->config_de($owner), 200);
    }

    /**
     * GET api/mi-consumo-ia → lo que muestra el footer del panel: consumo del mes contra el tope del
     * plan, más el modo activo del agente (proveedor, pensamiento y el id del modelo efectivo).
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
     * `proveedor` es el ELEGIDO (lo que el dueño guardó); `modelo` es con lo que efectivamente
     * corre el asistente (si el elegido no tiene clave, ahí se ve al que cayó). Las dos listas son
     * para que el modal deshabilite lo que no está disponible y filtre las variantes por proveedor
     * sin tener la lista duplicada del lado de la SPA.
     *
     * @param  \App\Models\User  $owner
     * @return array
     */
    protected function config_de($owner): array
    {
        $modelo = ProveedorIaHelper::modelo_del_asistente($owner);

        return [
            'confianza'                  => $this->confianza_de($owner),
            'pensamiento'                => $this->pensamiento_de($owner),
            'proveedor'                  => ProveedorIaHelper::proveedor_elegido($owner),
            'proveedores_disponibles'    => ProveedorIaHelper::proveedores_disponibles(),
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
