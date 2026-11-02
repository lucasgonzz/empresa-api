<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Http\Controllers\Helpers\asistente_ia\TopeDeTokensHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * El plan de IA que empuja el admin (misión foto-sucursal-y-asistente-configurable, 17/9/2026).
 *
 * El admin maneja los paquetes de IA y su precio; cuando le asigna uno a un cliente, pushea acá el
 * nombre y los dos topes con `PUT api/admin-sync/plan-ia`. Este proyecto los guarda en el DUEÑO, y
 * el footer del chat los muestra (consumo del mes contra el tope) y el corte por tope los usa.
 *
 * 🔴 LA CLAVE SE VALIDA ADENTRO DEL CONTROLADOR, no en el middleware. Igual que
 * ConsumoIaController y el canal de WhatsApp: el middleware del grupo (AdminApiKey) no exige nada
 * mientras `services.admin_api.require_api_key` esté apagado, que es como está en producción. Este
 * endpoint ESCRIBE el plan del cliente, así que valida el header por su cuenta —pero solo si el
 * cliente tiene la clave cargada de este lado—, con el mismo criterio de rechazo_por_clave() de
 * ConsumoIaController: estrictamente más seguro que el status quo, sin romper a los clientes que
 * todavía no tienen ADMIN_API_INBOUND_KEY en su .env.
 *
 * 🔴 LAS CLAVES DEL BODY SON ESPEJO EXACTO del emisor (contrato): `nombre`, `tope_tokens_mensual`,
 * `tope_interacciones_diarias`. Es el punto donde este proyecto ya se quemó (`manual_tasks` vs
 * `tareas`): un renombre de un lado deja al otro leyendo null sin un solo error.
 *
 * 🔴 `tope_busquedas_web_diarias` ES LA CUARTA CLAVE Y ES OPCIONAL (misión
 * asistente-fotos-barras-y-compras, 24/9/2026): el tope diario de búsquedas por código de barras en
 * internet. Un admin viejo no la manda, y en ese caso la columna NO SE TOCA —ni se pone en null—:
 * el negocio sigue con lo que tenía (o con el defecto de 30 si nunca le llegó nada). Por eso se
 * pregunta `has()` y no `input()`, que no distingue "no vino" de "vino null".
 *
 * 🔴 `tope_gasto_usd_mensual` Y `precios_ia` SON LA QUINTA Y LA SEXTA, TAMBIÉN OPCIONALES (misión
 * plan-ia-por-gasto, 2/11/2026): el tope del mes en dólares y la tabla de precios con la que se mide
 * ({modelo: {input, output, cache_write, cache_read}}, dólares por millón, el contenido de
 * config/ia_precios.php del admin). Mismo trato que la cuarta: un admin viejo no las manda, sus columnas
 * no se tocan y el negocio sigue con el tope de tokens. Y como un deploy sube los archivos antes de
 * migrar, las columnas pueden no existir todavía: ahí se ignoran con un warning y el resto del plan se
 * guarda igual, porque un 500 dejaría al cliente sin su plan por algo que se arregla solo. Con la
 * migración corrida, `ia:sincronizar-precios` del admin vuelve a mandarlas.
 *
 * 🔴 Y NO VUELVEN A SALIR: el dueño no ve dólares ni precios (`$hidden` de User, TopeDeTokensHelper).
 *
 * Idempotente: pushear el mismo plan dos veces deja el dueño igual. Un tope 0 o null se guarda como
 * null = sin tope, que es la guarda de compatibilidad (el agente no corta hasta que llega un tope > 0).
 */
class PlanIaController extends Controller
{
    /**
     * PUT api/admin-sync/plan-ia
     *
     * 200 {ok:true} guardado en el dueño
     * 401 la clave del header no coincide con la que tiene cargada este cliente
     * 409 {message} no se pudo resolver el dueño de esta instancia
     * 422 body mal formado
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $rechazo_clave = $this->rechazo_por_clave($request);

        if (! is_null($rechazo_clave)) {

            return $rechazo_clave;
        }

        $request->validate([
            'nombre'                     => 'nullable|string|max:255',
            'tope_tokens_mensual'        => 'nullable|integer|min:0',
            'tope_interacciones_diarias' => 'nullable|integer|min:0',
            'tope_busquedas_web_diarias' => 'nullable|integer|min:0',
            /* El máximo es el de decimal(10,2): más grande, la base rechaza el UPDATE con un 500. */
            'tope_gasto_usd_mensual'     => 'nullable|numeric|min:0|max:99999999.99',
            'precios_ia'                 => 'nullable|array',
        ]);

        /* Se valida antes de tocar al dueño: un 422 tiene que dejar el plan como estaba. */
        $precios_ia = $request->has('precios_ia') ? $this->precios_limpios($request->input('precios_ia')) : null;

        $dueno = AsistenteCanalHelper::dueno();

        if (is_null($dueno)) {

            return response()->json([
                'message' => 'No se pudo resolver el dueño de esta instancia. '
                           . 'Si la base la comparten varios comercios, falta USER_ID en el .env de este frente.',
            ], 409);
        }

        $dueno->plan_ia_nombre = $this->nombre_limpio($request->input('nombre'));
        $dueno->plan_ia_tope_tokens_mensual = $this->tope($request->input('tope_tokens_mensual'));
        $dueno->plan_ia_tope_interacciones_diarias = $this->tope($request->input('tope_interacciones_diarias'));

        /* Solo si la clave vino (ver el 🔴 del docblock): null o 0 → null = el defecto de config. */
        if ($request->has('tope_busquedas_web_diarias')) {

            $dueno->plan_ia_tope_busquedas_web_diarias = $this->tope($request->input('tope_busquedas_web_diarias'));
        }

        /* Las dos del gasto, cada una solo si vino y si su columna ya existe (ver el 🔴 del docblock). */
        if ($request->has('tope_gasto_usd_mensual') && $this->columna_existe('plan_ia_tope_gasto_usd_mensual')) {

            $dueno->plan_ia_tope_gasto_usd_mensual = $this->tope_en_dolares($request->input('tope_gasto_usd_mensual'));
        }

        if ($request->has('precios_ia') && $this->columna_existe('plan_ia_precios')) {

            $dueno->plan_ia_precios = is_null($precios_ia) ? null : json_encode($precios_ia);
        }

        $dueno->save();

        return response()->json(['ok' => true], 200);
    }

    /**
     * Un tope en dólares tal como se guarda: redondeado a centavos, y 0 o null es "sin tope de gasto".
     *
     * @param  mixed  $valor
     * @return float|null
     */
    protected function tope_en_dolares($valor)
    {
        $valor = round((float) $valor, 2);

        return $valor > 0 ? $valor : null;
    }

    /**
     * La tabla de precios tal como se guarda, solo con las cuatro puntas del contrato, o null si vino
     * vacía.
     *
     * 🔴 Una tarifa incompleta es un 422 y no un cero: una punta que falta se leería gratis, y un modelo que
     * la use gastaría sin que el tope se entere. Se valida a mano y no con `precios_ia.*` porque el validador
     * lee esa regla con notación de puntos, y un modelo puede tener un punto en el nombre.
     *
     * @param  mixed  $precios
     * @return array|null
     * @throws ValidationException
     */
    protected function precios_limpios($precios)
    {
        if (empty($precios)) {

            return null;
        }

        $limpios = [];

        foreach ($precios as $modelo => $tarifa) {
            $nombre = is_string($modelo) ? trim($modelo) : '';

            if ($nombre === '' || ! is_array($tarifa)) {
                throw ValidationException::withMessages(['precios_ia' => 'Cada modelo de precios_ia tiene que ser un nombre con su tarifa.']);
            }

            $limpios[$nombre] = [];

            foreach (TopeDeTokensHelper::PUNTAS as $punta) {
                if (! isset($tarifa[$punta]) || ! is_numeric($tarifa[$punta]) || (float) $tarifa[$punta] < 0) {
                    throw ValidationException::withMessages(['precios_ia' => 'La tarifa de ' . $nombre . ' necesita ' . $punta . ', un número mayor o igual a cero.']);
                }

                $limpios[$nombre][$punta] = (float) $tarifa[$punta];
            }
        }

        return $limpios;
    }

    /**
     * Si la columna ya está en la base. Sin ella se avisa al log y la clave se ignora (ver el docblock de
     * la clase). Es una consulta a information_schema, pero este endpoint corre solo cuando cambia un plan.
     *
     * @param  string  $columna
     * @return bool
     */
    protected function columna_existe($columna)
    {
        if (Schema::hasColumn('users', $columna)) {

            return true;
        }

        Log::warning('PlanIaController: llegó una clave del plan de IA sin su columna, se ignora. Falta correr la migración.', [
            'columna' => $columna,
        ]);

        return false;
    }

    /**
     * El nombre del paquete, recortado, o null si vino vacío.
     *
     * @param  mixed  $valor
     * @return string|null
     */
    protected function nombre_limpio($valor)
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : mb_substr($valor, 0, 255);
    }

    /**
     * Un tope tal como se guarda: > 0 es el tope, 0 o null es "sin tope" (null en la base).
     *
     * @param  mixed  $valor
     * @return int|null
     */
    protected function tope($valor)
    {
        if (is_null($valor)) {

            return null;
        }

        $valor = (int) $valor;

        return $valor > 0 ? $valor : null;
    }

    /**
     * Valida el header `X-Admin-Api-Key` SOLO si este cliente tiene la clave cargada. Copia del
     * criterio de ConsumoIaController::rechazo_por_clave() (ver el 🔴 del docblock de la clase).
     *
     * @param  Request  $request
     * @return JsonResponse|null
     */
    protected function rechazo_por_clave(Request $request)
    {
        $esperada = (string) config('services.admin_api.api_key');

        if ($esperada === '') {

            return null;
        }

        $recibida = (string) $request->header('X-Admin-Api-Key');

        if ($recibida === '' || ! hash_equals($esperada, $recibida)) {

            return response()->json(['error' => 'unauthorized'], 401);
        }

        return null;
    }
}
