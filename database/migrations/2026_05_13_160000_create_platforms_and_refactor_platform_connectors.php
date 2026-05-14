<?php

use App\Models\Platform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo global de plataformas (credenciales de la app Comercio City).
 * `platform_connectors` referencia `platform_id` y solo guarda tokens por usuario.
 */
class CreatePlatformsAndRefactorPlatformConnectors extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('platforms')) {
            Schema::create('platforms', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 40)->unique();
                $table->string('name', 120);
                $table->string('client_id', 160)->nullable();
                $table->text('client_secret')->nullable();
                $table->json('extra_config')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('platform_connectors')) {
            return;
        }

        if (!Schema::hasColumn('platform_connectors', 'platform_id')) {
            Schema::table('platform_connectors', function (Blueprint $table) {
                $table->unsignedBigInteger('platform_id')->nullable()->index();
            });
        }

        /**
         * Asegura filas base en `platforms` para poder correlacionar conectores viejos por slug.
         * Las claves definitivas las actualiza `PlatformSeeder` desde el .env.
         */
        Platform::query()->updateOrCreate(
            ['slug' => Platform::SLUG_MERCADO_LIBRE],
            [
                'name'           => 'Mercado Libre',
                'client_id'      => env('MERCADO_LIBRE_CLIENT_ID'),
                'client_secret'  => env('MERCADO_LIBRE_CLIENT_SECRET'),
                'extra_config'   => null,
            ]
        );
        Platform::query()->updateOrCreate(
            ['slug' => Platform::SLUG_TIENDA_NUBE],
            [
                'name'          => 'Tienda Nube',
                'client_id'     => env('TN_CLIENT_ID') ?: env('MERCADO_LIBRE_CLIENT_ID'),
                'client_secret' => env('TN_CLIENT_SECRET') ?: env('MERCADO_LIBRE_CLIENT_SECRET'),
                'extra_config'  => $this->tn_extra_config_from_env(),
            ]
        );

        $map = Platform::query()->pluck('id', 'slug')->all();

        if (isset($map[Platform::SLUG_MERCADO_LIBRE])) {
            DB::table('platform_connectors')
                ->where('platform', Platform::SLUG_MERCADO_LIBRE)
                ->whereNull('platform_id')
                ->update(['platform_id' => $map[Platform::SLUG_MERCADO_LIBRE]]);
        }
        if (isset($map[Platform::SLUG_TIENDA_NUBE])) {
            DB::table('platform_connectors')
                ->where('platform', Platform::SLUG_TIENDA_NUBE)
                ->whereNull('platform_id')
                ->update(['platform_id' => $map[Platform::SLUG_TIENDA_NUBE]]);
        }

        if (Schema::hasColumn('platform_connectors', 'platform')) {
            Schema::table('platform_connectors', function (Blueprint $table) {
                $table->dropColumn(['platform', 'client_id', 'client_secret', 'extra_config']);
            });
        }
    }

    /**
     * Arma `extra_config` inicial para Tienda Nube (app_id en URL de authorize).
     *
     * @return array|null
     */
    protected function tn_extra_config_from_env()
    {
        $app_id = env('TN_APP_ID');
        if (empty($app_id)) {
            return null;
        }

        return ['app_id' => $app_id];
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('platform_connectors')) {
            if (Schema::hasTable('platforms')) {
                Schema::dropIfExists('platforms');
            }

            return;
        }

        Schema::table('platform_connectors', function (Blueprint $table) {
            $table->string('platform', 30)->nullable()->index();
            $table->string('client_id', 120)->nullable();
            $table->text('client_secret')->nullable();
            $table->json('extra_config')->nullable();
        });

        $map = Platform::query()->pluck('slug', 'id')->all();
        foreach ($map as $id => $slug) {
            DB::table('platform_connectors')
                ->where('platform_id', $id)
                ->update(['platform' => $slug]);
        }

        if (Schema::hasColumn('platform_connectors', 'platform_id')) {
            Schema::table('platform_connectors', function (Blueprint $table) {
                $table->dropColumn('platform_id');
            });
        }

        if (Schema::hasTable('platforms')) {
            Schema::dropIfExists('platforms');
        }
    }
}
