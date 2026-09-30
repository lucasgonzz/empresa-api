<?php

namespace Tests\Feature\ModelosIa;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Misión modelos-ia-por-cliente (30/9/2026) — las dos migraciones.
 *
 * Protege: las tres columnas nuevas existen y son nullable (null = default de la tarea, en código);
 * y la migración de datos pasa a DeepSeek SOLO el `agente_proveedor` de los DUEÑOS que estaban en
 * Claude (decisión 3 de Lucas), sin tocar a los empleados, ni el pensamiento, ni la confianza, ni a
 * los que ya estaban en DeepSeek; su down() no revierte datos.
 *
 * La migración de datos se corre a mano sobre filas creadas en el test (dentro de la transacción):
 * `migrate` ya la corrió sobre la base de testing antes, y volver a correr up() es inocuo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Migracion_de_datos_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * @param  string      $nombre
     * @param  int|null    $owner_id
     * @param  string      $proveedor
     * @param  string      $pensamiento
     * @return User
     */
    protected function usuario($nombre, $owner_id, $proveedor, $pensamiento)
    {
        $user = User::create([
            'name'     => $nombre,
            'email'    => 'modelos-ia-m6-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $user->owner_id           = $owner_id;
        $user->agente_proveedor   = $proveedor;
        $user->agente_pensamiento = $pensamiento;
        $user->agente_confianza   = 'cauteloso';
        $user->save();

        return $user;
    }

    /**
     * La migración de datos, cargada desde su archivo (las migraciones no tienen autoload).
     *
     * @return \Illuminate\Database\Migrations\Migration
     */
    protected function migracion()
    {
        require_once base_path('database/migrations/2026_09_30_100100_pasar_agente_a_deepseek.php');

        return new \PasarAgenteADeepseek();
    }

    /**
     * @test
     */
    public function las_tres_columnas_existen_y_nacen_en_null()
    {
        foreach (['ia_modelo_whatsapp', 'ia_modelo_imagenes', 'ia_modelo_excel'] as $columna) {
            $this->assertTrue(Schema::hasColumn('users', $columna), $columna);
        }

        $user = User::create([
            'name'     => 'Comercio nuevo M6',
            'email'    => 'modelos-ia-m6-nuevo-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ])->fresh();

        $this->assertNull($user->ia_modelo_whatsapp, 'Null = el default de la tarea, que vive en código.');
        $this->assertNull($user->ia_modelo_imagenes);
        $this->assertNull($user->ia_modelo_excel);
    }

    /**
     * @test
     */
    public function pasa_a_deepseek_solo_a_los_duenos_en_claude_y_no_toca_nada_mas()
    {
        $dueno_en_claude   = $this->usuario('Dueño en Claude M6', null, 'anthropic', 'equilibrado');
        $dueno_en_deepseek = $this->usuario('Dueño en DeepSeek M6', null, 'deepseek', 'profundo');
        $empleado          = $this->usuario('Empleado en Claude M6', $dueno_en_claude->id, 'anthropic', 'agil');

        $this->migracion()->up();

        $dueno_en_claude   = $dueno_en_claude->fresh();
        $dueno_en_deepseek = $dueno_en_deepseek->fresh();
        $empleado          = $empleado->fresh();

        $this->assertSame('deepseek', (string) $dueno_en_claude->agente_proveedor, 'El dueño en Claude pasa a DeepSeek.');
        $this->assertSame('equilibrado', (string) $dueno_en_claude->agente_pensamiento, 'El pensamiento no se toca (equilibrado cae solo a Flash).');
        $this->assertSame('cauteloso', (string) $dueno_en_claude->agente_confianza, 'La confianza no se toca.');

        $this->assertSame('deepseek', (string) $dueno_en_deepseek->agente_proveedor);
        $this->assertSame('profundo', (string) $dueno_en_deepseek->agente_pensamiento);

        $this->assertSame('anthropic', (string) $empleado->agente_proveedor, 'Solo los dueños: la config se lee del dueño.');

        /* down() no revierte datos. */
        $this->migracion()->down();

        $this->assertSame('deepseek', (string) $dueno_en_claude->fresh()->agente_proveedor);
    }
}
