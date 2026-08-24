<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda cuál fue la cotización que el comercio ya vio y decidió NO adoptar.
 *
 * 🔴 Por qué hace falta una columna y no alcanzaba con el checkbox de aviso.
 *
 * Lucas pidió que, después de un "ahora no", se le vuelva a avisar "cuando se detecte un NUEVO
 * cambio". Sin esta columna la comparación se hace siempre contra `dolar_cotizacion_valor`, que
 * solo se mueve cuando el usuario ACEPTA — así que el que decía "ahora no" volvía a ver el mismo
 * modal, con los mismos dos números, en cada inicio de sesión y en cada F5. Eso no es avisar de un
 * cambio nuevo: es un cartel que se aprende a cerrar sin leer, y el día que el dólar se mueve en
 * serio ya nadie lo lee.
 *
 * Tampoco servía apagar el checkbox: eso apaga TODOS los avisos, incluidos los que sí importan.
 * El usuario que dice "ahora no" no está diciendo "no me avises más", está diciendo "de este
 * valor ya me enteré".
 *
 * Guarda el valor de la cotización de referencia en el momento del "ahora no". Mientras la casa
 * elegida siga valiendo eso, no se vuelve a abrir el modal solo; apenas se mueve, sí.
 *
 * Se limpia (vuelve a null) cuando el usuario adopta una cotización nueva: ahí la referencia
 * cambió y lo pospuesto quedó viejo.
 */
class AddDolarAvisoPospuestoToUsersTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'dolar_aviso_pospuesto_valor')) {
                /*
                 * Mismo tipo que `dolar_cotizacion_valor`, porque se compara contra él y contra
                 * las puntas que devuelve la API, que ya vienen redondeadas a 2 decimales.
                 * Nullable con default null: null = nunca pospuso nada.
                 */
                $table->decimal('dolar_aviso_pospuesto_valor', 10, 2)->nullable()->default(null);
            }
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'dolar_aviso_pospuesto_valor')) {
                $table->dropColumn('dolar_aviso_pospuesto_valor');
            }
        });
    }
}
