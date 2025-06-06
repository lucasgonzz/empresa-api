<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 128)->nullable();
            $table->string('doc_number', 128)->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('image_url')->nullable();
            $table->string('hosting_image_url')->nullable();
            $table->string('company_name', 128)->nullable();
            $table->enum('type', ['commerce', 'provider'])->default('commerce')->nullable();
            $table->string('password', 128);
            $table->string('visible_password', 128)->nullable();
            $table->string('prev_password', 128)->nullable();
            $table->integer('owner_id')->nullable()->unsigned();
            $table->integer('plan_id')->unsigned()->nullable();
            $table->decimal('plan_discount', 8,2)->unsigned()->nullable();
            $table->integer('percentage_card')->nullable();
            $table->string('dni')->nullable();
            // $table->string('verification_code')->nullable();
            $table->integer('admin_access')->default(0)->nullable();
            // $table->enum('online_prices', ['all', 'only_registered', 'only_buyers_with_comerciocity_client'])->nullable();
            $table->decimal('dollar', 10,2)->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('online')->nullable();
            $table->boolean('from_cloudinary')->default(0)->nullable();
            $table->integer('articles_pages')->nullable();
            $table->timestamp('payment_expired_at')->nullable();
            $table->integer('max_items_in_sale')->nullable();
            $table->boolean('download_articles')->default(1)->nullable();
            $table->integer('home_position')->nullable();
            $table->boolean('iva_included')->default(false);
            $table->boolean('ask_amount_in_vender')->default(true);
            $table->boolean('discount_stock_from_recipe_after_advance_to_next_status')->default(false);
            $table->decimal('sale_ticket_width', 12,2)->default(80);
            $table->integer('default_current_acount_payment_method_id')->nullable();
            $table->integer('article_ticket_info_id')->nullable();
            $table->string('session_id')->nullable();
            $table->decimal('total_a_pagar', 12,2)->nullable();
            $table->timestamp('last_activity')->nullable();
            $table->string('app_url')->nullable();
            $table->boolean('show_buyer_messages')->default(false);
            $table->rememberToken();

            $table->string('base_de_datos')->nullable();
            $table->string('google_custom_search_api_key')->nullable();

            $table->integer('dias_alertar_empleados_ventas_no_cobradas')->nullable();
            $table->integer('dias_alertar_administradores_ventas_no_cobradas')->nullable();
            $table->boolean('ver_alertas_de_todos_los_empleados')->nullable();

            $table->integer('str_limint_en_vender')->nullable();
            
            $table->enum('status', ['commerce', 'admin', 'super']);
            $table->softDeletes();

            $table->boolean('use_archivos_de_intercambio')->nullable();
            $table->text('image_pdf_header_url')->nullable();

            $table->text('sale_ticket_description')->nullable();

            $table->boolean('siempre_omitir_en_cuenta_corriente')->nullable();
            
            $table->integer('address_id')->nullable();
            $table->boolean('redondear_centenas_en_vender')->default(0);
            $table->boolean('redondear_miles_en_vender')->default(0);

            $table->boolean('aplicar_descuentos_en_articulos_antes_del_margen_de_ganancia')->default(1)->nullable();

            $table->string('comision_funcion')->nullable();

            $table->boolean('puede_guardar_ventas_sin_cliente')->default(0);

            $table->boolean('header_articulos_pdf')->default(1);

            $table->string('default_version')->nullable();

            $table->string('estable_version')->nullable();

            $table->string('text_omitir_cc')->nullable();

            $table->string('article_ticket_print_function')->nullable();
            $table->string('impresora')->nullable();

            $table->decimal('tamano_letra', 10,2)->nullable();
            $table->string('venta_terminada_comision_funcion')->nullable();

            $table->integer('seller_id')->unsigned()->nullable();

            $table->integer('default_article_iva_id')->nullable();

            $table->string('article_pdf_personalizado')->nullable();


            $table->timestamp('login_at')->nullable();
            $table->timestamp('logout_at')->nullable();
            // $table->foreign('owner_id')->references('id')->on('users');
            // $table->foreign('admin_id')->references('id')->on('users');
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
        Schema::dropIfExists('users');
    }
}
