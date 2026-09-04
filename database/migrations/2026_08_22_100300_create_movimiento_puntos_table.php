<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El libro de movimientos de puntos. Es la ÚNICA fuente del saldo de un cliente.
 *
 * 🔴 EL SALDO ES `SUM(puntos)`, UNA SOLA FRASE. `puntos` es una columna SIGNADA: los
 * 'ganados' y los 'ajuste' positivos suman, los 'canjeados', 'vencidos', 'revertidos' y
 * los 'ajuste' negativos restan. A propósito NO hay un par debe/haber como en la cuenta
 * corriente de plata: aquella lo necesita porque tiene imputación por moneda y saldo
 * corrido; acá no hace falta, y con un solo campo el bug de "sumé la columna equivocada"
 * es imposible de escribir.
 *
 * El modelo se llama `MovimientoPunto` y Laravel pluraliza a 'movimiento_puntos' solo,
 * igual que MovimientoCaja -> movimiento_cajas, que es el precedente del repo.
 *
 * Sin foreign keys físicas, igual que todo el schema.
 */
class CreateMovimientoPuntosTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('movimiento_puntos')) {
            return;
        }

        Schema::create('movimiento_puntos', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->id();

            /* Scope comercio: todo listado y todo reporte filtra por acá. `users.id` es int unsigned */
            $table->unsignedInteger('user_id');

            /*
             * Los puntos son SIEMPRE de un cliente: una venta sin client_id no suma nunca.
             * `clients.id` es bigIncrements -> bigint unsigned.
             */
            $table->unsignedBigInteger('client_id');

            /*
             * Con qué configuración se otorgó. Si mañana cambian los números del programa, el
             * histórico no miente. Nullable porque los movimientos de ajuste manual pueden
             * existir sin programa activo.
             */
            $table->unsignedBigInteger('sistema_de_puntos_id')->nullable();

            /*
             * 'ganados' | 'canjeados' | 'vencidos' | 'ajuste' | 'revertidos'.
             * String y no un tipo enumerado de MySQL: el único enum del schema
             * (current_acounts.status) es hoy un dolor de cabeza para migrar, porque agregarle
             * un valor es un ALTER de la tabla entera.
             */
            $table->string('tipo', 20);

            /* 🔴 SIGNADO. Ver el docblock de arriba: el saldo del cliente es SUM(puntos) */
            $table->decimal('puntos', 20, 2);

            /* La venta que originó el movimiento. null en 'ajuste' y en 'vencidos' */
            $table->unsignedBigInteger('sale_id')->nullable();

            /*
             * 🔴 Con qué lista de precio se otorgó. `0` = "sin lista" (venta sin price_type_id
             * y programa sin filtro de listas). NOT NULL con centinela y NO nullable a
             * propósito: MySQL permite N filas con NULL en una columna de un unique, así que
             * con nullable el unique de más abajo no protegería nada justo en el caso más común.
             */
            $table->unsignedBigInteger('price_type_id')->default(0);

            /*
             * El neto sin IVA sobre el que se calculó. Sin esto no se puede auditar por qué
             * salieron 4 puntos y no 5, que es el primer reclamo que llega.
             */
            $table->decimal('monto_base', 20, 2)->nullable();

            /* Texto que ve el usuario: 'Venta N° 1234', 'Vencimiento de puntos', 'Ajuste manual' */
            $table->string('detalle', 191);

            /* Solo en 'ganados'. null = no vence (programa con vencimiento_meses en null) */
            $table->dateTime('vence_at')->nullable();

            /*
             * Solo en 'ganados': cuánto de ESTE lote ya se gastó, sea por canje o por
             * vencimiento. Es el equivalente del `pagandose` de la cuenta corriente.
             */
            $table->decimal('consumido', 20, 2)->default(0);

            /*
             * Solo en 'ganados': lote revertido (venta anulada, nota de crédito total, venta
             * editada). El FIFO del canje y el barrido de vencimiento lo saltean.
             */
            $table->dateTime('anulado_at')->nullable();

            /* Quién lo hizo (ajuste manual, canje). `users.id` es int unsigned */
            $table->unsignedInteger('employee_id')->nullable();

            $table->timestamps();

            // 🔴 NO sacar este unique "porque un libro de movimientos no lleva uniques".
            // CurrentAcountHelper::checkPagos() (CurrentAcountHelper.php:579) resetea TODOS los debitos de la
            // cuenta a 'sin_pagar' y vuelve a correr CurrentAcountPagoHelper por CADA pago: pasa en cada
            // guardado de venta, cada borrado de pago y en tres jobs de fondo. O sea que la transicion
            // "el debito llego a pagado" se dispara N veces por UN solo hecho economico. El reconciliador
            // (PuntosAcumulacionHelper) ya es idempotente por codigo; esto es la red que lo respalda en la base.
            // Las filas con sale_id NULL (ajuste, vencidos) no participan del unique, que es lo correcto.
            $table->unique(['sale_id', 'tipo', 'price_type_id'], 'movimiento_puntos_sale_tipo_price_type_unique');

            /* La ficha del cliente y el saldo */
            $table->index(['user_id', 'client_id', 'created_at'], 'movimiento_puntos_user_client_created_index');

            /* El reporte de pasivo por período */
            $table->index(['user_id', 'tipo', 'created_at'], 'movimiento_puntos_user_tipo_created_index');

            /* El barrido del comando puntos:vencer */
            $table->index(['tipo', 'vence_at'], 'movimiento_puntos_tipo_vence_index');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('movimiento_puntos');
    }
}
