<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Provider;
use App\Models\Sale;
use Illuminate\Console\Command;

/**
 * Crea credit_accounts y vincula current_acounts para clientes/proveedores de un tenant.
 */
class iniciar_credit_accounts extends Command
{
    /**
     * @var string
     */
    protected $signature = 'iniciar_credit_accounts {user_id?} {client_id?}';

    /**
     * @var string
     */
    protected $description = 'Inicializa cuentas corrientes y vincula movimientos para un user_id.';

    /**
     * user_id del tenant en proceso.
     *
     * @var int
     */
    private $user_id;

    /**
     * credit_account_id pendientes de checkSaldos/checkPagos al final.
     *
     * @var array<int, int>
     */
    private $credit_accounts_to_recalc = [];

    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Ejecuta inicializacion por fases (crear/vincular y recalcular al final).
     *
     * @return int
     */
    public function handle()
    {
        $this->user_id = (int) config('app.USER_ID');

        $param_user_id = $this->argument('user_id');
        if ($param_user_id) {
            $this->user_id = (int) $param_user_id;
        }

        $this->info('USER_ID: ' . $this->user_id);

        $this->iniciar_clientes();
        $this->iniciar_providers();
        $this->set_sales_moneda_id();
        $this->recalcular_credit_accounts_pendientes();

        $this->info('*******************');
        $this->info('LISTO');
        $this->info('*******************');

        return 0;
    }

    /**
     * Actualiza moneda_id=1 en ventas sin moneda con un solo UPDATE.
     *
     * @return void
     */
    private function set_sales_moneda_id()
    {
        $updated_rows = Sale::where('user_id', $this->user_id)
            ->whereNull('moneda_id')
            ->update(['moneda_id' => 1]);

        $this->info('Ventas con moneda_id actualizadas: ' . $updated_rows);
    }

    /**
     * Crea credit_accounts y vincula movimientos de proveedores.
     *
     * @return void
     */
    private function iniciar_providers()
    {
        $providers = Provider::where('user_id', $this->user_id)
            ->withTrashed()
            ->get();

        $this->comment(count($providers) . ' proveedores');

        $processed = 0;
        foreach ($providers as $provider) {
            CreditAccountHelper::crear_credit_accounts('provider', $provider->id, $this->user_id);
            $this->queue_vincular('provider', $provider, 1);
            $processed++;

            if ($processed % 25 === 0) {
                $this->comment('Proveedores procesados: ' . $processed);
            }
        }

        $this->info('Proveedores ok (' . $processed . ')');
    }

    /**
     * Crea credit_accounts y vincula movimientos de clientes.
     *
     * @return void
     */
    private function iniciar_clientes()
    {
        $clients_query = Client::where('user_id', $this->user_id)
            ->orderBy('id', 'ASC')
            ->withTrashed();

        if ($this->argument('client_id')) {
            $clients_query->where('id', '>=', (int) $this->argument('client_id'));
        }

        $clients = $clients_query->get();
        $this->comment(count($clients) . ' clientes');

        $processed = 0;
        foreach ($clients as $client) {
            CreditAccountHelper::crear_credit_accounts('client', $client->id, $this->user_id);

            if (is_null($client->moneda_id) || (int) $client->moneda_id === 1) {
                $this->queue_vincular('client', $client, 1);
            } elseif ((int) $client->moneda_id === 2) {
                $client_original = Client::where('id', $client->client_pesos_id)->first();
                $this->queue_vincular('client', $client_original, 2);
            }

            $processed++;
            if ($processed % 25 === 0) {
                $this->comment('Clientes procesados: ' . $processed);
            }
        }

        $this->info('Clientes ok (' . $processed . ')');
    }

    /**
     * Vincula current_acounts a credit_account y encola recalculo para el final.
     *
     * @param  string  $model_name
     * @param  Client|Provider|null  $model
     * @param  int  $moneda_id
     * @return void
     */
    private function queue_vincular($model_name, $model, $moneda_id)
    {
        if (! $model) {
            return;
        }

        $credit_account = CreditAccount::where('model_name', $model_name)
            ->where('model_id', $model->id)
            ->where('moneda_id', $moneda_id)
            ->first();

        if (! $credit_account) {
            return;
        }

        $foreign_key = $model_name . '_id';

        CurrentAcount::query()
            ->where($foreign_key, $model->id)
            ->update(['credit_account_id' => $credit_account->id]);

        $this->credit_accounts_to_recalc[$credit_account->id] = $credit_account->id;
    }

    /**
     * Ejecuta checkSaldos y checkPagos una vez por credit_account afectada.
     *
     * @return void
     */
    private function recalcular_credit_accounts_pendientes()
    {
        $credit_account_ids = array_values($this->credit_accounts_to_recalc);
        $total = count($credit_account_ids);

        if ($total === 0) {
            $this->info('Sin credit_accounts para recalcular.');

            return;
        }

        $this->info('Recalculando ' . $total . ' credit_accounts...');

        $done = 0;
        foreach ($credit_account_ids as $credit_account_id) {
            CurrentAcountHelper::checkSaldos($credit_account_id);
            CurrentAcountHelper::checkPagos($credit_account_id);
            $done++;

            if ($done % 10 === 0) {
                $this->comment('Recalculadas ' . $done . ' / ' . $total);
            }
        }

        $this->info('Recalculo de saldos y pagos completado.');
    }
}
