<?php

/**
 * Котроллер оплаты
 * Class ControllerExtensionPaymentPaymaster
 *
 */
class ControllerExtensionPaymentPaymaster extends Controller
{
    const STATUS_TAX_OFF = 'no_vat';
    const MAX_POS_IN_CHECK = 100;
    const BEGIN_POS_IN_CHECK = 0;

    /**
     * Получение формы оплаты
     * @return mixed
     */
    public function index()
    {
        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['button_back'] = $this->language->get('button_back');
        $data['action'] = 'https://paymaster.ru/Payment/Init';

        $this->load->language('extension/payment/paymaster');
        $this->load->model('account/order');
        $order_totals = $this->model_account_order->getOrderTotals($this->session->data['order_id']);
        $services = [
            'shipping',
            'tax',
            'low_order_fee',
            'coupon'
        ];

        //service
        foreach ($order_totals as $service) {
            if (in_array($service['code'], $services) && ($service['value'] > 0)) {
                $data['order_check'][] = [
                    'name' => $service['title'],
                    'price' => $service['value'],
                    'quantity' => 1,
                    'tax' => self::STATUS_TAX_OFF
                ];
            }
        }

        $order_products = $this->model_account_order->getOrderProducts($this->session->data['order_id']);

        //product
        if ($order_products) {
            foreach ($order_products as $order_product) {
                $data['order_check'][] = [
                    'name' => $order_product['name'],
                    'price' => $order_product['price'],
                    'quantity' => $order_product['quantity'],
                    'tax' => $this->config->get('tax_status') ? $this->getTax($order_product['product_id']) : self::STATUS_TAX_OFF,
                ];
            }
        }

        if (count($data['order_check']) > self::MAX_POS_IN_CHECK) {
            $data['error_warning'] = $this->language->get('error_max_pos');
        }

        $data['pos'] = self::BEGIN_POS_IN_CHECK;
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        $data['merchant_id'] = $this->config->get('paymaster_merchant_id');
        $data['email'] = $order_info['email'];
        $data['order_id'] = $this->session->data['order_id'];
        $data['amount'] = number_format($order_info['total'], 2, ".", "");
        $data['lmi_currency'] = strtoupper($order_info['currency_code']);

        // Адаптер для последующего получения подписи
        $request = [
            'LMI_MERCHANT_ID' => $data['merchant_id'],
            'LMI_PAYMENT_NO' => $data['order_id'],
            'LMI_PAYMENT_AMOUNT' => $data['amount'],
            'LMI_CURRENCY' => strtoupper($data['lmi_currency'])
        ];

        $data['sign'] = $this->_getSign($request);
        $data['description'] = $this->language->get('text_order') . ' ' . $data['order_id'];

        return $this->load->view('extension/payment/paymaster', $data);
    }

    /**
     * Логгер
     * @param $method
     * @param array $data
     * @param string $text
     * @return bool
     */
    public function createLog($method, $data = [], $text = '')
    {
        if ($this->config->get('paymaster_log')) {
            if ($method == 'index') {
                $order_check = [];
                foreach ($data['order_check'] as $check) {
                    $order_check = [
                        'LMI_SHOPPINGCART.ITEMS[' . $check['pos'] . '].NAME' => $check['name'],
                        'LMI_SHOPPINGCART.ITEMS[' . $check['pos'] . '].QTY' => $check['quantity'],
                        'LMI_SHOPPINGCART.ITEMS[' . $check['pos'] . '].PRICE' => $check['price'],
                        'LMI_SHOPPINGCART.ITEMS[' . $check['pos'] . '].TAX' => $check['tax'],
                    ];
                }

                $data = array_merge([
                    'LMI_MERCHANT_ID' => $data['merchant_id'],
                    'LMI_PAYMENT_AMOUNT' => $data['amount'],
                    'LMI_CURRENCY' => strtoupper($data['lmi_currency']),
                    'LMI_PAYMENT_NO' => $data['order_id'],
                    'LMI_PAYMENT_DESC' => $data['description'],
                    'SIGN' => $data['sign'],
                ], $order_check);
            }

            $this->log->write('---------PAYMASTER START LOG---------');
            $this->log->write('---Вызываемый метод: ' . $method . '---');
            $this->log->write('---Описание: ' . $text . '---');
            $this->log->write($data);
            $this->log->write('---------PAYMASTER END LOG---------');
        }

        return true;
    }

    /**
     * Получение ставок налога
     * @param $product_id
     * @return mixed
     */
    protected function getTax($product_id)
    {
        $this->load->model('catalog/product');
        $product_info = $this->model_catalog_product->getProduct($product_id);
        $tax_rule_id = 3;

        foreach ($this->config->get('paymaster_classes') as $i => $tax_rule) {
            if ($tax_rule['paymaster_nalog'] == $product_info['tax_class_id']) {
                $tax_rule_id = $tax_rule['paymaster_tax_rule'];
            }
        }

        $tax_rules = [
            [
                'id' => 0,
                'name' => 'vat18'
            ],
            [
                'id' => 1,
                'name' => 'vat10'
            ],
            [
                'id' => 2,
                'name' => 'vat0'
            ],
            [
                'id' => 3,
                'name' => 'no_vat'
            ],
            [
                'id' => 4,
                'name' => 'vat118'
            ],
            [
                'id' => 5,
                'name' => 'vat110'
            ]
        ];

        return $tax_rules[$tax_rule_id]['name'];

    }

    /**
     * Неоплачено выброс URL
     * @return bool
     */
    public function fail()
    {
        $this->response->redirect($this->url->link('checkout/checkout', '', 'SSL'));
        return true;
    }

    /**
     * Заказ оформлен и оплачен удачно
     * @return bool
     */
    public function success()
    {

        $order_id = $this->request->post["LMI_PAYMENT_NO"];
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);

        if ((int)$order_info["order_status_id"] == (int)$this->config->get('paymaster_order_status_id')) {
            $this->model_checkout_order->addOrderHistory($order_id, $this->config->get('paymaster_order_status_id'),
                'PayMaster', true);
            $this->response->redirect($this->url->link('checkout/success', '', 'SSL'));
            return true;
        }

        return $this->fail();
    }

    /**
     * Основной CallBack для проверки подписи и смене статуса заказа
     */
    public function callback()
    {
        if (!isset($this->request->post)) {
            exit();
        }

        $order_id = $this->request->post["LMI_PAYMENT_NO"];
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);
        $amount = number_format($order_info['total'], 2, '.', '');
        $merchant_id = $this->config->get('paymaster_merchant_id');

        if (isset($this->request->post['LMI_PREREQUEST'])) {
            if ($this->request->post['LMI_MERCHANT_ID'] == $merchant_id && $this->request->post['LMI_PAYMENT_AMOUNT'] == $amount) {
                echo 'YES';
                exit;
            } else {
                echo 'FAIL';
                exit;
            }
        } else {
            if (isset($this->request->post['LMI_HASH'])) {
                $lmi_hash_post = $this->request->post['LMI_HASH'];
                $lmi_sign = $this->request->post['SIGN'];
                $request = $this->request->post;
                $hash = $this->_getHash($request);
                $sign = $this->_getSign($request);
                if ($lmi_hash_post == $hash && $lmi_sign == $sign) {
                    if ($order_info['order_status_id'] == 0) {
                        try {
                            $this->model_checkout_order->addOrderHistory($order_id,
                                $this->config->get('paymaster_order_status_id'), 'Оплачено через PayMaster');
                        }
                        catch (\Exception $exception) {
                            $this->log->write($exception->getMessage());
                            exit();
                        }
                        exit();
                    }
                    if ($order_info['order_status_id'] != $this->config->get('paymaster_order_status_id')) {
                        try {
                            $this->model_checkout_order->addOrderHistory($order_id,
                                $this->config->get('paymaster_order_status_id'), 'PayMaster', true);
                        }
                        catch (\Exception $exception) {
                            $this->log->write($exception->getMessage());
                            exit();
                        }
                        exit();
                    }
                } else {
                    $this->log->write("PayMaster sign is not correct!");
                }
            }
        }
    }

    //  Вспомогалельные функции

    /**
     * Получение HASH
     * @param $request
     * @return string
     */
    private function _getHash($request)
    {
        $hash_alg = $this->config->get('paymaster_hash_alg');
        $secret_key = htmlspecialchars_decode($this->config->get('paymaster_secret_key'));
        $plain_string = $request["LMI_MERCHANT_ID"] . ";" . $request["LMI_PAYMENT_NO"] . ";"
            . $request["LMI_SYS_PAYMENT_ID"] . ";" . $request["LMI_SYS_PAYMENT_DATE"] . ";"
            . $request["LMI_PAYMENT_AMOUNT"] . ";" . $request["LMI_CURRENCY"] . ";" . $request["LMI_PAID_AMOUNT"] . ";"
            . $request["LMI_PAID_CURRENCY"] . ";" . $request["LMI_PAYMENT_SYSTEM"] . ";"
            . $request["LMI_SIM_MODE"] . ";" .$secret_key;
        $hash = base64_encode(hash($hash_alg, $plain_string, true));
        return $hash;
    }


    /**
     * Получение подписи
     * @param $request
     * @return string
     */
    private function _getSign($request)
    {
        $hash_alg = $this->config->get('paymaster_hash_alg');
        $secret_key = htmlspecialchars_decode($this->config->get('paymaster_secret_key'));
        $plain_sign = $request["LMI_MERCHANT_ID"] . $request["LMI_PAYMENT_NO"] . $request["LMI_PAYMENT_AMOUNT"] . $request["LMI_CURRENCY"] . $secret_key;
        $sign = base64_encode(hash($hash_alg, $plain_sign, true));
        return $sign;
    }
}
