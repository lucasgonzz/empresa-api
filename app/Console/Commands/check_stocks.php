<?php

namespace App\Console\Commands;

use App\Exceptions\MailRechazadoPorElServidorException;
use App\Mail\Helpers\RechazosDeCorreoHelper;
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
    protected $signature = 'check_stocks {user_id?}';

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
        $articles = Article::where('user_id', config('app.USER_ID'))
                            ->get();

        $this->info(count($articles).' articulos');      

        foreach ($articles as $article) {
            
            $last_stock_movement = StockMovement::where('article_id', $article->id)
                                                ->orderBy('id', 'DESC')
                                                ->first();

            if ($last_stock_movement) {
                $stock_resultante = $last_stock_movement->stock_resultante;
                if ($article->stock != $stock_resultante) {
                    $articulos_mal[] = 'Articulo num: '.$article->id.'. Nombre: '.$article->name;
                    $this->comment('Articulo num: '.$article->id.'. Nombre: '.$article->name);
                }
            }
        }

        // Si el mail con los stocks mal no salió, el comando termina con código 1 (lo ven quien lo corre y el cron).
        $mail_salio = $this->enviar_mail($articulos_mal);
        $this->info('Termino');
        return $mail_salio ? 0 : 1;
    }

    /**
     * Manda por mail la lista de artículos con el stock mal (si hay).
     *
     * @param array $articulos_mal Renglones con el artículo y su stock.
     * @return bool false si el servidor de correo rechazó la casilla (el mail NO salió); true si salió o si no había nada que mandar.
     */
    function enviar_mail($articulos_mal) {

        if (count($articulos_mal) > 0) {

            $owner = User::find(config('app.USER_ID'));

            Mail::to('lucasgonzalez5500@gmail.com')->send(new SimpleMail([
                'asunto'    => 'Stocks Mal | '.$owner->company_name . ' | user_id: '.config('app.USER_ID'),
                'mensajes'  => $articulos_mal,
            ]));

            // 🔴 Que send() no haya tirado NO quiere decir que el mail haya salido: si el servidor SMTP rechaza la casilla (un 550 en el RCPT TO), SwiftMailer no
            // tira nada y este comando imprimía "Se envio mail" y devolvía 0 sobre un mail que nunca salió.
            if (!empty(RechazosDeCorreoHelper::del_ultimo_envio())) {
                $this->error('El mail NO salió: '.MailRechazadoPorElServidorException::MOTIVO.'. Se detectaron '.count($articulos_mal).' artículo(s) con el stock mal que no se avisaron.');
                return false;
            }

            $this->comment('Se envio mail');
        }

        return true;
    }
}
