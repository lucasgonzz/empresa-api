<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\ClientMailConfigHelper;
use App\Models\OnlineConfiguration;
use App\Services\LogoPaletteAiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class OnlineConfigurationController extends Controller
{

    public function index() {
        $models = OnlineConfiguration::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function update(Request $request, $id) {
        $model = OnlineConfiguration::find($id);
        $model->pausar_tienda_online            = $request->pausar_tienda_online;    
        $model->register_to_buy                 = $request->register_to_buy;    
        $model->scroll_infinito_en_home         = $request->scroll_infinito_en_home;    
        $model->online_price_type_id            = $request->online_price_type_id;                     
        $model->online_price_surchage           = $request->online_price_surchage;                      
        $model->instagram                       = $request->instagram;                     
        $model->facebook                        = $request->facebook;                     
        $model->quienes_somos                   = $request->quienes_somos;
        $model->aviso_antes_de_confirmar_compra = $request->aviso_antes_de_confirmar_compra;
        $model->default_article_image_url       = $request->default_article_image_url;                     
        $model->logo_url                        = $request->logo_url;                     
        $model->primary_color                   = $request->primary_color;
        $model->secondary_color                 = $request->secondary_color;
        $model->text_color                      = $request->text_color;
        $model->hover_text_color                = $request->hover_text_color;
        $model->background_color                = $request->background_color;
        $model->mensaje_contacto                = $request->mensaje_contacto;                     
        $model->show_articles_without_images    = $request->show_articles_without_images;
        $model->text_precio_pausado             = $request->text_precio_pausado;
        $model->save_sale_after_finish_order    = $request->save_sale_after_finish_order;
                             
        $model->show_articles_without_stock     = $request->show_articles_without_stock;
        $model->stock_null_equal_0              = $request->stock_null_equal_0;

        $model->online_description              = $request->online_description;

        // Descripción para vista previa al compartir el link de la tienda (og:description /
        // twitter:description). Grupo 270 · prompt 01. Distinta de online_description (columna
        // vieja no expuesta en la UI, no reutilizada a propósito, ver prompt).
        // Recorte defensivo a 300 caracteres (coincide con el largo de la columna): un texto
        // pegado desde otro lado que exceda el límite no debe tirar un error de base de datos.
        // String vacío o solo espacios se normaliza a null (no queremos "" persistido).
        $meta_description = $request->meta_description;
        if (!is_null($meta_description)) {
            $meta_description = trim($meta_description);
            if ($meta_description === '') {
                $meta_description = null;
            } elseif (mb_strlen($meta_description) > 300) {
                $meta_description = mb_substr($meta_description, 0, 300);
            }
        }
        $model->meta_description = $meta_description;

        $model->has_delivery                    = $request->has_delivery;
        $model->order_description               = $request->order_description;                     
        $model->online_template_id               = $request->online_template_id;                     
        $model->cantidad_tarjetas_en_telefono               = $request->cantidad_tarjetas_en_telefono;                     
        $model->cantidad_tarjetas_en_tablet               = $request->cantidad_tarjetas_en_tablet;                     
        $model->cantidad_tarjetas_en_notebook               = $request->cantidad_tarjetas_en_notebook;                     
        $model->cantidad_tarjetas_en_escritorio               = $request->cantidad_tarjetas_en_escritorio;


        $model->titulo_quienes_somos                = $request->titulo_quienes_somos;                     
        $model->default_amount_add_to_cart          = $request->default_amount_add_to_cart;       
        $model->pedir_barrio_al_registrarse         = $request->pedir_barrio_al_registrarse;       
        $model->logear_cliente_al_registrar         = $request->logear_cliente_al_registrar;       
        $model->auto_scroll_home                    = $request->auto_scroll_home;       
        $model->auto_scroll_home_init               = $request->auto_scroll_home_init;       
        $model->auto_scroll_home_interval           = $request->auto_scroll_home_interval;       
        $model->article_description_font_size       = $request->article_description_font_size;
        $model->mostrar_catalogo                    = $request->mostrar_catalogo;

        $model->enviar_whatsapp_al_terminar_pedido  = $request->enviar_whatsapp_al_terminar_pedido;

        // Correo propio por cliente (prompt 358): master switch y datos de la casilla SMTP.
        $model->mail_enabled                        = $request->mail_enabled;
        $model->mail_host                           = $request->mail_host;
        $model->mail_port                           = $request->mail_port;
        $model->mail_encryption                     = $request->mail_encryption;
        $model->mail_username                       = $request->mail_username;
        $model->mail_from_address                   = $request->mail_from_address;
        $model->mail_from_name                      = $request->mail_from_name;

        // Notificaciones por mail configurables desde la UI (prompt 382).
        // Estos 3 son booleanos NOT NULL con default true. Si el front todavia no tiene la
        // clave (por ejemplo, la pantalla quedo abierta desde antes de que se agregara el
        // campo) el request llega sin el valor. $request->boolean() nunca devuelve null: si
        // la clave no viene, cae al default indicado (el valor actual del modelo), asi el save
        // nunca pisa un true existente con null y no puede romper la constraint NOT NULL.
        $model->notificar_pedido_al_negocio         = $request->boolean('notificar_pedido_al_negocio', $model->notificar_pedido_al_negocio ?? true);
        $model->mail_notificacion_pedidos           = $request->mail_notificacion_pedidos;
        $model->notificar_pedido_al_cliente         = $request->boolean('notificar_pedido_al_cliente', $model->notificar_pedido_al_cliente ?? true);
        $model->avisar_ingreso_stock_por_mail       = $request->boolean('avisar_ingreso_stock_por_mail', $model->avisar_ingreso_stock_por_mail ?? true);

        // mail_password es write-only: solo se pisa si viene con contenido. Si el request no
        // trae el campo o llega vacio (la UI lo muestra siempre en blanco por seguridad), se
        // conserva la contraseña ya guardada en vez de borrarla.
        if ($request->filled('mail_password')) {
            $model->mail_password = $request->mail_password;
        }

        // Login con Google de la tienda online (prompt 589, grupo 164): credenciales que carga
        // el dueño del comercio desde el modal (prompt 588), reemplazando el .env compartido de
        // tienda-api. $request->boolean() nunca devuelve null: si la clave no viene en el
        // request, cae al valor actual del modelo (o false si todavia no existe), asi el save
        // nunca pisa un true existente con null.
        $model->google_login_enabled = $request->boolean('google_login_enabled', $model->google_login_enabled ?? false);
        $model->google_client_id     = $request->google_client_id;
        // google_client_secret no se loguea ni se expone en ningun response de este controller
        // fuera de fullModel() (igual que mail_password), pero a diferencia de mail_password no
        // tiene cast 'encrypted' ni esta en $hidden: el dueño necesita poder ver/copiar el valor
        // ya guardado para editarlo desde la UI, no es una contraseña de un tercero.
        $model->google_client_secret  = $request->google_client_secret;

        // Mercado Pago / Zippin (grupo 169, prompt 597): desde el panel solo se togglea el
        // master switch de cada integracion. Los tokens OAuth (*_access_token, *_refresh_token)
        // y los datos que devuelve el propio OAuth (mp_user_id, mp_public_key, zippin_account_id,
        // *_token_expires_at) los escriben unicamente los callbacks de OAuth (prompts 598/599),
        // nunca este endpoint: por eso no se leen esos campos del $request aca. $request->boolean()
        // nunca devuelve null: si la clave no viene, cae al valor actual del modelo (o false si
        // todavia no existe), asi el save nunca pisa un true existente con null.
        $model->mp_enabled      = $request->boolean('mp_enabled', $model->mp_enabled ?? false);
        $model->zippin_enabled  = $request->boolean('zippin_enabled', $model->zippin_enabled ?? false);

        $model->save();
        $this->sendAddModelNotification('OnlineConfiguration', $model->id);
        // $this->fullModel() ya devuelve el modelo con mail_password oculto por el $hidden del
        // modelo (Eloquent lo excluye al serializar a JSON), asi que nunca se filtra por esta via.
        return response()->json(['model' => $this->fullModel('OnlineConfiguration', $model->id)], 200);
    }

    /**
     * Envia un mail de prueba usando la configuracion SMTP propia del cliente (si esta activa y
     * completa), para que el dueño del comercio pueda validar sus credenciales sin depender de
     * que un comprador reciba (o no) un aviso real.
     *
     * @param Request $request Request con email_destino (obligatorio, formato email).
     * @return \Illuminate\Http\JsonResponse
     */
    public function testMail(Request $request) {
        // Validacion puntual del formato del mail de destino: justificada porque este endpoint
        // dispara un envio real de correo, no es la validacion general de formularios del ERP.
        $validator = Validator::make($request->all(), [
            'email_destino' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        // Intenta aplicar en runtime la config SMTP del cliente. Si no hay configuracion activa
        // y completa, no tiene sentido "probar" nada: el envio real caeria al .env igual.
        $applied = ClientMailConfigHelper::apply($this->userId());

        if (!$applied) {
            return response()->json([
                'message' => 'No hay una configuración de correo activa y completa para probar',
            ], 422);
        }

        try {
            Mail::raw('Este es un mail de prueba de configuración SMTP de ComercioCity.', function ($message) use ($request) {
                $message->to($request->email_destino)
                        ->subject('Mail de prueba - ComercioCity');
            });

            return response()->json(['message' => 'Mail de prueba enviado correctamente'], 200);
        } catch (\Exception $e) {
            // Se devuelve el mensaje de error de SMTP tal cual: es la razon de ser del endpoint,
            // le dice al dueño del comercio por que no le anda el correo (ej. auth rechazada).
            return response()->json(['message' => 'Error al enviar el mail de prueba: '.$e->getMessage()], 422);
        }
    }

    /**
     * Genera hasta 3 propuestas de paleta de colores para la tienda online a partir del logo
     * del comercio (tienda si tiene, si no el de empresa), usando IA con vision + validacion
     * determinista de contraste (grupo 202, prompt 02).
     *
     * El user_id se resuelve siempre por sesion ($this->userId()): nunca se acepta por request,
     * para que no se pueda pedir la paleta de un logo ajeno.
     *
     * @param Request $request  No se lee ningun campo del body; se deja por firma estandar.
     * @return \Illuminate\Http\JsonResponse
     */
    public function generatePalette(Request $request) {
        $result = (new LogoPaletteAiService())->generate($this->userId());

        if (!$result['ok']) {
            // El mensaje ya viene en español y apto para el usuario (nunca el error crudo de la
            // API de Anthropic ni el texto que devolvio Claude).
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json([
            'palettes'           => $result['palettes'],
            'logo_source'        => $result['logo_source'],
            'logo_is_monochrome' => $result['logo_is_monochrome'],
        ], 200);
    }
}
