<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

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
 * `VentasSinCobrarHelper::query_de_ventas($owner_id, null, $dias)` — la MISMA query que usan el
 * listado de la pantalla y el recordatorio de cobro por WhatsApp. No se reescribe acá: una segunda
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
 * siempre. Con `dias = 0` —el default de esta tool— es "todas", salvo las que tengan su propio
 * umbral cargado y todavía no lo cumplan.
 */
class VentasSinCobrarIaHelper
{
    /** Cuántos clientes deudores entran en el ranking. */
    const TOPE_DE_CLIENTES = 20;

    /** Techo del `dias` que puede pedir el modelo: más que eso no acota nada. */
    const TOPE_DIAS = 3650;

    /**
     * LAS VENTAS SIN COBRAR DE TODO EL NEGOCIO.
     *
     * @param  int  $owner_id  Dueño (`sales.user_id`). Nunca Auth: esta tool corre en un job sin sesión.
     * @param  int  $dias      Umbral general de antigüedad. 0 = sin umbral general.
     * @return array<string, mixed>  Con la clave `error` cuando el pedido no se puede atender
     */
    public static function ventas_sin_cobrar(int $owner_id, int $dias = 0): array
    {
        $owner = User::find($owner_id);

        if (is_null($owner)) {
            return ['error' => 'No se encontro el negocio.'];
        }

        if ($dias < 0) {
            $dias = 0;
        }

        if ($dias > self::TOPE_DIAS) {
            $dias = self::TOPE_DIAS;
        }

        $base = VentasSinCobrarHelper::query_de_ventas($owner_id, null, $dias);

        /*
         * Los totales se calculan sobre las filas de `current_acounts` del conjunto y NO sobre las
         * ventas: la deuda viva es `debe - pagandose` de la cuenta corriente, no el total de la
         * venta (que puede tener una parte ya cobrada). La condición que elige esas filas es la
         * MISMA que puso a la venta en el conjunto, invocada y no copiada.
         */
        $condicion = VentasSinCobrarHelper::condicion_de_deuda();

        $pesos = implode(',', array_map('intval', RecolectorBase::MONEDAS_PESOS));

        $filas = DB::table('current_acounts')
            ->whereIn('current_acounts.sale_id', (clone $base)->select('sales.id'))
            ->where($condicion)
            ->groupByRaw('COALESCE(client_id, 0)')
            ->selectRaw(
                'COALESCE(client_id, 0) as cliente_id, '
                . 'COUNT(*) as ventas, '
                . 'SUM(CASE WHEN COALESCE(moneda_id, 1) IN (' . $pesos . ') THEN 1 ELSE 0 END) as ventas_en_pesos, '
                . 'SUM(CASE WHEN COALESCE(moneda_id, 1) IN (' . $pesos . ') THEN debe - COALESCE(pagandose, 0) ELSE 0 END) as pendiente_en_pesos'
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
     * La venta impaga más vieja de TODO el negocio, calculada contra el conjunto entero y no contra
     * el ranking: "¿cuál es la más vieja que tengo sin cobrar?" es una de las dos preguntas que esta
     * tool tiene que contestar en una sola vuelta, y el cliente que la tiene puede no estar entre los
     * veinte que más deben. Es el mismo criterio de `venta_impaga_mas_vieja` en la consulta por
     * cliente.
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
