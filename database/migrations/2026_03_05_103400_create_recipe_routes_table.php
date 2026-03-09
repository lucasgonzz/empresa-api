<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRecipeRoutesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('recipe_routes', function (Blueprint $table) {
            $table->id();

            $table->integer('recipe_id')->nullable();

            $table->integer('recipe_route_type_id')->nullable(); // "Producción interna", "Producción externa"
            $table->boolean('is_default')->default(false);
            
            // De donde se va a sacar el stock de insumos por defecto
            $table->integer('from_address_id')->nullable();

            // Hacia donde se va a mandar el stock de lo producido por defecto
            $table->integer('to_address_id')->nullable();

            $table->string('temporal_id')->nullable();
            
            $table->text('notes')->nullable();

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
        Schema::dropIfExists('recipe_routes');
    }
}
