<?php

namespace App\Services;

use App\Events\SupportTourOffered;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\SupportSyncHelper;
use App\Http\Controllers\Helpers\SupportTicketHelper;
use App\Http\Controllers\Helpers\TelefonoArgentinoHelper;
use App\Http\Controllers\Helpers\VenderLayoutHelper;
use App\Http\Controllers\Helpers\WhatsappPhoneHelper;
use App\Http\Controllers\Helpers\asistente_ia\PermisosIaHelper;
use App\Models\ImportHistory;
use App\Models\Provider;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\SupportTour;
use App\Models\Surchage;
use App\Models\User;
use App\Models\VenderLayout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SupportTourService
{
    public const VERSION_SOPORTE_WEB = 1;

    public static function sesion_web_propia(Request $request, User $persona, bool $permitir_maestro = false): bool
    {
        $usuario_web = $request->user('web');
        if (!$request->hasSession() || $usuario_web === null || (int) $usuario_web->id !== (int) $persona->id) {
            return false;
        }

        $sesion = $request->session();

        if ($sesion->has('acceso_soporte_token_id') || (!$permitir_maestro && $sesion->get('master_login_bypass_user_last_activity', false))) {
            return false;
        }

        if ($permitir_maestro && $sesion->get('master_login_bypass_user_last_activity', false)) {
            return true;
        }

        if (PruebaHelper::puede_haber_prueba()) {
            return PruebaHelper::en_periodo_vigente(PruebaHelper::owner_id_de($persona));
        }

        return !$sesion->has('demo_ingreso_token_id');
    }

    public static function tiene_chat_soporte(User $persona): bool
    {
        $dueno = $persona->owner_id ? $persona->owner : $persona;

        return PermisosIaHelper::tiene_extencion($dueno, 'support_chat');
    }

    public static function de_la_persona(User $persona): Builder
    {
        return SupportTour::where('owner_id', $persona->owner_id ?: $persona->id)
            ->where('recipient_id', $persona->id);
    }

    public function ofrecer(User $dueno, array $datos): SupportTour
    {
        $mensaje = null;
        $persona_id = $datos['user_id'] ?? $dueno->id;

        if ($datos['source'] === 'sistema') {
            // El UUID corresponde al mensaje de la persona, en el ticket espejo de este frente.
            $mensaje = SupportMessage::with('ticket')->where('uuid', $datos['uuid'])
                ->where('sender_type', 'user')->first();
            abort_unless($mensaje && (int) $mensaje->robotito_support_version === self::VERSION_SOPORTE_WEB
                && $mensaje->ticket && $mensaje->ticket->uuid === $datos['ticket_uuid']
                && (int) $mensaje->ticket->user_id === (int) $mensaje->sender_user_id
                && (!isset($datos['user_id']) || (int) $datos['user_id'] === (int) $mensaje->sender_user_id),
                409, 'No se pudo identificar a la persona de ese mensaje de soporte.');
            $persona_id = $mensaje->sender_user_id;
        }

        $textos = array_values($datos['textos'] ?? []);
        $pasos = $datos['tema'] === SupportTour::GUIA_DINAMICA ? $this->normalizar_pasos($datos['pasos']) : null;
        $total = $pasos === null ? SupportTour::PASOS_POR_TEMA[$datos['tema']] : count($pasos);
        $import_history_id = isset($datos['import_history_id']) ? (int) $datos['import_history_id'] : null;

        [$tour, $nuevo] = DB::transaction(function () use ($dueno, $datos, $mensaje, $persona_id, $textos, $pasos, $total, $import_history_id) {
            // El mismo usuario puede recibir una sola oferta por mensaje, incluso con reintentos simultáneos.
            $persona = User::whereKey($persona_id)->lockForUpdate()->first();
            abort_unless($persona && (int) ($persona->owner_id ?: $persona->id) === (int) $dueno->id,
                409, 'No se pudo identificar a la persona en este sistema.');
            if ($datos['source'] === 'whatsapp' && isset($datos['user_id'])) {
                $this->validar_telefono_del_empleado($dueno, $persona, $datos['recipient_phone']);
            }

            $previo = SupportTour::where('owner_id', $dueno->id)->where('uuid', $datos['uuid'])->first();
            if ($previo) {
                abort_unless((int) $previo->recipient_id === (int) $persona_id
                    && $previo->ticket_uuid === $datos['ticket_uuid'] && $previo->source === $datos['source']
                    && $previo->tema === $datos['tema'] && $previo->titulo === $datos['titulo'] && $previo->textos === $textos
                    && $previo->import_history_id === $import_history_id && $previo->pasos_programados == $pasos,
                    409, 'Ese mensaje ya tiene una oferta de Robotito con otros datos.');

                return [$previo, false];
            }

            if ($datos['tema'] === 'crear_ticket') {
                abort_unless(self::tiene_chat_soporte($persona), 409, 'El chat de soporte no está disponible en este sistema.');
            }
            if (in_array($datos['tema'], ['buscar_articulo_nombre', 'buscar_articulo_codigo'], true)) {
                abort_unless($this->puede_vender($dueno, $persona), 409, 'La pantalla Vender no está disponible para esa persona.');
            }
            if ($datos['tema'] === SupportTour::REVERTIR_IMPORTACION_ARTICULOS) {
                abort_unless(self::puede_abrir_menu_de_listado($persona) && $this->importacion_reversible($dueno, $import_history_id),
                    409, 'Esa importación ya no se puede señalar para revertir desde esta cuenta.');
            }
            if ($pasos !== null) {
                $this->validar_pasos_programados($dueno, $persona, $pasos);
            }

            $anterior = $mensaje ? self::de_la_persona($persona)
                ->where('question_message_id', $mensaje->id)->lockForUpdate()->first() : null;

            $tour = SupportTour::create([
                'owner_id' => $dueno->id,
                'recipient_id' => $persona_id,
                'uuid' => $datos['uuid'],
                'ticket_uuid' => $datos['ticket_uuid'],
                'origin_ticket_uuid' => $anterior ? $anterior->origin_ticket_uuid : $datos['ticket_uuid'],
                'previous_uuid' => optional($anterior)->uuid,
                'source' => $datos['source'],
                'tema' => $datos['tema'],
                'titulo' => $datos['titulo'],
                'textos' => $textos,
                'import_history_id' => $import_history_id,
                'pasos_programados' => $pasos,
                'total_steps' => $total,
                'steps_shown' => [],
            ]);

            if ($anterior) {
                $anterior->reformulated_at = now();
                if ($anterior->state === 'accepted') {
                    $anterior->state = 'reformulated';
                    $anterior->finished_at = $anterior->reformulated_at;
                }
                $anterior->save();
            }

            return [$tour, true];
        });

        if ($nuevo) {
            try {
                event(new SupportTourOffered((int) $persona_id));
            } catch (\Throwable $e) {
                Log::warning('No se pudo avisar el tour por socket. La oferta sigue disponible.', ['uuid' => $tour->uuid]);
            }
        }

        return $tour;
    }

    public static function puede_abrir_menu_de_listado(User $persona): bool
    {
        // Son el acceso a Listado y las mismas condiciones que muestran Crear en esa pantalla.
        return PermisosIaHelper::puede($persona, 'article.index')
            && (PermisosIaHelper::puede($persona, 'article.store')
                || PermisosIaHelper::puede($persona, 'article.export_excel_clients'));
    }

    private function puede_vender(User $dueno, User $persona): bool
    {
        return PermisosIaHelper::tiene_extencion($dueno, 'comerciocity_interno')
            && PermisosIaHelper::puede($persona, 'sale.store');
    }

    private function puede_guiar_recargos(User $dueno, User $persona): bool
    {
        if ((int) ($persona->owner_id ?: $persona->id) !== (int) $dueno->id
            || !$this->puede_vender($dueno, $persona)
            || !PermisosIaHelper::puede($persona, 'sale.discount_surchage.aplicar')
            || !Surchage::where('user_id', $dueno->id)->exists()) {
            return false;
        }

        return $this->campo_vender_disponible($dueno, 'recargos');
    }

    private function campo_vender_disponible(User $dueno, string $campo): bool
    {
        $diseno = VenderLayout::where('user_id', $dueno->id)->where('en_uso', true)
            ->orderByDesc('updated_at')->orderByDesc('id')->first(['layout']);
        $layout = optional($diseno)->layout;
        if (!is_array($layout['etapas'] ?? null) || !in_array($campo, (array) ($layout['sacados'] ?? []), true)) {
            return true;
        }

        // Como en el resolver de Vender, una ubicación explícita gana sobre la bandeja de campos sacados.
        foreach (VenderLayoutHelper::ETAPAS as $etapa) {
            if (in_array($campo, array_column((array) ($layout['etapas'][$etapa] ?? []), 'key'), true)) {
                return true;
            }
        }

        return false;
    }

    public function catalogo_dinamico(User $dueno, User $persona): array
    {
        $pasos = [
            'indicacion' => 'Mostrar una instrucción textual en la pantalla actual, sin resaltar un control. La tarjeta queda acoplada a un costado y la persona sigue usando el sistema. No navega: usala después de un paso que ya dejó a la persona en la pantalla o la solapa de la que habla.',
            'tutoriales.abrir' => 'Señalar el enlace Tutoriales del Centro de Recursos.',
            'vista.alertas' => 'Abrir Alertas y mostrar una indicación textual dentro de esa pantalla.',
        ];
        $modulos = [
            'vista.listado' => ['article.index', 'Abrir Listado de artículos y mostrar una indicación textual.'],
            'vista.ventas' => ['sale.index', 'Abrir Ventas y mostrar una indicación textual.'],
            'vista.reportes' => ['reportes.index', 'Abrir Reportes y mostrar una indicación textual.'],
            'vista.tesoreria' => ['caja.index', 'Abrir Tesorería y mostrar una indicación textual.'],
            'vista.gastos' => ['expense.index', 'Abrir Gastos y mostrar una indicación textual.'],
            'vista.cheques' => ['reportes.cheques', 'Abrir Cheques y mostrar una indicación textual.'],
            'vista.agenda' => ['pending.index', 'Abrir Agenda y mostrar una indicación textual.'],
            'vista.comprobantes' => ['comprobantes.index', 'Abrir Comprobantes y mostrar una indicación textual.'],
            'vista.abm' => ['abm', 'Abrir el ABM y mostrar una indicación textual.'],
        ];
        if (PermisosIaHelper::tiene_extencion($dueno, 'comerciocity_interno')) {
            $modulos['vista.clientes'] = ['client.index', 'Abrir Clientes y mostrar una indicación textual.'];
            $modulos['vista.proveedores'] = ['provider.index', 'Abrir Proveedores y mostrar una indicación textual.'];
            if (PermisosIaHelper::tiene_extencion($dueno, 'budgets')) {
                $modulos['vista.presupuestos'] = ['budget.index', 'Abrir Presupuestos y mostrar una indicación textual.'];
            }
        }
        foreach ($modulos as $clave => [$permiso, $descripcion]) {
            if (PermisosIaHelper::puede($persona, $permiso)) {
                $pasos[$clave] = $descripcion;
            }
        }
        if (isset($pasos['vista.abm'])) {
            $pasos['abm.editar_recargo'] = 'Abrir ABM → Precios → Recargos y señalar la lista para que la persona elija qué recargo de venta editar.';
            $pasos['abm.porcentaje_recargo'] = 'Señalar Porcentaje en la edición del recargo predefinido. Usar después de abm.editar_recargo. La persona edita y guarda.';
            $pasos['abm.editar_descuento'] = 'Abrir ABM → Precios → Descuentos y señalar la lista para que la persona elija qué descuento de venta editar.';
            $pasos['abm.porcentaje_descuento'] = 'Señalar Porcentaje en la edición del descuento predefinido. Usar después de abm.editar_descuento. La persona edita y guarda.';
            $pasos += $this->solapas_del_abm($dueno);
        }
        if (isset($pasos['vista.tesoreria'])) {
            $pasos['caja.historial_aperturas'] = 'Señalar Aperturas en Tesorería. La persona elige la caja cuyo historial quiere abrir.';
            $pasos['caja.reabrir_apertura'] = 'Señalar Reabrir en el historial que abrió la persona. Solo aparece para la última apertura de una caja cerrada. Usar después de caja.historial_aperturas.';
        }
        if (isset($pasos['vista.listado'])) {
            $pasos['listado.filtro'] = 'Señalar el botón de filtros del Listado.';
            if (PermisosIaHelper::puede($persona, 'article.store')) {
                $pasos['listado.nuevo_articulo'] = 'Señalar la parte principal de Crear para abrir una ficha nueva de artículo.';
            }
        }
        if (isset($pasos['vista.ventas'])) {
            $pasos['ventas.filtro_fecha'] = 'Señalar el control de fechas e histórico en Ventas.';
        }
        if (isset($pasos['vista.proveedores']) && PermisosIaHelper::puede($persona, 'provider_order.index')) {
            $pasos['vista.compras'] = 'Abrir la solapa Compras de Proveedores y mostrar una indicación textual.';
            $pasos['compras.filtro_fecha'] = 'Señalar el control de fechas e histórico de Compras.';
            if (PermisosIaHelper::puede($persona, 'provider_order.store')) {
                $pasos['compras.nueva'] = 'Señalar el botón para abrir el formulario de una compra nueva.';
            }
        }
        if (self::puede_abrir_menu_de_listado($persona)) {
            $pasos['listado.crear'] = 'Señalar la flechita de Crear en Listado de artículos para desplegar el menú.';
            $pasos['listado.historial_importaciones'] = 'Señalar Historial de importaciones en el submenú Importación del Listado.';
            $pasos['listado.revertir_importacion'] = 'Señalar el botón Revertir o Reintentar de un historial de artículos validado por su ID. El cliente revisa la confirmación habitual.';
            if (PermisosIaHelper::puede($persona, 'article.excel.export')) {
                $pasos['listado.submenu_exportacion'] = 'Señalar Exportación dentro del menú Crear del Listado de artículos.';
                $pasos['listado.opcion_nueva_exportacion'] = 'Señalar Nueva exportación en Exportación del Listado. La persona elige si iniciar el Excel.';
                $pasos['listado.opcion_historial_exportaciones'] = 'Señalar Historial de exportaciones en Exportación del Listado para consultar o descargar Excel ya exportados.';
            }
        }
        if ($this->puede_vender($dueno, $persona)) {
            $pasos['vista.vender'] = 'Abrir Vender y mostrar una indicación textual, conservando la venta en curso.';
            $pasos['vender.buscar_articulo'] = 'Señalar el botón del buscador de artículos por nombre en Vender.';
            $pasos['vender.campo_busqueda'] = 'Señalar el campo de búsqueda por nombre después de que la persona abra el buscador.';
            $pasos['vender.resultados_busqueda'] = 'Señalar los resultados después de buscar por nombre.';
            if (!PermisosIaHelper::tiene_extencion($dueno, 'no_usar_codigos_de_barra')) {
                $pasos['vender.codigo_barras'] = 'Señalar el campo para escanear o escribir un código de barras en Vender.';
            }
            $pasos['vender.total'] = 'Señalar el total de la venta para que la persona lo revise.';
            if ($this->campo_vender_disponible($dueno, 'metodo_de_pago')) {
                $pasos['vender.metodos_pago'] = 'Señalar el botón + junto a Mét. pago en Vender para abrir Múltiples métodos de pago. En una venta con artículos permite repartir el total.';
                $pasos['vender.reparto_pago'] = 'Señalar el reparto de importes del modal Múltiples métodos de pago, después de que la persona lo abra. Usar después de vender.metodos_pago.';
            }
            if ($this->puede_guiar_recargos($dueno, $persona)) {
                $pasos['vender.recargos'] = 'Señalar los interruptores de recargos de venta existentes en el diseño activo.';
            }
        }
        if (self::tiene_chat_soporte($persona)) {
            $pasos['soporte.abrir'] = 'Señalar cómo abrir Soporte, o el encabezado si ya está abierto.';
            $pasos['soporte.conversacion'] = 'Señalar la conversación o el historial de Soporte después de abrirlo.';
            $pasos['soporte.escribir'] = 'Señalar el campo para escribir una consulta en Soporte.';
        }

        $catalogo = [];
        foreach ($pasos as $clave => $descripcion) {
            $catalogo[$clave] = ['clave' => $clave, 'descripcion' => $descripcion];
        }
        if (isset($catalogo['listado.revertir_importacion'])) {
            $catalogo['listado.revertir_importacion']['requiere_destino'] = true;
        }

        return $catalogo;
    }

    /**
     * Grupos y solapas del ABM que el recorrido puede abrir y señalar (12/11/2026).
     *
     * Salen de resources/soporte/catalogo-soporte.json, que es una COPIA del catálogo que genera
     * empresa-spa (`node scripts/generar-catalogo-soporte.cjs --api ../empresa-api`) a partir de las
     * solapas reales del ABM. La clave es la misma que el SPA pone como `data-tour` en cada solapa.
     *
     * @return array<string, string> clave => descripción para el planificador.
     */
    private function solapas_del_abm(User $dueno): array
    {
        $pasos = [];
        foreach (self::catalogo_de_soporte()['abm'] ?? [] as $entrada) {
            foreach ($entrada['extenciones'] as $extencion) {
                if (!PermisosIaHelper::tiene_extencion($dueno, $extencion)) {
                    continue 2;
                }
            }
            $pasos[$entrada['clave']] = $entrada['tipo'] === 'grupo'
                ? 'Abrir el ABM y señalar el grupo «' . $entrada['texto'] . '» para que la persona lo toque.'
                : 'Abrir ABM → ' . $entrada['grupo'] . ' y señalar la solapa «' . $entrada['texto'] . '» para que la persona la toque. Las indicaciones que sigan se muestran en esa solapa.';
        }

        return $pasos;
    }

    public static function catalogo_de_soporte(): array
    {
        static $catalogo = null;
        if ($catalogo === null) {
            $ruta = resource_path('soporte/catalogo-soporte.json');
            $datos = is_file($ruta) ? json_decode((string) file_get_contents($ruta), true) : null;
            $catalogo = is_array($datos) ? $datos : [];
        }

        return $catalogo;
    }

    private function normalizar_pasos(array $pasos): array
    {
        return array_map(static function (array $paso) {
            $datos = ['clave' => $paso['clave'], 'texto' => $paso['texto']];
            if (isset($paso['destino'])) {
                $datos['destino'] = (int) $paso['destino'];
            }

            return $datos;
        }, $pasos);
    }

    private function validar_pasos_programados(User $dueno, User $persona, array $pasos): void
    {
        $catalogo = $this->catalogo_dinamico($dueno, $persona);
        foreach ($pasos as $i => $paso) {
            $definicion = $catalogo[$paso['clave']] ?? null;
            $requiere_destino = $definicion['requiere_destino'] ?? false;
            if ($definicion === null || $requiere_destino !== isset($paso['destino'])) {
                throw ValidationException::withMessages(['pasos.' . $i => 'La acción o su destino no están disponibles para esta cuenta.']);
            }
            if ($requiere_destino && !$this->importacion_reversible($dueno, $paso['destino'])) {
                throw ValidationException::withMessages(['pasos.' . $i . '.destino' => 'Esa importación ya no está disponible para revertir.']);
            }
        }
    }

    public function validar_reproduccion(User $persona, SupportTour $tour): void
    {
        $persona = $persona->fresh();
        $dueno = $persona && $persona->owner_id ? $persona->owner : $persona;
        $pasos = $tour->pasos_programados;
        abort_unless($dueno && (int) $dueno->id === (int) $tour->owner_id && $pasos,
            409, 'Esta guía ya no está disponible para tu cuenta.');
        $this->validar_pasos_programados($dueno, $persona, $pasos);
    }

    private function importacion_reversible(User $dueno, int $id): bool
    {
        $historial = ImportHistory::where('user_id', $dueno->id)->where('model_name', 'article')->find($id);

        return $historial && $historial->puede_revertirse();
    }

    public function importaciones_candidatas(User $persona, ?int $import_history_id): array
    {
        abort_unless(self::puede_abrir_menu_de_listado($persona), 409, 'El historial de Listado no está disponible para esa persona.');
        $dueno_id = $persona->owner_id ?: $persona->id;
        $consulta = ImportHistory::where('user_id', $dueno_id)->where('model_name', 'article');
        if ($import_history_id !== null) {
            $consulta->whereKey($import_history_id);
        }

        $historiales = $consulta->orderByDesc('id')->limit(30)
            ->get(['id', 'created_at', 'provider_id', 'status', 'rollback_status', 'rolled_back_at'])
            ->filter->puede_revertirse()->take(10);
        $proveedores = Provider::where('user_id', $dueno_id)->whereIn('id', $historiales->pluck('provider_id')->filter())
            ->pluck('name', 'id');

        return $historiales->map(function (ImportHistory $historial) use ($proveedores) {
            return [
                'id' => (int) $historial->id,
                'created_at' => optional($historial->created_at)->toDateTimeString(),
                'provider_id' => $historial->provider_id === null ? null : (int) $historial->provider_id,
                'provider_name' => $proveedores->get($historial->provider_id),
            ];
        })->values()->all();
    }

    private function validar_telefono_del_empleado(User $dueno, User $persona, string $remitente): void
    {
        $canonico = strpos($remitente, '54') === 0 ? TelefonoArgentinoHelper::a_e164($remitente) : null;
        $coincide = static function (User $usuario) use ($remitente, $canonico): bool {
            if (WhatsappPhoneHelper::normalize($usuario->phone) === $remitente) {
                return true;
            }

            return $canonico !== null && TelefonoArgentinoHelper::a_e164($usuario->phone) === $canonico;
        };

        abort_unless($persona->owner_id && $coincide($persona) && !$coincide($dueno),
            409, 'El teléfono del remitente no identifica a ese empleado en el sistema.');

        $otro_empleado = $dueno->employees()->where('id', '<>', $persona->id)->whereNotNull('phone')
            ->get(['id', 'phone'])->contains($coincide);
        abort_if($otro_empleado, 409, 'El teléfono del remitente está asociado a más de un empleado.');
    }

    public function preguntar(User $persona, string $uuid, string $pregunta, ?int $paso): bool
    {
        $mensaje = DB::transaction(function () use ($persona, $uuid, $pregunta, $paso) {
            User::whereKey($persona->id)->lockForUpdate()->firstOrFail();
            $tour = self::de_la_persona($persona)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();

            if ($tour->question_message_id !== null) {
                abort_unless($tour->question === $pregunta && $tour->question_message,
                    409, 'La pregunta ya quedó guardada para esta guía.');

                return $tour->question_message;
            }

            abort_unless($tour->state === 'accepted', 409, 'Empezá la guía para poder preguntar.');
            abort_unless(self::tiene_chat_soporte($persona), 409, 'El chat de soporte no está disponible en este sistema.');
            $vistos = $tour->steps_shown;
            abort_if($paso !== null && !in_array($paso, $vistos, true), 409, 'Ese paso todavía no quedó registrado.');
            $paso = $paso ?? ($vistos ? end($vistos) : null);

            // Conserva el hilo original si es web y sigue abierto. Un origen de WhatsApp se continúa en Soporte.
            $ticket = SupportTicket::where('uuid', $tour->ticket_uuid)->where('user_id', $persona->id)
                ->where(function ($hilos) {
                    $hilos->where('origen', SupportTicket::ORIGEN_SISTEMA)->orWhereNotNull('sistema_exclusivo_at');
                })
                ->where('status', 'open')->first() ?? SupportTicketHelper::get_or_create_open_ticket((int) $persona->id);

            $contexto = 'Pregunta para Robotito sobre «' . $tour->titulo . '»';
            if ($paso !== null) {
                $contexto .= ' en el paso ' . $paso;
            }

            $mensaje = SupportMessage::create([
                'support_ticket_id' => $ticket->id,
                'sender_type' => 'user',
                'sender_user_id' => $persona->id,
                'kind' => 'text',
                'body' => $contexto . ': ' . $pregunta,
                'delivered_at' => now(),
                'remote_delivery_status' => 'not_received',
                'robotito_support_version' => self::VERSION_SOPORTE_WEB,
                'agentes_version' => $ticket->origen === SupportTicket::ORIGEN_WHATSAPP ? SupportAgentesSync::VERSION : null,
            ]);

            $tour->forceFill([
                'question' => $pregunta,
                'question_step' => $paso,
                'question_at' => now(),
                'question_message_id' => $mensaje->id,
                'question_ticket_uuid' => $ticket->uuid,
            ])->save();

            return $mensaje;
        });

        // El reintento web y el del cron usan el UUID del mismo mensaje persistido.
        return $mensaje->synced_to_admin_at !== null || SupportSyncHelper::sync_message_to_admin($mensaje);
    }
}
