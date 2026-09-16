<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Relations\Relation;

use App\Database\Connectors\RetryingMySqlConnector;
use App\Models\Article;
use App\Models\Category;
use App\Models\Description;
use App\Models\SubCategory;
use App\Models\User;
use App\Observers\ArticleObserver;
use App\Observers\CategoryObserver;
use App\Observers\DescriptionObserver;
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
            'promocion_vinoteca' => 'App\Models\PromocionVinoteca',
            'client' => 'App\Models\Client',
            'provider' => 'App\Models\Provider',
        ]);


        Article::observe(ArticleObserver::class);
        /* La descripción vive en otra tabla: sin este observer, editarla dejaba el embedding viejo. */
        Description::observe(DescriptionObserver::class);
        Category::observe(CategoryObserver::class);
        SubCategory::observe(SubCategoryObserver::class);
        User::observe(UserEtiquetaMedidaObserver::class);
    }
}
