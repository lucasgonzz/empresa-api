<?php

namespace App\Http\Controllers\Helpers\category_proposal;

use App\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * Las reglas de plata y de Tienda Nube que bloquean elegir un sistema de categorías nuevo (misión
 * categorizacion-tres-modelos, 5/10/2026). Plan §4.5 y §4.6.
 *
 * Por qué existe: elegir un sistema `nueva` reasigna `articles.category_id` en bloque, y en una cuenta
 * donde el precio depende de la categoría (margen propio de la categoría, listas de precio por
 * categoría o por rango de cantidad, descuento de vinculación de inventario) eso MUEVE precios. Y con
 * Tienda Nube cada categoría creada dispara un POST sincrónico a la API de TN desde el observer. En
 * esos casos el sistema nuevo no se ofrece: queda solo "Mantener las mías" (que completa los
 * artículos sin categoría y recalcula precios en segundo plano).
 *
 * 🔴 Este helper es la ÚNICA fuente de esa decisión (clase de error "el mismo invariante decidido con
 * dos criterios en front y back"): `elegir` corta con 422, `actual` muestra el `bloqueo` y
 * `admin-sync/catalogo/resumen` le dice a la skill que no arme modelos nuevos. La SPA no recalcula
 * nada: dibuja lo que dice la API.
 *
 * Las seis SQL de márgenes son las de `relevamiento/R1-efectos-de-aplicar-categorias.md` §2.5: ANSI
 * simples, corridas en MySQL 8 y válidas en MariaDB 11.8 (Doblep corre MariaDB). Todo se acota por el
 * `user_id` del DUEÑO: la base compartida vieja del shared hosting tiene decenas de comercios, y
 * `category_price_type` y `price_type_sub_category` no tienen `user_id` (se cruzan por `categories` y
 * `sub_categories`).
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class CategoryMargenesHelper
{
    /**
     * Códigos de los motivos que bloquean un sistema `nueva` por márgenes (R1 a R6) y la SQL que
     * cuenta cuántos casos hay. Cada SQL devuelve UNA fila con la columna `n` y usa el parámetro
     * nombrado `:o` (id del dueño) una sola vez: PDO sin emulación de prepares no deja repetir un
     * parámetro nombrado en la misma consulta.
     *
     *  - R1: margen propio (`percentage_gain`) de alguna categoría viva. Vale en cualquier cuenta,
     *        sin extensión. NULL no cuenta (el cálculo de precio no lo suma); `0.00` tampoco (por eso
     *        `<> 0`: suma cero y no mueve nada); un negativo (descuento) sí.
     *  - R2: la extensión de listas por categoría o la de rangos por cantidad está prendida. Cuenta
     *        aunque los porcentajes valgan 0: una categoría nueva nace con los pivotes en NULL y lo
     *        que se mueva ahí pierde el precio de lista.
     *  - R3: listas por categoría con datos (`category_price_type.percentage` distinto de 0).
     *  - R4: listas por subcategoría con datos (`price_type_sub_category.percentage` distinto de 0).
     *  - R5: rangos por cantidad que apuntan a una categoría o subcategoría del dueño.
     *  - R6: descuento de vinculación de inventario por categoría (`percentage_discount` distinto de 0).
     *
     * @var array
     */
    const SQL_MOTIVOS = [
        'R1' => 'SELECT COUNT(*) AS n FROM categories WHERE user_id = :o AND deleted_at IS NULL AND percentage_gain IS NOT NULL AND percentage_gain <> 0',
        'R2' => 'SELECT COUNT(*) AS n FROM extencion_empresas e JOIN extencion_empresa_user eu ON eu.extencion_empresa_id = e.id WHERE eu.user_id = :o AND e.slug IN (\'lista_de_precios_por_categoria\', \'lista_de_precios_por_rango_de_cantidad_vendida\')',
        'R3' => 'SELECT COUNT(*) AS n FROM category_price_type p JOIN categories c ON c.id = p.category_id AND c.deleted_at IS NULL WHERE c.user_id = :o AND p.percentage IS NOT NULL AND p.percentage <> 0',
        'R4' => 'SELECT COUNT(*) AS n FROM price_type_sub_category p JOIN sub_categories s ON s.id = p.sub_category_id AND s.deleted_at IS NULL WHERE s.user_id = :o AND p.percentage IS NOT NULL AND p.percentage <> 0',
        'R5' => 'SELECT COUNT(*) AS n FROM category_price_type_ranges WHERE user_id = :o AND (category_id IS NOT NULL OR sub_category_id IS NOT NULL)',
        'R6' => 'SELECT COUNT(*) AS n FROM category_inventory_linkage x JOIN inventory_linkages l ON l.id = x.inventory_linkage_id JOIN categories c ON c.id = x.category_id AND c.deleted_at IS NULL WHERE l.user_id = :o AND x.percentage_discount IS NOT NULL AND x.percentage_discount <> 0',
    ];

    /**
     * Avisos que NO bloquean pero el dueño o Lucas tienen que poder ver (mismo formato que los
     * motivos: `['codigo' => 'A1', 'cantidad' => n]`):
     *
     *  - A1: comisiones del vendedor por categoría (`category_seller.percentage` distinto de 0): mover
     *        artículos de categoría mueve plata del vendedor.
     *  - A2: el dueño tiene vinculaciones de inventario (`inventory_linkages`): las cuentas clientes
     *        no se actualizan al recategorizar, las reconcilia aparte `CheckInventoryLinkages`.
     *  - A3: la cuenta trabaja con listas de precio (`users.listas_de_precio = 1`): es informativo, el
     *        margen de la categoría (R1) igual mueve el precio base.
     *
     * @var array
     */
    const SQL_AVISOS = [
        'A1' => 'SELECT COUNT(*) AS n FROM category_seller x JOIN categories c ON c.id = x.category_id AND c.deleted_at IS NULL WHERE c.user_id = :o AND x.percentage IS NOT NULL AND x.percentage <> 0',
        'A2' => 'SELECT COUNT(*) AS n FROM inventory_linkages WHERE user_id = :o',
        'A3' => 'SELECT COUNT(*) AS n FROM users WHERE id = :o AND listas_de_precio = 1',
    ];

    /**
     * Slug de la extensión con la que el scheduler decide que una cuenta usa Tienda Nube
     * (`app/Console/Kernel.php`: sincronización de artículos pendientes hacia TN).
     *
     * @var string
     */
    const EXTENSION_TIENDA_NUBE = 'usa_tienda_nube';

    /**
     * ¿El dueño usa margen o listas de precio por categoría? Especificación EXACTA en
     * relevamiento/R1-efectos-de-aplicar-categorias.md §2.5 (seis SQL, motivos R1 a R6).
     *
     * @param  int $user_id  Un dueño o un empleado (se resuelve al dueño: `users.owner_id`).
     * @return array  ['usa' => bool, 'motivos' => [['codigo' => 'R1', 'cantidad' => 3], ...], 'avisos' => [['codigo' => 'A1', 'cantidad' => 2], ...]]
     *                `usa` es true si ALGÚN motivo R1 a R6 tiene cantidad mayor que cero; los avisos
     *                nunca cambian `usa`.
     */
    public static function usa_margenes_por_categoria($user_id)
    {
        // El dueño de la cuenta: nunca se consulta con el id de un empleado, sus datos son del dueño.
        $dueno_id = self::dueno_de($user_id);

        // Los motivos con cantidad mayor que cero (R1 a R6): son los que bloquean.
        $motivos = self::motivos_de_margenes($dueno_id);

        // Los avisos con cantidad mayor que cero (A1 a A3): informan, nunca bloquean.
        $avisos = self::contar($dueno_id, self::SQL_AVISOS);

        return [
            'usa'     => count($motivos) > 0,
            'motivos' => $motivos,
            'avisos'  => $avisos,
        ];
    }

    /**
     * ¿El dueño usa Tienda Nube? Tres señales, cualquiera alcanza:
     *  - `config('app.USA_TIENDA_NUBE')`: el flag que el plan nombra. 🔴 Hoy `config/app.php` NO define
     *    esa clave (devuelve null en toda la flota) y los observers leen `env('USA_TIENDA_NUBE')`
     *    directo; se consulta igual porque es lo que el plan especifica y lo que simulan los tests.
     *  - `env('USA_TIENDA_NUBE')`: lo que de verdad leen los observers de categoría y subcategoría para
     *    decidir si al crear una categoría se llama a la API de Tienda Nube (ver el comentario del cuerpo).
     *  - La extensión `usa_tienda_nube` del dueño: es la que usa el scheduler para sincronizar con TN,
     *    vive en la base y no depende de `.env` ni de `config:cache`. Es la señal confiable.
     *
     * @param  int $user_id  Un dueño o un empleado (se resuelve al dueño).
     * @return bool
     */
    public static function usa_tienda_nube($user_id)
    {
        if (config('app.USA_TIENDA_NUBE')) {
            return true;
        }

        // 🔴 Excepción puntual a "config() y nunca env() fuera de config/", con el MISMO criterio que los
        // observers de categoría y subcategoría (`CategoryObserver`, `SubCategoryObserver`): lo que decide si
        // al CREAR una categoría sale un pedido de red a Tienda Nube es `env('USA_TIENDA_NUBE')`, no la
        // extensión ni `config('app.USA_TIENDA_NUBE')` (que no existe). Un comercio con la variable prendida y
        // sin la extensión (una configuración a medias) quedaba sin bloquear y cada categoría que crea el
        // aplicar llamaba a Tienda Nube DENTRO de la transacción, con el candado de `users` tomado: con 2
        // categorías salían 4 pedidos, con 360 serían 720 (B-11 del verificador). Se lee con la MISMA expresión
        // literal que los observers (`env('USA_TIENDA_NUBE', false)`), no con otra "equivalente": así la guarda y
        // los observers no pueden discrepar nunca ("false", "0" y vacío son apagado para los dos). Si la
        // configuración está cacheada, `env()` devuelve el valor por defecto (apagado) y los observers
        // tampoco llaman a Tienda Nube: coinciden también ahí.
        if (env('USA_TIENDA_NUBE', false)) {
            return true;
        }

        // El dueño de la cuenta: las extensiones se asignan al dueño, no a sus empleados.
        $dueno_id = self::dueno_de($user_id);

        // Una fila con `n` = cuántas veces tiene asignada la extensión de Tienda Nube (0 o 1).
        $fila = DB::selectOne(
            'SELECT COUNT(*) AS n FROM extencion_empresas e JOIN extencion_empresa_user eu ON eu.extencion_empresa_id = e.id WHERE eu.user_id = :o AND e.slug = :s',
            ['o' => $dueno_id, 's' => self::EXTENSION_TIENDA_NUBE]
        );

        return (int) $fila->n > 0;
    }

    /**
     * El bloqueo de los sistemas `nueva` para este dueño: márgenes por categoría y Tienda Nube.
     * Es lo que muestra `actual` y lo que corta `elegir` (422). "Mantener las mías" nunca se bloquea.
     *
     * Los motivos de márgenes salen con sus códigos R1 a R6; Tienda Nube suma el motivo `tienda_nube`
     * (cantidad 1). Si hay al menos uno, `nuevo_modelo_bloqueado` es true.
     *
     * @param  int $user_id  Un dueño o un empleado (se resuelve al dueño).
     * @return array  ['nuevo_modelo_bloqueado' => bool, 'motivos' => [['codigo' => 'R1'|...|'tienda_nube', 'cantidad' => n]]]
     */
    public static function bloqueo_para($user_id)
    {
        // Solo los motivos: los avisos no bloquean y no hace falta pagar sus tres consultas.
        $motivos = self::motivos_de_margenes(self::dueno_de($user_id));

        if (self::usa_tienda_nube($user_id)) {
            $motivos[] = ['codigo' => 'tienda_nube', 'cantidad' => 1];
        }

        return [
            'nuevo_modelo_bloqueado' => count($motivos) > 0,
            'motivos'                => $motivos,
        ];
    }

    /**
     * Las advertencias que no bloquean pero el dueño tiene que ver antes de confirmar (la SPA las
     * muestra en el cartel de "Elegir este"). Plan §4.6:
     *
     *  - `vinculacion_de_inventario`: el dueño tiene `inventory_linkages` (aviso A2). Vale para
     *    cualquier tipo de propuesta.
     *  - `urls_de_la_tienda`: la propuesta es `nueva` y el dueño YA tenía categorías. La URL pública de
     *    cada categoría de la tienda sale del slug de su nombre, y el SEO de la tienda cachea 10
     *    minutos: reorganizar las categorías cambia las URLs.
     *  - `precios_a_recalcular`: la propuesta es `mantener` y el dueño usa márgenes por categoría. Los
     *    precios de los artículos completados se recalculan en segundo plano.
     *
     * @param  int         $user_id
     * @param  string|null $tipo  `nueva` | `mantener` | null (solo las que valen para cualquier tipo).
     * @return array  Lista de códigos (strings), en el orden de arriba.
     */
    public static function advertencias_para($user_id, $tipo = null)
    {
        // El dueño de la cuenta: todo lo que se pregunta acá es del dueño.
        $dueno_id = self::dueno_de($user_id);

        // Los códigos de las advertencias que aplican, en el orden en que se documentan arriba.
        $codigos = [];

        // Un dueño con vinculaciones de inventario: las cuentas clientes no se enteran del cambio.
        // `n` = cuántas vinculaciones de inventario tiene el dueño (la misma SQL del aviso A2).
        $vinculaciones = DB::selectOne(self::SQL_AVISOS['A2'], ['o' => $dueno_id]);
        if ((int) $vinculaciones->n > 0) {
            $codigos[] = 'vinculacion_de_inventario';
        }

        // Un sistema nuevo sobre un catálogo que ya tenía categorías cambia las URLs de la tienda.
        if ($tipo === 'nueva' && Category::where('user_id', $dueno_id)->exists()) {
            $codigos[] = 'urls_de_la_tienda';
        }

        // "Mantener" con márgenes por categoría: completar artículos mueve su precio.
        if ($tipo === 'mantener' && count(self::motivos_de_margenes($dueno_id)) > 0) {
            $codigos[] = 'precios_a_recalcular';
        }

        return $codigos;
    }

    /**
     * Los motivos R1 a R6 con cantidad mayor que cero, para un dueño ya resuelto.
     *
     * @param  int $dueno_id
     * @return array  [['codigo' => 'R1', 'cantidad' => n], ...] (vacío si no usa márgenes por categoría).
     */
    protected static function motivos_de_margenes($dueno_id)
    {
        return self::contar($dueno_id, self::SQL_MOTIVOS);
    }

    /**
     * Corre cada SQL de la lista para el dueño y devuelve las que dieron cantidad mayor que cero.
     *
     * @param  int   $dueno_id
     * @param  array $sqls  [codigo => SQL con `:o`].
     * @return array  [['codigo' => ..., 'cantidad' => n], ...] en el orden de la lista.
     */
    protected static function contar($dueno_id, array $sqls)
    {
        // Los códigos cuya SQL dio algo, con su cantidad.
        $encontrados = [];

        foreach ($sqls as $codigo => $sql) {
            // Una fila con la columna `n`: cuántos casos encontró esta SQL para el dueño.
            $fila = DB::selectOne($sql, ['o' => $dueno_id]);

            // Ese `n` como entero (el driver lo puede devolver como texto).
            $cantidad = (int) $fila->n;

            if ($cantidad > 0) {
                $encontrados[] = ['codigo' => $codigo, 'cantidad' => $cantidad];
            }
        }

        return $encontrados;
    }

    /**
     * El id del dueño de la cuenta: `users.owner_id` si el usuario es un empleado, o el mismo id si es
     * el dueño. Un id que no existe se devuelve tal cual (las consultas de arriba darán cero).
     *
     * @param  int $user_id
     * @return int
     */
    protected static function dueno_de($user_id)
    {
        // El dueño del usuario: NULL si el usuario es el dueño (o si no existe).
        $owner_id = DB::table('users')->where('id', (int) $user_id)->value('owner_id');

        return $owner_id ? (int) $owner_id : (int) $user_id;
    }
}
