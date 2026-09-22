<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\asistente_ia\PermisosIaHelper;
use App\Http\Controllers\Helpers\sale\VentasSinCobrarHelper;
use App\Models\User;
use App\Services\Mostrador\RecolectorBase;
use Illuminate\Support\Facades\DB;

/**
 * "¿CUÁNTA PLATA TENGO SIN COBRAR?" — LAS VENTAS IMPAGAS DE TODO EL NEGOCIO.
 *
 * Misión asistente-ventas-y-fotos (21/9/2026). Hasta hoy el asistente solo sabía contestar esa
 * pregunta POR CLIENTE (`ConsultasSistemaIaHelper::ventas_impagas_de_un_cliente`), así que "cuánto
 * me deben en total" terminaba en una ronda de consultas cliente por cliente, o directamente sin
 * respuesta. Es el mismo hueco que ya tenía el resumen de ventas antes de
 * `ResumenDeVentasIaHelper`, y se tapa igual: una consulta que devuelve el total del negocio y el
 * ranking, no una página de filas para que el modelo sume a mano.
 *
 * 🔴 EL CONJUNTO ES EL DE LA PANTALLA "VENTAS SIN COBRAR", y sale de
 * `VentasSinCobrarHelper::query_de_ventas($owner_id, $employee_id, $dias)` — la MISMA query que usan
 * el listado de la pantalla y el recordatorio de cobro por WhatsApp. No se reescribe acá: una segunda
 * definición de "venta impaga" es el camino más corto a que la pantalla y el chat le den dos
 * números distintos al mismo comerciante.
 *
 * 🔴 DE QUÉ UNIVERSO HABLA, PORQUE NO ES OBVIO Y EL MODELO LO IBA A INVENTAR. Acá adentro solo hay
 * ventas que generaron CUENTA CORRIENTE y todavía tienen deuda. Una venta de mostrador en efectivo
 * no tiene ningún registro de cobranza: no aparece porque no hay deuda, no porque exista un dato
 * que diga "esta se cobró". Por eso la respuesta NO trae ni puede traer "cuántas ventas están
 * cobradas": el complemento de este conjunto son "las que no deben nada", que es otra cosa. La
 * descripción de la tool lo dice con todas las letras.
 *
 * ⚠️ El `dias` es el umbral de antigüedad de la query compartida, y `COALESCE` con el umbral propio
 * de cada venta (`sales.dias_alerta_venta_no_cobrada_personalizado`) hace que el de la venta gane
 * siempre. Con `dias = 0` es "todas", salvo las que tengan su propio umbral cargado y todavía no lo
 * cumplan. Si el modelo no manda ninguno, sale de la cascada por rol de la pantalla (ver
 * `dias_de_la_cascada()`).
 *
 * 🔴 Y ESPEJAR LA PANTALLA ES TAMBIÉN ESPEJAR A QUIÉN LE MUESTRA QUÉ. La pantalla calcula
 * `$ver_solo_las_ventas_suyas = true` por defecto y solo lo apaga para el dueño o para quien tenga
 * `ver_alertas_de_todos_los_empleados`. Sin ese recorte acá, un vendedor que en la pantalla ve
 * únicamente sus ventas le preguntaba al asistente y recibía el total del negocio, el ranking de los
 * veinte clientes que más deben con nombre y monto, y la venta más vieja de cualquier compañero. Por
 * eso el recorte y el umbral salen de la PERSONA que escribe (`$contexto->persona`), no del dueño.
 */
class VentasSinCobrarIaHelper
{
    /** Cuántos clientes deudores entran en el ranking. */
    const TOPE_DE_CLIENTES = 20;

    /** Techo del `dias` que puede pedir el modelo: más que eso no acota nada. */
    const TOPE_DIAS = 3650;

    /**
     * LAS VENTAS SIN COBRAR DEL NEGOCIO, CON EL ALCANCE QUE TIENE QUIEN PREGUNTA.
     *
     * @param  int  $owner_id  Dueño (`sales.user_id`). Nunca Auth: esta tool corre en un job sin sesión.
     * @param  int|null  $dias  Umbral general de antigüedad. 0 = sin umbral general; null = el de la
     *                          cascada por rol de la pantalla.
     * @param  \App\Models\User|null  $persona  Quien le escribe al asistente. null = sin recorte (ver
     *                                          `employee_id_del_recorte()`).
     * @return array<string, mixed>  Con la clave `error` cuando el pedido no se puede atender
     */
    public static function ventas_sin_cobrar(int $owner_id, $dias = null, $persona = null): array
    {
        $owner = User::find($owner_id);

        if (is_null($owner)) {
            return ['error' => 'No se encontro el negocio.'];
        }

        $employee_id = self::employee_id_del_recorte($persona, $owner_id);

        $dias = self::umbral_de_dias($dias, $persona, $owner);

        $base = VentasSinCobrarHelper::query_de_ventas($owner_id, $employee_id, $dias);

        /*
         * Los totales se calculan sobre las filas de `current_acounts` del conjunto y NO sobre las
         * ventas: la deuda viva es `debe - pagandose` de la cuenta corriente, no el total de la
         * venta (que puede tener una parte ya cobrada). La condición que elige esas filas es la
         * MISMA que puso a la venta en el conjunto, invocada y no copiada.
         */
        $condicion = VentasSinCobrarHelper::condicion_de_deuda();

        $pesos = implode(',', array_map('intval', RecolectorBase::MONEDAS_PESOS));

        /*
         * 🔴 LA MONEDA SE RESUELVE COMO LA RESUELVE EL MODELO, Y POR ESO HAY UN JOIN.
         *
         * `current_acounts.moneda_id` viene NULL en TODA fila de deuda nacida de una venta:
         * `CurrentAcountFromSaleHelper::crear_current_acount()` —que es el único camino— nunca la
         * escribe. La moneda real vive en la fila de `credit_accounts` de la que cuelga la cuenta
         * (hay una por cliente y por moneda), y por eso el modelo tiene
         * `CurrentAcount::getMonedaIdAttribute()`, que cae al `credit_account`.
         *
         * Con el `COALESCE(moneda_id, 1)` pelado que había acá, ese NULL se leía como pesos SIEMPRE:
         * toda la deuda entraba en `total_pendiente_en_pesos` y `ventas_en_otra_moneda` daba 0 fijo.
         * A un comercio con la extensión de ventas en dólares se le sumaban pesos y dólares como si
         * fueran la misma unidad, justo lo contrario de lo que promete la descripción de la tool.
         *
         * 🔴 Las DOS puntas de esta respuesta tienen que resolver la moneda por el MISMO camino:
         * `venta_mas_vieja()` ya lo hacía por el accesor, así que una venta en dólares podía venir
         * con `en_pesos = false` mientras su deuda ya estaba sumada al total "en pesos". Ese
         * desacuerdo adentro de la misma respuesta es la prueba de que era un defecto y no una
         * decisión.
         *
         * El último `1` del COALESCE es el mismo "sin moneda en ningún lado se lee como pesos" del
         * accesor (que devuelve null) y de `venta_mas_vieja()` (que lee null como pesos).
         */
        $moneda = 'COALESCE(current_acounts.moneda_id, credit_accounts.moneda_id, 1)';

        /*
         * El LEFT JOIN no puede duplicar filas: `credit_account_id` es un belongsTo, así que trae a
         * lo sumo una. Y `condicion_de_deuda()` avisa en su docblock que sus columnas van sin
         * calificar y que el caller que joinea tiene que fijarse: `debe`, `status` y `pagandose` no
         * existen en `credit_accounts`, así que no hay ambigüedad. Las que sí compartirían nombre
         * (`saldo`, `user_id`, `moneda_id`, `id`) van calificadas acá.
         */
        $filas = DB::table('current_acounts')
            ->leftJoin('credit_accounts', 'credit_accounts.id', '=', 'current_acounts.credit_account_id')
            ->whereIn('current_acounts.sale_id', (clone $base)->select('sales.id'))
            ->where($condicion)
            ->groupByRaw('COALESCE(current_acounts.client_id, 0)')
            ->selectRaw(
                'COALESCE(current_acounts.client_id, 0) as cliente_id, '
                . 'COUNT(*) as ventas, '
                . 'SUM(CASE WHEN ' . $moneda . ' IN (' . $pesos . ') THEN 1 ELSE 0 END) as ventas_en_pesos, '
                . 'SUM(CASE WHEN ' . $moneda . ' IN (' . $pesos . ') THEN current_acounts.debe - COALESCE(current_acounts.pagandose, 0) ELSE 0 END) as pendiente_en_pesos'
            )
            ->get();

        $ventas_totales = 0;
        $ventas_en_otra_moneda = 0;
        $pendiente_total = 0.0;
        $sin_cliente = 0;
        $por_cliente = [];
        $ids = [];

        foreach ($filas as $fila) {
            $cliente_id = (int) $fila->cliente_id;
            $ventas = (int) $fila->ventas;
            $en_pesos = (int) $fila->ventas_en_pesos;
            $pendiente = round((float) $fila->pendiente_en_pesos, 2);

            $ventas_totales += $ventas;
            $ventas_en_otra_moneda += ($ventas - $en_pesos);
            $pendiente_total += $pendiente;

            if ($cliente_id <= 0) {
                $sin_cliente += $ventas;
                continue;
            }

            $ids[] = $cliente_id;

            $por_cliente[] = [
                'cliente_id'                 => $cliente_id,
                'ventas_sin_cobrar'          => $ventas,
                'pendiente_en_pesos'         => $pendiente,
                'ventas_en_otra_moneda'      => $ventas - $en_pesos,
            ];
        }

        // Con borrados: el cliente pudo borrarse después de la venta, y la deuda sigue siendo real.
        $nombres = empty($ids) ? [] : DB::table('clients')->whereIn('id', $ids)->pluck('name', 'id')->map(function ($v) {
            return (string) $v;
        })->all();

        usort($por_cliente, function ($a, $b) {
            if ($a['pendiente_en_pesos'] == $b['pendiente_en_pesos']) {
                return $b['ventas_sin_cobrar'] <=> $a['ventas_sin_cobrar'];
            }

            return $b['pendiente_en_pesos'] <=> $a['pendiente_en_pesos'];
        });

        $clientes_encontrados = count($por_cliente);

        $lista = [];

        foreach (array_slice($por_cliente, 0, self::TOPE_DE_CLIENTES) as $cliente) {
            $id = $cliente['cliente_id'];

            $lista[] = array_merge(
                ['cliente' => isset($nombres[$id]) ? $nombres[$id] : 'cliente borrado'],
                $cliente
            );
        }

        return [
            'criterio' => 'Las ventas que todavia tienen deuda en la cuenta corriente del cliente: el MISMO conjunto que la pantalla "Ventas sin cobrar" del sistema. Una venta de mostrador en efectivo no esta aca porque no genero deuda, no porque exista un dato que diga que se cobro.',
            // Que el modelo sepa si esta mirando todo el negocio o solo lo de quien le escribe: sin
            // esto presentaria el numero recortado como el total, que es mentirle al que pregunta.
            'alcance'                                => is_null($employee_id)
                ? 'Todas las ventas del negocio.'
                : 'SOLO las ventas que cargo la persona que te escribe: en la pantalla "Ventas sin cobrar" tampoco ve las de sus companeros. No presentes este numero como el total del negocio.',
            'dias_de_antiguedad_pedidos'             => $dias,
            'ventas_sin_cobrar'                      => $ventas_totales,
            'total_pendiente_en_pesos'               => round($pendiente_total, 2),
            // Las que no entran en ese total por estar en otra moneda (la extensión ventas_en_dolares).
            'ventas_en_otra_moneda'                  => $ventas_en_otra_moneda,
            // Ventas impagas sin cliente cargado: no entran en el ranking pero sí en el total.
            'ventas_sin_cliente'                     => $sin_cliente,
            'venta_mas_vieja'                        => self::venta_mas_vieja($base),
            'clientes_con_deuda'                     => $clientes_encontrados,
            'clientes_en_esta_lista'                 => count($lista),
            'clientes'                               => $lista,
        ];
    }

    /**
     * A QUÉ EMPLEADO SE RECORTA EL CONJUNTO, con el criterio LITERAL de la pantalla
     * (`SaleController::ventas_sin_cobrar()`): el recorte está PRENDIDO por defecto y solo se apaga
     * para el dueño o para quien tenga `ver_alertas_de_todos_los_empleados`.
     *
     * 🔴 Si esto devolviera siempre null —como hacía hasta el 21/9/2026— la tool le contestaría a un
     * vendedor el total del negocio entero, el ranking de deudores con nombre y monto y la venta más
     * vieja de cualquier compañero: datos que ese mismo vendedor no puede ver en ninguna pantalla del
     * sistema. Una tool que dice espejar una pantalla tiene que espejar también su alcance.
     *
     * ⚠️ El "es el dueño" se decide comparando ids y no con `PermisosIaHelper::es_dueno()`, porque es
     * el `is_owner()` del controller al pie de la letra (`userId() == userId(false)`) y de paso hace
     * de guarda de tenencia: una persona que no es este dueño ni empleado suyo nunca ensancha el
     * conjunto, se recorta a sí misma y no ve nada.
     *
     * ⚠️ Sin persona identificada NO se recorta, y eso no abre ningún agujero: desde el chat siempre
     * llega una, porque `ai_conversations` nace con tenencia doble (`user_id` la cuenta y
     * `auth_user_id` la persona) en todos sus caminos de alta. Los llamadores que pueden quedar sin
     * persona son los que hablan por el negocio entero (tests, consola), y además no habría a qué
     * empleado recortar.
     *
     * @param  \App\Models\User|null  $persona
     * @param  int  $owner_id
     * @return int|null
     */
    protected static function employee_id_del_recorte($persona, $owner_id)
    {
        if (is_null($persona)) {

            return null;
        }

        if ((int) $persona->id === (int) $owner_id) {

            return null;
        }

        if (! empty($persona->ver_alertas_de_todos_los_empleados)) {

            return null;
        }

        return (int) $persona->id;
    }

    /**
     * El umbral de antigüedad que finalmente se aplica: el que pidió el modelo si mandó uno, y si no
     * el de la cascada por rol de la pantalla. Es el mismo orden del controller, donde el `?dias=N`
     * del query string PISA la cascada.
     *
     * @param  int|null  $dias  Lo que pidió el modelo. null = no pidió nada.
     * @param  \App\Models\User|null  $persona
     * @param  \App\Models\User  $owner
     * @return int
     */
    protected static function umbral_de_dias($dias, $persona, $owner)
    {
        if (is_null($dias)) {
            $dias = self::dias_de_la_cascada($persona, $owner);
        }

        $dias = (int) $dias;

        if ($dias < 0) {
            $dias = 0;
        }

        if ($dias > self::TOPE_DIAS) {
            $dias = self::TOPE_DIAS;
        }

        return $dias;
    }

    /**
     * LA CASCADA POR ROL DE LA PANTALLA, copiada de `SaleController::ventas_sin_cobrar()`: dueño →
     * el umbral de administradores; administrador sin columna propia → el de administradores;
     * cualquiera con columna propia → la suya; el resto → el de empleados.
     *
     * 🔴 UNA DIFERENCIA A PROPÓSITO CON LA PANTALLA, Y ES LA ÚNICA: si la cascada termina en null
     * —el caso normal, porque las tres columnas son nullable y sin default y casi ningún comercio
     * las configuró— acá se devuelve 0 ("todas") y no null. Con null, el
     * `DATE(created_at) <= DATE_SUB(CURDATE(), INTERVAL NULL DAY)` de la query compartida da NULL
     * para toda fila y el conjunto queda VACÍO. En la pantalla eso se ve como un listado vacío, que
     * es raro pero mudo; en el chat el asistente le contestaría al dueño "no tenés nada sin cobrar"
     * teniendo el negocio lleno de deuda, que es la peor forma de fallar que tiene esta tool.
     *
     * ⚠️ Sin persona identificada tampoco hay cascada posible: queda 0, que es el comportamiento
     * anterior a esta corrección.
     *
     * @param  \App\Models\User|null  $persona
     * @param  \App\Models\User  $owner
     * @return int
     */
    protected static function dias_de_la_cascada($persona, $owner)
    {
        if (is_null($persona)) {

            return 0;
        }

        $dias = $owner->dias_alertar_empleados_ventas_no_cobradas;

        $es_dueno = ((int) $persona->id === (int) $owner->id);

        if ($es_dueno) {

            $dias = $owner->dias_alertar_administradores_ventas_no_cobradas;

        } elseif (PermisosIaHelper::es_admin($persona) && is_null($persona->dias_alertar_empleados_ventas_no_cobradas)) {

            $dias = $owner->dias_alertar_administradores_ventas_no_cobradas;

        } elseif (! is_null($persona->dias_alertar_empleados_ventas_no_cobradas)) {

            $dias = $persona->dias_alertar_empleados_ventas_no_cobradas;
        }

        return is_null($dias) ? 0 : (int) $dias;
    }

    /**
     * La venta impaga más vieja, calculada contra el conjunto ENTERO y no contra el ranking: "¿cuál
     * es la más vieja que tengo sin cobrar?" es una de las dos preguntas que esta tool tiene que
     * contestar en una sola vuelta, y el cliente que la tiene puede no estar entre los veinte que más
     * deben. Es el mismo criterio de `venta_impaga_mas_vieja` en la consulta por cliente.
     *
     * "El conjunto" es el que ya viene recortado por `employee_id_del_recorte()`: a un vendedor le
     * toca la más vieja DE LAS SUYAS, igual que en la pantalla.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $base
     * @return array<string, mixed>|null
     */
    protected static function venta_mas_vieja($base)
    {
        $venta = (clone $base)
            ->with('current_acount', 'client')
            ->orderBy('sales.created_at', 'ASC')
            ->orderBy('sales.id', 'ASC')
            ->first();

        if (is_null($venta)) {

            return null;
        }

        $cuenta = $venta->current_acount;

        $debe      = (is_null($cuenta) || is_null($cuenta->debe)) ? 0.0 : (float) $cuenta->debe;
        $pagandose = (is_null($cuenta) || is_null($cuenta->pagandose)) ? 0.0 : (float) $cuenta->pagandose;

        /*
         * La moneda viaja en la fila, igual que en ventas_impagas_de_un_cliente y por el mismo
         * motivo: el prompt le dice al asistente que los importes son en pesos salvo aviso, así que
         * un número pelado de una venta en dólares se informa en pesos. Manda la de la cuenta
         * corriente, que es la fila donde vive la deuda; la de la venta queda de respaldo.
         */
        $moneda_id = null;

        if (! is_null($cuenta) && ! is_null($cuenta->moneda_id)) {
            $moneda_id = (int) $cuenta->moneda_id;
        } elseif (! is_null($venta->moneda_id)) {
            $moneda_id = (int) $venta->moneda_id;
        }

        return [
            'venta_id'        => (int) $venta->id,
            'numero'          => is_null($venta->num) ? null : (int) $venta->num,
            'cliente'         => is_null($venta->client) ? null : (string) $venta->client->name,
            'fecha'           => is_null($venta->created_at) ? null : $venta->created_at->format('Y-m-d'),
            'dias_sin_cobrar' => is_null($venta->created_at) ? null : (int) $venta->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay()),
            'total'           => is_null($venta->total) ? 0.0 : (float) $venta->total,
            'pendiente'       => round($debe - $pagandose, 2),
            'moneda_id'       => $moneda_id,
            // null se lee como pesos, igual que en todo el resto (MONEDAS_PESOS incluye el 0 además del 1).
            'en_pesos'        => is_null($moneda_id) || in_array($moneda_id, RecolectorBase::MONEDAS_PESOS, true),
        ];
    }
}
