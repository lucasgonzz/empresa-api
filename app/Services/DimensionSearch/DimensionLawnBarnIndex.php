<?php

namespace App\Services\DimensionSearch;

use App\Models\DimensionSearchItem;
use App\Models\DimensionSearchRun;
use App\Services\BusquedaPorCodigoDeBarrasService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * El índice «código de Stihl → ficha de Lawn Barn» de UNA corrida de fichas del fabricante (misión
 * medidas-masquito-fichas, 14/11/2026).
 *
 * Lawn Barn (distribuidor de Stihl de EE. UU.) no tiene una URL que se pueda armar con el código: el código va al
 * final del slug («/hip-pad-4147-740-2500/»). Lo que sí publica es el sitemap de productos
 * (`xmlsitemap.php?type=products&page=N`, unas 10.000 URLs por página). Se baja página por página (N = 1, 2, …
 * mientras haya `<loc>`, hasta MAXIMO_PAGINAS) y de cada URL se toman los tokens del slug que tienen dígitos y se
 * arma el código compacto de la cola («4147-740-2500» → «41477402500»).
 *
 * 🔴 Se guarda SOLO lo que coincide con los códigos de los artículos de la corrida (no el sitemap entero), en
 * `storage/app/medidas/lawnbarn-<run_id>.json` (se borra al terminar la corrida). Se arma una vez por corrida: si el
 * archivo existe, se usa (los tramos siguientes y una reanudación no lo vuelven a bajar). Fin del sitemap = una
 * página que responde 404 o que no trae ningún `<loc>`. Si la primera página no baja, o baja sin `<loc>`, el índice
 * queda apagado y no rompe nada: el adaptador de Lawn Barn simplemente no se usa.
 *
 * Lo que puede ser pasajero NO se guarda como definitivo: si la primera página no bajó por un corte, un timeout, un
 * 5xx o un bloqueo, o si una página posterior falló así (índice `incompleto`), el archivo queda marcado y el tramo
 * siguiente lo vuelve a armar UNA vez (MAXIMO_ARMADOS por corrida): un sitio caído no se convierte en 150 s por tramo.
 *
 * El sitemap es XML y DimensionPageReader solo lee HTML: por eso va con su propio cliente HTTP, con timeout corto y
 * siempre al mismo host fijo (no hay URL que venga de afuera). Las URLs del índice se filtran por host: solo quedan
 * las de lawnbarn.com, así un sitemap adulterado no puede mandar al lector a otro lado.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class DimensionLawnBarnIndex
{
    /** El sitemap de productos (se le pega el número de página). */
    const URL_SITEMAP = 'https://lawnbarn.com/xmlsitemap.php?type=products&page=';

    /** Páginas del sitemap como mucho. */
    const MAXIMO_PAGINAS = 80;

    /** Segundos de espera por página del sitemap. */
    const TIMEOUT_POR_PAGINA = 10;

    /** Segundos como mucho para armar el índice entero (lo que se leyó hasta ahí se usa, marcado incompleto). */
    const PRESUPUESTO_SEGUNDOS = 150;

    /** Largo del código compacto de Stihl (4 + 3 + 4). */
    const LARGO_CODIGO_STIHL = 11;

    /** Hosts aceptados en las URLs del índice. */
    const HOSTS = ['lawnbarn.com', 'www.lawnbarn.com'];

    /** Veces como mucho que se arma el índice en una corrida (la primera y un reintento si quedó a medias). */
    const MAXIMO_ARMADOS = 2;

    /** @var array|null El índice ya cargado (en memoria, para los artículos siguientes del tramo). */
    protected $cargado = null;

    /**
     * El índice de la corrida: lo lee del archivo, o lo arma bajando el sitemap y lo guarda.
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @return array  ['ok' => bool, 'urls' => compacto => url, 'error' => string|null, 'incompleto' => bool,
     *                'fallo' => DimensionPageReader::FALLO_BLOQUEADA | FALLO_SIN_RESPUESTA | null (por qué no está, si
     *                puede ser pasajero), 'armados' => int, 'recien_armado' => bool (se bajó el sitemap en esta llamada)]
     */
    public function cargar(DimensionSearchRun $run)
    {
        if (!is_null($this->cargado)) {
            return array_merge($this->cargado, ['recien_armado' => false]);
        }

        $ruta     = self::ruta((int) $run->id);
        $guardado = $this->leer_guardado($ruta);
        $armados  = is_null($guardado) ? 0 : (int) $guardado['armados'];

        // Un índice guardado y definitivo (completo, o apagado por algo que no es pasajero) se usa tal cual; uno que
        // quedó a medias se vuelve a armar, una sola vez por corrida.
        if (!is_null($guardado) && (!$this->se_puede_reintentar($guardado) || $armados >= self::MAXIMO_ARMADOS)) {
            $this->cargado = $guardado;

            return array_merge($this->cargado, ['recien_armado' => false]);
        }

        $this->cargado = array_merge($this->armar(self::codigos_de_la_corrida((int) $run->id)), ['armados' => $armados + 1]);

        $this->guardar($ruta, $this->cargado);

        return array_merge($this->cargado, ['recien_armado' => true]);
    }

    /**
     * Borra el índice guardado de una corrida (al terminarla o al purgarla). Nunca lanza.
     *
     * @param  int $run_id
     * @return void
     */
    public static function borrar($run_id)
    {
        $ruta = self::ruta((int) $run_id);

        if (is_file($ruta)) {
            @unlink($ruta);
        }
    }

    /**
     * El índice guardado en el archivo, o null si no hay (o no se entiende).
     *
     * @param  string $ruta
     * @return array|null
     */
    protected function leer_guardado($ruta)
    {
        if (!is_file($ruta)) {
            return null;
        }

        $guardado = json_decode((string) @file_get_contents($ruta), true);

        if (!is_array($guardado) || !array_key_exists('ok', $guardado)) {
            return null;
        }

        return [
            'ok'         => (bool) $guardado['ok'],
            'urls'       => isset($guardado['urls']) && is_array($guardado['urls']) ? $guardado['urls'] : [],
            'error'      => isset($guardado['error']) ? $guardado['error'] : null,
            'incompleto' => !empty($guardado['incompleto']),
            'fallo'      => isset($guardado['fallo']) ? $guardado['fallo'] : null,
            'armados'    => isset($guardado['armados']) ? (int) $guardado['armados'] : 1,
        ];
    }

    /**
     * ¿El índice quedó a medias por algo que puede ser pasajero (y vale la pena volver a armarlo)?
     *
     * @param  array $indice
     * @return bool
     */
    protected function se_puede_reintentar(array $indice)
    {
        return !empty($indice['incompleto']) || (!$indice['ok'] && !is_null($indice['fallo']));
    }

    /**
     * Dónde se guarda el índice de una corrida.
     *
     * @param  int $run_id
     * @return string
     */
    public static function ruta($run_id)
    {
        return storage_path('app/medidas/lawnbarn-'.(int) $run_id.'.json');
    }

    /**
     * Un código sin espacios, guiones, puntos ni barras, en mayúsculas («4147-740-2500» → «41477402500»).
     *
     * @param  string $codigo
     * @return string
     */
    public static function compacto($codigo)
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $codigo));
    }

    /**
     * Los códigos compactos (de 11 caracteres alfanuméricos, la forma de los de Stihl) de los artículos de la
     * corrida, de la foto que guardó cada item al crearse.
     *
     * @param  int $run_id
     * @return array  compacto => true
     */
    public static function codigos_de_la_corrida($run_id)
    {
        $codigos = [];

        $filas = DimensionSearchItem::where('run_id', (int) $run_id)->pluck('article_provider_code');

        foreach ($filas as $codigo) {
            $compacto = self::compacto($codigo);

            if (strlen($compacto) === self::LARGO_CODIGO_STIHL) {
                $codigos[$compacto] = true;
            }
        }

        return $codigos;
    }

    /**
     * Los códigos compactos que puede tener el slug de una URL: las colas de cada tramo seguido de tokens con
     * dígitos («/rm-655-1-vs-usa-lawn-mower-6374-011-3451-us/» → «3451», «0113451», «63740113451», …).
     *
     * @param  string $url
     * @return array  Lista de compactos.
     */
    public static function compactos_del_slug($url)
    {
        $camino = parse_url((string) $url, PHP_URL_PATH);

        if (!is_string($camino) || $camino === '') {
            return [];
        }

        $slug   = trim($camino, '/');
        $partes = preg_split('/[^A-Za-z0-9]+/', $slug, -1, PREG_SPLIT_NO_EMPTY);

        if ($partes === false) {
            return [];
        }

        $candidatos = [];
        $tramo      = [];

        // Un token sin dígitos corta el tramo.
        foreach (array_merge($partes, ['']) as $parte) {
            if ($parte !== '' && preg_match('/\d/', $parte)) {
                $tramo[] = strtoupper($parte);
                continue;
            }

            for ($desde = count($tramo) - 1; $desde >= 0; $desde--) {
                $candidatos[] = implode('', array_slice($tramo, $desde));
            }

            $tramo = [];
        }

        return array_values(array_unique($candidatos));
    }

    /**
     * Baja el sitemap y se queda con las URLs cuyo código está en la corrida.
     *
     * @param  array $codigos  compacto => true
     * @return array  Como cargar().
     */
    protected function armar(array $codigos)
    {
        $indice = ['ok' => true, 'urls' => [], 'error' => null, 'incompleto' => false, 'fallo' => null];

        if (empty($codigos)) {
            return $indice;
        }

        $inicio = microtime(true);

        for ($pagina = 1; $pagina <= self::MAXIMO_PAGINAS; $pagina++) {
            if (microtime(true) - $inicio >= self::PRESUPUESTO_SEGUNDOS) {
                $indice['incompleto'] = true;
                break;
            }

            $leida = $this->leer_pagina($pagina);

            // Fin del sitemap: una página que no existe (404 / 410) o que no trae ningún <loc>.
            $fin = $leida['ok'] ? empty($leida['locs']) : in_array($leida['estado'], [404, 410], true);

            if ($pagina === 1 && ($fin || !$leida['ok'])) {
                // La primera página no bajó, o no trae productos: el índice queda apagado. `fallo` dice si puede ser
                // pasajero (un corte, un timeout, un 5xx, un bloqueo): entonces se reintenta en el tramo siguiente.
                return [
                    'ok'         => false,
                    'urls'       => [],
                    'error'      => $leida['ok'] ? 'El índice de Lawn Barn no trae productos.' : $leida['error'],
                    'incompleto' => false,
                    'fallo'      => $fin ? null : $leida['fallo'],
                ];
            }

            if ($fin) {
                break;
            }

            // Cualquier otro error después de la primera página: lo leído se usa, pero el índice queda a medias.
            if (!$leida['ok']) {
                $indice['incompleto'] = true;
                $indice['error']      = $leida['error'];
                break;
            }

            foreach ($leida['locs'] as $loc) {
                if (!$this->es_de_lawnbarn($loc)) {
                    continue;
                }

                foreach (self::compactos_del_slug($loc) as $compacto) {
                    if (isset($codigos[$compacto]) && !isset($indice['urls'][$compacto])) {
                        $indice['urls'][$compacto] = $loc;
                    }
                }
            }

            if ($pagina === self::MAXIMO_PAGINAS) {
                $indice['incompleto'] = true;
            }
        }

        return $indice;
    }

    /**
     * Una página del sitemap: sus URLs (`<loc>`) o por qué no bajó.
     *
     * @param  int $pagina
     * @return array  ['ok' => bool, 'locs' => array, 'estado' => int|null, 'error' => string|null,
     *                'fallo' => DimensionPageReader::FALLO_BLOQUEADA | FALLO_SIN_RESPUESTA | null]
     */
    protected function leer_pagina($pagina)
    {
        try {
            $respuesta = Http::withOptions(['connect_timeout' => 3])
                ->timeout(self::TIMEOUT_POR_PAGINA)
                ->withHeaders(['User-Agent' => BusquedaPorCodigoDeBarrasService::USER_AGENT_NAVEGADOR, 'Accept' => 'application/xml,text/xml;q=0.9,*/*;q=0.5'])
                ->get(self::URL_SITEMAP.(int) $pagina);
        } catch (\Throwable $e) {
            Log::info('[MedidasMasivas] No se pudo bajar el sitemap de Lawn Barn.', ['pagina' => $pagina, 'error' => Str::limit($e->getMessage(), 200, '…')]);

            return ['ok' => false, 'locs' => [], 'estado' => null, 'error' => 'El índice de Lawn Barn no respondió.', 'fallo' => DimensionPageReader::FALLO_SIN_RESPUESTA];
        }

        if (!$respuesta->successful()) {
            $estado = (int) $respuesta->status();

            return [
                'ok'     => false,
                'locs'   => [],
                'estado' => $estado,
                'error'  => 'El índice de Lawn Barn respondió con error (HTTP '.$estado.').',
                'fallo'  => in_array($estado, [404, 410], true) ? null : DimensionPageReader::FALLO_BLOQUEADA,
            ];
        }

        preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', (string) $respuesta->body(), $coincidencias);

        $locs = [];

        foreach ($coincidencias[1] as $loc) {
            $locs[] = html_entity_decode(trim($loc), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        return ['ok' => true, 'locs' => $locs, 'estado' => (int) $respuesta->status(), 'error' => null, 'fallo' => null];
    }

    /**
     * ¿La URL es de Lawn Barn (https y uno de HOSTS)?
     *
     * @param  string $url
     * @return bool
     */
    protected function es_de_lawnbarn($url)
    {
        $partes = parse_url((string) $url);

        return is_array($partes)
            && isset($partes['scheme'], $partes['host'])
            && strtolower($partes['scheme']) === 'https'
            && in_array(strtolower($partes['host']), self::HOSTS, true);
    }

    /**
     * Guarda el índice (si no se puede escribir, se usa igual en memoria y queda en el log).
     *
     * @param  string $ruta
     * @param  array  $indice
     * @return void
     */
    protected function guardar($ruta, array $indice)
    {
        $carpeta = dirname($ruta);

        if (!is_dir($carpeta)) {
            @mkdir($carpeta, 0775, true);
        }

        if (@file_put_contents($ruta, json_encode($indice, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            Log::warning('[MedidasMasivas] No se pudo guardar el índice de Lawn Barn.', ['ruta' => $ruta]);
        }
    }
}
