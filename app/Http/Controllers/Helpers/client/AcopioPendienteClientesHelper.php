<?php

namespace App\Http\Controllers\Helpers\client;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Collection;

class AcopioPendienteClientesHelper
{
    public static function agregar_a_pagina(Collection $clientes): void
    {
        if ($clientes->isEmpty()) {
            return;
        }

        $dueno = UserHelper::user();
        if (!$dueno || !UserHelper::hasExtencion('acopios', $dueno)) {
            return;
        }

        $usuario = UserHelper::user(false);
        if (!$usuario || ($usuario->owner_id && !$usuario->admin_access
            && !$usuario->permissions->contains('slug', 'client.index'))) {
            return;
        }

        // La marca distingue un acopio de una venta común. En un acopio, un renglón sin
        // cantidad entregada cuenta como cero, igual que al registrar el retiro.
        $pendientes = Sale::where('sales.user_id', $dueno->id)
            ->whereIn('sales.client_id', $clientes->modelKeys())
            ->where('sales.en_acopio', true)
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('article_sale')
                    ->whereColumn('article_sale.sale_id', 'sales.id')
                    ->whereRaw('article_sale.amount > COALESCE(article_sale.delivered_amount, 0)');
            })
            ->distinct()
            ->pluck('sales.client_id')
            ->flip();

        foreach ($clientes as $cliente) {
            $cliente->tiene_acopio_pendiente = $pendientes->has($cliente->id);
        }
    }
}
