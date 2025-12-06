<?php

namespace App\Console\Commands;

use App\Models\AfipTicket;
use App\Models\Sale;
use Illuminate\Console\Command;

class set_afip_tickets_data extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_afip_tickets_data {user_id?}';

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

        $user_id = env('USER_ID');
        if (!$user_id) {
            $user_id = $this->argument('user_id');
        }

        $this->info('user_id: '.$user_id);


        $sales = Sale::where('user_id', $user_id)
                        ->whereHas('afip_ticket')
                        ->get();

        $this->info(count($sales).' afip_tickets');

        foreach ($sales as $sale) {
            
            $afip_ticket = $sale->afip_ticket;

            $afip_ticket->afip_information_id = $sale->afip_information_id;
            $afip_ticket->afip_tipo_comprobante_id = $sale->afip_tipo_comprobante_id;
            $afip_ticket->save();

            $this->comment($afip_ticket->id.' ok');
        }
        $this->info('Listo');
        return 0;
    }
}
