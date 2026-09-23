<?php

namespace Tests\Feature\CuentaCorriente;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Helpers compartidos por los tests de la misión cuenta-corriente-carrera-y-velocidad (23/9/2026).
 *
 * 🔴 La verificación de la cadena (`assert_cadena_cierra()`) se hace ACÁ, en el test, recorriendo
 * las filas con su propio orden y su propia suma: NO usa `CurrentAcountHelper::checkSaldos()` ni
 * `primer_corte_de_la_cadena()`, que son justamente el código bajo prueba. Un test que verifica el
 * saldo con la misma función que lo calculó no puede dar rojo.
 */
trait ArmaCadenas
{
    /**
     * Un cliente del usuario con sus dos cuentas corrientes (pesos y dólares).
     *
     * @param  int     $user_id
     * @param  string  $nombre
     * @return array{0: Client, 1: CreditAccount}  El cliente y su cuenta en pesos.
     */
    protected function cliente_con_cuenta($user_id, $nombre)
    {
        $cliente = Client::create([
            'num'       => (int) Client::where('user_id', $user_id)->max('num') + 1,
            'name'      => 'zz '.$nombre.' '.uniqid(),
            'user_id'   => $user_id,
        ]);

        CreditAccountHelper::crear_credit_accounts('client', $cliente->id, $user_id);

        $cuenta = CreditAccount::where('model_name', 'client')
                                ->where('model_id', $cliente->id)
                                ->where('moneda_id', 1)
                                ->first();

        return [$cliente, $cuenta];
    }

    /**
     * Un movimiento sembrado a mano, SIN recalcular nada: lo que se prueba después es quién lo
     * recalcula.
     *
     * @param  CreditAccount  $cuenta
     * @param  array          $campos  debe o haber, created_at y lo que haga falta.
     * @return CurrentAcount
     */
    protected function movimiento($cuenta, $campos)
    {
        $base = [
            'credit_account_id' => $cuenta->id,
            'client_id'         => $cuenta->model_name == 'client' ? $cuenta->model_id : null,
            'provider_id'       => $cuenta->model_name == 'provider' ? $cuenta->model_id : null,
            'user_id'           => $cuenta->user_id,
            'is_provisorio'     => 0,
            'status'            => isset($campos['haber']) ? 'pago_from_client' : 'sin_pagar',
        ];

        return CurrentAcount::create(array_merge($base, $campos));
    }

    /**
     * Una venta mínima del cliente, que va a la cuenta corriente. No crea el movimiento: eso lo
     * hace el camino que se prueba.
     *
     * @param  Client          $cliente
     * @param  float           $total
     * @param  \Carbon\Carbon  $created_at
     * @return Sale
     */
    protected function venta($cliente, $total, $created_at)
    {
        return Sale::create([
            'num'                        => (int) Sale::where('user_id', $cliente->user_id)->max('num') + 1,
            'user_id'                    => $cliente->user_id,
            'client_id'                  => $cliente->id,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 1,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'caja_id'                    => null,
            'moneda_id'                  => 1,
            'sub_total'                  => $total,
            'total'                      => $total,
            'created_at'                 => $created_at,
        ]);
    }

    /**
     * Las filas de la cadena, leídas frescas, en el orden de la cadena: `created_at, id`, sin los
     * provisorios.
     *
     * @param  int  $credit_account_id
     * @return \Illuminate\Support\Collection
     */
    protected function filas_de_la_cadena($credit_account_id)
    {
        return DB::table('current_acounts')
                    ->where('credit_account_id', $credit_account_id)
                    ->where('is_provisorio', 0)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get();
    }

    /**
     * La cadena cierra: cada saldo es el anterior más el debe (o menos el haber), arrancando en 0,
     * salvo las anclas (sin debe ni haber), que conservan el suyo; y el saldo de la cuenta es el del
     * último movimiento.
     *
     * @param  int     $credit_account_id
     * @param  string  $contexto
     * @return float  El saldo final esperado.
     */
    protected function assert_cadena_cierra($credit_account_id, $contexto = '')
    {
        $esperado = 0.0;

        foreach ($this->filas_de_la_cadena($credit_account_id) as $fila) {

            // Un movimiento sin debe ni haber es un ancla: conserva su saldo y la cadena sigue desde
            // ahí (el comportamiento de siempre, que la misión mantiene a propósito).
            if (is_null($fila->debe) && is_null($fila->haber)) {
                $esperado = (float) $fila->saldo;
                continue;
            }

            if (!is_null($fila->debe)) {
                $esperado = round($esperado + (float) $fila->debe, 2);
            } else if (!is_null($fila->haber)) {
                $esperado = round($esperado - (float) $fila->haber, 2);
            }

            $this->assertNotNull($fila->saldo, $contexto.': el movimiento '.$fila->id.' ('.$fila->detalle.') quedó con el saldo en NULL.');

            $this->assertEqualsWithDelta(
                $esperado,
                (float) $fila->saldo,
                0.01,
                $contexto.': la cadena no cierra en el movimiento '.$fila->id.' ('.$fila->detalle.', '.$fila->created_at.').'
            );
        }

        $saldo_de_la_cuenta = (float) DB::table('credit_accounts')->where('id', $credit_account_id)->value('saldo');

        $this->assertEqualsWithDelta($esperado, $saldo_de_la_cuenta, 0.01, $contexto.': el saldo de la cuenta no es el del último movimiento.');

        return $esperado;
    }
}
