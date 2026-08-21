<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;

class GlobalNotification extends Notification
{
    use Queueable;

    
    public $message_text;
    public $color_variant;
    public $functions_to_execute;
    public $info_to_show;
    public $owner_id;
    public $is_only_for_auth_user;

    /**
     * Modal del SPA a abrir: global_notification (default) o article_import_result.
     *
     * @var string
     */
    public $notification_modal;

    /**
     * Estadísticas estructuradas para el modal de resultado de importación (opcional).
     *
     * @var array|null
     */
    public $import_stats;

    /**
     * Configuración utilizada en la importación (rango de filas, operación, opciones avanzadas).
     *
     * @var array|null
     */
    public $import_options;

    /**
     * Resultado del recálculo de precios para el modal price_update_result (opcional).
     * Mismo tratamiento que import_stats.
     *
     * @var array|null
     */
    public $price_stats;

    /**
     * Datos de la corrida de análisis de Excel terminada, para el modal
     * excel_analysis_ready (opcional). Mismo tratamiento que import_stats.
     *
     * A propósito NO lleva el resultado del análisis: el aviso solo dice que
     * terminó y de qué archivo. El resumen se pide después, y solo si el usuario
     * decide ir a verlo.
     *
     * @var array|null
     */
    public $excel_analysis;

    /**
     * Datos del escaneo de una factura de compra que terminó, para el modal
     * provider_order_scan_ready (opcional). Mismo tratamiento que excel_analysis.
     *
     * A propósito NO lleva el `resultado` del escaneo: el aviso solo dice que terminó, de
     * qué compra y cuántos artículos salieron. El detalle se pide después, y solo si el
     * usuario decide ir a revisarlo.
     *
     * @var array|null
     */
    public $provider_order_scan;

    public function __construct($data)
    {
        $this->message_text             = $data['message_text'];
        $this->color_variant            = $data['color_variant'];
        $this->functions_to_execute     = $data['functions_to_execute'];
        $this->info_to_show             = $data['info_to_show'];
        $this->owner_id                 = $data['owner_id'];
        $this->is_only_for_auth_user    = $data['is_only_for_auth_user'];
        $this->notification_modal       = $data['notification_modal'] ?? 'global_notification';
        $this->import_stats             = $data['import_stats'] ?? null;
        $this->import_options           = $data['import_options'] ?? null;
        $this->price_stats              = $data['price_stats'] ?? null;
        $this->excel_analysis           = $data['excel_analysis'] ?? null;
        $this->provider_order_scan      = $data['provider_order_scan'] ?? null;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['broadcast'];
    }

    public function broadcastOn() {
        return 'global_notification.'.$this->owner_id;
    }


    public function toBroadcast($notifiable) {
        return new BroadcastMessage([
            'message_text'              => $this->message_text,
            'color_variant'             => $this->color_variant,
            'functions_to_execute'      => $this->functions_to_execute,
            'info_to_show'              => $this->info_to_show,
            'owner_id'                  => $this->owner_id,
            'is_only_for_auth_user'     => $this->is_only_for_auth_user,
            'notification_modal'        => $this->notification_modal,
            'import_stats'              => $this->import_stats,
            'import_options'            => $this->import_options,
            'price_stats'               => $this->price_stats,
            'excel_analysis'            => $this->excel_analysis,
            'provider_order_scan'       => $this->provider_order_scan,
        ]);
    }
}
