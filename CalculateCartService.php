<?php

namespace App\Services\Cart;

use App\Models\Redtix\Event as RedtixEvent;
use App\Models\Redtix\PaymentMethod;
use App\Services\Cart\CartService;
use App\Models\Redtix\EventPaymentMethod;

class CalculateCartService
{
    private $redtixEvent;

    public $payment_config;

    private $cartData;

    private $ticketingFee;

    /**
     * CalculateCartService constructor.
     * @param RedtixEvent $event
     * @param $id
     */
    public function __construct(RedtixEvent $event, $id)
    {
        $this->redtixEvent = $event;
        $this->payment_config = $this->getPaymentConfig($event, $id);
        $this->cartData = $this->getCartData();
    }

    /**
     * @return array
     */
    public function getValues()
    {
        $fee = $this->getSumTicketingFee(
            $this->getSumCart(),
            $this->getSumCart('discount')
        );
        $event = $this->getSumEventProtect($this->cartData['total_summ']);
        $customer = $this->getSumCustomerProtect($this->cartData['total_summ']);
        $processingFee = $this->getSumProcessingFee([$fee, $event, $customer, $this->cartData['total_summ']]);
        $commission = $this->getSumCommission(
            $this->getSumCart(),
            $this->getSumCart('discount')
        );

        return [
            'parse_value' => [
                'ticketing_fee' => round($fee, 2, PHP_ROUND_HALF_UP),
                'event_protect' => round($event, 2, PHP_ROUND_HALF_UP),
                'customer_protect' => round($customer, 2, PHP_ROUND_HALF_UP),
                'mdr' => round($processingFee, 2, PHP_ROUND_HALF_UP),
                'commission' => round($commission, 2, PHP_ROUND_HALF_UP),
                'custom_price_base' => $this->getSumCart(),
                'custom_price_discount' => $this->getSumCart('discount'),
            ],
            'condition' => array_sum([$fee, $event, $customer, $processingFee, $commission])
        ];
    }

    /**
     * @param RedtixEvent $event
     * @param $id
     *
     * @return array|null
     */
    private function getPaymentConfig(RedtixEvent $event, $id)
    {
        $dataEvent = $event::find($id);
        if ($dataEvent) {
            return [
                'id' => $dataEvent->id,
                'currency' => $dataEvent->currency->toArray(),
                'commission' => $dataEvent->commission->toArray(),
                'event_payment_method' => EventPaymentMethod::where('event_id', $dataEvent->id)
                        ->where('payment_method_id', 1) //only credit cart
                        ->get()
                        ->toArray()[0] ??
                    PaymentMethod::whereId(1)
                        ->get()
                        ->toArray()[0],
                'insurance' => $dataEvent->eventInsurance ? $dataEvent->eventInsurance->first()->toArray() : [
                    'has_event_protect' => null,
                    'has_customer_protect' => null,
                    'event_protect_type' => null,
                    'event_protect_value' => null,
                    'customer_protect_type' => null,
                    'customer_protect_value' => null
                ]
            ];
        }

        return null;
    }

    /**
     * @param $priceTicketsBase
     * @param $priceTicketsDiscount
     *
     * @return float|mixed
     */
    private function getSumTicketingFee($priceTicketsBase, $priceTicketsDiscount)
    {
        $commission = $this->payment_config['commission'];

        if ($commission['ticketing_fee_from'] == 'Base Price') {
            $price = $priceTicketsBase;
        } else {
            $price = $priceTicketsDiscount;
        }

        if ($commission['ticketing_fee_type'] == 'Nominal') {
            if ($commission['ticketing_fee_calculation'] == 'Cart')
                return $this->ticketingFee = $commission['ticketing_fee_value'];
             else return $this->ticketingFee = $commission['ticketing_fee_value'] * $this->getCartData()['cnt'];
        } else  return $this->ticketingFee = ($price * $commission['ticketing_fee_value']) / 100;
    }

    /**
     * @param $priceTickets
     *
     * @return float|int
     */
    private function getSumEventProtect($priceTickets)
    {
        $price = $priceTickets + $this->ticketingFee;
        $insurance = $this->payment_config['insurance'];
        if ($insurance['has_event_protect']) {
            if ($insurance['event_protect_type'] == 'Nominal')
                return $insurance['event_protect_value'];
            else return ($price * $insurance['event_protect_value']) / 100;
        }
        return 0;
    }

    /**
     * @param $priceTickets
     *
     * @return float|int
     */
    private function getSumCustomerProtect($priceTickets)
    {
        $price = $priceTickets + $this->ticketingFee;
        $insurance = $this->payment_config['insurance'];
        if ($insurance['has_customer_protect']) {
            if ($insurance['customer_protect_type'] == 'Nominal')
                return $insurance['customer_protect_value'];
            else return ($price * $insurance['customer_protect_value']) / 100;
        }
        return 0;
    }

    /**
     * @param $baseSum
     * @param $discountSum
     *
     * @return float|int
     */
    private function getSumCommission($baseSum, $discountSum)
    {
        $event_payment_method = $this->payment_config['commission'];

        if ($event_payment_method['commission_from'] == 'Base Price') {
            $sum = $baseSum;
        } else {
            $sum = $discountSum;
        }

        if ($event_payment_method['commission_value']) {
            if ($event_payment_method['commission_type'] == 'Nominal') {
                if ($event_payment_method['commission_calculation'] == 'Cart') {
                    $this->ticketingFee = $event_payment_method['commission_value'];
                    return $this->ticketingFee;
                } else {
                    $this->ticketingFee = $event_payment_method['commission_value'] * $this->getCartData()['cnt'];
                    return $this->ticketingFee;
                }
            } else {
                $this->ticketingFee = ($sum * $event_payment_method['commission_value']) / 100;
                return $this->ticketingFee;
            }
        }

        return 0;
    }

    /**
     * @param array $params
     *
     * @return float|int
     */
    private function getSumProcessingFee(array $params)
    {
        $event_payment_method = $this->payment_config['event_payment_method'];
        if ($event_payment_method['mdr'] && $event_payment_method['is_active']) {
            return (array_sum($params) * $event_payment_method['mdr']) / 100;
        }
        return 0;
    }

    /**
     * @return array
     */
    private function getCartData()
    {
        $data = (new CartService)->getAllItemsForTickets();
        $cnt = 0;

        foreach ($data['items'] as $item) {
            $cnt += $item['quantityBooked'];
        }

        $cart = [
            'currency' => $data['items'][0]['currency']['code'],
            'currency_id' => $data['items'][0]['currency']['id'],
            'total_summ' => $data['subTotalAmount'],
            'cnt' => $cnt,
            'all' => $data,
        ];

        return $cart;
    }

    /**
     * @param string $type
     *
     * @return int
     */
    private function getSumCart($type = 'base'): int
    {
        $data = (new CartService)->getAllItemsForTickets();
        $price = 0;

        foreach ($data['items'] as $v) {
            for ($i = 0; $i < $v['quantityBooked']; $i++) {
                if ($type == 'base')
                    $price += $v['base_price'];
                else $price += ($v['discount_price'] != 0) ?
                    $v['discount_price'] :
                    $v['price'];
            }
        }

        return (int)$price;
    }

}
