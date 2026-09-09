<?php

namespace Tests\Feature\Infraestructura;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Misión actualizar-sin-el-vps (9/9/2026) — el índice `messages_buyer_id_idx`.
 *
 * `messages.buyer_id` no tenía índice desde 2021 y es la columna por la que se lee cada
 * conversación (`MessageController::fromBuyer`) y por la que `GET api/buyer` agrega sus
 * contadores. La migración `2026_09_09_150000_add_buyer_id_index_to_messages_table` lo crea,
 * con una guarda por si ya existe.
 *
 * Extiende `TestCase` y NO `EmpresaTestCase` a propósito: acá se ejecuta DDL (`ADD INDEX` /
 * `DROP INDEX`), y en MySQL el DDL hace commit implícito, lo que rompería el `BEGIN`/`ROLLBACK`
 * de `DatabaseTransactions`. El estado final de cada test es siempre "índice presente", que es
 * el que dejó `migrate` y el que espera el resto de la suite.
 */
class Indice_de_mensajes_por_comprador_Test extends TestCase
{
    /** Nombre del índice, el mismo que declara la migración. */
    const INDICE = 'messages_buyer_id_idx';

    /** Archivo de la migración bajo prueba. */
    const MIGRACION = '2026_09_09_150000_add_buyer_id_index_to_messages_table.php';

    /**
     * Filas de `SHOW INDEX` para el índice bajo prueba.
     *
     * @return array
     */
    protected function filas_del_indice()
    {
        return DB::select("SHOW INDEX FROM messages WHERE Key_name = '" . self::INDICE . "'");
    }

    /**
     * Instancia de la migración, cargada a mano: Laravel no autocarga las migraciones.
     *
     * @return \Illuminate\Database\Migrations\Migration
     */
    protected function migracion()
    {
        require_once database_path('migrations/' . self::MIGRACION);

        return new \AddBuyerIdIndexToMessagesTable();
    }

    /**
     * Después de migrar, el índice existe, es sobre `buyer_id` y solo sobre esa columna, y no es
     * único (un comprador tiene muchos mensajes).
     *
     * @return void
     */
    public function test_el_indice_existe_despues_de_migrar()
    {
        $filas = $this->filas_del_indice();

        $this->assertCount(1, $filas, 'Tiene que haber exactamente una columna en ' . self::INDICE . '.');

        $fila = $filas[0];

        $this->assertSame('buyer_id', $fila->Column_name, 'El índice tiene que ser sobre buyer_id.');
        $this->assertSame(1, (int) $fila->Seq_in_index);
        $this->assertSame(1, (int) $fila->Non_unique, 'No puede ser único: un comprador tiene muchos mensajes.');
    }

    /**
     * Correr `up()` de nuevo con el índice ya creado no tira ni lo duplica: es la guarda para las
     * instancias donde el índice ya se creó a mano por SSH antes del upgrade.
     *
     * @return void
     */
    public function test_up_es_idempotente_si_el_indice_ya_existe()
    {
        $this->assertCount(1, $this->filas_del_indice(), 'Precondición: el índice ya tiene que estar.');

        $this->migracion()->up();

        $this->assertCount(1, $this->filas_del_indice(), 'Un segundo up() no puede duplicar el índice ni tirar.');
    }

    /**
     * `down()` lo saca, y un `down()` repetido tampoco tira. Al final se vuelve a crear, para dejar
     * la base como la dejó `migrate`.
     *
     * @return void
     */
    public function test_down_lo_saca_y_up_lo_vuelve_a_crear()
    {
        $migracion = $this->migracion();

        try {
            $migracion->down();
            $this->assertCount(0, $this->filas_del_indice(), 'down() tiene que sacar el índice.');

            $migracion->down();
            $this->assertCount(0, $this->filas_del_indice(), 'Un segundo down() sin índice no puede tirar.');

            $migracion->up();
            $this->assertCount(1, $this->filas_del_indice(), 'up() después de down() tiene que volver a crearlo.');
        } finally {
            // Pase lo que pase, la suite que sigue espera el índice presente.
            if (count($this->filas_del_indice()) === 0) {
                $migracion->up();
            }
        }
    }
}
