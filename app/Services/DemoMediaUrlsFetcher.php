<?php

namespace App\Services;

use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use App\Models\DemoTrackingConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pide al admin el mapa fresco de URLs de los videos de la demo (mision demo-panel-recorrido).
 *
 * El problema que viene a resolver, medido sobre la base de Lucas el 17/8/2026: las URLs
 * viajaban a la instancia UNICAMENTE dentro del payload del demo setup y quedaban congeladas
 * en `demo_tracking_config.media_urls`. El setup corrio a las 15:02 y los links se cargaron en
 * el admin a las 15:51, asi que la instancia se quedo con el mapa viejo ({"intro": "..."}) y el
 * panel le decia al lead "Este video todavia no esta disponible" en los 28 clips.
 *
 * 🔴 REGLA QUE MANDA SOBRE TODO LO DEMAS: la respuesta del admin NUNCA puede romperle el panel
 * al lead. Red caida, timeout, 401, 404, JSON invalido, una forma que no sea un mapa de strings
 * -- todo cae al `media_urls` guardado y sigue. Es la misma regla que ya rige para el reporte de
 * eventos: el registro es la metrica, la demo es el producto. Por eso el cuerpo entero de
 * frescas() va adentro de un try/catch que no deja pasar ninguna Throwable.
 */
class DemoMediaUrlsFetcher
{
    /**
     * Timeout corto y SIN reintentos: esto corre adentro del request que le dibuja el panel al
     * lead, no en un job de fondo. Un admin lento no puede convertirse en una pantalla en
     * blanco; a los 3 segundos se usa lo guardado, que es exactamente el comportamiento de hoy.
     */
    const TIMEOUT_SEGUNDOS = 3;

    /** Ultimo segmento del canal de eventos que ya existe. */
    const SEGMENTO_EVENTOS = 'demo-eventos';

    /** Ultimo segmento del endpoint hermano de lectura. */
    const SEGMENTO_MEDIA_URLS = 'demo-media-urls';

    /**
     * Trae el mapa fresco del admin y, si vino bien, refresca el guardado.
     *
     * @param \App\Models\DemoTrackingConfig|null $config Fila del canal, tal como la devuelve
     *                                                    DemoTrackingConfigHelper::actual().
     * @return array<string, string>|null Mapa slot_id => url, o null si CUALQUIER cosa fallo.
     */
    public static function frescas($config)
    {
        /**
         * Fuera del try, igual que en DemoEventosPushHelper: el catch lo usa para enmascarar el
         * mensaje sin volver a preguntarle a la base cual era el token. Si lo que tiro FUE la
         * base, resolverlo de nuevo devolveria vacio y el mensaje saldria en claro, que es justo
         * cuando mas importa que no.
         *
         * @var string
         */
        $token = '';

        try {
            if (is_null($config)) {
                return null;
            }

            $token = trim((string) $config->eventos_token);

            /** Sin credencial no hay a quien preguntarle: el admin responde 401 y listo. */
            if ($token === '') {
                return null;
            }

            $url = self::url_del_endpoint($config->eventos_url);

            if (is_null($url)) {
                /**
                 * Una `eventos_url` con otra forma es un setup mas viejo (o cambiado del lado del
                 * admin). Se avisa una linea y se sigue con lo guardado: un catch mudo alrededor
                 * de una lectura produce un resultado plausible que nadie revisa nunca
                 * (APRENDER_NO_PARCHEAR.md).
                 */
                Log::info('DemoMediaUrlsFetcher: no se pudo derivar el endpoint de media urls de la eventos_url guardada. Se usa el mapa guardado.');

                return null;
            }

            $respuesta = Http::withHeaders([
                    'Accept' => 'application/json',
                    /** El token ES el identificador del lead del otro lado. No se loguea nunca. */
                    'X-Demo-Eventos-Key' => $token,
                ])
                ->timeout(self::TIMEOUT_SEGUNDOS)
                ->get($url);

            if (!$respuesta->successful()) {
                /**
                 * 404/405 es el admin viejo sin el endpoint desplegado, y 401 es el lead de la
                 * dinamica anterior. Los dos son casos PREVISTOS por el contrato, no errores:
                 * se cae al mapa guardado, que es el comportamiento que la instancia ya tenia.
                 */
                Log::info('DemoMediaUrlsFetcher: el admin respondio ' . $respuesta->status() . ' al pedido de media urls. Se usa el mapa guardado.');

                return null;
            }

            $cuerpo = $respuesta->json();

            $mapa = self::normalizar(
                is_array($cuerpo) && isset($cuerpo['media_urls']) ? $cuerpo['media_urls'] : null
            );

            if (is_null($mapa)) {
                Log::warning('DemoMediaUrlsFetcher: el admin respondio 200 pero sin un `media_urls` usable. Se usa el mapa guardado.');

                return null;
            }

            self::refrescar_guardado($config, $mapa);

            return $mapa;
        } catch (\Throwable $e) {
            /**
             * Red caida, DNS, timeout, TLS, JSON que hizo tirar al decodificador: todo termina
             * aca y todo se resuelve igual, con el mapa guardado. El mensaje pasa por el
             * enmascarado porque el de cURL trae la URL completa del request, y esa URL se deriva
             * de `eventos_url`, que viene verbatim del payload del admin: si algun dia emitiera
             * la key en la query string, este log la publicaria.
             */
            Log::warning('DemoMediaUrlsFetcher: no se pudieron traer las media urls del admin: ' . DemoTrackingConfigHelper::ocultar_token($e->getMessage(), $token));

            return null;
        }
    }

    /**
     * Deriva el endpoint de lectura reemplazando el ULTIMO segmento `demo-eventos` por
     * `demo-media-urls`.
     *
     * 🔴 POR QUE SE DERIVA Y NO SE RECIBE EN EL PAYLOAD DEL SETUP.
     *
     * Es la pregunta que alguien va a querer "mejorar" mandando una `media_urls_url` nueva desde
     * el admin. No se puede: una URL nueva SOLO llegaria en el proximo setup, y quedarse esperando
     * al proximo setup es EXACTAMENTE el problema que esta mision viene a resolver. Derivandola,
     * la demo que Lucas ya tiene armada empieza a funcionar sin volver a armarla. El valor real
     * hoy es `http://localhost/empresa/admin-api/public/api/demo-eventos`, asi que el reemplazo
     * del ultimo segmento es directo.
     *
     * Se busca el ultimo segmento y no se hace un str_replace sobre la URL entera: un admin
     * instalado en una carpeta que se llamara `demo-eventos` (o un host con ese nombre) quedaria
     * con la URL rota y nadie lo entenderia mirando el log.
     *
     * @param string|null $eventos_url URL del canal de eventos, tal como la guardo el setup.
     * @return string|null La URL derivada, o null si la guardada no tiene ese segmento.
     */
    public static function url_del_endpoint($eventos_url)
    {
        $url = trim((string) $eventos_url);

        if ($url === '') {
            return null;
        }

        /** La query y el fragmento no son camino: se apartan y se vuelven a pegar tal cual. */
        $corte = strcspn($url, '?#');
        $camino = substr($url, 0, $corte);
        $resto = substr($url, $corte);

        $segmentos = explode('/', $camino);

        $indice = null;

        for ($i = count($segmentos) - 1; $i >= 0; $i--) {
            if ($segmentos[$i] === self::SEGMENTO_EVENTOS) {
                $indice = $i;
                break;
            }
        }

        if (is_null($indice)) {
            return null;
        }

        $segmentos[$indice] = self::SEGMENTO_MEDIA_URLS;

        return implode('/', $segmentos) . $resto;
    }

    /**
     * Valida que lo que vino sea un mapa de slot_id => url usable.
     *
     * No se confia en la forma: el cuerpo es de otro sistema y el panel resuelve la URL de cada
     * clip indexando este array. Una lista (`["http://a"]`) se rechaza entera aunque sus valores
     * sean strings: no es un mapa, y aceptarla dejaria claves "0", "1" que no son ningun clip.
     *
     * @param mixed $crudo Lo que vino en `media_urls`.
     * @return array<string, string>|null El mapa limpio, o null si no hay nada usable.
     */
    private static function normalizar($crudo)
    {
        if (!is_array($crudo) || count($crudo) === 0) {
            return null;
        }

        /** Una lista JSON decodifica a claves 0..n-1. Eso no es el mapa que pide el contrato. */
        if (array_keys($crudo) === range(0, count($crudo) - 1)) {
            return null;
        }

        $mapa = [];

        foreach ($crudo as $slot_id => $url) {
            /** Los valores anidados (arrays, objetos, null, numeros) no son una URL. */
            if (!is_string($url)) {
                continue;
            }

            /**
             * json_decode con assoc convierte las claves que son numeros enteros a int
             * ({"1": "..."} llega como 1). Los ids del catalogo son "1.1", "2.1" y quedan string,
             * pero se castea igual para que el dia que exista un id "3" siga matcheando.
             */
            $slot = trim((string) $slot_id);
            $limpia = trim($url);

            if ($slot === '' || $limpia === '') {
                continue;
            }

            $mapa[$slot] = $limpia;
        }

        return count($mapa) === 0 ? null : $mapa;
    }

    /**
     * Deja el mapa fresco guardado en `demo_tracking_config.media_urls`.
     *
     * Sirve de cache para la proxima vez que el admin no conteste: sin esto, un admin caido
     * devolveria al lead al mapa del setup, que es el viejo. El admin es la fuente de verdad de
     * este dato, asi que se sobrescribe entero y no se mergea.
     *
     * 🔴 Tiene su PROPIO try/catch, y no alcanza con el de frescas(): si la escritura falla, el
     * mapa que ya tenemos en la mano sigue siendo bueno y el lead tiene que verlo igual. Caer al
     * catch de arriba lo tiraria a la basura por no haber podido cachearlo.
     *
     * 🔴 Y se escribe con un update del query builder A PROPOSITO, en vez de asignarle el atributo
     * a $config y guardarlo. La fila que llega aca es la MISMA instancia que cachea
     * DemoTrackingConfigHelper::actual() y que el controller tiene en la mano: pisarle el atributo
     * en memoria haria que el panel saliera con las URLs frescas aunque el controller ignorara el
     * valor que devuelve este servicio. O sea, el efecto de borde taparia el bug. Se comprobo
     * mutando: con la asignacion en memoria, romper el controller dejaba los tests en verde.
     *
     * @param \App\Models\DemoTrackingConfig $config
     * @param array<string, string> $mapa
     * @return void
     */
    private static function refrescar_guardado($config, array $mapa)
    {
        try {
            $json = json_encode($mapa);

            /** Contenido no serializable: se devuelve el mapa igual, pero no se guarda basura. */
            if ($json === false) {
                Log::warning('DemoMediaUrlsFetcher: el mapa fresco no se pudo serializar, no se guarda.');

                return;
            }

            /** El update del builder no aplica el cast `array`, asi que el json va armado a mano. */
            DemoTrackingConfig::query()->where('id', $config->id)->update(['media_urls' => $json]);
        } catch (\Throwable $e) {
            Log::warning('DemoMediaUrlsFetcher: llegaron las media urls frescas pero no se pudieron guardar: ' . $e->getMessage());
        }
    }
}
