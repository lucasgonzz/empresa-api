<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\Expense;

class MigrateDataToExpensePaymentMethodTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $expenses = Expense::whereNotNull('current_acount_payment_method_id')->get();

        foreach ($expenses as $expense) {
            if ($expense->amount) {
                DB::table('expense_current_acount_payment_method')->insert([
                    'expense_id' => $expense->id,
                    'amount' => $expense->amount,
                    'caja_id' => !is_null($expense->caja_id) ? $expense->caja_id : 0,
                    'current_acount_payment_method_id' => $expense->current_acount_payment_method_id,
                    'created_at' => $expense->created_at,
                    'updated_at' => $expense->updated_at,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::table('expense_current_acount_payment_method')->truncate();
    }
}