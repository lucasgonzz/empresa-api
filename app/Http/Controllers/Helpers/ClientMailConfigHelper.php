<?php

namespace App\Http\Controllers\Helpers;

use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Helper que resuelve el correo propio por cliente (prompt 358).
 *
 * Cada owner puede configurar, desde la configuracion online de empresa, su propia casilla SMTP
 * para que los mails salientes a sus compradores (avisos de stock, etc.) salgan desde su propia
 * cuenta en vez de la generica del .env. Si el cliente no configuro nada, lo desactivo, o la
 * configuracion esta incompleta, el sistema debe seguir usando el .env sin romperse: por eso todo
 * este helper esta pensado para fallar "silenciosamente" (loguear y devolver false) en vez de
 * tirar excepciones hacia arriba.
 */
class ClientMailConfigHelper
{
    /**
     * Aplica en runtime (via la fachada Config) la configuracion SMTP propia del owner indicado,
     * si existe, esta habilitada y completa. Si no, no toca nada y devuelve false: el envio de
     * mail que se haga despues de este llamado usara el mailer del .env (comportamiento actual).
     *
     * @param int|null $user_id Id del owner a resolver. Si es null, se usa UserHelper::userId(),
     *                          que a su vez cae a config('app.USER_ID') si no hay sesion (ej.
     *                          Job corriendo sin contexto de auth).
     * @return bool true si se aplico la configuracion propia del cliente, false si se debe usar
     *              el fallback del .env.
     */
    static function apply($user_id = null) {
        try {
            // Si no se pasa un user_id explicito (por ejemplo, un Job sin sesion de auth), se
            // resuelve por el mecanismo estandar del proyecto.
            if (is_null($user_id)) {
                $user_id = UserHelper::userId();
            }

            $configuration = OnlineConfiguration::where('user_id', $user_id)->first();

            // Sin configuracion, con el master switch apagado, o con datos incompletos: se cae
            // al .env. mail_password se lee a traves del accessor, que ya maneja el desencriptado
            // (y devuelve null si la APP_KEY cambio y no se pudo desencriptar).
            if (is_null($configuration)
                || !$configuration->mail_enabled
                || empty($configuration->mail_host)
                || empty($configuration->mail_username)
                || empty($configuration->mail_password)) {
                Log::info('ClientMailConfigHelper: sin config de mail propia activa/completa, se usa el .env', [
                    'user_id' => $user_id,
                ]);
                return false;
            }

            // Direccion y nombre "from": si el cliente no completo estos campos opcionales, se
            // usan valores razonables por defecto (la propia casilla y el nombre de la empresa).
            $from_address = $configuration->mail_from_address ?: $configuration->mail_username;
            $from_name = $configuration->mail_from_name;

            if (empty($from_name)) {
                $user = User::find($user_id);
                $from_name = $user ? $user->company_name : $from_name;
            }

            // Pisa en runtime el mailer "smtp" que usa toda la app (config/mail.php no se toca en
            // disco, solo el valor en memoria para este request/Job).
            Config::set('mail.mailers.smtp.host', $configuration->mail_host);
            Config::set('mail.mailers.smtp.port', $configuration->mail_port);
            Config::set('mail.mailers.smtp.encryption', $configuration->mail_encryption);
            Config::set('mail.mailers.smtp.username', $configuration->mail_username);
            Config::set('mail.mailers.smtp.password', $configuration->mail_password);
            Config::set('mail.from.address', $from_address);
            Config::set('mail.from.name', $from_name);
            Config::set('mail.default', 'smtp');

            // Sin esto, Laravel sigue usando la instancia del mailer ya armada con los valores
            // viejos del .env (el Mailer/Swift_Mailer se cachea la primera vez que se resuelve).
            if (app()->bound('mail.manager') && method_exists(app('mail.manager'), 'forgetMailers')) {
                app('mail.manager')->forgetMailers();
            } else {
                // Alternativa por si la version de Laravel no expone forgetMailers().
                app()->forgetInstance('mailer');
                app()->forgetInstance('swift.mailer');
            }

            Log::info('ClientMailConfigHelper: se aplico config de mail propia del cliente', [
                'user_id' => $user_id,
                'mail_host' => $configuration->mail_host,
            ]);

            return true;
        } catch (\Exception $e) {
            // Configurar mal el correo nunca puede tumbar el envio entero: se loguea y se cae
            // al fallback del .env.
            Log::error('ClientMailConfigHelper: error aplicando config de mail propia, se usa el .env', [
                'user_id' => $user_id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
