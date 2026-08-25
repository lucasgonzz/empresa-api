<?php

namespace Database\Seeders;

use App\Console\Commands\HornearEmbeddingsDeSemilla;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\Seeders\ArticleSeederHelper;
use App\Models\Article;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Description;
use App\Models\Provider;
use App\Models\SubCategory;
use App\Observers\ArticleObserver;
use App\Services\ArticleEmbeddingService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FerreteriaArticlesSeeder extends Seeder
{
    /**
     * Carpeta dentro del disco 'public' donde viven las fotos del catalogo.
     * Queda afuera del repo sola, porque storage/app/public/.gitignore ignora todo
     * salvo a si mismo. Se llena copiando a mano los archivos que Lucas elige y sube
     * contra su base local (ver database/seeders/data/ferreteria_imagenes.php).
     *
     * @var string
     */
    const CARPETA_IMAGENES = 'article-images-2';

    /**
     * Stock inicial, minimo y maximo por sucursal, en orden de posicion
     * (0 = Tucuman, que es el deposito de origen).
     *
     * Existen tres perfiles y no un numero uniforme porque con el 100/50/120 que habia
     * antes los dos motores de sugerencias decian exactamente lo mismo sobre los 46
     * articulos, y una pantalla donde todo esta en rojo no muestra nada. Repartidos asi,
     * despues de que la semilla descuente las ventas del año: el perfil A queda por
     * debajo del minimo global y dispara compra Y traslado, el B solo traslado (le sobra
     * en el deposito y le falta en las puntas) y el C no dispara nada.
     *
     * El piso de 100 unidades en la sucursal mas chica no es decorativo: la cota de
     * consumo del peor par (articulo, sucursal) en el año sembrado es de 60 unidades, y
     * con menos que eso el stock terminaria en negativo.
     *
     * @var array<string,array<int,array<string,int>>>
     */
    const PLANES_DE_STOCK = [
        // "Hay que comprar": el global queda abajo del minimo global (571 < 670).
        'A' => [
            ['amount' => 260, 'stock_min' => 130, 'stock_max' => 390],
            ['amount' => 130, 'stock_min' => 180, 'stock_max' => 540],
            ['amount' => 115, 'stock_min' => 180, 'stock_max' => 540],
            ['amount' => 100, 'stock_min' => 180, 'stock_max' => 540],
        ],
        // "Solo traslado": sobra en el deposito de origen y falta en las dos ultimas.
        'B' => [
            ['amount' => 500, 'stock_min' => 60,  'stock_max' => 300],
            ['amount' => 160, 'stock_min' => 130, 'stock_max' => 390],
            ['amount' => 120, 'stock_min' => 130, 'stock_max' => 390],
            ['amount' => 100, 'stock_min' => 130, 'stock_max' => 390],
        ],
        // "Sano": ninguna sucursal baja del minimo, asi la corrida no propone mover el catalogo entero.
        'C' => [
            ['amount' => 300, 'stock_min' => 60, 'stock_max' => 300],
            ['amount' => 200, 'stock_min' => 80, 'stock_max' => 300],
            ['amount' => 180, 'stock_min' => 80, 'stock_max' => 300],
            ['amount' => 160, 'stock_min' => 80, 'stock_max' => 300],
        ],
    ];

    /**
     * Si ya se avisó por log que faltan las imagenes en esta corrida.
     *
     * @var bool
     */
    protected $aviso_de_imagenes_faltantes_emitido = false;

    /**
     * Ejecuta el seeder completo del rubro ferreteria.
     * Crea ocho categorias padre, subcategorias, marcas y articulos del catalogo con descripciones ecommerce.
     *
     * 🔴 TODA LA CORRIDA VA CON EL FLAG DE SEMILLA PRENDIDO, Y ESO NO ES OPCIONAL.
     *
     * ArticleObserver::debe_generar_embedding() es el punto unico donde deciden los dos
     * observers (Article y Description). Con el flag apagado, sembrar este catalogo dispara
     * 138 llamadas pagas a OpenAI: 46 articulos x 3 jobs cada uno, uno por Article::created
     * y dos por Description::created. Y los tres jobs de un mismo articulo ven textos
     * distintos —sin descripcion, con una, con las tres—, asi que el corte por
     * embedding_source_hash no salva ninguno: los tres pagan.
     *
     * El flag se apaga en un finally porque si el seeder revienta a mitad de camino no puede
     * quedar prendido: el resto del proceso (o el resto de la suite de tests) se quedaria
     * sin generar embeddings de nada, en silencio y sin sintoma.
     *
     * @return void
     */
    public function run()
    {
        ArticleObserver::empezar_semilla();

        try {
            $this->sembrar_catalogo();
        } finally {
            ArticleObserver::terminar_semilla();
        }
    }

    /**
     * Cuerpo real del seeder, separado de run() para que el finally del flag de semilla
     * envuelva la corrida entera sin un try gigante alrededor de todo el metodo.
     *
     * @return void
     */
    protected function sembrar_catalogo()
    {
        /** Catalogo base obtenido desde excels con codigos de barra validados. */
        $catalog = $this->get_catalog();

        /** Ocho categorias de primer nivel para repartir articulos en el ecommerce. */
        $categories_by_name = $this->get_or_create_ferreteria_parent_categories();

        /** Marcas comerciales que se asignan a los articulos del catalogo. */
        $brands_by_name = $this->get_or_create_brands($catalog);

        /** Subcategorias bajo cada categoria padre segun el mapeo del catalogo. */
        $this->get_or_create_sub_categories($categories_by_name, $catalog);

        /** Proveedores disponibles del usuario actual para repartir los articulos. */
        $providers = $this->get_available_providers();

        /** Helper de seeding para reutilizar la creacion estandar de articulos. */
        $article_helper = new ArticleSeederHelper();

        /** Indice circular para repartir items entre todos los proveedores existentes. */
        $provider_index = 0;

        foreach ($catalog as $item) {
            /** Proveedor asignado en forma rotativa para balancear el catalogo. */
            $provider = $providers[$provider_index % count($providers)];

            /** Codigo de barras EAN13 fijo por articulo para enlazar imagenes por barcode. */
            $bar_code = $item['bar_code'];

            /** Codigo interno del proveedor informado por la planilla fuente. */
            $provider_code = $item['provider_code'];

            /** Nombre de categoria padre coherente con la subcategoria del item. */
            $parent_category_name = $this->parent_category_name_for_sub($item['sub_category_name']);

            /** Payload del articulo segun estructura esperada por el helper. */
            $article_payload = [
                'name' => $item['name'],
                'bar_code' => $bar_code,
                'provider_code' => $provider_code,
                'provider_id' => $provider->id,
                'category_name' => $parent_category_name,
                'sub_category_name' => $item['sub_category_name'],
                'cost' => $item['cost'],
                'stock' => $item['stock'],
                'percentage_gain' => 50,
                'iva_id' => 2,
                'apply_provider_percentage_gain' => 0,
            ];

            /** Foto local del articulo; null cuando el articulo va sin foto o cuando falta el archivo. */
            $url_de_imagen = $this->url_de_imagen($item);

            if (!is_null($url_de_imagen)) {
                $article_payload['images'] = [['url' => $url_de_imagen]];
            }

            /**
             * Plan de stock por sucursal. Si queda en null, setStockMovement() se comporta
             * como siempre (100 / 50 / 120), que es lo que necesitan los otros llamadores.
             */
            $article_payload['stock_por_sucursal'] = $this->plan_de_stock($item);

            $article_payload = $article_helper->add_price_types($article_payload);

            /** Articulo persistido con campos base, pivot de proveedor y stock inicial. */
            $created_article = $article_helper->crear_article($article_payload);

            /** Marca comercial asociada al articulo para filtros y detalle de producto. */
            $created_article->brand_id = $brands_by_name[$item['brand_name']]->id;
            $created_article->save();

            /** Se calculan precios finales usando la logica central del sistema. */
            ArticleHelper::setFinalPrice($created_article, config('app.USER_ID'));

            /** Se generan descripciones pensadas para ficha ecommerce. */
            $this->create_descriptions($created_article->id, $item);

            /*
             * Va DESPUES de create_descriptions() y no antes: la huella se calcula sobre el
             * texto completo, que incluye las tres descripciones. Corrido para arriba,
             * ninguna huella coincidiria y los 46 articulos quedarian sin vector.
             */
            $this->aplicar_embedding_horneado($created_article, $item);

            $article_helper->setStockMovement($created_article, $article_payload);

            $provider_index++;
        }
    }

    /**
     * Obtiene proveedores existentes para el usuario.
     * Si no encuentra por user_id, usa cualquier proveedor disponible como fallback.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function get_available_providers()
    {
        /** Proveedores asociados al usuario actual. */
        $providers = Provider::where('user_id', config('app.USER_ID'))->orderBy('id', 'asc')->get();

        if ($providers->count() === 0) {
            /** Fallback para escenarios donde no hay providers del usuario configurado. */
            $providers = Provider::orderBy('id', 'asc')->get();
        }

        return $providers;
    }

    /**
     * Crea o reutiliza la categoria principal de ferreteria.
     *
     * @param string $name
     * @return \App\Models\Category
     */
    protected function get_or_create_category($name)
    {
        /** Proximo numero correlativo dentro de categorias del usuario. */
        $next_num = ((int)Category::where('user_id', config('app.USER_ID'))->max('num')) + 1;

        /** Categoria encontrada o creada para el rubro indicado. */
        $category = Category::firstOrCreate(
            [
                'user_id' => config('app.USER_ID'),
                'name' => $name,
            ],
            [
                'num' => $next_num,
            ]
        );

        /**
         * Imagen cuadrada remota si aun no hay imagen: no pisa cargas manuales ni re-seeds con imagen ya definida.
         */
        if (empty($category->image_url)) {
            $category->image_url = $this->build_square_remote_image_url('empresa-category-' . $name);
            $category->save();
        }

        return $category;
    }

    /**
     * Nombres de las ocho categorias padre del rubro ferreteria (ecommerce).
     *
     * @return array<int,string>
     */
    protected function ferreteria_parent_category_names()
    {
        return [
            'Electricidad e iluminacion',
            'Herramientas de mano y taller',
            'Jardineria y equipos',
            'Adhesivos y selladores',
            'Plomeria y banos',
            'Repuestos para maquinaria',
            'Hogar y organizacion',
            'Cerrajeria y montaje',
        ];
    }

    /**
     * Obtiene el nombre de categoria padre asociado a una subcategoria del catalogo.
     *
     * @param string|null $sub_category_name Valor sub_category_name del item.
     * @return string
     */
    protected function parent_category_name_for_sub($sub_category_name)
    {
        /** Mapeo subcategoria de planilla -> categoria padre del seeder. */
        static $sub_to_parent = [
            'Electricidad' => 'Electricidad e iluminacion',
            'Herramientas Manuales' => 'Herramientas de mano y taller',
            'Jardineria y Forestal' => 'Jardineria y equipos',
            'Adhesivos y Selladores' => 'Adhesivos y selladores',
            'Plomeria' => 'Plomeria y banos',
            'Repuestos y Accesorios' => 'Repuestos para maquinaria',
            'Ferreteria General' => 'Hogar y organizacion',
            'Cerrajeria y Montaje' => 'Cerrajeria y montaje',
        ];

        /** Clave normalizada para buscar en el mapa. */
        $key = trim((string) $sub_category_name);

        if ($key === '' || !isset($sub_to_parent[$key])) {
            return 'Hogar y organizacion';
        }

        return $sub_to_parent[$key];
    }

    /**
     * Crea o recupera las ocho categorias padre de ferreteria con imagen si corresponde.
     *
     * @return array<string,\App\Models\Category> Clave: nombre de categoria.
     */
    protected function get_or_create_ferreteria_parent_categories()
    {
        /** Indice nombre de categoria -> modelo persistido. */
        $categories_by_name = [];

        foreach ($this->ferreteria_parent_category_names() as $category_name) {
            $categories_by_name[$category_name] = $this->get_or_create_category($category_name);
        }

        return $categories_by_name;
    }

    /**
     * Crea o reutiliza subcategorias del catalogo bajo su categoria padre correspondiente.
     *
     * @param array<string,\App\Models\Category> $categories_by_name Categorias padre por nombre.
     * @param array<int,array<string,mixed>> $catalog
     * @return array<string,\App\Models\SubCategory> Subcategorias por nombre (unicas en el seed).
     */
    protected function get_or_create_sub_categories($categories_by_name, $catalog)
    {
        /** Mapa subcategoria por nombre para acceso rapido. */
        $sub_categories_by_name = [];

        /** Evita reprocesar el mismo par padre/sub. */
        $seen_pair_keys = [];

        foreach ($catalog as $item) {
            /** Nombre de subcategoria segun fila del catalogo. */
            $sub_category_name = trim((string) $item['sub_category_name']);

            if ($sub_category_name === '') {
                continue;
            }

            /** Categoria padre derivada del tipo de subcategoria. */
            $parent_name = $this->parent_category_name_for_sub($sub_category_name);

            /** Clave compuesta para deduplicar en este run. */
            $pair_key = $parent_name . "\0" . $sub_category_name;

            if (isset($seen_pair_keys[$pair_key])) {
                continue;
            }

            $seen_pair_keys[$pair_key] = true;

            /** Modelo de categoria padre; si falta, se omite la fila. */
            $category = isset($categories_by_name[$parent_name]) ? $categories_by_name[$parent_name] : null;

            if (is_null($category)) {
                continue;
            }

            /** Numero correlativo por categoria para mantener orden visual consistente. */
            $next_num = ((int)SubCategory::where('category_id', $category->id)->max('num')) + 1;

            /** Subcategoria persistida o reutilizada para evitar duplicados bajo el mismo padre. */
            $sub_category = SubCategory::firstOrCreate(
                [
                    'user_id' => config('app.USER_ID'),
                    'category_id' => $category->id,
                    'name' => $sub_category_name,
                ],
                [
                    'num' => $next_num,
                ]
            );

            $sub_categories_by_name[$sub_category_name] = $sub_category;
        }

        return $sub_categories_by_name;
    }

    /**
     * Crea o reutiliza marcas usadas por el catalogo de ferreteria.
     *
     * @param array<int,array<string,mixed>> $catalog
     * @return array<string,\App\Models\Brand>
     */
    protected function get_or_create_brands($catalog)
    {
        /** Lista dinamica de marcas en base a la data real de excels. */
        $brand_names = collect($catalog)
            ->pluck('brand_name')
            ->filter(function ($brand_name) {
                return trim((string)$brand_name) !== '';
            })
            ->unique()
            ->values()
            ->all();

        /** Mapa de marcas por nombre para asignacion directa en cada articulo. */
        $brands_by_name = [];

        foreach ($brand_names as $brand_name) {
            /** Numero correlativo para nuevas marcas del usuario actual. */
            $next_num = ((int)Brand::where('user_id', config('app.USER_ID'))->max('num')) + 1;

            /** Marca encontrada o creada segun el catalogo de ferreteria. */
            $brand = Brand::firstOrCreate(
                [
                    'user_id' => config('app.USER_ID'),
                    'name' => $brand_name,
                ],
                [
                    'num' => $next_num,
                ]
            );

            if (empty($brand->image_url)) {
                $brand->image_url = $this->build_square_remote_image_url('empresa-brand-' . $brand_name);
                $brand->save();
            }

            $brands_by_name[$brand_name] = $brand;
        }

        return $brands_by_name;
    }

    /**
     * Ruta del archivo con las descripciones a medida de los 46 articulos.
     *
     * @var string
     */
    const ARCHIVO_DESCRIPCIONES = __DIR__ . '/data/ferreteria_descripciones.php';

    /**
     * Descripciones ya leidas del disco, para no hacer un require por articulo.
     *
     * @var array<string,array<int,array<string,string>>>|null
     */
    protected $descripciones_del_catalogo = null;

    /**
     * Vectores horneados, leidos una sola vez por corrida desde el JSON del repo.
     *
     * @var array<string,array<string,mixed>>|null
     */
    protected $embeddings_horneados = null;

    /**
     * Crea las descripciones a medida del articulo, en el orden en que estan escritas.
     *
     * 🔴 EL ORDEN DE INSERCION ES PARTE DEL TEXTO QUE SE VECTORIZA. Cada Description se
     * crea en el orden del array del archivo de datos, el orden de creacion define los id,
     * y ArticleEmbeddingService::descriptions_text() ordena por id para armar el texto que
     * se manda a OpenAI y se hashea. O sea que reordenar los tres bloques de un articulo
     * cambia el texto, cambia el sha1 y obliga a rehornear el vector con
     * `php artisan semilla:embeddings`. No es un detalle de presentacion.
     *
     * ¿POR QUE EL INDICE DEL ARCHIVO DE DATOS ES EL name Y NO OTRA COSA?
     *
     * - No por indice numerico: reordenar o intercalar una fila en get_catalog() correria
     *   todo el mapeo y cada articulo quedaria con la descripcion del vecino, sin ningun
     *   error a la vista. El sintoma seria un catalogo entero mal descripto y un agente de
     *   IA contestando cualquier cosa.
     * - No por bar_code: tres articulos lo tienen vacio (escobillon GARDEX, timbre CANDELA
     *   y cinta TACSA), asi que colisionarian entre ellos en la misma clave ''.
     * - No por provider_code: hay un 'SIN-COD-PROV' en el catalogo, que es un valor de
     *   relleno y no un identificador.
     *
     * El acoplamiento por nombre es el mismo que ya denuncia archivo_de_imagen_de():
     * si alguien retoca un name en get_catalog() y no lo retoca aca, este metodo no
     * encuentra la entrada. Por eso avisa por log y sigue, en vez de reventar.
     *
     * @param int $article_id
     * @param array<string,mixed> $item Fila del catalogo.
     * @return void
     */
    protected function create_descriptions($article_id, $item)
    {
        /** Nombre exacto del catalogo, que es la clave del archivo de datos. */
        $nombre = $item['name'];

        /** Bloques escritos a mano para este articulo; array vacio si no hay entrada. */
        $bloques = $this->descripciones_de($nombre);

        if (count($bloques) === 0) {
            /*
             * Mismo criterio que avisar_imagenes_faltantes(): un articulo sin descripciones
             * es un dato cosmetico incompleto, y este seeder corre adentro de
             * DemoSetupHelper::run(), que instala clientes reales sobre un migrate:fresh.
             * Voltear la instalacion entera por un texto que falta seria peor que crear el
             * articulo sin el. El unico costo real es que ese articulo va a quedar con un
             * embedding pobre hasta que alguien agregue la entrada.
             */
            Log::warning(
                'FerreteriaArticlesSeeder: no hay descripciones a medida para "' . $nombre . '", '
                . 'el articulo se crea SIN descripciones. El archivo '
                . 'database/seeders/data/ferreteria_descripciones.php se indexa por el name EXACTO '
                . 'de get_catalog(): si se retoco un nombre en el catalogo, hay que retocarlo alla '
                . 'tambien y despues rehornear con "php artisan semilla:embeddings".',
                ['name' => $nombre, 'article_id' => $article_id]
            );

            return;
        }

        foreach ($bloques as $bloque) {
            Description::create([
                'title' => $bloque['title'],
                'content' => $bloque['content'],
                'article_id' => $article_id,
            ]);
        }
    }

    /**
     * Le copia al articulo el vector ya horneado que viaja commiteado en el repo, en vez de
     * salir a generarlo.
     *
     * Es la otra mitad del flag de semilla: el flag evita que se DESPACHEN los jobs, y esto
     * hace que el articulo quede igual de utilizable que si los jobs hubieran corrido. Sin
     * esto, el catalogo de la demo quedaria sin vectores y el agente de WhatsApp no
     * encontraria un solo articulo hasta que corriera el comando agendado — y en una demo sin
     * OPENAI_API_KEY configurada, nunca.
     *
     * 🔴 SI LA HUELLA NO COINCIDE, NO SE ESCRIBE NADA. Esta es la decision importante del
     * metodo y la que hay que defender, porque la tentacion de escribir el vector igual
     * ("total es casi el mismo texto") produce un error que despues no se cura solo. Las tres
     * salidas posibles cuando alguien edito una descripcion y no rehorneo:
     *
     *   1. Vector viejo + huella NUEVA: GenerateArticleEmbeddingJob corta por huella y no lo
     *      regenera JAMAS. El agente busca para siempre contra un vector que no corresponde al
     *      texto del articulo, sin ningun sintoma visible.
     *   2. Vector viejo + huella VIEJA: el comando agendado lo regenera en el proximo ciclo,
     *      pero hasta entonces la busqueda usa un vector que miente.
     *   3. Nada (lo que hace este metodo): el articulo queda con embedding NULL, que es
     *      exactamente el filtro con el que articles:generate-embeddings lo levanta. Se
     *      regenera solo en el proximo ciclo y mientras tanto simplemente no aparece en la
     *      busqueda semantica, que es lo honesto.
     *
     * Un vector que falta se cura solo; uno que miente se queda.
     *
     * @param \App\Models\Article $article Articulo recien creado, con sus descripciones ya cargadas.
     * @param array<string,mixed> $item Fila del catalogo.
     * @return void
     */
    protected function aplicar_embedding_horneado($article, $item)
    {
        /** Datos horneados para este articulo; null si el archivo no lo tiene. */
        $horneado = $this->embedding_horneado_de($item['name']);

        if (is_null($horneado)) {
            Log::warning(
                'FerreteriaArticlesSeeder: no hay embedding horneado para "' . $item['name'] . '", '
                . 'el articulo queda sin vector hasta que corra articles:generate-embeddings. '
                . 'Para hornearlo: php artisan semilla:embeddings',
                ['name' => $item['name'], 'article_id' => $article->id]
            );

            return;
        }

        /** @var ArticleEmbeddingService $service */
        $service = app(ArticleEmbeddingService::class);

        /*
         * Se recarga el articulo desde la base en vez de usar el que viene por parametro: el
         * texto que se vectoriza incluye categoria, marca y las tres descripciones, y esas
         * relaciones no estan cargadas en la instancia que devolvio crear_article(). Sin la
         * recarga, embedding_for_article() armaria un texto sin descripciones y la huella no
         * coincidiria nunca con la horneada.
         */
        $fresco = Article::with(['category', 'brand', 'descriptions'])->find($article->id);

        if (is_null($fresco)) {
            return;
        }

        $hash = sha1($service->embedding_for_article($fresco));

        if ($hash !== $horneado['source_hash']) {
            Log::warning(
                'FerreteriaArticlesSeeder: el embedding horneado de "' . $item['name'] . '" quedo viejo '
                . '(cambio el nombre, la marca, la categoria o alguna descripcion). El articulo queda '
                . 'SIN vector a proposito, para no dejar guardado uno que no corresponde al texto. '
                . 'Rehorneá con: php artisan semilla:embeddings',
                ['name' => $item['name'], 'article_id' => $article->id]
            );

            return;
        }

        $service->persistir_embedding($article->id, $horneado['embedding']);

        /*
         * La huella se guarda junto con el vector, y es lo que hace que esto no se pague nunca
         * mas: el proximo GenerateArticleEmbeddingJob que agarre este articulo va a recalcular
         * el texto, ver la misma huella y cortar antes de llamar a OpenAI.
         */
        DB::table('articles')
            ->where('id', $article->id)
            ->update([
                'embedding_generated_at' => Carbon::now(),
                'embedding_source_hash'  => $hash,
            ]);
    }

    /**
     * Vector horneado de un articulo del catalogo, o null si no esta en el archivo.
     *
     * El archivo se lee UNA sola vez por corrida, igual que el de descripciones: son ~700 KB
     * de JSON y decodificarlo 46 veces seria gratuito de evitar.
     *
     * @param string $nombre Valor exacto de $item['name'].
     * @return array<string,mixed>|null
     */
    protected function embedding_horneado_de($nombre)
    {
        if (is_null($this->embeddings_horneados)) {
            $ruta = HornearEmbeddingsDeSemilla::ruta_del_archivo();

            /** Contenido decodificado del archivo, o array vacio si todavia no se horneo nunca. */
            $crudo = is_file($ruta)
                ? json_decode((string) file_get_contents($ruta), true)
                : null;

            $this->embeddings_horneados = (is_array($crudo) && isset($crudo['articulos']) && is_array($crudo['articulos']))
                ? $crudo['articulos']
                : [];
        }

        if (!isset($this->embeddings_horneados[$nombre])
            || !isset($this->embeddings_horneados[$nombre]['source_hash'])
            || !isset($this->embeddings_horneados[$nombre]['embedding'])) {

            return null;
        }

        return $this->embeddings_horneados[$nombre];
    }

    /**
     * Bloques de descripcion escritos a mano para un articulo del catalogo.
     *
     * El archivo se lee UNA sola vez por corrida y queda en memoria: son 46 entradas de
     * texto y hacer un require por articulo seria leer el mismo archivo 46 veces.
     *
     * @param string $nombre Valor exacto de $item['name'].
     * @return array<int,array<string,string>> Vacio si no hay entrada para ese nombre.
     */
    protected function descripciones_de($nombre)
    {
        if (is_null($this->descripciones_del_catalogo)) {
            $this->descripciones_del_catalogo = require self::ARCHIVO_DESCRIPCIONES;
        }

        if (!isset($this->descripciones_del_catalogo[$nombre])) {
            return [];
        }

        return $this->descripciones_del_catalogo[$nombre];
    }

    /**
     * Construye una URL de imagen remota con proporcion 1:1 (ancho y alto iguales).
     * Usa picsum.photos con semilla derivada del fragmento para resultados estables por categoria/marca.
     *
     * @param string $seed_fragment Texto base (nombre de categoria, marca, etc.).
     * @return string URL HTTPS lista para guardar en image_url.
     */
    protected function build_square_remote_image_url($seed_fragment)
    {
        /** Semilla acotada y sin caracteres problematicos para la ruta del servicio. */
        $seed = md5((string) $seed_fragment);

        return 'https://picsum.photos/seed/' . $seed . '/512/512';
    }

    /**
     * Ruta del archivo con el mapa nombre de articulo -> archivo de imagen.
     *
     * @var string
     */
    const ARCHIVO_IMAGENES = __DIR__ . '/data/ferreteria_imagenes.php';

    /**
     * Mapa nombre de articulo -> archivo de imagen, leido una sola vez por corrida.
     *
     * @var array<string,string>|null
     */
    protected $imagenes_del_catalogo = null;

    /**
     * Nombre del archivo de imagen asignado a mano para este articulo, o null si el
     * articulo no tiene foto nueva asignada.
     *
     * El archivo se lee UNA sola vez por corrida, mismo criterio que descripciones_de()
     * y embedding_horneado_de(): son 34 entradas y hacer un require por articulo seria
     * leer el mismo archivo 46 veces.
     *
     * @param string $nombre Valor exacto de $item['name'].
     * @return string|null
     */
    protected function archivo_de_imagen_de($nombre)
    {
        if (is_null($this->imagenes_del_catalogo)) {
            $this->imagenes_del_catalogo = require self::ARCHIVO_IMAGENES;
        }

        if (!isset($this->imagenes_del_catalogo[$nombre])) {
            return null;
        }

        return $this->imagenes_del_catalogo[$nombre];
    }

    /**
     * URL absoluta de la foto local del articulo, o null si no hay foto que mostrar.
     *
     * 🔴 Este metodo NUNCA puede lanzar una excepcion, y ese es todo su motivo de existir.
     * La carpeta storage/app/public/article-images-2 no se commitea, asi que en un servidor
     * recien instalado no existe. Este seeder corre adentro de DemoSetupHelper::run(), que
     * arranca con migrate:fresh: una excepcion aca por un archivo faltante voltearia el alta
     * de demos Y la instalacion de un cliente real, dejando la base a medio sembrar. Una foto
     * que falta es un detalle cosmetico; que no se pueda instalar el sistema no lo es.
     *
     * @param array<string,mixed> $item
     * @return string|null
     */
    protected function url_de_imagen($item)
    {
        /** Archivo asignado a mano; null en los articulos que no tienen foto nueva. */
        $archivo = $this->archivo_de_imagen_de($item['name']);

        if (is_null($archivo)) {
            return null;
        }

        /** Ruta relativa al disco 'public'. */
        $ruta = self::CARPETA_IMAGENES . '/' . $archivo;

        /*
         * El try/catch es a proposito mas ancho que el modo de falla conocido (el archivo
         * que no esta, que exists() ya resuelve devolviendo false sin tirar): cubre tambien
         * un disco mal configurado o un APP_URL vacio, que en un servidor nuevo es
         * justamente lo que puede pasar. Cualquiera de esos casos tiene que terminar en
         * "articulo sin foto", nunca en corrida abortada.
         */
        try {
            if (!Storage::disk('public')->exists($ruta)) {
                $this->avisar_imagenes_faltantes($ruta);

                return null;
            }

            return Storage::disk('public')->url($ruta);
        } catch (\Throwable $e) {
            $this->avisar_imagenes_faltantes($ruta . ' — ' . get_class($e) . ': ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Avisa por log, UNA sola vez por corrida, que los articulos se estan creando sin foto.
     *
     * Una linea por articulo serian 36 warnings identicos en cada db:seed, y un log que
     * repite 36 veces lo mismo es un log que nadie lee. Con una sola linea el que instala
     * se entera de que le falta subir la carpeta y de como generarla.
     *
     * @param string $detalle Primer faltante detectado, para poder ir a mirar.
     * @return void
     */
    protected function avisar_imagenes_faltantes($detalle)
    {
        if ($this->aviso_de_imagenes_faltantes_emitido) {
            return;
        }

        $this->aviso_de_imagenes_faltantes_emitido = true;

        Log::warning(
            'FerreteriaArticlesSeeder: no se encontraron las fotos en storage/app/public/'
            . self::CARPETA_IMAGENES . ', los articulos se crean SIN imagen. La carpeta no se '
            . 'commitea: copiala a mano desde donde Lucas subio las fotos elegidas (ver '
            . 'database/seeders/data/ferreteria_imagenes.php) y a los servidores se sube igual.',
            ['primer_faltante' => $detalle]
        );
    }

    /**
     * Plan de stock por sucursal de un articulo, segun su perfil.
     *
     * @param array<string,mixed> $item Fila del catalogo.
     * @return array<int,array<string,int>>|null Null si la fila no declara un perfil conocido.
     */
    protected function plan_de_stock($item)
    {
        if (!isset($item['perfil_stock']) || !isset(self::PLANES_DE_STOCK[$item['perfil_stock']])) {
            return null;
        }

        return self::PLANES_DE_STOCK[$item['perfil_stock']];
    }

    /**
     * Retorna catalogo de articulos de ferreteria obtenido de excels.
     * Cada entrada incluye nombre, bar_code valido, codigo proveedor y costo redondeado.
     *
     * Tres claves agregadas para la semilla de demo:
     *  - imagen_nombre / imagen_busqueda: 🔴 HISTORICAS, YA NO SE USAN (desde el 25/8/2026).
     *    Eran el slug en castellano y el termino en ingles con los que el comando
     *    `semilla:imagenes` (borrado) bajaba fotos de Wikimedia Commons a
     *    storage/app/public/articles-seeder. Ese flujo se reemplazo por fotos elegidas a mano
     *    por Lucas (ver database/seeders/data/ferreteria_imagenes.php, indexado por `name`).
     *    Quedan en el catalogo sin limpiar porque sacarlas es un diff de 40+ filas sin ningun
     *    beneficio: no las lee nada.
     *  - perfil_stock: A / B / C, ver PLANES_DE_STOCK. Vive al lado del articulo en vez de
     *    derivarse del indice en run() porque asi se lee de un vistazo cual es cual.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_catalog()
    {
        return [
            ['name' => 'CESTO DE BASURA CON PORTA PAPEL GRIS STOLF', 'bar_code' => '7897996714874', 'provider_code' => '61779', 'cost' => 18666, 'stock' => 40, 'sub_category_name' => 'Ferreteria General', 'brand_name' => 'STOLF', 'imagen_nombre' => 'cesto-de-basura', 'imagen_busqueda' => 'trash bin', 'perfil_stock' => 'A'],
            ['name' => 'CESTO DE BASURA CON PORTA PAPEL NEGRO STOLF', 'bar_code' => '7897996716014', 'provider_code' => '61782', 'cost' => 18666, 'stock' => 40, 'sub_category_name' => 'Ferreteria General', 'brand_name' => 'STOLF', 'imagen_nombre' => 'cesto-de-basura', 'imagen_busqueda' => 'pedal bin', 'perfil_stock' => 'A'],
            ['name' => 'ESCOBILLON 375 X 45MM CERDA RIGIDA GARDEX', 'bar_code' => '', 'provider_code' => '35641', 'cost' => 3557, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'GARDEX', 'imagen_nombre' => 'escobillon', 'imagen_busqueda' => 'push broom', 'perfil_stock' => 'A'],
            ['name' => 'DRIVER PARA PANEL LED 24W ETHEOS', 'bar_code' => '7798351081283', 'provider_code' => 'SIN-COD-PROV', 'cost' => 2267, 'stock' => 40, 'sub_category_name' => 'Electricidad', 'brand_name' => 'ETHEOS', 'imagen_nombre' => 'driver-para-panel-led', 'imagen_busqueda' => 'led power supply', 'perfil_stock' => 'A'],
            ['name' => 'DUCHA DE MANO METAL GLOA', 'bar_code' => '7798367551572', 'provider_code' => '23408', 'cost' => 17539, 'stock' => 40, 'sub_category_name' => 'Plomeria', 'brand_name' => 'Generica', 'imagen_nombre' => 'ducha-de-mano', 'imagen_busqueda' => 'shower head bathroom', 'perfil_stock' => 'A'],
            ['name' => 'LAMPARA DICROICA LEDS 7W GU10 LUZ DIA NO / DIMERIZABLE CANDELA', 'bar_code' => '7798347081013', 'provider_code' => 'NL-D71065D', 'cost' => 2632, 'stock' => 40, 'sub_category_name' => 'Electricidad', 'brand_name' => 'CANDELA', 'imagen_nombre' => 'lampara-dicroica', 'imagen_busqueda' => 'GU10 lamp', 'perfil_stock' => 'A'],
            ['name' => 'MODULO HUSQVARNA (BOBINA)  236/235E/240/136/137/ POULAN 295/2600/2750/2775/2900', 'bar_code' => '7393080841094', 'provider_code' => '5803501', 'cost' => 24, 'stock' => 40, 'sub_category_name' => 'Repuestos y Accesorios', 'brand_name' => 'HUSQVARNA', 'imagen_nombre' => 'modulo-de-encendido', 'imagen_busqueda' => 'ignition module', 'perfil_stock' => 'A'],
            ['name' => 'JUNTA KOHLER TAPA VALVULAS XT650-XT675 - 1404101-S', 'bar_code' => '11404101', 'provider_code' => '1404101', 'cost' => 4, 'stock' => 40, 'sub_category_name' => 'Repuestos y Accesorios', 'brand_name' => 'KOHLER', 'imagen_nombre' => 'junta-de-tapa-de-valvulas', 'imagen_busqueda' => 'engine gasket', 'perfil_stock' => 'A'],
            ['name' => 'FILTRO OREGON DE AIRE P/ B&S 12.5/13.5 MOD, VIEJOS OVALADO', 'bar_code' => '5400182524991', 'provider_code' => '55.30-049', 'cost' => 12, 'stock' => 40, 'sub_category_name' => 'Repuestos y Accesorios', 'brand_name' => 'OREGON', 'imagen_nombre' => 'filtro-de-aire', 'imagen_busqueda' => 'engine air filter', 'perfil_stock' => 'A'],
            ['name' => 'PIEDRA TECOMEC 145 X3.2 X 22.2MM P/ AFILADORA DE CADENA', 'bar_code' => '8032706107594', 'provider_code' => '13.K00204005', 'cost' => 21, 'stock' => 40, 'sub_category_name' => 'Jardineria y Forestal', 'brand_name' => 'TECOMEC', 'imagen_nombre' => 'piedra-de-afilar', 'imagen_busqueda' => 'abrasive disc', 'perfil_stock' => 'A'],
            ['name' => 'FILTRO OREGON AIRE P/ KOHLER SEMI RECTANGULAR C/ PREFILTRO (32-883-03-S1)', 'bar_code' => '032488301300', 'provider_code' => '55.30-130', 'cost' => 10, 'stock' => 40, 'sub_category_name' => 'Repuestos y Accesorios', 'brand_name' => 'OREGON', 'imagen_nombre' => 'filtro-de-aire', 'imagen_busqueda' => 'paper air filter', 'perfil_stock' => 'A'],
            ['name' => 'WP 230 STIHL BOMBA DE AGUA', 'bar_code' => '886661621811', 'provider_code' => 'VB02-011-2000', 'cost' => 241, 'stock' => 40, 'sub_category_name' => 'Jardineria y Forestal', 'brand_name' => 'STIHL', 'imagen_nombre' => 'bomba-de-agua', 'imagen_busqueda' => 'centrifugal water pump', 'perfil_stock' => 'A'],
            ['name' => 'VOLANTE STIHL MS210 / 250 / 021 / 025', 'bar_code' => '795711514785', 'provider_code' => '1123-400-1203', 'cost' => 27, 'stock' => 40, 'sub_category_name' => 'Jardineria y Forestal', 'brand_name' => 'STIHL', 'imagen_nombre' => 'volante-de-motor', 'imagen_busqueda' => 'dual mass flywheel', 'perfil_stock' => 'B'],
            ['name' => 'W80 LUBRICANTE MULTIUSO CON PTFE 250ML AEROSOL', 'bar_code' => '7790711442208', 'provider_code' => '500000', 'cost' => 3, 'stock' => 40, 'sub_category_name' => 'Adhesivos y Selladores', 'brand_name' => 'SILOC', 'imagen_nombre' => 'lubricante-en-aerosol', 'imagen_busqueda' => 'lubricant spray', 'perfil_stock' => 'B'],
            ['name' => 'LLAVE TERMICA SICA 1X25A.', 'bar_code' => '7791772051781', 'provider_code' => '782125', 'cost' => 4060, 'stock' => 40, 'sub_category_name' => 'Electricidad', 'brand_name' => 'SICA', 'imagen_nombre' => 'llave-termica', 'imagen_busqueda' => 'circuit breaker', 'perfil_stock' => 'B'],
            ['name' => 'TUBO DE LED 9W LUZ DIA 6500K 60CM CANDELA', 'bar_code' => '7798347080979', 'provider_code' => 'NL-TG9WB', 'cost' => 2352, 'stock' => 40, 'sub_category_name' => 'Electricidad', 'brand_name' => 'CANDELA', 'imagen_nombre' => 'tubo-led', 'imagen_busqueda' => 'led tube', 'perfil_stock' => 'B'],
            ['name' => 'PINZA CRIMPEADORA P/CONECTOR COAXIL RG59/6 SNAP-TIPO F', 'bar_code' => '7790483000880', 'provider_code' => 'CRI0507', 'cost' => 29584, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'Generica', 'imagen_nombre' => 'pinza-crimpeadora', 'imagen_busqueda' => 'crimping pliers', 'perfil_stock' => 'B'],
            ['name' => 'LAMPARA MESH BLACK CUBO 4W CALIDA FILAMENTO CANDELA', 'bar_code' => '7798347087435', 'provider_code' => '7922', 'cost' => 19193, 'stock' => 40, 'sub_category_name' => 'Electricidad', 'brand_name' => 'CANDELA', 'imagen_nombre' => 'lampara-de-filamento', 'imagen_busqueda' => 'edison bulb', 'perfil_stock' => 'B'],
            ['name' => 'CINTA PASACABLE DE ACERO X 30 METROS KALOP', 'bar_code' => '7793863750726', 'provider_code' => 'KL29130', 'cost' => 23810, 'stock' => 40, 'sub_category_name' => 'Electricidad', 'brand_name' => 'KALOP', 'imagen_nombre' => 'cinta-pasacable', 'imagen_busqueda' => 'fish tape', 'perfil_stock' => 'B'],
            ['name' => 'CAJA DERIVACION PVC 16x18X8 GEN-ROD', 'bar_code' => '7798304381927', 'provider_code' => '061618', 'cost' => 11691, 'stock' => 40, 'sub_category_name' => 'Electricidad', 'brand_name' => 'Generica', 'imagen_nombre' => 'caja-de-derivacion', 'imagen_busqueda' => 'electrical junction box', 'perfil_stock' => 'B'],
            ['name' => 'AZADA BELLOTA  S/CABO 2.1/2', 'bar_code' => '7702956228783', 'provider_code' => '54750', 'cost' => 29767, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'BELLOTA', 'imagen_nombre' => 'azada', 'imagen_busqueda' => 'garden hoe', 'perfil_stock' => 'B'],
            ['name' => 'FOCO LED 12W LUZ FRIA NOVAELETRICITY', 'bar_code' => '7798174312205', 'provider_code' => 'NL-A122765B', 'cost' => 1267, 'stock' => 40, 'sub_category_name' => 'Electricidad', 'brand_name' => 'NOVAELETRICITY', 'imagen_nombre' => 'foco-led', 'imagen_busqueda' => 'LED lamp', 'perfil_stock' => 'B'],
            ['name' => 'AZADA TRAMONTINA S/CABO 2.0', 'bar_code' => '7891117001768', 'provider_code' => '33901', 'cost' => 13000, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'TRAMONTINA', 'imagen_nombre' => 'azada', 'imagen_busqueda' => 'dutch hoe', 'perfil_stock' => 'B'],
            ['name' => 'Fraccionadora de Cinta con Mango Anatomico reforzado', 'bar_code' => '7796524810135', 'provider_code' => 'MA0083', 'cost' => 12475, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'Generica', 'imagen_nombre' => 'fraccionadora-de-cinta', 'imagen_busqueda' => 'packing tape dispenser', 'perfil_stock' => 'B'],
            ['name' => 'LLAVE ALLEN 9 PIERZAS JUSTER CORTO', 'bar_code' => '6972434502822', 'provider_code' => '01860', 'cost' => 12742, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'JUSTER', 'imagen_nombre' => 'llave-allen', 'imagen_busqueda' => 'hex key set', 'perfil_stock' => 'B'],
            ['name' => 'LLAVE TORX 9 PIEZAS JUSTER LARGO', 'bar_code' => '6972434508237', 'provider_code' => '01857', 'cost' => 12742, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'JUSTER', 'imagen_nombre' => 'llave-torx', 'imagen_busqueda' => 'torx wrench', 'perfil_stock' => 'B'],
            ['name' => 'LIMPIADOR QUITA OXIDO (FOSFATIZANTE) X 1LT', 'bar_code' => '7798120640703', 'provider_code' => '7200', 'cost' => 4647, 'stock' => 40, 'sub_category_name' => 'Adhesivos y Selladores', 'brand_name' => 'Generica', 'imagen_nombre' => 'quita-oxido', 'imagen_busqueda' => 'rust remover bottle', 'perfil_stock' => 'B'],
            ['name' => 'FLEXIBLE PARA DUCHA 1/2 X 1.50MTS CROMO TGFLEX', 'bar_code' => '7798430911296', 'provider_code' => '2780', 'cost' => 6020, 'stock' => 40, 'sub_category_name' => 'Plomeria', 'brand_name' => 'TGFLEX', 'imagen_nombre' => 'flexible-de-ducha', 'imagen_busqueda' => 'shower hose', 'perfil_stock' => 'B'],
            ['name' => 'ESPUMA POLIURETANO 300ML DOGO', 'bar_code' => '7798331914389', 'provider_code' => '4226', 'cost' => 5876, 'stock' => 40, 'sub_category_name' => 'Adhesivos y Selladores', 'brand_name' => 'DOGO', 'imagen_nombre' => 'espuma-de-poliuretano', 'imagen_busqueda' => 'expanding foam', 'perfil_stock' => 'B'],
            ['name' => 'MECHA PARA CERAMICA Y AZULEJOS 10 MM  EXPERT BOSCH', 'bar_code' => '3165140599269', 'provider_code' => '58136', 'cost' => 10008, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'BOSCH', 'imagen_nombre' => 'mecha-para-ceramica', 'imagen_busqueda' => 'tile drill bit', 'perfil_stock' => 'B'],
            ['name' => 'ESPATULA ENDUIR 140MM BIASSONI', 'bar_code' => '7798172005758', 'provider_code' => '50307', 'cost' => 4164, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'BIASSONI', 'imagen_nombre' => 'espatula', 'imagen_busqueda' => 'putty knife', 'perfil_stock' => 'B'],
            ['name' => 'ESPATULA PARA JUNTAS 150MM CONST. EN SECO BIASSONI', 'bar_code' => '7798312201927', 'provider_code' => '55339', 'cost' => 7706, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'BIASSONI', 'imagen_nombre' => 'espatula-para-juntas', 'imagen_busqueda' => 'putty knife', 'perfil_stock' => 'B'],
            ['name' => 'LLAVE T 10MM GARDEX', 'bar_code' => '7798431864652', 'provider_code' => '05486', 'cost' => 2957, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'GARDEX', 'imagen_nombre' => 'llave-t', 'imagen_busqueda' => 'socket wrench', 'perfil_stock' => 'B'],
            ['name' => 'DISCO DIAMANTADO KEX 3 EN 1 9\"', 'bar_code' => '7798312162204', 'provider_code' => '06757', 'cost' => 18788, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'KEX', 'imagen_nombre' => 'disco-diamantado', 'imagen_busqueda' => 'diamond blade', 'perfil_stock' => 'B'],
            ['name' => 'SOPORTE D/PARED PARA TV,RECLINABLE 43 X 100,ONEBOX', 'bar_code' => '714604320692', 'provider_code' => '40257', 'cost' => 18894, 'stock' => 40, 'sub_category_name' => 'Cerrajeria y Montaje', 'brand_name' => 'ONEBOX', 'imagen_nombre' => 'soporte-de-pared-para-tv', 'imagen_busqueda' => 'VESA mount', 'perfil_stock' => 'C'],
            ['name' => 'DISCO  KEX FIBRA REMOVEDOR 115x2.2 mm', 'bar_code' => '7798431865772', 'provider_code' => '55278', 'cost' => 4368, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'KEX', 'imagen_nombre' => 'disco-de-corte', 'imagen_busqueda' => 'grinding disc', 'perfil_stock' => 'C'],
            ['name' => 'PRECINTO 200 x 4.8', 'bar_code' => '7798312160590', 'provider_code' => '59004', 'cost' => 4140, 'stock' => 40, 'sub_category_name' => 'Repuestos y Accesorios', 'brand_name' => 'BROQUEL', 'imagen_nombre' => null, 'imagen_busqueda' => null, 'perfil_stock' => 'C'],
            ['name' => 'PRECINTO 400 x 4.8', 'bar_code' => '7798312160651', 'provider_code' => '59007', 'cost' => 9018, 'stock' => 40, 'sub_category_name' => 'Repuestos y Accesorios', 'brand_name' => 'BROQUEL', 'imagen_nombre' => null, 'imagen_busqueda' => null, 'perfil_stock' => 'C'],
            ['name' => 'CERRADURA PRIVE  DESTRABADOR ELECTRICO 122 8 A 12 VOLTS - 5WATTS', 'bar_code' => '7796011707160', 'provider_code' => '07180', 'cost' => 19359, 'stock' => 40, 'sub_category_name' => 'Cerrajeria y Montaje', 'brand_name' => 'Generica', 'imagen_nombre' => null, 'imagen_busqueda' => null, 'perfil_stock' => 'C'],
            ['name' => 'ESPATULA 70mm REMACHADO M/MADERA BIASSONI', 'bar_code' => '7798312201712', 'provider_code' => '55366', 'cost' => 3712, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'BIASSONI', 'imagen_nombre' => null, 'imagen_busqueda' => null, 'perfil_stock' => 'C'],
            ['name' => 'ESPATULA 80mm REMACHADO M/MADERA BIASSONI', 'bar_code' => '7798312201729', 'provider_code' => '55367', 'cost' => 3846, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'BIASSONI', 'imagen_nombre' => null, 'imagen_busqueda' => null, 'perfil_stock' => 'C'],
            ['name' => 'PISTOLA APLICADORA GARDEX P/ADHESIVO', 'bar_code' => '7798431860883', 'provider_code' => '06261', 'cost' => 5729, 'stock' => 40, 'sub_category_name' => 'Adhesivos y Selladores', 'brand_name' => 'GARDEX', 'imagen_nombre' => null, 'imagen_busqueda' => null, 'perfil_stock' => 'C'],
            ['name' => 'SOPAPA AMERICANA 9 CM AC INOX', 'bar_code' => '7798431864461', 'provider_code' => '34400', 'cost' => 2957, 'stock' => 40, 'sub_category_name' => 'Plomeria', 'brand_name' => 'BLU', 'imagen_nombre' => null, 'imagen_busqueda' => null, 'perfil_stock' => 'C'],
            ['name' => 'SOPAPA AMERICANA 11 CM AC INOX C/REJILLA BLU', 'bar_code' => '7798431864478', 'provider_code' => '34401', 'cost' => 3172, 'stock' => 40, 'sub_category_name' => 'Plomeria', 'brand_name' => 'BLU', 'imagen_nombre' => null, 'imagen_busqueda' => null, 'perfil_stock' => 'C'],
            ['name' => 'TIMBRE INALAMBRICO REDONDO CANDELA', 'bar_code' => '', 'provider_code' => '35504', 'cost' => 6323, 'stock' => 40, 'sub_category_name' => 'Electricidad', 'brand_name' => 'CANDELA', 'imagen_nombre' => null, 'imagen_busqueda' => null, 'perfil_stock' => 'C'],
            ['name' => 'CINTA MULTIPROPOSITO BLANCO 48MM X 9Mts. TACSA DUCTAC', 'bar_code' => '', 'provider_code' => '07051', 'cost' => 2739, 'stock' => 40, 'sub_category_name' => 'Adhesivos y Selladores', 'brand_name' => 'TACSA', 'imagen_nombre' => null, 'imagen_busqueda' => null, 'perfil_stock' => 'C'],
            // ['name' => 'DEPOSITO MONKOTO DC11', 'bar_code' => '7798095081044', 'provider_code' => '01.38.01.01', 'cost' => 35570, 'stock' => 40, 'sub_category_name' => 'Plomeria', 'brand_name' => 'MONKOTO'],
            // ['name' => 'DEPOSITO MONKOTO DB11', 'bar_code' => '7798095081051', 'provider_code' => '01.38.01.03', 'cost' => 35570, 'stock' => 40, 'sub_category_name' => 'Plomeria', 'brand_name' => 'MONKOTO'],
            // ['name' => 'DEPOSITO MONKOTO DC8', 'bar_code' => '7798095081068', 'provider_code' => '01.38.01.81', 'cost' => 31437, 'stock' => 40, 'sub_category_name' => 'Plomeria', 'brand_name' => 'MONKOTO'],
        ];
    }
}

