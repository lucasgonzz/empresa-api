<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\Seeders\ArticleSeederHelper;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Description;
use App\Models\Provider;
use App\Models\SubCategory;
use Illuminate\Database\Seeder;

class FerreteriaArticlesSeeder extends Seeder
{
    /**
     * Ejecuta el seeder completo del rubro ferreteria.
     * Crea ocho categorias padre, subcategorias, marcas y articulos del catalogo con descripciones ecommerce.
     *
     * @return void
     */
    public function run()
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
     * Crea una o mas descripciones del articulo para ecommerce.
     *
     * @param int $article_id
     * @param array<string,mixed> $item
     * @return void
     */
    protected function create_descriptions($article_id, $item)
    {
        /** Titulo principal de ficha para destacar uso recomendado. */
        $main_title = 'Descripcion del producto';

        /** Contenido principal con foco comercial y de decision de compra. */
        $main_content = $item['name'] . ' de la marca ' . $item['brand_name'] . ', ideal para trabajos de ' . strtolower($item['sub_category_name']) . '. '
            . 'Fabricado con materiales resistentes para uso frecuente en obra, taller o mantenimiento del hogar.';

        Description::create([
            'title' => $main_title,
            'content' => $main_content,
            'article_id' => $article_id,
        ]);

        /** Titulo secundario orientado a beneficios logistica/uso real. */
        $secondary_title = 'Beneficios';

        /** Contenido secundario para reforzar confianza y postventa ecommerce. */
        $secondary_content = 'Excelente relacion costo-beneficio, facil de manipular y con rendimiento confiable. '
            . 'Producto recomendado para profesionales y usuarios exigentes que buscan durabilidad y buen resultado final.';

        Description::create([
            'title' => $secondary_title,
            'content' => $secondary_content,
            'article_id' => $article_id,
        ]);
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
     * Retorna catalogo de articulos de ferreteria obtenido de excels.
     * Cada entrada incluye nombre, bar_code valido, codigo proveedor y costo redondeado.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function get_catalog()
    {
        return [
            ['name' => 'CESTO DE BASURA CON PORTA PAPEL GRIS STOLF', 'bar_code' => '7897996714874', 'provider_code' => '61779', 'cost' => 18666, 'stock' => 40, 'sub_category_name' => 'Ferreteria General', 'brand_name' => 'STOLF'],
            ['name' => 'CESTO DE BASURA CON PORTA PAPEL NEGRO STOLF', 'bar_code' => '7897996716014', 'provider_code' => '61782', 'cost' => 18666, 'stock' => 40, 'sub_category_name' => 'Ferreteria General', 'brand_name' => 'STOLF'],
            ['name' => 'ESCOBILLON 375 X 45MM CERDA RIGIDA GARDEX', 'bar_code' => '', 'provider_code' => '35641', 'cost' => 3557, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'GARDEX'],
            ['name' => 'DRIVER PARA PANEL LED 24W ETHEOS', 'bar_code' => '7798351081283', 'provider_code' => 'SIN-COD-PROV', 'cost' => 2267, 'stock' => 40, 'sub_category_name' => 'Electricidad', 'brand_name' => 'ETHEOS'],
            ['name' => 'DUCHA DE MANO METAL GLOA', 'bar_code' => '7798367551572', 'provider_code' => '23408', 'cost' => 17539, 'stock' => 40, 'sub_category_name' => 'Plomeria', 'brand_name' => 'Generica'],
            ['name' => 'LAMPARA DICROICA LEDS 7W GU10 LUZ DIA NO / DIMERIZABLE CANDELA', 'bar_code' => '7798347081013', 'provider_code' => 'NL-D71065D', 'cost' => 2632, 'stock' => 40, 'sub_category_name' => 'Electricidad', 'brand_name' => 'CANDELA'],
            ['name' => 'MODULO HUSQVARNA (BOBINA)  236/235E/240/136/137/ POULAN 295/2600/2750/2775/2900', 'bar_code' => '7393080841094', 'provider_code' => '5803501', 'cost' => 24, 'stock' => 40, 'sub_category_name' => 'Repuestos y Accesorios', 'brand_name' => 'HUSQVARNA'],
            ['name' => 'JUNTA KOHLER TAPA VALVULAS XT650-XT675 - 1404101-S', 'bar_code' => '11404101', 'provider_code' => '1404101', 'cost' => 4, 'stock' => 40, 'sub_category_name' => 'Repuestos y Accesorios', 'brand_name' => 'KOHLER'],
            ['name' => 'FILTRO OREGON DE AIRE P/ B&S 12.5/13.5 MOD, VIEJOS OVALADO', 'bar_code' => '5400182524991', 'provider_code' => '55.30-049', 'cost' => 12, 'stock' => 40, 'sub_category_name' => 'Repuestos y Accesorios', 'brand_name' => 'OREGON'],
            ['name' => 'PIEDRA TECOMEC 145 X3.2 X 22.2MM P/ AFILADORA DE CADENA', 'bar_code' => '8032706107594', 'provider_code' => '13.K00204005', 'cost' => 21, 'stock' => 40, 'sub_category_name' => 'Jardineria y Forestal', 'brand_name' => 'TECOMEC'],
            ['name' => 'FILTRO OREGON AIRE P/ KOHLER SEMI RECTANGULAR C/ PREFILTRO (32-883-03-S1)', 'bar_code' => '032488301300', 'provider_code' => '55.30-130', 'cost' => 10, 'stock' => 40, 'sub_category_name' => 'Repuestos y Accesorios', 'brand_name' => 'OREGON'],
            ['name' => 'WP 230 STIHL BOMBA DE AGUA', 'bar_code' => '886661621811', 'provider_code' => 'VB02-011-2000', 'cost' => 241, 'stock' => 40, 'sub_category_name' => 'Jardineria y Forestal', 'brand_name' => 'STIHL'],
            ['name' => 'VOLANTE STIHL MS210 / 250 / 021 / 025', 'bar_code' => '795711514785', 'provider_code' => '1123-400-1203', 'cost' => 27, 'stock' => 40, 'sub_category_name' => 'Jardineria y Forestal', 'brand_name' => 'STIHL'],
            ['name' => 'W80 LUBRICANTE MULTIUSO CON PTFE 250ML AEROSOL', 'bar_code' => '7790711442208', 'provider_code' => '500000', 'cost' => 3, 'stock' => 40, 'sub_category_name' => 'Adhesivos y Selladores', 'brand_name' => 'SILOC'],
            ['name' => 'LLAVE TERMICA SICA 1X25A.', 'bar_code' => '7791772051781', 'provider_code' => '782125', 'cost' => 4060, 'stock' => 40, 'sub_category_name' => 'Electricidad', 'brand_name' => 'SICA'],
            ['name' => 'TUBO DE LED 9W LUZ DIA 6500K 60CM CANDELA', 'bar_code' => '7798347080979', 'provider_code' => 'NL-TG9WB', 'cost' => 2352, 'stock' => 40, 'sub_category_name' => 'Electricidad', 'brand_name' => 'CANDELA'],
            ['name' => 'PINZA CRIMPEADORA P/CONECTOR COAXIL RG59/6 SNAP-TIPO F', 'bar_code' => '7790483000880', 'provider_code' => 'CRI0507', 'cost' => 29584, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'Generica'],
            ['name' => 'LAMPARA MESH BLACK CUBO 4W CALIDA FILAMENTO CANDELA', 'bar_code' => '7798347087435', 'provider_code' => '7922', 'cost' => 19193, 'stock' => 40, 'sub_category_name' => 'Electricidad', 'brand_name' => 'CANDELA'],
            ['name' => 'CINTA PASACABLE DE ACERO X 30 METROS KALOP', 'bar_code' => '7793863750726', 'provider_code' => 'KL29130', 'cost' => 23810, 'stock' => 40, 'sub_category_name' => 'Electricidad', 'brand_name' => 'KALOP'],
            ['name' => 'CAJA DERIVACION PVC 16x18X8 GEN-ROD', 'bar_code' => '7798304381927', 'provider_code' => '061618', 'cost' => 11691, 'stock' => 40, 'sub_category_name' => 'Electricidad', 'brand_name' => 'Generica'],
            ['name' => 'AZADA BELLOTA  S/CABO 2.1/2', 'bar_code' => '7702956228783', 'provider_code' => '54750', 'cost' => 29767, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'BELLOTA'],
            ['name' => 'FOCO LED 12W LUZ FRIA NOVAELETRICITY', 'bar_code' => '7798174312205', 'provider_code' => 'NL-A122765B', 'cost' => 1267, 'stock' => 40, 'sub_category_name' => 'Electricidad', 'brand_name' => 'NOVAELETRICITY'],
            ['name' => 'AZADA TRAMONTINA S/CABO 2.0', 'bar_code' => '7891117001768', 'provider_code' => '33901', 'cost' => 13000, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'TRAMONTINA'],
            ['name' => 'Fraccionadora de Cinta con Mango Anatomico reforzado', 'bar_code' => '7796524810135', 'provider_code' => 'MA0083', 'cost' => 12475, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'Generica'],
            ['name' => 'LLAVE ALLEN 9 PIERZAS JUSTER CORTO', 'bar_code' => '6972434502822', 'provider_code' => '01860', 'cost' => 12742, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'JUSTER'],
            ['name' => 'LLAVE TORX 9 PIEZAS JUSTER LARGO', 'bar_code' => '6972434508237', 'provider_code' => '01857', 'cost' => 12742, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'JUSTER'],
            ['name' => 'LIMPIADOR QUITA OXIDO (FOSFATIZANTE) X 1LT', 'bar_code' => '7798120640703', 'provider_code' => '7200', 'cost' => 4647, 'stock' => 40, 'sub_category_name' => 'Adhesivos y Selladores', 'brand_name' => 'Generica'],
            ['name' => 'FLEXIBLE PARA DUCHA 1/2 X 1.50MTS CROMO TGFLEX', 'bar_code' => '7798430911296', 'provider_code' => '2780', 'cost' => 6020, 'stock' => 40, 'sub_category_name' => 'Plomeria', 'brand_name' => 'TGFLEX'],
            ['name' => 'ESPUMA POLIURETANO 300ML DOGO', 'bar_code' => '7798331914389', 'provider_code' => '4226', 'cost' => 5876, 'stock' => 40, 'sub_category_name' => 'Adhesivos y Selladores', 'brand_name' => 'DOGO'],
            ['name' => 'MECHA PARA CERAMICA Y AZULEJOS 10 MM  EXPERT BOSCH', 'bar_code' => '3165140599269', 'provider_code' => '58136', 'cost' => 10008, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'BOSCH'],
            ['name' => 'ESPATULA ENDUIR 140MM BIASSONI', 'bar_code' => '7798172005758', 'provider_code' => '50307', 'cost' => 4164, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'BIASSONI'],
            ['name' => 'ESPATULA PARA JUNTAS 150MM CONST. EN SECO BIASSONI', 'bar_code' => '7798312201927', 'provider_code' => '55339', 'cost' => 7706, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'BIASSONI'],
            ['name' => 'LLAVE T 10MM GARDEX', 'bar_code' => '7798431864652', 'provider_code' => '05486', 'cost' => 2957, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'GARDEX'],
            ['name' => 'DISCO DIAMANTADO KEX 3 EN 1 9\"', 'bar_code' => '7798312162204', 'provider_code' => '06757', 'cost' => 18788, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'KEX'],
            ['name' => 'SOPORTE D/PARED PARA TV,RECLINABLE 43 X 100,ONEBOX', 'bar_code' => '714604320692', 'provider_code' => '40257', 'cost' => 18894, 'stock' => 40, 'sub_category_name' => 'Cerrajeria y Montaje', 'brand_name' => 'ONEBOX'],
            ['name' => 'DISCO  KEX FIBRA REMOVEDOR 115x2.2 mm', 'bar_code' => '7798431865772', 'provider_code' => '55278', 'cost' => 4368, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'KEX'],
            ['name' => 'PRECINTO 200 x 4.8 BLANCO BROQUEL', 'bar_code' => '7798312160590', 'provider_code' => '59004', 'cost' => 4140, 'stock' => 40, 'sub_category_name' => 'Repuestos y Accesorios', 'brand_name' => 'BROQUEL'],
            ['name' => 'PRECINTO 400 x 4.8 BLANCO BROQUEL', 'bar_code' => '7798312160651', 'provider_code' => '59007', 'cost' => 9018, 'stock' => 40, 'sub_category_name' => 'Repuestos y Accesorios', 'brand_name' => 'BROQUEL'],
            ['name' => 'CERRADURA PRIVE  DESTRABADOR ELECTRICO 122 8 A 12 VOLTS - 5WATTS', 'bar_code' => '7796011707160', 'provider_code' => '07180', 'cost' => 19359, 'stock' => 40, 'sub_category_name' => 'Cerrajeria y Montaje', 'brand_name' => 'Generica'],
            ['name' => 'ESPATULA 70mm REMACHADO M/MADERA BIASSONI', 'bar_code' => '7798312201712', 'provider_code' => '55366', 'cost' => 3712, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'BIASSONI'],
            ['name' => 'ESPATULA 80mm REMACHADO M/MADERA BIASSONI', 'bar_code' => '7798312201729', 'provider_code' => '55367', 'cost' => 3846, 'stock' => 40, 'sub_category_name' => 'Herramientas Manuales', 'brand_name' => 'BIASSONI'],
            ['name' => 'PISTOLA APLICADORA GARDEX P/ADHESIVO', 'bar_code' => '7798431860883', 'provider_code' => '06261', 'cost' => 5729, 'stock' => 40, 'sub_category_name' => 'Adhesivos y Selladores', 'brand_name' => 'GARDEX'],
            ['name' => 'SOPAPA AMERICANA 9 CM AC INOX C/REJILLA BLU', 'bar_code' => '7798431864461', 'provider_code' => '34400', 'cost' => 2957, 'stock' => 40, 'sub_category_name' => 'Plomeria', 'brand_name' => 'BLU'],
            ['name' => 'SOPAPA AMERICANA 11 CM AC INOX C/REJILLA BLU', 'bar_code' => '7798431864478', 'provider_code' => '34401', 'cost' => 3172, 'stock' => 40, 'sub_category_name' => 'Plomeria', 'brand_name' => 'BLU'],
            ['name' => 'TIMBRE INALAMBRICO REDONDO CANDELA', 'bar_code' => '', 'provider_code' => '35504', 'cost' => 6323, 'stock' => 40, 'sub_category_name' => 'Electricidad', 'brand_name' => 'CANDELA'],
            ['name' => 'CINTA MULTIPROPOSITO BLANCO 48MM X 9Mts. TACSA DUCTAC', 'bar_code' => '', 'provider_code' => '07051', 'cost' => 2739, 'stock' => 40, 'sub_category_name' => 'Adhesivos y Selladores', 'brand_name' => 'TACSA'],
            // ['name' => 'DEPOSITO MONKOTO DC11', 'bar_code' => '7798095081044', 'provider_code' => '01.38.01.01', 'cost' => 35570, 'stock' => 40, 'sub_category_name' => 'Plomeria', 'brand_name' => 'MONKOTO'],
            // ['name' => 'DEPOSITO MONKOTO DB11', 'bar_code' => '7798095081051', 'provider_code' => '01.38.01.03', 'cost' => 35570, 'stock' => 40, 'sub_category_name' => 'Plomeria', 'brand_name' => 'MONKOTO'],
            // ['name' => 'DEPOSITO MONKOTO DC8', 'bar_code' => '7798095081068', 'provider_code' => '01.38.01.81', 'cost' => 31437, 'stock' => 40, 'sub_category_name' => 'Plomeria', 'brand_name' => 'MONKOTO'],
        ];
    }
}

