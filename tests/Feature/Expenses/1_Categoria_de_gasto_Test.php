<?php

namespace Tests\Feature\Expenses;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseConcept;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature tests de la categoria del gasto (grupo 277, prompt 03). Estrena la suite `gastos` en
 * empresa-api/develop.
 *
 * Protege las tres cosas del prompt 01 que fallan en SILENCIO (no tiran error, dejan un dato
 * desalineado que recien se nota meses despues, cuando un reporte por categoria da un numero
 * raro): que la derivacion corra por cualquier camino de escritura, que un
 * expense_category_id mandado a mano en el request no le gane al concepto, y que la
 * propagacion al editar un concepto alcance a todos sus gastos (y solo a esos).
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing de esta rama esta sembrada de
 * antes y un refresh la vaciaria, rompiendo el resto de las suites. Cada test crea sus propios
 * datos (categorias, conceptos y gastos con user_id 500) en vez de depender de fixtures.
 */
class Categoria_de_gasto_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario autenticado de los tests de esta rama (mismo patron que Sales/Stock). Null si la
     * base de testing no lo tiene sembrado.
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * @group gastos
     * @test
     */
    public function el_gasto_toma_la_categoria_de_su_concepto()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $categoria_a = ExpenseCategory::create(['name' => 'zz Categoria A', 'user_id' => 500]);
        $concepto = ExpenseConcept::create([
            'num' => 900001,
            'name' => 'zz Concepto A',
            'expense_category_id' => $categoria_a->id,
            'user_id' => 500,
        ]);

        // El request NO manda expense_category_id: ese es el punto de este test.
        $response = $this->post('api/expense', [
            'expense_concept_id' => $concepto->id,
            'amount' => 100,
            'payment_methods' => [],
        ]);

        $response->assertStatus(201);

        $gasto = Expense::find($response->json('model.id'));
        $this->assertEquals($categoria_a->id, $gasto->expense_category_id);
    }

    /**
     * @group gastos
     * @test
     */
    public function la_categoria_mandada_en_el_request_no_le_gana_al_concepto()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $categoria_a = ExpenseCategory::create(['name' => 'zz Categoria A', 'user_id' => 500]);
        $categoria_b = ExpenseCategory::create(['name' => 'zz Categoria B', 'user_id' => 500]);
        $concepto = ExpenseConcept::create([
            'num' => 900002,
            'name' => 'zz Concepto A',
            'expense_category_id' => $categoria_a->id,
            'user_id' => 500,
        ]);

        // Se manda adrede expense_category_id de la categoria B: tiene que ignorarse.
        $response = $this->post('api/expense', [
            'expense_concept_id' => $concepto->id,
            'expense_category_id' => $categoria_b->id,
            'amount' => 100,
            'payment_methods' => [],
        ]);

        $response->assertStatus(201);

        $gasto = Expense::find($response->json('model.id'));
        $this->assertEquals($categoria_a->id, $gasto->expense_category_id);
    }

    /**
     * @group gastos
     * @test
     */
    public function un_concepto_sin_categoria_deja_el_gasto_sin_categoria()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $concepto = ExpenseConcept::create([
            'num' => 900003,
            'name' => 'zz Concepto Sin Categoria',
            'expense_category_id' => null,
            'user_id' => 500,
        ]);

        $response = $this->post('api/expense', [
            'expense_concept_id' => $concepto->id,
            'amount' => 100,
            'payment_methods' => [],
        ]);

        $response->assertStatus(201);

        $gasto = Expense::find($response->json('model.id'));
        // assertNull a proposito: 0 es un id que no existe, null es "no tiene".
        $this->assertNull($gasto->expense_category_id);
    }

    /**
     * @group gastos
     * @test
     */
    public function cambiarle_la_categoria_al_concepto_mueve_los_gastos_existentes()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $categoria_a = ExpenseCategory::create(['name' => 'zz Categoria A', 'user_id' => 500]);
        $categoria_b = ExpenseCategory::create(['name' => 'zz Categoria B', 'user_id' => 500]);

        $concepto = ExpenseConcept::create([
            'num' => 900004,
            'name' => 'zz Concepto A',
            'expense_category_id' => $categoria_a->id,
            'user_id' => 500,
        ]);

        $otro_concepto = ExpenseConcept::create([
            'num' => 900005,
            'name' => 'zz Otro Concepto',
            'expense_category_id' => $categoria_a->id,
            'user_id' => 500,
        ]);

        $gasto_1 = Expense::create([
            'expense_concept_id' => $concepto->id,
            'amount' => 100,
            'user_id' => 500,
        ]);

        $gasto_2 = Expense::create([
            'expense_concept_id' => $concepto->id,
            'amount' => 200,
            'user_id' => 500,
        ]);

        // Gasto de OTRO concepto: la propagacion no tiene que tocarlo.
        $gasto_de_otro_concepto = Expense::create([
            'expense_concept_id' => $otro_concepto->id,
            'amount' => 300,
            'user_id' => 500,
        ]);

        $response = $this->putJson('api/expense-concept/' . $concepto->id, [
            'name' => $concepto->name,
            'expense_category_id' => $categoria_b->id,
        ]);

        $response->assertStatus(200);

        $this->assertEquals($categoria_b->id, $gasto_1->fresh()->expense_category_id);
        $this->assertEquals($categoria_b->id, $gasto_2->fresh()->expense_category_id);
        $this->assertEquals($categoria_a->id, $gasto_de_otro_concepto->fresh()->expense_category_id);
    }

    /**
     * @group gastos
     * @test
     */
    public function el_listado_de_gastos_se_puede_filtrar_por_categoria()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $categoria_a = ExpenseCategory::create(['name' => 'zz Categoria A', 'user_id' => 500]);
        $categoria_b = ExpenseCategory::create(['name' => 'zz Categoria B', 'user_id' => 500]);

        $concepto_a = ExpenseConcept::create([
            'num' => 900006,
            'name' => 'zz Concepto A',
            'expense_category_id' => $categoria_a->id,
            'user_id' => 500,
        ]);

        $concepto_b = ExpenseConcept::create([
            'num' => 900007,
            'name' => 'zz Concepto B',
            'expense_category_id' => $categoria_b->id,
            'user_id' => 500,
        ]);

        $gasto_a = Expense::create([
            'expense_concept_id' => $concepto_a->id,
            'amount' => 100,
            'user_id' => 500,
        ]);

        $gasto_b = Expense::create([
            'expense_concept_id' => $concepto_b->id,
            'amount' => 200,
            'user_id' => 500,
        ]);

        // Mismo contrato que arma build_table_filters_from_props() en el SPA para un filtro
        // select: key/type/igual_que son las claves que lee el foreach de SearchController.
        $response = $this->postJson('api/search/expense/null/1', [
            'filters' => [
                [
                    'key' => 'expense_category_id',
                    'type' => 'select',
                    'igual_que' => $categoria_a->id,
                ],
            ],
        ]);

        $response->assertStatus(200);

        $ids = array_column($response->json('data'), 'id');

        $this->assertContains($gasto_a->id, $ids);
        $this->assertNotContains($gasto_b->id, $ids);
    }
}
