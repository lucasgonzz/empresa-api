<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\Seeders\ExcluirListaDePrecioExcelHelper;
use Database\Seeders\sales\SaleReporteArticuloSeeder;
use Database\Seeders\sales\SaleReporteSeeder;
use Database\Seeders\sales\SaleRoadMapSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

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

        $for_user = config('app.FOR_USER');
        Log::info('env user_Id: '.config('app.USER_ID'));

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

            // Se usan tanto para local y produccion
            $this->common_seeders();    



            $this->call(UserSeeder::class);


            if ($for_user == 'golo_norte') {

                // Llamo antes para despues poder relacionarlos con las categorias
                $this->call(PriceTypeSeeder::class);

            }


            // Se usan en local y para la version demo
            $this->local_y_demo();



            if ($for_user == 'truvari') {

                if (env('APP_ENV') == 'local') {
                    $this->call(BodegaSeeder::class);
                    $this->call(CepaSeeder::class);
                    $this->call(ArticleSeeder::class);
                    $this->call(PromocionVinotecaSeeder::class);
                }
                $this->call(DeliveryDaySeeder::class);
                $this->call(ArticlePdfObservationSeeder::class);

            } else if ($for_user == 'colman') {

                $this->call(ArticleSeeder::class);
                $this->call(PriceTypeSeeder::class);
                $this->call(RecipeArticleSeeder::class);
                $this->call(RecipeSeeder::class);

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
                // $this->call(CajaSeeder::class);

            } else if ($for_user == 'golo_norte') {

                $this->call(ArticleSeeder::class);
                // $this->call(ComboSeeder::class);
                $this->call(CategoryPriceTypeRangeSeeder::class);
                $this->call(ArticlePriceTypeGroupSeeder::class);

                // $this->call(SaleRoadMapSeeder::class);

                // $this->call(RoadMapSeeder::class);

            } else if ($for_user == 'ferretodo') {

                $this->call(ArticleSeeder::class);
                $this->call(PriceTypeSeeder::class);

            } else if ($for_user == 'ros_mar') {

                $this->call(ArticleSeeder::class);

            } else if ($for_user == 'hipermax') {

                $this->call(ArticleSeeder::class);
                
            } else if ($for_user == 'mza_group') {

                Log::info('mza_group seeder');
                $this->call(PriceTypeSeeder::class);
                $this->call(ArticlesTiendaNubeSeeder::class);
                // $this->call(ArticleSeeder::class);
                // $this->article_variants();
                

            } else if ($for_user == 'bad_girls') {

                Log::info('bad_girls seeder');
                $this->call(PriceTypeSeeder::class);
                $this->call(AddressSeeder::class);
                $this->call(ArticleSeeder::class);
                $this->article_variants();
                

            } else if ($for_user == 'trama') {

                $this->call(PriceTypeSeeder::class);
                $this->call(AddressSeeder::class);
                $this->call(ArticleSeeder::class);
                

            } else if ($for_user == 'electro_lacarra') {

                $this->call(PriceTypeSeeder::class);
                $this->call(ArticleSeeder::class);
                
                ExcluirListaDePrecioExcelHelper::set_articles();

            } else if ($for_user == 'racing_carts') {

                $this->call(AddressSeeder::class);
                $this->call(ArticleUbicationSeeder::class);

                $this->call(PriceTypeSeeder::class);
                $this->call(ArticleSeeder::class);
                // $this->call(ArticleDolarSeeder::class);

                
                $this->call(SaleReporteSeeder::class);
                $this->call(SaleReporteArticuloSeeder::class);

            } else if ($for_user == 'leudinox') {

                $this->call(PriceTypeSeeder::class);
                $this->call(MercadoLibreTokenSeeder::class);
                $this->call(AddressSeeder::class);
                // $this->call(MeliArticleSeeder::class);

            } else if ($for_user == 'san_blas') {

                $this->call(AddressSeeder::class);
                $this->call(ArticleSeeder::class);

            } else if ($for_user == 'arfren') {

                $this->call(ArticleSeeder::class);

            } else {

                if (
                    env('APP_ENV') == 'local'
                    || $for_user == 'demo'
                ) {

                    $this->call(ArticleSeeder::class);
                }
            }
            
            $this->call(BudgetSeeder::class);
            $this->call(ChequeSeeder::class);
            $this->call(CajaSeeder::class);
            $this->call(TurnoCajaSeeder::class);


            if ($for_user == 'demo') {
                $this->call(SaleReporteSeeder::class);
                $this->call(SaleReporteArticuloSeeder::class);
            }

            if ($for_user == 'truvari') {
                if (env('APP_ENV') == 'local') {
                    $this->call(SaleRoadMapSeeder::class);
                    $this->call(RoadMapSeeder::class);
                    $this->call(CartSeeder::class);
                }
                $this->call(VentaTerminadaCommissionSeeder::class);
                $this->call(PromocionVinotecaCommissionSeeder::class);
                $this->call(CommissionSeeder::class);
            }

            // $this->call(SaleDemoSeeder::class);

        }
    }

    function local_y_demo() {

        if (
            env('APP_ENV') == 'local'
            || config('app.FOR_USER') == 'demo'
        ) {

            $this->call(CategorySeeder::class);
            $this->call(SubCategorySeeder::class);



            // Por alguna rezon, a estos los llamaba despues de los seeders de cada negocio

            $this->call(ProviderSeeder::class);
            $this->call(ProviderDiscountSeeder::class);
            $this->call(ProviderPriceListSeeder::class);
            $this->call(ColorSeeder::class);
            $this->call(DepositSeeder::class);
            $this->call(ClientSeeder::class);
            $this->call(BuyerSeeder::class);
            $this->call(DiscountSeeder::class);
            $this->call(SurchageSeeder::class);
            $this->call(ProviderOrderSeeder::class);
            $this->call(ProviderPagosSeeder::class);
            $this->call(TitleSeeder::class);
            $this->call(DeliveryZoneSeeder::class);
            $this->call(UpdateFeatureSeeder::class);
            $this->call(OrderSeeder::class);
            $this->call(InventoryLinkageScopeSeeder::class);
            
            $this->call(MessageSeeder::class);

            $this->call(ExpenseCategorySeeder::class);
            $this->call(ExpenseConceptSeeder::class);
            $this->call(ExpenseSeeder::class);
            $this->call(PendingSeeder::class);

            $this->call(EmployeeSeeder::class);
            $this->call(SellerSeeder::class);

        }
    }

    function common_seeders() {
        $this->call(SaleChannelSeeder::class);
        $this->call(MonedaSeeder::class);
        $this->call(MeliListingTypeSeeder::class);
        $this->call(MeliBuyingModeSeeder::class);
        $this->call(MeliItemConditionSeeder::class);
        $this->call(ProvinciaSeeder::class);
        $this->call(PaisExportacionSeeder::class);
        $this->call(CheckStatusSeeder::class);
        $this->call(OnlineTemplateSeeder::class);
        $this->call(ExtencionSeeder::class);
        $this->call(ConceptoStockMovementSeeder::class);
        $this->call(UnidadMedidaSeeder::class);
        $this->call(PermissionSeeder::class);
        $this->call(NuevosPermisosListadoSeeder::class);
        $this->call(OrderStatusSeeder::class);
        $this->call(TiendaNubeOrderStatusSeeder::class);
        $this->call(ProviderOrderStatusSeeder::class);
        $this->call(OnlinePriceTypeSeeder::class);
        $this->call(DepositMovementStatusSeeder::class);


        // Estos estaban a lo ultimo
        $this->call(IvaSeeder::class);
        
        $this->call(IvaConditionSeeder::class);

        // $this->call(OrderProductionStatusSeeder::class);
        $this->call(CurrentAcountPaymentMethodSeeder::class);
        $this->call(BudgetStatusSeeder::class);

        $this->call(ArticleTicketInfoSeeder::class);

        $this->call(UnidadFrecuenciaSeeder::class);

        $this->call(ConceptoMovimientoCajaSeeder::class);

        $this->call(AfipTipoComprobanteSeeder::class);
    }

    function article_variants() {
        $this->call(ArticlePropertyTypeSeeder::class);
        $this->call(ArticlePropertyValueSeeder::class);
        $this->call(ArticlePropertySeeder::class);

        $this->call(ArticleVariantSeeder::class);
    }
}
