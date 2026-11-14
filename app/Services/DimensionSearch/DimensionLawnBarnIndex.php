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
 * `storage/app/medidas/lawnbarn-<run_id>.json`. Se arma una vez por corrida: si el archivo existe, se usa (los
 * tramos siguientes y una reanudación no lo vuelven a bajar). Si la primera página no baja, el índice queda
 * apagado para la corrida (y eso también se guarda: no se reintenta en cada tramo); no rompe nada, el adaptador
 * de Lawn Barn simplemente no se usa.
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

    /** @var array|null El índice ya cargado (en memoria, para los artículos siguientes del tramo). */
    protected $cargado = null;

    /**
     * El índice de la corrida: lo lee del archivo, o lo arma bajando el sitemap y lo guarda.
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @return array  ['ok' => bool, 'urls' => compacto => url, 'error' => string|null, 'incompleto' => bool]
     */
    public function cargar(DimensionSearchRun $run)
    {
        if (!is_null($this->cargado)) {
            return $this->cargado;
        }

        $ruta = self::ruta((int) $run->id);

        if (is_file($ruta)) {
            $guardado = json_decode((string) @file_get_contents($ruta), true);

            if (is_array($guardado) && array_key_exists('ok', $guardado)) {
                $this->cargado = [
                    'ok'         => (bool) $guardado['ok'],
                    'urls'       => isset($guardado['urls']) && is_array($guardado['urls']) ? $guardado['urls'] : [],
                    'error'      => isset($guardado['error']) ? $guardado['error'] : null,
                    'incompleto' => !empty($guardado['incompleto']),
                ];

                return $this->cargado;
            }
        }

        $this->cargado = $this->armar(self::codigos_de_la_corrida((int) $run->id));

        $this->guardar($ruta, $this->cargado);

        return $this->cargado;
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
        $indice = ['ok' => true, 'urls' => [], 'error' => null, 'incompleto' => false];

        if (empty($codigos)) {
            return $indice;
        }

        $inicio = microtime(true);

        for ($pagina = 1; $pagina <= self::MAXIMO_PAGINAS; $pagina++) {
            if (microtime(true) - $inicio >= self::PRESUPUESTO_SEGUNDOS) {
                $indice['incompleto'] = true;
                break;
            }

            $locs = $this->leer_pagina($pagina);

            if (is_string($locs)) {
                // La primera página no bajó: el índice queda apagado para la corrida. Una página posterior que falla es
                // el fin del sitemap (o un corte): se usa lo que se leyó hasta ahí.
                if ($pagina === 1) {
                    return ['ok' => false, 'urls' => [], 'error' => $locs, 'incompleto' => false];
                }

                break;
            }

            if (empty($locs)) {
                break;
            }

            foreach ($locs as $loc) {
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
     * Las URLs (`<loc>`) de una página del sitemap, o un string con el error si no bajó.
     *
     * @param  int $pagina
     * @return array|string
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

            return 'El índice de Lawn Barn no respondió.';
        }

        if (!$respuesta->successful()) {
            return 'El índice de Lawn Barn respondió con error (HTTP '.(int) $respuesta->status().').';
        }

        preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', (string) $respuesta->body(), $coincidencias);

        $locs = [];

        foreach ($coincidencias[1] as $loc) {
            $locs[] = html_entity_decode(trim($loc), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        return $locs;
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
