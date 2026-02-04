<?php

namespace App\Console\Commands;

use App\Models\CurrentAcount;
use Illuminate\Console\Command;

class set_afip_ticket_nota_credito_data extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_afip_ticket_nota_credito_data';

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
        
        $models = CurrentAcount::where('user_id', config('app.USER_ID'))
                                ->where('status', 'nota_credito')
                                ->whereHas('afip_ticket')
                                ->orderBy('created_at', 'DESC')
                                ->get();

        $this->info(count($models).' notas de credito');
        foreach ($models as $nota_credito) {
            
            $afip_ticket = $nota_credito->afip_ticket;

            $afip_ticket->afip_information_id = $nota_credito->sale->afip_tickets[0]->afip_information_id;
            $afip_ticket->afip_tipo_comprobante_id = $nota_credito->sale->afip_tickets[0]->afip_tipo_comprobante_id;
                
            $this->line('Nota credito N° '.$nota_credito->id.', afip_ticket: '.$afip_ticket->id.' afip_information_id: '. $nota_credito->sale->afip_tickets[0]->afip_information_id);
            $afip_ticket->save();
        }
        $this->info('Listo');
        return 0;
    }
}
