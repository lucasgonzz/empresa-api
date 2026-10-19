<?php

namespace App\Providers;

use App\Broadcasting\PusherBroadcasterConEspacio;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Pusher\Pusher;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Reemplaza el canal de broadcast de las notificaciones por el propio.
     *
     * 🔴 Este binding es el unico punto de entrada posible:
     * `ChannelManager::createBroadcastDriver()` (vendor, linea 85) hace
     * `$this->container->make(Channels\BroadcastChannel::class)`, asi que resolver esa clase a
     * la nuestra es lo que hace que TODAS las notificaciones del repo pasen por el canal nuevo.
     *
     * El canal nuevo solo se comporta distinto con las notificaciones que pidieron
     * `connection === 'sync'`; el resto sigue el camino identico al de vendor. El motivo de todo
     * esto esta en el docblock de `InstantBroadcastChannel`.
     *
     * Va acá y no en `AppServiceProvider` porque en este provider ya vive todo lo de
     * broadcasting (el `Broadcast::extend`, las rutas y `channels.php`): partirlo en dos
     * archivos seria esconder la mitad.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(
            \Illuminate\Notifications\Channels\BroadcastChannel::class,
            \App\Notifications\Channels\InstantBroadcastChannel::class
        );
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        /**
         * Registra un broadcaster Pusher con cliente Guzzle explícito y con el espacio de nombres del
         * sistema.
         *
         * Cliente Guzzle explícito — motivo: pusher-php-server 7.x ignora curl_options; sin CA bundle en
         * PHP (típico en WAMP/Windows) aparece "cURL error 60: SSL certificate problem". Ver
         * config/broadcasting.php (guzzle_verify / guzzle_ca_bundle).
         *
         * Espacio de nombres — desde el 19/10/2026 (misión pusher-canales-por-sistema) devuelve
         * `PusherBroadcasterConEspacio` en lugar del `PusherBroadcaster` de vendor. Toda la flota emite a
         * UNA app de Pusher y los canales llevaban solo un id, así que dos sistemas con el mismo id de
         * dueño se escuchaban entre sí (demo3 y Ferretotal, los dos con el 2600). El broadcaster nuevo le
         * pega `@{espacio}` a cada canal antes del `trigger` y saca ese sufijo al autorizar un privado.
         * Es el único punto central del lado de la API: ningún `broadcastOn()` ni ningún patrón de
         * `routes/channels.php` cambia. El porqué del espacio está en `App\Broadcasting\EspacioDeCanales`.
         *
         * @param \Illuminate\Contracts\Foundation\Application $app Contenedor de Laravel.
         * @param array<string, mixed> $config Entrada broadcasting.connections.pusher.
         */
        Broadcast::extend('pusher', function ($app, array $config) {
            /**
             * Opciones nativas de Pusher (cluster, useTLS, etc.).
             *
             * @var array<string, mixed>
             */
            $options = $config['options'] ?? [];

            /**
             * Timeout HTTP compartido con el valor por defecto del SDK si no viene en options.
             */
            $timeout = isset($options['timeout']) ? (int) $options['timeout'] : 30;

            /**
             * Archivo PEM de autoridades certificadoras; si no está vacío tiene prioridad sobre guzzle_verify.
             */
            $ca_bundle = isset($options['guzzle_ca_bundle']) ? (string) $options['guzzle_ca_bundle'] : '';

            /**
             * Parámetro verify de Guzzle: false, true o ruta al bundle CA.
             *
             * @var bool|string
             */
            $verify = $ca_bundle !== '' ? $ca_bundle : (bool) ($options['guzzle_verify'] ?? true);

            /**
             * Cliente HTTP usado por Pusher para POST al API REST (disparo de eventos).
             */
            $guzzle = new Client([
                'timeout' => $timeout,
                'verify' => $verify,
            ]);

            /**
             * Instancia Pusher con cliente inyectado (misma firma que usa Laravel en BroadcastManager).
             */
            $pusher = new Pusher(
                $config['key'],
                $config['secret'],
                $config['app_id'],
                $options,
                $guzzle
            );

            if ($config['log'] ?? false) {
                $pusher->setLogger($app->make(LoggerInterface::class));
            }

            /**
             * El broadcaster con espacio (ver el docblock de arriba). Hereda todo lo demás del de vendor:
             * el `broadcast()`, la firma de la autorización y el manejo de errores de Pusher.
             */
            return new PusherBroadcasterConEspacio($pusher);
        });

        Broadcast::routes();

        require base_path('routes/channels.php');
    }
}
