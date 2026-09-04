<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\AuthController;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\UserProfileChangeDescriptionHelper;
use App\Jobs\ProcessSetFinalPrices;
use App\Models\DolarCotizacionRegistro;
use App\Models\OnlineConfiguration;
use App\Models\PdfColumnProfile;
use App\Models\User;
use App\Models\UserConfiguration;
use App\Models\Article;
use App\Models\PriceType;
use App\Notifications\GlobalNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{

    function store(Request $request) {
        if (!$this->docNumberRegistered($request->doc_number)) {
            $model = User::create([
                'name'          => $request->name,
                'doc_number'    => $request->doc_number,
                'phone'         => $request->phone,
                'company_name'  => $request->company_name,
                'email'         => $request->email,
                'expired_at'    => Carbon::now()->addMonth(),
                'password'      => bcrypt($request->password),
            ]);
            $model->extencions()->attach([6, 8, 9]);
            // UserConfiguration::create([
            //     'user_id'       => $model->id,
            //     'iva_included'  => 1,
            //     'current_acount_pagado_details' => 'Se saldo',
            //     'current_acount_pagandose_details'  => 'Pagandose',
            // ]);
            OnlineConfiguration::create([
                'user_id'       => $model->id,
            ]);
            Auth::login($model);
            return response()->json(['model' => $model], 201);
        } else {
            return response()->json(['repeated' => true], 200);
        }
    }

    function update(Request $request, $id) {
        /** @var User|null $model Usuario autenticado (puede ser owner o empleado). */
        $model = Auth()->user();
        
        /** @var User|null $owner_user Usuario dueño (owner) al que se le aplica el flag listas_de_precio. */
        // El flag listas_de_precio vive en el usuario dueño (owner). El auth_user puede ser empleado.
        $owner_user = $model->owner_id ? User::find($model->owner_id) : $model;
        $current_lists_de_precio = (int) ($owner_user ? (bool) $owner_user->listas_de_precio : false);

        /**
         * Array de notificaciones para devolver al frontend en respuestas exitosas.
         *
         * - Se usa para feedback inmediato cuando se encola un recálculo masivo de precios.
         * - Formato esperado por el interceptor del frontend: array de objetos con `message` y `type`.
         *
         * @var array<int, array{message:string,type:string}>
         */
        $notifications = [];

        $current_dolar                          = $model->dollar;
        $current_iva_included                   = $model->iva_included;
        $current_percentage_gain                = $model->percentage_gain;
        $current_cotizar_precios_en_dolares     = $model->cotizar_precios_en_dolares;
        $current_redondear_precios_en_decenas   = (int) $model->redondear_precios_en_decenas;
        $current_redondear_de_a_50              = (int) $model->redondear_de_a_50;
        $current_redondear_precios_en_centavos  = (int) $model->redondear_precios_en_centavos;
        // Tarea 4: faltaban en el snapshot, ver el comentario de check_actualizar_articulos().
        $current_redondear_centenas_en_vender   = (int) $model->redondear_centenas_en_vender;
        $current_redondear_miles_en_vender      = (int) $model->redondear_miles_en_vender;
        $current_aplicar_iva_al_costo           = (int) $model->aplicar_iva_al_costo;
        // Condicion de IVA para precios (RRII/MT) previa al guardado, para detectar si cambio y disparar el recalculo masivo.
        $current_condicion_iva_precios          = $model->condicion_iva_precios;
        // Flag previo que indica si el costeo depende de la condicion fiscal de la cuenta, para el mismo chequeo de cambios.
        $current_usar_condicion_fiscal_en_costeo = (int) $model->usar_condicion_fiscal_en_costeo;

        $current_default_version                = (int) $model->default_version;

        /**
         * Snapshot de atributos rastreados antes de aplicar el request (para armar el detalle del broadcast).
         *
         * @var array<string, mixed>
         */
        $before_auth_attrs = UserProfileChangeDescriptionHelper::snapshot_tracked_attributes($model);

        $model->name                            = $request->name;
        $model->doc_number                      = $request->doc_number;
        $model->dollar                          = $request->dollar;
        $model->company_name                    = $request->company_name;
        $model->phone                           = $request->phone;
        $model->email                           = $request->email;
        $model->download_articles               = $request->download_articles;
        $model->iva_included                    = $request->iva_included;
        $model->ask_amount_in_vender            = $request->ask_amount_in_vender;
        $model->sale_ticket_width               = $request->sale_ticket_width;
        $model->default_current_acount_payment_method_id               = $request->default_current_acount_payment_method_id;
        $model->discount_stock_from_recipe_after_advance_to_next_status               = $request->discount_stock_from_recipe_after_advance_to_next_status;
        $model->article_ticket_info_id          = $request->article_ticket_info_id;

        $model->dias_alertar_empleados_ventas_no_cobradas          = $request->dias_alertar_empleados_ventas_no_cobradas;

        $model->aplicar_descuentos_en_articulos_antes_del_margen_de_ganancia          = $request->aplicar_descuentos_en_articulos_antes_del_margen_de_ganancia;
        
       
        $model->dias_alertar_administradores_ventas_no_cobradas          = $request->dias_alertar_administradores_ventas_no_cobradas;

        $model->str_limint_en_vender          = $request->str_limint_en_vender;
        $model->sale_ticket_description          = $request->sale_ticket_description;
        $model->sale_ticket_name_font_size       = $request->sale_ticket_name_font_size;
        $model->sale_ticket_price_font_size      = $request->sale_ticket_price_font_size;
        $model->siempre_omitir_en_cuenta_corriente          = $request->siempre_omitir_en_cuenta_corriente;

        // Tarea 4: las cuatro tildes de redondeo se reemplazaron por el select "Opciones de
        // redondeo" (`modo_redondeo`), que es una fachada sobre las cinco columnas booleanas.
        // La traduccion vive en un metodo aparte para que la garantia de no-op quede en un solo
        // lugar y con su explicacion al lado.
        $this->aplicar_modo_redondeo($model, $request);

        $model->header_articulos_pdf            = $request->header_articulos_pdf;
        $model->default_version                 = $request->default_version;
        $model->estable_version                 = $request->estable_version;

        if ($request->default_version) {
            $api_url = str_replace('https://', 'https://api-', $request->default_version);
            // Normalizacion centralizada e idempotente en ApiUrlHelper (grupo 237, prompt 01):
            // evita que un valor ya duplicado ("/public/public") pase el guard y quede persistido.
            $model->api_url = ApiUrlHelper::canonical_public_url($api_url);
        }

        $model->text_omitir_cc                  = $request->text_omitir_cc;
        $model->percentage_gain                  = $request->percentage_gain;
        $model->scroll_en_tablas                  = $request->scroll_en_tablas;
        $model->cotizar_precios_en_dolares        = $request->cotizar_precios_en_dolares;
        $model->cc_ultimas_arriba        = $request->cc_ultimas_arriba;

        
        $model->show_stock_min_al_iniciar       = $request->show_stock_min_al_iniciar;
        $model->show_afip_errors_al_iniciar     = $request->show_afip_errors_al_iniciar;
        $model->usar_articles_cache             = $request->usar_articles_cache;

        // Minutos de duración del reporte de inventario. Se blinda para que nunca quede en 0 o
        // vacío (0 significaría regenerar el reporte en cada request, algo inviable en cuentas
        // con muchos artículos): si el valor recibido es nulo, no numérico o menor a 1, se guarda
        // el default de 30 minutos.
        $duracion_reporte_inventario = $request->duracion_reporte_inventario;
        if ($duracion_reporte_inventario === null || !is_numeric($duracion_reporte_inventario) || (int) $duracion_reporte_inventario < 1) {
            $duracion_reporte_inventario = 30;
        }
        $model->duracion_reporte_inventario     = $duracion_reporte_inventario;
        /**
         * Flag para habilitar/deshabilitar trabajo offline en frontend.
         */
        $model->sync_offline_articles           = $request->sync_offline_articles;
        $model->clave_eliminar_article          = $request->clave_eliminar_article;
        $model->img_auto_timeout                = $request->img_auto_timeout;

        $model->address_company                 = $request->address_company;
        $model->all_addresses_in_sale_pdf       = $request->all_addresses_in_sale_pdf;
        $model->mostrar_vendedor_en_venta_pdf   = $request->mostrar_vendedor_en_venta_pdf;
        $model->pdf_image_size                  = $request->pdf_image_size;
        $model->inputs_size_id                  = $request->inputs_size_id;
        /**
         * Tilde historica de costeo. Va con guard, igual que `condicion_iva_precios` mas abajo y
         * que `usar_condicion_fiscal_en_costeo`: un request que no traiga la clave la dejaba en
         * null, que en la columna boolean se persiste como 0, o sea que un update cualquiera
         * apagaba el flag sin que nadie lo pidiera. Y no queda latente: los tres campos entran en
         * la comparacion de check_actualizar_articulos(), asi que el apagado silencioso dispara el
         * recalculo de precios de TODOS los articulos de la cuenta en el acto.
         *
         * Ademas del `has()` se descarta el null explicito, porque `has()` devuelve true con la
         * clave presente en null y ese null no puede significar "apagar": apagar se manda como 0.
         * El campo sigue siendo editable — cuando viene con valor, se guarda.
         */
        if ($request->has('aplicar_iva_al_costo') && !is_null($request->aplicar_iva_al_costo)) {
            $model->aplicar_iva_al_costo = (int) $request->aplicar_iva_al_costo;
        }
        $model->aplicar_descuentos_de_venta_a_costos = $request->aplicar_descuentos_de_venta_a_costos;

        /**
         * Condicion de IVA para precios (Grupo 231): define si la cuenta es Monotributista o
         * Responsable Inscripto y de eso depende el calculo de costeo (via
         * ArticlePricesHelper::iva_va_al_costo). Se valida acotado porque un valor corrupto en
         * esta columna descalibra el costeo de toda la cuenta en silencio (misma validacion que
         * antes vivia en UserConfigurationController, movida aca en el prompt 01). Si llega algo
         * distinto de RRII/MT no se guarda nada y se corta el request con 422. Se chequea con
         * `has()` (a diferencia del resto de los campos de este metodo) para no romper el guardado
         * del formulario de propiedades mientras el prompt 06 (empresa-spa) todavia no manda este
         * campo nuevo: sin el guard, cualquier request viejo sin `condicion_iva_precios` llegaria
         * como null y tiraria 422 en TODO guardado de configuracion, no solo en el de esta feature.
         */
        if ($request->has('condicion_iva_precios')) {
            if ($request->condicion_iva_precios !== User::CONDICION_RRII && $request->condicion_iva_precios !== User::CONDICION_MT) {
                return response()->json(['error' => true, 'message' => 'condicion_iva_precios invalida'], 422);
            }
            $model->condicion_iva_precios = $request->condicion_iva_precios;
        }

        /**
         * Flag que activa la nueva dinamica de costeo dependiente de la condicion fiscal de la
         * cuenta. Mismo guard y mismo motivo que `aplicar_iva_al_costo` mas arriba: sin el, un
         * request que no mande la clave lo apaga en silencio y dispara el recalculo masivo de
         * precios por check_actualizar_articulos(). No sacar el guard pensando que es redundante.
         */
        if ($request->has('usar_condicion_fiscal_en_costeo') && !is_null($request->usar_condicion_fiscal_en_costeo)) {
            $model->usar_condicion_fiscal_en_costeo = (int) $request->usar_condicion_fiscal_en_costeo;
        }

        /**
         * Permite `provider_code` repetido en artículos.
         * Esta configuración se usa desde el front solo por el owner, por eso se persiste en el auth_user.
         */
        if ($request->has('usa_provider_codes_repetidos')) {
            $model->usa_provider_codes_repetidos = (bool) $request->usa_provider_codes_repetidos;
        }

        /**
         * Configuración de sugerencias de stock (v2): periodicidad de la
         * generación automática y valores por defecto de cada sugerencia.
         * Con guard has() (mismo criterio que usar_condicion_fiscal_en_costeo):
         * el form sin la extensión sugerencias_inteligentes no manda estas
         * claves, y un request viejo no puede pisar la configuración con null
         * (la periodicidad en null apagaría la generación en silencio).
         *
         * sugerencias_ultima_generacion_at NO se asigna acá a propósito: esa
         * marca la escribe únicamente el comando sugerencias:generar. El form
         * de configuración manda el modelo entero, así que aceptarla por
         * request hacía que cada guardado retrocediera la marca y adelantara
         * la próxima generación automática.
         */
        if ($request->has('sugerencias_periodicidad') && !is_null($request->sugerencias_periodicidad)) {
            $model->sugerencias_periodicidad = $request->sugerencias_periodicidad;
        }
        if ($request->has('sugerencias_modo') && !is_null($request->sugerencias_modo)) {
            $model->sugerencias_modo = $request->sugerencias_modo;
        }
        if ($request->has('sugerencias_origen') && !is_null($request->sugerencias_origen)) {
            $model->sugerencias_origen = $request->sugerencias_origen;
        }
        if ($request->has('sugerencias_limite_origen') && !is_null($request->sugerencias_limite_origen)) {
            $model->sugerencias_limite_origen = $request->sugerencias_limite_origen;
        }

        /**
         * Configuración de sugerencias de compra a proveedores: periodicidad
         * de la generación automática (comando compras:generar). Mismo
         * criterio que el bloque de sugerencias de stock de arriba: guard
         * has() para que un form sin la extensión sugerencias_compras no
         * mande esta clave, y descarte explícito del null para que ese "no
         * mandó nada" nunca apague la generación en silencio (la
         * periodicidad en null se comporta como 'nunca' en compras:generar).
         *
         * sugerencias_compras_ultima_generacion_at NO se asigna acá, mismo
         * motivo que la marca de stock: la escribe únicamente el comando
         * compras:generar. Si el PUT la aceptara, cada guardado del form
         * retrocedería la marca y adelantaría la próxima generación
         * automática.
         */
        if ($request->has('sugerencias_compras_periodicidad') && !is_null($request->sugerencias_compras_periodicidad)) {
            $model->sugerencias_compras_periodicidad = $request->sugerencias_compras_periodicidad;
        }

        /**
         * Motor de ofertas por cliente: periodicidad de la generación automática
         * (ofertas:generar). Mismo criterio que los dos bloques de arriba: guard
         * has() para que un form sin la extensión motor_de_ofertas no mande la
         * clave, y descarte del null para que "no mandó nada" no apague nada.
         *
         * ofertas_ultima_generacion_at NO se asigna acá, mismo motivo que sus dos
         * hermanas: la escribe SOLO el comando. Si el PUT la aceptara, cada guardado
         * del form retrocedería la marca y adelantaría la próxima corrida.
         */
        if ($request->has('ofertas_periodicidad') && !is_null($request->ofertas_periodicidad)) {
            $model->ofertas_periodicidad = $request->ofertas_periodicidad;
        }

        $model->save();

        if ($owner_user && $request->has('listas_de_precio')) {
            $owner_user->listas_de_precio = (int) $request->listas_de_precio;
            $owner_user->save();
        }

        /**
         * Preferencia del dueño sobre qué PDF/ticket abre el botón de imprimir de la factura ARCA
         * (tarjetita en Ventas y en "problemas al facturar"). Igual que `listas_de_precio`, vive
         * siempre en el usuario dueño aunque quien guarda el formulario sea un empleado.
         */
        if ($owner_user && $request->has('sale_factura_print_option')) {
            $owner_user->sale_factura_print_option = $this->resolve_sale_factura_print_option($request->sale_factura_print_option);
            $owner_user->save();
        }


        /**
         * Marca de cotización manual cuando el dólar se cambió a mano desde el formulario de
         * configuración (misión cotizacion-dolar).
         *
         * 🔴 Solo cuando el auth_user ES el owner. Este método escribe `dollar` en `Auth()->user()`,
         * que para un empleado admin es SU PROPIA FILA y no la del dueño (bug preexistente de
         * develop; esta misión NO lo arregla). Escribir el marcador en la fila del owner mientras
         * `dollar` se guardó en la del empleado dejaría las dos filas contando historias distintas.
         *
         * 🔴 El guard de null es aparte del `!=`: un PUT parcial sin `dollar` deja `$model->dollar`
         * en null, que es "distinto" del anterior y marcaría una cotización manual sin valor.
         *
         * 🔴 PROHIBIDO asignar acá `dolar_avisar_cambios` ni `dolar_variacion_minima` desde el
         * request: `ModelForm` postea el modelo ENTERO, así que un cliente que entra a cambiarse el
         * teléfono pisaría sus preferencias de aviso con lo que el front tenga en memoria. Es el
         * mismo agujero que ya documentan `ofertas_ultima_generacion_at` y sus hermanas.
         */
        if ($owner_user && $model->id === $owner_user->id
            && !is_null($model->dollar) && $model->dollar != $current_dolar) {
            DolarCotizacionRegistro::marcar_manual_desde_formulario($model, $current_dolar);
        }

        UserHelper::set_sessions($model);

        $this->check_update_articles_price_types_relations_on_lists_de_precio($owner_user, $current_lists_de_precio);

        $this->check_cambio_version($model, $current_default_version);

        // Si se encola recálculo masivo de precios, devolvemos feedback inmediato al usuario.
        if (
            $this->check_actualizar_articulos(
                $model,
                $current_dolar,
                $current_iva_included,
                $current_percentage_gain,
                $current_cotizar_precios_en_dolares,
                $current_redondear_precios_en_decenas,
                $current_redondear_de_a_50,
                $current_redondear_precios_en_centavos,
                $current_aplicar_iva_al_costo,
                $current_condicion_iva_precios,
                $current_usar_condicion_fiscal_en_costeo,
                $current_redondear_centenas_en_vender,
                $current_redondear_miles_en_vender
            )
        ) {
            $notifications[] = [
                'message' => 'Se inició la actualización de precios en segundo plano. Te avisaremos cuando termine.',
                'type'    => 'info',
            ];
        }

        $model = UserHelper::getFullModel();

        /**
         * Refresca el usuario autenticado desde BD y compara con el snapshot para notificar a otras sesiones.
         */
        $auth_after_save = Auth::user();
        if ($auth_after_save) {
            $auth_after_save->refresh();
            $after_auth_attrs = UserProfileChangeDescriptionHelper::snapshot_tracked_attributes($auth_after_save);
            /** @var int $company_owner_id Id del comercio (mismo que usa Echo en el SPA). */
            $company_owner_id = $auth_after_save->owner_id ? (int) $auth_after_save->owner_id : (int) $auth_after_save->id;
            /** @var int|null $listas_after_val Estado actual de listas de precio en el registro del owner. */
            $listas_after_val = (int) (bool) User::where('id', $company_owner_id)->value('listas_de_precio');
            /** @var array<int, string> $change_descriptions Líneas para el modal de otras sesiones. */
            $change_descriptions = UserProfileChangeDescriptionHelper::build_change_descriptions(
                $before_auth_attrs,
                $after_auth_attrs,
                $owner_user ? (int) $current_lists_de_precio : null,
                $listas_after_val
            );
            UserHelper::schedule_company_owner_context_updated_broadcast(
                $company_owner_id,
                (int) $auth_after_save->id,
                $change_descriptions
            );
        }

        return response()->json([
            'model' => $model,
            'notifications' => $notifications,
        ], 200);
    }

    /**
     * Normaliza y valida el valor de sale_factura_print_option antes de guardarlo en el owner.
     * Devuelve el valor normalizado a guardar, o null si el valor recibido no es válido
     * (en cuyo caso se guarda null en vez de dejar un valor corrupto/inexistente).
     *
     * Vocabulario aceptado (reutiliza las claves de `vender_print_shortcut_options.js` del SPA):
     * - 'factura_ticket_pdf': ticket común (equivalente explícito al default).
     * - 'ticket_2': Ticket 2.0.
     * - 'factura_a4:{id}': perfil PdfColumnProfile fiscal de tipo A4 con ese id (debe existir,
     *   pertenecer al modelo 'sale' y estar marcado como is_afip_ticket).
     * - Cualquier otro valor (o vacío): se descarta y se guarda null.
     *
     * @param mixed $value Valor recibido desde el request.
     * @return string|null
     */
    private function resolve_sale_factura_print_option($value) {
        // Valor vacío o no enviado: sin preferencia, comportamiento default (ticket común).
        if (empty($value)) {
            return null;
        }

        // Claves fijas del vocabulario: se aceptan tal cual.
        if ($value === 'factura_ticket_pdf' || $value === 'ticket_2') {
            return $value;
        }

        // Perfil de PDF A4 fiscal: 'factura_a4:{id}'. Se valida que el id exista y sea un perfil
        // fiscal (is_afip_ticket) del modelo 'sale', para no guardar una referencia inexistente.
        if (strpos($value, 'factura_a4:') === 0) {
            $profile_id = (int) str_replace('factura_a4:', '', $value);

            if ($profile_id <= 0) {
                return null;
            }

            $profile_valido = PdfColumnProfile::where('id', $profile_id)
                ->where('model_name', 'sale')
                ->where('is_afip_ticket', true)
                ->exists();

            if (!$profile_valido) {
                return null;
            }

            return $value;
        }

        // Cualquier otro valor no reconocido se ignora silenciosamente.
        return null;
    }

    function check_cambio_version($model, $current_default_version) {
        if ($current_default_version != $model->default_version) {
            /**
             * Obtiene el owner real de la empresa.
             * - Si el auth user es empleado, usa su owner.
             * - Si el auth user es owner, usa su propio id.
             */
            $owner_id = $model->owner_id ? $model->owner_id : $model->id;

            /**
             * Libera sesiones del owner y todos sus empleados para que
             * puedan iniciar sesión en la nueva versión.
             */
            User::where(function ($query) use ($owner_id) {
                    $query->where('id', $owner_id)
                        ->orWhere('owner_id', $owner_id);
                })
                ->update([
                    'session_id' => null,
                    'last_activity' => null,
                ]);

            Log::info('Se liberaron sesiones por cambio de version para owner_id: '.$owner_id);
        }
    }

    /**
     * Si el usuario dueño cambia el flag `listas_de_precio`, sincroniza las relaciones en `article_price_type`
     * y dispara recálculo para que `final_price` quede consistente.
     *
     * - 0 -> 1: crea (o completa) relaciones para todos los artículos actuales contra todos los `price_types`
     *   creados por el dueño, setea el porcentaje por defecto del `price_type` cuando exista.
     * - 1 -> 0: elimina relaciones de artículos contra los `price_types` del dueño.
     *
     * @param User|null $owner_user Usuario dueño (owner) al que se le aplica el flag.
     * @param int $current_lists_de_precio Valor previo antes de guardar.
     * @return void
     */
    function check_update_articles_price_types_relations_on_lists_de_precio($owner_user, $current_lists_de_precio) {
        if (!$owner_user) {
            return;
        }

        $new_lists_de_precio = (int) ($owner_user->listas_de_precio ? 1 : 0);
        $current_lists_de_precio = (int) ($current_lists_de_precio ? 1 : 0);

        if ($new_lists_de_precio === $current_lists_de_precio) {
            return;
        }

        $price_types = PriceType::where('user_id', $owner_user->id)
            ->orderBy('position', 'ASC')
            ->get(['id', 'percentage', 'setear_precio_final', 'incluir_en_lista_de_precios_de_excel', 'position']);

        $last_position = PriceType::where('user_id', $owner_user->id)
                                ->orderBy('position', 'DESC')
                                ->first()->position;

        Log::info('Last position: '.$last_position);

        $price_type_ids = $price_types->pluck('id')->values()->all();
        $articles_chunk_size = 200;

        // 0 -> 1
        if ($current_lists_de_precio === 0 && $new_lists_de_precio === 1) {

            Log::info('Agregando listas de precio');
            $pivot_table = 'article_price_type';
            $now = now();

            // Inserta relaciones por chunk para evitar queries y payloads gigantes.
            Article::where('user_id', $owner_user->id)
                ->select('id', 'percentage_gain', 'cost', 'final_price')
                ->chunk($articles_chunk_size, function ($articles_chunk) use ($price_types, $pivot_table, $now, $articles_chunk_size, $last_position) {

                    if ($price_types->isEmpty()) {
                        return;
                    }

                    $rows = [];

                    foreach ($articles_chunk as $article) {


                        foreach ($price_types as $price_type) {

                            $percentage = $price_type->percentage;
                            $final_price = null;

                            $setear_precio_final = $price_type->setear_precio_final;

                            if ((int)$price_type->position == (int)$last_position) {

                                if (!is_null($article->cost) && !is_null($article->percentage_gain)) {
                                    $percentage = $article->percentage_gain;
                                } else if (!is_null($article->final_price)) {
                                    $percentage = null;
                                    $final_price = (float)$article->final_price;
                                    $setear_precio_final = 1;
                                    // Log::info('usando precio manual para la lista '.$price_type->name.' de '.$final_price);
                                }
                                // Log::info('last_position de article: '.$article->name);
                            }
                            
                            $rows[] = [
                                'article_id' => (int) $article->id,
                                'price_type_id' => (int) $price_type->id,
                                // Por defecto, seteamos el porcentaje del price_type. Si el usuario no completó
                                // percentage en el price_type, quedará null y el cálculo lo normaliza en 0.
                                'percentage' => $percentage,
                                'final_price' => $final_price,
                                'previus_final_price' => null,
                                'incluir_en_excel_para_clientes' => (int) ($price_type->incluir_en_lista_de_precios_de_excel ? 1 : 0),
                                'setear_precio_final' => (int) $setear_precio_final,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }

                    if (count($rows)) {
                        DB::table($pivot_table)->insertOrIgnore($rows);
                    }
                });

            // Recalcula precios para que `final_price` refleje listas activadas.
            ProcessSetFinalPrices::dispatch($owner_user->id, null, null, false, 'configuracion_usuario');
            return;
        }

        // 1 -> 0
        if ($current_lists_de_precio === 1 && $new_lists_de_precio === 0) {
            Log::info('Quitando listas de precio');
            if (!empty($price_type_ids)) {
                DB::table('article_price_type')
                    ->join('articles', 'articles.id', '=', 'article_price_type.article_id')
                    ->where('articles.user_id', $owner_user->id)
                    ->whereIn('article_price_type.price_type_id', $price_type_ids)
                    ->delete();
            }

            // Recalcula precios para que `final_price` deje de depender de los price_types.
            ProcessSetFinalPrices::dispatch($owner_user->id, null, null, false, 'configuracion_usuario');
        }
    }

    /**
     * Guarda la preferencia de modo oscuro del usuario AUTENTICADO (dueño o empleado).
     *
     * A diferencia de set_img_auto_timeout, que resuelve con $this->userId() (siempre el dueño,
     * porque esa preferencia es de la cuenta), acá resolvemos con Auth::user() a propósito: el
     * modo oscuro es de CADA PERSONA. Si se usara $this->userId(), la preferencia de un empleado
     * quedaría grabada en el dueño y el síntoma sería desconcertante (el empleado prende el modo
     * oscuro y a quien le cambia la pantalla al recargar es al dueño).
     */
    function set_dark_mode($value) {
        $model = Auth::user();
        $model->dark_mode = (int) ((bool) $value);
        $model->save();

        UserHelper::set_sessions($model);

        return response()->json(['dark_mode' => (int) $model->dark_mode], 200);
    }

    /**
     * Guarda la impresora del Ticket 2.0 de la persona AUTENTICADA.
     *
     * Mismo criterio que set_dark_mode: se resuelve con Auth::user() y no con
     * $this->userId(), porque cada persona imprime en la suya y con el userId la
     * eleccion de un empleado quedaria grabada en la fila del dueño.
     *
     * Hasta esta version la columna `impresora` no tenia NINGUN camino de escritura:
     * se llenaba una sola vez al crear la cuenta con el literal 'comerciocity'
     * (UserSetupHelper), y de ahi salia la obligacion de renombrar la impresora en
     * Windows para que el nombre coincidiera.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    function set_impresora(Request $request) {
        $request->validate([
            'impresora' => 'nullable|string|max:255',
        ]);

        $model = Auth::user();
        $model->impresora = $request->impresora;
        $model->save();

        UserHelper::set_sessions($model);

        return response()->json(['impresora' => $model->impresora], 200);
    }

    /**
     * Guarda las preferencias de UI del chat con el asistente de IA de la
     * persona AUTENTICADA (dueño o empleado): la posición del botón flotante
     * y el ancho de la sidebar del panel.
     *
     * Mismo criterio que set_dark_mode, y a diferencia de set_img_auto_timeout:
     * se resuelve con Auth::user() a propósito porque estas preferencias son
     * de CADA PERSONA. Con $this->userId(), la posición que arrastra un
     * empleado quedaría grabada en la fila del dueño y el botón se le movería
     * de lugar al dueño al recargar.
     *
     * `chat_ia_fab_position` viaja como string "left,top" en px (D3 del plan:
     * string corto en vez de JSON; parse con explode y guard de dos enteros
     * 0..20000). `chat_ia_sidebar_width` en px, mismo rango 180..420 que la
     * SPA permite arrastrar. `chat_ia_panel_width` es el ancho del MODAL
     * ENTERO (19/8/2026), también en px y con el mismo rango 720..1600 que
     * clampa la SPA. Las tres claves son opcionales: se persiste solo lo que
     * llega con valor, y un valor inválido corta con 422 SIN guardar nada
     * (por eso se valida todo antes de escribir).
     *
     * 🔴 Los rangos de acá y los de la SPA tienen que decir el MISMO número.
     * Si divergen, el usuario arrastra hasta un ancho que la SPA acepta y este
     * endpoint rechaza, y como savePreferences() se traga el 422 en un
     * console.log, el ancho se pierde en silencio recién al recargar.
     */
    function set_chat_ia_preferencias(Request $request) {
        $model = Auth::user();

        $tiene_posicion     = $request->has('chat_ia_fab_position') && !is_null($request->chat_ia_fab_position);
        $tiene_ancho        = $request->has('chat_ia_sidebar_width') && !is_null($request->chat_ia_sidebar_width);
        $tiene_ancho_panel  = $request->has('chat_ia_panel_width') && !is_null($request->chat_ia_panel_width);

        if ($tiene_posicion && !$this->es_posicion_de_fab_valida($request->chat_ia_fab_position)) {
            return response()->json([
                'error'   => true,
                'message' => 'chat_ia_fab_position invalida: se espera "left,top" con dos enteros entre 0 y 20000.',
            ], 422);
        }

        if ($tiene_ancho && !$this->es_ancho_valido($request->chat_ia_sidebar_width, 180, 420)) {
            return response()->json([
                'error'   => true,
                'message' => 'chat_ia_sidebar_width invalido: se espera un entero entre 180 y 420.',
            ], 422);
        }

        if ($tiene_ancho_panel && !$this->es_ancho_valido($request->chat_ia_panel_width, 720, 1600)) {
            return response()->json([
                'error'   => true,
                'message' => 'chat_ia_panel_width invalido: se espera un entero entre 720 y 1600.',
            ], 422);
        }

        if ($tiene_posicion) {
            $model->chat_ia_fab_position = (string) $request->chat_ia_fab_position;
        }

        if ($tiene_ancho) {
            $model->chat_ia_sidebar_width = (int) $request->chat_ia_sidebar_width;
        }

        if ($tiene_ancho_panel) {
            $model->chat_ia_panel_width = (int) $request->chat_ia_panel_width;
        }

        $model->save();

        UserHelper::set_sessions($model);

        return response()->json([
            'chat_ia_fab_position'  => $model->chat_ia_fab_position,
            'chat_ia_sidebar_width' => is_null($model->chat_ia_sidebar_width) ? null : (int) $model->chat_ia_sidebar_width,
            'chat_ia_panel_width'   => is_null($model->chat_ia_panel_width) ? null : (int) $model->chat_ia_panel_width,
        ], 200);
    }

    /**
     * true si el valor es un entero (o su string) adentro del rango cerrado [$min, $max].
     *
     * La comparacion `(int) $valor != $valor` es la que voltea los decimales: sin ella,
     * "300.7" pasaria como 300. Es floja (!=) a proposito, porque el valor llega del
     * request como string y "300" tiene que valer igual que 300.
     *
     * @param mixed $valor Valor recibido desde el request.
     * @param int $min
     * @param int $max
     * @return bool
     */
    private function es_ancho_valido($valor, $min, $max) {
        if (!is_numeric($valor) || (int) $valor != $valor) {
            return false;
        }

        return (int) $valor >= $min && (int) $valor <= $max;
    }

    /**
     * true si el valor es un "left,top" válido: exactamente dos enteros no
     * negativos de hasta 20000, separados por una coma. ctype_digit y no
     * is_numeric: "12.5" o "1e3" no son coordenadas de pantalla.
     *
     * @param mixed $valor Valor recibido desde el request.
     * @return bool
     */
    private function es_posicion_de_fab_valida($valor) {
        if (!is_string($valor)) {
            return false;
        }

        $partes = explode(',', $valor);

        if (count($partes) !== 2) {
            return false;
        }

        foreach ($partes as $parte) {
            if (!ctype_digit($parte) || (int) $parte > 20000) {
                return false;
            }
        }

        return true;
    }

    function set_img_auto_timeout($value) {
        $model = User::find($this->userId());
        $model->img_auto_timeout = $value;
        $model->save();

        return response()->json(['model' => $model], 200);
    }

    function actualizar_empleados($user) {
        
        $functions_to_execute = [
            [
                'btn_text'      => 'Recargar pagina',
                'function_name' => 'recargar_pagina',
                'btn_variant'   => 'primary',
            ],
        ];

        $info_to_show = [];

        $user->notify(new GlobalNotification([
            'message_text'              => 'Informacion de la cuenta actualizada',
            'color_variant'             => 'success',
            'functions_to_execute'      => $functions_to_execute,
            'info_to_show'              => $info_to_show,
            'owner_id'                  => $user->id,
            'is_only_for_auth_user'     => false,
        ]));
    }

    /**
     * Detecta cambios de configuración que requieren recálculo masivo de precios y encola el proceso.
     *
     * Nota: el recálculo se ejecuta en segundo plano vía queue, por eso este método devuelve un boolean
     * para que el controller pueda retornar feedback inmediato al frontend (campo `notifications`).
     *
     * @param User $model Usuario autenticado.
     * @param mixed $current_dolar Valor previo de dólar.
     * @param mixed $current_iva_included Valor previo de iva_included.
     * @param mixed $current_percentage_gain Valor previo de percentage_gain.
     * @param mixed $current_cotizar_precios_en_dolares Valor previo de cotizar_precios_en_dolares.
     * @param int $current_redondear_precios_en_decenas Valor previo del flag redondear_precios_en_decenas.
     * @param int $current_redondear_de_a_50 Valor previo del flag redondear_de_a_50.
     * @param int $current_redondear_precios_en_centavos Valor previo del flag redondear_precios_en_centavos.
     * @param int $current_aplicar_iva_al_costo Valor previo del flag aplicar_iva_al_costo.
     * @param mixed $current_condicion_iva_precios Valor previo de condicion_iva_precios (RRII/MT).
     * @param int $current_usar_condicion_fiscal_en_costeo Valor previo del flag usar_condicion_fiscal_en_costeo.
     * @param int $current_redondear_centenas_en_vender Valor previo del flag redondear_centenas_en_vender.
     * @param int $current_redondear_miles_en_vender Valor previo del flag redondear_miles_en_vender.
     * @return bool true si se encoló un recálculo; false si no hubo cambios relevantes.
     *
     * 🔴 Tarea 4 — `redondear_centenas_en_vender` y `redondear_miles_en_vender` se agregaron acá el
     * 10/8/2026. Faltaban, y era un bug real: `ArticleHelper::redondear()` lee las CINCO columnas,
     * pero esta comparación miraba solo tres, así que prender o apagar "de a centenas" no
     * despachaba `ProcessSetFinalPrices` y los precios quedaban con el redondeo viejo hasta que
     * otra cosa los recalculara.
     *
     * Con el select "Opciones de redondeo" deja de ser un bug silencioso y pasa a ser una falla
     * evidente: el cliente elige "de a 100", guarda, y no pasa nada. La regla que ordena esto es
     * simple y hay que sostenerla si mañana aparece una sexta columna: **cualquier cambio de modo
     * de redondeo tiene que disparar el recálculo**, así que toda columna que lea
     * `ArticleHelper::redondear()` va también en esta comparación.
     */
    function check_actualizar_articulos(
        $model,
        $current_dolar,
        $current_iva_included,
        $current_percentage_gain,
        $current_cotizar_precios_en_dolares,
        $current_redondear_precios_en_decenas,
        $current_redondear_de_a_50,
        $current_redondear_precios_en_centavos,
        $current_aplicar_iva_al_costo,
        $current_condicion_iva_precios,
        $current_usar_condicion_fiscal_en_costeo,
        $current_redondear_centenas_en_vender,
        $current_redondear_miles_en_vender
    ) {

        if (
            $model->dollar != $current_dolar
            || $model->iva_included != $current_iva_included
            || $model->percentage_gain != $current_percentage_gain
            || $model->cotizar_precios_en_dolares != $current_cotizar_precios_en_dolares
            || (int) $model->redondear_precios_en_decenas !== (int) $current_redondear_precios_en_decenas
            || (int) $model->redondear_de_a_50 !== (int) $current_redondear_de_a_50
            || (int) $model->redondear_precios_en_centavos !== (int) $current_redondear_precios_en_centavos
            || (int) $model->aplicar_iva_al_costo !== (int) $current_aplicar_iva_al_costo
            || $model->condicion_iva_precios != $current_condicion_iva_precios
            || (int) $model->usar_condicion_fiscal_en_costeo !== (int) $current_usar_condicion_fiscal_en_costeo
            || (int) $model->redondear_centenas_en_vender !== (int) $current_redondear_centenas_en_vender
            || (int) $model->redondear_miles_en_vender !== (int) $current_redondear_miles_en_vender

        ) {
            Log::info($model->dollar.' | '.$current_dolar);
            Log::info($model->iva_included.' | '.$current_iva_included);
            Log::info($model->percentage_gain.' | '.$current_percentage_gain);
            Log::info($model->cotizar_precios_en_dolares.' | '.$current_cotizar_precios_en_dolares);
            Log::info((int) $model->redondear_precios_en_decenas.' | '.(int) $current_redondear_precios_en_decenas);
            Log::info((int) $model->redondear_de_a_50.' | '.(int) $current_redondear_de_a_50);
            Log::info((int) $model->redondear_precios_en_centavos.' | '.(int) $current_redondear_precios_en_centavos);
            Log::info((int) $model->aplicar_iva_al_costo.' | '.(int) $current_aplicar_iva_al_costo);
            Log::info($model->condicion_iva_precios.' | '.$current_condicion_iva_precios);
            Log::info((int) $model->usar_condicion_fiscal_en_costeo.' | '.(int) $current_usar_condicion_fiscal_en_costeo);
            Log::info('Hubo cambios en propiedades de user');

            /** @var bool $from_dolar Indica si el recálculo se disparó por cambio de dólar (optimiza query en job). */
            $from_dolar = false;

            if ($model->dollar != $current_dolar) {
                $from_dolar = true;
            }

            // from_dolar ya distinguia este caso; ahora ademas lo cuenta el modal.
            ProcessSetFinalPrices::dispatch(UserHelper::userId(), null, null, $from_dolar, $from_dolar ? 'dolar' : 'configuracion_usuario');
            return true;
        }
        return false;
    }

    /**
     * Tarea 4 — traduce el valor del select "Opciones de redondeo" a las cinco columnas booleanas.
     *
     * Hay TRES resultados posibles, y la diferencia entre el segundo y el tercero es la que se paso
     * por alto la primera vez que se escribio este metodo:
     *
     * 1. Un modo de la tabla ('miles', 'centenas', 'decenas', 'cincuenta', 'centavos') deja
     *    EXACTAMENTE una columna en 1 y las otras cuatro en 0.
     * 2. 'sin_redondeo' apaga las cinco. Es una eleccion explicita del usuario, no un caso de borde.
     * 3. 'personalizado', ausente, vacio o desconocido: NO se toca ninguna de las cinco columnas.
     *
     * 🔴 El caso 2 faltaba, y era una regresion funcional dura: `sin_redondeo` no esta en
     * `COLUMNAS_MODO_REDONDEO` —no le corresponde ninguna columna— asi que caia en el early-return
     * del caso 3 junto con la basura. El select lo ofrece como PRIMERA opcion y habilitada, con lo
     * cual el cliente elegia "Sin redondeo", recibia 200 OK, la pantalla recargaba `auth/me` y el
     * select volvia solo a la opcion anterior. Sin ningun mensaje de error: el peor tipo de falla.
     * Y era regresion, no un limite viejo: antes de la fachada, destildar una tilde la apagaba.
     *
     * 🔴 El caso 3 NO es defensa de mas: es la garantia que hace seguro todo el cambio, y este es el
     * lugar exacto donde alguien lo iria a "simplificar".
     *
     * - 'personalizado': el usuario tiene dos o mas columnas prendidas. `ModelForm` postea el
     *   modelo ENTERO, asi que un cliente que entra a cambiarse el telefono manda
     *   `modo_redondeo: 'personalizado'` sin haber tocado el select — es el valor que el accessor
     *   `getModoRedondeoAttribute()` le mando en el GET. Si esta rama colapsara el estado a un
     *   modo unico, le habriamos cambiado los precios de todo el catalogo por editar un telefono.
     * - ausente, vacio o desconocido: mismo tratamiento por el mismo motivo. Nunca se adivina un
     *   modo a partir de un valor que no esta en la tabla.
     *
     * La linea que separa el caso 2 del 3 es "apagar es una eleccion, adivinar no": 'sin_redondeo'
     * es un valor que el propio accessor emite y que el select ofrece, o sea que llega porque
     * alguien lo eligio. 'personalizado' tambien lo emite el accessor, pero describe un estado que
     * el select no puede reconstruir — por eso uno se honra y el otro se ignora.
     *
     * ⚠️ Limitacion conocida y FUERA DEL ALCANCE de la tarea 4, anotada donde se ve: esto escribe
     * sobre `$model`, que es la fila del usuario AUTENTICADO, mientras que `ArticleHelper::redondear()`
     * lee el redondeo del OWNER. Un empleado que elija un modo se lo guarda a si mismo y no cambia
     * ningun precio, pero `check_actualizar_articulos()` detecta el cambio igual y despacha un
     * recalculo del catalogo entero del owner que no va a mover un solo precio. `listas_de_precio` y
     * `sale_factura_print_option` resuelven esto escribiendo en `$owner_user`; el redondeo no. Ver
     * el hallazgo `20260811-el-select-de-redondeo-le-escribe-al-empleado-y-el-precio-lo-calcula-el-owner`.
     *
     * @param User $model Usuario al que se le aplica la configuracion.
     * @param Request $request Request del PUT, que puede traer o no `modo_redondeo`.
     * @return void
     */
    private function aplicar_modo_redondeo($model, Request $request) {

        $modo = $request->modo_redondeo;

        if (is_null($modo) || $modo === '') {
            return;
        }

        // Caso 2. Va ANTES del in_array de abajo porque `sin_redondeo` no es el valor de ninguna
        // columna: si se dejara caer ahi, seria indistinguible de un valor desconocido — que es
        // exactamente el bug que este bloque arregla.
        if ($modo === User::MODO_SIN_REDONDEO) {
            foreach (array_keys(User::COLUMNAS_MODO_REDONDEO) as $columna) {
                $model->$columna = 0;
            }
            return;
        }

        // Caso 3. in_array estricto sobre los modos que SI tienen columna: 'personalizado' no esta
        // en la tabla, asi que cae aca junto con cualquier valor desconocido y sale sin tocar nada.
        if (!in_array($modo, array_values(User::COLUMNAS_MODO_REDONDEO), true)) {
            return;
        }

        // Caso 1.
        foreach (User::COLUMNAS_MODO_REDONDEO as $columna => $modo_de_esa_columna) {
            $model->$columna = ($modo_de_esa_columna === $modo) ? 1 : 0;
        }
    }

    function updatePassword(Request $request) {

        if (Hash::check($request->current_password, Auth()->user()->password)) {
            $user = User::find(Auth()->user()->id);
            $user->update([
                'password' => bcrypt($request->new_password),
            ]);
            return response()->json(['updated' => true], 200);
        } else {
            return response()->json(['updated' => false], 200);
        }
    }

    function docNumberRegistered($doc_number) {
        $repeated_user = User::where('doc_number', $doc_number)->first();
        return !is_null($repeated_user);
    }

    function set_eliminar_articulos_offline($id, $value) {
        $user = User::find($id);
        $user->eliminar_articulos_offline = (int)$value;
        $user->save();
        return response(null, 200);
    }
}
