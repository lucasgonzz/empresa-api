<?php

namespace App\Console\Commands;

use Database\Seeders\FerreteriaArticlesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

/**
 * Baja a storage/app/public/articles-seeder las fotos de los articulos del catalogo de
 * ferreteria, las elige por aspecto de catalogo y las deja cuadradas de 800x800 sobre blanco.
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
 * fotos ENCICLOPEDICAS de objeto, que es lo que sirve para una ficha de producto.
 *
 * 🔴 El criterio de seleccion es el FONDO, no la posicion en el ranking (Lucas, 17/8/2026:
 * "no hace falta que las imagenes sean exactamente de los productos, con que coincidan un
 * poco esta bien. Quiero que tengan el fondo blanco y que sean cuadradas"). O sea que el
 * termino de busqueda solo tiene que acertarle al rubro, y de las que Commons devuelve se
 * elige la que MAS se parece a una foto de catalogo, midiendola de verdad: se bajan varias
 * candidatas y se puntua el marco de cada una por claridad y uniformidad (ver
 * puntaje_de_fondo()). Elegir por ranking traia fotos pertinentes pero de escena, con arboles
 * y veredas de fondo, que en una grilla de productos quedan sucias.
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
    protected $description = 'Descarga de Wikimedia Commons las fotos del catalogo de ferreteria, cuadradas 800x800 sobre fondo blanco (solo local)';

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
     * Segundos de espera por pedido. Son 36 busquedas mas unas 180 descargas de candidatas.
     *
     * @var int
     */
    const TIMEOUT_SEGUNDOS = 20;

    /**
     * Resultados que se le piden a Commons por busqueda.
     *
     * Se piden 15 y no 8 desde que la eleccion es por fondo: entre los primeros resultados casi
     * siempre hay fotos de escena, y la de catalogo suele aparecer mas abajo en el ranking. Con
     * una lista corta directamente no esta entre las opciones.
     *
     * @var int
     */
    const RESULTADOS_POR_BUSQUEDA = 15;

    /**
     * Cuantas candidatas se bajan y se miden por articulo.
     *
     * Es el precio de medir: para saber que fondo tiene una foto hay que bajarla. Cinco alcanzan
     * para que aparezca una de catalogo cuando existe, y mantienen la corrida en pocos minutos;
     * bajar las 15 multiplicaria por tres el tiempo para ganar muy poco.
     *
     * @var int
     */
    const CANDIDATAS_A_MEDIR = 5;

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
     * Formatos que sirven para una ficha de producto.
     *
     * Hay que filtrar si o si: Commons devuelve muchos image/svg+xml (diagramas y esquemas),
     * algun image/tiff y algun image/webp mezclados en el top del ranking, y hay terminos que
     * devuelven PDFs y nada mas ("lawn mower air filter" trae 8 boletines escaneados).
     *
     * @var array<int,string>
     */
    const MIMES_ACEPTADOS = ['image/jpeg', 'image/png'];

    /**
     * Lado del cuadrado final, en pixeles.
     *
     * @var int
     */
    const LADO_FINAL = 800;

    /**
     * Calidad del jpg de salida. 88 no deja artefactos visibles en fotos de producto y mantiene
     * los archivos en el orden de los 100 KB.
     *
     * @var int
     */
    const CALIDAD_JPG = 88;

    /**
     * Puntos que se muestrean por cada uno de los cuatro bordes; en total, cuatro veces esto.
     *
     * @var int
     */
    const PUNTOS_POR_BORDE = 10;

    /**
     * Desvio de luminancia a partir del cual el marco se considera completamente disparejo.
     *
     * Calibrado mirando los dos extremos: una foto de catalogo sobre blanco da un desvio de
     * un digito, y una foto al aire libre (arboles, vereda, cielo) pasa comodamente de 60.
     *
     * @var float
     */
    const DESVIO_MAXIMO = 60.0;

    /**
     * Cuanto pesa que el marco sea CLARO frente a que sea UNIFORME.
     *
     * La claridad pesa mas porque el pedido es "fondo blanco" y no "fondo prolijo": un fondo
     * negro de estudio es uniforme pero no es lo que se busca.
     *
     * @var float
     */
    const PESO_CLARIDAD = 0.6;

    /**
     * @var float
     */
    const PESO_UNIFORMIDAD = 0.4;

    /**
     * Detalle del ultimo error de red, para poder informarlo desde donde se sabe que archivo es.
     *
     * @var string
     */
    protected $ultimo_error = '';

    /**
     * Puntaje a partir del cual se considera que la foto tiene fondo de catalogo.
     *
     * Por debajo NO se descarta la foto —se usa igual, normalizada, que es lo pedido— pero se
     * lista al final para poder decidir si a ese articulo le conviene otro termino.
     *
     * @var int
     */
    const PUNTAJE_FONDO_DE_CATALOGO = 70;

    /**
     * Pausa entre pedidos a Wikimedia, en milisegundos.
     *
     * 🔴 No es prolijidad, es lo que hace que la corrida sea correcta. Desde que se miden varias
     * candidatas por articulo, una corrida son ~36 busquedas y ~180 descargas, y sin pausa
     * Wikimedia empieza a contestar 429 a la mitad (medido el 17/8/2026: los articulos 9 a 12 se
     * comieron 38 respuestas 429 seguidas). Lo peligroso del 429 no es que falte una foto: es que
     * las candidatas que se caen NO se miden, asi que el articulo termina quedandose con la unica
     * que pudo bajar en vez de con la mejor, y la eleccion deja de ser la que dice ser.
     *
     * @var int
     */
    const PAUSA_ENTRE_PEDIDOS_MS = 300;

    /**
     * Reintentos ante un 429, y espera inicial antes del primero (se duplica en cada vuelta).
     *
     * @var int
     */
    const REINTENTOS_POR_429 = 3;

    /**
     * @var int
     */
    const ESPERA_POR_429_MS = 2000;

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

        /** Archivos que quedaron por debajo del umbral de fondo, para revisarlos despues. */
        $flojas = [];

        /** Titulos de Commons ya usados en esta corrida, para no repetir foto. Ver mas abajo. */
        $titulos_usados = [];

        foreach ($catalogo as $indice => $item) {

            /** Nombre del archivo; null en los articulos que van sin foto a proposito. */
            $archivo = FerreteriaArticlesSeeder::nombre_de_archivo_de_imagen($indice, $item);

            if (is_null($archivo)) {
                continue;
            }

            $termino = $item['imagen_busqueda'];

            $ruta = $carpeta . '/' . $archivo;

            if (Storage::disk('public')->exists($ruta) && !$this->option('forzar')) {
                $salteadas++;
                continue;
            }

            /** Candidatas ya bajadas y ordenadas de mejor a peor fondo. */
            $candidatas = $this->candidatas_por_fondo($termino, $archivo);

            if (count($candidatas) === 0) {
                $fallidas++;
                continue;
            }

            /*
             * 🔴 La foto se descarta si YA la usó otro articulo, y el descarte va por titulo de
             * Commons y es global a la corrida, no por termino.
             *
             * Antes esto se resolvia con un contador por termino, y no alcanzaba: dos articulos
             * con terminos DISTINTOS pueden caer igual en el mismo archivo. Medido el 17/8/2026,
             * "engine air filter" y "paper air filter" terminaban los dos en
             * File:Air filter, opel astra(1).JPG, y "garden hoe" y "dutch hoe" los dos en
             * File:Draw hoe and Dutch hoe.jpg. Mirando el titulo, el segundo articulo se corre
             * solo a la candidata siguiente y no hace falta inventarle un termino propio.
             */
            $eleccion = null;

            foreach ($candidatas as $candidata) {
                if (!isset($titulos_usados[$candidata['titulo']])) {
                    $eleccion = $candidata;
                    break;
                }
            }

            if (is_null($eleccion)) {
                /*
                 * Todas las candidatas ya estan tomadas. Se usa la mejor igual en vez de dejar el
                 * articulo sin foto: una foto repetida es un defecto menor y avisado, quedarse sin
                 * foto no arregla nada. avisar_fotos_repetidas() lo marca al final.
                 */
                $this->warn(
                    '  ojo: todas las candidatas de "' . $termino . '" ya las usaron otros artículos; '
                    . $archivo . ' repite foto. Dale un término propio.'
                );

                $eleccion = $candidatas[0];
            }

            $titulos_usados[$eleccion['titulo']] = true;

            /** La foto ya normalizada: 800x800 sobre blanco. */
            $final = $this->normalizar_a_cuadrado($eleccion['bytes'], $archivo);

            if (is_null($final)) {
                $fallidas++;
                continue;
            }

            Storage::disk('public')->put($ruta, $final);

            $bajadas++;

            if ($eleccion['puntaje'] < self::PUNTAJE_FONDO_DE_CATALOGO) {
                $flojas[] = $archivo . ' (' . $eleccion['puntaje'] . ') ' . $eleccion['titulo'];
            }

            // Se imprimen titulo y puntaje para poder revisar las 36 leyendo la salida, sin tener
            // que abrir archivo por archivo.
            $this->line(
                '  ok  ' . $archivo . '  [' . $termino . ']'
                . '  fondo ' . str_pad($eleccion['puntaje'], 3, ' ', STR_PAD_LEFT)
                . '  ->  ' . $eleccion['titulo']
            );
        }

        $this->avisar_fotos_repetidas($carpeta);
        $this->avisar_archivos_huerfanos($catalogo, $carpeta);

        $this->info('semilla:imagenes — ' . $bajadas . ' bajadas, ' . $salteadas . ' ya estaban, ' . $fallidas . ' fallaron.');

        if (count($flojas) > 0) {
            $this->warn('Fondo por debajo de ' . self::PUNTAJE_FONDO_DE_CATALOGO . ' (se usaron igual, ya normalizadas; revisá si les conviene otro término):');

            foreach ($flojas as $floja) {
                $this->warn('  · ' . $floja);
            }
        }

        if ($fallidas > 0) {
            $this->warn('Las que fallaron se pueden reintentar volviendo a correr el comando: las que ya están se saltean solas.');
        }

        $this->warn('Recordatorio: storage/app/public/' . $carpeta . ' NO se commitea. A los servidores de produccion hay que subirla a mano.');

        return 0;
    }

    /**
     * Busca el termino, baja hasta CANDIDATAS_A_MEDIR fotos y las devuelve ordenadas por
     * puntaje de fondo, de mejor a peor.
     *
     * @param string $termino Termino de busqueda en ingles.
     * @param string $archivo Solo para los mensajes.
     * @return array<int,array<string,mixed>> Cada una con titulo, puntaje y bytes.
     */
    protected function candidatas_por_fondo($termino, $archivo)
    {
        /** Resultados de la API que pasan los filtros de formato y tamaño. */
        $resultados = $this->buscar_en_commons($termino, $archivo);

        $candidatas = [];

        foreach ($resultados as $resultado) {

            if (count($candidatas) >= self::CANDIDATAS_A_MEDIR) {
                break;
            }

            $bytes = $this->descargar($resultado['thumburl'], $archivo);

            if (is_null($bytes)) {
                continue;
            }

            $puntaje = $this->puntaje_de_fondo($bytes);

            if (is_null($puntaje)) {
                continue;
            }

            $resultado['bytes'] = $bytes;
            $resultado['puntaje'] = $puntaje;

            $candidatas[] = $resultado;
        }

        if (count($candidatas) === 0) {
            $this->warn('  falló ' . $archivo . ' — no se pudo bajar ninguna candidata usable para "' . $termino . '"');

            return [];
        }

        /*
         * Orden por puntaje descendente, con el ranking de Commons como desempate (el orden en
         * que vinieron ya es el del ranking, y usort en PHP no es estable: sin el desempate
         * explicito, dos fotos con el mismo puntaje podrian alternar entre corridas).
         */
        usort($candidatas, function ($a, $b) {
            if ($a['puntaje'] === $b['puntaje']) {
                return $a['orden'] <=> $b['orden'];
            }

            return $b['puntaje'] <=> $a['puntaje'];
        });

        return $candidatas;
    }

    /**
     * Puntua cuanto se parece el MARCO de la foto a un fondo de catalogo, de 0 a 100.
     *
     * La idea: una foto de producto sobre blanco tiene el borde casi blanco y casi sin
     * variacion, y una foto de escena tiene el borde oscuro y disparejo (arboles, vereda,
     * pared, sombras). Se muestrean 40 puntos repartidos por los cuatro bordes y se combinan
     * dos medidas: cuan CLARO es el marco (luminancia media) y cuan UNIFORME es (desvio).
     *
     * Se mira el marco y no la imagen entera a proposito: en el centro esta el producto, que
     * puede ser oscuro sin que eso diga nada del fondo. Una llave termica negra sobre blanco
     * tiene que puntuar alto.
     *
     * @param string $bytes Contenido de la imagen.
     * @return int|null Puntaje 0-100, o null si la imagen no se pudo leer.
     */
    protected function puntaje_de_fondo($bytes)
    {
        try {
            $manager = new ImageManager();
            $imagen = $manager->make($bytes);

            $ancho = $imagen->width();
            $alto = $imagen->height();

            /*
             * Se entra un 2% hacia adentro en vez de leer el pixel del borde exacto: muchas fotos
             * traen una linea de compresion o un filete de un pixel en el canto que no dice nada
             * del fondo real.
             */
            $margen_x = max(1, (int) round($ancho * 0.02));
            $margen_y = max(1, (int) round($alto * 0.02));

            /** Rango util para repartir los puntos de muestreo. */
            $largo_x = max(1, $ancho - 1 - 2 * $margen_x);
            $largo_y = max(1, $alto - 1 - 2 * $margen_y);

            $luminancias = [];

            for ($i = 0; $i < self::PUNTOS_POR_BORDE; $i++) {

                /** Posicion relativa 0..1 del punto dentro del borde. */
                $t = self::PUNTOS_POR_BORDE > 1 ? $i / (self::PUNTOS_POR_BORDE - 1) : 0;

                $x = (int) round($margen_x + $t * $largo_x);
                $y = (int) round($margen_y + $t * $largo_y);

                $luminancias[] = $this->luminancia($imagen, $x, $margen_y);
                $luminancias[] = $this->luminancia($imagen, $x, $alto - 1 - $margen_y);
                $luminancias[] = $this->luminancia($imagen, $margen_x, $y);
                $luminancias[] = $this->luminancia($imagen, $ancho - 1 - $margen_x, $y);
            }

            $cantidad = count($luminancias);
            $media = array_sum($luminancias) / $cantidad;

            $suma_cuadrados = 0.0;

            foreach ($luminancias as $luminancia) {
                $suma_cuadrados += pow($luminancia - $media, 2);
            }

            $desvio = sqrt($suma_cuadrados / $cantidad);

            /** 0 = marco negro, 1 = marco blanco. */
            $claridad = $media / 255;

            /** 1 = marco parejo, 0 = marco completamente disparejo. */
            $uniformidad = max(0.0, 1.0 - ($desvio / self::DESVIO_MAXIMO));

            return (int) round(100 * (self::PESO_CLARIDAD * $claridad + self::PESO_UNIFORMIDAD * $uniformidad));
        } catch (\Throwable $e) {
            $this->warn('  no se pudo medir el fondo de una candidata — ' . get_class($e) . ': ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Luminancia percibida de un pixel, 0 a 255.
     *
     * Coeficientes de Rec. 601: el ojo pesa el verde mucho mas que el azul, asi que un promedio
     * plano de r/g/b daria por "claro" un fondo azul que a la vista es oscuro.
     *
     * @param \Intervention\Image\Image $imagen
     * @param int $x
     * @param int $y
     * @return float
     */
    protected function luminancia($imagen, $x, $y)
    {
        /** [r, g, b, alpha] */
        $color = $imagen->pickColor($x, $y, 'array');

        return 0.299 * $color[0] + 0.587 * $color[1] + 0.114 * $color[2];
    }

    /**
     * Deja la foto cuadrada de LADO_FINAL x LADO_FINAL sobre fondo blanco.
     *
     * @param string $bytes
     * @param string $archivo Solo para el mensaje de error.
     * @return string|null Bytes del jpg final, o null si no se pudo procesar.
     */
    protected function normalizar_a_cuadrado($bytes, $archivo)
    {
        try {
            $manager = new ImageManager();
            $imagen = $manager->make($bytes);

            /*
             * Encaja el objeto dentro del cuadrado sin deformarlo y SIN agrandarlo (upsize()):
             * estirar una foto de 480px a 800 la deja pixelada, y una foto chica bien nitida en
             * el medio del lienzo se ve mejor que una grande y borrosa.
             */
            $imagen->resize(self::LADO_FINAL, self::LADO_FINAL, function ($restriccion) {
                $restriccion->aspectRatio();
                $restriccion->upsize();
            });

            /*
             * 🔴 Se rellena con blanco, NUNCA se recorta. Recortar al centro para forzar el
             * cuadrado le come las puntas al producto: en una foto apaisada de una azada o de una
             * llave T queda el mango y se pierde la herramienta. Las bandas blancas al costado son
             * exactamente lo que hace que una foto parezca de catalogo.
             *
             * Se arma un lienzo blanco y se pega la foto encima, en vez de resizeCanvas(): con un
             * png con transparencia, resizeCanvas conserva el alpha del original y al encodear a
             * jpg esas zonas salen negras. Pegando sobre un lienzo opaco el resultado es blanco
             * siempre, sin importar el formato de origen.
             */
            $lienzo = $manager->canvas(self::LADO_FINAL, self::LADO_FINAL, '#ffffff');
            $lienzo->insert($imagen, 'center');

            // Siempre jpg: asi la extension .jpg que espera el seeder es cierta tambien cuando
            // Commons devolvio un png.
            return (string) $lienzo->encode('jpg', self::CALIDAD_JPG);
        } catch (\Throwable $e) {
            $this->warn('  falló ' . $archivo . ' — no se pudo normalizar: ' . get_class($e) . ': ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Busca el termino en Commons y devuelve los resultados usables, en orden de ranking.
     *
     * 🔴 El orden del array `pages` que devuelve la API NO es el ranking de la busqueda: viene
     * indexado por pageid y PHP lo conserva en ese orden. El ranking real esta en el campo
     * `index` de cada pagina, y por eso se ordena a mano. Sin ese usort se tomarian las
     * candidatas por orden de pageid, o sea casi al azar entre los 15 resultados.
     *
     * Sobre repetibilidad, para no prometer de mas: el ranking que manda Commons NO es 100%
     * estable entre llamadas; cuando dos archivos empatan en relevancia, el puesto se
     * intercambia de una llamada a la otra. Desde que la eleccion la decide el puntaje de fondo
     * eso pesa mucho menos (el ranking quedo como desempate), y ademas el comando saltea los
     * archivos que ya estan: una instalacion baja cada foto una sola vez y se la queda.
     *
     * @param string $termino
     * @param string $archivo Solo para el mensaje de error.
     * @return array<int,array<string,mixed>> Cada uno con titulo, thumburl, ancho y orden.
     */
    protected function buscar_en_commons($termino, $archivo)
    {
        $respuesta = $this->pedir(self::API_COMMONS, [
            'action' => 'query',
            'format' => 'json',
            'generator' => 'search',
            /*
             * 🔴 El "filetype:bitmap" no es un lujo del filtrado, arregla un bug feo de la API.
             * Como se pide iiurlwidth (el thumb de 800), MediaWiki intenta generarle miniatura a
             * CADA resultado; si entre ellos cae un PDF al que no puede, contesta 200 con un
             * `error` de nivel raiz y SIN la clave `pages`: un solo archivo malo se lleva puesta
             * la respuesta entera. Pasaba con "packing tape dispenser", que a partir del
             * resultado 9 trae un PDF de sanidad militar y dejaba al articulo sin foto, mientras
             * que con gsrlimit=8 andaba -- o sea que el sintoma aparecia y desaparecia segun
             * cuantos resultados se pidieran. Acotando la busqueda a imagenes de mapa de bits el
             * PDF ni entra. Ademas sube la calidad de las candidatas: los 15 resultados pasan a
             * ser 15 fotos y no 15 entradas de las cuales varias son diagramas o escaneos.
             */
            'gsrsearch' => $termino . ' filetype:bitmap',
            // 6 es el namespace File:, o sea archivos y no articulos.
            'gsrnamespace' => 6,
            'gsrlimit' => self::RESULTADOS_POR_BUSQUEDA,
            'prop' => 'imageinfo',
            // 'size' trae el ancho del ORIGINAL, que es lo unico que permite descartar
            // los archivos chicos (ver ANCHO_MINIMO).
            'iiprop' => 'url|mime|size',
            'iiurlwidth' => self::LADO_FINAL,
        ]);

        if (is_null($respuesta)) {
            $this->warn('  falló ' . $archivo . ' — la búsqueda no salió: ' . $this->ultimo_error);

            return [];
        }

        if (!$respuesta->successful()) {
            $this->warn('  falló ' . $archivo . ' — la búsqueda devolvió HTTP ' . $respuesta->status());

            return [];
        }

        /*
         * La API contesta 200 aunque haya fallado, con el detalle en `error`. Se informa aparte
         * de "no hubo resultados" porque son dos cosas muy distintas: una es que el termino no
         * matchea nada y hay que cambiarlo, la otra es que la consulta se rompio y el termino
         * puede estar perfecto. Confundirlas cuesta caro -- este mismo bug se investigo dos veces
         * porque el mensaje decia "no devolvió resultados".
         */
        $error = $respuesta->json('error.info');

        if (!is_null($error)) {
            $this->warn('  falló ' . $archivo . ' — Commons rechazó la búsqueda de "' . $termino . '": ' . $error);

            return [];
        }

        /** Paginas del resultado; Commons omite la clave entera cuando no hay ninguna. */
        $paginas = $respuesta->json('query.pages');

        if (!is_array($paginas) || count($paginas) === 0) {
            $this->warn('  falló ' . $archivo . ' — Commons no devolvió resultados para "' . $termino . '"');

            return [];
        }

        $paginas = array_values($paginas);

        usort($paginas, function ($a, $b) {
            $indice_a = isset($a['index']) ? $a['index'] : PHP_INT_MAX;
            $indice_b = isset($b['index']) ? $b['index'] : PHP_INT_MAX;

            return $indice_a <=> $indice_b;
        });

        $resultados = [];
        $orden = 0;

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

            $resultados[] = [
                'titulo' => isset($pagina['title']) ? $pagina['title'] : '(sin titulo)',
                'thumburl' => $info['thumburl'],
                'ancho' => $ancho,
                'orden' => $orden,
            ];

            $orden++;
        }

        if (count($resultados) === 0) {
            $this->warn(
                '  falló ' . $archivo . ' — para "' . $termino . '" Commons no devolvió ninguna imagen usable '
                . '(o son formatos que no sirven —svg, pdf, tiff, webp— o son más chicas que ' . self::ANCHO_MINIMO . 'px)'
            );
        }

        return $resultados;
    }

    /**
     * Hace un pedido a Wikimedia con pausa previa y reintento ante 429.
     *
     * Concentra las dos reglas de convivencia con el servicio en un solo lugar, porque valen
     * igual para la busqueda y para la descarga: ir despacio, y si igual contesta "demasiados
     * pedidos", esperar y volver a intentar en vez de dar la vuelta por perdida.
     *
     * @param string $url
     * @param array<string,mixed> $parametros
     * @return \Illuminate\Http\Client\Response|null Null si ni con reintentos se pudo.
     */
    protected function pedir($url, $parametros = [])
    {
        $espera_ms = self::ESPERA_POR_429_MS;

        for ($intento = 0; $intento <= self::REINTENTOS_POR_429; $intento++) {

            usleep(self::PAUSA_ENTRE_PEDIDOS_MS * 1000);

            try {
                $respuesta = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                    ->timeout(self::TIMEOUT_SEGUNDOS)
                    ->get($url, $parametros);
            } catch (\Throwable $e) {
                $this->ultimo_error = get_class($e) . ': ' . $e->getMessage();

                return null;
            }

            if ($respuesta->status() !== 429) {
                return $respuesta;
            }

            // 429 = nos pasamos de rosca. Se espera cada vez mas antes de volver a probar.
            usleep($espera_ms * 1000);
            $espera_ms *= 2;
        }

        $this->ultimo_error = 'Wikimedia siguió contestando 429 después de ' . self::REINTENTOS_POR_429 . ' reintentos';

        return null;
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
        $respuesta = $this->pedir($url);

        if (is_null($respuesta)) {
            $this->warn('  falló ' . $archivo . ' — ' . $this->ultimo_error);

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
         * guardada como .jpg deja el articulo con la imagen rota en la tienda.
         */
        if (strpos($tipo, 'image/') !== 0) {
            $this->warn('  falló ' . $archivo . ' — el servidor devolvió "' . $tipo . '" en vez de una imagen');

            return null;
        }

        return $respuesta->body();
    }

    /**
     * Avisa si dos articulos quedaron con la MISMA foto byte a byte.
     *
     * Es la red que avisa si dos terminos del catalogo terminan colapsando en el mismo
     * resultado cuando alguien agrega o retoca articulos. El sintoma sin esto seria la demo
     * mostrando la misma foto en dos fichas, sin ningun error a la vista.
     *
     * Se miran TODOS los archivos de la carpeta y no solo los que se escribieron en esta
     * corrida: el descarte por titulo solo conoce lo que se bajo ahora, asi que en una corrida
     * sin --forzar un articulo nuevo podria repetir la foto de otro que se salteo por ya tener
     * la suya. Releyendo la carpeta entera, ese caso tambien queda a la vista.
     *
     * @param string $carpeta
     * @return void
     */
    protected function avisar_fotos_repetidas($carpeta)
    {
        /** md5 => archivos con ese contenido. */
        $huellas = [];

        foreach (Storage::disk('public')->files($carpeta) as $ruta) {
            $huella = md5((string) Storage::disk('public')->get($ruta));

            $huellas[$huella] = isset($huellas[$huella]) ? $huellas[$huella] : [];
            $huellas[$huella][] = basename($ruta);
        }

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

    /**
     * Avisa si en la carpeta quedaron .jpg que ningun articulo del catalogo reclama.
     *
     * Pasa cuando a un articulo se le sacan las claves de imagen o se le cambia el slug: el
     * archivo viejo queda ahi, se sube a los servidores y ocupa lugar sin que nada lo muestre.
     * Como la carpeta no esta en git, no hay diff que lo denuncie.
     *
     * @param array<int,array<string,mixed>> $catalogo
     * @param string $carpeta
     * @return void
     */
    protected function avisar_archivos_huerfanos($catalogo, $carpeta)
    {
        /** Nombres que el catalogo reclama hoy. */
        $esperados = [];

        foreach ($catalogo as $indice => $item) {
            $archivo = FerreteriaArticlesSeeder::nombre_de_archivo_de_imagen($indice, $item);

            if (!is_null($archivo)) {
                $esperados[$archivo] = true;
            }
        }

        $huerfanos = [];

        foreach (Storage::disk('public')->files($carpeta) as $ruta) {
            $nombre = basename($ruta);

            if (!isset($esperados[$nombre])) {
                $huerfanos[] = $nombre;
            }
        }

        if (count($huerfanos) > 0) {
            $this->warn(
                '  ojo: en la carpeta hay archivos que ningún artículo del catálogo usa, se pueden borrar: '
                . implode(', ', $huerfanos)
            );
        }
    }
}
