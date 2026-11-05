<?php

namespace Tests\Feature\Expenses;

use App\Models\CurrentAcountPaymentMethod;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseConcept;
use App\Models\PermissionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Exportar_Gastos_Test extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/api/expense/export/data?from_date=2026-10-01&until_date=2026-10-31';

    public function test_respeta_autenticacion_permiso_y_rango()
    {
        $this->getJson(self::URL)->assertStatus(401);
        $dueno = User::factory()->create();
        $empleado = User::factory()->create(['owner_id' => $dueno->id, 'admin_access' => false]);
        $this->actingAs($empleado, 'web')->getJson(self::URL)->assertStatus(403);

        $permiso = PermissionEmpresa::where('slug', 'expense.index')->firstOrFail();
        $empleado->permissions()->attach($permiso->id);
        $this->getJson('/api/expense/export/data?from_date=2026-10-31&until_date=2026-10-01')
            ->assertStatus(422)->assertJsonValidationErrors('until_date');
        $this->getJson('/api/expense/export/data?from_date=2026-02-31')
            ->assertStatus(422)->assertJsonValidationErrors(['from_date', 'until_date']);

        $inicio = $this->gasto($dueno, '2026-10-01 00:00:00');
        $fin = $this->gasto($dueno, '2026-10-31 23:59:59');
        $this->gasto($dueno, '2026-09-30 23:59:59');
        $this->gasto($dueno, '2026-11-01 00:00:00');
        $this->gasto(User::factory()->create(), '2026-10-04 12:00:00');

        $this->getJson(self::URL)->assertOk()->assertJsonCount(2, 'models')
            ->assertJsonPath('models.0.id', $fin->id)
            ->assertJsonPath('models.0.fecha_gasto', '2026-10-31')
            ->assertJsonPath('models.0.pagos_exportacion', [])
            ->assertJsonPath('models.1.id', $inicio->id)
            ->assertJsonPath('models.1.fecha_gasto', '2026-10-01');
    }

    public function test_conserva_pagos_combinados_en_su_moneda_y_metodos_borrados()
    {
        $dueno = User::factory()->create();
        $this->actingAs($dueno, 'web');
        $categoria = ExpenseCategory::create(['name' => 'Servicios', 'user_id' => $dueno->id]);
        $concepto = ExpenseConcept::create([
            'name' => 'Internet',
            'num' => 1,
            'user_id' => $dueno->id,
            'expense_category_id' => $categoria->id,
        ]);
        $metodo = CurrentAcountPaymentMethod::query()->firstOrFail();
        $metodoBorrado = 10000001;
        $legacy = $this->gasto($dueno, '2026-10-15 00:00:00', [
            'current_acount_payment_method_id' => $metodo->id,
            'moneda_id' => null,
        ]);
        $combinado = $this->gasto($dueno, '2026-10-20 00:00:00', ['expense_concept_id' => $concepto->id]);
        DB::table('expense_current_acount_payment_method')->insert([
            ['expense_id' => $combinado->id, 'current_acount_payment_method_id' => $metodo->id,
                'moneda_id' => 1, 'amount' => 30, 'amount_cotizado' => null],
            ['expense_id' => $combinado->id, 'current_acount_payment_method_id' => $metodoBorrado,
                'moneda_id' => 2, 'amount' => 0.50, 'amount_cotizado' => 70],
        ]);

        $respuesta = $this->getJson(self::URL)->assertOk()->assertJsonCount(2, 'models')
            ->assertJsonPath('models.0.id', $combinado->id)
            ->assertJsonPath('models.0.categoria_id', $categoria->id)
            ->assertJsonPath('models.0.categoria', 'Servicios')
            ->assertJsonPath('models.0.concepto', 'Internet')
            ->assertJsonCount(2, 'models.0.pagos_exportacion')
            ->assertJsonPath('models.0.pagos_exportacion.0.name', $metodo->name)
            ->assertJsonPath('models.0.pagos_exportacion.1.name', null)
            ->assertJsonPath('models.0.pagos_exportacion.1.current_acount_payment_method_id', $metodoBorrado)
            ->assertJsonPath('models.0.pagos_exportacion.1.moneda_id', 2)
            ->assertJsonPath('models.1.id', $legacy->id)
            ->assertJsonPath('models.1.metodo_legacy', $metodo->name);
        $this->assertEquals(0.50, $respuesta->json('models.0.pagos_exportacion.1.amount'));
    }

    private function gasto(User $dueno, $fecha, array $atributos = [])
    {
        return Expense::forceCreate(array_merge([
            'user_id' => $dueno->id,
            'created_at' => $fecha,
            'moneda_id' => 1,
            'amount' => 100,
        ], $atributos));
    }
}
