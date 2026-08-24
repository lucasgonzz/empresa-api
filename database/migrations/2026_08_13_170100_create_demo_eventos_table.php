<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migracion: crea demo_eventos, la cola local de eventos de la demo (mision 50).
 *
 * Todo evento se escribe primero aca y despues se intenta empujar al admin. Es la
 * decision T4 de contexto/demo_experiencia.md §9: si se cae el enlace con el admin no
 * se pierde nada, porque el push es un reintento sobre filas que ya estan persistidas.
 *
 * El `uuid` lo genera la instancia y es lo que hace idempotente el reintento: el admin
 * lo tiene con indice unico por lead y descarta el repetido devolviendo 200, asi que
 * reenviar un lote entero nunca duplica.
 *
 * Igual que demo_tracking_config, la tabla existe en las instancias de los clientes
 * reales pero nunca recibe una fila: sin canal configurado, DemoEventoEmitter no emite.
 */
class CreateDemoEventosTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('demo_eventos')) {
            return;
        }

        Schema::create('demo_eventos', function (Blueprint $table) {
            $table->id();

            /** Identidad del evento del lado del admin. La genera la instancia (Str::uuid()). */
            $table->string('uuid', 36)->unique();

            /** Nombre del catalogo: articulo.creado, clip.terminado, etc. */
            $table->string('nombre', 60);

            /** Clip del plan al que pertenece el evento, cuando aplica (1.1, 3.10...). */
            $table->string('clip_id', 10)->nullable();

            /**
             * Carga util. Para los eventos de negocio es el id del registro creado y nada
             * mas: esto viaja a otra base y no puede llevar datos del cliente final.
             */
            $table->json('datos')->nullable();

            $table->timestamp('ocurrido_at');

            /** Null = pendiente de empujar. Lo marca DemoEventosPushHelper::flush(). */
            $table->timestamp('sincronizado_at')->nullable();

            /**
             * Cuantas veces se intento empujar este evento. Al llegar a 10 deja de
             * reintentarse: un canal caido no puede convertirse en un reintento infinito.
             */
            $table->unsignedTinyInteger('intentos')->default(0);

            $table->text('ultimo_error')->nullable();

            $table->timestamps();

            /**
             * El flush busca siempre lo mismo: pendientes, mas viejo primero. Sin este
             * indice ese barrido cada minuto escanea la tabla entera.
             */
            $table->index(['sincronizado_at', 'ocurrido_at'], 'demo_eventos_pendientes_index');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('demo_eventos');
    }
}
