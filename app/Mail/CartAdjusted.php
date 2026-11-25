<?php

namespace App\Mail;

use App\Http\Controllers\Helpers\ColorDeMailHelper;
use App\Http\Controllers\Helpers\ImagenParaMailHelper;
use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Mail al comprador cuando se le ajusta un renglón del carrito por stock (misión carritos-ajuste-stock).
 * Lo manda `ProcessSendCartAdjustedMail`; NO implementa ShouldQueue (el job ya es el que se encola).
 *
 * Dos tipos:
 *  - 'recortado': "Agregaste X unidades y ahora tu carrito tiene Y".
 *  - 'restaurado': "Volvió a haber stock: tu carrito vuelve a tener Y unidades (de las X que habías agregado)".
 *
 * Misma estructura visual que `Advise` (logo, color del comercio, nombre del artículo).
 */
class CartAdjusted extends Mailable
{
    use Queueable, SerializesModels;

    /** @var int Dueño del comercio. */
    public $owner_id;

    /** @var string|null Nombre del comprador. */
    public $buyer_name;

    /** @var int Artículo del renglón. */
    public $article_id;

    /** @var int|null Variante del renglón. */
    public $variant_id;

    /** @var string 'recortado' | 'restaurado'. */
    public $type;

    /** @var float Lo que el comprador había agregado (X). */
    public $requested_amount;

    /** @var float Lo que quedó en el carrito (Y). */
    public $new_amount;

    /**
     * @param int $owner_id
     * @param string|null $buyer_name
     * @param int $article_id
     * @param int|null $variant_id
     * @param string $type 'recortado' | 'restaurado'.
     * @param float $requested_amount
     * @param float $new_amount
     * @return void
     */
    public function __construct($owner_id, $buyer_name, $article_id, $variant_id, $type, $requested_amount, $new_amount)
    {
        $this->owner_id = $owner_id;
        $this->buyer_name = $buyer_name;
        $this->article_id = $article_id;
        $this->variant_id = $variant_id;
        $this->type = $type;
        $this->requested_amount = $requested_amount;
        $this->new_amount = $new_amount;
    }

    /**
     * Arma el mail. Tira excepción si no puede resolver el artículo o el comercio (el job la registra).
     *
     * @return $this
     */
    public function build()
    {
        $article = Article::with('images')->find($this->article_id);
        if (is_null($article)) {
            throw new \RuntimeException('CartAdjusted: articulo no disponible id '.$this->article_id);
        }

        // Sólo manda el dueño persistido del artículo (la sesión puede ser de otro comercio).
        $user = User::where('id', $article->user_id)->withAll()->first();
        if (is_null($user)) {
            throw new \Exception('CartAdjusted::build - no se pudo resolver el usuario para el articulo id '.$this->article_id);
        }

        $configuration = $user->online_configuration;
        if (is_null($configuration)) {
            $configuration = OnlineConfiguration::where('user_id', $user->id)->first();
        }

        // Hosts permitidos para incrustar imágenes (mismo criterio que Advise).
        $origin = config('app.url');
        $hosts = (array) config('mail.image_allowed_hosts', []);
        foreach ([$origin, config('filesystems.disks.public.url'), config('filesystems.disks.s3.url')] as $public_origin) {
            $safe = ImagenParaMailHelper::publicUrl($public_origin);
            if ($safe !== null) {
                $hosts[] = strtolower(parse_url($safe, PHP_URL_HOST));
            }
        }
        $hosts = array_values(array_unique(array_filter($hosts, 'is_string')));
        $helper = app(ImagenParaMailHelper::class);

        $logo = null;
        foreach ([$configuration ? $configuration->logo_url : null, $user->image_url] as $source) {
            if (!empty($source)) {
                $logo = $helper->prepare($source, $origin, $hosts, 440);
                if ($logo !== null) {
                    break;
                }
            }
        }

        $palette = ColorDeMailHelper::palette(
            $configuration ? $configuration->primary_color : null,
            $configuration ? array($configuration->add_to_cart_button_color, $configuration->secondary_color) : array()
        );

        $image = null;
        if (isset($article->images) && count($article->images) > 0) {
            $image = $helper->prepare($article->images[0]->hosting_url, $origin, $hosts, 192);
        }

        // Variante del renglón: el comprador tiene que saber de cuál se trata.
        $variant_name = null;
        if (!is_null($this->variant_id)) {
            $variant = ArticleVariant::find($this->variant_id);
            if (!is_null($variant)) {
                $variant_name = $variant->variant_description;
            }
        }

        // Botón "Ver mi carrito": sólo en producción y si la tienda tiene URL.
        $cart_url = null;
        if (config('app.APP_ENV') == 'production' && !empty($user->online)) {
            $cart_url = ImagenParaMailHelper::publicUrl(rtrim($user->online, '/').'/carrito');
        }

        $is_restored = $this->type === 'restaurado';

        $subject = $is_restored
            ? 'Volvió el stock de '.$article->name
            : 'Actualizamos tu carrito en '.$user->company_name;

        return $this->subject($subject)
                    ->view('emails.carts.adjusted', [
                        'company_name'     => $user->company_name,
                        'logo'             => $logo,
                        'accent_color'     => $palette['background'],
                        'cta_text_color'   => $palette['text'],
                        'buyer_name'       => $this->buyer_name,
                        'article_name'     => $article->name,
                        'variant_name'     => $variant_name,
                        'image'            => $image,
                        'is_restored'      => $is_restored,
                        'requested_text'   => self::units_text($this->requested_amount),
                        'new_text'         => self::units_text($this->new_amount),
                        'new_number'       => self::format_number($this->new_amount),
                        'requested_number' => self::format_number($this->requested_amount),
                        'cart_url'         => $cart_url,
                    ]);
    }

    /**
     * Número sin ceros de más: 5.0 -> "5", 2.5 -> "2,5".
     *
     * @param float $amount
     * @return string
     */
    private static function format_number($amount)
    {
        $amount = (float) $amount;

        return rtrim(rtrim(number_format($amount, 2, ',', ''), '0'), ',');
    }

    /**
     * "5 unidades" / "1 unidad".
     *
     * @param float $amount
     * @return string
     */
    private static function units_text($amount)
    {
        $text = self::format_number($amount);

        return $text.((float) $amount == 1.0 ? ' unidad' : ' unidades');
    }
}
