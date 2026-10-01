<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Relations\Relation;

use App\Database\Connectors\RetryingMySqlConnector;
use App\Models\Article;
use App\Models\Category;
use App\Models\Description;
use App\Models\PriceType;
use App\Models\SubCategory;
use App\Models\User;
use App\Observers\ArticleObserver;
use App\Observers\CategoryObserver;
use App\Observers\DescriptionObserver;
use App\Observers\PriceTypeObserver;
use App\Observers\SubCategoryObserver;
use App\Observers\UserEtiquetaMedidaObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        Schema::defaultStringLength(191);

        // Reintenta la conexión a MySQL ante una saturación transitoria de la cuenta compartida
        // de Hostinger (misión reintento-conexion-mysql, 15/9/2026) en vez de romper el request al
        // primer fallo. Ver el docblock de la clase para el detalle y la relación con el reintento
        // que Laravel ya trae de fábrica. Configurable sin deploy vía DB_RETRY_MAX_INTENTOS /
        // DB_RETRY_ESPERA_BASE_MS — leído de config('database.retry') y no de env() directo acá:
        // con config:cache corrido (producción) un env() fuera de config/ cae siempre al default
        // sin avisar (config/database.php trae el porqué completo, ya documentado en el resto del
        // repo).
        $this->app->singleton('db.connector.mysql', function () {
            return new RetryingMySqlConnector(
                (int) config('database.retry.max_intentos', 3),
                (int) config('database.retry.espera_base_ms', 200) * 1000
            );
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Relation::enforceMorphMap([
            'article' => 'App\Models\Article',
            /*
             * Misión combos-calculados (30/9/2026): el combo tiene fotos propias en la tabla
             * `images` (`imageable_type = 'combo'`) y con el mapa impuesto un modelo que no está
             * acá no puede ser el dueño de una relación polimórfica: `Combo::images()` y el
             * `Image::create(['imageable_type' => 'combo'])` de ImageController::setImage()
             * reventarían con ClassMorphViolationException. La tienda (tienda-api) tiene el mismo
             * alias apuntando a su propio modelo `App\Combo`: los DOS tienen que llamarse `combo`,
             * porque el alias es lo que queda escrito en la base compartida.
             */
            'combo' => 'App\Models\Combo',
            'promocion_vinoteca' => 'App\Models\PromocionVinoteca',
            'client' => 'App\Models\Client',
            'provider' => 'App\Models\Provider',
            /*
             * Misión asistente-mcp (22/9/2026): `personal_access_tokens.tokenable` es polimórfica
             * (morphMany de Laravel\Sanctum\HasApiTokens) y con el mapa impuesto, un modelo que
             * no está acá no puede ser el dueño de NINGUNA relación polimórfica: User::tokens() y
             * User::createToken() reventaban con ClassMorphViolationException. Hasta esta misión
             * el repo no creaba tokens (el SPA se autentica por cookie), así que nadie lo notó; la
             * clave de conexión al servidor MCP es el primer createToken() y necesita esta línea.
             */
            'user' => 'App\Models\User',
        ]);


        Article::observe(ArticleObserver::class);
        /* La descripción vive en otra tabla: sin este observer, editarla dejaba el embedding viejo. */
        Description::observe(DescriptionObserver::class);
        Category::observe(CategoryObserver::class);
        SubCategory::observe(SubCategoryObserver::class);
        User::observe(UserEtiquetaMedidaObserver::class);
        /* Lista de precios nueva -> su diseño de etiquetas de góndola, venga del camino que venga. */
        PriceType::observe(PriceTypeObserver::class);

        /*
         * Auditoría de cambios (misión auditoria-de-cambios, 30/9/2026): un listener global de los
         * eventos de Eloquent deja una fila en `audit_logs` por cada cambio de cualquier modelo.
         * Se registra acá, en una sola línea, y no en cada modelo: así cubre también a los modelos
         * que se agreguen después. Qué se excluye y por qué: config/audit_log.php.
         */
        \App\Services\AuditLog\AuditLogRecorder::register();
    }
}
