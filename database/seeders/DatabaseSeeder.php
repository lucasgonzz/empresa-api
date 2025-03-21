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

            $this->call(ConceptoStockMovementSeeder::class);

            $this->call(ArticlePreImportRangeSeeder::class);

            $this->call(DepositMovementStatusSeeder::class);

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


            if ($for_user == 'golo_norte') {

                // Llamo antes para despues poder relacionarlos con las categorias
                $this->call(PriceTypeSeeder::class);

            }

            if (env('APP_ENV') == 'local') {

                $this->call(CategorySeeder::class);
                $this->call(SubCategorySeeder::class);
            }



            if ($for_user == 'colman') {

                $this->call(ArticleSeeder::class);
                $this->call(PriceTypeSeeder::class);
                // $this->call(RecipeSeeder::class);

            } else if ($for_user == 'feito') {

                $this->article_variants();
                
                $this->call(ArticleSeeder::class);
                
                $this->call(CurrentAcountPaymentMethodDiscountSeeder::class);
                $this->call(AfipSelectedPaymentMethodSeeder::class);

                $this->call(CuotaSeeder::class);


            } else if ($for_user == 'fenix') {

                $this->call(ArticleSeeder::class);
                $this->call(SaleTypeSeeder::class);
                $this->call(CommissionSeeder::class);
                
            } else if ($for_user == 'pack_descartables') {

                $this->call(PriceTypeSeeder::class);
                $this->call(ArticleSeeder::class);
                $this->call(CajaSeeder::class);

            } else if ($for_user == 'golo_norte') {

                if (env('APP_ENV') == 'local') {

                    $this->call(ArticleSeeder::class);
                    $this->call(ComboSeeder::class);
                    $this->call(CategoryPriceTypeRangeSeeder::class);
                    $this->call(ArticlePriceTypeGroupSeeder::class);
                }


            } else if ($for_user == 'ferretodo') {

                $this->call(ArticleSeeder::class);
                $this->call(PriceTypeSeeder::class);

            } else if ($for_user == 'ros_mar') {

                $this->call(ArticleSeeder::class);

            } else if ($for_user == 'hipermax') {

                $this->call(ArticleSeeder::class);
            }


            if (env('APP_ENV') == 'local') {

                $this->call(ProviderSeeder::class);
                $this->call(ProviderPriceListSeeder::class);
                $this->call(ColorSeeder::class);
                $this->call(SellerSeeder::class);
                $this->call(DepositSeeder::class);
                $this->call(ClientSeeder::class);
                $this->call(BuyerSeeder::class);
                $this->call(DiscountSeeder::class);
                $this->call(SurchageSeeder::class);
                $this->call(AddressSeeder::class);
                // $this->call(ProviderOrderSeeder::class);
                $this->call(ProviderPagosSeeder::class);
                // $this->call(SaleSeeder::class);
                $this->call(TitleSeeder::class);
                $this->call(DeliveryZoneSeeder::class);
                $this->call(BudgetSeeder::class);
                $this->call(UpdateFeatureSeeder::class);
                $this->call(OrderSeeder::class);
                $this->call(InventoryLinkageScopeSeeder::class);
                $this->call(InventoryLinkageSeeder::class);
                
                $this->call(MessageSeeder::class);

                $this->call(ExpenseConceptSeeder::class);
                $this->call(ExpenseSeeder::class);
                $this->call(PendingSeeder::class);
            }



            /* 
                Estos de abajo se ejectuan siempre.
                Son los unicos que se ejectuan en produccion,
                junto con los propios de caja "for_user"
            */
            $this->call(IvaSeeder::class);
            
            $this->call(IvaConditionSeeder::class);

            $this->call(OrderProductionStatusSeeder::class);
            $this->call(CurrentAcountPaymentMethodSeeder::class);
            $this->call(BudgetStatusSeeder::class);

            $this->call(ArticleTicketInfoSeeder::class);

            $this->call(UnidadFrecuenciaSeeder::class);

            $this->call(ConceptoMovimientoCajaSeeder::class);

            $this->call(AfipTipoComprobanteSeeder::class);


        }
    }

    function article_variants() {
        $this->call(ArticlePropertyTypeSeeder::class);
        $this->call(ArticlePropertyValueSeeder::class);
        $this->call(ArticlePropertySeeder::class);

        $this->call(ArticleVariantSeeder::class);
    }
}
