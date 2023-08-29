<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\PaymentCardHelper;
use App\Http\Controllers\Helpers\PaywayHelper;
use App\Models\Buyer;
use App\Models\PaymentCardInfo;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PaywayController extends Controller
{
    function executePayment($order) {
        $user = $this->user();
        $this->order = $order;
        $keys_data = [
            'public_key' => $order->payment_method->public_key,
            'private_key' => $order->payment_method->access_token,
        ];

        $ambient = 'test';//valores posibles: 'test' , 'prod' o 'qa'
        $connector = new \Decidir\Connector($keys_data, $ambient);

        $site_transaction_id = time().'_'.rand(0,1000);

        $online_payment_helper = new PaymentCardHelper($user, $order->payment_method);

        $articles = $online_payment_helper->setPrices($order->cupon, $order->delivery_zone, $order->articles);

        foreach ($articles as $article) {
            $total = $article['final_price'] *  $article['amount'];
        }

        $data = [
            'site_transaction_id'   => $site_transaction_id,
            'token'                 => $order->payment_card_info->token,
            'customer'              => array(
                'id'                => 'customer', 
                'email'             => $order->buyer->email,
                'ip_address'        => null,
            ),
            'payment_method_id'     => (int)$order->payment_card_info->payment_method_id,
            // 'payment_method_id'     => PaywayHelper::getPaymentMethodId($order->payment_card_info->card_brand),
            'bin'                   => $order->payment_card_info->bin,
            'amount'                => $total,
            'currency'              => 'ARS',
            'installments'          => $order->payment_card_info->installments,
            'description'           => '',
            'establishment_name'    => $user->company_name,
            'payment_type'          => 'single',
            'sub_payments'          => array(),
        ];

        Log::info('data: ');
        Log::info($data);

        try {
            $response = $connector->payment()->ExecutePayment($data);
            $response->getId();
            $response->getToken();
            $response->getUser_id();
            $response->getPayment_method_id();
            $response->getBin();
            $response->getAmount();
            $response->getCurrency();
            $response->getInstallments();
            $response->getPayment_type();
            $response->getDate_due();
            $response->getSub_payments();
            $response->getStatus();
            $response->getStatus_details()->ticket;
            $response->getStatus_details()->card_authorization_code;
            $response->getStatus_details()->address_validation_code;
            $response->getStatus_details()->error;
            $response->getDate();
            $response->getEstablishment_name();
            // $response->getFraud_detection();
            // $response->getAggregate_data();
            // $response->getSite_id();
            Log::info('Todo bien');
            Log::info($response);
        } catch( \Exception $e ) {
            Log::info('Hubo un error');
            Log::info($e->getData());
            $this->updatePaymentCardInfo($e->getData());
        }
    }

    function updatePaymentCardInfo($data) {
        $payment_card_info = PaymentCardInfo::find($this->order->payment_card_info_id);
        $payment_card_info->payment_id = $data['id']; 
        $payment_card_info->card_brand = $data['card_brand']; 
        $payment_card_info->status = $data['status']; 
        $payment_card_info->num_ticket = $data['status_details']['ticket']; 
        $payment_card_info->save();
    }
}
