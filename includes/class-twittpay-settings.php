<?php

if (!defined('ABSPATH')) {
    exit;
}

use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Helpers\Helper;

class TwittPay_FC_Settings extends BaseGatewaySettings
{
    public $settings;
    public $methodHandler = 'fluent_cart_payment_settings_twittpay';

    public function __construct()
    {
        parent::__construct();

        $settings = $this->getCachedSettings();
        $defaults = static::getDefaults();

        if (!$settings || !is_array($settings) || empty($settings)) {
            $settings = $defaults;
        } else {
            $settings = wp_parse_args($settings, $defaults);
        }

        $this->settings = $settings;
    }

    public static function getDefaults()
    {
        return [
            'is_active'    => 'no',
            'payment_mode' => 'live',
            'api_key'      => '',
        ];
    }

    public function isActive(): bool
    {
        return isset($this->settings['is_active']) && $this->settings['is_active'] == 'yes';
    }

    public function get($key = '')
    {
        if ($key && isset($this->settings[$key])) {
            return $this->settings[$key];
        }
        return $this->settings;
    }

    public function getMode()
    {
        return 'live';
    }

    public function getApiKey()
    {
        $key = $this->get('api_key');
        return $key ? Helper::decryptKey($key) : '';
    }
}
