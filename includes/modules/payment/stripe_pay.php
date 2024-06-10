<?php

class stripe_pay
{
    /**
     * $_check is used to check the configuration key set up
     * @var int
     */
    protected $_check;
    /**
     * $code determines the internal 'code' name used to designate "this" payment module
     * @var string
     */
    public $code;
    /**
     * $description is a soft name for this payment method
     * @var string
     */
    public $description;
    /**
     * $email_footer is the text to me placed in the footer of the email
     * @var string
     */
    public $email_footer;
    /**
     * $enabled determines whether this module shows or not... during checkout.
     * @var boolean
     */
    public $enabled;
    /**
     * $order_status is the order status to set after processing the payment
     * @var int
     */
    public $order_status;
    /**
     * $title is the displayed name for this order total method
     * @var string
     */
    public $title;
    /**
     * $sort_order is the order priority of this payment module when displayed
     * @var int
     */
    public $sort_order;

    function __construct()
    {
        global $order, $psr4Autoloader;

        $this->autoloadSupportClasses($psr4Autoloader);
        $this->code = 'stripe_pay';
        $this->title = MODULE_PAYMENT_STRIPE_PAY_TEXT_TITLE;
        $this->description = MODULE_PAYMENT_STRIPE_PAY_TEXT_DESCRIPTION;
        $this->sort_order = defined('MODULE_PAYMENT_STRIPE_PAY_SORT_ORDER') ? MODULE_PAYMENT_STRIPE_PAY_SORT_ORDER : null;
        $this->enabled = (defined('MODULE_PAYMENT_STRIPE_PAY_STATUS') && MODULE_PAYMENT_STRIPE_PAY_STATUS == 'True');
        if (null === $this->sort_order) return false;
        if ((int)MODULE_PAYMENT_STRIPE_PAY_ORDER_STATUS_ID > 0) {
            $this->order_status = MODULE_PAYMENT_STRIPE_PAY_ORDER_STATUS_ID;
        }
        if (is_object($order)) $this->update_status();
        $this->email_footer = MODULE_PAYMENT_STRIPE_PAY_TEXT_EMAIL_FOOTER;
    }


    function update_status()
    {
        global $order, $db;

        if ($this->enabled && (int)MODULE_PAYMENT_STRIPE_PAY_ZONE > 0 && isset($order->billing['country']['id'])) {
            $check_flag = false;
            $check = $db->Execute("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_PAYMENT_STRIPE_PAY_ZONE . "' and zone_country_id = '" . (int)$order->billing['country']['id'] . "' order by zone_id");
            while (!$check->EOF) {
                if ($check->fields['zone_id'] < 1) {
                    $check_flag = true;
                    break;
                } elseif ($check->fields['zone_id'] == $order->billing['zone_id']) {
                    $check_flag = true;
                    break;
                }
                $check->MoveNext();
            }

            if ($check_flag == false) {
                $this->enabled = false;
            }
        }
    }

    public function selection(): array
    {
        global $order;
        $paymentCurrency = $order->info['currency'];
        $orderTotal = $order->info['total'] * 100;
        $postcode = $order->billing['postcode'];
        $country = $order->billing['country']['iso_code_2'];
        $publishableKey = $this->getPublishableKey();
        $secretKey = $this->getSecretKey();
        Stripe\Stripe::setApiKey($secretKey);
        $stripeAlwaysShowForm = true;
        $paymentIntent = Stripe\PaymentIntent::create([
            'amount' => $orderTotal,
            'currency' => $paymentCurrency,
        ]);
        $clientSecret = $paymentIntent->client_secret;
        $selection = [];
        $selection['id'] = $this->code;
        $selection['module'] = $this->title;
        $selection['fields'] = [
            [
                'title' =>
                    '<script>const stripePublishableKey = "' . $publishableKey . '";</script>' .
                    '<script>const stripeSecretKey = "' . $clientSecret . '";</script>',
                'field' =>
                    '<script>const stripeAlwaysShowForm  = "' . $stripeAlwaysShowForm . '"</script>' .
                    '<script>const stripePaymentAmount  = "' . $orderTotal . '"</script>' .
                    '<script>const stripePaymentCurrency = "' . $paymentCurrency . '"</script>' .
                    '<script>const stripeBillingPostcode = "' . $postcode . '"</script>' .
                    '<script>const stripeBillingCountry = "' . $country . '"</script>' .
                    '<input type="hidden" name="stripepay-payment-intent-id" id="stripepay-payment-intent-id" value="' . $paymentIntent->id . '">' .
                    '<script>' . file_get_contents(DIR_WS_MODULES . 'payment/stripe_pay/stripepay.paymentform.js') . '</script>' .
                    '<div id="stripepay-intent-payment-element" style="display: none">' .
                    '</div>' .
                    '<div id="stripepay-intent-error-message">' .
                    '</div>',
            ],
        ];

        return $selection;
    }

    function check()
    {
        global $db;
        if (!isset($this->_check)) {
            $check_query = $db->Execute("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_STRIPE_PAY_STATUS'");
            $this->_check = $check_query->RecordCount();
        }
        return $this->_check;
    }

    function install()
    {
        global $db, $messageStack;
        if (defined('MODULE_PAYMENT_STRIPE_PAY_STATUS')) {
            $messageStack->add_session('Stripe Pay module already installed.', 'error');
            zen_redirect(zen_href_link(FILENAME_MODULES, 'set=payment&module=stripe_pay', 'NONSSL'));
            return 'failed';
        }
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Check/Money Order Module', 'MODULE_PAYMENT_STRIPE_PAY_STATUS', 'True', 'Do you want to accept Check/Money Order payments?', '6', '1', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now());");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Sort order of display.', 'MODULE_PAYMENT_STRIPE_PAY_SORT_ORDER', '0', 'Sort order of display. Lowest is displayed first.', '6', '0', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Payment Zone', 'MODULE_PAYMENT_STRIPE_PAY_ZONE', '0', 'If a zone is selected, only enable this payment method for that zone.', '6', '2', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Order Status', 'MODULE_PAYMENT_STRIPE_PAY_ORDER_STATUS_ID', '0', 'Set the status of orders made with this payment module to this value', '6', '0', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");

        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Test or Live Mode', 'MODULE_PAYMENT_STRIPE_PAY_MODE', 'Test', '', '6', '1', 'zen_cfg_select_option(array(\'Test\', \'Live\'), ', now());");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Stripe live publishable key', 'MODULE_PAYMENT_STRIPE_PAY_LIVE_PUB_KEY', '', '', '6', '', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Stripe test publishable key', 'MODULE_PAYMENT_STRIPE_PAY_TEST_PUB_KEY', '', '', '6', '', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Stripe live secret key', 'MODULE_PAYMENT_STRIPE_PAY_LIVE_SECRET_KEY', '', '', '6', '', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Stripe test secret key', 'MODULE_PAYMENT_STRIPE_PAY_TEST_SECRET_KEY', '', '', '6', '', now())");
    }

    function remove()
    {
        global $db;
        $db->Execute("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");
    }

    function keys()
    {
        return array('MODULE_PAYMENT_STRIPE_PAY_STATUS', 'MODULE_PAYMENT_STRIPE_PAY_ZONE', 'MODULE_PAYMENT_STRIPE_PAY_ORDER_STATUS_ID', 'MODULE_PAYMENT_STRIPE_PAY_SORT_ORDER', 'MODULE_PAYMENT_STRIPE_PAY_LIVE_PUB_KEY', 'MODULE_PAYMENT_STRIPE_PAY_TEST_PUB_KEY', 'MODULE_PAYMENT_STRIPE_PAY_LIVE_SECRET_KEY', 'MODULE_PAYMENT_STRIPE_PAY_TEST_SECRET_KEY', 'MODULE_PAYMENT_STRIPE_PAY_MODE');
    }


    protected function checkConfigureStatus(): bool
    {
        $configureStatus = true;
        $toCheck = 'LIVE';
        if ($this->getDefine('MODULE_PAYMENT_%%_MODE') == 'Test') {
            $toCheck = 'TEST';
        }
        if ($this->getDefine('MODULE_PAYMENT_%%_' . $toCheck . '_PUB_KEY') == '' || $this->getDefine('MODULE_PAYMENT_%%_' . $toCheck . '_SECRET_KEY') == '') {
            $this->configureErrors[] = '(not configured - needs publishable and secret key)';
            $configureStatus = false;
        }
        return $configureStatus;
    }


    public function pre_confirmation_check()
    {
        if (!isset($_POST['stripepay-payment-intent-id'])) {
            zen_redirect(zen_href_link('checkout_payment', '', 'SSL'));
        }
    }

    public function confirmation()
    {
        $stripePaymentIntentId = htmlspecialchars($_POST['stripepay-payment-intent-id']);
        return [
            'title' => '<input type="hidden" name="stripepay-payment-intent-id" value="' . $stripePaymentIntentId . '">',
        ];
    }

    public function before_process()
    {
        // require_once DIR_WS_MODULES . 'payment/stripe_pay/stripe-php-13.15.0/init.php';
        $secretKey = $this->getSecretKey();
        Stripe\Stripe::setApiKey($secretKey);
        $paymentIntentId = $_POST['paymentIntentId'] ?? null;
        if (!$paymentIntentId) {
            return false;
        }
        try {
            // Retrieve the PaymentIntent from Stripe
            $paymentIntent = Stripe\PaymentIntent::retrieve($paymentIntentId);

            // Confirm the payment intent to finalize the payment
            $paymentIntent->confirm();

            // Check the status of the PaymentIntent
            if ($paymentIntent->status == 'succeeded') {
                // Payment was successful
            } else {
                // Payment did not succeed
                echo "<h1>Payment Failed</h1>";
                echo "<p>Payment status: " . $paymentIntent->status . "</p>";
                echo "<p>There was an issue with your payment. Please try again or contact support.</p>";
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Handle error from Stripe API
            echo "<h1>Error</h1>";
            echo "<p>" . $e->getMessage() . "</p>";
        }

    }

    protected function getPublishableKey(): string
    {
        $toCheck = 'LIVE';
        if ($this->getDefine('MODULE_PAYMENT_%%_MODE') == 'Test') {
            $toCheck = 'TEST';
        }
        return $this->getDefine('MODULE_PAYMENT_%%_' . $toCheck . '_PUB_KEY');
    }

    protected function getSecretKey(): string
    {
        $toCheck = 'LIVE';
        if ($this->getDefine('MODULE_PAYMENT_%%_MODE') == 'Test') {
            $toCheck = 'TEST';
        }
        return $this->getDefine('MODULE_PAYMENT_%%_' . $toCheck . '_SECRET_KEY');
    }

    protected function autoloadSupportClasses($psr4Autoloader): void
    {
        $psr4Autoloader->addPrefix('Stripe', DIR_FS_CATALOG . DIR_WS_MODULES . 'payment/stripe_pay/stripe-php-13.15.0/lib/');
    }


    //////////////////////////////////////////////////////
    protected function getDefine(string $defineTemplate, $default = null): mixed
    {
        $define = $this->buildDefine($defineTemplate);
        if (!defined($define)) {
            return $default;
        }
        return constant($define);
    }

    /**
     * @param $defineTemplate
     * @return string
     */
    protected function buildDefine(string $defineTemplate): string
    {
        return str_replace('%%', strtoupper($this->code), $defineTemplate);
    }


    /// Unused Methods
    public function javascript_validation(): string
    {
        return false;
    }

    public function process_button()
    {
        return false;
    }

    public function clear_payments()
    {

    }

    public function after_order_create($orders_id)
    {

    }

    public function after_process()
    {
        return false;
    }

    public function admin_notification($zf_order_id)
    {

    }
}
