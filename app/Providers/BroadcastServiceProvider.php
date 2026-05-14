<?php

namespace App\Providers;

use GuzzleHttp\Client;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Pusher\Pusher;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        /**
         * Registra un broadcaster Pusher con cliente Guzzle explícito.
         *
         * Motivo: pusher-php-server 7.x ignora curl_options; sin CA bundle en PHP (típico en WAMP/Windows)
         * aparece "cURL error 60: SSL certificate problem". Ver config/broadcasting.php (guzzle_verify / guzzle_ca_bundle).
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

            return new PusherBroadcaster($pusher);
        });

        Broadcast::routes();

        require base_path('routes/channels.php');
    }
}
