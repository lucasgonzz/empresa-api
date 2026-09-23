<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\currentAcount\CuentaCorrienteLock;
use App\Models\CreditAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Detecta y repara las cuentas corrientes con la cadena de saldos cortada o con el saldo final
 * descuadrado (misión cuenta-corriente-carrera-y-velocidad, 23/9/2026).
 *
 * Una cuenta se repara cuando:
 *   - la cadena está cortada: un movimiento con debe o haber tiene el saldo en NULL, o su saldo no es
 *     el del anterior más su debe menos su haber (tolerancia 0,05). Mismo orden y filtro que
 *     `CurrentAcountHelper::checkSaldos()` (`created_at, id`, `is_provisorio = 0`); un movimiento sin
 *     debe ni haber es un ancla y no es un corte. La definición vive en
 *     `CurrentAcountHelper::primer_corte_de_la_cadena()`;
 *   - o la cadena cierra pero `credit_accounts.saldo`, o el `saldo_pesos`/`saldo_dolares` del dueño,
 *     no es el saldo del último movimiento (`CurrentAcountHelper::descuadre_del_saldo_final()`).
 *
 * Los cortes que había en producción al escribir esto no eran todos de la carrera de Fenix: Servian
 * (un pago cargado con fecha pasada que no recalculó la venta posterior), Masquito (Pago N°2128) y
 * 3D Tisk (Pago N°116 con saldo NULL).
 *
 *   - Sin `--aplicar`: lista cada cuenta a reparar y NO escribe nada.
 *   - Con `--aplicar`: por cada una, adentro de una transacción con el candado de la cuenta
 *     (`CuentaCorrienteLock`), recalcula la cadena de saldos (`checkSaldos()`, que también deja
 *     bien la cuenta y el dueño) y vuelve a verificar. Loguea cada cuenta y cuánto se movió el saldo.
 *   - `--con-pagos`: además re-imputa los pagos (`check_saldos_y_pagos()`). NO es el default a
 *     propósito: re-imputar en masa puede desliquidar comisiones de vendedores
 *     (`SellerCommissionHelper::revertirComisionesNoSaldadas()`). Es para uso manual.
 *   - `{user_id?}`: solo las cuentas cuyo dueño (cliente o proveedor) es de ese comercio. En una base
 *     compartida, sin esto se tocan las cuentas de todos los comercios. El admin lo publica como
 *     `php artisan cuenta_corriente:reparar_cadenas --aplicar {user_id?}`.
 *
 * 🔴 SALE SIEMPRE CON EXIT 0, aunque alguna cuenta no se pueda reparar. Va en el despliegue, y con un
 * exit distinto de 0 el despliegue del admin frena antes de rotar el frente: una cuenta rara no
 * puede dejar a un cliente sin la versión nueva. Las fallidas quedan en el log (con report() de la
 * excepción) y en la salida, con un resumen al final.
 *
 * Idempotente: una segunda corrida no encuentra nada.
 */
class RepararCadenasDeCuentaCorriente extends Command
{
    protected $signature = 'cuenta_corriente:reparar_cadenas
                            {user_id? : Solo las cuentas de los clientes y proveedores de este comercio}
                            {--aplicar : Repara las cuentas (sin esto, solo lista)}
                            {--con-pagos : Además de los saldos, re-imputa los pagos (uso manual)}
                            {--credit_account_id= : Revisa una sola cuenta}';

    protected $description = 'Detecta (y con --aplicar repara) las cuentas corrientes cuya cadena de saldos no cierra o cuyo saldo final no coincide.';

    public function handle()
    {
        $aplicar = (bool) $this->option('aplicar');
        $con_pagos = (bool) $this->option('con-pagos');
        $user_id = $this->argument('user_id');

        if (!is_null($user_id) && $user_id !== '' && !ctype_digit((string) $user_id)) {

            // Un user_id raro NO puede terminar recorriendo todas las cuentas de la base.
            $this->error('cuenta_corriente:reparar_cadenas: user_id inválido ("'.$user_id.'"). No se revisa nada.');
            Log::warning('cuenta_corriente:reparar_cadenas: user_id inválido ("'.$user_id.'"). No se revisó nada.');

            return 0;
        }

        $query = CreditAccount::query();

        if (!is_null($user_id) && $user_id !== '') {

            $user_id = (int) $user_id;

            $query->where(function ($q) use ($user_id) {

                foreach (CuentaCorrienteLock::TABLAS as $model_name => $tabla) {

                    $q->orWhere(function ($q2) use ($model_name, $tabla, $user_id) {
                        $q2->where('model_name', $model_name)
                           ->whereIn('model_id', DB::table($tabla)->where('user_id', $user_id)->select('id'));
                    });
                }
            });
        }

        if (!is_null($this->option('credit_account_id')) && $this->option('credit_account_id') !== '') {
            $query->where('id', (int) $this->option('credit_account_id'));
        }

        $revisadas = 0;
        $a_reparar = 0;
        $reparadas = 0;
        $fallidas = [];

        $query->chunkById(200, function ($credit_accounts) use ($aplicar, $con_pagos, &$revisadas, &$a_reparar, &$reparadas, &$fallidas) {

            foreach ($credit_accounts as $credit_account) {

                $revisadas++;

                $corte = CurrentAcountHelper::primer_corte_de_la_cadena($credit_account->id);
                $descuadre = is_null($corte) ? CurrentAcountHelper::descuadre_del_saldo_final($credit_account) : null;

                if (is_null($corte) && is_null($descuadre)) {
                    continue;
                }

                $a_reparar++;

                $this->line($this->describir($credit_account, $corte, $descuadre));

                if (!$aplicar) {
                    continue;
                }

                if ($this->reparar($credit_account, $con_pagos)) {
                    $reparadas++;
                } else {
                    $fallidas[] = $credit_account->id;
                }
            }
        });

        $resumen = 'Cuentas revisadas: '.$revisadas.'. A reparar: '.$a_reparar.'.';

        if ($aplicar) {
            $resumen .= ' Reparadas: '.$reparadas.'. Sin reparar: '.count($fallidas).(count($fallidas) ? ' (cuentas '.implode(', ', $fallidas).')' : '').'.';
        } else {
            $resumen .= ' (sin --aplicar: no se escribió nada)';
        }

        $this->info($resumen);

        if (count($fallidas)) {
            Log::warning('cuenta_corriente:reparar_cadenas: '.$resumen);
        }

        // Siempre 0: ver el docblock de la clase.
        return 0;
    }

    /**
     * Repara una cuenta: candado, recálculo (saldos, y con --con-pagos también imputaciones) y
     * verificación.
     *
     * @param  \App\Models\CreditAccount  $credit_account
     * @param  bool  $con_pagos
     * @return bool  true si la cadena y el saldo final quedaron bien.
     */
    protected function reparar($credit_account, $con_pagos)
    {
        // El dueño se resuelve ANTES de abrir la transacción: adentro, esta lectura común fijaría
        // la foto de la base antes de esperar el candado (ver CuentaCorrienteLock).
        $duenio = CuentaCorrienteLock::duenio_de_la_cuenta($credit_account->id);

        $saldo_antes = (float) $credit_account->saldo;

        try {

            $resultado = DB::transaction(function () use ($credit_account, $duenio, $con_pagos) {

                CuentaCorrienteLock::bloquear_duenio($duenio);

                if ($con_pagos) {
                    CurrentAcountHelper::check_saldos_y_pagos($credit_account->id);
                } else {
                    CurrentAcountHelper::checkSaldos($credit_account->id);
                }

                return [
                    'corte'     => CurrentAcountHelper::primer_corte_de_la_cadena($credit_account->id),
                    'descuadre' => CurrentAcountHelper::descuadre_del_saldo_final(CreditAccount::find($credit_account->id)),
                ];
            });

        } catch (\Throwable $e) {

            // DB::transaction ya hizo el rollback; sin report() este fallo no llegaría al reporter
            // de errores (APRENDER_NO_PARCHEAR: "excepción capturada que nunca llega al reporter").
            report($e);

            Log::warning('cuenta_corriente:reparar_cadenas: no se pudo reparar la cuenta '.$credit_account->id.': '.$e->getMessage());

            $this->error('  No se pudo reparar la cuenta '.$credit_account->id.': '.$e->getMessage());

            return false;
        }

        $saldo_despues = (float) CreditAccount::where('id', $credit_account->id)->value('saldo');

        $mensaje = 'cuenta_corriente:reparar_cadenas: cuenta '.$credit_account->id.' ('.$credit_account->model_name.' '.$credit_account->model_id.') '.($con_pagos ? 'recalculada con pagos' : 'recalculada').'. Saldo final '.$saldo_antes.' -> '.$saldo_despues.' (diferencia '.round($saldo_despues - $saldo_antes, 2).').';

        if (!is_null($resultado['corte']) || !is_null($resultado['descuadre'])) {

            $donde = !is_null($resultado['corte'])
                        ? 'la cadena sigue cortada en el movimiento '.$resultado['corte']['current_acount_id']
                        : 'el saldo final sigue sin coincidir con el de la cadena';

            Log::warning($mensaje.' 🔴 Después del recálculo '.$donde.'.');

            $this->error('  La cuenta '.$credit_account->id.' no quedó bien: '.$donde.'.');

            return false;
        }

        Log::info($mensaje);

        $this->info('  Reparada. Saldo final '.$saldo_antes.' -> '.$saldo_despues.'.');

        return true;
    }

    /**
     * Renglón del listado para una cuenta a reparar.
     *
     * @param  \App\Models\CreditAccount  $credit_account
     * @param  array|null  $corte      Lo que devuelve CurrentAcountHelper::primer_corte_de_la_cadena().
     * @param  array|null  $descuadre  Lo que devuelve CurrentAcountHelper::descuadre_del_saldo_final().
     * @return string
     */
    protected function describir($credit_account, $corte, $descuadre)
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

        $renglon = 'Cuenta '.$credit_account->id.' (moneda '.$credit_account->moneda_id.') de '.$duenio.': ';

        if (!is_null($corte)) {

            return $renglon.'primer corte en el movimiento '.$corte['current_acount_id']
                .' "'.$corte['detalle'].'" del '.$corte['created_at']
                .'. Saldo guardado '.(is_null($corte['saldo_guardado']) ? 'NULL' : $corte['saldo_guardado'])
                .', esperado '.$corte['saldo_esperado']
                .(is_null($corte['diferencia']) ? '' : ', diferencia '.$corte['diferencia']).'.';
        }

        return $renglon.'la cadena cierra en '.$descuadre['saldo_de_la_cadena']
            .' pero la cuenta tiene '.(is_null($descuadre['saldo_de_la_cuenta']) ? 'NULL' : $descuadre['saldo_de_la_cuenta'])
            .(is_null($descuadre['saldo_del_duenio']) ? '' : ' y el dueño '.$descuadre['saldo_del_duenio']).'.';
    }
}
