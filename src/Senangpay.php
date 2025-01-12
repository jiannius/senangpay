<?php

namespace Jiannius\Senangpay;

use Illuminate\Support\Facades\Http;

class Senangpay
{
    public $settings = [];

    public $url = [
        'production' => 'https://app.senangpay.my',
        'sandbox' => 'https://sandbox.senangpay.my',
    ];

    public function setMerchantId($value)
    {
        $this->settings['merchant_id'] = $value;
        return $this;
    }

    public function setSecretKey($value)
    {
        $this->settings['secret_key'] = $value;
        return $this;
    }

    public function setSandbox($value)
    {
        $this->settings['sandbox'] = $value;
        return $this;
    }

    public function getSettings($key = null)
    {
        $settings = [
            'merchant_id' => $this->settings['merchant_id'] ?? env('SENANGPAY_MERCHANT_ID'),
            'secret_key' => $this->settings['secret_key'] ?? env('SENANGPAY_SECRET_KEY'),
            'sandbox' => is_bool($this->settings['sandbox']) ? $this->settings['sandbox'] : (
                is_bool(env('SENANGPAY_SANDBOX')) ? env('SENANGPAY_SANDBOX') : false
            ),
        ];

        return $key ? data_get($settings, $key) : $settings;
    }

    public function getEndpoint($uri) : string
    {
        $base = $this->getSettings('sandbox') ? $this->url['sandbox'] : $this->url['production'];
        $uri = str($uri)->is('payment/*') ? (string) str($uri)->start('/') : (string) str($uri)->start('/apiv1/');

        return $base.$uri;
    }

    public function queryOrderStatus($orderId)
    {
        $mid = $this->getSettings('merchant_id');
        $sk = $this->getSettings('secret_key');

        $response = Http::get($this->getEndpoint('query_order_status'), [
            'merchant_id' => $mid,
            'order_id' => $orderId,
            'hash' => hash_hmac('sha256', collect([$mid, $sk, $orderId])->join(''), $sk),
        ]);

        return data_get($response, 'data.0');
    }

    public function checkout($params) : mixed
    {
        $mid = $this->getSettings('merchant_id');
        $sk = $this->getSettings('secret_key');
        $detail = data_get($params, 'detail');
        $orderId = data_get($params, 'order_id');
        $amount = data_get($params, 'amount');
        $amount = (string) str(number_format($amount, 2))->replace(',', '');

        $params = [
            ...$params,
            'amount' => $amount,
            'hash' => hash_hmac('sha256', collect([$sk, $detail, $amount, $orderId])->join(''), $sk),
        ];

        $url = $this->getEndpoint('payment/'.$mid).'?'.http_build_query($params);

        return redirect($url);
    }

    public function validatePayload($payload = null) : mixed
    {
        $payload = $payload ?? request()->all();
        $sk = $this->getSettings('secret_key');
        $status = data_get($payload, 'status_id');
        $order = data_get($payload, 'order_id');
        $transaction = data_get($payload, 'transaction_id');
        $msg = data_get($payload, 'msg');
        $hash = hash_hmac('sha256', collect([$sk.$status.$order.$transaction.$msg])->join(''), $sk);

        if ($hash !== data_get($payload, 'hash')) {
            logger('Unable to validate hashing.');
            return false;
        }

        return true;
    }

    public function getStatus($payload = null)
    {
        $payload = $payload ?? request()->all();
        $status = data_get($payload, 'status_id') ?? data_get($payload, 'payment_info.status');

        if (in_array($status, ['0', 'failed'])) return 'failed';
        else if (in_array($status, ['1', 'paid'])) return 'success';
        else if ($status === '2') return 'pending';

        return null;
    }

    public function test() : array
    {
        try {
            $mid = $this->getSettings('merchant_id');
            $sk = $this->getSettings('secret_key');

            $response = Http::get($this->getEndpoint('query_order_status'), [
                'merchant_id' => $mid,
                'order_id' => 'testing-123',
                'hash' => hash_hmac('sha256', collect([$mid, $sk, 'testing-123'])->join(''), $sk),
            ]);

            throw_if(!data_get($response, 'status'), \Exception::class, data_get($response, 'msg'));

            return [
                'success' => true,
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Connection failed',
            ];
        }
    }
}
