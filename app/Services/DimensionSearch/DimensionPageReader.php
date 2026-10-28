<?php

namespace App\Services\DimensionSearch;

use App\Services\BusquedaPorCodigoDeBarrasService;
use App\Services\ImageAssignment\ImageServiceCallLogger;
use App\Services\ImageSearch\ImageSearchProviderFactory;
use App\Services\ProductFacts\ProductFactsException;
use App\Services\ProductFacts\SerperUsageService;
use App\Services\ProductFacts\TopeSerperDemo;
use App\Services\ProductFacts\TopeSerperDemoAgotado;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Descarga páginas web con la guarda anti-SSRF de la búsqueda por código de barras (misión
 * medidas-masivas-catalogo, 27/10/2026).
 *
 * BusquedaPorCodigoDeBarrasService::descargar() es el descargador completo: DNS público, IP fijada con
 * CURLOPT_RESOLVE, redirecciones validadas salto por salto, tope de bytes y solo `text/html`. Es
 * protected; ProductFactsResearchService lo reusa heredando. Acá se hace lo mismo y se expone UNA
 * operación pública, `leer()`, sin tocar la clase original.
 *
 * Se resuelve por el contenedor (`app()->makeWith(DimensionPageReader::class, ['owner' => $owner])`)
 * para que los tests lo reemplacen por uno con DNS de prueba: nada sale a internet.
 *
 * RESPALDO POR EL SCRAPE DE SERPER (misión medidas-sin-datos, 28/10/2026): hay páginas que la descarga directa
 * no baja (403 de Cloudflare / captcha, timeouts) y PDF (que la descarga directa descarta). `scrapear()` le pide
 * a `https://scrape.serper.dev` el TEXTO PLANO de esa URL con la clave de Serper del dueño (o la del servidor).
 * Cuesta 2 créditos normalmente y hasta 10 en sitios difíciles, así que NO decide solo cuándo usarse: lo
 * decide el motor (topes por artículo, dominios inútiles, páginas sin el código). Acá solo se hace la llamada,
 * se registra el consumo en `serper_usage_events` (proceso `medidas_scrape`, endpoint `scrape`) y se respeta
 * el tope de búsquedas de las demos. La clave nunca va a un log ni a un mensaje.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class DimensionPageReader extends BusquedaPorCodigoDeBarrasService
{
    /** Segundos máximos para bajar UNA página (se recorta a lo que quede del presupuesto). */
    const SEGUNDOS_POR_PAGINA = 10;

    /** Presupuesto de tiempo de TODAS las páginas de un artículo, en segundos. */
    const PRESUPUESTO_POR_ARTICULO = 90;

    /** URL del scrape de Serper (texto plano de cualquier página o PDF). */
    const URL_SCRAPE = 'https://scrape.serper.dev';

    /**
     * Proceso y endpoint con los que se registra el consumo del scrape en serper_usage_events.
     *
     * 🔴 El endpoint es `search` y NO `scrape`: el admin valida `endpoint` con `in:search,images`
     * (SerperUsageController::store) y UN solo evento con otro valor hace que responda 422 y trabe la
     * sincronización de todo el consumo Serper del cliente (reintenta siempre el mismo lote). El scrape se
     * distingue por el proceso (`medidas_scrape`).
     */
    const PROCESO_SCRAPE  = 'medidas_scrape';
    const ENDPOINT_SCRAPE = 'search';

    /** Segundos de espera del scrape (un PDF grande o un sitio difícil tarda más que una búsqueda). */
    const TIMEOUT_SCRAPE = 30;

    /** Por qué falló la última descarga directa (ver motivo_del_fallo()). */
    const FALLO_BLOQUEADA     = 'bloqueada';
    const FALLO_SIN_RESPUESTA = 'sin_respuesta';
    const FALLO_PDF           = 'pdf';
    const FALLO_NO_EXISTE     = 'no_existe';
    const FALLO_NO_PERMITIDO  = 'no_permitido';
    const FALLO_SIN_TIEMPO    = 'sin_presupuesto';
    const FALLO_OTRO          = 'otro';

    /** @var string|null La cabecera Content-Type de la última página descargada. */
    protected $content_type = null;

    /** @var int|null El estado HTTP de la última respuesta que se descartó (null = no hubo respuesta). */
    protected $ultimo_estado = null;

    /** @var string|null Por qué falló la última descarga (uno de los FALLO_*); null si no falló. */
    protected $fallo = null;

    /**
     * @param  \App\Models\User|null $owner
     */
    public function __construct($owner)
    {
        parent::__construct($owner);

        $this->reiniciar_presupuesto();
    }

    /**
     * Vuelve a empezar el reloj: un lector sirve para varios artículos seguidos y cada uno tiene su
     * propio presupuesto.
     *
     * @param  int|null $segundos  null = PRESUPUESTO_POR_ARTICULO.
     * @return $this
     */
    public function reiniciar_presupuesto($segundos = null)
    {
        $this->vence_en   = microtime(true) + (is_null($segundos) ? self::PRESUPUESTO_POR_ARTICULO : (int) $segundos);
        $this->descartes  = [];

        return $this;
    }

    /**
     * El HTML de una página, o null si no se pudo (destino no permitido, no es HTML, pesa de más,
     * error, sin presupuesto de tiempo).
     *
     * @param  string $url
     * @return string|null
     */
    public function leer($url)
    {
        $this->content_type  = null;
        $this->ultimo_estado = null;
        $this->fallo         = null;

        $descartes_antes = count($this->descartes);

        $html = $this->descargar((string) $url, 'pagina', self::SEGUNDOS_POR_PAGINA);

        if (is_null($html)) {
            // Se anota POR QUÉ falló: el motor decide con eso si pide el respaldo.
            $this->fallo = $this->clasificar_el_fallo(array_slice($this->descartes, $descartes_antes));

            return null;
        }

        // Siempre UTF-8: una página en ISO-8859-1 / Windows-1252 no tiene que quedar ilegible.
        return DimensionPageText::a_utf8($html, $this->content_type);
    }

    /**
     * Antes de leer el cuerpo, anota la cabecera `Content-Type` de la respuesta final (la que trae el
     * juego de caracteres), y sigue con la lectura de siempre.
     *
     * @param  \Illuminate\Http\Client\Response $respuesta
     * @param  int    $max_bytes
     * @param  string $url
     * @return string|null
     */
    protected function leer_con_tope($respuesta, $max_bytes, $url)
    {
        $this->content_type = (string) $respuesta->header('Content-Type');

        return parent::leer_con_tope($respuesta, $max_bytes, $url);
    }

    /**
     * Anota el estado HTTP de una respuesta que no se va a leer (403, 404, 503, un redirect, un PDF…) antes de
     * cerrarla: es lo único que dice POR QUÉ falló la descarga directa, y descargar() solo devuelve null.
     *
     * @param  \Illuminate\Http\Client\Response $respuesta
     * @return void
     */
    protected function cerrar($respuesta)
    {
        try {
            $this->ultimo_estado = (int) $respuesta->status();
        } catch (\Throwable $e) {
            $this->ultimo_estado = null;
        }

        parent::cerrar($respuesta);
    }

    /**
     * Por qué falló la última descarga directa: una de las constantes FALLO_*, o null si no falló.
     *
     * @return string|null
     */
    public function motivo_del_fallo()
    {
        return $this->fallo;
    }

    /**
     * El estado HTTP de la última respuesta que se descartó (403, 404, 503…); null si no hubo respuesta.
     *
     * @return int|null
     */
    public function ultimo_estado()
    {
        return $this->ultimo_estado;
    }

    /**
     * ¿Conviene pedir el respaldo (scrape) tras este fallo? Sí cuando el sitio bloquea (403 y similares), no
     * respondió a tiempo, o es un PDF. No cuando la página no existe (404/410), cuando la guarda anti-SSRF
     * no permite el destino, ni cuando se acabó el presupuesto de tiempo del artículo.
     *
     * @param  string|null $fallo  Un FALLO_*.
     * @return bool
     */
    public static function fallo_admite_respaldo($fallo)
    {
        return in_array($fallo, [self::FALLO_BLOQUEADA, self::FALLO_SIN_RESPUESTA, self::FALLO_PDF], true);
    }

    /**
     * Traduce lo que dejó la descarga fallida (los descartes nuevos de la guarda y el último estado HTTP) a un
     * FALLO_*.
     *
     * @param  array $descartes  Los descartes anotados durante ESTA descarga.
     * @return string
     */
    protected function clasificar_el_fallo(array $descartes)
    {
        $texto = implode(' | ', $descartes);

        if (strpos($texto, 'destino no permitido') !== false) {
            return self::FALLO_NO_PERMITIDO;
        }

        if (strpos($texto, 'sin presupuesto de tiempo') !== false) {
            return self::FALLO_SIN_TIEMPO;
        }

        if (stripos($texto, 'application/pdf') !== false) {
            return self::FALLO_PDF;
        }

        // Cualquier otro descarte (pesa de más, otro Content-Type, demasiadas redirecciones): no es un bloqueo.
        if ($texto !== '') {
            return self::FALLO_OTRO;
        }

        if (is_null($this->ultimo_estado)) {
            return self::FALLO_SIN_RESPUESTA;
        }

        if ($this->ultimo_estado === 404 || $this->ultimo_estado === 410) {
            return self::FALLO_NO_EXISTE;
        }

        return $this->ultimo_estado >= 400 ? self::FALLO_BLOQUEADA : self::FALLO_OTRO;
    }

    /**
     * Pide el TEXTO PLANO de una página (o de un PDF) al scrape de Serper. Nunca lanza.
     *
     * Respeta el tope de búsquedas de las demos (un scrape es una llamada real a Serper y cuenta como una) y
     * registra el consumo en `serper_usage_events` (proceso `medidas_scrape`, endpoint `search`, `credits` = lo que
     * informe Serper en la respuesta). Sin clave de Serper no sale a la red.
     *
     * @param  string $url  La página o el PDF.
     * @return array  ['ok' => bool, 'texto' => string|null, 'titulo' => string, 'creditos' => float|null,
     *                'error' => string|null, 'tope_demo' => bool, 'sin_clave' => bool]
     */
    public function scrapear($url)
    {
        $fallo = function ($error, $extra = []) {
            return array_merge(['ok' => false, 'texto' => null, 'titulo' => '', 'creditos' => null, 'error' => $error, 'tope_demo' => false, 'sin_clave' => false], $extra);
        };

        $clave = ImageSearchProviderFactory::clave_serper_para($this->owner);

        if ($clave === '') {
            return $fallo('No hay clave de Serper para el respaldo.', ['sin_clave' => true]);
        }

        $contexto = ['user_id' => is_null($this->owner) ? null : (int) $this->owner->id, 'auth_user_id' => null, 'process' => self::PROCESO_SCRAPE];
        $url      = (string) $url;

        try {
            $llamada = TopeSerperDemo::ejecutar($this->owner, function () use ($clave, $url, $contexto) {
                $inicio    = microtime(true);
                $respuesta = null;
                $excepcion = null;

                try {
                    $respuesta = $this->google_http()
                        ->withOptions(['connect_timeout' => 3])
                        ->timeout(self::TIMEOUT_SCRAPE)
                        ->withHeaders(['X-API-KEY' => $clave, 'Content-Type' => 'application/json'])
                        ->post(self::URL_SCRAPE, ['url' => $url]);
                } catch (\Throwable $e) {
                    $excepcion = $e;
                } finally {
                    // El registro va ADENTRO del candado de la demo: el siguiente tiene que verlo.
                    SerperUsageService::record($contexto, self::ENDPOINT_SCRAPE, $url, $respuesta, $inicio);
                }

                return ['respuesta' => $respuesta, 'excepcion' => $excepcion];
            });
        } catch (TopeSerperDemoAgotado $e) {
            return $fallo(TopeSerperDemo::MENSAJE, ['tope_demo' => true]);
        } catch (ProductFactsException $e) {
            // La demo tenía el candado tomado por otra llamada: no se llamó.
            return $fallo($e->getMessage());
        }

        if (!is_null($llamada['excepcion'])) {
            // El detalle técnico (sin claves) al log; a quien mira, un texto legible.
            Log::warning('[MedidasMasivas] El respaldo de lectura no respondió.', [
                'error' => Str::limit(ImageServiceCallLogger::sin_claves($llamada['excepcion']->getMessage(), [$clave]), 200, '…'),
            ]);

            return $fallo(ImageServiceCallLogger::es_timeout($llamada['excepcion']->getMessage())
                ? 'El respaldo de lectura no respondió a tiempo.'
                : 'No se pudo conectar con el respaldo de lectura.');
        }

        $respuesta = $llamada['respuesta'];
        $cuerpo    = $respuesta->json();
        $creditos  = is_array($cuerpo) && isset($cuerpo['credits']) && is_numeric($cuerpo['credits']) && $cuerpo['credits'] >= 0 ? (float) $cuerpo['credits'] : null;

        if (!$respuesta->successful()) {
            return $fallo('El respaldo de lectura respondió con error (HTTP '.(int) $respuesta->status().').', ['creditos' => $creditos]);
        }

        $texto = is_array($cuerpo) && isset($cuerpo['text']) && is_string($cuerpo['text']) ? trim($cuerpo['text']) : '';

        if ($texto === '') {
            return $fallo('El respaldo de lectura no devolvió texto.', ['creditos' => $creditos]);
        }

        $titulo = is_array($cuerpo) && isset($cuerpo['metadata']['title']) && is_string($cuerpo['metadata']['title']) ? trim($cuerpo['metadata']['title']) : '';

        return [
            'ok'        => true,
            'texto'     => $texto,
            'titulo'    => $titulo,
            'creditos'  => $creditos,
            'error'     => null,
            'tope_demo' => false,
            'sin_clave' => false,
        ];
    }

    /**
     * Por qué no se pudo bajar la última página, en una frase legible (el último descarte de la guarda).
     *
     * @return string
     */
    public function ultimo_descarte()
    {
        $descartes = $this->descartes();

        return empty($descartes) ? 'No se pudo descargar la página.' : (string) end($descartes);
    }
}
