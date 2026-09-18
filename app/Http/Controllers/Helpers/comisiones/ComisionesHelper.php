<?php

namespace App\Http\Controllers\Helpers\comisiones;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\comisiones\ComisionPorcentajeGeneral;
use App\Http\Controllers\Helpers\comisiones\DistriCreoComision;
use App\Http\Controllers\Helpers\comisiones\FenixComision;
use App\Http\Controllers\Helpers\comisiones\GolonorteComision;
use App\Http\Controllers\Helpers\comisiones\RosMarComision;
use App\Http\Controllers\Helpers\comisiones\TruvariComision;
use App\Models\SellerCommission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ComisionesHelper {
	
	public $sale;

	function __construct($sale) {

		$this->sale = $sale;
	}

	function crear_comision() {

		if (
            !is_null($this->sale->seller_id)
            && $this->sale->seller_id !== 0
        ) {

			Log::info('entro en crear_comision');
			
			$comision_function = $this->sale->user->comision_funcion;

            if ($comision_function == 'ros_mar') {
				Log::info('entro en ros_mar comision_function');

                $comision = new RosMarComision($this->sale);

                $comision->crear_comision();
                
            } else if ($comision_function == 'fenix') {

                if ($this->sale->current_acount) {

                    $comision = new FenixComision($this->sale);

                    $comision->crear_comision();
                }

            } else if ($comision_function == 'golonorte') {

                Log::info('entro en golonorte comision_function');

                $comision = new GolonorteComision($this->sale);

                $comision->crear_comision();

            } else if ($comision_function == 'truvari') {

                $comision = new TruvariComision($this->sale);

                $comision->crear_comision();

            } else if ($comision_function == 'distri_creo') {

                Log::info('Creando comision para distri_creo');

                $comision = new DistriCreoComision();

                $comision->crear_comision($this->sale);

            } else {

                // Grupo 268 · Prompt 02: motor generico por defecto. comision_funcion es nullable
                // y solo se setea a mano para los cinco clientes de arriba (ver UserSeeder); para
                // cualquier otro cliente cae aca. Antes de este else, ningun cliente nuevo
                // generaba nunca una fila en seller_commissions.
                Log::info('Creando comision con el motor generico por porcentaje');

                $comision = new ComisionPorcentajeGeneral();

                $comision->crear_comision($this->sale);

            }
		}
	}

    /**
     * Cuantos ids entran en cada UPDATE ... CASE. Con este tope una tanda de 24.000 comisiones
     * (el vendedor mas grande medido en Fenix, 18/9/2026) queda en ~48 sentencias en vez de una
     * sola de varios MB de texto SQL.
     */
    const TANDA_UPDATE = 500;

    /**
     * Grupo 268 · Prompt 02, bug B: unica funcion de saldo del ledger de comisiones, por vendedor
     * y por moneda (antes habia dos implementaciones con criterios de orden distintos —
     * ComisionesHelper::set_saldo() por id, SellerCommissionHelper::checkSaldos()/getSaldo() por
     * created_at— que daban resultados distintos con dos comisiones del mismo segundo).
     *
     * Recorre TODAS las comisiones active del vendedor en esa moneda, en orden de id, y persiste
     * el saldo acumulado de cada una (debe - haber). Las comisiones inactive quedan con saldo en
     * null: una comision pendiente todavia no forma parte del ledger (bug C).
     *
     * 🔴 Medido en el VPS de Fenix (18/9/2026, transaccion con rollback): el vendedor con mas
     * historial (24.107 comisiones) tardaba 4,05s y 2.164 queries porque la version anterior traia
     * el modelo COMPLETO de cada fila con Eloquent (->get()) y guardaba una por una con ->save()
     * dentro de un foreach. Esta version hace el MISMO calculo, en el MISMO orden, con el MISMO
     * redondeo — solo cambia COMO se ejecuta: un SELECT liviano (4 columnas, no el modelo entero)
     * y un UPDATE en bloque por tanda, en vez de N round-trips. No se toco el algoritmo en si
     * (que filas se procesan y en que orden) porque el orden de ACTIVACION de una comision no
     * siempre sigue el orden de id — un pago puede liquidar una deuda vieja mientras ventas mas
     * nuevas del mismo vendedor ya estan activas — y limitar el recalculo a "desde la ultima
     * conocida" es un cambio de logica financiera con mas riesgo del que justifica esta mejora.
     *
     * @param int $seller_id
     * @param int|null $moneda_id null se trata como 1 (pesos), igual que en todo el read-path.
     * @return void
     */
    static function recalcular_saldos($seller_id, $moneda_id) {

        if (is_null($moneda_id) || $moneda_id == 0) {
            $moneda_id = 1;
        }

        $filtro_moneda = function ($query) use ($moneda_id) {
            if ($moneda_id == 1) {
                // Filas historicas sin moneda_id todavia cargada se tratan como pesos.
                $query->where('moneda_id', 1)->orWhereNull('moneda_id');
            } else {
                $query->where('moneda_id', $moneda_id);
            }
        };

        // Select liviano: solo las 4 columnas que hacen falta, no el modelo Eloquent completo.
        $filas = DB::table('seller_commissions')
                    ->select('id', 'debe', 'haber', 'saldo')
                    ->where('seller_id', $seller_id)
                    ->where('status', 'active')
                    ->where($filtro_moneda)
                    ->orderBy('id', 'ASC')
                    ->get();

        $saldo = 0;
        $por_actualizar = [];

        foreach ($filas as $fila) {

            $debe = !is_null($fila->debe) ? (float)$fila->debe : 0;
            $haber = !is_null($fila->haber) ? (float)$fila->haber : 0;

            $saldo = Numbers::redondear($saldo + $debe - $haber);

            // Mismo criterio que el dirty-tracking de Eloquent: si el saldo ya es el que
            // corresponde, no hace falta reescribir la fila.
            $saldo_actual = !is_null($fila->saldo) ? (float)$fila->saldo : null;

            if (is_null($saldo_actual) || abs($saldo_actual - $saldo) >= 0.00001) {
                $por_actualizar[(int)$fila->id] = $saldo;
            }
        }

        self::aplicar_saldos_en_tandas($por_actualizar);

        // Una comision pendiente no tiene saldo: todavia no forma parte del ledger.
        SellerCommission::where('seller_id', $seller_id)
                            ->where('status', 'inactive')
                            ->where($filtro_moneda)
                            ->update(['saldo' => null]);
    }

    /**
     * Escribe {id => saldo} con UPDATE ... CASE en tandas de TANDA_UPDATE ids, para no armar una
     * sola sentencia de decenas de miles de WHEN. Los valores de saldo van bindeados (nunca
     * concatenados como texto), solo los ids literales viajan armados a mano en el WHERE/CASE
     * porque ya pasaron por (int) arriba.
     *
     * @param array $por_actualizar id (int) => saldo (float)
     * @return void
     */
    private static function aplicar_saldos_en_tandas($por_actualizar) {

        if (empty($por_actualizar)) {
            return;
        }

        $ids = array_keys($por_actualizar);

        foreach (array_chunk($ids, self::TANDA_UPDATE) as $tanda_ids) {

            $case = 'CASE id ';
            $bindings = [];

            foreach ($tanda_ids as $id) {
                $case .= 'WHEN ' . $id . ' THEN ? ';
                $bindings[] = $por_actualizar[$id];
            }

            $case .= 'END';

            DB::update(
                'UPDATE seller_commissions SET saldo = ' . $case . ' WHERE id IN (' . implode(',', $tanda_ids) . ')',
                $bindings
            );
        }
    }

    /**
     * Wrapper delgado: resuelve seller_id y moneda_id desde la propia comision y delega en
     * recalcular_saldos(). Se mantiene para no tener que tocar la firma en cascada en todos los
     * llamadores existentes (RosMarComision, GolonorteComision, DistriCreoComision,
     * TruvariComision, SellerCommissionController::pago()), pero ya no calcula nada por su
     * cuenta. $es_un_pago queda sin uso: recalcular_saldos() ya trata debe/haber de forma
     * uniforme (saldo = saldo_anterior + debe - haber), asi que la distincion dejo de hacer falta.
     *
     * @param \App\Models\SellerCommission $seller_commission
     * @param bool $es_un_pago sin uso, ver arriba. Se mantiene por compatibilidad de firma.
     * @return void
     */
    static function set_saldo($seller_commission, $es_un_pago = false) {
        $moneda_id = $seller_commission->moneda_id;
        if (is_null($moneda_id) || $moneda_id == 0) {
            $moneda_id = 1;
        }
        Self::recalcular_saldos($seller_commission->seller_id, $moneda_id);
    }

}