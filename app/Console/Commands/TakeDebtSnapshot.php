<?php

namespace App\Console\Commands;

use App\Models\CreditAccount;
use App\Models\CreditAccountSnapshot;
use App\Models\DebtSnapshot;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comando Artisan que captura el snapshot de deuda del día para todos los dueños de negocio.
 * Se ejecuta automáticamente a las 23:59 todos los días desde el Kernel.
 *
 * Por cada usuario owner (owner_id null) calcula:
 * - Suma de saldos de credit_accounts de clientes en ARS y USD
 * - Suma de saldos de credit_accounts de proveedores en ARS y USD
 * Luego hace updateOrCreate en DebtSnapshot para hoy.
 *
 * Además (parte C de la misión de sugerencias inteligentes) persiste el saldo diario POR
 * ENTIDAD en credit_account_snapshots, escribiendo fila solo cuando el saldo cambió
 * respecto del último snapshot de esa cuenta (ver process_credit_account_snapshots y la
 * regla de lectura en el modelo CreditAccountSnapshot). Mismo comando y mismo horario a
 * propósito: no hay un segundo cron que mantener.
 */
class TakeDebtSnapshot extends Command
{
    /**
     * Diferencia mínima de saldo (en la moneda de la cuenta) para considerar que cambió y
     * escribir un snapshot nuevo. Por debajo de esto, la comparación entre el decimal de la
     * base y el float de PHP es ruido, no un movimiento.
     *
     * @var float
     */
    const TOLERANCIA_SALDO = 0.01;

    /**
     * Nombre y firma del comando Artisan.
     *
     * @var string
     */
    protected $signature = 'debt:snapshot';

    /**
     * Descripción del comando para php artisan list.
     *
     * @var string
     */
    protected $description = 'Captura el snapshot de deuda (clientes y proveedores) del día para todos los dueños de negocio';

    /**
     * Crea una nueva instancia del comando.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Ejecuta el comando: itera todos los usuarios owner y genera un snapshot de deuda para hoy.
     *
     * @return int Código de salida (0 = OK)
     */
    public function handle()
    {
        /* Obtener todos los usuarios dueños de negocio (owner_id null = no son empleados) */
        $owners = User::whereNull('owner_id')
            ->select('id')
            ->orderBy('id')
            ->get();

        $this->info('Iniciando debt:snapshot para ' . $owners->count() . ' usuarios...');

        foreach ($owners as $owner) {

            $this->process_user($owner->id);

            $this->process_credit_account_snapshots($owner->id);
        }

        $this->info('debt:snapshot finalizado.');

        return 0;
    }

    /**
     * Calcula y guarda el snapshot de deuda del día para un usuario específico.
     * Suma los saldos de credit_accounts separando por model_name y moneda_id.
     *
     * @param int $user_id ID del usuario dueño del negocio
     * @return void
     */
    protected function process_user($user_id)
    {
        /* Suma de saldos de clientes en ARS (moneda_id = 1) */
        $deuda_clientes = CreditAccount::where('user_id', $user_id)
            ->where('model_name', 'client')
            ->where('moneda_id', 1)
            ->sum('saldo');

        /* Suma de saldos de clientes en USD (moneda_id = 2) */
        $deuda_clientes_usd = CreditAccount::where('user_id', $user_id)
            ->where('model_name', 'client')
            ->where('moneda_id', 2)
            ->sum('saldo');

        /* Suma de saldos de proveedores en ARS (moneda_id = 1) */
        $deuda_proveedores = CreditAccount::where('user_id', $user_id)
            ->where('model_name', 'provider')
            ->where('moneda_id', 1)
            ->sum('saldo');

        /* Suma de saldos de proveedores en USD (moneda_id = 2) */
        $deuda_proveedores_usd = CreditAccount::where('user_id', $user_id)
            ->where('model_name', 'provider')
            ->where('moneda_id', 2)
            ->sum('saldo');

        /* Guardar o actualizar el snapshot del día (evita duplicados por el índice único user_id + date) */
        DebtSnapshot::updateOrCreate(
            [
                'user_id' => $user_id,
                'date'    => today()->toDateString(),
            ],
            [
                'deuda_clientes'        => $deuda_clientes,
                'deuda_clientes_usd'    => $deuda_clientes_usd,
                'deuda_proveedores'     => $deuda_proveedores,
                'deuda_proveedores_usd' => $deuda_proveedores_usd,
            ]
        );

        $this->info('Snapshot guardado para user_id ' . $user_id . ': clientes=' . $deuda_clientes . ', proveedores=' . $deuda_proveedores);
    }

    /**
     * Persiste el saldo diario POR ENTIDAD (cada credit_account: cliente/proveedor × moneda)
     * en credit_account_snapshots, para el historial crediticio.
     *
     * Se escribe fila SOLO cuando el saldo difiere del último snapshot de esa cuenta
     * (tolerancia TOLERANCIA_SALDO) o cuando la cuenta no tiene ninguno y su saldo no es 0:
     * una cuenta que nunca se movió no necesita existir en el historial, y guardar todas las
     * cuentas todos los días explotaría filas (1000 entidades × 2 monedas × 365 días son
     * 730k filas/año). La regla de lectura que esto impone vive en el modelo
     * CreditAccountSnapshot (scopeSaldoAlDia).
     *
     * updateOrCreate sobre el unique (credit_account_id, date): si el saldo cambia dos veces
     * el mismo día, la segunda corrida actualiza la fila del día en vez de duplicarla.
     *
     * @param int $user_id ID del usuario dueño del negocio
     * @return void
     */
    protected function process_credit_account_snapshots($user_id)
    {
        $fecha = today()->toDateString();

        $escritos = 0;

        CreditAccount::where('user_id', $user_id)
            ->select('id', 'user_id', 'saldo', 'model_name', 'model_id', 'moneda_id')
            ->chunkById(1000, function ($cuentas) use ($user_id, $fecha, &$escritos) {

                /* Último snapshot de cada cuenta del chunk, en UNA consulta (no una por cuenta) */
                $ultimos = $this->ultimos_snapshots_por_cuenta($cuentas->pluck('id'));

                foreach ($cuentas as $cuenta) {

                    $saldo_actual = (float) $cuenta->saldo;

                    $ultimo = isset($ultimos[$cuenta->id]) ? $ultimos[$cuenta->id] : null;

                    if (is_null($ultimo)) {

                        /* Cuenta sin historia y en 0: no genera fila (sin snapshot previo, el saldo se lee como 0) */
                        if (abs($saldo_actual) < self::TOLERANCIA_SALDO) {
                            continue;
                        }

                    } else if (abs($saldo_actual - (float) $ultimo->saldo) < self::TOLERANCIA_SALDO) {

                        /* El saldo no cambió respecto del último snapshot: no se escribe nada */
                        continue;
                    }

                    CreditAccountSnapshot::updateOrCreate(
                        [
                            'credit_account_id' => $cuenta->id,
                            'date'              => $fecha,
                        ],
                        [
                            'user_id'    => $user_id,
                            'saldo'      => $saldo_actual,
                            'model_name' => $cuenta->model_name,
                            'model_id'   => $cuenta->model_id,
                            'moneda_id'  => $cuenta->moneda_id,
                        ]
                    );

                    $escritos++;
                }
            });

        $this->info('Snapshots por entidad para user_id ' . $user_id . ': ' . $escritos . ' con saldo cambiado.');
    }

    /**
     * Último snapshot (saldo y fecha) de cada una de las cuentas recibidas, indexado por
     * credit_account_id, resuelto en una sola consulta: el MAX(date) por cuenta como
     * subquery joineada contra la tabla (el unique credit_account_id+date garantiza una
     * fila por cuenta).
     *
     * @param \Illuminate\Support\Collection $ids Ids de credit_accounts del chunk.
     * @return \Illuminate\Support\Collection Filas {credit_account_id, saldo, date} por id de cuenta.
     */
    protected function ultimos_snapshots_por_cuenta($ids)
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        $maximos = DB::table('credit_account_snapshots')
            ->select('credit_account_id', DB::raw('MAX(date) as ultima_fecha'))
            ->whereIn('credit_account_id', $ids)
            ->groupBy('credit_account_id');

        return DB::table('credit_account_snapshots as snapshots')
            ->joinSub($maximos, 'maximos', function ($join) {
                $join->on('snapshots.credit_account_id', '=', 'maximos.credit_account_id')
                     ->on('snapshots.date', '=', 'maximos.ultima_fecha');
            })
            ->select('snapshots.credit_account_id', 'snapshots.saldo', 'snapshots.date')
            ->get()
            ->keyBy('credit_account_id');
    }
}
