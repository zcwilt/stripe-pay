<?php

use Carbon\Carbon;
use Zencart\ModuleSupport\PaymentModuleAbstract;
use Zencart\ModuleSupport\PaymentModuleContract;
use Zencart\ModuleSupport\PaymentModuleConcerns;

class stripe_pay extends PaymentModuleAbstract implements PaymentModuleContract
{
    use PaymentModuleConcerns;

    protected const CURRENT_VERSION = '1.0.0-alpha';
    public string $MODULE_ID = 'STRIPE_PAY';
    public string $code = 'stripe_pay';

    protected function addCustomConfigurationKeys(): array
    {

        $configKeys = [];
        $key = $this->buildDefine('MODULE_PAYMENT_%%_ORDER_STATUS_ID');
        $configKeys[$key] = [
            'configuration_value' => '2',
            'configuration_title' => 'Completed Order Status',
            'configuration_description' => 'Set the status of orders whose payment has been successfully <em>captured</em> to this status.<br>Recommended: <b>Processing[2]</b><br>',
            'configuration_group_id' => 6,
            'sort_order' => 1,
            'date_added' => Carbon::now(),
            'set_function' => 'zen_cfg_pull_down_order_statuses(',
            'use_function' => 'zen_get_order_status_name',
        ];
        $key = $this->buildDefine('MODULE_PAYMENT_%%_LIVE_PUB_KEY');
        $configKeys[$key] = [
            'configuration_value' => '',
            'configuration_title' => 'Stripe live publishable key',
            'configuration_description' => 'Your live publishable key.',
            'configuration_group_id' => 6,
            'sort_order' => 1,
            'date_added' => Carbon::now(),
        ];
        $key = $this->buildDefine('MODULE_PAYMENT_%%_LIVE_SECRET_KEY');
        $configKeys[$key] = [
            'configuration_value' => '',
            'configuration_title' => 'Stripe live secret key',
            'configuration_description' => 'Your live secret key.',
            'configuration_group_id' => 6,
            'sort_order' => 1,
            'date_added' => Carbon::now(),
        ];
        $key = $this->buildDefine('MODULE_PAYMENT_%%_TEST_PUB_KEY');
        $configKeys[$key] = [
            'configuration_value' => '',
            'configuration_title' => 'Stripe test publishable key',
            'configuration_description' => 'Your test publishable key.',
            'configuration_group_id' => 6,
            'sort_order' => 1,
            'date_added' => Carbon::now(),
        ];
        $key = $this->buildDefine('MODULE_PAYMENT_%%_TEST_SECRET_KEY');
        $configKeys[$key] = [
            'configuration_value' => '',
            'configuration_title' => 'Stripe test key',
            'configuration_description' => 'Your test secret key.',
            'configuration_group_id' => 6,
            'sort_order' => 1,
            'date_added' => Carbon::now(),
        ];
        $key = $this->buildDefine('MODULE_PAYMENT_%%_MODE');
        $configKeys[$key] = [
            'configuration_value' => 'Test',
            'configuration_title' => 'Test or Live mode',
            'configuration_description' => 'Whether to process transactions in test or live mode',
            'configuration_group_id' => 6,
            'sort_order' => 1,
            'date_added' => Carbon::now(),
            'set_function' => "zen_cfg_select_option(array('Test', 'Live'), ",
        ];
        return $configKeys;
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

    public function selection(): array
    {
        global $order;
        //require_once DIR_WS_MODULES . 'payment/stripe_pay/stripe-php-13.15.0/init.php';
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
}
