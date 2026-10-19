<?php

namespace App\Broadcasting;

use Illuminate\Support\Facades\DB;

/**
 * El espacio de nombres de los canales en vivo de ESTE sistema (misión pusher-canales-por-sistema,
 * 19/10/2026).
 *
 * ## El problema
 *
 * Toda la flota —demos incluidas— emite a UNA sola app de Pusher, y los canales llevan solo un id:
 * `global_notification.{owner}`, `added_model.{owner}`, `chat.user.{id}`, `App.Models.User.{id}`.
 * Dos sistemas con el mismo id de dueño se escuchan entre sí. El 19/10/2026 el aviso del escaneo de
 * una factura de demo3 (`USER_ID=2600`) le apareció a Ferretotal, cuyo dueño también es el 2600.
 *
 * Los privados tampoco protegían: cada sistema autoriza a SU usuario con SU API, así que si el id
 * coincide, la firma sale igual. Eso incluye `private-App.Models.User.{id}`, que es el candado de
 * sesión: un login en demo3 podía expulsar al dueño de Ferretotal.
 *
 * ## La solución
 *
 * Cada sistema le pega a todos sus canales el sufijo `@{espacio}`, donde el espacio es:
 *
 *     substr(hash_hmac('sha256', 'comerciocity-canales|' . APP_ENV . '|' . BASE, CLAVE), 0, 12)
 *
 * - `BASE` es la base por defecto de la API. Dentro de una base los ids son clave primaria, así que
 *   `(base, id)` es único aunque 51 comercios compartan `u767360347_empresa`. Dos frentes sobre la
 *   misma base (ferretotal / ferretotal2) dan el mismo espacio, y empresa-api y tienda-api del mismo
 *   cliente también, sin hablarse: leen la misma base.
 * - `APP_ENV` separa una copia local de una base real (el MySQL local tiene `ferretotal`) de la de
 *   producción aunque se llamen igual: los slots emiten a la MISMA app de Pusher que la flota.
 * - `CLAVE` es el secret de Pusher (o una constante si está vacío). Con el secret, el nombre del canal
 *   no se adivina desde afuera sabiendo el nombre de la base. Es consistente donde importa: dos
 *   procesos que comparten canales están en la misma app de Pusher, y una app tiene un solo secret.
 *
 * Sufijo y no prefijo: el `private-` de Pusher queda adelante sin tocarlo, y un listado de canales
 * por prefijo (`filter_by_prefix=global_notification.`) sigue dando un canal por comercio.
 *
 * ## 🔴 Este algoritmo es CONTRATO con tienda-api
 *
 * tienda-api tiene su propia copia de esta clase (PHP 8.4) y tiene que dar EXACTAMENTE el mismo
 * string para la misma base: los dos repos afirman los mismos vectores de prueba con el mismo literal
 * (`tests/Feature/Broadcast/EspacioDeCanalesTest.php`). Si se toca `calcular()`, `espacio()` o el
 * saneo del override acá, se toca igual allá, en el mismo release.
 *
 * ## Los canales de tienda (compatibilidad hacia atrás)
 *
 * Dentro del ERP no hay ventana: empresa-api y empresa-spa salen juntos. Pero los canales que cruzan
 * ERP ↔ tienda tienen una punta que se actualiza aparte. Para ellos hay un modo de transición
 * (`broadcasting.canales_viejos_de_tienda`, default true): se emite con y sin espacio, y se autoriza
 * también el nombre sin espacio. En estricto (false), solo con espacio. Ver `nombres_para_emitir()` y
 * `canal_para_autorizar()`.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, str_starts_with, str_ends_with, nullsafe (?->),
 * argumentos nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class EspacioDeCanales
{
    /**
     * Separador entre el nombre lógico del canal y el espacio. `@` es válido en un nombre de canal de
     * Pusher (el SDK valida contra `/\A#?[-a-zA-Z0-9_=@,.;]+\z/`) y no aparece en ningún nombre lógico
     * del repo, así que sirve para reconocer el sufijo sin ambigüedad.
     *
     * @var string
     */
    const SEPARADOR = '@';

    /**
     * Largo del espacio calculado: 12 caracteres hex = 48 bits. Sobra para la flota (cientos de bases)
     * y deja el nombre del canal corto (Pusher corta en 164 caracteres).
     *
     * @var int
     */
    const LARGO = 12;

    /**
     * Largo máximo del override manual (`broadcasting.espacio`).
     *
     * @var int
     */
    const LARGO_MAXIMO_DEL_OVERRIDE = 32;

    /**
     * Texto fijo al principio del string que se firma, y clave cuando el secret de Pusher está vacío.
     * Es parte del contrato con tienda-api: no se cambia.
     *
     * @var string
     */
    const CLAVE_POR_DEFECTO = 'comerciocity-canales';

    /**
     * Prefijos de tipo de canal de Pusher, en el orden en que hay que probarlos (el cifrado primero,
     * porque también empieza con `private-`). Mismo orden que
     * `UsePusherChannelConventions::normalizeChannelName()` de vendor.
     *
     * @var string[]
     */
    const PREFIJOS_DE_TIPO = ['private-encrypted-', 'private-', 'presence-'];

    /**
     * Comienzos de los nombres LÓGICOS (sin prefijo de tipo) de los canales que empresa-api emite y
     * que escucha alguien que no sale en el mismo release que este repo:
     *
     * - `tienda-respuestas.{owner}.{buyer}`: la respuesta del comercio, la escucha tienda-spa.
     * - `message.from_commerce.{buyer}`: idem, canal viejo del chat de la tienda.
     * - `tienda-mensajes.{owner}`: privado de Mensajes; lo emiten empresa-api Y tienda-api y lo escucha
     *   empresa-spa, así que con una tienda vieja el ERP nuevo tiene que seguir autorizándolo sin sufijo.
     *
     * 🔴 Si mañana empresa-api emite un canal nuevo que escucha la tienda, va acá. Si no, en una tienda
     * sin actualizar ese canal no llega nunca, sin ningún error a la vista.
     *
     * @var string[]
     */
    const PREFIJOS_DE_TIENDA = ['tienda-respuestas.', 'message.from_commerce.', 'tienda-mensajes.'];

    /**
     * Espacios ya calculados en este proceso, por la combinación de entradas (`base|entorno|clave`).
     *
     * Por la combinación y no un valor único a propósito: en los tests (y en un comando que cambiara de
     * conexión) la base o el secret cambian dentro del mismo proceso, y un cache de un solo valor
     * devolvería el espacio de la base anterior. Con la combinación como clave, cambiar cualquier
     * entrada recalcula solo.
     *
     * @var array<string, string>
     */
    protected static $espacios_calculados = [];

    /**
     * Calcula el espacio de un sistema a partir de sus tres entradas. Función pura: no lee config ni
     * base, y por eso es lo que afirman los vectores de prueba compartidos con tienda-api.
     *
     * @param string $base     Nombre de la base de datos por defecto de la API.
     * @param string $entorno  `APP_ENV` (production, local, testing...).
     * @param string|null $clave Secret de la app de Pusher. Vacío o null → `CLAVE_POR_DEFECTO`.
     * @return string 12 caracteres hex en minúscula.
     */
    public static function calcular($base, $entorno, $clave)
    {
        /** Clave del hmac: el secret si hay, la constante si no. */
        $clave_efectiva = (string) $clave;

        if ($clave_efectiva === '') {
            $clave_efectiva = self::CLAVE_POR_DEFECTO;
        }

        /** String que se firma. El orden entorno → base es contrato con tienda-api. */
        $texto = self::CLAVE_POR_DEFECTO . '|' . (string) $entorno . '|' . (string) $base;

        return substr(hash_hmac('sha256', $texto, $clave_efectiva), 0, self::LARGO);
    }

    /**
     * El espacio de ESTE sistema.
     *
     * Si `broadcasting.espacio` (env `BROADCAST_ESPACIO`) trae algo usable, manda ese valor: es para
     * tests y para alinear a mano las dos mitades de un mismo sistema si algún día no coinciden. Si
     * no, se calcula con la base por defecto, `app.env` y el secret de Pusher.
     *
     * `DB::connection()->getDatabaseName()` no abre la conexión: devuelve el nombre de la config de la
     * conexión ya construida (el PDO de Laravel 8 es perezoso). O sea que esto no cuesta una query.
     *
     * @return string
     */
    public static function espacio()
    {
        /** Override manual ya saneado ('' si no hay o si no quedó nada usable). */
        $override = self::sanear_override(config('broadcasting.espacio'));

        if ($override !== '') {
            return $override;
        }

        /** Base por defecto de la API: la que separa un sistema de otro. */
        $base = (string) DB::connection()->getDatabaseName();

        /** Entorno: separa una copia local de la base de producción homónima. */
        $entorno = (string) config('app.env');

        /** Secret de Pusher: hace que el nombre no se pueda adivinar desde afuera. */
        $clave = (string) config('broadcasting.connections.pusher.secret');

        /** Clave del cache: la combinación de entradas, ver `$espacios_calculados`. */
        $clave_de_cache = $base . '|' . $entorno . '|' . $clave;

        if (!isset(self::$espacios_calculados[$clave_de_cache])) {
            self::$espacios_calculados[$clave_de_cache] = self::calcular($base, $entorno, $clave);
        }

        return self::$espacios_calculados[$clave_de_cache];
    }

    /**
     * Sanea el override manual: minúsculas, solo `[a-z0-9]` y hasta 32 caracteres.
     *
     * El saneo existe porque el valor termina adentro de un nombre de canal de Pusher, que rechaza
     * muchos caracteres, y porque el SPA reconoce el sufijo comparando strings: un espacio con
     * mayúsculas o un `@` adentro rompería esa comparación. Se pasa a minúsculas ANTES de filtrar para
     * que `Demo3` dé `demo3` y no `emo3`.
     *
     * 🔴 Contrato con tienda-api: el mismo saneo, en el mismo orden.
     *
     * @param mixed $valor Lo que vino de `config('broadcasting.espacio')`.
     * @return string El override usable, o '' si no hay.
     */
    public static function sanear_override($valor)
    {
        if (!is_string($valor) && !is_numeric($valor)) {
            return '';
        }

        /** Solo letras minúsculas y dígitos. */
        $saneado = preg_replace('/[^a-z0-9]/', '', strtolower((string) $valor));

        return substr((string) $saneado, 0, self::LARGO_MAXIMO_DEL_OVERRIDE);
    }

    /**
     * Le agrega el sufijo `@{espacio}` a un canal, salvo que ya lo tenga.
     *
     * El "salvo que ya lo tenga" no es paranoia: el mismo nombre puede pasar dos veces por acá (un
     * canal que ya salió de `nombres_para_emitir()` y vuelve a entrar), y un doble sufijo es un canal
     * que no escucha nadie.
     *
     * @param string $canal Nombre lógico, con o sin prefijo de tipo (`private-chat.user.5`).
     * @return string Nombre físico (`private-chat.user.5@26d5013a6846`).
     */
    public static function con_espacio($canal)
    {
        $canal = (string) $canal;

        if ($canal === '') {
            return $canal;
        }

        /** Sufijo propio de este sistema. */
        $sufijo = self::SEPARADOR . self::espacio();

        if (self::termina_con($canal, $sufijo)) {
            return $canal;
        }

        return $canal . $sufijo;
    }

    /**
     * ¿Está prendido el modo de transición de los canales de tienda?
     *
     * Misma función que en tienda-api, para que las dos mitades lean igual el mismo valor: solo un
     * falso EXPLÍCITO es estricto (un bool false, o el texto 'false'/'0'/'off'/'no'). Si la clave
     * falta en la config (un `config:cache` hecho antes de este release) el valor es null, y lo
     * seguro ahí es la transición, que es exactamente el comportamiento de hoy para los canales que
     * cruzan con la tienda; lo mismo con un vacío o un valor mal tipeado.
     *
     * @return bool true = transición (con y sin espacio), false = estricto (solo con espacio).
     */
    public static function canales_viejos_de_tienda()
    {
        /** Valor crudo de la config: normalmente un bool (lo normaliza config/broadcasting.php). */
        $valor = config('broadcasting.canales_viejos_de_tienda', true);

        if (is_bool($valor)) {
            return $valor;
        }

        if ($valor === null || $valor === '') {
            return true;
        }

        return filter_var($valor, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
    }

    /**
     * ¿Es un canal que cruza con la tienda (ver `PREFIJOS_DE_TIENDA`)?
     *
     * Se mira el nombre lógico: se saca primero el prefijo de tipo (`private-encrypted-`, `private-`
     * o `presence-`), porque `tienda-mensajes.` viaja como `private-tienda-mensajes.{owner}`.
     *
     * @param string $canal Nombre del canal, con o sin prefijo de tipo y con o sin sufijo.
     * @return bool
     */
    public static function es_canal_de_tienda($canal)
    {
        /** Nombre sin el prefijo de tipo. */
        $logico = self::sin_prefijo_de_tipo((string) $canal);

        foreach (self::PREFIJOS_DE_TIENDA as $prefijo) {
            if (strpos($logico, $prefijo) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Los nombres por los que hay que emitir un canal.
     *
     * Siempre el físico (con espacio). Además, si el modo de transición está prendido y el canal es de
     * tienda, el lógico (sin espacio), para que una tienda sin actualizar lo siga recibiendo. Los dos
     * van en el MISMO `trigger` de Pusher: cero costo extra, y un cliente suscrito a uno solo de los dos
     * nombres recibe el evento una sola vez.
     *
     * ⚠️ Pusher no deja emitir a más de un canal cifrado (`private-encrypted-`) en el mismo `trigger`.
     * Hoy ningún canal de tienda es cifrado; si alguno pasara a serlo, hay que partir el envío.
     *
     * @param string $canal Nombre lógico del canal.
     * @return string[] Físico primero; el lógico después, si corresponde. Sin repetidos.
     */
    public static function nombres_para_emitir($canal)
    {
        $canal = (string) $canal;

        /** Nombre físico: el que escucha un SPA nuevo. */
        $fisico = self::con_espacio($canal);

        $nombres = [$fisico];

        if ($canal !== $fisico && self::canales_viejos_de_tienda() && self::es_canal_de_tienda($canal)) {
            $nombres[] = $canal;
        }

        return $nombres;
    }

    /**
     * Traduce el `channel_name` que pide autorización en `POST /broadcasting/auth` al nombre lógico
     * contra el que se corren los callbacks de `routes/channels.php`, o dice que no se autoriza.
     *
     * - `private-X@{mi espacio}` → `private-X`. Es el caso normal de un SPA nuevo.
     * - `private-X` sin sufijo, en transición y de tienda → `private-X` (una tienda o un SPA viejo
     *   escuchando `private-tienda-mensajes.{owner}`, con el mismo callback de siempre).
     * - Todo lo demás → null, que es un 403 sin llamar a ningún callback:
     *   - `@{otro espacio}`: es el canal de OTRO sistema. 🔴 Este es el agujero que cierra la misión:
     *     sin este corte, `chat.user.5@otro` matchea el patrón `chat.user.{auth_user_id}` (Laravel
     *     compara con `Str::is` y un `*`), el parámetro llega como `'5@otro'`, `(int) '5@otro'` da 5 y
     *     el callback autoriza.
     *   - sin sufijo fuera de tienda (o en estricto): el canal sin espacio ya no lo emite nadie de este
     *     sistema, y autorizarlo sería dejar escuchar lo que emita otro sistema con el mismo id.
     *   - un nombre lógico que todavía tiene un `@` adentro (`private-X@otro@mio`): ningún patrón de
     *     `routes/channels.php` lleva `@`, así que es un nombre armado a mano.
     *   - vacío.
     *
     * @param string|null $pedido El `channel_name` tal cual vino en el request.
     * @return string|null Nombre lógico CON su prefijo de tipo, o null si no se autoriza.
     */
    public static function canal_para_autorizar($pedido)
    {
        $pedido = (string) $pedido;

        if ($pedido === '') {
            return null;
        }

        /** Sufijo propio de este sistema. */
        $sufijo = self::SEPARADOR . self::espacio();

        if (self::termina_con($pedido, $sufijo)) {
            /** El pedido sin el sufijo propio. */
            $logico = substr($pedido, 0, strlen($pedido) - strlen($sufijo));

            if ($logico === '' || self::sin_prefijo_de_tipo($logico) === '') {
                return null;
            }

            if (strpos($logico, self::SEPARADOR) !== false) {
                return null;
            }

            return $logico;
        }

        if (strpos($pedido, self::SEPARADOR) !== false) {
            // Sufijo de otro sistema (o un `@` que no es nuestro): no es un canal de este sistema.
            return null;
        }

        if (self::canales_viejos_de_tienda() && self::es_canal_de_tienda($pedido)) {
            return $pedido;
        }

        return null;
    }

    /**
     * Lo que viaja al SPA en `User::$appends` (`canales_en_vivo`): con esto el SPA arma el nombre
     * físico de cada canal que escucha. Las claves son contrato con empresa-spa y con tienda-api
     * (`Buyer::$appends`): `espacio` y `canales_viejos_de_tienda`, con esos nombres exactos.
     *
     * No lleva nada secreto: el espacio aparece igual en el nombre de cada canal que el SPA escucha, y
     * del espacio no se puede volver al secret.
     *
     * @return array{espacio: string, canales_viejos_de_tienda: bool}
     */
    public static function para_el_spa()
    {
        return [
            'espacio'                  => self::espacio(),
            'canales_viejos_de_tienda' => self::canales_viejos_de_tienda(),
        ];
    }

    /**
     * Saca el prefijo de tipo de Pusher (`private-encrypted-`, `private-` o `presence-`) si lo tiene.
     *
     * @param string $canal
     * @return string
     */
    protected static function sin_prefijo_de_tipo($canal)
    {
        foreach (self::PREFIJOS_DE_TIPO as $prefijo) {
            if (strpos($canal, $prefijo) === 0) {
                return (string) substr($canal, strlen($prefijo));
            }
        }

        return $canal;
    }

    /**
     * `str_ends_with` de PHP 8, para PHP 7.4.
     *
     * @param string $texto
     * @param string $final
     * @return bool
     */
    protected static function termina_con($texto, $final)
    {
        /** Largo del final buscado. */
        $largo = strlen($final);

        if ($largo === 0) {
            return true;
        }

        return strlen($texto) >= $largo && substr($texto, -$largo) === $final;
    }
}
