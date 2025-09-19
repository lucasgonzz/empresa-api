<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticlesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('me_li_id')->nullable();
            $table->integer('num')->nullable();
            $table->string('bar_code', 128)->nullable();
            $table->string('provider_code', 128)->nullable();
            $table->text('name')->nullable();
            $table->text('slug')->nullable();
            $table->decimal('cost', 16, 2)->nullable();
            $table->decimal('costo_real', 16, 2)->nullable();
            $table->decimal('costo_mano_de_obra', 16, 2)->nullable();
            $table->decimal('percentage_gain', 8, 2)->nullable();
            $table->decimal('price', 16, 2)->nullable();
            $table->decimal('final_price', 16, 2)->nullable();
            $table->decimal('previus_final_price', 12, 2)->nullable();
            $table->decimal('stock', 12,2)->nullable();
            $table->integer('stock_min')->nullable();
            $table->boolean('online')->default(1);
            $table->integer('user_id')->unsigned();
            $table->integer('brand_id')->unsigned()->nullable();
            $table->integer('iva_id')->unsigned()->default(2)->nullable();
            $table->bigInteger('provider_id')->nullable()->unsigned();
            $table->bigInteger('category_id')->nullable()->unsigned();
            $table->bigInteger('sub_category_id')->nullable()->unsigned();
            $table->enum('status', ['active', 'inactive', 'from_provider_order', 'from_budget'])->default('active');
            $table->bigInteger('condition_id')->nullable()->unsigned();
            $table->integer('featured')->nullable();
            $table->integer('provider_price_list_id')->unsigned()->nullable();
            $table->boolean('cost_in_dollars')->default(0)->nullable();
            $table->boolean('provider_cost_in_dollars')->default(0)->nullable();
            $table->boolean('apply_provider_percentage_gain')->default(1);
            $table->integer('articles_pages')->nullable();
            $table->integer('provider_article_id')->nullable();
            $table->boolean('in_offer')->default(0)->nullable();
            $table->boolean('default_in_vender')->nullable();
            $table->boolean('mercado_libre')->default(true)->nullable();
            $table->timestamp('final_price_updated_at')->nullable();
            $table->integer('unidad_medida_id')->default(1)->nullable();
            $table->integer('unidades_individuales')->nullable();
            $table->softDeletes();

            $table->decimal('percentage_gain_blanco', 16, 2)->nullable();
            $table->decimal('final_price_blanco', 30, 2)->nullable();


            $table->integer('unidades_por_bulto')->nullable();
            $table->string('contenido')->nullable();
            $table->string('tipo_envase_id')->nullable();

            $table->timestamp('stock_updated_at')->nullable();

            $table->integer('bodega_id')->nullable();
            $table->integer('cepa_id')->nullable();
            $table->integer('presentacion')->nullable();

            $table->integer('omitir_en_lista_pdf')->nullable();

            $table->boolean('needs_sync_with_tn')->nullable();   
            $table->integer('tiendanube_product_id')->nullable();     
            $table->integer('tiendanube_variant_id')->nullable();     
                   
            
            $table->foreign('user_id')
                    ->references('id')->on('users');
            // $table->foreign('sub_category_id')
            //         ->references('id')->on('sub_categories');
                    
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('articles');
    }
}
