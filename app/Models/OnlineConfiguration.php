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
     *
     * Los tokens OAuth de Mercado Pago y Zippin (grupo 169, prompt 597) siguen el mismo criterio:
     * se guardan cifrados (cast 'encrypted') y se ocultan de toda serializacion. El frontend solo
     * se entera del estado de conexion a traves de los atributos calculados mp_connected /
     * zippin_connected, nunca del token crudo.
     */
    protected $hidden = ['mail_password', 'mp_access_token', 'mp_refresh_token', 'zippin_access_token', 'zippin_refresh_token'];

    /**
     * NOTA (prompt 597): el modelo usa $guarded = [] mas abajo (mass assignment abierto), igual
     * que las credenciales de Google (ver comentario de google_client_id/google_client_secret).
     * Deliberadamente NO se declara $fillable con las columnas de Mercado Pago/Zippin: el
     * codebase tiene multiples OnlineConfiguration::create([...]) (UserController, UserSeeder,
     * DemoSetupHelper, HelperController, etc.) con arrays que no incluyen estas columnas nuevas.
     * Si se declarara $fillable no vacio, Eloquent deja de tratar el modelo como "totalmente
     * desguardado" (la regla es: fillable no vacio -> ya no cae al fallback de guarded=[] para
     * las claves ausentes) y esos create() existentes perderian silenciosamente el resto de sus
     * campos. Se prioriza no romper esos call-sites por sobre agregar un $fillable redundante.
     */

    /**
     * Atributo calculado que se agrega siempre a la serializacion del modelo, para que la UI
     * pueda saber si ya existe una contraseña de mail guardada sin necesidad de exponerla.
     *
     * mp_connected / zippin_connected (prompt 597): estado de conexion derivado, para que la UI
     * sepa si el comercio ya conecto su cuenta de Mercado Pago / Zippin sin exponer el token.
     */
    protected $appends = ['has_mail_password', 'mp_connected', 'zippin_connected'];

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
        // Notificaciones por mail configurables desde la UI (prompt 382).
        'notificar_pedido_al_negocio' => 'boolean',
        'notificar_pedido_al_cliente' => 'boolean',
        'avisar_ingreso_stock_por_mail' => 'boolean',
        // Login con Google de la tienda online (prompt 589, grupo 164): master switch. No hace
        // falta agregar google_client_id/google_client_secret a $fillable: el modelo usa
        // $guarded = [] (mass assignment abierto), y ademas el controller las asigna una por una.
        'google_login_enabled' => 'boolean',
        // Credenciales de Mercado Pago y Zippin (grupo 169, prompt 597): cada comercio conecta su
        // propia cuenta via OAuth para cobrar (Mercado Pago) y gestionar envios (Zippin).
        'mp_enabled' => 'boolean',
        // Cast nativo de Laravel: encripta/desencripta con la APP_KEY, igual que mail_password.
        // Nunca debe viajar en texto plano ni en BD ni en un response (ver $hidden mas arriba).
        'mp_access_token' => 'encrypted',
        'mp_refresh_token' => 'encrypted',
        'mp_token_expires_at' => 'datetime',
        'zippin_enabled' => 'boolean',
        'zippin_access_token' => 'encrypted',
        'zippin_refresh_token' => 'encrypted',
        'zippin_token_expires_at' => 'datetime',
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

    /**
     * Indica si el comercio ya conecto su cuenta de Mercado Pago via OAuth, sin exponer el
     * token. Se considera conectado si hay access_token guardado y (no tiene fecha de
     * vencimiento registrada, o esa fecha todavia es futura). El criterio de "por vencer"
     * (por ejemplo, avisar unos dias antes) queda a criterio del controller/UI que consuma este
     * valor, no de este accessor.
     *
     * @return bool
     */
    function getMpConnectedAttribute() {
        // Se lee el atributo crudo (attributes) en vez de $this->mp_access_token para no forzar
        // el desencriptado del cast 'encrypted' solo para chequear si esta vacio.
        $has_token = !empty($this->attributes['mp_access_token']);
        // Sin fecha de expiracion guardada se asume vigente (token que no vence o aun no se
        // registro vencimiento); con fecha, solo vale si todavia no paso.
        $not_expired = empty($this->mp_token_expires_at) || $this->mp_token_expires_at->isFuture();
        return $has_token && $not_expired;
    }

    /**
     * Analogo a getMpConnectedAttribute() pero para la cuenta de Zippin conectada del comercio.
     *
     * @return bool
     */
    function getZippinConnectedAttribute() {
        $has_token = !empty($this->attributes['zippin_access_token']);
        $not_expired = empty($this->zippin_token_expires_at) || $this->zippin_token_expires_at->isFuture();
        return $has_token && $not_expired;
    }
}
