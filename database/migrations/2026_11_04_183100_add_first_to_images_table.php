<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFirstToImagesTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('images', 'first')) {
            Schema::table('images', function (Blueprint $table) {
                $table->boolean('first')->default(false);
            });
        }
    }

    public function down()
    {
        // Algunas instalaciones ya traían esta columna. Se conserva la portada elegida al revertir el código.
    }
}
