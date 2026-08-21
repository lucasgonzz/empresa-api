<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Misión escaneo-factura-compra — la tabla de los escaneos de facturas de proveedor.
 *
 * Un ESCANEO es una subida: el usuario saca una o varias fotos de la factura (o del remito)
 * que le trajo el proveedor, las manda todas juntas, y un job las lee con IA y deja acá el
 * JSON de lo que encontró (tabla de artículos + datos del comprobante). Después el usuario
 * abre esa lectura en un modal, la corrige a mano y la confirma; recién ahí se asienta en la
 * compra. Una subida = UNA fila de esta tabla, sin importar cuántas páginas tenga, porque las
 * páginas se procesan juntas en una sola pasada (una tabla cortada entre la página 1 y la 2
 * solo se puede reconstruir viendo las dos).
 *
 * Dos columnas necesitan explicación porque no se deducen del nombre:
 *
 *  - auth_user_id: QUIÉN disparó el escaneo, no de quién es el comercio (eso es user_id).
 *    El canal `global_notification.{owner_id}` lo escuchan TODOS los empleados del comercio;
 *    sin este id, el aviso de "terminó el escaneo" le llega también a los cuatro compañeros
 *    del que sacó la foto. Nullable por el mismo motivo que en excel_analysis_runs: una
 *    corrida sin auth_user_id no le avisa a nadie, que es mejor que avisarle a todos.
 *
 *  - gestionado_at: cuándo el usuario RESOLVIÓ el escaneo (lo confirmó o lo descartó). Es lo
 *    que apaga el botón rojo "Revisar escaneo" del listado de compras. No alcanza con visto_at
 *    —que es solo "vio el aviso"—: mirar el aviso y cerrar el modal no quiere decir que la
 *    lectura ya se haya asentado en la compra.
 *
 * 🔴 De esta tabla NO se limpia por antigüedad. A diferencia de excel_analysis_runs (que borra
 * corridas de más de 2 días porque son análisis descartables de un archivo), un escaneo es
 * parte del expediente de la compra: la foto es el respaldo del comprobante y el `resultado`
 * es la evidencia de qué se leyó. Vive mientras viva la compra. La ventana de 48 h se usa SOLO
 * para decidir qué escaneo ofrecer al arrancar la SPA, nunca para borrar.
 */
class CreateProviderOrderScansTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('provider_order_scans', function (Blueprint $table) {
            $table->bigIncrements('id');

            /*
             * Lo que viaja al frontend. Nunca se expone el id interno: mismo criterio que
             * excel_analysis_runs.
             */
            $table->string('uuid', 64);

            /*
             * OWNER (la empresa). Es la clave de tenencia: TODAS las consultas del módulo
             * filtran por acá. Una factura tiene CUIT, razón social y precios; sin este filtro,
             * iterar uuids deja leer la factura de otro comercio.
             */
            $table->unsignedInteger('user_id');

            /* Quién disparó el escaneo (ver el comentario de cabecera). */
            $table->unsignedInteger('auth_user_id')->nullable();

            /* La compra a la que pertenece el escaneo. */
            $table->unsignedInteger('provider_order_id');

            /*
             * pendiente | procesando | listo | error. Los mismos cuatro valores que
             * excel_analysis_runs: no se inventan otros.
             */
            $table->string('estado', 32)->default('pendiente');

            /* 0 a 100, para la barra del modal. */
            $table->unsignedTinyInteger('progreso')->default(0);

            /* Texto corto para el usuario ("Leyendo las fotos…", "Analizando la factura con IA…"). */
            $table->string('paso', 160)->nullable();

            /* Mensaje legible cuando estado = 'error'. */
            $table->text('error')->nullable();

            /*
             * El JSON de lo que leyó la IA (y de lo que el usuario dejó, después de confirmar).
             * longText y no text: una factura de 80 renglones con confianzas por campo pasa
             * cómodo los 64 KB de un text.
             */
            $table->longText('resultado')->nullable();

            /*
             * Cuándo el usuario vio el AVISO. Sin esto, cada recarga de la SPA le vuelve a
             * tirar el mismo modal de "terminó", para siempre.
             */
            $table->timestamp('visto_at')->nullable();

            /* Cuándo se resolvió el escaneo. Es lo que apaga el botón rojo. */
            $table->timestamp('gestionado_at')->nullable();

            /*
             * confirmado | descartado. Dice CÓMO terminó. Un string y no un enum: el proyecto
             * corre PHP 7.4 y además un enum de MySQL obliga a una migración por cada valor
             * nuevo.
             */
            $table->string('resultado_gestion', 16)->nullable();

            /*
             * Resumen de lo que REALMENTE se asentó al confirmar:
             * {"articulos_agregados":12,"articulos_creados":2,"factura_guardada":"completa"}.
             * Es el registro de auditoría de la confirmación: `resultado` dice qué se leyó,
             * `aplicado` dice qué entró.
             */
            $table->text('aplicado')->nullable();

            $table->timestamps();

            /*
             * Nombres cortos y explícitos: MySQL corta los identificadores en 64 caracteres y
             * el nombre automático de un índice compuesto sobre una tabla con este nombre se
             * pasa sin avisar.
             */
            $table->index('uuid', 'pos_uuid_idx');
            $table->index('user_id', 'pos_user_idx');
            $table->index('provider_order_id', 'pos_order_idx');

            /* Consulta del botón rojo: "escaneos listos y sin gestionar de este comercio". */
            $table->index(['user_id', 'estado', 'gestionado_at'], 'pos_pendientes_idx');

            /* Consulta de /en-curso, que arranca en cada carga de la SPA. */
            $table->index(['auth_user_id', 'id'], 'pos_auth_user_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('provider_order_scans');
    }
}
