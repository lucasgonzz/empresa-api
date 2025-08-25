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
     * @return $this
     */
    public function build()
    {
        if (env('SEND_MAILS', false)) {
            Log::info('Se envio mail advise');
            $user = UserHelper::getFullModel();

            $article_url = null;
            if (env('APP_ENV') == 'production') {
                $article_url = $user->online.'/articulos/'.$this->article->slug.'/'.$user->id;
            }

            return $this->from(env('MAIL_FROM_ADDRESS'), env('MAIL_DOMAIN', 'comerciocity.com'))
                        ->subject('Nuevo stock de '.$this->article->name)
                        ->markdown('emails.articles.advise', [
                            'article'       => $this->article,
                            'user'          => $user,
                            'article_url'   => $article_url,
                            'logo_url'      => $user->image_url,
                        ]);
        }
    }
}
