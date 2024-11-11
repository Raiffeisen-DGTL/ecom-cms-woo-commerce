<?php

namespace RF\Payment;

defined('ABSPATH') || exit;

use WC_HTTPS;
use WC_Order;
use WC_Order_Item;
use WC_Payment_Gateway;
use WP_REST_Server;
use WP_Error;
use Exception;
use ErrorException;
use RF\Api\Client;
use RF\Api\ClientException;

class Gateway extends WC_Payment_Gateway
{
    public $id = 'rf';

    protected $notification_url;
    protected $env_url;
    protected $public_id;
    protected $secret_key;
    protected $theme_code;
    protected $alive_time;
    protected $method_supports;
    protected $title_icon;
    protected $paymenticon;
    protected $use_popup;
    protected $use_debug;
    protected $title_icon_html;
    protected $icon_html;
    protected $method_description_html;
    protected $logger;
    protected $client;
    protected $vat;
    protected $inner_payment_method;
    protected $enable_fiscal;
    // protected $fiscal_documents_format;

    public static function payment_post()
    {
        require_once 'page-sync.php';
    }

    public static function page_sync()
    {

        wp_enqueue_style(
            'woocommerce-payment-rf-sync',
            plugins_url('/assets/sync.css', __DIR__),
            [],
            filemtime(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'sync.css')
        );
        wp_enqueue_script(
            'woocommerce-payment-rf-sync',
            plugins_url('/assets/sync.js', __DIR__),
            ['jquery'],
            filemtime(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'sync.js'),
            true
        );
        wp_localize_script(
            'woocommerce-payment-rf-sync',
            'woocommerce_payment_rf_sync',
            [
                'url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wp_ajax_woocommerce_payment_rf_sync'),
                'beforeunload' => 'Остановить синхронизацию ?',
                'error' => 'Ошибка синхронизации',
                'end' => 'Синхронизация выполнена',
                'single' => '[{0}/{1}] Проверка статуса для заказа #{2}  ',
                'success' => 'Успех',
            ]
        );

        require_once 'page-sync.php';
    }

    public static function set_menu()
    {
        add_action('admin_menu', function () {
            $config_page = 'Райффайзенбанк';
            add_submenu_page('woocommerce', 'Райффайзен', $config_page, 'manage_woocommerce', 'admin.php?page=wc-settings&tab=checkout&section=rf');
        }, 51);
    }

    public static function set_menu_sync()
    {
        add_action('admin_menu', function () {
            $sync_page = 'Райффайзенбанк. Синхронизация';
            add_submenu_page('woocommerce', $sync_page, $sync_page, 'manage_woocommerce', 'woocommerce_payment_rf_sync', ['RF\Payment\Gateway', 'page_sync']);
        }, 51);
    }

    public function get_client_options($options)
    {
        if (empty($options)) {
            return [];
        }

        return $options;
    }

    public function get_host($host)
    {
        if (empty($host)) {
            return Client::HOST_PROD;
        }

        return $host;
    }

    public static function ajax_sync()
    {
        check_ajax_referer('wp_ajax_woocommerce_payment_rf_sync', 'nonce');
        if (!is_admin()) {
            wp_die('error');
        }

        $response = [
            'nonce' => wp_create_nonce('wp_ajax_woocommerce_payment_rf_sync')
        ];

        $order_id = $_POST['order_id'];

        if ($order_id) {
            $payment = wc_get_payment_gateway_by_order($order_id);
            if ($payment instanceof Gateway) {
                $payment->init_options();

                $result = $payment->process_order($order_id);

                $response['message'] = $result['result'];
            }
        } else {
            $response['list'] = wc_get_orders([
                'limit' => -1,
                'return' => 'ids',
                'status' => 'wc-pending',
                'payment_method' => 'rf',
            ]);
        }

        wp_send_json($response);
    }

    public function set_client_callback_url($callBackUrl)
    {
        $this->client->postCallbackUrl($this->notification_url);
    }

    public function client_check_event_signature($sign, $notice)
    {
        return $this->client->checkEventSignature($sign, $notice);
    }

    public function get_client_order_transaction($bill_id)
    {
        return $this->client->getOrderTransaction($bill_id);
    }

    public function get_client_pay_url($url, $order_get_total, $bill_id, $params)
    {
        return $this->client->getPayUrl($order_get_total, $bill_id, $params);
    }

    public function get_client_refund_id($refund_id)
    {
        return $this->client->generateId();
    }

    public function get_client_post_order_refund($refund, $bill_id, $refund_id, $amount, $receipt)
    {
        return $this->client->postOrderRefund($bill_id, $refund_id, $amount, $receipt);
    }

    /**
     * Gateway constructor.
     */
    public function __construct()
    {
        $this->method_title = 'Райффайзен';

        $this->method_supports = "Raiffeisen payments";

        $this->method_description = "";

        // Getaway accept billing and refunding.
        $this->supports = ['products', 'refunds'];

        // Setup logger.
        $this->logger = wc_get_logger();

        // Setup icons.
        $this->title_icon = plugins_url('assets/logo.png', __DIR__);

        // Setup readonly props.
        $this->notification_url = site_url() . '/?wc-api=' . $this->id;

        // Initialise config and form.

        $this->init_form_fields();
        $this->init_settings();
        $this->init_options();

        // Hooks.
        $this->init_hooks();

        $this->title_icon_html = $this->get_option('title', $this->method_title).'<br><img src="' . WC_HTTPS::force_https_url($this->title_icon) . '"  class="rf" />';
        $this->icon_html = '<img src="' . WC_HTTPS::force_https_url($this->title_icon) . '"  class="rf" />';

        $this->method_description_html = $this->method_description;

        // Initialise API.
        try {
            $host = apply_filters('woocommerce_payment_rf_host', $this->env_url);
            $options = apply_filters('woocommerce_payment_rf_client_options', []);

            $this->client = new Client($this->secret_key, $this->public_id, $host, $options);

            do_action('woocommerce_payment_rf_client_set_callback_url', $this->notification_url);
        } catch (ErrorException $exception) {
            wc_add_wp_error_notices(new WP_Error(
                $exception->getCode(),
                $exception->getMessage(),
                $exception
            ));
        }

        // Capture API callback.
        add_action("woocommerce_api_{$this->id}", [$this, 'woocommerce_api']);

        // Prevent html escape.
        add_filter('esc_html', [$this, 'title_esc_html'], 50, 2);

        if (is_admin()) {
            // Capture options change.
            add_action("woocommerce_update_options_payment_gateways_{$this->id}", [$this, 'process_admin_options']);
        } else {
            // Add frontend scripts.
            wp_register_script('rf-oplata-popup', 'https://pay.raif.ru/pay/sdk/v2/payment.min.js');
            wp_enqueue_script(
                'woocommerce-payment-rf-popup',
                plugins_url('/assets/popup.js', __DIR__),
                ['rf-oplata-popup', 'wc-checkout'],
                filemtime(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'popup.js'),
                true
            );

            // Add frontend style.
            wp_enqueue_style(
                'woocommerce-payment-rf1',
                plugins_url('/assets/payment.min.css', __DIR__),
                [],
                filemtime(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'payment.min.css')
            );
            wp_enqueue_style(
                'woocommerce-payment-rf',
                plugins_url('/assets/rf.css', __DIR__),
                [],
                filemtime(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'rf.css')
            );
        }
    }


    protected function log($message, $context)
    {
        if ($this->use_debug) {
            //phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Because on debug mode only.
            $this->logger->debug($message . ': ' . print_r($context, true));
            //phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_print_r
        }
    }

    protected function init_hooks()
    {
        add_filter('woocommerce_payment_rf_host', [$this, 'get_host'], 50);
        add_filter('woocommerce_payment_rf_client_options', [$this, 'get_client_options'], 50);
        add_action('woocommerce_payment_rf_client_set_callback_url', [$this, 'set_client_callback_url'], 50);
        add_filter('woocommerce_payment_rf_client_check_event_signature', [$this, 'client_check_event_signature'], 50, 3);
        add_filter('woocommerce_payment_rf_client_get_order_transaction', [$this, 'get_client_order_transaction'], 50, 2);
        add_filter('woocommerce_payment_rf_client_get_pay_url', [$this, 'get_client_pay_url'], 50, 4);
        add_filter('woocommerce_payment_rf_client_get_refund_id', [$this, 'get_client_refund_id'], 50);
        add_filter('woocommerce_payment_rf_client_post_order_refund', [$this, 'get_client_post_order_refund'], 50, 5);
    }

    protected function init_options()
    {
        $this->env_url = $this->get_option('env_url');
        $this->title = $this->get_option('title', $this->method_title);
        $this->description = $this->get_option('description', $this->method_description);
        $this->secret_key = $this->get_option('secret_key');
        $this->public_id = $this->get_option('public_id');
        $this->theme_code = $this->get_option('theme_code');
        $this->alive_time = intval($this->get_option('alive_time', 45));
        $this->use_popup = $this->get_option('use_popup', 'not') === 'yes';
        $this->use_debug = $this->get_option('use_debug') === 'yes';
        $this->inner_payment_method = $this->get_option('inner_payment_method');
        $this->vat = $this->get_option('vat');
        $this->enable_fiscal = $this->get_option('enable_fiscal');
        // $this->fiscal_documents_format = $this->get_option('fiscal_documents_format');
    }


    public function process_admin_options()
    {
        global $wpdb;

        $result = parent::process_admin_options();
        $this->init_options();

        /**
         * Query for update payment title on old bills.
         *
         * @noinspection SqlResolve
         */
        $wpdb->query($wpdb->prepare(
            "UPDATE $wpdb->postmeta AS t1 LEFT JOIN $wpdb->postmeta AS t2 ON t1.`post_id` = t2.`post_id` SET t1.`meta_value` = %s WHERE t1.`meta_key` = '_payment_method_title' AND t2.`meta_key` = '_payment_method' AND t2.`meta_value` = %s",
            $this->get_title(),
            $this->id
        ));

        return $result;
    }


    public function get_method_description()
    {
        return apply_filters('woocommerce_gateway_method_description', $this->method_description_html, $this);
    }

    public function get_title()
    {
        return apply_filters('woocommerce_gateway_title', $this->title_icon_html, $this->id);
        //return apply_filters('woocommerce_gateway_title', '$this->title_icon_html', $this->id);
    }

    public function title_esc_html($safe_text, $text)
    {
        return $text;
    }

    public function get_icon()
    {
    }

    public function needs_setup()
    {
    }

    public function init_settings()
    {
        parent::init_settings();

        // Readonly option.
        $this->settings['notification_url'] = $this->notification_url;
    }

    /**
     * Set up config page fields.
     */
    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => 'Активно',
                'label' => '',
                'type' => 'checkbox',
                'default' => 'no',
            ],
            'env_url' => [
                'title' => 'Тестовый режим',
                'desc' => '',
                'type' => 'select',
                'default' => '',
                'options' => array(
                    Client::HOST_TEST => 'Да',
                    Client::HOST_PROD => 'Нет'
                )
            ],
            'title' => [
                'title' => 'Название',
                'description' => 'Название метода оплаты',
                'type' => 'text',
                'default' => '',
            ],
            'description' => [
                'title' => 'Описание',
                'description' => 'Описание метода оплаты',
                'type' => 'textarea',
                'default' => $this->method_description,
            ],
            'public_id' => [
                'title' => 'Public ID',
                'description' => 'Идентификатор мерчанта, обязательный',
                'type' => 'text',
            ],
            'secret_key' => [
                'title' => 'Секретный ключ',
                'description' => 'Секретный ключ, обязательный',
                'type' => 'password',
            ],
            'notification_url' => [
                'title' => 'URL для настройки callback',
                'description' => 'URL для настройки callback. Для корректной работы уведомлений, убедитесь что данный адрес указан в настройках на стороне банка на pay.raif.ru.',
                'type' => 'text',
                'disabled' => true,
                'default' => $this->notification_url,
            ],
            'vat' => [
                'title' => 'НДС',
                'description' => 'Укажите ставку НДС',
                'type' => 'text',
                'default' => '21',
            ],
            'use_popup' => [
                'title' => 'Всплывающая форма оплаты',
                'description' => 'Использовать всплывающую форму оплаты в том же окне',
                'type' => 'checkbox',
                'default' => 'not',
            ],
            'theme_code' => [
                'title' => 'Стили для формы оплаты',
                'description' => 'Css стили для формы оплаты. Измените внешний вид формы в конструкторе и перенесите код в эту форму (<a href="https://pay.raif.ru/pay/configurator/" target="_blank">https://pay.raif.ru/pay/configurator/</a>)',
                'type' => 'textarea',
            ],
            'inner_payment_method' => [
                'title' => 'Метод оплаты',
                'desc' => 'Возможные внутренние методы оплаты',
                'type' => 'select',
                'default' => '',
                'options' => array(
                    '' => 'Все',
                    'ONLY_ACQUIRING' => 'Карта',
                    'ONLY_SBP' => 'СБП'
                )
            ],
            'enable_fiscal' => [
                'title' => 'Фискализация чеков',
                'description' => 'Будет использована фискализация чеков (https://pay.raif.ru/doc/fiscal.html)',
                'type' => 'checkbox',
                'default' => 'not',
            ],
            // 'fiscal_documents_format' => [
            //     'title' => 'ФФД',
            //     'description' => 'Формат фискальных документов',
            //     'type' => 'select',
            //     'default' => 'FFD_105',
            //     'options' => array(
            //         'FFD_105' => 'ФФД 1.05',
            //         'FFD_12'  => 'ФФД 1.2'
            //     )
            // ],
            'use_debug' => [
                'title' => 'Режим отладки',
                'description' => 'Включить логирование запросов',
                'type' => 'checkbox',
                'default' => 'not',
            ],
        ];
    }

    public function process_status($status, $order)
    {
        error_log('status: ' . $status);
        switch ($status) {
            case 'WAITING':
                $order->update_status('pending');
                break;
            case 'SUCCESS':
                $order->payment_complete();
                break;
            case 'REJECTED':
                $order->update_status('canceled');
                break;
            case 'EXPIRED':
                $order->update_status('failed');
                break;
            case 'PARTIAL':
                $order->update_status('processing');
                break;
            case 'FULL':
                $order->update_status('refunded');
                break;
        }
    }

    private static function getStyle($str) {
        $re = '/{ ( (?: [^{}]* | (?R) )* ) }/x';
        preg_match_all($re, $str, $matches);

        if (isset($matches[0], $matches[0][0]) && !empty($matches)) {
            $str = rtrim($matches[0][0], '}');
            $str = ltrim($str, '{');

            preg_match_all($re, $str, $matchesTwo, PREG_SET_ORDER, 0);

            if (isset($matchesTwo, $matchesTwo[0], $matchesTwo[0][0]) && !empty($matchesTwo[0][0])) {
                return $matchesTwo[0][0];
            }
        }

        return false;
    }

    // Callback url
    public function woocommerce_api()
    {
        // Get request data.
        $sign = array_key_exists('HTTP_X_API_SIGNATURE_SHA256', $_SERVER) ? wp_unslash($_SERVER['HTTP_X_API_SIGNATURE_SHA256']) : '';  // phpcs:ignore WordPress.VIP
        $body = WP_REST_Server::get_raw_data();
        $notice = json_decode($body, true);

        error_log("sign: " . $sign);
        error_log("body: " . $body);

        // Check signature.
        $result = apply_filters('woocommerce_payment_rf_client_check_event_signature', $sign, $notice);

        $this->log(
            $result ? 'Получено действительное уведомление' : 'Получено недействительное уведомление',
            [
                'sign' => $sign,
                'notice' => $notice,
            ]
        );
        if (!$result) {
            wp_send_json(['error' => 403], 403);
        }

        $order = wc_get_order($notice['transaction']['orderId']);

        $order->update_meta_data('paymentmetod', $notice['transaction']['paymentMethod']);
        $order->save();

        $this->process_status($notice['transaction']['status']['value'], $order);
        wp_send_json(['error' => 0], 200);
    }

    public function process_order($order_id)
    {
        $order = wc_get_order($order_id);
        $bill_id = $order->get_transaction_id();

        $params = [
            'amount' => $order->get_total(),
            'currency' => $order->get_currency(),
            'phone' => $order->get_billing_phone(),
            'email' => $order->get_billing_email(),
            'account' => $order->get_user_id(),
            'successUrl' => $this->get_return_url($order),
            'paymentMethod' => $this->inner_payment_method,
            'customFields' => array_filter([
                'themeCode' => $this->theme_code,
            ]),
        ];

        // Return notice on trow.
        try {
            // Need to create bill transaction.
            if ($order->get_status() === 'pending' && empty($bill_id)) {

                $bill_id = $order_id;
                $order->set_transaction_id($bill_id);
                $order->save();

                // Reduce stock levels.
                wc_reduce_stock_levels($order->get_id());

                // Remove cart.
                wc_empty_cart();

            } elseif (!$order->is_paid() && $order->get_status() === 'cancelled') {
                $this->deleteOrder($bill_id);

                $this->log(
                    'Cancel bill',
                    [
                        'bill_id' => $bill_id,
                        'bill' => $bill,
                    ]
                );
            } else {
                error_log('');
                $bill = '';
                $bill = apply_filters('woocommerce_payment_rf_client_get_order_transaction', $bill, $bill_id);
                $order->update_meta_data('paymentmetod', $bill['transaction']['paymentMethod']);
                $order->save();
                $this->process_status($bill['transaction']['status']['value'], $order);
                $this->log(
                    'Get bill info',
                    [
                        'bill_id' => $bill_id,
                        'bill' => $bill,
                    ]
                );
            }
        } catch (Exception $exception) {
            $message = 'request error';
            wc_add_wp_error_notices(new WP_Error(
                $exception->getCode(),
                $message . '<br>' . $exception->getMessage(),
                $exception
            ));
            return ['result' => 'fail'];
        }


        // Return thank you redirect.
        $url = '';
        $url = apply_filters('woocommerce_payment_rf_client_get_pay_url', $url, $order->get_total(), $bill_id, $params);

        //$styles = self::getStyle($this->theme_code);
        $styles = $this->get_option('theme_code');
        preg_match_all('/style:\s*{\s*(.*?}),\s*},/si', $styles, $output_array);
    
        if(!empty($output_array[1])) {
            $css = $output_array[1][0];
            //$style = $css;
        }
        if(!isset($css)) {
            $css = $styles;
        }
        $css = str_replace(PHP_EOL, '', $css);
        $css = trim(preg_replace('/\s\s+/', '', $css));
        $css = str_replace(',},}', '}}', $css);
        $css = str_replace(',}', '}', $css);
        $css = str_replace("'", '"', $css);
        $to_repalce = [
            'header',
            'titlePlace',
            'button',
            'backgroundColor',
            'textColor',
            'hoverTextColor',
            'hoverBackgroundColor',
            'borderRadius',
            'logo',
        ];
        foreach ($to_repalce as $item) {
            $css = str_replace($item, '"'.$item.'"', $css);
        }
        $css = str_replace(" ", '', $css);
        $css = trim($css);

        $result = [
            'result' => 'success',
            'success' => $this->get_return_url($order),
            'redirect' => $url,
            'order_id' => $bill_id,
            'public_id' => $this->public_id,
            'amount' => $order->get_total(),
            'paymentMethod' => $this->inner_payment_method,
            'styles' => $css,
            'payurl' => explode('?', $url, 2)[0]
        ];

        if ($this->use_popup) {
            $result['popup_type'] = 'popup';
        } else
            $result['popup_type'] = 'replace';

        $this->log(
            'enable_fiscal',
            $this->enable_fiscal
        );
        // Add receiept to fiscal mode
        if ($this->enable_fiscal === 'yes') {
            $receipt = [
                'receiptNumber' => $bill_id,
                'customer' => array_filter(['email' => $order->get_billing_email()]),
                'items' => array()
            ];
            foreach ($order->get_items() as $item_id => $item) {
                $item = [
                    'name' => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                    'price' => $item->get_product()->get_price(),
                    'amount' => $item->get_total(),
                    'vatType' => $this->vat,
                ];
                $receipt['items'][] = $item;
            }
            $result['receipt'] = $receipt;

            
            // if ($this->fiscal_documents_format === 'FFD_105') {
            //     $rfb_receipt = [
            //         'receiptNumber' => $bill_id,
            //         'client' => [
            //             'email' => $order->get_billing_email(),
            //         ],
            //         'items' => array_map(function (WC_Order_Item $item) {
            //             return [
            //                 'name' => $item->get_name(),
            //                 'price' => $item->get_product()->get_price(),
            //                 'quantity' => $item->get_quantity(),
            //                 'amount' => $item->get_total(),
            //                 'vatType' => $this->vat,
            //             ];
            //         }, array_values($order->get_items())),
            //         'total' => $order->get_total(),
            //     ];
            // }
            // elseif ($this->fiscal_documents_format === 'FFD_12') {
            //     $rfb_receipt = [
            //         'receiptNumber' => $bill_id,
            //         'client' => [
            //             'email' => $order->get_billing_email(),
            //         ],
            //         'items' => array_map(function (WC_Order_Item $item) {
            //             return [
            //                 'name' => $item->get_name(),
            //                 'price' => $item->get_product()->get_price(),
            //                 'quantity' => $item->get_quantity(),
            //                 'amount' => $item->get_total(),
            //                 'vatType' => $this->vat,
            //             ];
            //         }, array_values($order->get_items())),
            //         'total' => $order->get_total(),
            //     ];
            // }

            // $this->create_rfb_receipt([
            //     'receiptNumber' => $bill_id,
            //     'client' => [
            //         'email' => $order->get_billing_email(),
            //     ],
            //     'items' => array_map(function (WC_Order_Item $item) {
            //         return [
            //             'name' => $item->get_name(),
            //             'price' => $item->get_product()->get_price(),
            //             'quantity' => $item->get_quantity(),
            //             'amount' => $item->get_total(),
            //             'vatType' => $this->vat,
            //         ];
            //     }, array_values($order->get_items())),
            //     'total' => $order->get_total(),
            // ]);
        }

        return $result;
    }

    protected function create_rfb_receipt($receipt)
    {
        $this->log(
            'Create RFB receipt',
            $receipt
        );
        $response = $this->client->postReceipt($receipt);
        $this->log(
            'Create RFB receipt response',
            $response
        );

        $response = $this->client->putReceipt($response['receiptNumber']);
        $this->log(
            'Register RFB receipt response',
            $response
        );
    }

    public function process_payment($order_id)
    {
        error_log("process payment");
        $result = $this->process_order($order_id);

        // Detect AJAX.
        $request = array_key_exists('HTTP_X_REQUESTED_WITH', $_SERVER) ? wp_unslash($_SERVER['HTTP_X_REQUESTED_WITH']) : ''; // phpcs:ignore WordPress.VIP
        if (strtolower($request) === 'xmlhttprequest') {
            wp_send_json(apply_filters('woocommerce_payment_successful_result', $result, $order_id));
        }

        return $result;
    }


    // process refund
    public function process_refund($order_id, $amount = null, $reason = null)
    {
        error_log("process refund");
        $refund_items = json_decode(stripcslashes($_REQUEST['line_item_qtys']), true);

        $order = wc_get_order($order_id);
        $bill_id = $order->get_transaction_id();

        // Generated refund transaction ID.
        try {
            $refund_id = '';
            $refund_id = apply_filters('woocommerce_payment_rf_client_get_refund_id', $refund_id);
        } catch (Exception $exception) {
            return new WP_Error(
                $exception->getCode(),
                $exception->getMessage(),
                $exception
            );
        }

        // Refund transaction.
        try {
            $receipt = [
                'receiptNumber' => $bill_id,
                'customer' => array_filter(['email' => $order->get_billing_email()]),
                'items' => array()
            ];

            foreach ($order->get_items() as $item_id => $item) {
                foreach ($refund_items as $refund_item_id => $refund_item_q) {
                    if ($item_id == $refund_item_id) {
                        $item = [
                            'name' => $item->get_name(),
                            'quantity' => $refund_item_q,
                            'price' => $item->get_product()->get_price(),
                            'amount' => $refund_item_q * $item->get_product()->get_price(),
                            'vatType' => $this->vat
                        ];
                        $receipt['items'][] = $item;
                        break;
                    }
                }
            }

            $refund = '';
            $refund = apply_filters('woocommerce_payment_rf_client_post_order_refund', $refund, $bill_id, $refund_id, $amount, $receipt);

            $this->log(
                'Create bill refund',
                [
                    'bill_id' => $bill_id,
                    'refund_id' => $refund_id,
                    'amount' => $amount,
                    'currency' => $order->get_currency(),
                    'refund' => $refund,
                ]
            );
        } catch (Exception $exception) {
            return new WP_Error(
                $exception->getCode(),
                $exception->getMessage(),
                $exception
            );
        }

        // Process result.
        switch ($refund['code']) {
            case 'SUCCESS':
                $order->add_order_note('Выполнен возврат', $refund_id);
                return true;
        }
        return false;
    }
}
