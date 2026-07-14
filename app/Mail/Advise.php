<?php

namespace App\Mail;

use App\Http\Controllers\Helpers\UserHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class Advise extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Artículo para el cual se avisa que ingresó stock nuevo.
     * Declarada explícitamente (antes se asignaba dinámicamente sin declarar la propiedad).
     *
     * @var \App\Models\Article
     */
    public $article;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($article)
    {
        $this->article = $article;
    }

    /**
     * Build the message.
     *
     * El gate ya NO vive acá: ahora lo controlan checkAdvises() y el Job ProcessSendAdviseMail,
     * a través de MailNotificationConfigHelper (configuración online del cliente, columna
     * avisar_ingreso_stock_por_mail, prompt 383), antes de llamar a Mail::send(). build() siempre
     * tiene que devolver el mailable armado, porque un build() que no devuelve nada es lo que
     * permitía que el aviso se borrara sin haber mandado nunca el mail.
     *
     * @return $this
     */
    public function build()
    {
        Log::info('Se envio mail advise');
        $user = UserHelper::getFullModel();

        // getFullModel() puede devolver null (sin sesión de auth cae a config('app.USER_ID'),
        // que puede no estar seteado). Sin esto, el blade explota en $user->online.
        if (is_null($user)) {
            throw new \Exception('Advise::build - no se pudo resolver el usuario (UserHelper::getFullModel devolvio null) para el articulo id '.$this->article->id);
        }

        // El link al artículo solo se arma en producción.
        $article_url = null;
        if (config('app.APP_ENV') == 'production') {
            $article_url = $user->online.'/articulos/'.$this->article->slug.'/'.$user->id;
        }

        return $this->subject('Nuevo stock de '.$this->article->name)
                    ->markdown('emails.articles.advise', [
                        'article'       => $this->article,
                        'user'          => $user,
                        'article_url'   => $article_url,
                        'logo_url'      => $user->image_url,
                    ]);
    }
}
