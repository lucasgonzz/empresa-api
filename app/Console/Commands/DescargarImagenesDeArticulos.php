<?php

namespace App\Console\Commands;

use Database\Seeders\FerreteriaArticlesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Baja a storage/app/public/articles-seeder las fotos de los articulos del catalogo de
 * ferreteria, para que la demo se vea como una tienda de verdad y no como una grilla de
 * placeholders.
 *
 * Se corre A MANO y solo en local. Las imagenes NO se commitean (storage/app/public tiene
 * un .gitignore que ignora todo), asi que a los servidores la carpeta se sube a mano. Ese
 * es el trato: el repo no engorda con 36 jpg y el seeder aguanta que la carpeta no este
 * (FerreteriaArticlesSeeder::url_de_imagen() loguea un warning y crea el articulo sin foto).
 *
 * 🔴 La fuente es Wikimedia Commons y NO un banco de fotos por tag tipo Flickr, y la
 * diferencia no es de gusto. Se probaron las dos (17/8/2026): un banco por tag indexa fotos
 * de ESCENA subidas por aficionados, asi que "circuit breaker" devolvia un señor lavando los
 * platos y "garden hoe" un rastrillo oxidado entre las ramas de un arbol. Commons indexa
 * fotos ENCICLOPEDICAS de objeto, que es justo lo que necesita una ficha de producto: el
 * mismo "circuit breaker" devuelve una llave termica sobre riel DIN con fondo negro. Para una
 * demo que se le muestra a un cliente, una foto impertinente es peor que no tener foto.
 *
 * El catalogo lo lee del propio seeder: si la lista de terminos viviera copiada aca, agregar
 * un articulo al catalogo dejaria de bajarle la foto sin que nada lo denuncie.
 *
 * Nota de licencias: las fotos de Commons son de uso libre pero muchas piden atribucion
 * (CC-BY / CC-BY-SA). Para datos de demo y de local no molesta, pero NO son fotos de
 * catalogo propias: antes de usar cualquiera de estas imagenes en material comercial hay
 * que mirar la licencia de cada archivo.
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
    protected $description = 'Descarga de Wikimedia Commons las fotos del catalogo de ferreteria a storage/app/public/articles-seeder (solo local)';

    /**
     * API de Wikimedia Commons.
     *
     * @var string
     */
    const API_COMMONS = 'https://commons.wikimedia.org/w/api.php';

    /**
     * 🔴 Wikimedia EXIGE un User-Agent descriptivo, con contacto, en su politica de uso, y
     * rechaza los pedidos que no lo traen. Va tanto en la busqueda como en la descarga del
     * archivo: son dos hosts distintos (commons.wikimedia.org y upload.wikimedia.org) y los
     * dos aplican la misma politica.
     *
     * @var string
     */
    const USER_AGENT = 'ComercioCitySeeder/1.0 (https://comerciocity.com; contacto@comerciocity.com)';

    /**
     * Segundos de espera por pedido. Son 36 busquedas mas 36 descargas.
     *
     * @var int
     */
    const TIMEOUT_SEGUNDOS = 20;

    /**
     * Resultados que se piden por busqueda. Ocho alcanzan para saltear los formatos que no
     * sirven y quedarse igual con algo del top del ranking: pedir mas es traer resultados
     * cada vez menos pertinentes que igual no se van a usar.
     *
     * @var int
     */
    const RESULTADOS_POR_BUSQUEDA = 8;

    /**
     * Ancho del thumbnail que se baja. Se baja el thumb y NO el original a proposito: los
     * originales de Commons son archivos de archivo y pueden pesar decenas de MB.
     *
     * @var int
     */
    const ANCHO_THUMB = 800;

    /**
     * Formatos que sirven para una ficha de producto.
     *
     * Hay que filtrar si o si: Commons devuelve muchos image/svg+xml (diagramas y esquemas),
     * algun image/tiff y algun image/webp mezclados en el top del ranking. Sin este filtro,
     * "putty knife" se traeria un .svg (esta en el puesto 2) y "fish tape" un .webp (puesto 1),
     * y los dos terminarian guardados con extension .jpg.
     *
     * @var array<int,string>
     */
    const MIMES_ACEPTADOS = ['image/jpeg', 'image/png'];

    /**
     * Ancho minimo del archivo ORIGINAL para que un resultado sea usable.
     *
     * Commons NO agranda: si el original mide 195px, el thumb de 800 sale de 195px igual y en
     * la grilla de productos se ve pixelado. Y ojo con el dato que se mira, que es la trampa
     * de este filtro: `thumbwidth` de la respuesta devuelve SIEMPRE el ancho pedido (800),
     * tambien cuando el original es mas chico. El unico dato real es `width`, que es el ancho
     * del original y llega porque se pide `size` en iiprop.
     *
     * Medido el 17/8/2026: sin este filtro "plaster trowel" se traia File:Traufel.jpg de
     * 195x222 y "putty knife" tenia un 263x172 en el cuarto puesto.
     *
     * @var int
     */
    const ANCHO_MINIMO = 400;

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

        /** md5 => archivos con ese contenido, para detectar fotos repetidas al final. */
        $huellas = [];

        /** termino => cuantos articulos lo usaron ya, para no repetir la foto. Ver mas abajo. */
        $usos_por_termino = [];

        foreach ($catalogo as $indice => $item) {

            /** Nombre del archivo; null en los articulos que van sin foto a proposito. */
            $archivo = FerreteriaArticlesSeeder::nombre_de_archivo_de_imagen($indice, $item);

            if (is_null($archivo)) {
                continue;
            }

            $termino = $item['imagen_busqueda'];

            /*
             * Dos articulos con el MISMO termino tienen que quedar con fotos DISTINTAS: Commons
             * es determinista para un termino dado, asi que sin esto los dos cestos de basura o
             * las dos espatulas quedaban con el mismo archivo byte a byte y el catalogo mostraba
             * 33 fotos para 36 articulos. El enesimo articulo que usa un termino se lleva el
             * enesimo resultado usable.
             *
             * El contador se incrementa por POSICION en el catalogo y no por descarga exitosa, y
             * eso importa: si el primero de los dos ya tenia su archivo y se saltea, el segundo
             * igual tiene que tomar el segundo resultado. Contando solo las descargas, en una
             * corrida sin --forzar el segundo se llevaria el primer resultado y los dejaria a los
             * dos con la misma foto, que es justo lo que este contador viene a evitar.
             */
            $salto = isset($usos_por_termino[$termino]) ? $usos_por_termino[$termino] : 0;
            $usos_por_termino[$termino] = $salto + 1;

            $ruta = $carpeta . '/' . $archivo;

            if (Storage::disk('public')->exists($ruta) && !$this->option('forzar')) {
                $salteadas++;
                continue;
            }

            /** Resultado de Commons que le toca a este articulo, o null. */
            $eleccion = $this->buscar_en_commons($termino, $archivo, $salto);

            if (is_null($eleccion)) {
                $fallidas++;
                continue;
            }

            /** Cuerpo de la imagen, o null si la descarga no sirvio. */
            $foto = $this->descargar($eleccion['thumburl'], $archivo);

            if (is_null($foto)) {
                $fallidas++;
                continue;
            }

            Storage::disk('public')->put($ruta, $foto);

            $huella = md5($foto);
            $huellas[$huella] = isset($huellas[$huella]) ? $huellas[$huella] : [];
            $huellas[$huella][] = $archivo;

            $bajadas++;

            // Se imprime el titulo elegido para que se pueda revisar la pertinencia de las 36
            // leyendo la salida, sin tener que abrir archivo por archivo.
            $this->line(
                '  ok  ' . $archivo . '  [' . $termino . ($salto > 0 ? ' #' . ($salto + 1) : '') . ']'
                . '  ->  ' . $eleccion['titulo'] . '  (' . $eleccion['ancho'] . 'px)'
            );
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
     * Busca el termino en Commons y devuelve la primera imagen usable del ranking.
     *
     * 🔴 El orden del array `pages` que devuelve la API NO es el ranking de la busqueda: viene
     * indexado por pageid y PHP lo conserva en ese orden. El ranking real esta en el campo
     * `index` de cada pagina, y por eso se ordena a mano antes de elegir. Sin ese usort se
     * elegiria por orden de pageid, o sea casi al azar entre los 8 resultados.
     *
     * Sobre repetibilidad, para no prometer de mas: el usort ordena bien, pero el ranking que
     * manda Commons NO es 100% estable entre llamadas. Cuando dos archivos empatan en
     * relevancia (medido el 17/8/2026 con "ignition module", que alterna entre "Capacitor
     * Discharge Ignition 1" y "2"), el primer puesto se intercambia de una llamada a la otra.
     * En la practica no molesta porque el comando saltea los archivos que ya estan: una
     * instalacion baja la foto una sola vez y se la queda. Lo unico que puede cambiar una foto
     * por otra igual de pertinente es correr con --forzar.
     *
     * @param string $termino Termino de busqueda en ingles.
     * @param string $archivo Solo para el mensaje de error.
     * @param int $salto Cuantos resultados usables saltear (0 = el mejor rankeado).
     * @return array<string,mixed>|null ['titulo', 'thumburl', 'ancho'], o null si no hubo.
     */
    protected function buscar_en_commons($termino, $archivo, $salto = 0)
    {
        try {
            $respuesta = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(self::TIMEOUT_SEGUNDOS)
                ->get(self::API_COMMONS, [
                    'action' => 'query',
                    'format' => 'json',
                    'generator' => 'search',
                    'gsrsearch' => $termino,
                    // 6 es el namespace File:, o sea archivos y no articulos.
                    'gsrnamespace' => 6,
                    'gsrlimit' => self::RESULTADOS_POR_BUSQUEDA,
                    'prop' => 'imageinfo',
                    // 'size' trae el ancho del ORIGINAL, que es lo unico que permite descartar
                    // los archivos chicos (ver ANCHO_MINIMO).
                    'iiprop' => 'url|mime|size',
                    'iiurlwidth' => self::ANCHO_THUMB,
                ]);
        } catch (\Throwable $e) {
            $this->warn('  falló ' . $archivo . ' — la búsqueda no salió: ' . get_class($e) . ': ' . $e->getMessage());

            return null;
        }

        if (!$respuesta->successful()) {
            $this->warn('  falló ' . $archivo . ' — la búsqueda devolvió HTTP ' . $respuesta->status());

            return null;
        }

        /** Paginas del resultado; Commons omite la clave entera cuando no hay ninguna. */
        $paginas = $respuesta->json('query.pages');

        if (!is_array($paginas) || count($paginas) === 0) {
            $this->warn('  falló ' . $archivo . ' — Commons no devolvió resultados para "' . $termino . '"');

            return null;
        }

        $paginas = array_values($paginas);

        usort($paginas, function ($a, $b) {
            $indice_a = isset($a['index']) ? $a['index'] : PHP_INT_MAX;
            $indice_b = isset($b['index']) ? $b['index'] : PHP_INT_MAX;

            return $indice_a <=> $indice_b;
        });

        /** Resultados usables, ya en orden de ranking. */
        $candidatos = [];

        foreach ($paginas as $pagina) {

            if (!isset($pagina['imageinfo'][0])) {
                continue;
            }

            $info = $pagina['imageinfo'][0];

            if (!isset($info['mime']) || !in_array($info['mime'], self::MIMES_ACEPTADOS, true)) {
                continue;
            }

            if (empty($info['thumburl'])) {
                continue;
            }

            /** Ancho del original, no del thumb. Ver ANCHO_MINIMO. */
            $ancho = isset($info['width']) ? (int) $info['width'] : 0;

            if ($ancho < self::ANCHO_MINIMO) {
                continue;
            }

            $candidatos[] = [
                'titulo' => isset($pagina['title']) ? $pagina['title'] : '(sin titulo)',
                'thumburl' => $info['thumburl'],
                'ancho' => $ancho,
            ];
        }

        if (count($candidatos) === 0) {
            $this->warn(
                '  falló ' . $archivo . ' — para "' . $termino . '" Commons no devolvió ninguna imagen usable '
                . '(o son formatos que no sirven —svg, pdf, tiff, webp— o son más chicas que ' . self::ANCHO_MINIMO . 'px)'
            );

            return null;
        }

        if ($salto >= count($candidatos)) {
            /*
             * Mas articulos con este termino que resultados usables. Se reusa el ultimo en vez de
             * fallar: una foto repetida es un defecto menor y avisado, y quedarse sin foto no
             * arregla nada. avisar_fotos_repetidas() lo va a marcar igual al final.
             */
            $this->warn(
                '  ojo: "' . $termino . '" lo usan más artículos (' . ($salto + 1) . ') que resultados usables '
                . 'que tiene (' . count($candidatos) . '); ' . $archivo . ' repite foto. Dale un término propio.'
            );

            $salto = count($candidatos) - 1;
        }

        return $candidatos[$salto];
    }

    /**
     * Baja una foto. Devuelve el cuerpo, o null si no sirvio.
     *
     * Nunca corta la corrida: 35 fotos de 36 es un resultado aceptable y las que faltan se
     * reintentan volviendo a correr el comando. Abortar en la primera que falla obligaria a
     * empezar de cero cada vez que Commons tiene un mal minuto.
     *
     * @param string $url
     * @param string $archivo Solo para el mensaje de error.
     * @return string|null
     */
    protected function descargar($url, $archivo)
    {
        try {
            $respuesta = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(self::TIMEOUT_SEGUNDOS)
                ->get($url);
        } catch (\Throwable $e) {
            $this->warn('  falló ' . $archivo . ' — ' . get_class($e) . ': ' . $e->getMessage());

            return null;
        }

        if (!$respuesta->successful()) {
            $this->warn('  falló ' . $archivo . ' — la descarga devolvió HTTP ' . $respuesta->status());

            return null;
        }

        /** Tipo de contenido informado por el servidor de archivos. */
        $tipo = (string) $respuesta->header('Content-Type');

        /*
         * Se revalida el tipo en la descarga aunque la API ya lo haya informado: entre la
         * busqueda y la bajada hay otro host de por medio, y un 200 con una pagina de error
         * guardada como .jpg deja el articulo con la imagen rota en la tienda, que es peor
         * que no tener foto.
         */
        if (strpos($tipo, 'image/') !== 0) {
            $this->warn('  falló ' . $archivo . ' — el servidor devolvió "' . $tipo . '" en vez de una imagen');

            return null;
        }

        return $respuesta->body();
    }

    /**
     * Avisa si dos articulos terminaron con la MISMA foto byte a byte.
     *
     * Con Commons no deberia pasar nunca (cada termino cae en un archivo distinto), pero es la
     * red que avisa si dos terminos del catalogo terminan colapsando en el mismo resultado
     * cuando alguien agrega o retoca articulos. El sintoma sin esto seria la demo mostrando la
     * misma foto en dos fichas, sin ningun error a la vista.
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
                '  ojo: estos archivos quedaron con la MISMA foto. Cambiá el termino imagen_busqueda '
                . 'de alguno en FerreteriaArticlesSeeder: ' . implode(', ', $archivos)
            );
        }
    }
}
