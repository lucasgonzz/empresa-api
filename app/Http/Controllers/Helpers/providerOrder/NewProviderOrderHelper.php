<?php

namespace App\Http\Controllers\Helpers\providerOrder;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\article\ArticleProviderDiscountHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Article;
use App\Models\ArticleSurchage;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Iva;
use App\Models\MovimientoCaja;
use App\Models\Provider;
use App\Models\ProviderOrderDiscount;
use App\Models\ProviderOrderExtraCost;
use App\Services\Compras\OfertasDeProveedorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class NewProviderOrderHelper {

    public $provider_order;
    public $new_articles;
    public $ultimos_articulos_recividos;

	function __construct($provider_order, $new_articles, $ya_se_actualizo_stock = false) {

        $this->provider_order           = $provider_order;
        $this->new_articles             = $new_articles;
        $this->ya_se_actualizo_stock    = $ya_se_actualizo_stock;
        $this->user                     = UserHelper::user();

        $this->set_credit_account();

        $this->set_ultimos_articulos_recividos();

        $this->set_ivas();
    }

    function procesar_pedido() {

        // $this->attach_articles();

        $this->set_totales();

        // Prompt 306: reemplaza el prorrateo horneado sobre articles.cost (método eliminado
        // aplicar_descuento_compra_a_costo_articulos, prompt 262) por la materialización
        // explícita de los descuentos vigentes de la orden como article_discounts tagueados con
        // el proveedor. Tiene que ir DESPUÉS de set_totales() porque necesita
        // provider_order_discounts ya cargados (set_totales() hace el load()).
        $this->materializar_descuentos_proveedor_en_articulos();

        // Prompt 264: los costos extra tipados de la compra (flete, seguro, arancel de
        // importación) se prorratean entre los artículos y se materializan como recargo
        // (article_surchage) de cada artículo. Corre después de set_totales() porque necesita
        // sub_total y provider_order_extra_costs ya cargados/calculados.
        $this->aplicar_costos_extra_a_recargos_articulos();

        $this->set_current_acount();
    }

    /**
     * Prompt 262 - Tarea 1: pre-carga las bonificaciones del proveedor (provider_discounts,
     * dato maestro de la negociación con el proveedor) como descuentos EDITABLES de esta orden
     * de compra puntual (provider_order_discounts), al momento de crearla.
     *
     * Racional: desde el prompt 261, la bonificación del proveedor dejó de aplicarse
     * automáticamente al costo de catálogo (Capa 1). Ahora es solo un dato "sugerido" que
     * pre-completa el descuento de la orden de compra concreta, para que el usuario lo vea, lo
     * pueda editar o eliminar antes de confirmar la compra. Recién lo que quede cargado en
     * provider_order_discounts en el momento de confirmar es lo que impacta el costo real, vía
     * aplicar_descuento_compra_a_costo_articulos().
     *
     * No pisa nada si la orden ya trae descuentos propios (por ejemplo, si el usuario ya los
     * mandó explícitamente en el request al crear la orden) — solo pre-completa cuando la orden
     * todavía no tiene ningún provider_order_discount cargado.
     */
    function precargar_bonificaciones_proveedor() {

        // Si la orden ya tiene descuentos propios cargados, no se pisan con los del proveedor.
        if ($this->provider_order->provider_order_discounts()->count() > 0) {
            return;
        }

        $provider = Provider::find($this->provider_order->provider_id);

        if (is_null($provider)) {
            return;
        }

        foreach ($provider->provider_discounts as $provider_discount) {

            // Solo se pre-cargan bonificaciones porcentuales (dato maestro del proveedor,
            // ver ProviderDiscount): si no tiene porcentaje cargado, no hay nada que copiar.
            if (is_null($provider_discount->percentage) || $provider_discount->percentage == '') {
                continue;
            }

            ProviderOrderDiscount::create([
                'description'        => 'Bonificación de proveedor',
                'percentage'         => $provider_discount->percentage,
                'provider_order_id'  => $this->provider_order->id,
            ]);
        }

        // Se vuelve a cargar la relación para que set_totales() (que corre después) vea
        // los descuentos recién creados.
        $this->provider_order->load('provider_order_discounts');
    }

    /**
     * Prompt 306 — Tareas 2/3/6: reemplaza el prorrateo horneado sobre `articles.cost` (método
     * eliminado `aplicar_descuento_compra_a_costo_articulos`, prompt 262) por la materialización
     * explícita de los descuentos vigentes de la orden (`provider_order_discounts`, ya cargados o
     * editados por el usuario) como `article_discounts` tagueados con el proveedor de la orden.
     *
     * Racional: antes el descuento se aplicaba una sola vez, prorrateado, directo sobre
     * `articles.cost` — y el costo bruto original se perdía. Ahora `articles.cost` (seteado por
     * update_cost() con el costo bruto literal del pivot) NUNCA se toca acá; los descuentos
     * quedan como `article_discounts` explícitos, que el pipeline de precios
     * (ArticlePricesHelper::aplicar_descuentos, vía ArticleHelper::aplicar_descuentos_e_iva) aplica
     * UNA sola vez sobre ese costo bruto para dar el `costo_real`. Evita el doble descuento.
     *
     * A diferencia del prorrateo viejo (que ajustaba el costo unitario según el peso de cada
     * artículo en el total de la compra), acá se copian los MISMOS descuentos (porcentaje o
     * monto) tal cual están cargados en la orden a cada artículo — no se prorratean por peso,
     * porque el pipeline de precios ya los aplica individualmente sobre el costo bruto de cada
     * artículo.
     *
     * Semántica overwrite/último costo (delegada en ArticleProviderDiscountHelper): cada
     * confirmación de compra vuelve a pisar los descuentos tagueados del proveedor, no los
     * acumula.
     *
     * Gate: solo corre con `update_prices` ON (mismo criterio que usa update_cost()/update_price()
     * para decidir si la compra actualiza el costo de catálogo) y si la orden tiene proveedor
     * cargado (sin proveedor no hay a qué taguear el descuento).
     */
    function materializar_descuentos_proveedor_en_articulos() {

        if (!$this->provider_order->update_prices) {
            return;
        }

        $provider_id = $this->provider_order->provider_id;

        if (is_null($provider_id)) {
            return;
        }

        // Descuentos vigentes de la orden (ya cargados/editados por el usuario), sean
        // porcentuales o por monto fijo — se materializan tal cual en cada artículo de la compra.
        $discounts = $this->provider_order->provider_order_discounts;

        foreach ($this->provider_order->articles as $article) {

            $articulo = Article::find($article->id);

            if (is_null($articulo)) {
                continue;
            }

            ArticleProviderDiscountHelper::sync_provider_discounts($articulo, $provider_id, $discounts);

            Log::info('materializar_descuentos_proveedor_en_articulos: descuentos del proveedor '.$provider_id.' materializados en '.$articulo->name);

            // Recalcula costo_real con el costo bruto (sin hornear) + los descuentos recién
            // materializados, aplicados una sola vez por el pipeline de precios.
            ArticleHelper::setFinalPrice($articulo);
        }
    }

    /**
     * Prompt 264 — Tarea 2: prorratea los costos extra "tipados" de la orden de compra
     * (provider_order_extra_costs con `tipo` transporte/seguro/arancel_importacion, ver
     * ProviderOrderExtraCost::TIPO_*) entre los artículos de la compra y materializa el monto
     * prorrateado de cada artículo como un `article_surchage` (App\Models\ArticleSurchage) de
     * ese mismo `tipo`, para que impacte el costo real (Capa 1, vía
     * ArticlePricesHelper::aplicar_recargos()) y participe del cálculo de precio como
     * cualquier recargo cargado a mano.
     *
     * Fórmula de prorrateo por ítem (mismo patrón que aplicar_descuento_compra_a_costo_articulos()
     * y que el prorrateo de bonificaciones del prompt 262):
     *   monto_item = valor_costo_extra * subtotal_item / total_articulos_compra
     * El recargo de artículo (ArticleSurchage->amount) es un valor UNITARIO — se suma directo
     * al precio por unidad en ArticlePricesHelper::aplicar_recargos() — por eso acá se divide el
     * monto prorrateado del ítem por la cantidad comprada (pivot->amount) antes de guardarlo.
     *
     * Criterio "último costo" (confirmado por Lucas, prompt 264): si el artículo YA tiene un
     * recargo del mismo `tipo`, se PISA su `amount` (no se acumula un segundo recargo del mismo
     * tipo). El costo del artículo siempre refleja el flete/seguro/arancel de la ÚLTIMA compra
     * que trajo un costo extra de ese tipo.
     *
     * Nota (decisión documentada, no automatizar): si en una edición de la orden se elimina un
     * extra cost tipado (o se le saca el tipo), el recargo de artículo ya materializado NO se
     * borra automáticamente acá — el criterio último costo asume que una próxima compra con
     * costo extra de ese tipo lo va a pisar, y mientras tanto el usuario lo puede borrar a mano
     * desde el modal del artículo (ArticleSurchageController::destroy).
     *
     * Nota (simetría con precios en blanco, Tarea 3): `article_surchage_blancos` sólo tiene
     * columna `percentage` (no `amount`), no existe un campo unitario equivalente para copiar
     * un recargo por monto fijo como este. Por eso este método NO crea/actualiza recargos en
     * blanco — no hay a dónde propagar un `amount` en esa tabla sin agregar una columna nueva,
     * fuera del alcance de este prompt.
     *
     * Mismo gate que aplicar_descuento_compra_a_costo_articulos(): solo corre si la orden está
     * marcada para actualizar costo/precio de catálogo.
     */
    function aplicar_costos_extra_a_recargos_articulos() {

        if (!$this->provider_order->update_prices) {
            return;
        }

        // Tipos de costo extra que se materializan como recargo de artículo (mismo enum que
        // ArticleSurchage, prompt 260). NULL o TIPO_OTRO: comportamiento actual, solo suman al
        // total de la orden.
        $tipos_materializables = [
            ProviderOrderExtraCost::TIPO_TRANSPORTE,
            ProviderOrderExtraCost::TIPO_SEGURO,
            ProviderOrderExtraCost::TIPO_ARANCEL_IMPORTACION,
        ];

        // Total de artículos de la compra (ya calculado por set_totales(), corre antes en
        // procesar_pedido()), usado como base del prorrateo.
        $total_articulos = (float)$this->provider_order->sub_total;

        if ($total_articulos <= 0) {
            return;
        }

        foreach ($this->provider_order->provider_order_extra_costs as $extra_cost) {

            if (!in_array($extra_cost->tipo, $tipos_materializables)) {
                continue;
            }

            $valor_costo_extra = (float)$extra_cost->value;

            if ($valor_costo_extra <= 0) {
                continue;
            }

            // Prompt 516: consistencia neto/bruto al costo (Capa 1), mismo criterio que el
            // back-out de costo del prompt 514. Si el costo extra vino FACTURADO con alícuota
            // propia (`iva_id`) y el usuario es Responsable Inscripto (IVA recuperable ->
            // `iva_va_al_costo()` false), el IVA de ese costo extra es crédito fiscal, no costo:
            // se prorratea el NETO (se le saca el IVA con SU propia alícuota, no la de los
            // artículos). Si es Monotributista (`iva_va_al_costo()` true) o el costo extra no
            // está facturado, se prorratea el monto entero (comportamiento actual, sin cambios).
            //
            // Prompt 231/02: esta condición ya resuelve por el resolver único
            // `ArticlePricesHelper::iva_va_al_costo()`, igual que el resto del pipeline de
            // costeo/precios. Antes usaba el campo legacy `aplicar_iva_al_costo` directo a
            // propósito (ver historial), porque la columna real de condición fiscal
            // (`usar_condicion_fiscal_en_costeo`) todavía no existía; ya aterrizó, así que este
            // punto queda unificado con `update_cost()` y `catalogar_costo_proveedor()`.
            if ($extra_cost->facturado && (int)$extra_cost->iva_id > 0 && !ArticlePricesHelper::iva_va_al_costo($this->user)) {

                $iva_costo_extra = $this->get_iva($extra_cost->iva_id);

                if (!is_null($iva_costo_extra)) {

                    // Prompt 609: alícuota numérica segura (is_numeric antes de castear), por si
                    // el costo extra viene facturado con un IVA 'Exento'/'No Gravado' (texto).
                    $alicuota_costo_extra = $this->get_alicuota_numerica($iva_costo_extra);

                    if ($alicuota_costo_extra > 0) {
                        // Back-out "final -> neto": neto = bruto / (1 + alicuota/100).
                        $valor_costo_extra = $valor_costo_extra / (1 + ($alicuota_costo_extra / 100));
                    }
                }
            }

            foreach ($this->provider_order->articles as $article) {

                // Subtotal de este ítem, calculado con la misma lógica que usa set_totales()
                // para sumar total_articulos (get_total_article), así el prorrateo es
                // consistente con la base usada.
                $res            = $this->get_total_article($article);
                $subtotal_item  = (float)$res['sub_total_article'];

                if ($subtotal_item <= 0) {
                    continue;
                }

                // Cantidad comprada del ítem (Prompt 609: recibida si está completada -incluido
                // 0-, sino pedida), para llevar el monto prorrateado del ítem a un valor unitario.
                $cantidad = $this->get_cantidad_efectiva($article);

                if ($cantidad <= 0) {
                    continue;
                }

                $monto_prorrateado_item = $valor_costo_extra * $subtotal_item / $total_articulos;
                $monto_unitario         = $monto_prorrateado_item / $cantidad;

                $articulo = Article::find($article->id);

                if (is_null($articulo)) {
                    continue;
                }

                // Criterio último costo: busca un recargo existente del mismo tipo en el
                // artículo para pisar su amount; si no existe, lo crea.
                $surchage = ArticleSurchage::where('article_id', $articulo->id)
                                            ->where('tipo', $extra_cost->tipo)
                                            ->first();

                if (is_null($surchage)) {
                    $surchage                          = new ArticleSurchage();
                    $surchage->article_id              = $articulo->id;
                    $surchage->tipo                     = $extra_cost->tipo;
                    $surchage->luego_del_precio_final   = 0;
                }

                // Es un recargo por monto fijo (unitario), no por porcentaje.
                $surchage->amount      = $monto_unitario;
                $surchage->percentage  = null;
                $surchage->save();

                Log::info('aplicar_costos_extra_a_recargos_articulos: recargo '.$extra_cost->tipo.' de '.$articulo->name.' seteado en '.$monto_unitario.' (costo extra: '.$extra_cost->description.', valor total: '.$valor_costo_extra.')');

                ArticleHelper::setFinalPrice($articulo);
            }
        }
    }

    function set_credit_account() {
        $this->credit_account = CreditAccount::where('model_name', 'provider')
                                                ->where('model_id', $this->provider_order->provider_id)
                                                ->where('moneda_id', $this->provider_order->moneda_id)
                                                ->first();
    }


    /*
        * Si total_from_provider_order_afip_tickets = TRUE
            1. Se calcula $total en base las provider_order_afip_ticket->total
            2. 


        * Si total_from_provider_order_afip_tickets = FALSE
            1. Se calculo $total_articulos en base a los articulos sin tener en cuenta el IVA de cada articulo.
            2. 

    */
    function set_totales() {

        $total_articulos = 0;
        $descuentos_individuales = 0;
        $descuentos_compra = 0;
        $total_descuento = 0;
        $total_costos_extra = 0;
        $total_iva = 0;
        $total = 0;

        $this->provider_order->load([
            'articles',
            'provider_order_afip_tickets',
            'provider_order_discounts',
            'provider_order_extra_costs',
            'provider', // porque en get_total_article lo usás para dólar
        ]);


        $des = [];


        $des[] = 'TOTAL ARTICULOS';
        foreach ($this->provider_order->articles as $article) {

            $res                = $this->get_total_article($article);
            $sub_total_article  = $res['sub_total_article'];
            // $total_article      = $res['total_article'];
            $article_descuento  = $res['total_descuento'];
            // $article_iva        = $res['article_iva']['importe_iva'];

            $total_articulos += $sub_total_article;
            $descuentos_individuales += $article_descuento;

            Log::info('Sumando '.$sub_total_article.' de '.$article->name);
            Log::info('Descuentos de articulo '.$article_descuento);

            $des[] = Numbers::price($sub_total_article, true).' x '.$article->pivot->amount.' u. de '.$article->name;
        }

        if ($total_articulos > 0) {
            if ($descuentos_individuales > 0) {

                $des[] = 'Total articulos (sin descuentos) = '.Numbers::price($total_articulos, true);
            } else {
                $des[] = 'Total articulos = '.Numbers::price($total_articulos, true);
            }
        }

        if ($descuentos_individuales > 0) {

            $des[] = Numbers::price($descuentos_individuales, true).' de descuentos individuales en articulos';  
        }


        if ($this->provider_order->total_from_provider_order_afip_tickets) {

            Log::info('Sumando total de las facturas');

            $des[] = 'CALCULANDO TOTAL EN BASE A FACTURAS';

            foreach ($this->provider_order->provider_order_afip_tickets as $afip_ticket) {
                $total      += $afip_ticket->total;
                $des[] = Numbers::price($afip_ticket->total, true).' de factura N° '.$afip_ticket->code;
            }

            $des[] = 'Total pedido = '.Numbers::price($total, true);
        } else {
            $total += $total_articulos;
        }



        /*
            Sumando IVA que va a venir siempre de los provider_order_afip_tickets
            Estos van a ser creados de forma manual o automatica en base a "modo_facturacion"
            De eso se encarga ModoFacturacionHelper
        */

        if (count($this->provider_order->provider_order_afip_tickets) >= 1) {
            $des[] = 'CALCULANDO IVA EN BASE A FACTURAS';
        }
        foreach ($this->provider_order->provider_order_afip_tickets as $afip_ticket) {

            $total_iva  += $afip_ticket->total_iva;
            $des[] = Numbers::price($afip_ticket->total_iva, true).' de IVA de factura N° '.$afip_ticket->code;
        }
        if ($total_iva > 0) {
            $des[] =  'Total IVA = '.Numbers::price($total_iva, true);
        }


        $this->provider_order->total_iva            = $total_iva;
        $this->provider_order->sub_total            = $total_articulos;


        // Descuentos del pedido

        $total_solo_con_descuentos_individuales = $total_articulos - $descuentos_individuales;

        if (count($this->provider_order->provider_order_discounts) >= 1) {
            $des[] = 'CALCULANDO DESCUENTOS DE COMPRA';
            $des[] = 'Total articulos = '.Numbers::price($total_articulos, true);

            if ($descuentos_individuales > 0) {
                $des[] = 'Descuentos individuales = '.Numbers::price($descuentos_individuales, true);
                $des[] = 'Total articulos con desc aplicado = '.Numbers::price($total_solo_con_descuentos_individuales, true);
            }
        }

        foreach ($this->provider_order->provider_order_discounts as $discount) {

            if (
                !is_null($discount->percentage)
                && $discount->percentage != ''
            ) {

                $monto_descuento = $total_solo_con_descuentos_individuales * (float)$discount->percentage / 100;
                $des[] = 'Menos el '.$discount->percentage.'% de '.Numbers::price($total_solo_con_descuentos_individuales, true).' = '.Numbers::price($monto_descuento, true);
            } else if (
                !is_null($discount->monto)
                && $discount->monto != ''
            ) {

                $monto_descuento = $discount->monto;
                $des[] = 'Menos '.Numbers::price($discount->monto, true).' = '.Numbers::price($monto_descuento, true);
                Log::info('menos $'.$discount->monto);
            }


            $descuentos_compra += $monto_descuento;
            $total_solo_con_descuentos_individuales -= $monto_descuento;

            $des[] = 'Descuentos Compra = '.Numbers::price($descuentos_compra, true);


            // Log::info('monto_descuento = '.$monto_descuento);
            // Log::info('total_descuento = '.$total_descuento);
        }

        // if ($total_descuento > 0) {
        //     $des[] = 'Total articulos con descuentos aplicados = '.Numbers::price($total_)
        // }

        $total_descuento = $descuentos_individuales + $descuentos_compra;
        
        $this->provider_order->descuentos_individuales    = $descuentos_individuales;
        $this->provider_order->descuentos_compra          = $descuentos_compra;
        $this->provider_order->total_descuento            = $total_descuento;

        if (!$this->provider_order->total_from_provider_order_afip_tickets) {

            $total_sin_descuento = $total;
            $total -= $total_descuento;

            $des[] = 'APLICANDO DESCUENTOS DE COMPRA';
            $des[] = 'Total articulos sin descuentos = '.Numbers::price($total_sin_descuento, true);
            $des[] = 'Descuento individuales = '.Numbers::price($descuentos_individuales, true);
            $des[] = 'Descuentos de compra = '.Numbers::price($descuentos_compra, true);
            $des[] = 'Total Descuentos = '.Numbers::price($total_descuento, true);
            $des[] = 'Total con descuentos aplicado = '.Numbers::price($total, true);
        }


        if (count($this->provider_order->provider_order_extra_costs) >= 1) {
            $des[] = 'CALCULANDO COSTOS EXTRAS';
        }
        foreach ($this->provider_order->provider_order_extra_costs as $extra_cost) {
            $total_costos_extra += (float)$extra_cost->value;
            $des[] = 'Sumando '.Numbers::price($extra_cost->value, true).' de '.$extra_cost->description;
            $des[] = 'Costos extras en '.Numbers::price($total_costos_extra, true);
        }

        if ($total_costos_extra > 0) {
            $total_sin_costo_extra = $total;
            $total += $total_costos_extra;

            $des[] = 'APLICANDO COSTOS EXTRAS';
            $des[] = 'Total en '.Numbers::price($total_sin_costo_extra, true).' mas '.Numbers::price($total_costos_extra, true).' de costos extra = '.Numbers::price($total, true);
        }

        $this->provider_order->total_costos_extra            = $total_costos_extra;


        // Prompt 609 - Tarea 5: un Monotributista no suma el IVA por encima del total (ya está
        // "adentro" de lo que tipeó/pagó en cada línea, no se recupera aparte como crédito fiscal).
        // La columna de IVA de la compra sigue calculándose y mostrándose (prompt 611), solo se deja
        // de sumar acá.
        //
        // Prompt 614: mismo razonamiento aplica para un Responsable Inscripto cuando la orden trae
        // `precios_incluyen_iva` ON: en ese caso `$total_articulos` (y por lo tanto `$total`, que
        // arranca en `$total_articulos`) YA es el precio final tipeado con IVA incluido — el IVA
        // no es un crédito fiscal a sumar aparte sobre ese número, ya está "adentro". Sumar
        // `$total_iva` encima duplicaba el IVA (bug real detectado al automatizar el costeo con
        // PHPUnit: una compra de 1210 x 10 con `precios_incluyen_iva=1` daba total 14200 en vez de
        // los 12100 que efectivamente cuestan esos artículos, la misma plata que tipeada neta). Con
        // el flag OFF (comportamiento de siempre) no cambia nada: el costo tipeado es neto y el IVA
        // sí hay que sumarlo aparte para llegar al total final.
        if ($this->provider_order->total_with_iva && $this->get_condicion_iva_precios() != 'MT' && !$this->provider_order->precios_incluyen_iva) {

            $total_sin_iva = $total;

            $total += $total_iva;

            $des[] = 'APLICANDO IVA';
            $des[] = 'Total en '.Numbers::price($total_sin_iva, true).' mas '.Numbers::price($total_iva, true).' de IVA = '.Numbers::price($total, true);
        }

        $des[] = 'TOTAL FINAL';
        $des[] = 'Total final = '.Numbers::price($total, true);


        $this->provider_order->total                = $total;
        $this->provider_order->price_description    = json_encode($des);

        $this->provider_order->save();



    }

    /**
     * Determina la cantidad real a usar para stock y total de la compra: usa `received`
     * (Cant Recibida) SOLO si fue completado manualmente -incluye 0 como dato real, "llegaron
     * 0 unidades"-. Si vino vacío o null, usa `amount` (Cantidad pedida).
     *
     * IMPORTANTE: no filtrar por `> 0` (bug detectado 20/7/2026, ver refactor_empresa/compras.md
     * en el repo de contexto) - eso ignoraba un 0 cargado a mano y caía a `amount`, sumando stock
     * que en realidad no llegó.
     *
     * @param mixed $amount   Cantidad pedida (pivot->amount), siempre debería venir cargada.
     * @param mixed $received Cantidad recibida (pivot->received): null, '' o un número (incluido 0).
     * @return mixed la cantidad real a usar para stock/total.
     */
    function interpretar_cantidad_real($amount, $received) {

        if (is_null($received) || $received === '') {
            return $amount;
        }

        return $received;
    }

    function get_total_article($article) {

        $cost = (float)($article->pivot->cost);
        $sub_total_article = 0;
        $total_article = 0;
        $total_descuento = 0;
        $article_iva = [
            'iva_id'        => 0,
            'importe_iva'   => 0,
        ];
        
        if (
            $article->pivot->cost_in_dollars
            && $this->provider_order->moneda_id == 1
        ) {

            $valor_dolar = $this->user->dollar;

            if (
                !is_null($this->provider_order->provider) 
                && !is_null($this->provider_order->provider->dolar) 
                && (float)$this->provider_order->provider->dolar > 0) {

                $valor_dolar = $this->provider_order->provider->dolar;

            }

            $cost *= $valor_dolar;
        }

        // Prompt 609 - Tarea 6: cantidad recibida si está completada (incluido 0), sino pedida.
        // get_cantidad_efectiva() delega en interpretar_cantidad_real() (misma semantica que develop).
        $cantidad_efectiva = $this->get_cantidad_efectiva($article);

        $total_article = $cost * $cantidad_efectiva;

        if (
            (
                $total_article == 0
                || is_null($total_article)
            )
            && $article->pivot->price
        ) {
            $total_article = (float)$article->pivot->price * $cantidad_efectiva;
        }

        if (!is_null($article->presentacion)) {
            $total_article *= $article->presentacion;
        }


        $sub_total_article = $total_article;

        if (!is_null($article->pivot->discount)) {

            $descuento = $total_article * (float)$article->pivot->discount / 100;
            
            $total_descuento += $descuento;

            $total_article -= $descuento;
        }


        if (
            !$this->user->iva_included
            && !is_null($article->pivot->iva_id)
            && $article->pivot->iva_id != 0) {

            $iva = $this->get_iva($article->pivot->iva_id);

            if (!is_null($iva)) {

                // Prompt 609: alícuota numérica segura (is_numeric antes de castear).
                $importe_iva = $total_article * $this->get_alicuota_numerica($iva) / 100;

                $article_iva['iva_id']      = $iva->id;
                $article_iva['neto']        = $total_article;
                $article_iva['importe_iva'] = $importe_iva;

            } else {
                Log::info('No se encontro el iva_id: '.$article->pivot->iva_id);
            }

        }

        return [
            'total_article'     => $total_article,
            'sub_total_article' => $sub_total_article,
            'article_iva'       => $article_iva,
            'total_descuento'   => $total_descuento,
        ];
    }

    function get_iva($iva_id) {

        $iva = null;

        foreach ($this->ivas as $_iva) {

            if ($_iva->id == $iva_id) {

                $iva = $_iva;
            }
        }

        return $iva;
    }

    /**
     * Prompt 609 — Tarea 1: devuelve la alícuota NUMÉRICA de un IVA, tratando explícitamente como 0
     * los valores no numéricos que puede tener `ivas.percentage` ('Exento', 'No Gravado', guardados
     * como texto). No confiar en el casteo implícito de PHP: `(float)'Exento'` da 0 "por casualidad",
     * pero no queremos depender de eso — se valida con is_numeric() antes de castear.
     *
     * @param  \App\Models\Iva|null $iva
     * @return float Alícuota numérica (0 si el IVA es null, no tiene percentage, o percentage no es
     *               numérico).
     */
    private function get_alicuota_numerica($iva) {

        if (is_null($iva) || !isset($iva->percentage)) {
            return 0;
        }

        if (!is_numeric($iva->percentage)) {
            // 'Exento' y 'No Gravado' se guardan como texto en la columna percentage.
            return 0;
        }

        return (float)$iva->percentage;
    }

    /**
     * Prompt 609 — Tarea 2: resuelve la condición de IVA para costeo (`'RRII'` o `'MT'`) de la
     * cuenta dueña de esta compra, leyendo `condicion_iva_precios` desde el usuario.
     *
     * Grupo 231, prompt 01: `condicion_iva_precios` se movió de `UserConfiguration` a `User`.
     * Si no hay usuario, se asume `'RRII'` — comportamiento actual del sistema (costos netos, IVA
     * sumado al final), opción conservadora. Este método sigue usando el literal `'MT'` (no la
     * constante `User::CONDICION_MT`), consistente con el resto del archivo.
     *
     * @return string 'RRII' o 'MT'.
     *
     * Nota (Prompt 610): pasa de `private` a `public` porque `ModoFacturacionHelper::calcular_iva()`
     * necesita la misma condición para decidir qué muestra la factura automática (neto+desglose de
     * IVA para RRII, solo total para MT) — se reusa este único punto de verdad en vez de duplicar
     * la lectura de `condicion_iva_precios` en otra clase.
     */
    function get_condicion_iva_precios() {

        if (is_null($this->user)) {
            return 'RRII';
        }

        if ($this->user->condicion_iva_precios == 'MT') {
            return 'MT';
        }

        return 'RRII';
    }

    /**
     * Prompt 609 — Tarea 6: cantidad efectiva a usar en los cálculos de costeo/totales de un
     * artículo de la compra.
     *
     * Usa la cantidad RECIBIDA siempre que esté completada (incluido el caso `0`: "pedí 10, recibí
     * 0"), y cae a la cantidad PEDIDA (`pivot->amount`) solo cuando la recibida esté vacía/null (el
     * usuario no la completó porque asumió que llegó todo). Se chequea con isset()/is_null(), NO con
     * un chequeo de falsy, porque `0` es falsy en PHP y confundirlo con "no cargada" es justo el bug
     * a evitar acá.
     *
     * @param  \App\Models\Article $article Artículo de la compra, con su pivot ya cargado.
     * @return float
     */
    private function get_cantidad_efectiva($article) {

        // Merge develop -> refractor: se delega en interpretar_cantidad_real() para que exista una
        // sola fuente de verdad. Ademas de is_null, esa funcion trata '' como "no recibido"
        // (caso que la version anterior de este metodo casteaba a 0).
        $received = isset($article->pivot->received) ? $article->pivot->received : null;

        return (float)$this->interpretar_cantidad_real($article->pivot->amount, $received);
    }

    function set_current_acount() {

        if ($this->provider_order->generate_current_acount) {
            
            $current_acount = CurrentAcount::where('provider_order_id', $this->provider_order->id)
                                            ->first();

            if (is_null($current_acount)) {

                $current_acount = $this->crear_current_acount();
            } else {

                $cambio_moneda = $this->check_cambio_moneda();

                if ($cambio_moneda) {

                    $current_acount = $this->crear_current_acount();
                    
                } else {

                    $current_acount = $this->actualizar_current_acount($current_acount);
                }

            }

            CurrentAcountHelper::check_saldos_y_pagos($this->credit_account->id);
        }

    }

    function actualizar_current_acount($current_acount) {

        
        $current_acount->debe = $this->provider_order->total;

        $saldo = CurrentAcountHelper::getSaldo($this->credit_account->id, $current_acount) + $this->provider_order->total;

        $current_acount->saldo = $saldo;

        $current_acount->save();

        return $current_acount;
    }


    /*
        Si en este punto, filtrando ademas por credit_account_id, 
        no encuentra current_acount, es porque el current_acount que se encontro
        antes pertenece a otra credit_account.

        Entonces busco current_acount sin filtrar por credit_account y la elimino 
    */
    function check_cambio_moneda() {
            
        $current_acount = CurrentAcount::where('provider_order_id', $this->provider_order->id)
                                        ->where('credit_account_id', $this->credit_account->id)
                                        ->first();

        if (!$current_acount) {

            $current_acount = CurrentAcount::where('provider_order_id', $this->provider_order->id)
                                            ->first();

            $credit_account_id = $current_acount->credit_account_id;
            $current_acount->delete();

            CurrentAcountHelper::check_saldos_y_pagos($credit_account_id);

            return true;
        }

        return false;
    }

    function crear_current_acount() {

        $current_acount = CurrentAcount::create([
            'detalle'           => 'Pedido N°'.$this->provider_order->num,
            'debe'              => $this->provider_order->total,
            'status'            => 'sin_pagar',
            'user_id'           => UserHelper::userId(),
            'provider_id'       => $this->provider_order->provider_id,
            'provider_order_id' => $this->provider_order->id,
            'credit_account_id' => $this->credit_account->id,
        ]);

        $saldo = CurrentAcountHelper::getSaldo($this->credit_account->id, $current_acount) + $this->provider_order->total;

        $current_acount->saldo = $saldo;

        $current_acount->save();

        return $current_acount;
    }

    function set_ivas() {
        $this->ivas = Iva::all();
    }

    function attach_articles(bool $overwrite_articles = false) {

        $syncData = [];

        foreach ($this->new_articles as $new_article) {
            
            // Solo seteamos en la pivot las props cuyo valor venga distinto de `null`,
            // para evitar sobrescribir columnas con `null` cuando se actualizan artículos.
            $pivotData = [];
            $pivotKeys = [
                'cost',
                'amount',
                'received',
                'price',
                'discount',
                'notes',
                'iva_id',
                'cost_in_dollars',
                'amount_pedida',
                'update_provider',
            ];

            foreach ($pivotKeys as $pivotKey) {
                $value = GeneralHelper::getPivotValue($new_article, $pivotKey);

                // 'received' vacío (string '') significa "no se completó el campo" (o se borró en una
                // edición): siempre se graba como NULL explícito, nunca como 0 ni se deja el valor viejo
                // sin tocar. Ver bug 20/7/2026, refactor_empresa/compras.md.
                if ($pivotKey == 'received' && $value === '') {
                    $pivotData[$pivotKey] = null;
                    continue;
                }

                if (!is_null($value)) {
                    $pivotData[$pivotKey] = $value;
                }
            }

            $syncData[$new_article['id']] = $pivotData;
        }

        if ($overwrite_articles) {
            $this->provider_order->articles()->sync($syncData);
        } else {
            $this->provider_order->articles()->syncWithoutDetaching($syncData);
        }

        foreach ($this->new_articles as $new_article) {
            $this->update_article($new_article);
        }
    }

    function update_article($new_article) {

        $article = Article::find($new_article['id']);

        if (!is_null($article)) {

            $article = $this->update_iva($article, $new_article);
            
            if ($this->provider_order->update_prices) {

                Log::info('update_prices');

                $article = $this->update_cost($article, $new_article);

                $article = $this->update_price($article, $new_article);

                // Prompt 306 — Tarea 5: catalogar el costo bruto de este proveedor en
                // article_provider (dato para la futura recomendación de a qué proveedor conviene
                // comprar).
                $this->catalogar_costo_proveedor($article, $new_article);
            }

            // Si el articulo esta inacive, se actualiza la info de bar_code y demas
            $article = $this->update_article_data($article, $new_article);
            
            $article = $this->check_article_status($article, $new_article);

            if ($this->provider_order->update_stock) {

                $article = $this->update_stock($article, $new_article);
                
            }

            $this->update_article_provider($article, $new_article);

            $article->save();
        }
    }

    /**
     * Prompt 306 — Tarea 4: linkea el artículo al proveedor de la compra.
     *
     * Desde este prompt, cuando la orden tiene `update_prices` ON (el flujo principal,
     * "actualizar precios" tildado), el artículo pasa a pertenecer al proveedor de ESTA compra sin
     * importar el flag `update_provider` del pivot — con `update_prices` ON el artículo ya está
     * recibiendo el costo bruto (update_cost()) y los descuentos materializados (article_discounts
     * tagueados, ver materializar_descuentos_proveedor_en_articulos()) de este mismo proveedor, así
     * que su `provider_id` debe quedar consistente con esos datos.
     *
     * Con `update_prices` OFF se conserva el comportamiento previo (anterior a este prompt): el
     * proveedor solo se linkea si el usuario tildó explícitamente `update_provider` en el pivot de
     * este artículo puntual.
     */
    function update_article_provider($article, $new_article) {

        $tilda_update_provider = isset($new_article['pivot']['update_provider']) && (bool)$new_article['pivot']['update_provider'];

        if ($this->provider_order->update_prices || $tilda_update_provider) {

            $article->provider_id = $this->provider_order->provider_id;
            $article->timestamps = false;
            $article->save();
        }

    }

    /**
     * Prompt 306 — Tarea 5: catalogar el costo bruto del proveedor de la compra en
     * `article_provider` (relación Article::providers()), reusando el mismo patrón de upsert que
     * ProcessRow::update_provider_relation() (import de artículos) y SetProvider::set_provider()
     * (movimiento de stock).
     *
     * Alimenta la futura recomendación de "a qué proveedor conviene comprar": guarda el costo
     * NETO (mismo valor que setea update_cost() luego del back-out de IVA del prompt 514, sin
     * descuentos aplicados) más el `provider_code`, sin tocar la relación del artículo con otros
     * proveedores. Se normaliza acá también para que la comparación de costo entre proveedores sea
     * homogénea (todos netos), sin importar si cada proveedor factura con IVA incluido o no.
     *
     * Gate: solo se llama con `update_prices` ON (ver update_article()).
     */
    function catalogar_costo_proveedor($article, $new_article) {

        $provider_id = $this->provider_order->provider_id;

        if (is_null($provider_id)) {
            return;
        }

        // Costo bruto literal cargado en el pivot de esta compra (mismo valor que usa update_cost()
        // antes del back-out).
        $cost = (isset($new_article['pivot']['cost']) && $new_article['pivot']['cost'] != '')
            ? $new_article['pivot']['cost']
            : null;

        if (is_null($cost)) {
            return;
        }

        // Prompt 514: mismo back-out que update_cost() — si la orden trae precios con IVA incluido,
        // se guarda el costo NETO también en article_provider, para mantener la convención
        // "articles.cost / article_provider.cost siempre netos" y no inflar la comparación entre
        // proveedores.
        //
        // Prompt 609 - Tarea 3: branching por condición de IVA de la cuenta. Un Monotributista
        // trata el costo tipeado SIEMPRE como bruto (se ignora `precios_incluyen_iva` de la
        // compra); un Responsable Inscripto respeta el flag como hasta ahora.
        if ($this->get_condicion_iva_precios() == 'MT' || $this->provider_order->precios_incluyen_iva) {
            $cost = ArticlePricesHelper::back_out_iva($article, $cost, $this->user);
        }

        $pivot_data = [
            'cost' => $cost,
        ];

        // provider_code es opcional: solo se propaga si vino informado en el artículo de la compra.
        if (isset($new_article['provider_code']) && $new_article['provider_code'] != '') {
            $pivot_data['provider_code'] = $new_article['provider_code'];
        }

        $existe_relacion = $article->providers()
                                    ->where('provider_id', $provider_id)
                                    ->exists();

        if ($existe_relacion) {

            $article->providers()->updateExistingPivot($provider_id, $pivot_data);
        } else {

            $article->providers()->attach($provider_id, $pivot_data);
        }

        try {
            // El costo de una compra REAL es el dato más confiable del histórico: ya viene
            // neto (back-out de IVA arriba) y con proveedor y fecha ciertos.
            OfertasDeProveedorService::registrar_lote(
                [$article->id => [$provider_id => ['cost' => $cost, 'provider_code' => isset($pivot_data['provider_code']) ? $pivot_data['provider_code'] : null]]],
                (int) $this->provider_order->user_id,
                'compra',
                $this->provider_order->id
            );
        } catch (\Throwable $e) {
            // Igual que en la importación: el histórico de precios ofertados es un extra,
            // su falla nunca puede voltear la carga de la compra.
            Log::warning('NewProviderOrderHelper: no se pudo registrar el histórico de precios ofertados', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    function update_article_data($article, $new_article) {

        if ($article->status == 'inactive') {

            $article->bar_code          = $new_article['bar_code'];
            $article->provider_code     = $new_article['provider_code'];
            $article->save();

        }

        return $article;
    }

    function check_article_status($article, $new_article) {

        if (
            $article->status == 'inactive' 
            && $this->provider_order->update_stock
            && $new_article['pivot']['amount'] > 0
        ) {

            $article->status = 'active';
            $article->apply_provider_percentage_gain = 1;
            $article->created_at = Carbon::now();
        }

        return $article;
    }

    function update_stock($article, $new_article) {

        $article = Self::save_stock_movement($article, $new_article);

        return $article;
    }

    function save_stock_movement($article, $new_article) {

        // Cantidad real a mover en stock: `received` si fue completado manualmente (incluido 0),
        // si no `amount` (Cantidad pedida). Ver interpretar_cantidad_real().
        $received = isset($new_article['pivot']['received']) ? $new_article['pivot']['received'] : null;

        $amount = $this->interpretar_cantidad_real($new_article['pivot']['amount'], $received);

        if ($amount != '' 
            && !is_null($amount)
            && $amount > 0) {

            Log::info('*****************');
            Log::info('save_stock_movement para '.$article->name);
        
            if (is_null($article->stock)) {
                $article->stock = 0;
                $article->save();
            }

            $ct_stock_movement = new StockMovementController();

            Log::info('amount '.$amount);

            $se_esta_actualizando = false;

            if (isset($this->ultimos_articulos_recividos[$article->id])) {
                $se_esta_actualizando = true;
                Log::info('antes habia '.$this->ultimos_articulos_recividos[$article->id]);
                $amount -= $this->ultimos_articulos_recividos[$article->id];
                Log::info('amount quedo en: '.$amount);
            }

            if ($amount != 0) {

                $data = [];

                $data['model_id'] = $article->id;

                if (!is_null($this->provider_order->address_id)
                    && $this->provider_order->address_id != 0
                    && (
                        count($article->addresses) >= 1 
                        || $article->stock == 0
                        || is_null($article->stock)
                    )
                ) {

                    $data['to_address_id'] = $this->provider_order->address_id;
                } 

                if (!$new_article['pivot']['update_provider']) {

                    $data['not_save_provider'] = true;
                } 

                $data['amount'] = $amount;

                $data['provider_id'] = $this->provider_order->provider_id;

                $data['provider_order_id'] = $this->provider_order->id;

                if ($se_esta_actualizando) {

                    $data['concepto_stock_movement_name'] = 'Act Compra a proveedor';
                } else {

                    $data['concepto_stock_movement_name'] = 'Compra a proveedor';
                }
                

                $ct_stock_movement->crear($data);
            }

        }

        return $article;
    }

    function update_iva($article, $new_article) {

        if (
            isset($new_article['pivot']['iva_id'])
            && !is_null($new_article['pivot']['iva_id']) 
            && $new_article['pivot']['iva_id'] != 0 
            && $article->iva_id != $new_article['pivot']['iva_id']
        ) {

            $article->iva_id = $new_article['pivot']['iva_id'];

            Log::info('update iva con: '.$article->iva_id);
        } else {
            Log::info('No se actualizo iva');

        }

        return $article;
    }

    function update_price($article, $new_article) {

        $price = null;

        if (isset($new_article['pivot']['price']) 
            && !is_null($new_article['pivot']['price'])) {

            $price = (float)$new_article['pivot']['price'];
        }

        if (!is_null($price)
            && $article->price != $price) {

            $article->price = $price;
            $article->save();

            Log::info('update_price');

            ArticleHelper::setFinalPrice($article);
        }

        return $article;
    }

    function update_cost($article, $new_article) {

        $cost = null;

        if (isset($new_article['pivot']['cost'])
            && $new_article['pivot']['cost'] != '') {

            $cost = $new_article['pivot']['cost'];
        }

        // Prompt 514: `articles.cost` es SIEMPRE neto (sin IVA), por convención del sistema. Si la
        // orden de compra tiene `precios_incluyen_iva` ON, el costo que llega en el pivot viene con
        // IVA incluido (precio de lista del proveedor) y hay que sacárselo ANTES de guardarlo, con
        // la alícuota propia del artículo (ArticlePricesHelper::back_out_iva). Con el flag OFF (o
        // sin cargar) el comportamiento queda idéntico al de siempre: se guarda el valor tal cual.
        //
        // Prompt 609 - Tarea 3: branching por condición de IVA de la cuenta (`condicion_iva_precios`
        // de la configuración del usuario). Un Monotributista SIEMPRE trata el costo tipeado como
        // bruto (se ignora el flag `precios_incluyen_iva` de la compra, forzado a `true`); un
        // Responsable Inscripto respeta el flag como hasta ahora.
        if (!is_null($cost) && ($this->get_condicion_iva_precios() == 'MT' || $this->provider_order->precios_incluyen_iva)) {
            $cost = ArticlePricesHelper::back_out_iva($article, $cost, $this->user);
        }

        if (!is_null($cost)

            && $article->cost != $cost) {


            $article->cost = $cost;

            if (
                isset($new_article['pivot'])
                && isset($new_article['pivot']['cost_in_dollars'])
            ) {

                $article->cost_in_dollars = $new_article['pivot']['cost_in_dollars'];
            }


            $article->save();

            Log::info('update_cost con '. $article->cost);

            ArticleHelper::setFinalPrice($article);
        }

        return $article;
    }
	
    function set_ultimos_articulos_recividos() {

        $this->ultimos_articulos_recividos = [];

        if ($this->ya_se_actualizo_stock) {

            foreach ($this->provider_order->articles as $article) {

                // Este método corre en el constructor, ANTES de attach_articles(): acá el pivot
                // todavía refleja el valor VIEJO (de un guardado anterior). Se usa el mismo
                // criterio (received completado -incluido 0- manda, si no amount) para calcular
                // correctamente la base del delta de stock en ediciones.
                $received_previo = isset($article->pivot->received) ? $article->pivot->received : null;
                $this->ultimos_articulos_recividos[$article->id] = $this->interpretar_cantidad_real(
                    $article->pivot->amount,
                    $received_previo
                );
            }
        }

    }
}