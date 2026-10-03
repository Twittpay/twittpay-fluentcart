<?php

if (!defined('ABSPATH')) {
    exit;
}

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Order;
use FluentCart\App\Helpers\Status;

class TwittPay_FC_Gateway extends AbstractPaymentGateway
{
    public array $supportedFeatures = ['payment', 'webhook'];

    /** @var TwittPay_FC_API */
    private $api;

    public function __construct()
    {
        parent::__construct(new TwittPay_FC_Settings());
        $this->api = new TwittPay_FC_API($this->settings);
    }

    public function boot()
    {
        // The confirm ajax handler is registered in the main plugin file.
    }

    public function meta(): array
    {
        return [
            'title'              => __('TwittPay (bKash/Nagad/Rocket/Upay)', 'twittpay-for-fluentcart'),
            'route'              => TWITTPAY_FC_SLUG,
            'slug'               => TWITTPAY_FC_SLUG,
            'label'              => 'TwittPay',
            'admin_title'        => 'TwittPay',
            'description'        => __('Accept bKash, Nagad, Rocket and Upay payments with automatic SMS verification.', 'twittpay-for-fluentcart'),
            'logo'               => TWITTPAY_FC_URL . 'assets/images/logo.svg',
            'icon'               => TWITTPAY_FC_URL . 'assets/images/icon.svg',
            'brand_color'        => '#0B71F8',
            'status'             => $this->settings->get('is_active') === 'yes',
            'upcoming'           => false,
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function has(string $feature): bool
    {
        return in_array($feature, $this->supportedFeatures, true);
    }

    public function fields(): array
    {
        return [
            'api_key' => [
                'value'       => '',
                'label'       => __('Brand Key', 'twittpay-for-fluentcart'),
                'type'        => 'password',
                'placeholder' => __('Paste your TwittPay Brand Key', 'twittpay-for-fluentcart'),
            ],
            'webhook_info' => [
                'value' => '<code>' . esc_url(home_url('/?fluent-cart=fct_payment_listener_ipn&method=' . TWITTPAY_FC_SLUG)) . '</code>'
                    . '<p class="description">' . esc_html__('TwittPay sends this address automatically with every payment, so a payment approved later still completes the order. Nothing to configure.', 'twittpay-for-fluentcart') . '</p>',
                'label' => __('Webhook URL', 'twittpay-for-fluentcart'),
                'type'  => 'html_attr',
            ],
        ];
    }

    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        if (isset($data['api_key'])) {
            $old = isset($oldSettings['api_key']) ? $oldSettings['api_key'] : '';
            // Encrypt only when the value changed, so saving again never double-encrypts.
            if ($data['api_key'] !== '' && $data['api_key'] !== $old) {
                $data['api_key'] = Helper::encryptKey($data['api_key']);
            }
        }
        return $data;
    }

    public static function validateSettings($data): array
    {
        return $data;
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle'  => 'twittpay-fc-checkout',
                'src'     => TWITTPAY_FC_URL . 'assets/js/checkout.js',
                'version' => TWITTPAY_FC_VERSION,
            ],
        ];
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        $order       = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $customer    = $paymentInstance->order->customer;

        $fullName = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
        if ($fullName === '') {
            $fullName = __('Customer', 'twittpay-for-fluentcart');
        }

        $payload = [
            'cus_name'    => $fullName,
            'cus_email'   => $customer->email ?? '',
            'amount'      => (string) number_format($transaction->total / 100, 2, '.', ''),
            'metadata'    => [
                'order_id'   => (string) $order->id,
                'order_uuid' => (string) $order->uuid,
                'fc_txn'     => (string) $transaction->uuid,
            ],
            'success_url' => add_query_arg([
                'action' => 'twittpay_fc_confirm',
                'fc_txn' => $transaction->uuid,
            ], admin_url('admin-ajax.php')),
            'cancel_url'  => $this->getCancelUrl(),
            'webhook_url' => home_url('/?fluent-cart=fct_payment_listener_ipn&method=' . TWITTPAY_FC_SLUG),
        ];

        $result = $this->api->createPayment($payload);

        if (empty($result['success'])) {
            return [
                'status'  => 'failed',
                'message' => $result['message'] ?? __('Could not start the TwittPay payment.', 'twittpay-for-fluentcart'),
            ];
        }

        return [
            'redirect_to' => $result['payment_url'],
            'status'      => 'success',
            'message'     => __('Redirecting to TwittPay...', 'twittpay-for-fluentcart'),
        ];
    }

    public function getOrderInfo(array $data)
    {
        wp_send_json([
            'status'       => 'success',
            'payment_args' => [],
            'message'      => __('Order info retrieved', 'twittpay-for-fluentcart'),
        ], 200);
    }

    /** The customer returns here after paying or cancelling. */
    public function confirmPayment()
    {
        $fcTxn         = sanitize_text_field($_REQUEST['fc_txn'] ?? '');
        $transactionId = sanitize_text_field($_REQUEST['transactionId'] ?? '');

        $transaction = OrderTransaction::query()->where('uuid', $fcTxn)->first();

        if (!$transaction) {
            wp_die(esc_html__('Invalid or expired transaction.', 'twittpay-for-fluentcart'));
        }

        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            wp_redirect($this->getSuccessUrl($transaction));
            exit;
        }

        if ($transactionId === '') {
            wp_redirect($this->getCancelUrl());
            exit;
        }

        $this->verifyAndComplete($transactionId, $transaction);
        $transaction->refresh();

        wp_redirect($this->getSuccessUrl($transaction));
        exit;
    }

    /** Server-to-server webhook. Always re-verified, never trusted as sent. */
    public function handleIPN()
    {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data)) {
            $data = $_POST;
        }

        $transactionId = '';
        foreach (['transactionId', 'transaction_id'] as $k) {
            if (!empty($data[$k])) {
                $transactionId = sanitize_text_field($data[$k]);
                break;
            }
        }
        if ($transactionId === '') {
            wp_send_json(['status' => 'ignored'], 200);
            return;
        }

        $verified = $this->api->verifyPayment($transactionId);
        if (empty($verified['success'])) {
            wp_send_json(['status' => 'verification_failed'], 200);
            return;
        }

        $meta  = TwittPay_FC_API::decodeMetadata($verified['data']);
        $fcTxn = $meta['fc_txn'] ?? null;
        if (!$fcTxn) {
            wp_send_json(['status' => 'no_transaction_ref'], 200);
            return;
        }

        $transaction = OrderTransaction::query()->where('uuid', $fcTxn)->first();
        if ($transaction) {
            $this->completeTransaction($transaction, $verified['data'], $transactionId);
        }

        wp_send_json(['status' => 'processed'], 200);
    }

    private function verifyAndComplete($transactionId, $transaction)
    {
        $verified = $this->api->verifyPayment($transactionId);
        if (empty($verified['success'])) {
            return;
        }
        $this->completeTransaction($transaction, $verified['data'], $transactionId);
    }

    private function completeTransaction($transaction, array $data, $transactionId)
    {
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return; // already processed, never credit twice
        }

        if (strtoupper(trim((string) ($data['status'] ?? ''))) !== 'COMPLETED') {
            return;
        }

        // The payment must belong to this exact transaction.
        $meta = TwittPay_FC_API::decodeMetadata($data);
        if (($meta['fc_txn'] ?? '') !== (string) $transaction->uuid) {
            return;
        }

        // And it must cover the amount.
        $expected = $transaction->total / 100;
        $paid     = (float) ($data['amount'] ?? 0);
        if ($paid + 0.01 < $expected) {
            return;
        }

        $transaction->fill([
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'vendor_charge_id' => $transactionId,
        ]);
        $transaction->save();

        $order = Order::query()->find($transaction->order_id);
        if ($order) {
            (new StatusHelper($order))->syncOrderStatuses($transaction);
        }
    }
}
