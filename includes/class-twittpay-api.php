<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin client for the TwittPay API.
 *   POST /api/payment/create  -> payment_url
 *   POST /api/payment/verify  -> status, amount, metadata
 * Auth header: API-KEY (the Brand Key).
 */
class TwittPay_FC_API
{
    const ENDPOINT = 'https://checkout.twittpay.com';

    /** @var TwittPay_FC_Settings */
    private $settings;

    public function __construct(TwittPay_FC_Settings $settings)
    {
        $this->settings = $settings;
    }

    private function request($path, $body)
    {
        $response = wp_remote_post(self::ENDPOINT . $path, [
            'timeout' => 30,
            'headers' => [
                'API-KEY'      => $this->settings->getApiKey(),
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400 || !is_array($data)) {
            return [
                'success' => false,
                'message' => (is_array($data) && !empty($data['message'])) ? $data['message'] : __('TwittPay API request failed.', 'twittpay-for-fluentcart'),
            ];
        }

        return ['success' => true, 'data' => $data];
    }

    public function createPayment(array $payload)
    {
        $result = $this->request('/api/payment/create', $payload);
        if (!$result['success']) {
            return $result;
        }
        if (empty($result['data']['payment_url'])) {
            return [
                'success' => false,
                'message' => !empty($result['data']['message']) ? $result['data']['message'] : __('No payment URL returned by TwittPay.', 'twittpay-for-fluentcart'),
            ];
        }
        return ['success' => true, 'payment_url' => $result['data']['payment_url']];
    }

    public function verifyPayment($transactionId)
    {
        return $this->request('/api/payment/verify', ['transaction_id' => $transactionId]);
    }

    /** Verify returns metadata as a JSON string; make it an array. */
    public static function decodeMetadata($data)
    {
        $meta = isset($data['metadata']) ? $data['metadata'] : [];
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }
        return is_array($meta) ? $meta : [];
    }
}
