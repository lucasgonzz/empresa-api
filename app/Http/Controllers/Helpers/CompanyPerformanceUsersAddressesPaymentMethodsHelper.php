<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Address;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class CompanyPerformanceUsersAddressesPaymentMethodsHelper {

	function __construct($company_performance, $user_id) {

		$this->company_performance = $company_performance;
		$this->user_id = $user_id;

		// Log::info('__construct:');
		// Log::info($company_performance->users_payment_methods);
	}

	function set_users_relation() {

		Log::info('set_users_relation');

		$this->set_users_array();

		foreach ($this->company_performance->users_payment_methods as $user_payment_methods) {
				
			$this->users_payment_methods[$user_payment_methods->pivot->user_id]['payment_methods'][] = $user_payment_methods;
		}

		$this->add_users_relation();
	}

	function add_users_relation() {

		$relation = [];

		foreach ($this->users_payment_methods as $user_payment_methods) {
			
			$relation_to_add = [
				'user'				=> User::find($user_payment_methods['user_id']),
				'payment_methods'	=> $user_payment_methods['payment_methods'],
			];

			$total_ingresado = 0;

			foreach ($user_payment_methods['payment_methods'] as $payment_method) {
				
				$total_ingresado += $payment_method->pivot->amount;
			}

			$relation_to_add['total_ingresado'] = $total_ingresado;

			$relation_to_add['total_vendido'] = $this->get_user_total_vendido($relation_to_add);

			$relation[] = $relation_to_add;
		}

		usort($relation, function ($a, $b) {
		    return $b['total_ingresado'] <=> $a['total_ingresado'];
		});

		$this->company_performance->users_payment_methods_formated = $relation; 
	}

	function get_user_total_vendido($relation_to_add) {

		$total_vendido = 0;

		foreach ($this->company_performance->users_total_vendido as $user) {

			if ($user->id == $relation_to_add['user']['id']) {

				$total_vendido = $user->pivot->total_vendido;
			}
		}

		return $total_vendido;
	}


	/*
		Creo el array agregando cada empleado y el dueño
		Para despues agregarle los metodos de pago con sus totales
		Y el total_vendido de cada usuario
	*/
	function set_users_array() {

		$this->users_payment_methods = [];

		$employees = User::where('owner_id', $this->user_id)
							->get();

		foreach ($employees as $employee) {
			
			$this->users_payment_methods[$employee->id] = [
				'user_id'			=> $employee->id,
				'payment_methods'	=> [],
			];
		}
			
		$this->users_payment_methods[$this->user_id] = [
			'user_id'			=> $this->user_id,
			'payment_methods'	=> [],
		];
	}




	function set_addresses_relation() {

		$this->set_addresses_array();

		foreach ($this->company_performance->addresses_payment_methods as $address_payment_methods) {
			
			$this->addresses_payment_methods[$address_payment_methods->pivot->address_id]['payment_methods'][] = $address_payment_methods;
		}

		$this->add_addresses_relation();
	}

	function add_addresses_relation() {

		$relation = [];

		foreach ($this->addresses_payment_methods as $address_payment_methods) {
			
			$relation_to_add = [
				'address'			=> Address::find($address_payment_methods['address_id']),
				'payment_methods'	=> $address_payment_methods['payment_methods'],
			];

			$total_vendido = 0;

			foreach ($address_payment_methods['payment_methods'] as $payment_method) {
				
				// Log::info('sumando '.$payment_method->pivot->amount.' de '.$payment_method->name.' de la direccion '.$relation_to_add['address']->street);

				$total_vendido += $payment_method->pivot->amount;
			}

			$relation_to_add['total_vendido'] = $total_vendido;

			$relation[] = $relation_to_add;
		}

		$this->company_performance->addresses_payment_methods_formated = $relation; 
	}

	function set_addresses_array() {

		$this->addresses_payment_methods = [];

		$addresses = Address::where('user_id', $this->user_id)
							->get();

		foreach ($addresses as $address) {
			
			$this->addresses_payment_methods[$address->id] = [
				'address_id'		=> $address->id,
				'payment_methods'	=> [],
			];
		}

	}
	
}