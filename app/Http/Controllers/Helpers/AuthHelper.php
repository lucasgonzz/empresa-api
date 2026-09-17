<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AuthHelper {
	
	function setEmployeeProps($user) {
		$owner = UserHelper::getFullModel();
		$user->owner_extencions = $owner->extencions;
		$user->owner_configuration = $owner->configuration;
		$user->iva_included = $owner->iva_included;
		$user->ask_amount_in_vender = $owner->ask_amount_in_vender;
		$user->owner = $owner;
		return $user;
	}

	function checkUserLastActivity() {
		Log::info('checkUserLastActivity');
		$user = Auth()->user();

		/**
		 * APRENDER NO PARCHEAR (candado-sesion-refresh, 9/9/2026): antes, este método generaba
		 * un valor al azar (`time().rand(0,1000)`) y lo escribía por separado en DOS lugares -la
		 * sesión Laravel en memoria, y la fila de `users`-. El ERP se usa habitualmente con varias
		 * pestañas del mismo navegador abiertas a la vez, y TODAS comparten la MISMA cookie de
		 * sesión Laravel (driver `file`, sin lock entre procesos). Cuando el candado estaba
		 * abierto (vencido o recién logueado), dos requests que llegaban casi juntos -dos pestañas
		 * arrancando a la vez, o el `auth/me` normal coincidiendo con
		 * `refresh_user_from_api_silent()` disparado por un broadcast, ver mixins/broadcast.js-
		 * generaban CADA UNO su propio valor al azar. Sin coordinación entre procesos, la fila de
		 * `users` terminaba con el valor de quien ganó el último `save()` a la base, y el archivo
		 * de sesión (compartido por todas las pestañas) con el valor de quien ganó el último
		 * guardado de sesión -no necesariamente el mismo request-. Resultado: el propio navegador,
		 * sin que existiera ningún otro dispositivo, dejaba de matchear contra su propia fila, y
		 * el siguiente F5 mostraba "tu cuenta está siendo usada en otro dispositivo".
		 *
		 * Un lock (`Cache::lock`) alrededor del check-and-set NO alcanza para arreglar esto: el
		 * archivo de sesión de Laravel recién se persiste al final del ciclo de vida del request
		 * (middleware StartSession::terminate, después de que el controller ya respondió), así
		 * que ningún lock tomado adentro de este método puede evitar que un segundo request, que
		 * ya había cargado su copia de la sesión en memoria ANTES de que el primero la persista,
		 * seguiría viéndola vieja. Habría que agregar además una "adopción" manual del valor
		 * ganador -releer, detectar que se perdió la carrera, y copiar el valor ajeno a la sesión
		 * propia- para lograr lo mismo que se consigue acá de forma directa.
		 *
		 * El arreglo real no es coordinar dos escrituras concurrentes: es dejar de generar un
		 * valor que haya que coordinar. `session()->getId()` -el ID nativo de la sesión Laravel,
		 * ya resuelto por el middleware StartSession antes de llegar acá- YA es el mismo para
		 * todas las pestañas del mismo navegador (viene de la MISMA cookie `laravel_session`, no
		 * hay que sincronizarlo a mano) y YA es distinto para un dispositivo genuinamente distinto
		 * (cookie propia, nunca comparte sesión con la de origen -no hay forma de "heredar" el ID
		 * de otro navegador sin compartir su cookie). Dos requests concurrentes del mismo navegador
		 * calculan entonces el MISMO valor sin coordinarse -no hay nada que competir ni que
		 * adoptar-, y el candado deja de poder rechazar a su propio navegador.
		 */
		$session_id_de_este_navegador = session()->getId();

		if (is_null($user->last_activity) || is_null($user->session_id) || $this->ya_paso_el_tiempo($user)) {
			$user->last_activity = Carbon::now();
			$user->session_id = $session_id_de_este_navegador;
			$user->save();
			Log::info('se puso session_id: '.$user->session_id);
			return true;
		} else if ($user->session_id == $session_id_de_este_navegador) {
			$user->last_activity = Carbon::now();
			$user->save();
			Log::info('tiene el mismo session_id: '.$user->session_id);
			return true;
		}
		return false;
	}

	function get_activity_minutes($user) {
		$owner = $user->owner_id
			? User::find($user->owner_id)
			: $user;

		return $owner->activity_minutes ?? env('USER_ACTIVITY_MINUTES', 60);
	}

	function get_remaining_wait_minutes($user) {
		if (!$user) {
			return 0;
		}

		if (is_null($user->last_activity)) {
			return 0;
		}

		$minutes = $this->get_activity_minutes($user);
		$unlock_at = Carbon::parse($user->last_activity)->addMinutes($minutes);
		$remaining_seconds = Carbon::now()->diffInSeconds($unlock_at, false);

		if ($remaining_seconds <= 0) {
			return 0;
		}

		return (int) ceil($remaining_seconds / 60);
	}

	function ya_paso_el_tiempo($user) {
		$minutes = $this->get_activity_minutes($user);
		if (Carbon::now()->subMinutes($minutes)->gte($user->last_activity)) {
			Log::info('Ya paso el tiempo ('.$minutes.' min)');
			return true;
		} else {
			Log::info('No paso el tiempo ('.$minutes.' min)');
		}
		return false;
	}

	/**
	 * Libera el candado de sesión única del usuario para permitir un nuevo login.
	 *
	 * Limpia `session_id` y `last_activity` (mismo criterio que UserController y limpiar_sesiones).
	 * Antes solo retrocedía `last_activity`, lo que podía dejar el lock activo si el navegador
	 * abría una sesión PHP nueva sin el `session_id` anterior.
	 *
	 * @param User $user Usuario autenticado que está cerrando sesión.
	 * @return void
	 */
	function removeUserLastActivity($user) {
		$user->session_id = null;
		$user->last_activity = null;
		$user->save();
		Log::info('se liberó sesión única para user_id: '.$user->id);
	}

}
