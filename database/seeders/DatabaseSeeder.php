<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        // $this->call(ExtencionSeeder::class);
        // $this->call(UserSeeder::class);

        // return;

        $for_user = env('FOR_USER');

        if (env('FOR_SERVER') == 'la_barraca') {
            $this->call(UserSeeder::class);
            $this->call(ExtencionSeeder::class);
            $this->call(PermissionSeeder::class);
            // $this->call(FeaturesSeeder::class);
            // $this->call(PlansSeeder::class);
            $this->call(OrderStatusSeeder::class);
            $this->call(ProviderOrderStatusSeeder::class);
            $this->call(ColorSeeder::class);
            $this->call(IvaSeeder::class);
            $this->call(IvaConditionSeeder::class);
            // $this->call(WorkdaySeeder::class);
            $this->call(CurrentAcountPaymentMethodSeeder::class);
            $this->call(BudgetStatusSeeder::class);
        } else {
            $this->call(ExtencionSeeder::class);

            $this->call(ArticlePreImportRangeSeeder::class);

            // $this->call(PermissionsTableSeeder::class);
            $this->call(UnidadMedidaSeeder::class);
            $this->call(PermissionSeeder::class);
            // $this->call(FeaturesSeeder::class);
            $this->call(PlanFeatureSeeder::class);
            $this->call(PlanSeeder::class);
            $this->call(OrderStatusSeeder::class);
            $this->call(ProviderOrderStatusSeeder::class);
            $this->call(OnlinePriceTypeSeeder::class);
            $this->call(UserSeeder::class);
            $this->call(CategorySeeder::class);
            $this->call(SubCategorySeeder::class);
            $this->call(SellerSeeder::class);
            $this->call(ProviderSeeder::class);
            $this->call(ProviderPriceListSeeder::class);
            $this->call(ColorSeeder::class);
            $this->call(IvaSeeder::class);
            $this->call(DepositSeeder::class);
            $this->call(ArticleSeeder::class);
            $this->call(IvaConditionSeeder::class);
            $this->call(ClientSeeder::class);
            $this->call(BuyerSeeder::class);
            $this->call(DiscountSeeder::class);
            $this->call(SurchageSeeder::class);
            // $this->call(SaleTypeSeeder::class);
            $this->call(CommissionSeeder::class);
            // $this->call(CurrentAcountSeeder::class);
            // $this->call(ScheduleSeeder::class);
            $this->call(AddressSeeder::class);

            // $this->call(MeLiOrderSeeder::class);
            
            $this->call(ProviderOrderSeeder::class);
            // $this->call(EmployeeSeeder::class);
            $this->call(SaleSeeder::class);
            // $this->call(WorkdaySeeder::class);
            // $this->call(ConditionSeeder::class);
            $this->call(TitleSeeder::class);
            // $this->call(BrandSeeder::class);
            // $this->call(SizeSeeder::class);
            // $this->call(PricesListSeeder::class);
            // $this->call(PlateletSeeder::class);
            $this->call(OrderProductionStatusSeeder::class);
            $this->call(CurrentAcountPaymentMethodSeeder::class);
            $this->call(PaymentMethodSeeder::class);
            $this->call(DeliveryZoneSeeder::class);
            // $this->call(LocationSeeder::class);
            $this->call(PaymentMethodTypeSeeder::class);
            // $this->call(CuponSeeder::class);
            $this->call(BudgetStatusSeeder::class);
            $this->call(BudgetSeeder::class);
            $this->call(RecipeSeeder::class);
            // $this->call(OrderProductionSeeder::class);
            $this->call(SuperBudgetSeeder::class);
            // $this->call(CreditCardSeeder::class);
            // $this->call(CreditCardPaymentPlanSeeder::class);
            $this->call(UpdateFeatureSeeder::class);
            $this->call(OrderSeeder::class);
            $this->call(InventoryLinkageScopeSeeder::class);
            // $this->call(InventoryLinkageSeeder::class);
            // $this->call(AfipTicketSeeder::class);


            $this->article_variants();

            
            $this->call(CartSeeder::class);
            $this->call(MessageSeeder::class);
            $this->call(ArticleTicketInfoSeeder::class);
            // $this->call(ArticlePerformanceSeeder::class);
            // $this->call(StockMovementSeeder::class);
            // $this->call(PriceChangeSeeder::class);

            $this->call(ExpenseConceptSeeder::class);
            $this->call(ExpenseSeeder::class);

            $this->call(UnidadFrecuenciaSeeder::class);
            $this->call(PendingSeeder::class);


            if ($for_user == 'colman') {

                $this->call(PriceTypeSeeder::class);

            } else if ($for_user == 'feito') {

                $this->call(CurrentAcountPaymentMethodDiscountSeeder::class);
                $this->call(AfipSelectedPaymentMethodSeeder::class);
            }



        }
    }

    function article_variants() {
        $this->call(ArticlePropertyTypeSeeder::class);
        $this->call(ArticlePropertyValueSeeder::class);
        $this->call(ArticlePropertySeeder::class);

        $this->call(ArticleVariantSeeder::class);
    }
}
