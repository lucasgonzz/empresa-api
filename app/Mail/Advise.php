<?php

namespace App\Mail;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\OnlineConfiguration;
use App\Models\User;
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
     *
     * @var \App\Models\Article
     */
    public $article;

    /**
     * @param \App\Models\Article $article
     * @return void
     */
    public function __construct($article)
    {
        $this->article = $article;
    }

    /**
     * Build the message.
     *
     * El gate de si se manda o no vive en checkAdvises() y ProcessSendAdviseMail (columna
     * avisar_ingreso_stock_por_mail de la config online, prompt 383), antes de Mail::send().
     * build() siempre devuelve el mailable armado.
     *
     * @return $this
     */
    public function build()
    {
        Log::info('Se envio mail advise');

        // El $article puede llegar como stdClass parcial desde algunos callers de stock
        // (ver ArticleHelper::checkAdvises). Re-fetch del modelo real con imagenes para
        // garantizar name, slug, final_price e images.
        $article = Article::with('images')->find($this->article->id);
        if (is_null($article)) {
            $article = $this->article;
        }

        // Owner del articulo = el negocio. Primero la sesion (flujo normal desde el ERP);
        // si no hay (job async a futuro), se resuelve del user_id del articulo.
        $user = UserHelper::getFullModel();
        if (is_null($user) && isset($article->user_id)) {
            $user = User::where('id', $article->user_id)->withAll()->first();
        }
        if (is_null($user)) {
            throw new \Exception('Advise::build - no se pudo resolver el usuario para el articulo id '.$this->article->id);
        }

        $configuration = $user->online_configuration;
        if (is_null($configuration)) {
            $configuration = OnlineConfiguration::where('user_id', $user->id)->first();
        }

        // Logo: primero el de la tienda online, si no el del sistema (mismo criterio que los mails de pedido).
        $logo_url = (!is_null($configuration) && !empty($configuration->logo_url))
            ? $configuration->logo_url
            : $user->image_url;
        if (empty($logo_url)) {
            $logo_url = null;
        }

        // Acento = color primario de la tienda del cliente (nunca un color de ComercioCity).
        $accent_color = (!is_null($configuration) && !empty($configuration->primary_color))
            ? $configuration->primary_color
            : '#111827';

        // Imagen del articulo: primera imagen, si no la imagen por defecto de la tienda.
        $image_url = null;
        if (isset($article->images) && count($article->images) > 0 && !empty($article->images[0]->image_url)) {
            $image_url = $article->images[0]->image_url;
        } else if (!is_null($configuration) && !empty($configuration->default_article_image_url)) {
            $image_url = $configuration->default_article_image_url;
        }

        // Precio solo si el negocio NO trabaja con listas de precio (con listas el precio
        // depende del comprador; mostrar uno solo seria enganoso).
        $mostrar_precio = !UserHelper::uses_listas_de_precio($user);
        $precio = null;
        if ($mostrar_precio && isset($article->final_price) && (float) $article->final_price > 0) {
            $precio = '$ '.number_format((float) $article->final_price, 2, ',', '.');
        }
        if (is_null($precio)) {
            $mostrar_precio = false;
        }

        // Link al articulo en la tienda: solo en produccion y si la tienda tiene URL configurada.
        $article_url = null;
        if (config('app.APP_ENV') == 'production' && !empty($user->online) && isset($article->slug)) {
            $article_url = $user->online.'/articulos/'.$article->slug.'/'.$user->id;
        }

        return $this->subject('Volvió el stock de '.$article->name)
                    ->view('emails.articles.advise', [
                        'company_name'   => $user->company_name,
                        'logo_url'       => $logo_url,
                        'accent_color'   => $accent_color,
                        'article_name'   => $article->name,
                        'image_url'      => $image_url,
                        'mostrar_precio' => $mostrar_precio,
                        'precio'         => $precio,
                        'article_url'    => $article_url,
                    ]);
    }
}
