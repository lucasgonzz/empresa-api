<?php

namespace Tests\Feature\Seguridad\RutasPublicas;

use App\Http\Middleware\AdminApiKey;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\ClaveDeAdminEnSetup;
use App\Http\Middleware\DescargaDelComercio;
use App\Http\Middleware\PrintAgentToken;
use App\Http\Middleware\RutaDeMantenimiento;
use Illuminate\Routing\Route as RutaDeLaravel;
use Illuminate\Support\Facades\Route;

/**
 * EL GUARDIÁN DE LA CLASE (misión cerrar-rutas-web-publicas, 10/10/2026): ninguna ruta del sistema queda
 * sin protección sin que alguien lo haya decidido y escrito el porqué.
 *
 * El agujero que cerró la misión no era una ruta: era una CLASE. `routes/web.php` se monta con el grupo
 * `web`, que no tiene ninguna capa de autenticación, y durante años se le fueron sumando rutas que borran,
 * copian bases o crean dueños, cada una "para una cosa puntual". Este test recorre TODO el router
 * (web.php, mantenimiento.php, api.php y lo que registren los paquetes), resuelve el middleware que corre
 * de verdad (`gatherRouteMiddleware`: grupos expandidos y `withoutMiddleware` aplicados) y exige que cada
 * ruta pase por un protector, o que esté en PUBLICAS con su motivo.
 *
 * Protectores: el `Authenticate` de Laravel (sanctum o sesión), `AdminApiKey`, `ClaveDeAdminEnSetup`,
 * `PrintAgentToken`, `DescargaDelComercio` y `RutaDeMantenimiento` (con o sin `:demo`).
 *
 * 🔴 SI ESTE TEST TE MARCA UNA RUTA NUEVA: si es de mantenimiento, debug o algo que se abre a mano, va en
 * `routes/mantenimiento.php` (queda cerrada sola). Si es de un comercio, va detrás de `auth:sanctum` (api)
 * o de `descarga.comercio` (PDF/export de web). Si de verdad tiene que ser pública (un webhook, un callback
 * OAuth, algo que se usa antes del login), se agrega a PUBLICAS con el motivo y con qué la protege, si algo
 * la protege. Agregarla a la lista es una decisión, no un trámite.
 */
class Ninguna_ruta_publica_sin_saberlo_Test extends RutasPublicasTestCase
{
    /** Las clases de middleware que cuentan como protección. */
    const PROTECTORES = [
        Authenticate::class,
        AdminApiKey::class,
        ClaveDeAdminEnSetup::class,
        PrintAgentToken::class,
        DescargaDelComercio::class,
        RutaDeMantenimiento::class,
    ];

    /**
     * Las rutas sin protector, a propósito. Clave: "MÉTODOS uri" (métodos sin HEAD, separados por `|`).
     */
    const PUBLICAS = [
        // --- Sesión: el que las usa todavía no tiene sesión, o la está cerrando --------------------
        'POST login'                            => 'Login por documento y contraseña. Ojo: loginLucas() es una puerta maestra sin contraseña (hallazgo ya reportado el 27/9/2026, fuera de esta misión).',
        'POST login-forzado'                    => 'Login que expulsa la otra sesión: exige credenciales válidas (Auth::attempt).',
        'POST login-from-version-session-token' => 'Abre sesión con un token de un solo uso que emite version-session-token (que sí exige sesión).',
        'POST logout'                           => 'Cierra la sesión propia; sin sesión no hace nada.',
        'GET sanctum/csrf-cookie'               => 'Ruta de Sanctum: entrega la cookie XSRF que la SPA necesita antes del login.',
        'GET|POST broadcasting/auth'            => 'Autorización de canales de Pusher (Broadcast::routes): exige el usuario de la sesión y el callback de routes/channels.php; sin sesión, 403.',

        // --- Recuperación de contraseña (CommonLaravel\PasswordResetController) --------------------
        'POST password-reset/send-verification-code'  => 'La usa quien no puede entrar. Manda un código de 6 dígitos al mail del usuario (en caché, 30 minutos).',
        'POST password-reset/check-verification-code' => 'Verifica el código sin consumirlo; al 5.º intento fallido el código muere (misión cerrar-rutas-web-publicas).',
        'POST password-reset/update-password'         => 'Cambia la contraseña SOLO con el código válido (422 sin él) y lo consume (misión cerrar-rutas-web-publicas).',

        // --- Callbacks OAuth y webhooks de plataformas externas ------------------------------------
        'GET platform-connector/mercadolibre/callback' => 'Callback OAuth de Mercado Libre: lo llama ML con el `code`.',
        'GET platform-connector/tiendanube/callback'   => 'Callback OAuth de Tienda Nube: lo llama TN con el `code`.',
        'POST mercadolibre/webhook'                    => 'Webhook viejo de ML: apunta a un método que no existe y el CSRF le da 419 antes. Las notificaciones reales entran por api/meli/notifications.',
        'POST tiendanube/webhook/store_redact'         => 'Webhook obligatorio de privacidad de Tienda Nube: responde {status: ok} y no hace nada.',
        'POST tiendanube/webhook/customers_redact'     => 'Webhook obligatorio de privacidad de Tienda Nube: responde {status: ok} y no hace nada.',
        'POST tiendanube/webhook/customers_data_request' => 'Webhook obligatorio de privacidad de Tienda Nube: responde {status: ok} y no hace nada.',
        'GET api/integraciones/mercadopago/callback'   => 'Callback OAuth de Mercado Pago: consume un `state` aleatorio de un solo uso (OauthState::consume).',
        'GET api/integraciones/zippin/callback'        => 'Callback OAuth de Zippin: consume un `state` aleatorio de un solo uso (ZippinOauthState::consume).',
        'POST api/meli/notifications'                  => 'Webhook de Mercado Libre: no confía en el cuerpo, re-consulta la orden a ML con el token del vendedor.',
        'POST api/zipnova/webhook'                     => 'Webhook de Zipnova: no confía en el cuerpo, re-consulta el envío a Zipnova; throttle 120/min.',
        'POST api/whatsapp-bot/webhook'                => 'Webhook de Kapso (WhatsApp): firma HMAC-SHA256 del cuerpo con webhook_secret; 401 si no coincide.',

        // --- Archivos y descargas con su propia guarda ---------------------------------------------
        'GET storage/{path}'        => 'Imágenes de artículos en hosting sin symlink; confinado a storage/app/public por StoragePathHelper.',
        'GET imported-files/{path}' => 'Excel subidos por el cliente (nombre con azar); confinado por StoragePathHelper.',
        'GET exported-files/{path}' => 'Exportaciones de la notificación global (nombre con uniqid); confinado por StoragePathHelper.',
        'GET client/pdf'            => 'Exige sesión en su propio controlador desde el 5/10/2026 (401 sin sesión); la fija PdfDeVentaPublico.',
        'GET afip-txt/{mes_inicio}/{mes_fin}'           => 'Lo protege AfipController::exportVentas (401 sin sesión) desde la misión afip-txt-por-duenio (10/10/2026).',
        'GET afip-txt-alicuotas/{mes_inicio}/{mes_fin}' => 'Lo protege AfipController::exportAlicuotasTxt (401 sin sesión) desde la misión afip-txt-por-duenio (10/10/2026).',

        // --- api.php: públicas por diseño, con o sin guarda propia ---------------------------------
        'GET api/version-activa'           => 'La pide la SPA al cargar, antes del login: devuelve solo la versión por defecto del dueño.',
        'GET api/plan'                     => 'Catálogo público de planes (precios de lista).',
        'GET api/plan-feature'             => 'Catálogo público de features de los planes.',
        'POST api/internal/report-front-error' => 'Errores del SPA, que pueden pasar antes del login; con throttle por firma del error.',
        'POST api/demo/ingreso'            => 'Ingreso a una demo por magic link: busca el sha256 del token (vigente, no revocado).',
        'GET api/informe-compartido/{token}' => 'Un informe del mostrador por token de 64 caracteres guardado como hash; vence a los 7 días.',
        'GET api/integraciones/articulos/{export_key}' => 'Catálogo para n8n por la export_key (>= 20 caracteres) del dueño; throttle 120/min. Es el reemplazo de n8n/productos-disponibles.',
        'POST api/print-agent/vincular'    => 'El agente de impresión canjea un código de vinculación (sha256, un solo uso, con vencimiento) por su token; throttle 20/min.',
        'POST api/admin-sync/demo-setup'   => 'Lo llama admin-api sin clave para armar las demos. Guarda propia (misión guarda-demo-setup): fuera de una demo, con la base con datos, 409 sin tocar nada (GuardaDeInstanciaDelDemoSetupTest).',
    ];

    /**
     * Toda ruta sin protector está en PUBLICAS. La que no, falla con un mensaje que dice qué hacer.
     *
     * @test
     */
    public function toda_ruta_sin_proteccion_esta_declarada_publica_con_su_motivo()
    {
        $sin_declarar = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {

            if ($this->tiene_protector($ruta)) {
                continue;
            }

            $clave = $this->clave_de($ruta);

            if (!array_key_exists($clave, self::PUBLICAS)) {
                $sin_declarar[] = $clave . '  (' . $ruta->getActionName() . ')';
            }
        }

        $this->assertSame([], $sin_declarar,
            "Hay rutas SIN protección que no están declaradas públicas:\n  " . implode("\n  ", $sin_declarar) . "\n"
            . 'Si es de mantenimiento o debug, movela a routes/mantenimiento.php (queda cerrada sola). Si es de un comercio, '
            . 'protegela (auth:sanctum en api.php, descarga.comercio en web.php). Si tiene que ser pública, agregala a '
            . 'PUBLICAS de este test con su motivo.'
        );
    }

    /**
     * Cada entrada de PUBLICAS existe en el router, sigue sin protector y tiene motivo. Si una se borra,
     * se renombra o se protege, la lista tiene que acompañar: si no, deja de describir lo que hay.
     *
     * @test
     */
    public function cada_ruta_declarada_publica_existe_y_sigue_sin_proteccion()
    {
        $por_clave = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            $por_clave[$this->clave_de($ruta)] = $ruta;
        }

        foreach (self::PUBLICAS as $clave => $motivo) {

            $this->assertArrayHasKey($clave, $por_clave, 'La ruta ' . $clave . ' de PUBLICAS ya no existe en el router: sacala de la lista.');

            $this->assertNotSame('', trim($motivo), 'La ruta ' . $clave . ' está declarada pública sin motivo.');

            $this->assertFalse($this->tiene_protector($por_clave[$clave]), 'La ruta ' . $clave . ' ya tiene protector: sacala de PUBLICAS.');
        }
    }

    /**
     * El guardián no es ciego: las rutas que la misión cerró las ve como protegidas, y una ruta nueva
     * sin protección registrada acá mismo la ve como pública sin declarar.
     *
     * @test
     */
    public function el_guardian_ve_lo_que_tiene_que_ver()
    {
        foreach (['helpers/{method}/{param?}/{param_2?}', 'cambiar-bbdd/{company_name}/{bbbdd_destino}', 'extensiones', 'register-user/{name}/{doc_number}/{company_name}/{iva_included}/{extencions_id}/{database?}'] as $uri) {
            $ruta = $this->ruta_get($uri);

            $this->assertNotNull($ruta, 'No existe la ruta ' . $uri . '.');
            $this->assertTrue($this->tiene_protector($ruta), 'El guardián no ve protegida a ' . $uri . '.');
        }

        // Una ruta nueva en el grupo `web`, sin nada más: exactamente el caso que se repitió durante años.
        $nueva = Route::middleware('web')->get('/_ruta_nueva_del_guardian', function () {
            return 'nada';
        });

        $this->assertFalse($this->tiene_protector($nueva), 'Una ruta con solo `web` tiene que contar como desprotegida.');
        $this->assertArrayNotHasKey($this->clave_de($nueva), self::PUBLICAS);
    }

    /**
     * ¿Pasa por alguno de los PROTECTORES? Se mira el middleware resuelto, sin los parámetros
     * (`Authenticate:sanctum`, `RutaDeMantenimiento:demo`, `DescargaDelComercio:sale,id`...).
     *
     * @param  RutaDeLaravel  $ruta
     * @return bool
     */
    protected function tiene_protector(RutaDeLaravel $ruta)
    {
        foreach (app('router')->gatherRouteMiddleware($ruta) as $middleware) {

            if (!is_string($middleware)) {
                continue;
            }

            $clase = explode(':', $middleware, 2)[0];

            if (in_array($clase, self::PROTECTORES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "MÉTODOS uri", con los métodos sin HEAD separados por `|`.
     *
     * @param  RutaDeLaravel  $ruta
     * @return string
     */
    protected function clave_de(RutaDeLaravel $ruta)
    {
        return implode('|', $this->metodos_sin_head($ruta)) . ' ' . $ruta->uri();
    }

    /**
     * La ruta GET con esa URI, o null.
     *
     * @param  string  $uri
     * @return RutaDeLaravel|null
     */
    protected function ruta_get($uri)
    {
        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            if ($ruta->uri() === $uri && in_array('GET', $ruta->methods(), true)) {
                return $ruta;
            }
        }

        return null;
    }
}
