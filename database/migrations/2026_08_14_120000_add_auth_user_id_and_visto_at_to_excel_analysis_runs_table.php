<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a las corridas de análisis los dos datos que hacen falta para que el
 * análisis deje de tener al usuario esperando frente al modal.
 *
 * Hasta ahora la corrida solo sabía de qué OWNER era (user_id). Alcanzaba
 * mientras el único que miraba el resultado era la misma pestaña que lo había
 * pedido: el uuid vivía en memoria del componente y no hacía falta más.
 *
 * Desde que el aviso de "terminó" viaja por broadcast y el resultado se puede
 * abrir después de un F5, hacen falta dos cosas más:
 *
 *  - auth_user_id: QUIÉN la lanzó. El canal de global_notification es del owner
 *    y lo escuchan todos sus empleados; sin esto, el empleado que subió el Excel
 *    y sus tres compañeros reciben el mismo aviso.
 *  - visto_at: si el usuario ya abrió el resultado. Sin esto, al recargar la
 *    página le volvemos a ofrecer el mismo análisis que ya miró, para siempre.
 */
class AddAuthUserIdAndVistoAtToExcelAnalysisRunsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('excel_analysis_runs', function (Blueprint $table) {

            /*
             * Usuario autenticado que disparó la corrida. Nullable a propósito:
             * las corridas que ya existen en la tabla no tienen forma de saberlo,
             * y una corrida sin auth_user_id simplemente no le avisa a nadie
             * (mejor que avisarle a todo el comercio).
             */
            $table->unsignedInteger('auth_user_id')->nullable()->after('user_id');

            /* Momento en que el usuario abrió el resultado; null = todavía no lo vio. */
            $table->timestamp('visto_at')->nullable()->after('paso');

            /*
             * Índice para la consulta de /analysis-en-curso, que arranca en cada
             * carga de la SPA: "la última corrida de este auth_user".
             */
            $table->index(['auth_user_id', 'id'], 'ear_auth_user_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('excel_analysis_runs', function (Blueprint $table) {
            $table->dropIndex('ear_auth_user_idx');
            $table->dropColumn(['auth_user_id', 'visto_at']);
        });
    }
}
