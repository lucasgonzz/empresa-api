<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class UserPaymentExpiredAtController extends Controller
{
    public function edit($user_id = null)
    {
        if (is_null($user_id)) {
            $user_id = config('app.USER_ID');
        }

        $user = User::findOrFail($user_id);

        return view('user.payment_expired_at.edit', compact('user'));
    }

    public function update(Request $request, $user_id)
    {
        $user = User::findOrFail($user_id);

        $request->validate([
            'payment_expired_at' => ['required', 'date'],
            'precio_por_cuenta' => ['required', 'numeric', 'min:0'],
        ]);

        $user->payment_expired_at = $request->payment_expired_at;
        $user->precio_por_cuenta = $request->precio_por_cuenta;
        $user->save();

        return redirect()
            ->route('users.payment_expired_at.edit', $user->id)
            ->with('success', 'Fecha de pago y total a pagar actualizados correctamente.');
    }
}
