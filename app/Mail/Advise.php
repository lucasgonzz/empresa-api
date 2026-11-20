<?php

namespace App\Mail;

use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Http\Controllers\Helpers\ImagenParaMailHelper;
use App\Http\Controllers\Helpers\ColorDeMailHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

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
        // El $article puede llegar como stdClass parcial desde algunos callers de stock
        // (ver ArticleHelper::checkAdvises). Re-fetch del modelo real con imagenes para
        // garantizar name, slug, final_price e images.
        $article = Article::with('images')->find($this->article->id);
        if (is_null($article)) {
            throw new \RuntimeException('Advise: articulo persistido no disponible');
        }

        // La sesión puede pertenecer a otro comercio; sólo manda el dueño persistido.
        $user = isset($article->user_id)
            ? User::where('id', $article->user_id)->withAll()->first()
            : null;
        if (is_null($user)) {
            throw new \Exception('Advise::build - no se pudo resolver el usuario para el articulo id '.$this->article->id);
        }

        $configuration = $user->online_configuration;
        if (is_null($configuration)) {
            $configuration = OnlineConfiguration::where('user_id', $user->id)->first();
        }

        $origin = config('app.url');
        $hosts = (array) config('mail.image_allowed_hosts', []);
        foreach ([$origin, config('filesystems.disks.public.url'), config('filesystems.disks.s3.url')] as $publicOrigin) {
            $safe = ImagenParaMailHelper::publicUrl($publicOrigin);
            if ($safe !== null) {
                $hosts[] = strtolower(parse_url($safe, PHP_URL_HOST));
            }
        }
        $hosts = array_values(array_unique(array_filter($hosts, 'is_string')));
        $helper = app(ImagenParaMailHelper::class);
        $logo = null;
        foreach ([$configuration ? $configuration->logo_url : null, $user->image_url] as $source) {
            if (!empty($source)) {
                $logo = $helper->prepare($source, $origin, $hosts);
                if ($logo !== null) {
                    break;
                }
            }
        }
        $palette = ColorDeMailHelper::palette($configuration ? $configuration->primary_color : null);

        // hosting_url es el contrato real de Image; jamás usar el placeholder como artículo.
        $image = null;
        if (isset($article->images) && count($article->images) > 0) {
            $image = $helper->prepare($article->images[0]->hosting_url, $origin, $hosts);
        }

        // Precio solo si el negocio NO trabaja con listas de precio (con listas el precio
        // depende del comprador; mostrar uno solo seria enganoso). Con una sola lista, la «Precio
        // final», hay un solo precio y es el de siempre (misión prueba-listas-siempre, 3/11/2026).
        $mostrar_precio = !UserHelper::uses_listas_de_precio($user) || PriceTypeHelper::solo_lista_precio_final($user);
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
            $article_url = ImagenParaMailHelper::publicUrl(rtrim($user->online, '/').'/articulos/'.rawurlencode($article->slug).'/'.$user->id);
        }

        return $this->subject('Volvió el stock de '.$article->name)
                    ->view('emails.articles.advise', [
                        'company_name'   => $user->company_name,
                        'logo'           => $logo,
                        'accent_color'   => $palette['background'],
                        'cta_text_color' => $palette['text'],
                        'article_name'   => $article->name,
                        'image'          => $image,
                        'mostrar_precio' => $mostrar_precio,
                        'precio'         => $precio,
                        'article_url'    => $article_url,
                    ]);
    }
}
