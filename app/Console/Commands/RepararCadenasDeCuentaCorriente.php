<?php

namespace App\Console\Commands;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\currentAcount\CuentaCorrienteLock;
use App\Models\CreditAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Detecta y repara las cuentas corrientes con la cadena de saldos cortada (misión
 * cuenta-corriente-carrera-y-velocidad, 23/9/2026).
 *
 * Una cadena está cortada cuando un movimiento no cierra contra el anterior:
 * `saldo ≠ saldo_anterior + debe − haber` (con tolerancia de 0,05), o cuando un movimiento no
 * provisorio tiene el saldo en NULL. Se recorre con el mismo orden y el mismo filtro que
 * `CurrentAcountHelper::checkSaldos()` (`created_at, id` e `is_provisorio = 0`): la definición vive
 * en `CurrentAcountHelper::primer_corte_de_la_cadena()`, que es la que usan también los tests.
 *
 * Los cortes que había en producción al escribir esto no eran todos de la carrera de Fenix: Servian
 * (un pago cargado con fecha pasada que no recalculó la venta posterior), Masquito (Pago N°2128) y
 * 3D Tisk (Pago N°116 con saldo NULL). Con el código nuevo toda escritura recalcula la cadena entera,
 * así que no se vuelven a producir; este comando limpia lo que ya quedó.
 *
 *   - Sin `--aplicar`: lista cada cuenta cortada (dueño, primer movimiento que no cierra y la
 *     diferencia) y NO escribe nada.
 *   - Con `--aplicar`: por cada cuenta cortada, adentro de una transacción con el candado de la
 *     cuenta (`CuentaCorrienteLock`), corre `check_saldos_y_pagos()` y vuelve a verificar la cadena.
 *     Loguea cada cuenta reparada y cuánto se movió el saldo final.
 *
 * Idempotente: una segunda corrida no encuentra nada. Va en el despliegue como comando
 * (`php artisan cuenta_corriente:reparar_cadenas --aplicar`).
 */
class RepararCadenasDeCuentaCorriente extends Command
{
    protected $signature = 'cuenta_corriente:reparar_cadenas {--aplicar : Repara las cuentas cortadas (sin esto, solo lista)} {--credit_account_id= : Revisa una sola cuenta}';

    protected $description = 'Detecta (y con --aplicar repara) las cuentas corrientes cuya cadena de saldos no cierra.';

    public function handle()
    {
        $aplicar = (bool) $this->option('aplicar');

        $query = CreditAccount::query()->orderBy('id');

        if (!is_null($this->option('credit_account_id')) && $this->option('credit_account_id') !== '') {
            $query->where('id', (int) $this->option('credit_account_id'));
        }

        $revisadas = 0;
        $cortadas = 0;
        $reparadas = 0;
        $fallidas = 0;

        $query->chunkById(200, function ($credit_accounts) use ($aplicar, &$revisadas, &$cortadas, &$reparadas, &$fallidas) {

            foreach ($credit_accounts as $credit_account) {

                $revisadas++;

                $corte = CurrentAcountHelper::primer_corte_de_la_cadena($credit_account->id);

                if (is_null($corte)) {
                    continue;
                }

                $cortadas++;

                $this->line($this->describir($credit_account, $corte));

                if (!$aplicar) {
                    continue;
                }

                if ($this->reparar($credit_account)) {
                    $reparadas++;
                } else {
                    $fallidas++;
                }
            }
        });

        $this->info('Cuentas revisadas: '.$revisadas.'. Con la cadena cortada: '.$cortadas.'.'.($aplicar ? ' Reparadas: '.$reparadas.'. Sin reparar: '.$fallidas.'.' : ' (sin --aplicar: no se escribió nada)'));

        return $fallidas > 0 ? 1 : 0;
    }

    /**
     * Repara una cuenta: candado, recálculo completo (saldos e imputaciones) y verificación.
     *
     * @param  \App\Models\CreditAccount  $credit_account
     * @return bool  true si la cadena quedó cerrando.
     */
    protected function reparar($credit_account)
    {
        // El dueño se resuelve ANTES de abrir la transacción: adentro, esta lectura común fijaría
        // la foto de la base antes de esperar el candado (ver CuentaCorrienteLock).
        $duenio = CuentaCorrienteLock::duenio_de_la_cuenta($credit_account->id);

        $saldo_antes = (float) $credit_account->saldo;

        try {

            $corte_despues = DB::transaction(function () use ($credit_account, $duenio) {

                CuentaCorrienteLock::bloquear_duenio($duenio);

                CurrentAcountHelper::check_saldos_y_pagos($credit_account->id);

                return CurrentAcountHelper::primer_corte_de_la_cadena($credit_account->id);
            });

        } catch (\Throwable $e) {

            // DB::transaction ya hizo el rollback; sin report() este fallo no llegaría al reporter
            // de errores (APRENDER_NO_PARCHEAR: "excepción capturada que nunca llega al reporter").
            report($e);

            $this->error('  No se pudo reparar la cuenta '.$credit_account->id.': '.$e->getMessage());

            return false;
        }

        $saldo_despues = (float) CreditAccount::where('id', $credit_account->id)->value('saldo');

        $mensaje = 'cuenta_corriente:reparar_cadenas: cuenta '.$credit_account->id.' ('.$credit_account->model_name.' '.$credit_account->model_id.') reparada. Saldo final '.$saldo_antes.' -> '.$saldo_despues.' (diferencia '.round($saldo_despues - $saldo_antes, 2).').';

        if (!is_null($corte_despues)) {

            Log::warning($mensaje.' 🔴 Después del recálculo la cadena SIGUE sin cerrar en el movimiento '.$corte_despues['current_acount_id'].'.');

            $this->error('  La cuenta '.$credit_account->id.' sigue cortada después de recalcular (movimiento '.$corte_despues['current_acount_id'].').');

            return false;
        }

        Log::info($mensaje);

        $this->info('  Reparada. Saldo final '.$saldo_antes.' -> '.$saldo_despues.'.');

        return true;
    }

    /**
     * Renglón del listado para una cuenta cortada.
     *
     * @param  \App\Models\CreditAccount  $credit_account
     * @param  array  $corte  Lo que devuelve CurrentAcountHelper::primer_corte_de_la_cadena().
     * @return string
     */
    protected function describir($credit_account, $corte)
    {
        $duenio = $credit_account->model_name.' '.$credit_account->model_id;

        // Por query builder y no por el modelo: un cliente borrado (soft delete) también tiene que
        // aparecer con su nombre en el listado.
        if (isset(CuentaCorrienteLock::TABLAS[$credit_account->model_name])) {

            $nombre = DB::table(CuentaCorrienteLock::TABLAS[$credit_account->model_name])
                        ->where('id', $credit_account->model_id)
                        ->value('name');

            if (!is_null($nombre)) {
                $duenio .= ' ('.$nombre.')';
            }
        }

        return 'Cuenta '.$credit_account->id.' (moneda '.$credit_account->moneda_id.') de '.$duenio
            .': primer corte en el movimiento '.$corte['current_acount_id']
            .' "'.$corte['detalle'].'" del '.$corte['created_at']
            .'. Saldo guardado '.(is_null($corte['saldo_guardado']) ? 'NULL' : $corte['saldo_guardado'])
            .', esperado '.$corte['saldo_esperado']
            .(is_null($corte['diferencia']) ? '' : ', diferencia '.$corte['diferencia']).'.';
    }
}
