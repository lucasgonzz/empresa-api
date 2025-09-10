<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Models\Client;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessRecalculateCurrentAcounts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    
    protected $company_name;
    
    public $timeout = 9999999;

    public function __construct($company_name)
    {
        $this->company_name = $company_name;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $user = User::where('company_name', $this->company_name)->first();

        Log::info('Recalculando pagos de '.$user->company_name);

        $clients = Client::where('user_id', $user->id)
                            ->orderBy('id', 'ASC')
                            ->where('id', '!=', 7334)
                            ->get();

        Log::info(count($clients).' clientes');

        foreach ($clients as $client) {
            Log::info('Entro con '.$client->name);


            foreach ($client->credit_accounts as $credit_account) {
                CurrentAcountHelper::checkSaldos($credit_account->id);
                CurrentAcountHelper::checkPagos($credit_account->id, true);
            }
            
            foreach ($client->current_acounts as $current_acount) {
                if (!is_null($current_acount->debe)) {
                    if (!is_null($current_acount->sale)) {
                        $current_acount->detalle = 'Venta N°'.$current_acount->sale->num;
                    } else {
                        $current_acount->detalle = 'Nota debito';
                    }
                } else if (!is_null($current_acount->haber)) {
                    if ($current_acount->status == 'nota_credito') {
                        $current_acount->detalle = 'Nota Credito N°'.$current_acount->num_receipt;
                    } else {
                        $current_acount->detalle = 'Pago N°'.$current_acount->num_receipt;
                    }
                }
                $current_acount->save();
            }
            Log::info('Se recalcularon las cuentas corrientes de '.$client->name);
        }
        Log::info('termino');
    }
}
