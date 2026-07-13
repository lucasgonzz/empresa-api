<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OnlineConfiguration extends Model
{
    protected $guarded = [];

    /**
     * mail_password nunca debe viajar en una respuesta JSON de la API: se guarda encriptada
     * (cast 'encrypted', prompt 358) y ademas se oculta de toda serializacion. La UI se entera
     * de si ya hay una contraseña guardada a traves del atributo calculado has_mail_password.
     */
    protected $hidden = ['mail_password'];

    /**
     * Atributo calculado que se agrega siempre a la serializacion del modelo, para que la UI
     * pueda saber si ya existe una contraseña de mail guardada sin necesidad de exponerla.
     */
    protected $appends = ['has_mail_password'];

    protected $casts = [
        'auto_scroll_home' => 'integer',
        'auto_scroll_home_init' => 'integer',
        'auto_scroll_home_interval' => 'integer',
        'article_description_font_size' => 'integer',
        // Master switch de correo propio por cliente (prompt 358).
        'mail_enabled' => 'boolean',
        'mail_port' => 'integer',
        // Cast nativo de Laravel: encripta/desencripta automaticamente con la APP_KEY al leer y
        // escribir el atributo. Verificado soportado en esta version de Laravel (8.75).
        'mail_password' => 'encrypted',
    ];

    function scopeWithAll($q) {

    }

    /**
     * Indica si ya hay una contraseña de mail guardada, sin desencriptarla ni exponerla.
     * Se lee el atributo crudo (ciphertext) para evitar el costo/riesgo de desencriptar solo
     * para chequear si esta vacio.
     *
     * @return bool
     */
    function getHasMailPasswordAttribute() {
        return !empty($this->attributes['mail_password']);
    }
}
