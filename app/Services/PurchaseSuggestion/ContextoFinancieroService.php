<?php

namespace App\Services\PurchaseSuggestion;

use App\Http\Controllers\Helpers\caja\CajaLiquidacionHelper;
use App\Models\Caja;
use App\Models\CreditAccount;
use App\Models\Provider;

/**
 * Contexto financiero de una corrida de sugerencia de compra: deuda con cada
 * proveedor involucrado + disponible en cajas. PURAMENTE INFORMATIVO (D9):
 * estos números NUNCA entran en el cálculo de cantidades de
 * PurchaseSuggestionService, que ya terminó de correr cuando este servicio
 * arranca — es una decisión explícita de Lucas, no un descuido, y por eso
 * este servicio ni siquiera recibe las líneas calculadas, solo los totales
 * ya hechos por el llamador. Se guarda como JSON en
 * purchase_suggestions.contexto_financiero; la IA lo puede comentar en el
 * resumen, pero jamás usarlo para recortar nada.
 */
class ContextoFinancieroService
{
    /** Moneda en la que se informa el disponible en cajas (1 = Peso) */
    const MONEDA_CAJAS = 1;

    /**
     * Arma el contexto financiero de la corrida: deuda por proveedor
     * (credit_accounts.saldo — NUNCA el recálculo full de checkSaldos() ni
     * los espejos desnormalizados providers.saldo_pesos/saldo_dolares/saldo)
     * y disponible en cajas en pesos (CajaLiquidacionHelper::calcular_saldos_liquidez(),
     * que no filtra por moneda: acá se filtran las cajas ANTES de sumar, no
     * después).
     *
     * Corre UNA sola vez al cerrar la corrida (nunca por chunk ni por
     * proveedor): el loop de cajas es N queries para N cajas y no hay forma
     * barata de evitarlo (ver INSUMOS-MEDIDOS.md §8).
     *
     * @param int $user_id Dueño de la corrida
     * @param array $provider_ids Proveedores con al menos una línea con proveedor asignado en esta corrida
     * @param array $totales_por_proveedor [provider_id => ['total_estimado' => float, 'lineas' => int]], calculado por el llamador sobre purchase_suggestion_articles (este servicio no toca esa tabla)
     * @return array Listo para json_encode() en purchase_suggestions.contexto_financiero
     */
    public static function armar(int $user_id, array $provider_ids, array $totales_por_proveedor): array
    {
        return [
            'capturado_at' => now()->toDateTimeString(),
            'caja_disponible_pesos' => self::caja_disponible_pesos($user_id),
            'proveedores' => self::armar_proveedores($user_id, $provider_ids, $totales_por_proveedor),
        ];
    }

    /**
     * Deuda en pesos y dólares por proveedor, nombre y totales de la corrida.
     * Una sola query a credit_accounts para TODOS los proveedores del lote,
     * nunca una consulta por proveedor.
     *
     * @param int $user_id
     * @param array $provider_ids
     * @param array $totales_por_proveedor
     * @return array
     */
    protected static function armar_proveedores(int $user_id, array $provider_ids, array $totales_por_proveedor): array
    {
        $provider_ids = array_values(array_unique(array_filter($provider_ids, function ($id) {
            return $id !== null;
        })));

        if (empty($provider_ids)) {
            return [];
        }

        $nombres = Provider::whereIn('id', $provider_ids)->pluck('name', 'id');

        // 🔴 Fuente de verdad: credit_accounts.saldo, directo. Prohibido
        // CurrentAcountHelper::checkSaldos() (recálculo full, caro) y
        // providers.saldo_pesos/saldo_dolares (espejo desnormalizado y
        // NULLABLE) / providers.saldo (legacy). Saldo positivo = le debo al
        // proveedor (INSUMOS-MEDIDOS.md §7).
        $saldos = CreditAccount::where('model_name', 'provider')
            ->where('user_id', $user_id)
            ->whereIn('model_id', $provider_ids)
            ->whereIn('moneda_id', [1, 2])
            ->get(['model_id', 'moneda_id', 'saldo']);

        $deuda_pesos = [];
        $deuda_dolares = [];

        foreach ($saldos as $cuenta) {
            // NULL se trata como 0.0 (proveedor sin movimientos en esa moneda).
            $saldo = $cuenta->saldo !== null ? (float) $cuenta->saldo : 0.0;

            if ((int) $cuenta->moneda_id === 1) {
                $deuda_pesos[(int) $cuenta->model_id] = $saldo;
            } elseif ((int) $cuenta->moneda_id === 2) {
                $deuda_dolares[(int) $cuenta->model_id] = $saldo;
            }
        }

        $proveedores = [];

        foreach ($provider_ids as $provider_id) {
            $totales = isset($totales_por_proveedor[$provider_id]) ? $totales_por_proveedor[$provider_id] : [];

            $proveedores[] = [
                'provider_id' => $provider_id,
                'nombre' => isset($nombres[$provider_id]) ? $nombres[$provider_id] : null,
                'deuda_pesos' => isset($deuda_pesos[$provider_id]) ? $deuda_pesos[$provider_id] : 0.0,
                'deuda_dolares' => isset($deuda_dolares[$provider_id]) ? $deuda_dolares[$provider_id] : 0.0,
                'total_estimado' => isset($totales['total_estimado']) ? (float) $totales['total_estimado'] : 0.0,
                'lineas' => isset($totales['lineas']) ? (int) $totales['lineas'] : 0,
            ];
        }

        return $proveedores;
    }

    /**
     * Disponible en cajas EN PESOS. CajaLiquidacionHelper::calcular_saldos_liquidez()
     * no filtra por moneda (suma todos los movimientos de la caja): por eso
     * las cajas se filtran por moneda_id ANTES del loop, no después.
     * cajas.moneda_id es nullable (cajas viejas sin moneda asignada) y se
     * tratan como pesos, igual criterio que el resto del sistema.
     *
     * @param int $user_id
     * @return float
     */
    protected static function caja_disponible_pesos(int $user_id): float
    {
        $caja_ids = Caja::where('user_id', $user_id)
            ->where(function ($q) {
                $q->where('moneda_id', self::MONEDA_CAJAS)->orWhereNull('moneda_id');
            })
            ->pluck('id');

        $disponible = 0.0;

        // N queries para N cajas (calcular_saldos_liquidez no acepta una
        // lista de ids): por eso este método corre UNA sola vez al cerrar la
        // corrida, nunca por chunk ni por proveedor.
        foreach ($caja_ids as $caja_id) {
            $saldos = CajaLiquidacionHelper::calcular_saldos_liquidez($caja_id);
            $disponible += (float) $saldos['saldo_disponible'];
        }

        return $disponible;
    }
}
