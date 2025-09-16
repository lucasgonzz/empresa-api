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

class iniciar_credit_accounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'iniciar_credit_accounts {user_id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {

        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {


        $this->user_id = $this->argument('user_id');
        
        if (!$this->user_id) {

            $this->user_id = env('USER_ID');
        }

        
        $this->iniciar_clientes();

        $this->iniciar_providers();

        $this->set_sales_moneda_id();

        $this->info('Listo');

        return 0;
    }

    function set_sales_moneda_id() {
        $sales = Sale::where('user_id', $this->user_id)
                        ->get();

        foreach ($sales as $sale) {
            if (is_null($sale->moneda_id)) {

                $sale->moneda_id = 1;
                $sale->save();
            }
        }
        $this->info('Ventas ok');

    }

    function iniciar_providers() {

        $providers = Provider::where('user_id', $this->user_id)
                            ->get();

        foreach ($providers as $provider) {
            
            CreditAccountHelper::crear_credit_accounts('provider', $provider->id, $this->user_id);
            
            $this->vincular_current_acounts('provider', $provider, 1);            

            // if (
            //     is_null($provider->moneda_id)
            //     || $provider->moneda_id == 1
            // ) {

            //     $provider->moneda_id = 1;
            //     $provider->timestamps = false;
            //     $provider->save();

            //     $this->vincular_current_acounts('provider', $provider, 1);            
            
            // } else if ($provider->moneda_id == 2) {


            //     // $client_original = provider::where('id', $provider->client_pesos_id)
            //     //                             ->first();

            //     // $this->vincular_current_acounts('provider', $client_original, 2);            
            // }
        }
    }

    function iniciar_clientes() {
        $clients = Client::where('user_id', $this->user_id)
                            ->get();

        foreach ($clients as $client) {
            
            CreditAccountHelper::crear_credit_accounts('client', $client->id, $this->user_id);

            if (
                is_null($client->moneda_id)
                || $client->moneda_id == 1
            ) {

                $this->vincular_current_acounts('client', $client, 1);            
            
            } else if ($client->moneda_id == 2) {


                $client_original = Client::where('id', $client->client_pesos_id)
                                            ->first();

                $this->vincular_current_acounts('client', $client_original, 2);            
            }
        }
    }

    function vincular_current_acounts($model_name, $model, $moneda_id) {
        
        $credit_account = CreditAccount::where('model_name', $model_name)
                                            ->where('model_id', $model->id)
                                            ->where('moneda_id', $moneda_id)
                                            ->first();

        $current_acounts = CurrentAcount::where($model_name.'_id', $model->id)
                                        ->update([
                                            'credit_account_id'     => $credit_account->id
                                        ]);

        CurrentAcountHelper::checkSaldos($credit_account->id);
        CurrentAcountHelper::checkPagos($credit_account->id, true);

    }
}
