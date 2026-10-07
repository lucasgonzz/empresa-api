<?php

namespace App\Providers;

use App\Listeners\AnotarMailRechazadoPorElServidor;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],

        // Un mail que el servidor SMTP rechazó queda anotado en el log (solo anota, nunca tira): red de seguridad para lo que ningún
        // punto de envío puede mirar —los mails encolados y las notificaciones por el canal mail—. Ver el docblock del listener.
        MessageSent::class => [
            AnotarMailRechazadoPorElServidor::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
