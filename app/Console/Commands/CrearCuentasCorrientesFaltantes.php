<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Models\CreditAccount;
use App\Models\Provider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Crea las cuentas corrientes (pesos y dólares) que les faltan a los proveedores (misión
 * importacion-proveedores-saldo-inicial, 8/10/2026).
 *
 * Hasta esa misión, la importación de proveedores (`ProviderImport`) y la de artículos con columna
 * "Proveedor" (`ArticleImport::set_providers()`) creaban proveedores SIN cuentas. Un proveedor así
 * no acepta una compra en cuenta corriente (500 en `NewProviderOrderHelper::crear_current_acount()`)
 * ni un saldo inicial. Las dos importaciones ya crean las cuentas; esto es para los que quedaron.
 *
 * Solo proveedores (decisión de Lucas). Incluye los borrados (soft delete), como
 * `iniciar_credit_accounts`: un proveedor restaurado no puede volver sin cuenta.
 *
 *   - Sin `--aplicar`: lista cada proveedor al que le falta alguna cuenta y NO escribe nada.
 *   - Con `--aplicar`: le crea las que le faltan con `CreditAccountHelper::crear_credit_accounts()`,
 *     con el `user_id` DEL PROVEEDOR (no hay sesión). Las cuentas nacen en cero.
 *   - `{user_id?}`: solo los proveedores de ese comercio. En una base compartida, sin esto se
 *     recorren los de todos. El admin lo publica como
 *     `php artisan cuenta_corriente:crear_cuentas_faltantes --aplicar {user_id?}`.
 *
 * 🔴 NO toca movimientos, saldos ni recálculos: solo crea filas de `credit_accounts`. El saldo que
 * la importación vieja perdió no está en la base: vuelve reimportando el mismo Excel, que con el
 * arreglo carga el saldo inicial en la cuenta vacía sin duplicar nada.
 *
 * 🔴 SALE SIEMPRE CON EXIT 0, aunque algún proveedor no se pueda completar. Va en el despliegue, y
 * con un exit distinto de 0 el despliegue del admin frena antes de rotar el frente (mismo criterio
 * que `cuenta_corriente:reparar_cadenas`). Los fallidos quedan en el log (con report() de la
 * excepción) y en la salida, con un resumen al final.
 *
 * Idempotente: una segunda corrida no encuentra nada.
 */
class CrearCuentasCorrientesFaltantes extends Command
{
    protected $signature = 'cuenta_corriente:crear_cuentas_faltantes
                            {user_id? : Solo los proveedores de este comercio}
                            {--aplicar : Crea las cuentas (sin esto, solo lista)}';

    protected $description = 'Lista (y con --aplicar crea) las cuentas corrientes que les faltan a los proveedores, sin tocar movimientos ni saldos.';

    /**
     * Las dos monedas de una cuenta corriente, con el nombre que va en el listado.
     *
     * @var array
     */
    const MONEDAS = [
        1 => 'pesos',
        2 => 'dólares',
    ];

    public function handle()
    {
        $aplicar = (bool) $this->option('aplicar');
        $user_id = $this->argument('user_id');

        if (!is_null($user_id) && $user_id !== '' && !ctype_digit((string) $user_id)) {

            // Un user_id raro NO puede terminar recorriendo los proveedores de toda la base.
            $this->error('cuenta_corriente:crear_cuentas_faltantes: user_id inválido ("'.$user_id.'"). No se revisa nada.');
            Log::warning('cuenta_corriente:crear_cuentas_faltantes: user_id inválido ("'.$user_id.'"). No se revisó nada.');

            return 0;
        }

        $query = Provider::withTrashed()->select(['id', 'name', 'user_id']);

        if (!is_null($user_id) && $user_id !== '') {
            $query->where('user_id', (int) $user_id);
        }

        $revisados = 0;
        $sin_cuenta = 0;
        $creados = 0;
        $fallidos = [];

        $query->chunkById(200, function ($proveedores) use ($aplicar, &$revisados, &$sin_cuenta, &$creados, &$fallidos) {

            $monedas_por_proveedor = $this->monedas_por_proveedor($proveedores->pluck('id')->all());

            foreach ($proveedores as $proveedor) {

                $revisados++;

                $faltan = [];

                foreach (self::MONEDAS as $moneda_id => $nombre_moneda) {
                    if (!isset($monedas_por_proveedor[(int) $proveedor->id][$moneda_id])) {
                        $faltan[] = $nombre_moneda;
                    }
                }

                if (count($faltan) == 0) {
                    continue;
                }

                $sin_cuenta++;

                $this->line('Proveedor '.$proveedor->id.' ('.$proveedor->name.') del comercio '.$proveedor->user_id.': le falta la cuenta en '.implode(' y en ', $faltan).'.');

                if (!$aplicar) {
                    continue;
                }

                try {

                    CreditAccountHelper::crear_credit_accounts('provider', $proveedor->id, $proveedor->user_id);

                    $creados++;

                } catch (\Throwable $e) {

                    // Sin report() este fallo no llegaría al reporter de errores (APRENDER_NO_PARCHEAR:
                    // "excepción capturada que nunca llega al reporter").
                    report($e);

                    Log::warning('cuenta_corriente:crear_cuentas_faltantes: no se pudieron crear las cuentas del proveedor '.$proveedor->id.': '.$e->getMessage());

                    $this->error('  No se pudieron crear las cuentas del proveedor '.$proveedor->id.': '.$e->getMessage());

                    $fallidos[] = $proveedor->id;
                }
            }
        });

        $resumen = 'Proveedores revisados: '.$revisados.'. Sin cuenta: '.$sin_cuenta.'.';

        if ($aplicar) {
            $resumen .= ' Creados: '.$creados.'. Sin crear: '.count($fallidos).(count($fallidos) ? ' (proveedores '.implode(', ', $fallidos).')' : '').'.';
        } else {
            $resumen .= ' (sin --aplicar: no se escribió nada)';
        }

        $this->info($resumen);

        if ($aplicar && $creados > 0) {
            Log::info('cuenta_corriente:crear_cuentas_faltantes: '.$resumen);
        }

        if (count($fallidos)) {
            Log::warning('cuenta_corriente:crear_cuentas_faltantes: '.$resumen);
        }

        // Siempre 0: ver el docblock de la clase.
        return 0;
    }

    /**
     * Las monedas en las que ya tiene cuenta cada proveedor del lote, en UNA consulta (sobre
     * `credit_accounts_duenio_idx`).
     *
     * @param  array $provider_ids
     * @return array [provider_id => [moneda_id => true]]
     */
    protected function monedas_por_proveedor(array $provider_ids)
    {
        $monedas = [];

        if (count($provider_ids) == 0) {
            return $monedas;
        }

        $cuentas = CreditAccount::where('model_name', 'provider')
                                ->whereIn('model_id', $provider_ids)
                                ->get(['model_id', 'moneda_id']);

        foreach ($cuentas as $cuenta) {
            $monedas[(int) $cuenta->model_id][(int) $cuenta->moneda_id] = true;
        }

        return $monedas;
    }
}
