<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\ArticleEmbeddingService;
use Carbon\Carbon;
use Database\Seeders\FerreteriaArticlesSeeder;
use Illuminate\Console\Command;

/**
 * Genera de una vez los vectores de los 46 artículos del catálogo de la demo y los deja
 * escritos en un archivo del repo, para que sembrar no vuelva a pagarle nunca más a OpenAI.
 *
 * POR QUÉ EXISTE ESTE COMANDO
 * ---------------------------
 * `FerreteriaArticlesSeeder` corre en cada alta de demo y en cada instalación de un cliente
 * nuevo (`DemoSetupHelper::run()`). Antes de esta misión, cada una de esas corridas disparaba
 * 138 llamadas pagas a OpenAI — 46 artículos por 3 jobs cada uno (uno de `Article::created` y
 * dos de `Description::created`), y como los tres ven un texto distinto, el corte por huella no
 * salvaba ninguno.
 *
 * Y había algo peor que el gasto: `DemoSetupHelper` NO configura `OPENAI_API_KEY` (sale del
 * `.env` de cada instancia). En una demo levantada sin esa clave, los embeddings no se generaban
 * NUNCA, así que el lead probaba el agente de WhatsApp contra un catálogo que la búsqueda
 * semántica no veía, y el agente no sabía contestarle nada. Con los vectores horneados, el
 * catálogo de la demo funciona desde el minuto cero y sin depender de ninguna clave.
 *
 * SE CORRE A MANO Y CASI NUNCA: solo cuando cambian las descripciones, los nombres, las marcas o
 * las categorías del catálogo de la semilla. Si alguien se olvida, hay dos redes que lo agarran:
 * el seeder deja el vector en null con un warning (y el comando agendado lo regenera solo), y el
 * test 18 se pone rojo.
 *
 * Uso:
 *   php artisan db:seed --class=FerreteriaArticlesSeeder   (primero, si no están los artículos)
 *   php artisan semilla:embeddings
 */
class HornearEmbeddingsDeSemilla extends Command
{
    /**
     * @var string
     */
    protected $signature = 'semilla:embeddings
                            {--forzar : Rehornea todos, incluso los que no cambiaron}';

    /**
     * @var string
     */
    protected $description = 'Hornea los vectores del catálogo de la semilla en database/seeders/data/ferreteria_embeddings.json';

    /**
     * Decimales a los que se redondea cada componente del vector.
     *
     * 🔴 SEIS, Y NO CUATRO NI DIECISIETE. Es una decisión de tamaño contra ruido de diagnóstico:
     *
     * - Sin redondear, un vector de 1536 componentes con la precisión completa de PHP ocupa unos
     *   28 KB, o sea ~1,3 MB de archivo para los 46 artículos.
     * - A 6 decimales el error por componente es 5e-7, que se traduce en un error del orden de
     *   1e-5 en la similitud de coseno: indistinguible en cualquier búsqueda real. Y el archivo
     *   baja a ~700 KB.
     * - A 4 decimales el archivo bajaría a ~550 KB y el error seguiría siendo despreciable para
     *   la búsqueda (~2e-3). Pero ese orden ya alcanza para que alguien que compare un resultado
     *   horneado contra uno recién generado vea números distintos y pierda una tarde buscando un
     *   bug que no existe. Ahorrar 150 KB no paga esa confusión.
     *
     * @var int
     */
    const DECIMALES = 6;

    /**
     * Archivo donde se dejan los vectores. Es el mismo que lee FerreteriaArticlesSeeder.
     *
     * @return string
     */
    public static function ruta_del_archivo()
    {
        return database_path('seeders/data/ferreteria_embeddings.json');
    }

    /**
     * @return int
     */
    public function handle()
    {
        if (trim((string) config('services.openai.api_key')) === '') {
            $this->error('semilla:embeddings: no hay OPENAI_API_KEY configurada. Sin clave no hay nada que hornear.');
            return 1;
        }

        /*
         * 🔴 Se permiten `local` y `testing`, y NO cualquier entorno. La idea es que esto no se
         * pueda correr por accidente en el servidor de un cliente: allá gastaría plata sin
         * sentido (el archivo horneado ya viaja con el código) y encima escribiría dentro del
         * repo desplegado.
         *
         * `testing` está incluido a propósito y no por comodidad: los slots del pool corren con
         * `APP_ENV=testing`, así que sin él este comando no se podría correr en el único lugar
         * donde se lo corre de verdad.
         */
        if (!in_array(config('app.env'), ['local', 'testing'], true)) {
            $this->error('semilla:embeddings: solo se corre en local o testing. Entorno actual: '.config('app.env'));
            return 1;
        }

        /** @var ArticleEmbeddingService $service */
        $service = app(ArticleEmbeddingService::class);

        /** Catálogo de la semilla, que es la lista de nombres a hornear. */
        $catalogo = (new FerreteriaArticlesSeeder())->get_catalog();

        $user_id = config('app.USER_ID');

        /*
         * Se trabaja contra los artículos YA SEMBRADOS y no contra modelos armados en memoria, y
         * esto no es un detalle de implementación.
         *
         * `ArticleEmbeddingService::descriptions_text()` ordena las descripciones con
         * `sortBy('id')`. Un modelo que no se guardó tiene `id = null`, y `Collection::sortBy` usa
         * `uasort`, que no garantiza estabilidad: el orden de los tres bloques podría salir
         * distinto del que sale al sembrar de verdad. Y si el orden difiere, el texto difiere, y
         * si el texto difiere la huella difiere — con lo cual el seeder descartaría el vector
         * horneado y saldría a OpenAI en silencio, que es exactamente lo que este comando viene a
         * evitar. Contra la base, el texto es por construcción el mismo que arma producción.
         */
        $articulos = Article::with(['category', 'brand', 'descriptions'])
            ->where('user_id', $user_id)
            ->whereIn('name', array_column($catalogo, 'name'))
            ->get()
            ->keyBy('name');

        if ($articulos->count() < count($catalogo)) {
            $this->error(
                'semilla:embeddings: faltan artículos del catálogo en la base ('
                .$articulos->count().' de '.count($catalogo).').'
            );
            $this->line('Corré primero:  php artisan db:seed --class=FerreteriaArticlesSeeder');

            /*
             * El comando NO siembra por su cuenta a propósito: sembrar tiene efectos que no le
             * corresponden a un comando de horneado (crea stock, precios, movimientos, busca
             * imágenes). Que lo pida explícito es más aburrido y mucho más difícil de romper.
             */
            return 1;
        }

        /** Lo que había horneado antes, para poder saltear lo que no cambió. */
        $previo = $this->leer_archivo_previo();

        $forzar    = (bool) $this->option('forzar');
        $horneados = 0;
        $salteados = 0;
        $resultado = [];

        $this->info('semilla:embeddings: horneando '.count($catalogo).' artículos...');

        foreach ($catalogo as $item) {
            $nombre = $item['name'];

            /** @var Article $article */
            $article = $articulos->get($nombre);

            // El texto y la huella se calculan igual que en GenerateArticleEmbeddingJob: son el
            // contrato que después compara el seeder para decidir si el vector sigue vigente.
            $texto = $service->embedding_for_article($article);
            $hash  = sha1($texto);

            if (!$forzar
                && isset($previo[$nombre])
                && isset($previo[$nombre]['source_hash'])
                && $previo[$nombre]['source_hash'] === $hash) {

                // El texto no cambió desde el último horneado: se reusa el vector y no se paga.
                $resultado[$nombre] = $previo[$nombre];
                $salteados++;

                continue;
            }

            $vector = $service->generate_embedding($texto);

            $resultado[$nombre] = [
                'source_hash' => $hash,
                'embedding'   => $this->redondear($vector),
            ];

            $horneados++;

            $this->line('  horneado: '.$nombre);
        }

        $this->escribir_archivo($resultado);

        $peso_kb = round(filesize(self::ruta_del_archivo()) / 1024);

        $this->info('semilla:embeddings: listo. Horneados: '.$horneados.'. Salteados (sin cambios): '.$salteados.'.');
        $this->info('Archivo: '.self::ruta_del_archivo().' ('.$peso_kb.' KB)');

        return 0;
    }

    /**
     * Redondea cada componente del vector, para que el archivo no ocupe el doble por decimales
     * que no cambian ninguna búsqueda. Ver la constante DECIMALES.
     *
     * @param array $vector
     *
     * @return array
     */
    protected function redondear(array $vector)
    {
        $redondeado = [];

        foreach ($vector as $componente) {
            $redondeado[] = round((float) $componente, self::DECIMALES);
        }

        return $redondeado;
    }

    /**
     * Lee el archivo horneado anterior, si existe.
     *
     * @return array Mapa nombre => ['source_hash' => ..., 'embedding' => [...]].
     */
    protected function leer_archivo_previo()
    {
        $ruta = self::ruta_del_archivo();

        if (!is_file($ruta)) {
            return [];
        }

        $crudo = json_decode((string) file_get_contents($ruta), true);

        if (!is_array($crudo) || !isset($crudo['articulos']) || !is_array($crudo['articulos'])) {
            return [];
        }

        return $crudo['articulos'];
    }

    /**
     * Escribe el archivo con los vectores.
     *
     * 🔴 SIN `JSON_PRETTY_PRINT` PERO CON UNA LÍNEA POR ARTÍCULO, y las dos mitades importan.
     * Pretty print sobre 70.656 floats infla el archivo de ~700 KB a más de 3 MB, porque le pone
     * cada componente en su propio renglón. Pero volcarlo todo en una sola línea deja un archivo
     * que en `git diff` aparece como una única línea gigante modificada, sin poder ver qué
     * artículo cambió. Con un renglón por artículo, un rehorneado parcial muestra exactamente
     * cuáles se tocaron.
     *
     * @param array $articulos
     *
     * @return void
     */
    protected function escribir_archivo(array $articulos)
    {
        $ruta      = self::ruta_del_archivo();
        $directorio = dirname($ruta);

        if (!is_dir($directorio)) {
            mkdir($directorio, 0775, true);
        }

        $lineas = [];

        $lineas[] = '{';
        $lineas[] = '"modelo": "text-embedding-3-small",';
        $lineas[] = '"dimensiones": 1536,';
        $lineas[] = '"decimales": '.self::DECIMALES.',';
        $lineas[] = '"generado_at": '.json_encode(Carbon::now()->toIso8601String()).',';
        $lineas[] = '"articulos": {';

        /** Renglones de artículo, que se unen con coma para que el JSON quede válido. */
        $renglones = [];

        foreach ($articulos as $nombre => $datos) {
            $renglones[] = json_encode((string) $nombre, JSON_UNESCAPED_UNICODE)
                .': '
                .json_encode($datos, JSON_UNESCAPED_UNICODE);
        }

        $lineas[] = implode(",\n", $renglones);
        $lineas[] = '}';
        $lineas[] = '}';

        file_put_contents($ruta, implode("\n", $lineas)."\n");
    }
}
