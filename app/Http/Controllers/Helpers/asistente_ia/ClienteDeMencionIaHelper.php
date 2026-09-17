<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Client;
use App\Services\Mostrador\RecolectorBase;

/**
 * El cliente que necesita el modal de cuenta corriente cuando se lo abre desde una mención del chat
 * (misión agente-ia-mano-derecha, §3 del contrato, 16/9/2026).
 *
 * 🔴 POR QUÉ EXISTE ESTE CAMINO Y NO SE USA `GET api/client/{id}`. Medido el 16/9/2026 sobre el
 * código, no sobre la expectativa: `ClientController::show()` resuelve con
 * `Controller::fullModel('Client', $id)`, que es `Client::where('id', $id)->withAll()->first()` —
 * **por id pelado, sin `user_id`**, y `Client` no tiene ningún scope global que lo compense. En una
 * base compartida (u767360347_empresa tiene 51 comercios adentro) eso devuelve el cliente de otro
 * comercio con su deuda. Es la misma clase de agujero que se acaba de encontrar en
 * `ComboController::show()`. Además `scopeWithAll()` NO trae `current_acounts_count` —el
 * `withCount` está comentado (Client.php:18)— y devuelve TODAS las `credit_accounts`, también la de
 * dólares de un comercio que no tiene la extensión.
 *
 * Los tres se arreglan acá, en una superficie chica que solo sirve para esto. El endpoint viejo
 * sigue como está: arreglarlo es una misión propia (lo usan el listado de clientes, el header del
 * sidebar de WhatsApp y todos los ABM) y no se cuela adentro de ésta.
 */
class ClienteDeMencionIaHelper
{
    /** Extensión que habilita la cuenta corriente en dólares (BtnCurrentAcounts.vue:93-95). */
    const EXTENCION_DOLARES = 'ventas_en_dolares';

    /**
     * El cliente con sus cuentas, o null si no existe o no es del dueño (el controller contesta
     * 404).
     *
     * @param  int  $client_id
     * @param  int  $owner_id  Dueño de la cuenta (clients.user_id).
     * @param  \App\Models\User|null  $owner  El dueño, para mirar la extensión: el módulo lo compra
     *                                        el COMERCIO, no la persona que charla.
     * @return array<string, mixed>|null
     */
    public static function cliente($client_id, $owner_id, $owner)
    {
        $client = Client::where('user_id', (int) $owner_id)
            ->where('id', (int) $client_id)
            ->withCount('current_acounts')
            ->with('credit_accounts')
            ->first();

        if (is_null($client)) {

            return null;
        }

        return [
            'id'   => (int) $client->id,
            'name' => (string) $client->name,
            /*
             * Lo lee Nav.vue:72 (`== 0`) para ofrecer el botón "Saldo inicial". Va porque el modal
             * abierto desde el chat tiene que verse igual que el abierto desde Clientes: sin la
             * clave el botón no aparece nunca.
             */
            'current_acounts_count' => (int) $client->current_acounts_count,
            'credit_accounts'       => self::cuentas($client, $owner),
        ];
    }

    /**
     * Las cuentas que corresponde ofrecer, la de pesos primero.
     *
     * ⚠️ **`moneda_id = 0` ES PESOS, igual que el 1** (RecolectorBase::MONEDAS_PESOS, commit
     * 8ddbac31), y `null` también. En producción hay `credit_accounts` con 0 por altas donde no se
     * eligió moneda: filtrar por `== 1` deja a esos clientes sin cuenta corriente y el chat les
     * dice que no tienen. Es un defecto real que ya costó caro el 15/9/2026.
     *
     * 🔴 La de dólares solo viaja con la extensión `ventas_en_dolares`. Sin ella, el comercio no
     * tiene esa cuenta en ninguna pantalla y el chat no puede ser la excepción.
     *
     * @param  Client  $client
     * @param  \App\Models\User|null  $owner
     * @return array<int, array<string, mixed>>
     */
    protected static function cuentas($client, $owner): array
    {
        $con_dolares = !is_null($owner) && UserHelper::hasExtencion(self::EXTENCION_DOLARES, $owner);

        $pesos = [];

        $otras = [];

        foreach ($client->credit_accounts as $cuenta) {
            $fila = [
                'id'              => (int) $cuenta->id,
                'moneda_id'       => is_null($cuenta->moneda_id) ? null : (int) $cuenta->moneda_id,
                'saldo'           => is_null($cuenta->saldo) ? 0.0 : (float) $cuenta->saldo,
                // null es un valor válido: es lo que hay cuando el cliente no tiene límite, y la
                // SPA lo distingue de "no vino" (SaldoYLimite.vue:12,29-34).
                'limite_credito'  => is_null($cuenta->limite_credito) ? null : (float) $cuenta->limite_credito,
            ];

            if (self::es_pesos($cuenta)) {
                $pesos[] = $fila;
                continue;
            }

            if (!$con_dolares) {
                continue;
            }

            $otras[] = $fila;
        }

        return array_merge($pesos, $otras);
    }

    /**
     * true si la cuenta es en pesos.
     *
     * @param  \App\Models\CreditAccount  $cuenta
     * @return bool
     */
    protected static function es_pesos($cuenta): bool
    {
        if (is_null($cuenta->moneda_id)) {

            return true;
        }

        return in_array((int) $cuenta->moneda_id, RecolectorBase::MONEDAS_PESOS, true);
    }
}
