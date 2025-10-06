<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Relations\Relation;

use App\Models\Category;
use App\Models\SubCategory;
use App\Observers\CategoryObserver;
use App\Observers\SubCategoryObserver;

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


        Category::observe(CategoryObserver::class);
        SubCategory::observe(SubCategoryObserver::class);
    }
}
