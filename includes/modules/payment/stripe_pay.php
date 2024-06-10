<?php

require_once DIR_FS_CATALOG . DIR_WS_MODULES . 'payment/stripe_pay/ModuleSupport/PaymentModuleAbstract.php';
require_once DIR_FS_CATALOG . DIR_WS_MODULES . 'payment/stripe_pay/ModuleSupport/PaymentModuleContract.php';
require_once DIR_FS_CATALOG . DIR_WS_MODULES . 'payment/stripe_pay/ModuleSupport/PaymentModuleConcerns.php';
require_once DIR_FS_CATALOG . DIR_WS_MODULES . 'payment/stripe_pay/ModuleSupport/GeneralModuleConcerns.php';

use Carbon\Carbon;
use Zencart\ModuleSupport\PaymentModuleAbstract;
use Zencart\ModuleSupport\PaymentModuleContract;
use Zencart\ModuleSupport\PaymentModuleConcerns;


class stripe_pay extends PaymentModuleAbstract implements PaymentModuleContract
{
    use PaymentModuleConcerns;

    public string $code = 'stripe_pay';

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
        $stripeAlwaysShowForm = false;
        $setupIntent = Stripe\SetupIntent::create([
            'payment_method_types' => ['card'],
        ]);
        $clientSecret = $setupIntent->client_secret;
        $selection = [];
        $selection['id'] = $this->code;
        $selection['module'] = $this->title;
        $selection['fields'] = [
            [
                'title' =>
                    '<script>const stripeAlwaysShowForm = false;</script>' .
                    '<script>const stripePublishableKey = "' . $publishableKey . '";</script>' .
                    '<script>const stripeSecretKey = "' . $clientSecret . '";</script>' .
                    '<script>const stripeAlwaysShowForm  = "' . $stripeAlwaysShowForm . '"</script>' .
                    '<script>const stripePaymentAmount  = "' . $orderTotal . '"</script>' .
                    '<script>const stripePaymentCurrency = "' . $paymentCurrency . '"</script>' .
                    '<script>const stripeBillingPostcode = "' . $postcode . '"</script>' .
                    '<script>const stripeBillingCountry = "' . $country . '"</script>' .
                    '',
                'field' =>
                    '<input type="hidden" name="stripepay-setup-intent-id" id="stripepay-setup-intent-id" value="' . $setupIntent->id . '">' .
                    '<script>' . file_get_contents(DIR_WS_MODULES . 'payment/stripe_pay/stripepay.paymentform.js') . '</script>' .
                    '<div id="stripepay-intent-payment-element" style="display: none">' .
                    '</div>' .
                    '<div id="stripepay-intent-error-message" class="alert"' .
                    '</div>' .
                    '',
            ],
        ];
        return $selection;
    }

    public function pre_confirmation_check()
    {
        if (!isset($_POST['stripepay-setup-intent-id'])) {
            zen_redirect(zen_href_link('checkout_payment', '', 'SSL'));
        }
    }

    public function process_button()
    {
        $process_button_string = zen_draw_hidden_field('stripepay-setup-intent-id', $_POST['stripepay-setup-intent-id']) ;
        return $process_button_string;
    }

    public function before_process()
    {
        $secretKey = $this->getSecretKey();
        Stripe\Stripe::setApiKey($secretKey);
        $setupIntentId = $_POST['stripepay-setup-intent-id'] ?? null;
        if (!$setupIntentId) {
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT));
        }
        try {
            // Retrieve the PaymentIntent from Stripe
            $setupIntent = Stripe\SetupIntent::retrieve($setupIntentId);

            //dd($setupIntent);
            // Check the status of the PaymentIntent
            if ($setupIntent->status === 'succeeded') {
                $this->handleSetupSuccess($setupIntent);
            } else {
                $this->handleSetupFailure($setupIntent);
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            dd($e);
            // Handle error from Stripe API
            echo "<h1>Error</h1>";
            echo "<p>" . $e->getMessage() . "</p>";
        }
    }

    protected function handleSetupSuccess($setupIntent)
    {
        global $order;

        //dd($order);

        $customer = Stripe\Customer::create([
            'email' => $order->customer['email_address'],
        ]);

        //dd($customer);

        $paymentMethod = Stripe\PaymentMethod::retrieve($setupIntent->payment_method);

        //dd($paymentMethod);

        if ($paymentMethod->customer) {
            // If the payment method is already attached to a customer, use that customer
            $customer_id = $paymentMethod->customer;
        } else {
            // Attach the payment method to the newly created customer
            $paymentMethod->attach([
                'customer' => $customer->id,
            ]);
            $customer_id = $customer->id;
        }
        //dd($customer->id);

        $paymentIntent = Stripe\PaymentIntent::create([
            'amount' => $order->info['total'] * 100,
            'currency' => $order->info['currency'],
            'payment_method' => $setupIntent->payment_method,
            'customer' => $customer_id,
            'confirm' => true,
            'return_url' => zen_href_link(FILENAME_CHECKOUT_CONFIRMATION, '', 'SSL', true),
        ]);

        if ($paymentIntent->status === 'succeeded') {
            $this->handlePaymentSuccess($paymentIntent);
        } else {
            $this->handlePaymentFailure($paymentIntent);
        }
    }

    protected function handleSetupFailure($setupIntent)
    {
        dump('handleSetupFailure');
        dd($setupIntent);
    }

    protected function handlePaymentSuccess($paymentIntent)
    {
        //dump('handlePaymentSuccess');
        //dd($paymentIntent);
    }

    protected function handlePaymentFailure($paymentIntent)
    {
        dump('handlePaymentFailure');
        dd($paymentIntent);
    }



    protected function moduleAutoloadSupportClasses($psr4Autoloader): void
    {
        $psr4Autoloader->addPrefix('Stripe', DIR_FS_CATALOG . DIR_WS_MODULES . 'payment/stripe_pay/stripe-php-13.15.0/lib/');
    }



    protected function checkConfigureStatus(): bool
    {
        $configureStatus = true;
        $toCheck = 'LIVE';
        if ($this->getDefine('MODULE_PAYMENT_%%_MODE') == 'Test') {
            $toCheck = 'TEST';
        }
        if ($this->getDefine('MODULE_PAYMENT_%%_' . $toCheck . '_PUB_KEY') == '' || $this->getDefine('MODULE_PAYMENT_%%_' . $toCheck . '_SECRET_KEY') == '') {
            $this->configureErrors[] = sprintf('(not configured - needs %s publishable and secret key)', $toCheck);
            $configureStatus = false;
        }
        return $configureStatus;
    }

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

}
