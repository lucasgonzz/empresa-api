<?php

namespace App\Console\Commands;

use App\Mail\SimpleMail;
use App\Models\Article;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class check_stocks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check_stocks {user_id}';

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
        $articulos_mal = [];
        $articles = Article::where('user_id', $this->argument('user_id'))
                            ->get();

        $this->info(count($articles).' articulos');      

        foreach ($articles as $article) {
            
            $last_stock_movement = StockMovement::where('article_id', $article->id)
                                                ->orderBy('id', 'DESC')
                                                ->first();

            if ($last_stock_movement) {
                $stock_resultante = $last_stock_movement->stock_resultante;
                if ($article->stock != $stock_resultante) {
                    $articulos_mal[] = 'Articulo num: '.$article->num.'. Nombre: '.$article->name;
                }
            }
        }

        $this->enviar_mail($articulos_mal);
        $this->info('Termino');      
        return 0;
    }

    function enviar_mail($articulos_mal) {

        if (count($articulos_mal) > 0) {

            $owner = User::find($this->argument('user_id'));

            Mail::to('lucasgonzalez5500@gmail.com')->send(new SimpleMail([
                'asunto'    => 'Stocks Mal | '.$owner->company_name . ' | user_id: '.$this->argument('user_id'),
                'mensajes'  => $articulos_mal,
            ]));      
            $this->comment('Se envio mail');      
        }
    }
}
