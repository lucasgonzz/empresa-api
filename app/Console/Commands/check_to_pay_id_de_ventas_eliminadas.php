<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Models\CurrentAcount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class check_to_pay_id_de_ventas_eliminadas extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check_to_pay_id_de_ventas_eliminadas {user_id?}';

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
        $user_id = config('app.USER_ID');

        $param_user_id = $this->argument('user_id');
        
        if ($param_user_id) {
            $user_id = $param_user_id;
        }
        
        $this->info('USER_ID: '.$user_id);


        $pagos = CurrentAcount::whereNotNull('to_pay_id')
                                ->where('user_id', $user_id)
                                ->get();

        $this->info(count($pagos).' pagos');
        foreach ($pagos as $pago) {
            $debito = CurrentAcount::find($pago->to_pay_id);


            if (!$debito) {
                if ($pago->client) {
                    if (!$pago->credit_account_id) {
                        $this->comment('ERROR: Pago id '.$pago->id.' del cliente '.$pago->client->name.' no tiene credit_account_id');
                        continue;
                    }

                    $this->comment('Se corrigio pago del cliente '.$pago->client->name);
                } else if ($pago->provider) {
                    if (!$pago->credit_account_id) {
                        $this->comment('ERROR: Pago id '.$pago->id.' del proveedor '.$pago->provider->name.' no tiene credit_account_id');
                        continue;
                    }
                    $this->comment('Se corrigio pago del proveedor '.$pago->provider->name);
                }

                $pago->to_pay_id = null;
                $pago->save();

                Log::info('Se puso null en to_pay_id de current_acount id: '.$pago->id);
                
                CurrentAcountHelper::check_saldos_y_pagos($pago->credit_account_id);
            }
        }

        return 0;
    }
}
