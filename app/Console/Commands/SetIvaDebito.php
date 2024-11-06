<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Console\Command;

class SetIvaDebito extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_iva_debito {company_name}';

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

        $user = User::where('company_name', $this->argument('company_name'))
                        ->first();

        $sales = Sale::where('user_id', $user->id)
                    ->whereHas('afip_ticket')
                    ->orderBy('created_at', 'ASC')
                    ->get();

        foreach ($sales as $sale) {
            
            $afip_ticket = $sale->afip_ticket;
            
            if (!is_null($afip_ticket)) {

                if (is_null($afip_ticket->importe_iva)) {

                    $afip_helper = new AfipHelper($sale, null, $user);
                    $importes = $afip_helper->getImportes();

                    $afip_ticket->importe_iva = $importes['iva'];
                    $afip_ticket->save();

                    $this->info('Se actualizo venta N° '.$sale->num.'. Afip ticket con total de '.$afip_ticket->importe_total.'. Se le puso total iva de '.$importes['iva']);
                    $this->info($importes['iva']);
                }
            }
        }
        
        $this->info('Termino');

        return 0;
    }
}
