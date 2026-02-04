<?php

namespace App\Console\Commands;

use App\Models\MercadoLibreToken;
use App\Services\MercadoLibre\ProductoDownloaderService;
use Illuminate\Console\Command;

class descargar_productos_meli extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'descargar_productos_meli';

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
        $service = new ProductoDownloaderService(config('app.USER_ID'));

        $token = MercadoLibreToken::where('user_id', config('app.USER_ID'))->first();

        $service->importar_productos($token->meli_user_id);
        return 0;
    }
}
