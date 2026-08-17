<?php

namespace App\Console\Commands;

use Database\Seeders\FerreteriaArticlesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Baja a storage/app/public/articles-seeder las fotos de los articulos del catalogo de
 * ferreteria, para que la demo y el local se vean como una tienda de verdad y no como una
 * grilla de placeholders.
 *
 * Se corre A MANO y solo en local. Las imagenes NO se commitean (storage/app/public tiene
 * un .gitignore que ignora todo), asi que a los servidores la carpeta se sube a mano. Ese
 * es el trato: el repo no engorda con 36 jpg y el seeder aguanta que la carpeta no este
 * (FerreteriaArticlesSeeder::url_de_imagen() loguea un warning y crea el articulo sin foto).
 *
 * El catalogo lo lee del propio seeder: si la lista de terminos viviera copiada aca,
 * agregar un articulo al catalogo dejaria de bajarle la foto sin que nada lo denuncie.
 */
class DescargarImagenesDeArticulos extends Command
{
    /**
     * @var string
     */
    protected $signature = 'semilla:imagenes {--forzar : Vuelve a bajar las que ya estan}';

    /**
     * @var string
     */
    protected $description = 'Descarga las fotos del catalogo de ferreteria a storage/app/public/articles-seeder (solo local)';

    /**
     * Banco de fotos. Devuelve una foto real de Flickr que matchea los tags pedidos.
     *
     * @var string
     */
    const BASE_DEL_BANCO_DE_FOTOS = 'https://loremflickr.com/512/512/';

    /**
     * Segundos de espera por foto. Son 36 descargas y el servicio a veces se hace rogar:
     * cortar en 20 s deja la corrida en un par de minutos en el peor caso.
     *
     * @var int
     */
    const TIMEOUT_SEGUNDOS = 20;

    /**
     * @return int Codigo de salida (0 = OK).
     */
    public function handle()
    {
        /*
         * Guarda de entorno antes que nada: este comando sale a internet y escribe 36
         * archivos. En un servidor no tiene nada que hacer, alla la carpeta se sube a mano.
         *
         * config('app.env') y NUNCA env('APP_ENV'), por el mismo motivo que en semilla:datos:
         * con config:cache activo, env() fuera de config/ devuelve null y la guarda dejaria pasar.
         */
        if (config('app.env') !== 'local') {
            $this->error('semilla:imagenes — solo corre con APP_ENV=local. En los servidores la carpeta articles-seeder se sube a mano.');

            return 1;
        }

        /** Carpeta destino, la misma que despues busca el seeder. */
        $carpeta = FerreteriaArticlesSeeder::CARPETA_IMAGENES;

        // makeDirectory es idempotente: si ya existe devuelve true sin tocar el contenido.
        Storage::disk('public')->makeDirectory($carpeta);

        /** Catalogo leido del seeder, unica fuente de la verdad de nombres y terminos. */
        $catalogo = (new FerreteriaArticlesSeeder())->get_catalog();

        $bajadas = 0;
        $salteadas = 0;
        $fallidas = 0;

        /** md5 de cada foto bajada, para detectar al final si el banco devolvio siempre la misma. */
        $huellas = [];

        foreach ($catalogo as $indice => $item) {

            /** Nombre del archivo; null en los articulos que van sin foto a proposito. */
            $archivo = FerreteriaArticlesSeeder::nombre_de_archivo_de_imagen($indice, $item);

            if (is_null($archivo)) {
                continue;
            }

            $ruta = $carpeta . '/' . $archivo;

            if (Storage::disk('public')->exists($ruta) && !$this->option('forzar')) {
                $salteadas++;
                continue;
            }

            /** Cuerpo de la respuesta, o null si la descarga no sirvio. */
            $foto = $this->descargar($this->url_de_busqueda($indice, $item), $archivo);

            if (is_null($foto)) {
                $fallidas++;
                continue;
            }

            Storage::disk('public')->put($ruta, $foto);

            $huella = md5($foto);
            $huellas[$huella] = isset($huellas[$huella]) ? $huellas[$huella] : [];
            $huellas[$huella][] = $archivo;

            $bajadas++;
            $this->line('  ok    ' . $archivo . '   (' . $item['imagen_busqueda'] . ')');
        }

        $this->avisar_fotos_repetidas($huellas);

        $this->info('semilla:imagenes — ' . $bajadas . ' bajadas, ' . $salteadas . ' ya estaban, ' . $fallidas . ' fallaron.');

        if ($fallidas > 0) {
            $this->warn('Las que fallaron se pueden reintentar volviendo a correr el comando: las que ya estan se saltean solas.');
        }

        $this->warn('Recordatorio: storage/app/public/' . $carpeta . ' NO se commitea. A los servidores de produccion hay que subirla a mano.');

        return 0;
    }

    /**
     * URL del banco de fotos para un articulo del catalogo.
     *
     * Dos detalles que se pagaron probando contra el servicio (17/8/2026) y que no se
     * deducen de la documentacion:
     *
     *  - Los terminos de varias palabras van separados por COMA, no por espacio. Un espacio
     *    codificado (%20) devuelve 403, no una foto.
     *  - El ?lock fija cual de las fotos que matchean toca, asi dos corridas del comando bajan
     *    exactamente la misma imagen. Se usa el numero de archivo (indice + 1) y no el indice
     *    crudo, porque el primer articulo quedaria con lock=0 y el servicio lo trata como
     *    "sin lock", que es justo lo contrario de lo que se busca.
     *
     * @param int $indice
     * @param array<string,mixed> $item
     * @return string
     */
    protected function url_de_busqueda($indice, $item)
    {
        /** Tags separados por coma, normalizados a minusculas y solo letras. */
        $tags = preg_replace('/[^a-z,]/', '', strtolower(str_replace(' ', ',', trim($item['imagen_busqueda']))));

        return self::BASE_DEL_BANCO_DE_FOTOS . $tags . '/all?lock=' . ($indice + 1);
    }

    /**
     * Baja una foto. Devuelve el cuerpo, o null si no sirvio.
     *
     * Nunca corta la corrida: 35 fotos de 36 es un resultado aceptable y las que faltan se
     * reintentan volviendo a correr el comando. Abortar en la primera que falla obligaria a
     * empezar de cero cada vez que el banco de fotos tiene un mal minuto.
     *
     * @param string $url
     * @param string $archivo Solo para el mensaje de error.
     * @return string|null
     */
    protected function descargar($url, $archivo)
    {
        try {
            $respuesta = Http::timeout(self::TIMEOUT_SEGUNDOS)->get($url);
        } catch (\Throwable $e) {
            $this->warn('  falló ' . $archivo . ' — ' . get_class($e) . ': ' . $e->getMessage());

            return null;
        }

        if (!$respuesta->successful()) {
            $this->warn('  falló ' . $archivo . ' — HTTP ' . $respuesta->status());

            return null;
        }

        /** Tipo de contenido informado por el servicio. */
        $tipo = (string) $respuesta->header('Content-Type');

        /*
         * El codigo 200 no alcanza: ante una URL que no le gusta el servicio contesta 200 con
         * una pagina HTML de error. Sin este chequeo se guardaria un HTML con extension .jpg y
         * el articulo quedaria con la imagen rota en la tienda, que es peor que no tener foto.
         */
        if (strpos($tipo, 'image/') !== 0) {
            $this->warn('  falló ' . $archivo . ' — el servicio devolvió "' . $tipo . '" en vez de una imagen');

            return null;
        }

        return $respuesta->body();
    }

    /**
     * Avisa si dos articulos terminaron con la MISMA foto byte a byte.
     *
     * No es paranoia: cuando ningun tag matchea, el banco devuelve siempre la misma foto de
     * relleno con codigo 200 y content-type image/jpeg, asi que ningun chequeo anterior lo
     * agarra. Los terminos del catalogo estan elegidos para que todos matcheen, pero el banco
     * es un servicio de terceros y el dia que uno deje de matchear el sintoma seria que la
     * demo muestra la misma foto en varios articulos, sin ningun error a la vista.
     *
     * @param array<string,array<int,string>> $huellas md5 => archivos con ese contenido.
     * @return void
     */
    protected function avisar_fotos_repetidas($huellas)
    {
        foreach ($huellas as $archivos) {

            if (count($archivos) < 2) {
                continue;
            }

            $this->warn(
                '  ojo: estos archivos quedaron con la MISMA foto, casi seguro es la imagen de relleno '
                . 'del banco (ninguno de sus tags matcheó). Cambiá el termino imagen_busqueda de esos '
                . 'articulos en FerreteriaArticlesSeeder: ' . implode(', ', $archivos)
            );
        }
    }
}
