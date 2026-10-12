<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddPdfLinksLegacyUntilToUsersTable extends Migration
{
    /**
     * Días que dura la ventana de transición desde que la instalación recibe esta versión
     * (decisión de Lucas, 10/10/2026).
     */
    const DIAS_DE_VENTANA = 60;

    /**
     * Ventana de transición de los links de PDF sin token (misión pdf-de-venta-publico, 10/10/2026).
     *
     * Los links que ya se mandaron por WhatsApp o por mail (`sale/pdf/123`, sin `?t=`) no se pueden
     * cortar de un día para el otro: un cliente final que abre el comprobante de la semana pasada
     * tiene que poder verlo. Mientras `pdf_links_legacy_until` del dueño del recurso sea posterior a
     * ahora, el middleware `descarga.comercio` sirve esos links igual y deja un `Log::info` de cada
     * uso; al vencerse, un link sin sesión ni token da 404.
     *
     * 🔴 LA VENTANA SE CUENTA POR INSTALACIÓN, NO POR FECHA FIJA: arranca cuando ESTA base corre esta
     * migración, o sea cuando el cliente recibe la versión. Los clientes se actualizan en días
     * distintos y una fecha fija le cortaría los links de golpe al que se actualiza tarde.
     *
     * Se completa solo en los DUEÑOS (`owner_id IS NULL`): el middleware mira siempre al dueño del
     * recurso, nunca a un empleado. NULL = sin ventana, que es lo que queda en una instalación nueva
     * (su `migrate:fresh` corre antes de que exista ningún usuario) y en los usuarios que se crean
     * después: un comercio que nace con esta versión nunca compartió links sin token.
     *
     * Idempotente: la columna se agrega solo si falta, y el UPDATE toca solo a los dueños que siguen
     * en NULL. Si una corrida anterior agregó la columna y se cortó antes del UPDATE (en MySQL el
     * ALTER no vuelve atrás), la próxima corrida completa la ventana en vez de dejar a todos los
     * dueños sin ella — que sería cortar los links compartidos el mismo día de la actualización.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('users', 'pdf_links_legacy_until')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('pdf_links_legacy_until')->nullable();
            });
        }

        DB::table('users')
            ->whereNull('owner_id')
            ->whereNull('pdf_links_legacy_until')
            ->update(['pdf_links_legacy_until' => Carbon::now()->addDays(self::DIAS_DE_VENTANA)]);
    }

    /**
     * Saca la columna si existe (rollback seguro en entornos desalineados).
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('users', 'pdf_links_legacy_until')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('pdf_links_legacy_until');
            });
        }
    }
}
