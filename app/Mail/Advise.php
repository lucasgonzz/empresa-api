<?php

namespace App\Mail;

use App\Http\Controllers\Helpers\UserHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

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
            $user = UserHelper::getFullModel();
            return $this->from(env('MAIL_FROM_ADDRESS'), 'comerciocity.com')
                        ->subject('Nuevo stock de '.$this->article->name)
                        ->markdown('emails.articles.advise', [
                            'article'       => $this->article,
                            'user'          => $user,
                            'article_url'   => $user->online.'/articulos/'.$this->article->slug.'/'.$user->id,
                            'logo_url'      => $user->hosting_image_url,
                        ]);
        }
    }
}
