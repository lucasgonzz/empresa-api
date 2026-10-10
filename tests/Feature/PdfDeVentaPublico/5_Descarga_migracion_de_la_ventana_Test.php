<?php

namespace Tests\Feature\PdfDeVentaPublico;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La migración `add_pdf_links_legacy_until_to_users_table` (misión pdf-de-venta-publico,
 * 10/10/2026): abre la ventana de transición de 60 días en los DUEÑOS de la instalación que la corre,
 * y deja a los empleados en NULL.
 *
 * Se corre su `up()` sobre la base del slot, donde la columna ya existe: el `hasColumn` saltea el
 * ALTER (que en MySQL haría commit implícito y rompería la transacción del test) y queda solo el
 * UPDATE, que es lo que se mide y que la transacción revierte.
 */
class Descarga_migracion_de_la_ventana_Test extends DescargaTestCase
{
    /**
     * @return void
     */
    protected function correr_la_migracion()
    {
        require_once database_path('migrations/2026_10_10_100100_add_pdf_links_legacy_until_to_users_table.php');

        (new \AddPdfLinksLegacyUntilToUsersTable())->up();
    }

    /**
     * Dueños: ahora + 60 días. Empleados: NULL.
     *
     * @test
     */
    public function la_ventana_queda_en_60_dias_para_los_duenos_y_null_para_los_empleados()
    {
        $this->assertTrue(Schema::hasColumn('users', 'pdf_links_legacy_until'));

        $otro = $this->crear_otro_comercio();
        $empleado = $this->crear_empleado_del_dueno();

        // Arrancan todos sin ventana, como antes de la actualización.
        DB::table('users')->whereIn('id', [$this->dueno->id, $otro->id, $empleado->id])->update(['pdf_links_legacy_until' => null]);

        $antes = Carbon::now()->addDays(60)->subMinute();

        $this->correr_la_migracion();

        $despues = Carbon::now()->addDays(60)->addMinute();

        foreach ([$this->dueno->id, $otro->id] as $duenio_id) {

            $hasta = DB::table('users')->where('id', $duenio_id)->value('pdf_links_legacy_until');

            $this->assertNotNull($hasta, 'El dueño ' . $duenio_id . ' tiene que quedar con ventana.');
            $this->assertTrue(Carbon::parse($hasta)->between($antes, $despues), 'La ventana del dueño ' . $duenio_id . ' tiene que ser de 60 días: ' . $hasta);
        }

        $this->assertNull(DB::table('users')->where('id', $empleado->id)->value('pdf_links_legacy_until'), 'Un empleado no tiene ventana propia.');
    }

    /**
     * Correrla de nuevo no corre la ventana de quien ya la tiene: el UPDATE toca solo a los dueños
     * que siguen en NULL (el caso de una corrida que se cortó después del ALTER).
     *
     * @test
     */
    public function correrla_de_nuevo_no_pisa_una_ventana_existente()
    {
        $otro = $this->crear_otro_comercio();

        $fijada = Carbon::now()->addDays(5)->startOfMinute()->format('Y-m-d H:i:s');

        DB::table('users')->where('id', $this->dueno->id)->update(['pdf_links_legacy_until' => $fijada]);
        DB::table('users')->where('id', $otro->id)->update(['pdf_links_legacy_until' => null]);

        $this->correr_la_migracion();

        $this->assertSame($fijada, DB::table('users')->where('id', $this->dueno->id)->value('pdf_links_legacy_until'));
        $this->assertNotNull(DB::table('users')->where('id', $otro->id)->value('pdf_links_legacy_until'));
    }
}
