<?php

namespace App\Http\Controllers\Helpers;

use App\Models\AcopioArticleDelivery;
use App\Models\AfipTicket;
use App\Models\AperturaCaja;
use App\Models\Article;
use App\Models\Budget;
use App\Models\Caja;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\DepositMovement;
use App\Models\Order;
use App\Models\OrderProduction;
use App\Models\PdfLink;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\ResumenCaja;
use App\Models\RoadMap;
use App\Models\Sale;
use Illuminate\Support\Str;

/**
 * Los links de PDF con token y el "de quién es cada cosa" de las descargas (misión
 * pdf-de-venta-publico, 10/10/2026).
 *
 * Hasta esta misión las rutas de PDF y de exportación de `routes/web.php` eran públicas: el grupo
 * `web` no tiene ninguna capa de autenticación y los controladores hacen `find($id)` pelado, así que
 * en una base compartida (51 comercios en `u767360347_empresa`) cualquiera leía los comprobantes de
 * todos enumerando ids. Ahora el middleware `descarga.comercio` (DescargaDelComercio) sirve cada PDF
 * con la sesión del comercio dueño, o con un `?t=<token>` válido para ESE recurso.
 *
 * Este helper es el único lugar que:
 *   1. Emite y reutiliza los tokens (`token_para()`, `con_token()`), para los armadores de links que
 *      mandan el PDF fuera del sistema y para el endpoint `GET api/pdf-link/{tipo}/{id}` de la SPA.
 *   2. Valida un token (`token_valido()`), para el middleware.
 *   3. Resuelve el dueño de cada tipo de recurso (`duenios_del_recurso()`), para el middleware y
 *      para el endpoint: una sola regla de pertenencia, no una por consumidor.
 *
 * 🔴 CONTRATO CON tienda-api (misma base física): `pdf_links` y los valores de `TIPOS_CON_TOKEN`.
 * tienda-api inserta filas con estos mismos `tipo` para los PDF de cuenta corriente del comprador.
 *
 * PHP 7.4: sin match, sin nullsafe, sin argumentos nombrados.
 */
class PdfLinkHelper
{
    /**
     * Los únicos tipos que se comparten fuera del sistema y por eso admiten token. Exactos: son
     * parte del contrato con tienda-api.
     *
     * Los demás PDF (hoja de ruta, pedido a proveedor, orden de producción, etiquetas, libros de
     * IVA, exports...) solo los abre el usuario logueado desde la SPA: no tienen por qué tener un
     * link que ande sin sesión.
     */
    const TIPOS_CON_TOKEN = ['sale', 'budget', 'current_acount', 'credit_account', 'order'];

    /** Largo del token: `Str::random(48)` (letras y números), dentro del `string(64)` de la tabla. */
    const LARGO_DEL_TOKEN = 48;

    /** Tope de lo que se acepta como token al validar: más largo que la columna no puede existir. */
    const LARGO_MAXIMO_ACEPTADO = 64;

    /**
     * ¿Este tipo de recurso admite links con token?
     *
     * @param  mixed  $tipo
     * @return bool
     */
    public static function admite_token($tipo): bool
    {
        return is_string($tipo) && in_array($tipo, self::TIPOS_CON_TOKEN, true);
    }

    /**
     * El token del link de un recurso: el de la fila vigente si ya existe, o uno nuevo.
     *
     * Se REUTILIZA a propósito: el mismo comprobante se comparte varias veces (WhatsApp, mail,
     * recordatorio, asistente) y un token por envío llenaría la tabla sin dar nada a cambio. Si dos
     * pedidos simultáneos crean dos filas para el mismo recurso, las dos valen: no hay unique por
     * recurso porque no hace falta, y el reuso siguiente devuelve la más vieja.
     *
     * ⚠️ No verifica que `$owner_id` sea el dueño real del recurso: los armadores de links le pasan
     * el `user_id` del propio modelo y el endpoint de la SPA lo verifica antes de llamar. El
     * `user_id` de la fila es informativo (listar o revocar los links de un comercio); la validación
     * del token mira `tipo` + `model_id` + `token`, nunca `user_id`.
     *
     * @param  string  $tipo      Uno de TIPOS_CON_TOKEN.
     * @param  int     $model_id  Id del recurso.
     * @param  int     $owner_id  Dueño del recurso (el `user_id` del modelo).
     * @return string
     *
     * @throws \InvalidArgumentException  Si el tipo no admite token o el id no es un entero positivo.
     */
    public static function token_para($tipo, $model_id, $owner_id)
    {
        if (!self::admite_token($tipo)) {
            throw new \InvalidArgumentException('El tipo "'.(is_scalar($tipo) ? $tipo : gettype($tipo)).'" no admite links con token.');
        }

        if (!self::es_id($model_id)) {
            throw new \InvalidArgumentException('El id del recurso tiene que ser un entero positivo.');
        }

        $vigente = PdfLink::where('tipo', $tipo)
            ->where('model_id', (int) $model_id)
            ->whereNull('revoked_at')
            ->orderBy('id', 'ASC')
            ->first();

        if (!is_null($vigente)) {
            return (string) $vigente->token;
        }

        $link = PdfLink::create([
            'user_id'  => (int) $owner_id,
            'tipo'     => $tipo,
            'model_id' => (int) $model_id,
            'token'    => Str::random(self::LARGO_DEL_TOKEN),
        ]);

        return (string) $link->token;
    }

    /**
     * La misma URL con `t=<token>` sumado a lo que ya traiga en el query.
     *
     * Usa `?` si la URL todavía no tiene query y `&` si ya tiene (por ejemplo
     * `?pdf_column_profile_id=..&afip_ticket_id=..`). Si trae un `#fragmento`, el token va antes:
     * lo que sigue al `#` no llega al servidor.
     *
     * @param  string  $url       La URL del PDF tal como la arma cada consumidor.
     * @param  string  $tipo      Uno de TIPOS_CON_TOKEN.
     * @param  int     $model_id  Id del recurso.
     * @param  int     $owner_id  Dueño del recurso.
     * @return string
     *
     * @throws \InvalidArgumentException  Ver token_para().
     */
    public static function con_token($url, $tipo, $model_id, $owner_id)
    {
        $token = self::token_para($tipo, $model_id, $owner_id);

        $url = (string) $url;

        $fragmento = '';

        $posicion_del_fragmento = strpos($url, '#');

        if ($posicion_del_fragmento !== false) {
            $fragmento = substr($url, $posicion_del_fragmento);
            $url = substr($url, 0, $posicion_del_fragmento);
        }

        if (strpos($url, '?') === false) {
            $separador = '?';
        } else if (substr($url, -1) === '?' || substr($url, -1) === '&') {
            // Una URL que ya termina en separador no necesita otro.
            $separador = '';
        } else {
            $separador = '&';
        }

        return $url.$separador.'t='.rawurlencode($token).$fragmento;
    }

    /**
     * ¿Este token abre este recurso?
     *
     * Tiene que coincidir con una fila no revocada del MISMO `tipo` y `model_id`: el token de una
     * venta no abre otra venta ni el presupuesto con el mismo id.
     *
     * 🔴 La comparación final es con `hash_equals()` y no se confía solo en el WHERE: la columna usa
     * la collation de la base, que en MySQL suele ser case-insensitive, y un token con otras
     * mayúsculas no es el mismo token.
     *
     * @param  string  $tipo
     * @param  mixed   $model_id
     * @param  mixed   $token  El `t` del query tal cual llegó (puede venir como array o vacío).
     * @return bool
     */
    public static function token_valido($tipo, $model_id, $token): bool
    {
        if (!self::admite_token($tipo) || !self::es_id($model_id)) {
            return false;
        }

        if (!is_string($token) || $token === '' || strlen($token) > self::LARGO_MAXIMO_ACEPTADO) {
            return false;
        }

        $link = PdfLink::where('tipo', $tipo)
            ->where('model_id', (int) $model_id)
            ->where('token', $token)
            ->whereNull('revoked_at')
            ->first();

        return !is_null($link) && hash_equals((string) $link->token, $token);
    }

    /**
     * Los dueños (ids de `users`, siempre el DUEÑO, nunca un empleado) del recurso de una descarga.
     *
     * Para todos los tipos menos `articles` es un solo dueño. Para `articles` el "id" es la lista
     * `12-15-40` de las rutas de etiquetas y catálogos, y se devuelve el dueño de cada artículo que
     * existe (sin repetir): la regla de acceso exige que la sesión sea dueña de TODOS.
     *
     * Cómo se resuelve cada tipo (verificado contra las migraciones el 10/10/2026):
     *   - Con `user_id` propio: sale, budget, credit_account, order, provider_order, road_map,
     *     order_production, deposit_movement, resumen_caja, articles.
     *   - current_acount: su `user_id` (nullable desde 2021); si está en NULL, el de su cuenta
     *     corriente, y si tampoco, el del cliente o proveedor del movimiento.
     *   - afip_ticket (`afip_tickets` no tiene `user_id`): la venta del ticket, o la venta acreditada
     *     (`sale_nota_credito_id`), o el movimiento de la nota de crédito (`nota_credito_id`).
     *   - acopio_article_delivery (sin `user_id`): su venta (`sale_id`, no nullable).
     *   - apertura_caja (sin `user_id`): su caja (`caja_id` → `cajas.user_id`).
     * Los padres (la venta de un ticket, de un acopio) se leen con `withTrashed()` cuando el modelo lo
     * tiene: el dueño de una venta borrada sigue siendo el mismo. El recurso pedido, en cambio, se
     * busca con la consulta de siempre, igual que su controlador: si el controlador no lo encuentra,
     * acá tampoco.
     *
     * @param  string  $tipo      Uno de los tipos de arriba.
     * @param  mixed   $model_id  El parámetro de la ruta tal cual llegó.
     * @return int[]|null  null = el recurso no existe, el id no es válido o no tiene dueño
     *                     resoluble. Nunca un array vacío.
     */
    public static function duenios_del_recurso($tipo, $model_id)
    {
        if ($tipo === 'articles') {
            return self::duenios_de_articulos($model_id);
        }

        if (!self::es_id($model_id)) {
            return null;
        }

        $id = (int) $model_id;

        $duenio = null;

        if ($tipo === 'current_acount') {
            $duenio = self::duenio_de_movimiento($id);
        } else if ($tipo === 'afip_ticket') {
            $duenio = self::duenio_de_afip_ticket($id);
        } else if ($tipo === 'acopio_article_delivery') {
            $sale_id = AcopioArticleDelivery::where('id', $id)->value('sale_id');
            $duenio = is_null($sale_id) ? null : Sale::withTrashed()->where('id', $sale_id)->value('user_id');
        } else if ($tipo === 'apertura_caja') {
            $caja_id = AperturaCaja::where('id', $id)->value('caja_id');
            $duenio = is_null($caja_id) ? null : Caja::where('id', $caja_id)->value('user_id');
        } else {
            $clase = self::clase_con_user_id($tipo);

            if (is_null($clase)) {
                return null;
            }

            $duenio = $clase::where('id', $id)->value('user_id');
        }

        if (empty($duenio)) {
            return null;
        }

        return [(int) $duenio];
    }

    /**
     * ¿Es un id de base válido? Entero positivo, como número o como texto de solo dígitos.
     *
     * 🔴 Estricto a propósito: `Sale::find('12abc')` encuentra la venta 12 (MySQL convierte el texto
     * a número), así que un parámetro raro podía abrir un recurso que no es el que dice la URL. Acá
     * '12abc', '-3', '1.5' o 'undefined' no son ningún id y la descarga da 404.
     *
     * @param  mixed  $valor
     * @return bool
     */
    public static function es_id($valor): bool
    {
        if (is_int($valor)) {
            return $valor > 0;
        }

        return is_string($valor) && $valor !== '' && ctype_digit($valor) && (int) $valor > 0;
    }

    /**
     * El modelo de los tipos que tienen `user_id` propio, o null si el tipo no es uno de ellos.
     *
     * @param  string  $tipo
     * @return string|null  Nombre de la clase.
     */
    protected static function clase_con_user_id($tipo)
    {
        $clases = [
            'sale'             => Sale::class,
            'budget'           => Budget::class,
            'credit_account'   => CreditAccount::class,
            'order'            => Order::class,
            'provider_order'   => ProviderOrder::class,
            'road_map'         => RoadMap::class,
            'order_production' => OrderProduction::class,
            'deposit_movement' => DepositMovement::class,
            'resumen_caja'     => ResumenCaja::class,
        ];

        return isset($clases[$tipo]) ? $clases[$tipo] : null;
    }

    /**
     * Dueño de un movimiento de cuenta corriente (pago, nota de crédito, venta en la cuenta).
     *
     * `current_acounts.user_id` es nullable desde la migración de 2021: un movimiento viejo sin
     * dueño no puede quedar inaccesible para el comercio que lo tiene, así que se cae a la cuenta
     * corriente y, si tampoco, al cliente o proveedor del movimiento.
     *
     * @param  int  $id
     * @return int|null
     */
    protected static function duenio_de_movimiento($id)
    {
        $movimiento = CurrentAcount::where('id', $id)->first(['id', 'user_id', 'credit_account_id', 'client_id', 'provider_id']);

        if (is_null($movimiento)) {
            return null;
        }

        if (!empty($movimiento->user_id)) {
            return (int) $movimiento->user_id;
        }

        if (!empty($movimiento->credit_account_id)) {
            $duenio = CreditAccount::where('id', $movimiento->credit_account_id)->value('user_id');

            if (!empty($duenio)) {
                return (int) $duenio;
            }
        }

        if (!empty($movimiento->client_id)) {
            $duenio = Client::withTrashed()->where('id', $movimiento->client_id)->value('user_id');

            if (!empty($duenio)) {
                return (int) $duenio;
            }
        }

        if (!empty($movimiento->provider_id)) {
            $duenio = Provider::withTrashed()->where('id', $movimiento->provider_id)->value('user_id');

            if (!empty($duenio)) {
                return (int) $duenio;
            }
        }

        return null;
    }

    /**
     * Dueño de un comprobante de ARCA. `afip_tickets` no tiene `user_id`: es el de la venta del
     * ticket, el de la venta que acredita una nota de crédito, o el del movimiento de la nota de
     * crédito de cuenta corriente. Mismo orden que `AfipTicket::duenio_de_la_venta()`, más el último
     * caso que ese método no necesita.
     *
     * El ticket se busca sin `withTrashed()`, igual que `SaleController@afipTicketPdf`.
     *
     * @param  int  $id
     * @return int|null
     */
    protected static function duenio_de_afip_ticket($id)
    {
        $ticket = AfipTicket::where('id', $id)->first(['id', 'sale_id', 'sale_nota_credito_id', 'nota_credito_id']);

        if (is_null($ticket)) {
            return null;
        }

        foreach (['sale_id', 'sale_nota_credito_id'] as $columna) {

            if (!empty($ticket->{$columna})) {
                $duenio = Sale::withTrashed()->where('id', $ticket->{$columna})->value('user_id');

                if (!empty($duenio)) {
                    return (int) $duenio;
                }
            }
        }

        if (!empty($ticket->nota_credito_id)) {
            return self::duenio_de_movimiento((int) $ticket->nota_credito_id);
        }

        return null;
    }

    /**
     * Dueños de los artículos de una lista `12-15-40` (las rutas `article/*-pdf/{ids}`).
     *
     * Mismo separador que usan los PDF (`explode('-', $ids)`). Los segmentos vacíos se ignoran (un
     * `-` de más al final no rompe nada hoy y no tiene por qué empezar a romper); cualquier segmento
     * que no sea un id da null → 404.
     *
     * Un id que no existe (o está borrado) se ignora: no muestra nada de nadie, y los PDF ya lo
     * saltean o lo arrastran como antes. Lo que cuenta son los que SÍ existen: si ninguno existe, el
     * recurso no existe; si existen, se devuelven sus dueños sin repetir.
     *
     * @param  mixed  $ids
     * @return int[]|null
     */
    protected static function duenios_de_articulos($ids)
    {
        if (!is_string($ids) && !is_int($ids)) {
            return null;
        }

        $lista = [];

        foreach (explode('-', (string) $ids) as $segmento) {

            if ($segmento === '') {
                continue;
            }

            if (!self::es_id($segmento)) {
                return null;
            }

            $lista[(int) $segmento] = true;
        }

        if (!count($lista)) {
            return null;
        }

        $duenios = Article::whereIn('id', array_keys($lista))
            ->distinct()
            ->pluck('user_id')
            ->all();

        if (!count($duenios)) {
            return null;
        }

        $resultado = [];

        foreach ($duenios as $duenio) {
            // Un artículo sin dueño no lo puede reclamar ninguna sesión: queda como un 0 que no
            // coincide con nadie y la regla de "dueño de TODOS" falla.
            $resultado[(int) $duenio] = true;
        }

        return array_keys($resultado);
    }
}
