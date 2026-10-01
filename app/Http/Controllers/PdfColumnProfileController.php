<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\CatalogHeaderLayoutHelper;
use App\Http\Controllers\Helpers\PdfColumnProfileHelper;
use App\Http\Controllers\Helpers\PdfLayout\CatalogoDeCamposPdf;
use App\Http\Controllers\Helpers\PdfLayout\DisenoDePaginaPdf;
use App\Http\Controllers\Helpers\PdfLayout\DisenoDerivadoPdf;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\PdfColumnProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PdfColumnProfileController extends Controller
{
    public function index(Request $request)
    {
        $models = PdfColumnProfile::where('user_id', $this->userId())->with(['sheet_type', 'pdf_column_options']);
        if ($request->query('model_name')) {
            $models->where('model_name', $request->query('model_name'));
        }

        $models = $models->orderBy('model_name')
            ->orderBy('name')
            ->get();

        return response()->json(['models' => $models], 200);
    }

    public function show($id)
    {
        PdfColumnProfile::where('user_id', $this->userId())
            ->where('id', $id)
            ->firstOrFail();

        return response()->json(['model' => $this->fullModel('PdfColumnProfile', $id)], 200);
    }

    public function store(Request $request)
    {
        $request->validate(
            $this->store_validation_rules($request),
            [],
            $this->validation_attribute_labels()
        );

        $this->assert_sum_of_column_widths_not_exceeds_paper($request, null);

        $is_afip_ticket = (bool) $request->input('is_afip_ticket', false);

        /**
         * El diseño de la hoja se normaliza ANTES de escribir nada: si no tiene forma, el 422 tiene
         * que salir sin haber apagado los "por defecto" de los hermanos, que es lo primero que
         * escribe este método.
         */
        try {
            $page_layout = $this->normalize_page_layout(
                $this->page_layout_from_request($request),
                $request->model_name,
                $is_afip_ticket
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        /** Una factura de ARCA diseñada con cajas no puede nacer en una hoja donde no entra su cuadro (A5). */
        $hoja_chica = $this->factura_en_hoja_chica_response(
            $request->model_name,
            $is_afip_ticket,
            $page_layout,
            $request->input('paper_height_mm')
        );

        if (! is_null($hoja_chica)) {
            return $hoja_chica;
        }

        if ($request->is_default) {
            PdfColumnProfile::where('user_id', $this->userId())
                ->where('model_name', $request->model_name)
                ->update(['is_default' => false]);
        }

        $this->clear_whatsapp_default_flags_on_siblings(
            $request->model_name,
            $is_afip_ticket,
            $request->is_default_whatsapp,
            $request->is_default_whatsapp_afip,
            null
        );

        $this->clear_tienda_default_flag_on_siblings(
            $request->model_name,
            $is_afip_ticket,
            $request->input('is_default_tienda', false),
            null
        );

        $model = PdfColumnProfile::create([
            'user_id' => $this->userId(),
            'model_name' => $request->model_name,
            'name' => $request->name,
            /**
             * `columns` es json NOT NULL sin default (migración 2026_03_25_101000) y la app ya no la
             * usa: las columnas viven en el pivot pdf_column_option_profile. Sin este valor el
             * INSERT revienta con "Field 'columns' doesn't have a default value" (strict mode fijo
             * en config/database.php) y ningún perfil se puede crear por la API. Mismo valor que
             * usan los seeders (PdfColumnProfileSeeder y hermanos).
             */
            'columns' => [],
            'is_default' => (bool) $request->is_default,
            'is_default_whatsapp' => (bool) $request->input('is_default_whatsapp', false),
            'is_default_whatsapp_afip' => (bool) $request->input('is_default_whatsapp_afip', false),
            /**
             * Perfil predeterminado para PDF de ventas solicitado desde la tienda.
             */
            'is_default_tienda' => (bool) $request->input('is_default_tienda', false),
            'paper_width_mm' => (int) $request->paper_width_mm,
            'printable_width_mm' => (int) $request->printable_width_mm,
            'margin_mm' => (int) $request->input('margin_mm', 5),
            /**
             * Tamaño del logo en mm para este perfil. Se guarda null cuando viene vacío,
             * para que el generador de PDF caiga al tamaño global del dueño (users.pdf_image_size).
             */
            'logo_size_mm' => ($request->input('logo_size_mm') !== null && $request->input('logo_size_mm') !== '')
                ? (int) $request->input('logo_size_mm')
                : null,
            'sheet_type_id' => $request->sheet_type_id,
            'is_afip_ticket' => (bool) $request->input('is_afip_ticket', false),
            'show_totals_on_each_page' => (bool) $request->input('show_totals_on_each_page', false),
            'show_comissions' => (bool) $request->input('show_comissions', false),
            'show_total_costs' => (bool) $request->input('show_total_costs', false),
            /**
             * Flag para mostrar/ocultar las observaciones del cliente (clients.description)
             * debajo del header. Default true para compatibilidad con perfiles existentes.
             */
            'show_client_description' => (bool) $request->input('show_client_description', true),
            /**
             * Texto libre del pie de página; null si no se envía.
             */
            'footer_text' => $request->input('footer_text') ?: null,
            /**
             * Flag para mostrar/ocultar el total general en el pie del PDF.
             * Default true para compatibilidad con perfiles existentes.
             */
            'show_total_in_footer' => (bool) $request->input('show_total_in_footer', true),
            /**
             * Flag para mostrar/ocultar la línea "Sub Total" del pie. Default true (el default de la
             * migración). Se persistía SOLO por la columna: el formulario lo mandaba y este
             * controlador lo descartaba en silencio, así que el checkbox "Mostrar Sub Total en el pie"
             * no hacía nada (misión pdf-presupuestos-y-pedidos-personalizables, que lo expone también
             * para presupuestos y pedidos online).
             */
            'show_subtotal_in_footer' => (bool) $request->input('show_subtotal_in_footer', true),
            /**
             * Imprimir la fecha actual en vez de la del comprobante. Default false. Antes solo
             * entraba por update(): un diseño creado con la casilla tildada la perdía.
             */
            'use_current_date' => (bool) $request->input('use_current_date', false),
            /**
             * Modo de listado de descuentos/recargos en el pie: 'descriptivo' (monto + % + total parcial,
             * comportamiento actual) o 'simple' (solo % + nombre). Default 'descriptivo' si no se envía.
             */
            'discount_display_mode' => $request->input('discount_display_mode') ?: 'descriptivo',
            /**
             * URL de imagen de cabecera en cada página del PDF (plantillas de artículos u otras).
             */
            'header_image_url' => $request->input('header_image_url') ?: null,
            /**
             * Tamaño de letra uniforme para encabezados de columnas (PDF tabular de artículos).
             */
            'table_header_font_size' => $this->normalize_table_header_font_size(
                $request->input('table_header_font_size')
            ),
            /**
             * Diseño del header por cuadrante (JSON). Null = el render usa el default por código
             * según PdfColumnProfile::default_header_layout(). Normalizado defensivamente por si
             * llega como string JSON en vez de array.
             */
            'header_layout' => $this->normalize_header_layout($request->input('header_layout')),
            /**
             * Diseño del encabezado del PDF del catálogo de artículos (JSON). Null = sin diseño,
             * el catálogo se imprime como siempre. normalize() acepta array o string JSON y deja
             * el esquema exacto (ver PdfColumnProfile::$casts).
             */
            'catalog_header_layout' => CatalogHeaderLayoutHelper::normalize($request->input('catalog_header_layout')),
            /**
             * Diseño de la hoja armado en el diseñador de PDF, ya normalizado y con los bloques
             * fijos de ARCA si el perfil es fiscal (normalize_page_layout()). Null = el PDF de
             * siempre: un perfil creado sin pasar por el diseñador imprime como hasta ahora.
             */
            'page_layout' => $page_layout,
            /**
             * Alto de la hoja en mm (solo lo lee el PDF con page_layout). Null = A4 (297).
             */
            'paper_height_mm' => $this->normalize_paper_height_mm($request->input('paper_height_mm')),
        ]);

        GeneralHelper::attachModels(
            $model,
            'pdf_column_options',
            $request->pdf_column_options,
            ['visible', 'order', 'width', 'wrap_content', 'font_size', 'text_align']
        );

        return response()->json(['model' => $this->fullModel('PdfColumnProfile', $model->id)], 201);
    }

    public function update(Request $request, $id)
    {
        $model = PdfColumnProfile::where('user_id', $this->userId())
            ->where('id', $id)
            ->firstOrFail();

        $request->validate(
            $this->update_validation_rules($request, $model),
            [],
            $this->validation_attribute_labels()
        );

        $this->assert_printable_width_not_exceeds_paper($request, $model);

        $this->assert_sum_of_column_widths_not_exceeds_paper($request, $model);

        $new_model_name = $request->model_name ?: $model->model_name;

        $is_afip_ticket = $request->has('is_afip_ticket')
            ? (bool) $request->is_afip_ticket
            : (bool) $model->is_afip_ticket;

        /**
         * page_layout solo se toca si el PUT lo menciona (null incluido: "volver al diseño de
         * siempre"). Un PUT que cambia el nombre o una columna no puede convertir ni desconvertir
         * el diseño. Se normaliza ANTES de escribir nada, por el mismo motivo que en store(): un
         * 422 no puede dejar a los hermanos sin su "por defecto".
         */
        $has_page_layout = $request->has('page_layout');
        $page_layout = null;

        if ($has_page_layout) {
            try {
                $page_layout = $this->normalize_page_layout(
                    $this->page_layout_from_request($request),
                    $new_model_name,
                    $is_afip_ticket
                );
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        /**
         * Una factura de ARCA diseñada con cajas no puede quedar en una hoja donde no entra su
         * cuadro (A5). Se mira lo que QUEDA guardado: el diseño y el alto del PUT si vienen, si no
         * los del perfil. Así se frena también tildar "Es factura de ARCA" en el formulario de un
         * diseño armado en A5, que no pasa por el diseñador (que ya no deja elegir A5).
         */
        $hoja_chica = $this->factura_en_hoja_chica_response(
            $new_model_name,
            $is_afip_ticket,
            $has_page_layout ? $page_layout : $model->page_layout,
            $request->has('paper_height_mm') ? $request->input('paper_height_mm') : $model->paper_height_mm
        );

        if (! is_null($hoja_chica)) {
            return $hoja_chica;
        }

        if ($request->is_default) {
            PdfColumnProfile::where('user_id', $this->userId())
                ->where('model_name', $new_model_name)
                ->where('id', '!=', $model->id)
                ->update(['is_default' => false]);
        }

        $this->clear_whatsapp_default_flags_on_siblings(
            $new_model_name,
            $is_afip_ticket,
            $request->is_default_whatsapp,
            $request->is_default_whatsapp_afip,
            $model->id
        );

        $this->clear_tienda_default_flag_on_siblings(
            $new_model_name,
            $is_afip_ticket,
            $request->input('is_default_tienda', false),
            $model->id
        );

        $fillable = $request->only([
            'model_name',
            'name',
            'is_default',
            'is_default_whatsapp',
            'is_default_whatsapp_afip',
            'is_default_tienda',
            'paper_width_mm',
            'printable_width_mm',
            'paper_height_mm',
            'margin_mm',
            'logo_size_mm',
            'sheet_type_id',
            'is_afip_ticket',
            'show_totals_on_each_page',
            'show_comissions',
            'show_total_costs',
            'show_client_description',
            'footer_text',
            'show_total_in_footer',
            'show_subtotal_in_footer',
            'discount_display_mode',
            'use_current_date',
            'header_image_url',
            'table_header_font_size',
            'header_layout',
            'catalog_header_layout',
        ]);

        /**
         * Un logo_size_mm vacío desde el formulario significa "usar el tamaño global del dueño":
         * se persiste como null para que el fallback en el generador de PDF aplique.
         */
        if (array_key_exists('logo_size_mm', $fillable)) {
            if ($fillable['logo_size_mm'] === '' || $fillable['logo_size_mm'] === null) {
                $fillable['logo_size_mm'] = null;
            } else {
                $fillable['logo_size_mm'] = (int) $fillable['logo_size_mm'];
            }
        }

        /**
         * discount_display_mode vacío o inválido cae a 'descriptivo' (comportamiento por defecto).
         */
        if (array_key_exists('discount_display_mode', $fillable)) {
            if ($fillable['discount_display_mode'] !== 'simple') {
                $fillable['discount_display_mode'] = 'descriptivo';
            }
        }

        /**
         * header_layout puede venir null (perfil sin diseño custom) o, defensivamente,
         * como string JSON en vez de array (el cast 'array' del modelo espera un array/null).
         */
        if (array_key_exists('header_layout', $fillable)) {
            $fillable['header_layout'] = $this->normalize_header_layout($fillable['header_layout']);
        }

        /**
         * catalog_header_layout: mismo criterio que header_layout. Solo se toca si el PUT lo
         * menciona (un update que solo cambia el nombre no lo pisa); null lo borra.
         */
        if (array_key_exists('catalog_header_layout', $fillable)) {
            $fillable['catalog_header_layout'] = CatalogHeaderLayoutHelper::normalize($fillable['catalog_header_layout']);
        }

        /** Ya normalizado arriba (antes de cualquier escritura). */
        if ($has_page_layout) {
            $fillable['page_layout'] = $page_layout;
        }

        if (array_key_exists('paper_height_mm', $fillable)) {
            $fillable['paper_height_mm'] = $this->normalize_paper_height_mm($fillable['paper_height_mm']);
        }

        $model->update($fillable);

        if ($request->has('pdf_column_options')) {
            GeneralHelper::attachModels(
                $model,
                'pdf_column_options',
                $request->pdf_column_options,
                ['visible', 'order', 'width', 'wrap_content', 'font_size', 'text_align']
            );
        }

        return response()->json(['model' => $this->fullModel('PdfColumnProfile', $model->id)], 200);
    }

    public function destroy($id)
    {
        $model = PdfColumnProfile::where('user_id', $this->userId())
            ->where('id', $id)
            ->firstOrFail();
        $model->delete();

        return response()->json(null, 204);
    }

    /**
     * Duplica un perfil de PDF del usuario autenticado con toda su configuración y columnas (pivots).
     * La copia apaga los flags de "por defecto" para no colisionar con los únicos por model_name.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function duplicate($id)
    {
        // Solo permite duplicar perfiles del usuario autenticado; 404 si el id no es suyo.
        $original = PdfColumnProfile::where('user_id', $this->userId())
            ->where('id', $id)
            ->with(['pdf_column_options'])
            ->firstOrFail();

        // replicate() copia todas las columnas menos PK y timestamps: la copia arranca
        // con toda la config del original (anchos, márgenes, pie, imagen de cabecera, flags de contenido).
        $new_model = $original->replicate();
        $new_model->name = $this->build_duplicated_name($original->name);
        // Apagamos los flags de "por defecto" para no pisar los únicos por model_name que maneja store/update.
        $new_model->is_default = false;
        $new_model->is_default_whatsapp = false;
        $new_model->is_default_whatsapp_afip = false;
        $new_model->is_default_tienda = false;
        $new_model->save();

        // Arma el array de pivots (id de columna => atributos del pivot) para sincronizar en el nuevo perfil.
        $pivot_data = [];
        foreach ($original->pdf_column_options as $option) {
            $pivot = $option->pivot;
            $pivot_data[$option->id] = [
                'visible' => $pivot->visible,
                'order' => $pivot->order,
                'width' => $pivot->width,
                'wrap_content' => $pivot->wrap_content,
                'font_size' => $pivot->font_size,
                'text_align' => $pivot->text_align,
            ];
        }
        if (! empty($pivot_data)) {
            $new_model->pdf_column_options()->sync($pivot_data);
        }

        return response()->json(['model' => $this->fullModel('PdfColumnProfile', $new_model->id)], 201);
    }

    /**
     * Lo que necesita el diseñador del encabezado del catálogo al abrirse: los datos del negocio
     * que se pueden arrastrar como renglones (con su valor actual), el logo y el nombre del
     * negocio para la previsualización, y el diseño por defecto para un perfil que todavía no
     * tiene uno. GET api/pdf-column-profiles/catalog-header-sources.
     *
     * El usuario es el dueño (UserHelper::getFullModel(): trae afip_information.iva_condition y
     * addresses), no el empleado autenticado: los datos del encabezado son del negocio.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function catalog_header_sources()
    {
        $user = UserHelper::getFullModel();

        return response()->json([
            'sources'        => CatalogHeaderLayoutHelper::sources_for_user($user),
            'logo_url'       => (! is_null($user) && $user->image_url) ? $user->image_url : null,
            'company_name'   => ! is_null($user) ? (string) $user->company_name : '',
            'default_layout' => CatalogHeaderLayoutHelper::default_for_user($user),
        ], 200);
    }

    /**
     * Lo que necesita el diseñador de PDF (cajas) al abrirse: el catálogo de campos del modelo, los
     * bloques fijos, los formatos de hoja, los límites del diseño, el diseño derivado de los flags
     * del perfil y un comprobante para "Ver un PDF de prueba" (misión diseno-pdf-configurable,
     * contrato §2.3 del plan).
     *
     * GET api/pdf-column-profiles/page-layout-catalog?model_name=sale|budget|order&profile_id=&is_afip_ticket=0|1
     *
     * - model_name que no se diseña con cajas (el catálogo de artículos, o nada) → 422.
     * - profile_id de otro dueño o de otro modelo se IGNORA, no da 404: el diseñador abre igual,
     *   con el derivado de un perfil nuevo. Es lo que pasa también con un perfil que se está
     *   creando y todavía no tiene id.
     * - is_afip_ticket viaja porque el formulario puede tener tildado "Es factura de ARCA" sin
     *   haber guardado todavía: manda el del formulario; si no viene, el del perfil guardado.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function page_layout_catalog(Request $request)
    {
        /**
         * Sin cast a string: un `model_name[]=sale` llega como arreglo, y castearlo tira un notice
         * que Laravel convierte en 500. Lo que no es texto es, simplemente, un modelo que no se
         * diseña con cajas.
         */
        $model_name = $request->query('model_name');

        if (! is_string($model_name) || ! CatalogoDeCamposPdf::soporta($model_name)) {
            return response()->json(['message' => 'Ese tipo de diseño no se arma con cajas.'], 422);
        }

        $owner_id = $this->userId();

        $profile = null;
        $profile_id = $request->query('profile_id');

        if (is_numeric($profile_id)) {
            $profile = PdfColumnProfile::where('user_id', $owner_id)
                ->where('model_name', $model_name)
                ->where('id', (int) $profile_id)
                ->first();
        }

        $is_afip_ticket = $request->filled('is_afip_ticket')
            ? $request->boolean('is_afip_ticket')
            : (! is_null($profile) && (bool) $profile->is_afip_ticket);

        $es_fiscal = DisenoDerivadoPdf::es_fiscal($model_name, $is_afip_ticket);

        return response()->json([
            'model_name'            => $model_name,
            'es_fiscal'             => $es_fiscal,
            'categorias'            => CatalogoDeCamposPdf::categorias($model_name),
            'campos'                => CatalogoDeCamposPdf::campos($model_name),
            'fijos'                 => CatalogoDeCamposPdf::fijos($model_name, $es_fiscal),
            'formatos_de_hoja'      => CatalogoDeCamposPdf::formatos_de_hoja(),
            'limites'               => $this->page_layout_limits(),
            'diseno_derivado'       => DisenoDerivadoPdf::para($model_name, $profile, $es_fiscal, User::find($owner_id)),
            'comprobante_de_prueba' => DisenoDerivadoPdf::comprobante_de_prueba($model_name, $es_fiscal, $owner_id),
        ], 200);
    }

    /**
     * Los límites del diseño de la hoja que el diseñador respeta, leídos de las constantes de
     * DisenoDePaginaPdf: el SPA no tiene una copia propia, y si un límite cambia acá, cambia allá.
     *
     * @return array<string, mixed>
     */
    protected function page_layout_limits()
    {
        return [
            'max_items_por_zona'  => DisenoDePaginaPdf::MAX_ITEMS_POR_ZONA,
            'max_campos_por_caja' => DisenoDePaginaPdf::MAX_CAMPOS_POR_CAJA,
            'max_titulo'          => DisenoDePaginaPdf::MAX_TITULO,
            'max_etiqueta'        => DisenoDePaginaPdf::MAX_ETIQUETA,
            'max_texto_libre'     => DisenoDePaginaPdf::MAX_TEXTO_LIBRE,
            'tamano_min'          => DisenoDePaginaPdf::TAMANO_MIN,
            'tamano_max'          => DisenoDePaginaPdf::TAMANO_MAX,
            'margen_min'          => DisenoDePaginaPdf::MARGEN_MIN,
            'margen_max'          => DisenoDePaginaPdf::MARGEN_MAX,
            'estilos_de_caja'     => DisenoDePaginaPdf::ESTILOS_DE_CAJA,
            'alineaciones'        => DisenoDePaginaPdf::ALINEACIONES,
        ];
    }

    /**
     * Nombre de la copia ("Copia de X") respetando el máximo de 120 caracteres de la columna name.
     *
     * @param  string  $original_name
     * @return string
     */
    protected function build_duplicated_name($original_name)
    {
        // Prefijo estándar para copias; si supera el máximo de la columna, se trunca.
        $name = 'Copia de ' . $original_name;
        if (strlen($name) > 120) {
            $name = substr($name, 0, 120);
        }

        return $name;
    }

    /**
     * Deja un solo perfil WhatsApp por tipo (remito vs factura ARCA) dentro del mismo model_name.
     *
     * @param string $model_name
     * @param bool $is_afip_ticket Tipo del perfil que se guarda.
     * @param mixed $wants_remito_whatsapp is_default_whatsapp en el request.
     * @param mixed $wants_afip_whatsapp is_default_whatsapp_afip en el request.
     * @param int|null $except_id Id del perfil actual en update.
     * @return void
     */
    protected function clear_whatsapp_default_flags_on_siblings(
        $model_name,
        $is_afip_ticket,
        $wants_remito_whatsapp,
        $wants_afip_whatsapp,
        $except_id = null
    ) {
        $base_query = PdfColumnProfile::where('user_id', $this->userId())
            ->where('model_name', $model_name);
        if ($except_id) {
            $base_query->where('id', '!=', $except_id);
        }

        if ($wants_remito_whatsapp && ! $is_afip_ticket) {
            (clone $base_query)->where('is_afip_ticket', false)->update(['is_default_whatsapp' => false]);
        }

        if ($wants_afip_whatsapp && $is_afip_ticket) {
            (clone $base_query)->where('is_afip_ticket', true)->update(['is_default_whatsapp_afip' => false]);
        }
    }

    /**
     * Deja un solo perfil predeterminado de tienda por tipo (remito vs factura ARCA) dentro del mismo model_name.
     *
     * @param string $model_name
     * @param bool $is_afip_ticket Tipo del perfil que se guarda.
     * @param mixed $wants_tienda_default is_default_tienda en el request.
     * @param int|null $except_id Id del perfil actual en update.
     * @return void
     */
    protected function clear_tienda_default_flag_on_siblings(
        $model_name,
        $is_afip_ticket,
        $wants_tienda_default,
        $except_id = null
    ) {
        if (! $wants_tienda_default) {
            return;
        }

        $base_query = PdfColumnProfile::where('user_id', $this->userId())
            ->where('model_name', $model_name)
            ->where('is_afip_ticket', (bool) $is_afip_ticket);

        if ($except_id) {
            $base_query->where('id', '!=', $except_id);
        }

        $base_query->update(['is_default_tienda' => false]);
    }

    /**
     * Etiquetas en español para sustituir :attribute en mensajes de validación.
     *
     * @return array<string, string>
     */
    protected function validation_attribute_labels(): array
    {
        return [
            'model_name' => 'modelo',
            'name' => 'nombre',
            'is_default' => 'perfil por defecto',
            'is_default_whatsapp' => 'perfil predeterminado para WhatsApp (remito)',
            'is_default_whatsapp_afip' => 'perfil predeterminado para WhatsApp (factura ARCA)',
            'is_default_tienda' => 'perfil predeterminado para la tienda',
            'paper_width_mm' => 'ancho de hoja (mm)',
            'printable_width_mm' => 'ancho imprimible (mm)',
            'paper_height_mm' => 'alto de hoja (mm)',
            'page_layout' => 'diseño de la hoja',
            'margin_mm' => 'margen lateral (mm)',
            'sheet_type_id' => 'tipo de hoja',
            'is_afip_ticket' => 'perfil fiscal AFIP',
            'show_totals_on_each_page' => 'mostrar totales en cada hoja',
            'show_comissions' => 'mostrar comisiones',
            'show_total_costs' => 'mostrar total costos',
            'show_client_description' => 'mostrar observaciones del cliente',
            'footer_text' => 'pie de página',
            'show_total_in_footer' => 'mostrar total en el pie',
            'show_subtotal_in_footer' => 'mostrar sub total en el pie',
            'table_header_font_size' => 'tamaño de letra del encabezado de columnas',
            'catalog_header_layout' => 'diseño del encabezado del catálogo',
            'pdf_column_options' => 'opciones de columnas',
            'pdf_column_options.*.id' => 'opción de columna',
            'pdf_column_options.*.pivot.visible' => 'visible',
            'pdf_column_options.*.pivot.order' => 'orden',
            'pdf_column_options.*.pivot.width' => 'ancho',
            'pdf_column_options.*.pivot.wrap_content' => 'salto de línea',
            'pdf_column_options.*.pivot.font_size' => 'tamaño de letra',
            'pdf_column_options.*.pivot.text_align' => 'alineación horizontal',
        ];
    }

    /**
     * Reglas para crear un perfil: coincide con columnas de `pdf_column_profiles` y pivots del attach.
     *
     * @param \Illuminate\Http\Request $request Payload entrante (debe incluir `model_name` para validar ids de opciones).
     * @return array<string, mixed>
     */
    protected function store_validation_rules(Request $request): array
    {
        $model_name = $request->input('model_name');

        return [
            'model_name' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
            'is_default_whatsapp' => ['sometimes', 'boolean'],
            'is_default_whatsapp_afip' => ['sometimes', 'boolean'],
            'is_default_tienda' => ['sometimes', 'boolean'],
            'paper_width_mm' => ['required', 'integer', 'min:1', 'max:50000'],
            'printable_width_mm' => ['required', 'integer', 'min:1', 'max:50000', 'lte:paper_width_mm'],
            'paper_height_mm' => $this->paper_height_mm_rules(),
            'margin_mm' => ['sometimes', 'integer', 'min:0', 'max:5000'],
            'sheet_type_id' => ['nullable', 'integer', Rule::exists('sheet_types', 'id')],
            'is_afip_ticket' => ['sometimes', 'boolean'],
            'show_totals_on_each_page' => ['sometimes', 'boolean'],
            'show_comissions' => ['sometimes', 'boolean'],
            'show_total_costs' => ['sometimes', 'boolean'],
            'show_client_description' => ['sometimes', 'boolean'],
            'footer_text' => ['nullable', 'string', 'max:2000'],
            'show_total_in_footer' => ['sometimes', 'boolean'],
            'show_subtotal_in_footer' => ['sometimes', 'boolean'],
            'table_header_font_size' => ['sometimes', 'nullable', 'integer', 'min:4', 'max:24'],
            /** Sin tipo: puede llegar array o string JSON; CatalogHeaderLayoutHelper::normalize() resuelve. */
            'catalog_header_layout' => ['sometimes', 'nullable'],
            /** Sin tipo, por lo mismo: DisenoDePaginaPdf::normalizar() resuelve y, si no tiene forma, store() responde 422. */
            'page_layout' => ['sometimes', 'nullable'],
            'pdf_column_options' => ['required', 'array', 'min:1'],
            'pdf_column_options.*.id' => [
                'required',
                'integer',
                Rule::exists('pdf_column_options', 'id')->where('model_name', $model_name),
            ],
            'pdf_column_options.*.pivot' => ['sometimes', 'array'],
            'pdf_column_options.*.pivot.visible' => ['sometimes', 'boolean'],
            'pdf_column_options.*.pivot.order' => ['sometimes', 'integer', 'min:0'],
            'pdf_column_options.*.pivot.width' => ['sometimes', 'integer', 'min:0', 'max:5000'],
            'pdf_column_options.*.pivot.wrap_content' => ['sometimes', 'boolean'],
            'pdf_column_options.*.pivot.font_size' => ['sometimes', 'nullable', 'integer', 'min:4', 'max:24'],
            'pdf_column_options.*.pivot.text_align' => ['sometimes', 'nullable', 'string', Rule::in(['left', 'center', 'right'])],
        ];
    }

    /**
     * Reglas para actualizar: campos opcionales salvo que se envíen; `pdf_column_options` solo si viene en el body.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\PdfColumnProfile $model Perfil actual (resuelve `model_name` si no se reenvía).
     * @return array<string, mixed>
     */
    protected function update_validation_rules(Request $request, PdfColumnProfile $model): array
    {
        $resolved_model_name = $request->filled('model_name')
            ? $request->input('model_name')
            : $model->model_name;

        return [
            'model_name' => ['sometimes', 'string', 'max:60'],
            'name' => ['sometimes', 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
            'is_default_whatsapp' => ['sometimes', 'boolean'],
            'is_default_whatsapp_afip' => ['sometimes', 'boolean'],
            'is_default_tienda' => ['sometimes', 'boolean'],
            'paper_width_mm' => ['sometimes', 'integer', 'min:1', 'max:50000'],
            'printable_width_mm' => ['sometimes', 'integer', 'min:1', 'max:50000'],
            'paper_height_mm' => $this->paper_height_mm_rules(),
            'margin_mm' => ['sometimes', 'integer', 'min:0', 'max:5000'],
            'sheet_type_id' => ['sometimes', 'nullable', 'integer', Rule::exists('sheet_types', 'id')],
            'is_afip_ticket' => ['sometimes', 'boolean'],
            'show_totals_on_each_page' => ['sometimes', 'boolean'],
            'show_comissions' => ['sometimes', 'boolean'],
            'show_total_costs' => ['sometimes', 'boolean'],
            'show_client_description' => ['sometimes', 'boolean'],
            'footer_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'show_total_in_footer' => ['sometimes', 'boolean'],
            'show_subtotal_in_footer' => ['sometimes', 'boolean'],
            'table_header_font_size' => ['sometimes', 'nullable', 'integer', 'min:4', 'max:24'],
            /** Sin tipo: puede llegar array o string JSON; CatalogHeaderLayoutHelper::normalize() resuelve. */
            'catalog_header_layout' => ['sometimes', 'nullable'],
            /** Sin tipo, por lo mismo: DisenoDePaginaPdf::normalizar() resuelve y, si no tiene forma, update() responde 422. */
            'page_layout' => ['sometimes', 'nullable'],
            'pdf_column_options' => ['sometimes', 'array'],
            'pdf_column_options.*.id' => [
                'required_with:pdf_column_options',
                'integer',
                Rule::exists('pdf_column_options', 'id')->where('model_name', $resolved_model_name),
            ],
            'pdf_column_options.*.pivot' => ['sometimes', 'array'],
            'pdf_column_options.*.pivot.visible' => ['sometimes', 'boolean'],
            'pdf_column_options.*.pivot.order' => ['sometimes', 'integer', 'min:0'],
            'pdf_column_options.*.pivot.width' => ['sometimes', 'integer', 'min:0', 'max:5000'],
            'pdf_column_options.*.pivot.wrap_content' => ['sometimes', 'boolean'],
            'pdf_column_options.*.pivot.font_size' => ['sometimes', 'nullable', 'integer', 'min:4', 'max:24'],
            'pdf_column_options.*.pivot.text_align' => ['sometimes', 'nullable', 'string', Rule::in(['left', 'center', 'right'])],
        ];
    }

    /**
     * Garantiza que el ancho imprimible no supere el de papel cuando el update envía solo uno de los dos.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\PdfColumnProfile $model Valores previos si faltan en el request.
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function assert_printable_width_not_exceeds_paper(Request $request, PdfColumnProfile $model): void
    {
        $paper = (int) ($request->input('paper_width_mm') ?? $model->paper_width_mm);
        $printable = (int) ($request->input('printable_width_mm') ?? $model->printable_width_mm);
        if ($printable > $paper) {
            throw ValidationException::withMessages([
                'printable_width_mm' => ['El ancho imprimible no puede superar el ancho de hoja.'],
            ]);
        }
    }

    /**
     * Indica si el pivot del request cuenta como columna visible/activa para sumar ancho.
     * Si no viene `visible`, se asume activa (mismo criterio que el default en BD).
     *
     * @param array $pivot Fragmento `pivot` del JSON del cliente.
     * @return bool
     */
    protected function is_request_pivot_visible_for_width_sum(array $pivot): bool
    {
        if (! array_key_exists('visible', $pivot)) {
            return true;
        }
        $visible = $pivot['visible'];
        if ($visible === true || $visible === 1 || $visible === '1') {
            return true;
        }
        if ($visible === false || $visible === 0 || $visible === '0' || $visible === '') {
            return false;
        }

        return (bool) $visible;
    }

    /**
     * Indica si el pivot persistido está visible (columna activa).
     * Trata 0 / "0" / false como no visible (evita el fallo de `(bool) "0"` en PHP).
     *
     * @param \Illuminate\Database\Eloquent\Relations\Pivot|object $pivot Pivot de la relación.
     * @return bool
     */
    protected function is_attached_pivot_visible_for_width_sum($pivot): bool
    {
        if (! isset($pivot->visible)) {
            return true;
        }
        $visible = $pivot->visible;
        if ($visible === true || $visible === 1 || $visible === '1') {
            return true;
        }
        if ($visible === false || $visible === 0 || $visible === '0' || $visible === '') {
            return false;
        }

        return (bool) $visible;
    }

    /**
     * Suma los anchos (`pivot.width`) solo de columnas con `pivot.visible` activo.
     *
     * @param mixed $pdf_column_options Lista tal como llega del cliente (objetos con `pivot`).
     * @return int Suma en mm (filas ocultas o sin pivot activo no suman).
     */
    protected function sum_pivot_widths_from_request_options($pdf_column_options): int
    {
        if (! is_array($pdf_column_options)) {
            return 0;
        }
        $sum = 0;
        foreach ($pdf_column_options as $row) {
            if (! is_array($row)) {
                continue;
            }
            $pivot = (isset($row['pivot']) && is_array($row['pivot'])) ? $row['pivot'] : [];
            if (! $this->is_request_pivot_visible_for_width_sum($pivot)) {
                continue;
            }
            $sum += (int) ($pivot['width'] ?? 0);
        }

        return $sum;
    }

    /**
     * Suma anchos en pivots ya guardados, solo donde `visible` está activo.
     *
     * @param \App\Models\PdfColumnProfile $model Debe tener cargada la relación `pdf_column_options`.
     * @return int Suma en mm.
     */
    protected function sum_pivot_widths_from_attached_options(PdfColumnProfile $model): int
    {
        $sum = 0;
        foreach ($model->pdf_column_options as $option) {
            if (! $this->is_attached_pivot_visible_for_width_sum($option->pivot)) {
                continue;
            }
            $sum += (int) ($option->pivot->width ?? 0);
        }

        return $sum;
    }

    /**
     * Calcula ancho útil disponible para columnas visibles en mm.
     *
     * Ejemplo A4: imprimible 210, margen 5 por lado → 210 − 10 = 200 mm para columnas.
     *
     * @param int $printable_width_mm Ancho imprimible de la hoja (antes de márgenes laterales).
     * @param int $margin_mm Margen por lado (izquierdo y derecho).
     * @return int
     */
    protected function get_available_width_mm_for_columns(int $printable_width_mm, int $margin_mm): int
    {
        /**
         * La regla vive en PdfColumnProfileHelper::ancho_disponible_mm() (misión
         * asistente-masivas-imagenes-y-remito): es la MISMA que usa el asistente al acomodar
         * las columnas de un diseño, y tiene que haber una sola. Se descuentan ambos márgenes
         * laterales para evitar desbordes.
         */
        return PdfColumnProfileHelper::ancho_disponible_mm($printable_width_mm, $margin_mm);
    }

    /**
     * Impide que la suma de anchos de columnas visibles supere el ancho útil (imprimible - márgenes).
     *
     * - En store: usa siempre el body del request.
     * - En update con `pdf_column_options`: suma lo enviado y compara contra ancho imprimible/márgenes efectivos.
     * - En update solo con `printable_width_mm` o `margin_mm`: revalida la suma de pivots ya guardados.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\PdfColumnProfile|null $model Null en store; perfil actual en update.
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function assert_sum_of_column_widths_not_exceeds_paper(Request $request, ?PdfColumnProfile $model = null): void
    {
        $printable_width_mm = (int) ($request->input('printable_width_mm') ?? ($model !== null ? $model->printable_width_mm : 0));
        $margin_mm = (int) ($request->input('margin_mm') ?? ($model !== null ? $model->margin_mm : 5));
        $available_width_mm = $this->get_available_width_mm_for_columns($printable_width_mm, $margin_mm);
        if ($available_width_mm <= 0) {
            return;
        }

        $sum_widths = null;

        if ($request->has('pdf_column_options')) {
            $sum_widths = $this->sum_pivot_widths_from_request_options($request->input('pdf_column_options'));
        } elseif ($model !== null && ($request->has('printable_width_mm') || $request->has('margin_mm'))) {
            $model->loadMissing('pdf_column_options');
            $sum_widths = $this->sum_pivot_widths_from_attached_options($model);
        }

        if ($sum_widths === null) {
            return;
        }

        if ($sum_widths > $available_width_mm) {
            throw ValidationException::withMessages([
                'pdf_column_options' => [
                    sprintf(
                        'La suma de los anchos visibles (%d mm) no puede superar el ancho disponible (%d mm) luego de descontar márgenes.',
                        $sum_widths,
                        $available_width_mm
                    ),
                ],
            ]);
        }
    }

    /**
     * Normaliza el tamaño de letra del encabezado tabular (4–24 pt); null si no es válido.
     *
     * @param  mixed  $value
     * @return int|null
     */
    protected function normalize_table_header_font_size($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $font_size = (int) $value;
        if ($font_size >= 4 && $font_size <= 24) {
            return $font_size;
        }

        return null;
    }

    /**
     * Normaliza el JSON de diseño del header por cuadrante antes de persistirlo.
     * No valida estrictamente la estructura (eso lo consumen los renders de forma defensiva);
     * solo garantiza que, si llegó como string JSON, se decodifique a array antes de guardar
     * (con el cast 'array' del modelo y el axios del SPA debería llegar ya como objeto/array).
     *
     * @param  mixed  $value
     * @return array|null
     */
    protected function normalize_header_layout($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }

        return is_array($value) ? $value : null;
    }

    /**
     * page_layout tal como vino en el cuerpo del pedido, SIN pasar por los middleware globales
     * TrimStrings y ConvertEmptyStringsToNull (app/Http/Kernel.php).
     *
     * 🔴 No es un capricho. En el diseño, `etiqueta: ""` ("sin rótulo") y `etiqueta: null` ("la
     * del catálogo") son dos cosas distintas, y ConvertEmptyStringsToNull convierte en null todo ""
     * anidado, también adentro de $request->json(). Leído de $request->input(), el recuadro de
     * observaciones (su campo va sin rótulo, debajo del título "OBSERVACIONES") se guardaría con el
     * rótulo del catálogo y el PDF imprimiría "Observaciones: ..." adentro de esa caja. TrimStrings,
     * por su lado, le comería los espacios del principio a un texto libre.
     *
     * Si el pedido no es JSON, o el cuerpo no trae la clave, vale $request->input() como siempre.
     *
     * @param \Illuminate\Http\Request $request
     * @return mixed
     */
    protected function page_layout_from_request(Request $request)
    {
        if ($request->isJson()) {
            $body = json_decode((string) $request->getContent(), true);

            if (is_array($body) && array_key_exists('page_layout', $body)) {
                return $body['page_layout'];
            }
        }

        return $request->input('page_layout');
    }

    /**
     * El diseño de la hoja tal como se guarda: normalizado (DisenoDePaginaPdf::normalizar()) y con
     * los bloques fijos de ARCA puestos o sacados según el perfil sea factura o no
     * (asegurar_fijos()). Un modelo que no se diseña con cajas (el catálogo de artículos) guarda
     * siempre null: su PDF no lee esta columna, y un diseño ahí quedaría colgado sin que nada lo
     * muestre.
     *
     * @param mixed  $value          array, string JSON o null, tal como vino.
     * @param string $model_name
     * @param bool   $is_afip_ticket el del request si vino, si no el del perfil guardado.
     * @return array|null
     * @throws \InvalidArgumentException si no tiene la forma de un diseño (el que llama responde 422).
     */
    protected function normalize_page_layout($value, $model_name, $is_afip_ticket)
    {
        if (! CatalogoDeCamposPdf::soporta($model_name)) {
            return null;
        }

        $page_layout = DisenoDePaginaPdf::normalizar($value);

        if (is_null($page_layout)) {
            return null;
        }

        return DisenoDePaginaPdf::asegurar_fijos(
            $page_layout,
            DisenoDerivadoPdf::es_fiscal($model_name, $is_afip_ticket)
        );
    }

    /**
     * 422 si el perfil queda como factura de ARCA diseñada con cajas en una hoja donde no entra el
     * cuadro de ARCA (DisenoDerivadoPdf::factura_en_hoja_chica()), o null si no.
     *
     * Va armada a mano y no como ValidationException: el Handler de la app le pisa el `message` a
     * esa excepción con el genérico traducido, y acá el `message` es un título propio.
     *
     * 🔴 Título y detalle van SEPARADOS, y no se repiten. `message` lleva el título corto
     * (TITULO_FACTURA_EN_HOJA_CHICA) y el detalle (MENSAJE_FACTURA_EN_HOJA_CHICA) va SOLO en
     * `errors.paper_height_mm`, bajo la clave del alto de la hoja como el resto de la validación de
     * este controller (printable_width_mm, pdf_column_options). El aviso global del SPA arma
     * "<message>\n\n1. <errors…>": con el mismo texto en los dos, el formulario genérico del
     * registro mostraba el aviso dos veces seguidas (verificación en vivo, 1/10/2026). El diseñador
     * lee `errors` primero, así que sigue mostrando el detalle.
     *
     * @param string     $model_name
     * @param bool       $is_afip_ticket
     * @param array|null $page_layout     el que queda guardado.
     * @param mixed      $paper_height_mm el que queda guardado.
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function factura_en_hoja_chica_response($model_name, $is_afip_ticket, $page_layout, $paper_height_mm)
    {
        if (! DisenoDerivadoPdf::factura_en_hoja_chica($model_name, $is_afip_ticket, $page_layout, $paper_height_mm)) {
            return null;
        }

        return response()->json([
            'message' => DisenoDerivadoPdf::TITULO_FACTURA_EN_HOJA_CHICA,
            'errors'  => [
                'paper_height_mm' => [DisenoDerivadoPdf::MENSAJE_FACTURA_EN_HOJA_CHICA],
            ],
        ], 422);
    }

    /**
     * Reglas del alto de la hoja (mm), las mismas para el alta y la edición. Los topes salen de
     * DisenoDePaginaPdf, que es donde vive el resto de los límites del diseño.
     *
     * @return array<int, string>
     */
    protected function paper_height_mm_rules()
    {
        return [
            'sometimes',
            'nullable',
            'integer',
            'between:'.DisenoDePaginaPdf::ALTO_DE_HOJA_MIN.','.DisenoDePaginaPdf::ALTO_DE_HOJA_MAX,
        ];
    }

    /**
     * Alto de la hoja tal como se guarda: null si viene vacío (el PDF con page_layout usa A4), o
     * el entero que ya validó paper_height_mm_rules().
     *
     * @param mixed $value
     * @return int|null
     */
    protected function normalize_paper_height_mm($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
