<?php

/*
|--------------------------------------------------------------------------
| Auditoría de cambios (misión auditoria-de-cambios, 30/9/2026)
|--------------------------------------------------------------------------
|
| Cada vez que el sistema crea, modifica, borra o restaura una fila por Eloquent, el listener
| global de `App\Services\AuditLog\AuditLogRecorder` deja UNA fila en la tabla `audit_logs`: qué
| modelo, qué campos cambiaron (valor viejo y nuevo), quién lo hizo, desde dónde y en qué
| "lote" (todo lo que salió de un mismo request o de un mismo job).
|
| El criterio es "se audita TODO salvo lo que se excluye a propósito": así un modelo nuevo queda
| auditado sin que nadie se acuerde de registrarlo. Lo que se excluye, se excluye acá y con su
| motivo escrito.
|
| Este archivo se lee siempre con `config('audit_log.…')` y nunca con `env()` desde otro lado:
| con `config:cache` (producción) un `env()` fuera de `config/` devuelve siempre el default, sin
| avisar.
|
| IMPORTANTE (PHP 7.4): nada de match, str_contains, nullsafe ni argumentos nombrados.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Interruptor general
    |--------------------------------------------------------------------------
    |
    | Con `AUDIT_LOG_HABILITADO=false` en el .env el listener no escribe nada. Es la salida de
    | emergencia si en un cliente la auditoría llegara a molestar; no es una opción de uso normal.
    */
    'habilitado' => env('AUDIT_LOG_HABILITADO', true),

    /*
    |--------------------------------------------------------------------------
    | Tabla donde se escribe
    |--------------------------------------------------------------------------
    |
    | Configurable SOLO para poder probar "si el registro falla, el negocio sigue": un test apunta
    | esto a una tabla que no existe y comprueba que la operación de negocio termina bien.
    */
    'tabla' => 'audit_logs',

    /*
    |--------------------------------------------------------------------------
    | Modelos que NO se auditan (clase => motivo)
    |--------------------------------------------------------------------------
    |
    | Criterio: se excluye lo que NO es información del negocio, o lo que tiene un volumen tal que
    | el registro pesaría más que el dato. Un nombre de clase mal escrito acá es una exclusión que
    | no excluye nada y nadie se entera: por eso hay un test
    | (tests/Feature/AuditoriaDeCambios/ExclusionesBienEscritasTest) que comprueba que cada
    | clase existe, que tiene motivo, y que ninguna de las que NUNCA se pueden excluir (Article,
    | Sale, Client, CurrentAcount, StockMovement…) está en esta lista.
    */
    'modelos_excluidos' => [

        /* ---- Infraestructura / estado de proceso ---- */
        \App\Models\AuditLog::class                     => 'Recursión: la auditoría no se audita a sí misma.',
        \App\Models\ImportStatus::class                 => 'Estado de avance de una importación: se actualiza en cada lote. La operación ya queda en ImportHistory.',
        \App\Models\EmbeddingRun::class                 => 'Estado de una corrida de embeddings (infraestructura de búsqueda).',
        \App\Models\PrintJob::class                     => 'Cola de impresión: cada ticket es una fila efímera, no información del negocio.',
        \App\Models\SupportTypingState::class           => 'Estado "está escribiendo" del chat de soporte: se reescribe con cada tecla.',
        \App\Models\OauthState::class                   => 'Estado transitorio de un login OAuth (vive minutos).',
        \App\Models\ZippinOauthState::class             => 'Estado transitorio de un login OAuth con Zippin (vive minutos).',
        \App\Models\SyncedVersionNotificationRead::class => 'Marca de "novedad leída" por usuario: ruido de navegación.',
        \App\Models\VersionSessionTransfer::class       => 'Token de transferencia de sesión entre versiones del SPA: efímero y sensible, sin valor de auditoría.',
        \App\Models\DemoIngresoToken::class             => 'Token de ingreso a una demo: efímero y sensible.',
        \App\Models\WhatsappChatMessage::class          => 'Se excluye por volumen: es una fila por cada mensaje de cada chat. El contenido no se pierde, vive en el propio chat y en su tabla; WhatsappChat sí se audita.',
        \App\Models\ImageAssignmentRun::class           => 'Estado de una corrida de asignación de imágenes: se actualiza sin parar mientras corre. La operación ya queda en su propia fila de corrida.',
        \App\Models\ImageAssignmentItem::class          => 'Un renglón por artículo dentro de una corrida de asignación de imágenes: estado de proceso, el volumen pesaría más que el dato.',

        /* ---- Telemetría / tracking de alto volumen ---- */
        \App\Models\BuyerTrackingEvent::class           => 'Telemetría de la tienda: una fila por evento de cada visitante.',
        \App\Models\BuyerTrackingDaily::class           => 'Agregado diario de telemetría de la tienda.',
        \App\Models\DemoEvento::class                   => 'Eventos de la demo: telemetría de leads.',
        \App\Models\LastSearch::class                   => 'Historial de búsquedas: una fila por búsqueda.',
        \App\Models\FilterHistory::class                => 'Historial de filtros usados: una fila por uso.',
        \App\Models\ImageServiceCall::class             => 'Registro de llamadas a servicios de imágenes: una fila por llamada.',
        \App\Models\AiTokenUsage::class                 => 'Consumo de tokens de IA: una fila por llamada al modelo.',
        \App\Models\ArticleImageSearchAttempt::class    => 'Intentos de búsqueda de imágenes por artículo: una fila por intento automático.',
        \App\Models\GeocoderCounter::class              => 'Contador de llamadas al geocodificador.',
        \App\Models\Impression::class                   => 'Registra qué comprobante de una venta se imprimió: ruido de bajo valor, una fila por cada impresión.',
        \App\Models\TableColumnPreference::class        => 'Preferencia de columnas de una tabla: se reescribe al mover una columna.',
        \App\Models\VenderKeyboardShortcut::class       => 'Atajos de teclado de la pantalla de vender: preferencia personal.',

        /* ---- Derivados regenerables (el dato de verdad está en otro lado) ---- */
        \App\Models\ArticlePerformance::class           => 'Métrica derivada por artículo: se recalcula sola.',
        \App\Models\InventoryPerformance::class         => 'Métrica derivada de inventario: se recalcula sola.',
        \App\Models\CompanyPerformance::class           => 'Métrica derivada de la empresa: se recalcula sola.',
        \App\Models\CompanyPerformanceInfoFacturacion::class => 'Métrica derivada de facturación: se recalcula sola.',
        \App\Models\CreditAccountSnapshot::class        => 'Foto diaria de cuentas corrientes: derivada de CurrentAcount, que sí se audita.',
        \App\Models\DebtSnapshot::class                 => 'Foto de deuda: derivada de CurrentAcount, que sí se audita.',
        \App\Models\MostradorReporte::class             => 'Informe generado por el mostrador IA: derivado de los datos del negocio.',
        \App\Models\MostradorAcceso::class              => 'Acceso (token) al mostrador IA: efímero.',
        \App\Models\DolarCotizacionRegistro::class      => 'Cotización del dólar: la escribe un proceso automático, no un usuario.',

        /* ---- Catálogos globales de terceros (cambian por seeder, no por un usuario) ---- */
        \App\Models\MeliCategory::class                 => 'Catálogo de Mercado Libre.',
        \App\Models\MeliAttribute::class                => 'Catálogo de Mercado Libre.',
        \App\Models\MeliAttributeTag::class             => 'Catálogo de Mercado Libre.',
        \App\Models\MeliAttributeValue::class           => 'Catálogo de Mercado Libre.',
        \App\Models\MeliBuyingMode::class               => 'Catálogo de Mercado Libre.',
        \App\Models\MeliItemCondition::class            => 'Catálogo de Mercado Libre.',
        \App\Models\MeliListingType::class              => 'Catálogo de Mercado Libre.',
        /*
         * `Provincia` NO se excluye: tiene `user_id` y su `Route::resource('provincia')` con
         * store/update/destroy, o sea que la editan usuarios. Solo `Provicia` (el modelo con el
         * typo, sin referencias en el código) queda afuera.
         */
        \App\Models\Provicia::class                     => 'Catálogo geográfico global (el modelo se llama así, con el typo).',
        \App\Models\Localidad::class                    => 'Catálogo geográfico global.',
        \App\Models\PaisExportacion::class              => 'Catálogo de países para exportación.',
        \App\Models\Iva::class                          => 'Catálogo de alícuotas de IVA (global, por seeder).',
        \App\Models\IvaCondition::class                 => 'Catálogo de condiciones frente al IVA (global, por seeder).',
        \App\Models\AfipTipoComprobante::class          => 'Catálogo de tipos de comprobante de ARCA (global, por seeder).',
        \App\Models\Extencion::class                    => 'Catálogo global de extensiones. Ojo: ExtencionEmpresa (lo que cada cliente tiene prendido) SÍ se audita.',
        \App\Models\Feature::class                      => 'Catálogo global de características de planes.',
        \App\Models\Plan::class                         => 'Catálogo global de planes.',
        \App\Models\PlanFeature::class                  => 'Catálogo global de planes.',

        /* ---- Chat IA ---- */
        \App\Models\AiMessage::class                    => 'Mensajes del chat IA: volumen alto y el contenido ya vive en su propia tabla. AiConversation sí se audita.',
        \App\Models\AiMessageAction::class              => 'Acciones de un mensaje del chat IA: mismo motivo que AiMessage.',
        \App\Models\AiMessageImagen::class              => 'Imágenes de un mensaje del chat IA: mismo motivo que AiMessage.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Modelos que NUNCA se pueden excluir
    |--------------------------------------------------------------------------
    |
    | No los lee el listener: los lee el test que cuida `modelos_excluidos`. Es la lista de lo que
    | el negocio necesita poder auditar sí o sí (dinero, stock, datos maestros, permisos). Si
    | alguien excluye uno de acá para "bajar el ruido", el test lo frena.
    */
    'modelos_no_excluibles' => [
        \App\Models\Article::class,
        \App\Models\Category::class,
        \App\Models\SubCategory::class,
        \App\Models\Sale::class,
        \App\Models\Client::class,
        \App\Models\Provider::class,
        \App\Models\ProviderOrder::class,
        \App\Models\Budget::class,
        \App\Models\CurrentAcount::class,
        \App\Models\Caja::class,
        \App\Models\MovimientoCaja::class,
        \App\Models\Cheque::class,
        \App\Models\Expense::class,
        \App\Models\PriceType::class,
        \App\Models\Employee::class,
        \App\Models\User::class,
        \App\Models\Permission::class,
        \App\Models\PermissionEmpresa::class,
        \App\Models\StockMovement::class,
        \App\Models\Payment::class,
        \App\Models\PaymentMethod::class,
        \App\Models\AfipInformation::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Operaciones masivas: jobs que corren en silencio (clase => motivo)
    |--------------------------------------------------------------------------
    |
    | Decisión de Lucas (30/9/2026): una importación de 20.000 artículos deja UNA fila de
    | operación, no 20.000. Mientras uno de estos jobs corre, el listener no audita las filas
    | que toca (salvo `registro_de_operaciones`, más abajo). Se decide por CLASE de job y en el
    | evento de la cola, así que no hay que tocar ningún job.
    |
    | Cada entrada se verificó leyendo el job (30/9/2026): escribe filas de negocio en volumen y
    | tiene una fila de operación propia (ImportHistory, MasiveUpdate, PriceUpdateRun,
    | BackgroundProcess) que sigue quedando registrada.
    |
    | 🔴 NO están, a propósito:
    |  - ProcessDeleteModelsJob: borrar es irreversible y el detalle de QUÉ se borró importa más
    |    que el volumen. Queda protegido por el tope de filas por lote (`max_filas_por_lote`).
    |  - ProcessArchivoDeIntercambio*: se disparan desde rutas web sin sesión, para un cliente
    |    puntual, y no dejan ninguna fila de operación: silenciarlos no dejaría ningún rastro.
    |  - ProcessRecalculateCurrentAcounts y ProcessSetStockResultante: son de mantenimiento
    |    (HelperController) y tampoco tienen fila de operación.
    */
    'jobs_masivos' => [
        \App\Jobs\ProcessArticleChunk::class                     => 'Un lote de una importación de artículos por Excel: una fila de ImportHistory cubre toda la operación.',
        \App\Jobs\FinalizeArticleImport::class                   => 'Cierre de la importación: cierra ImportHistory y recalcula el stock por variantes de todo el catálogo importado.',
        \App\Jobs\RollbackArticleImportHistory::class            => 'Reversión de una importación: revierte cientos de artículos y deja su ImportHistory.',
        \App\Jobs\ProcessProviderOrderArticleImport::class       => 'Importación del Excel de una compra: una fila por artículo del archivo.',
        \App\Jobs\ProcessSetFinalPrices::class                   => 'Productor del recálculo de precios en lote: la corrida queda en PriceUpdateRun.',
        \App\Jobs\ProcessChunkSetFinalPrices::class              => 'Un lote del recálculo de precios: recalcula hasta 1.000 artículos.',
        \App\Jobs\FinalizeSetFinalPrices::class                  => 'Cierre de la corrida de recálculo de precios.',
        \App\Jobs\ProcessMasiveUpdateJob::class                  => 'Actualización masiva: hasta 3.000 registros y una fila de MasiveUpdate con los criterios y los valores previos.',
        \App\Jobs\ProcessMasiveUpdateRevertJob::class            => 'Reversión de una actualización masiva.',
        \App\Jobs\ProcessPropagarDescuentosProveedorJob::class   => 'Propaga los descuentos de un proveedor a decenas de miles de artículos.',
        \App\Jobs\ProcessSincronizarDescuentosProveedorJob::class => 'Sincroniza los descuentos de un proveedor con todos sus artículos.',
    ],

    /*
    |--------------------------------------------------------------------------
    | La "fila por operación" que SÍ se audita aunque el job esté en silencio
    |--------------------------------------------------------------------------
    |
    | Son los registros que representan a la operación masiva entera. Sin esto, silenciar el job
    | dejaría la operación sin ningún rastro.
    */
    'registro_de_operaciones' => [
        \App\Models\BackgroundProcess::class,
        \App\Models\ImportHistory::class,
        \App\Models\MasiveUpdate::class,
        \App\Models\PriceUpdateRun::class,
        \App\Models\ExportHistory::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Modelos de los que solo se audita la CREACIÓN
    |--------------------------------------------------------------------------
    |
    | BackgroundProcess se actualiza cada pocos segundos para mostrar el avance de la barra: ese
    | ruido no es información. Basta con dejar constancia de que la operación existió.
    */
    'solo_creacion' => [
        \App\Models\BackgroundProcess::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Campos que no cuentan como "cambio" en un `updated`
    |--------------------------------------------------------------------------
    |
    | Si lo único que cambió son estos campos, NO se escribe ninguna fila. Son columnas que el
    | sistema toca solo, en cada request o cada pocos minutos:
    |  - created_at / updated_at: el timestamp de Eloquent, no es un cambio de negocio.
    |  - session_id / last_activity: el candado de sesión única de User (AuthHelper), que se
    |    reescribe en cada request autenticado.
    |  - remember_token: lo rota Laravel al hacer login/logout.
    |  - last_used_at: Sanctum lo actualiza en cada request autenticado con token.
    |  - last_seen_at: latido de los agentes de impresión.
    |  - last_message_at / last_inbound_at: cada mensaje de WhatsApp o del chat IA los reescribe
    |    en `whatsapp_chats` y `ai_conversations` (y last_message_at en `buyers`): sin ignorarlos,
    |    cada mensaje dejaba una fila `updated` que no dice nada del negocio.
    */
    'campos_ignorados' => [
        'created_at',
        'updated_at',
        'session_id',
        'last_activity',
        'remember_token',
        'last_used_at',
        'last_seen_at',
        'last_message_at',
        'last_inbound_at',
    ],

    /*
    |--------------------------------------------------------------------------
    | Campos sensibles: se guarda "[oculto]" en vez del valor
    |--------------------------------------------------------------------------
    |
    | Queda constancia de que el campo CAMBIÓ, sin filtrar el valor. Ninguna clave, token ni
    | contraseña puede aparecer en claro en `audit_logs` (hay un test que lo prueba). Además de
    | esta lista se ocultan todos los campos que el modelo declara en `$hidden` y los que
    | terminan en alguno de los `sufijos_sensibles`.
    |
    | La lista sale de las columnas reales del esquema (30/9/2026): hay claves de terceros en
    | `users`, `online_configurations`, `whatsapp_bot_configs`, `platform_connectors`,
    | `payment_methods`, `credentials`, `payments`…
    */
    'campos_sensibles' => [
        'password',
        'prev_password',
        'visible_password',
        'remember_token',
        'api_key',
        'secret',
        'token',
        'token_hash',
        'link_code_hash',
        'clave',
        'clave_eliminar_article',
        'access_token',
        'refresh_token',
        'auth_code',
        'client_secret',
        'articles_export_key',
        'serper_api_key',
        'google_custom_search_api_key',
        'google_client_secret',
        'kapso_api_key',
        'webhook_secret',
        'mail_password',
        'mp_access_token',
        'mp_refresh_token',
        'zippin_access_token',
        'zippin_refresh_token',
        'ai_auto_send_token',
        'ai_schedule_token',
        'eventos_token',
        'verification_code',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sufijos que vuelven sensible a un campo
    |--------------------------------------------------------------------------
    |
    | Red de seguridad para columnas nuevas: `foo_token`, `foo_secret`, `foo_password`,
    | `foo_api_key` se ocultan aunque nadie las haya agregado a la lista de arriba.
    */
    'sufijos_sensibles' => [
        '_token',
        '_secret',
        '_password',
        '_passwd',
        '_api_key',
    ],

    /*
    |--------------------------------------------------------------------------
    | Texto que reemplaza al valor de un campo sensible
    |--------------------------------------------------------------------------
    */
    'valor_oculto' => '[oculto]',

    /*
    |--------------------------------------------------------------------------
    | Topes de tamaño
    |--------------------------------------------------------------------------
    |
    | max_largo_valor:     todo string más largo se corta con "…[+N caracteres]" (descripciones
    |                      largas, imágenes en base64, JSON de diseños de ticket).
    | max_bytes_fila:      si el JSON completo de old_values o new_values pasa de esto, se guarda
    |                      solo la lista de nombres de campo con la marca `_truncado`.
    | max_filas_por_lote:  máximo de filas de auditoría por request o por job. Al pasarlo se
    |                      escribe UNA fila `truncated` y el resto solo se cuenta. Es la red de
    |                      seguridad contra una operación masiva que nadie previó: acota el peor
    |                      caso, no lo oculta. Es 5000 y no 500 porque con 500 una venta grande
    |                      (150+ renglones, cada uno con su artículo, su StockMovement y su
    |                      pivote) agotaba el cupo antes de llegar a lo último que hace: caja, cuenta
    |                      corriente, el cierre de la venta. Además, los modelos de
    |                      `modelos_sin_tope` (más abajo) nunca se omiten.
    */
    'max_largo_valor'    => 1000,
    'max_bytes_fila'     => 60000,
    'max_filas_por_lote' => 5000,

    /*
    |--------------------------------------------------------------------------
    | Modelos que el tope de filas por lote NUNCA omite
    |--------------------------------------------------------------------------
    |
    | Plata y stock. Siguen contando para el marco (`filas`) pero se escriben SIEMPRE aunque el
    | lote ya haya pasado `max_filas_por_lote`. Sin esta excepción, la venta de 150+ renglones
    | perdía JUSTO las filas de plata: renglones y artículos llenaban el cupo, y lo último de la
    | venta (Caja, CurrentAcount, Sale updated...) era lo que quedaba afuera, que es lo que más
    | importa auditar. Es un conjunto acotado (una fila por movimiento de plata o de stock), no
    | el volumen que el tope quiere frenar.
    */
    'modelos_sin_tope' => [
        \App\Models\Sale::class,
        \App\Models\CurrentAcount::class,
        \App\Models\MovimientoCaja::class,
        \App\Models\Payment::class,
        \App\Models\Caja::class,
        \App\Models\AperturaCaja::class,
        \App\Models\Cheque::class,
        \App\Models\Expense::class,
        \App\Models\ProviderOrder::class,
        \App\Models\Budget::class,
        \App\Models\StockMovement::class,
        \App\Models\ArticlePurchase::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cuánto esperar antes de reintentar si la tabla no existe
    |--------------------------------------------------------------------------
    |
    | Un cliente a mitad de un upgrade (código nuevo, migración todavía sin correr) no tiene la
    | tabla. Cada fila intentaría un INSERT que falla; con esto, tras el primer error de "tabla
    | inexistente" el proceso deja de intentar durante estos segundos y vuelve a probar (un
    | worker de cola vive días: si la migración corrió mientras tanto, la auditoría se retoma
    | sola).
    */
    'segundos_de_espera_si_falta_la_tabla' => 60,

];
