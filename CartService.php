<?php

namespace App\Services\Cart;

use CartRubgy;
use Darryldecode\Cart\Cart;
use Darryldecode\Cart\CartCondition;
use App\Services\Payment\PaymentPagesService;

class CartService
{
    protected function addItem($id, $name, $price, $quantity, $attributes = [])
    {
        CartRubgy::add(
            compact('id', 'name', 'price', 'quantity', 'attributes')
        );
    }

    public function addPackage(array $package)
    {
        $attributes = [
            'rooms' => $package['rooms'],
            'selectedCityId' => $package['selectedCityId'],
            'travelers' => $package['travelers'],
            'ticket_cat' => $package['ticket_cat'],
        ];

        $this->addItem(
            $id,
            $package['packageName'],
            $package['price'],
            $quantity,
            $attributes
        );
    }

    public function addTickets(array $tickets)
    {
        $this->addItem(
            $tickets['id'],
            $tickets['name'],
            $tickets['price'],
            $tickets['quantity'],
            $tickets['attributes']
        );
    }

    public function addCondition($name, $type, $target, $value, $order = null)
    {
        $condition = new CartCondition(
            [
                'name' => $name,
                'type' => $type,
                'target' => $target,
                'value' => $value,
                'order' => $order
            ]
        );
        CartRubgy::condition($condition);
    }

    public function delItem($id)
    {
        CartRubgy::remove($id);
    }

    public function removeCondition($name)
    {
        CartRubgy::removeCartCondition($name);
    }

    public function applyPromo(array $params)
    {
        $this->addCondition(
            $params['name'],
            $params['type'],
            $params['target'],
            $params['value']
        );
    }

    public function getAllItemsForTickets()
    {
        $items = [];
        if(count(CartRubgy::getContent()->toArray())) {
            foreach (CartRubgy::getContent()->toArray() as $k => $v) {
                $item = [
                    'id' => $v['id'],
                    'name' => $v['name'],
                    'price' => $v['price'],
                    'quantityBooked' => $v['quantity'],
                    'category' => $v['attributes']['category'],
                    'tier' => $v['attributes']['tier'],
                    'currency' => $v['attributes']['currency'],
                    'discount_price' => $v['attributes']['discount_price'],
                    'base_price' => $v['attributes']['base_price'],
                    'has_discount' => $v['attributes']['has_discount'],
                    'log_id' => $v['attributes']['log_id'],
                    'event_id' => $v['attributes']['event_id'],
                ];
                $items[] = $item;
            }
        }
        return [
            'items' => $items,
            'totalAmount' => CartRubgy::getTotal(),
            'subTotalAmount' => CartRubgy::getSubTotal(),
            'conditions' => CartRubgy::getConditions(),
        ];
    }

    public function getAllItems()
    {
        return [
            'items' => CartRubgy::getContent()->toArray(),
            'totalAmount' => CartRubgy::getTotal(),
            'subTotalAmount' => CartRubgy::getSubTotal(),
            'conditions' => CartRubgy::getConditions(),
        ];
    }
    public function getPackage()
    {
        return CartRubgy::get('package_1');//todo package id
    }

    public function clear()
    {
        CartRubgy::clear();
        CartRubgy::clearCartConditions();
        (new PaymentPagesService)->resetTimer();
        session()->forget('purchaser_data');
        session()->forget('order');
        session()->save();
    }
}
