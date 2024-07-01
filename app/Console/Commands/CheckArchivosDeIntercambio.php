<?php

namespace App\Console\Commands;

use App\Http\Controllers\TsvFileController;
use Illuminate\Console\Command;

class CheckArchivosDeIntercambio extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check_archivos_intercambio';

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

        $tsv_controller = new TsvFileController();

        $tsv_controller->leer_archivo_articulos();
        
        $tsv_controller->leer_archivo_precios();
        
        $tsv_controller->leer_archivo_clientes();

        $tsv_controller->escribir_archivos_pedidos();

        $this->info('Comando ejecutado con éxito');

        return 0;
    }
}
