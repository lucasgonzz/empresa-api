<?php

namespace Tests\Fakes;

/**
 * La cuarta capa del freno de internet de los tests: los pedidos HTTP que salen por los STREAMS de PHP.
 *
 * `HttpFactorySinSalida` frena lo que pasa por la fachada `Http`, y el proxy muerto de `phpunit.xml` frena
 * a curl y a Guzzle. Pero hay código que le habla a internet sin pasar por ninguna de las dos cosas: el
 * wrapper `http://` de PHP (`file_get_contents($url)`, `get_headers($url)`, `getimagesize($url)`,
 * `copy($url, …)`, `fopen($url)`) ignora las variables de proxy y no pasa por ninguna fábrica. En `app/` hay
 * varios: `PdfHelper` mide con `getimagesize($image_url)`, `GeneralHelper` pregunta con
 * `get_headers($filePath)`, `HelperController` baja una imagen con `file_get_contents($url_cloudinary)`, el
 * reporte de errores a GitHub sube con `file_get_contents($url, false, $context)`.
 *
 * Qué hace: reemplaza los wrappers `http` y `https` por esta clase. Cualquier intento de abrir una URL
 * queda anotado en la lista de `HttpFactorySinSalida` (para que `Tests\TestCase` haga FALLAR al test que lo
 * intentó, aunque el código bajo prueba se haya tragado la excepción) y lanza `PedidoHttpRealBloqueado`.
 * No distingue loopback: los tests no le hablan a ningún servidor, tampoco al del slot.
 *
 * Qué NO hace:
 *  - `is_file()`, `file_exists()`, `is_dir()`, `filesize()` sobre una URL dan `false` SIN anotar: el wrapper
 *    nativo no implementa `stat()` y esas funciones nunca tocaron la red.
 *  - El cargador de libxml (`simplexml_load_file($url)`, `DOMDocument::load($url)`, el WSDL por URL de un
 *    `SoapClient`) pregunta primero por `stat()`: con la barrera falla igual y sin red, pero NO queda anotado.
 *  - No frena sockets crudos (`fsockopen`, `stream_socket_client`) ni los clientes que arman su propio
 *    transporte (SOAP, SSH). Esos dependen de datos que el test mismo arma; ver el docblock de
 *    `Tests\TestCase::cerrar_la_salida_a_internet()`.
 *
 * 🔴 Se vuelve a instalar en CADA `setUp()` (`instalar()` desregistra lo que haya y registra esta clase). No hay
 * bandera de "ya instalado": hay tests que tocan los wrappers por su cuenta —`ComprobantesConDisenoDePagina::
 * sin_red_https()` registra el suyo y al terminar llamaba a `stream_wrapper_restore('https')`, que devuelve el
 * wrapper NATIVO— y con una bandera la barrera quedaba apagada para el resto del proceso de PHPUnit (medido en la
 * revisión independiente del 6/10/2026: 343 archivos de `Feature` corriendo con `https` saliendo de verdad).
 */
class StreamHttpSinSalida
{
    /**
     * Contexto del stream. PHP lo asigna solo cuando el wrapper se abre con un contexto; la propiedad tiene
     * que existir y ser pública para que el wrapper de usuario sea válido.
     *
     * @var resource|null
     */
    public $context;

    /**
     * Reemplaza los wrappers `http` y `https` (los nativos, o el que haya dejado registrado un test) por esta
     * clase. Se puede llamar las veces que haga falta.
     *
     * @return void
     */
    public static function instalar(): void
    {
        foreach (['http', 'https'] as $esquema) {
            if (in_array($esquema, stream_get_wrappers(), true)) {
                stream_wrapper_unregister($esquema);
            }

            // STREAM_IS_URL: sin esa bandera PHP rechaza el wrapper en `get_headers()` ("This function may only be used
            // against URLs") antes de llamarlo, y el intento no quedaría anotado.
            stream_wrapper_register($esquema, static::class, STREAM_IS_URL);
        }
    }

    /**
     * Abrir una URL (`fopen`, `file_get_contents`, `get_headers`, `getimagesize`, `copy`…): se frena.
     *
     * @param string      $ruta        La URL.
     * @param string      $modo        Modo de apertura.
     * @param int         $opciones    Opciones del stream.
     * @param string|null $ruta_abierta Ruta realmente abierta (no se usa).
     *
     * @return bool Nunca devuelve: lanza.
     */
    public function stream_open($ruta, $modo, $opciones, &$ruta_abierta)
    {
        return $this->frenar($ruta);
    }

    /**
     * `file_exists($url)`, `is_file($url)`, `is_dir($url)`, `filesize($url)`…: el wrapper `http` NATIVO de PHP no implementa `stat()`, así
     * que esas funciones dan `false` sin tocar la red. Acá pasa lo mismo: se devuelve `false` y NO se anota nada (un pedido que PHP nunca hace
     * no es una salida a internet; anotarlo daría falsos positivos, como `is_file($image_url)` de `GeneralHelper::storage_public_path_from_image_url()`).
     *
     * @param string $ruta     La URL.
     * @param int    $banderas Banderas de `stat`.
     *
     * @return false Siempre.
     */
    public function url_stat($ruta, $banderas)
    {
        return false;
    }

    /**
     * Anota el pedido y lanza.
     *
     * @param string $ruta La URL que se intentó abrir.
     *
     * @return bool Nunca devuelve: lanza.
     *
     * @throws PedidoHttpRealBloqueado Siempre.
     */
    protected function frenar($ruta)
    {
        HttpFactorySinSalida::anotar('STREAM ' . $ruta);

        throw new PedidoHttpRealBloqueado(
            'Pedido HTTP por stream bloqueado en un test: ' . $ruta . '. Un test de empresa-api no sale a internet '
            . '(ni con file_get_contents, get_headers, getimagesize o copy). Falsealo o apuntalo a un archivo local.'
        );
    }
}
