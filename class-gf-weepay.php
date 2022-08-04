<?php

require_once 'weepay-sdk/weepayBootstrap.php';

GFForms::include_payment_addon_framework();

class GFWeepay extends GFPaymentAddOn
{
    /**
     * weepay plugin config key ID and key secret
     */
    const GF_WEEPAY_BAYI = 'gf_weepay_bayi';
    const GF_WEEPAY_API = 'gf_weepay_key';
    const GF_WEEPAY_SECRET = 'gf_weepay_secret';
    const GF_WEEPAY_WEBHOOK_ENABLED_AT = 'gf_weepay_webhook_enable_at';

    /**
     * weepay API attributes
     */
    const WEEPAY_ORDER_ID = 'weepay_order_id';
    const WEEPAY_PAYMENT_ID = 'weepay_payment_id';
    const CAPTURE = 'capture';
    const AUTHORIZE = 'authorize';
    const ORDER_PAID = 'order.paid';

    const COOKIE_DURATION = 86400;

    const CUSTOMER_FIELDS_NAME = 'name';
    const CUSTOMER_FIELDS_EMAIL = 'email';
    const CUSTOMER_FIELDS_CONTACT = 'contact';
    const CUSTOMER_FIELDS_INS = 'installement';

    protected $_version = GF_WEEPAY_VERSION;

    protected $_min_gravityforms_version = '1.9.3';

    protected $_slug = 'weepay-gravity-forms';

    protected $_path = 'gfweepay/weepay.php';

    protected $_full_path = __FILE__;

    protected $_url = 'https://weepay.co';

    protected $_title = 'Gravity Forms weepay Add-On';

    protected $_short_title = 'weepay';

    protected $_supports_callbacks = true;

    public $_async_feed_processing = false;

    // --------------------------------------------- Permissions Start -------------------------------------------------

    protected $_capabilities_settings_page = 'gravityforms_weepay';

    protected $_capabilities_form_settings = 'gravityforms_weepay';

    protected $_capabilities_uninstall = 'gravityforms_weepay_uninstall';

    // --------------------------------------------- Permissions End ---------------------------------------------------

    protected $_enable_rg_autoupgrade = true;

    private static $_instance = null;

    protected $supportedWebhookEvents = array(
        'order.paid',
    );

    protected $defaultWebhookEvents = array(
        'order.paid' => true,
    );

    public static function get_instance()
    {
        if (self::$_instance === null) {
            self::$_instance = new GFWeepay();
        }

        return self::$_instance;
    }

    public function init_frontend()
    {
        parent::init_frontend();
        add_action('gform_after_submission', array($this, 'generate_weepay_order'), 10, 2);
    }

    public function plugin_settings_fields()
    {

        return array(
            array(
                'title' => 'weepay Settings',
                'fields' => array(
                    array(
                        'name' => self::GF_WEEPAY_BAYI,
                        'label' => esc_html__('weepay BayiId', $this->_slug),
                        'type' => 'text',
                        'class' => 'medium',
                        // 'feedback_callback' => array($this, 'auto_enable_webhook'),
                    ),
                    array(
                        'name' => self::GF_WEEPAY_API,
                        'label' => esc_html__('weepay API key', $this->_slug),
                        'type' => 'text',
                        'class' => 'medium',
                    ),
                    array(
                        'name' => self::GF_WEEPAY_SECRET,
                        'label' => esc_html__('weepay Secret key', $this->_slug),
                        'type' => 'text',
                        'class' => 'medium',
                    ),

                    array(
                        'type' => 'save',
                        'messages' => array(
                            'success' => esc_html__('Settings have been updated.', $this->_slug),
                        ),
                    ),
                ),
            ),
        );
    }

    public function get_customer_fields($form, $feed, $entry)
    {
        $fields = array();

        $billing_fields = $this->billing_info_fields();

        foreach ($billing_fields as $field) {
            $field_id = $feed['meta']['billingInformation_' . $field['name']];

            $value = $this->get_field_value($form, $entry, $field_id);

            $fields['billing'][$field['name']] = $value;
        }

        $customerFields = $this->customer_info_fields();
        foreach ($customerFields as $field) {
            $field_id = $feed['meta']['customerInformation_' . $field['name']];

            $value = $this->get_field_value($form, $entry, $field_id);

            $fields['customer'][$field['name']] = $value;
        }

        $otherFields = $this->other_info_fields();
        foreach ($otherFields as $field) {
            $field_id = $feed['meta']['otherInformation_' . $field['name']];

            $value = $this->get_field_value($form, $entry, $field_id);

            $fields['other'][$field['name']] = $value;
        }

        $shippingFields = $this->shipping_info_fields();
        foreach ($shippingFields as $field) {
            $field_id = $feed['meta']['shippingInformation_' . $field['name']];

            $value = $this->get_field_value($form, $entry, $field_id);
            if (!empty($value)) {
                # code...
                $fields['shipping'][$field['name']] = $value;
            }
        }
        return $fields;
    }

    public function callback()
    {

        if ($_POST['paymentStatus'] == true) {
            $payment_id = $_POST['paymentId'];
            $entry = GFAPI::get_entry($_POST['orderId']);
            $action = array(
                'id' => $payment_id,
                'type' => 'fail_payment',
                'transaction_id' => $payment_id,
                'amount' => $entry['payment_amount'],
                'payment_method' => 'weepay',
                'entry_id' => $entry['id'],
                'error' => 'Payment Failed',
            );
        }

        $result = $this->GetOrderData($payment_id);
        if ($result->paymentStatus == 'SUCCESS' && $result->orderId == $entry['id']) {
            $action['type'] = 'complete_payment';
            $action['amount'] = $result->price;
            $action['error'] = null;

        } else {

            $action['error'] = $_POST['message'];
        }

        return $action;
    }

    public function post_callback($callback_action, $callback_result)
    {
        if (is_wp_error($callback_action) || !$callback_action) {
            return false;
        }

        $entry = null;

        $feed = null;

        $ref_id = url_to_postid(wp_get_referer());
        $ref_title = $ref_id > 0 ? get_the_title($ref_id) : "Home";
        $ref_url = get_home_url();
        $form_id = 0;

        if (isset($callback_action['entry_id']) === true) {
            $entry = GFAPI::get_entry($callback_action['entry_id']);
            $feed = $this->get_payment_feed($entry);
            $transaction_id = rgar($callback_action, 'transaction_id');
            $amount = rgar($callback_action, 'amount');
            $status = rgar($callback_action, 'type');
            $ref_url = $entry['source_url'];
            $form_id = $entry['form_id'];
        }

        if ($status === 'complete_payment') {
            do_action('gform_weepay_complete_payment', $callback_action['transaction_id'], $callback_action['amount'], $entry, $feed);
        } else {
            do_action('gform_weepay_fail_payment', $entry, $feed);
        }

        $form = GFAPI::get_form($form_id);

        if (!class_exists('GFFormDisplay')) {
            require_once GFCommon::get_base_path() . '/form_display.php';
        }

        $confirmation = GFFormDisplay::handle_confirmation($form, $entry, false);

        if (is_array($confirmation) && isset($confirmation['redirect'])) {
            header("Location: {$confirmation['redirect']}"); // nosemgrep : php.lang.security.non-literal-header.non-literal-header
            exit;
        }

        ?>

<head>
    <link rel="stylesheet" type="text/css" href="<?php echo plugin_dir_url(__FILE__) . 'assets/css/style.css'; ?>">
</head>

<body>
    <div class="invoice-box">
        <table cellpadding="0" cellspacing="0">
            <tr class="top">
                <td colspan="2">
                    <table>
                        <tr>
                            <td class="title"> <img
                                    src="<?php echo plugin_dir_url(__FILE__) . 'assets/img/logo.png'; ?>"
                                    style="width:100%; max-width:300px;margin-left:30%"> </td>
                        </tr>
                    </table>
                </td>
            </tr>
            <tr class="heading">
                <td> Payment Details </td>
                <td> Value </td>
            </tr>
            <tr class="item">
                <td> Status </td>
                <td> <?php echo $status == 'complete_payment' ? "Success ✅" : "Fail 🚫"; ?> </td>
            </tr>
            <?php
if ($status == 'complete_payment') {
            ?>
            <tr class="item">
                <td> Transaction Id </td>
                <td> # <?php echo $transaction_id; ?> </td>
            </tr>
            <?php
} else {
            ?>
            <tr class="item">
                <td> Transaction Error</td>
                <td> <?php echo $callback_action['error']; ?> </td>
            </tr>
            <?php
}
        ?>
            <tr class="item">
                <td> Transaction Date </td>
                <td> <?php echo date("F j, Y"); ?> </td>
            </tr>
            <tr class="item last">
                <td> Amount </td>
                <td> <?php echo $amount ?> </td>
            </tr>
        </table>
        <p style="font-size:17px;text-align:center;">Go back to the <strong><a
                    href="<?php echo $ref_url; ?>"><?php echo $ref_title; ?></a></strong> page. </p>
        <p style="font-size:17px;text-align:center;"><strong>Note:</strong> This page will automatically redirected to
            the <strong><?php echo $ref_title; ?></strong> page in <span id="rzp_refresh_timer"></span> seconds.</p>
        <progress style="margin-left: 40%;" value="0" max="10" id="progressBar"></progress>
        <div style="margin-left:22%; margin-top: 20px;">
            <?php echo $confirmation; ?>
        </div>
    </div>
</body>';
<script type="text/javascript">
setTimeout(function() {
    window.location.href = "<?php echo $ref_url; ?>"
}, 1e3 * rzp_refresh_time), setInterval(function() {
    rzp_actual_refresh_time > 0 ? (rzp_actual_refresh_time--, document.getElementById("rzp_refresh_timer")
        .innerText = rzp_actual_refresh_time) : clearInterval(rzp_actual_refresh_time)
}, 1e3);
</script>
<?php

    }

    public function is_callback_valid()
    {
        // Will check if the return url is valid
        if (rgget('page') !== 'gf_weepay_callback') {
            return false;
        }

        return true;
    }
    public function get_menu_icon()
    {

        return $this->is_gravityforms_supported('2.5-beta-4') ? 'gform-icon--credit-card' : 'dashicons-admin-generic';

    }
    public function generate_weepay_order($entry, $form)
    {

        $feed = $this->get_payment_feed($entry);
        $submission_data = $this->get_submission_data($feed, $form, $entry);

        //Check if gravity form is executed without any payment
        if (!$feed || empty($submission_data['payment_amount'])) {
            return true;
        }
        //gravity form method to get value of payment_amount key from entry
        $paymentAmount = rgar($entry, 'payment_amount');

        if (empty($paymentAmount) === true) {
            $paymentAmount = GFCommon::get_order_total($form, $entry);
            gform_update_meta($entry['id'], 'payment_amount', $paymentAmount);
            $entry['payment_amount'] = $paymentAmount;
        }

        $siteLang = explode('_', get_locale());
        $locale = ($siteLang[0] == "tr") ? "tr" : "en";
        $currency = $entry['currency'];
        if ($currency == 'TRY') {
            $currency = "TL";
        }

        $weepayArray['Auth'] = array(
            'bayiId' => $this->get_plugin_setting(self::GF_WEEPAY_BAYI),
            'apiKey' => $this->get_plugin_setting(self::GF_WEEPAY_API),
            'secretKey' => $this->get_plugin_setting(self::GF_WEEPAY_SECRET),
        );

        $customerFields = $this->get_customer_fields($form, $feed, $entry);

        $weepayArrayData = array(
            'Customer' => [
                'customerId' => uniqid(),
                'customerName' => $customerFields['customer']['customerName'],
                'customerSurname' => $customerFields['customer']['customerSurname'],
                'gsmNumber' => $customerFields['customer']['gsmNumber'],
                'email' => $customerFields['customer']['email'],
                'identityNumber' => '11111111111',
                'city' => $customerFields['billing']['city'],
                'country' => $customerFields['billing']['country'],
            ],
            'BillingAddress' => [
                'contactName' => $customerFields['customer']['customerName'] . ' ' . $customerFields['customer']['customerSurname'],
                'address' => $customerFields['billing']['address'],
                'city' => $customerFields['billing']['city'],
                'country' => $customerFields['billing']['country'],
                'zipCode' => $customerFields['billing']['zipCode'],
            ],

        );
        if (isset($customerFields['other']['installmentNumber']) && is_int($customerFields['other']['installmentNumber'])) {
            $ins = $customerFields['other']['installmentNumber'];
        } else {
            $ins = 1;
        }

        if (isset($submission_data['card_expiration_date'][0])) {
            $expMonth = $submission_data['card_expiration_date'][0];
            $expMonthCount = strlen($expMonth);
            if ($expMonthCount == 1) {
                $expMonth = "0" . $expMonth;
            }
        }

        if (isset($submission_data['card_expiration_date'][1])) {
            $expYear = $submission_data['card_expiration_date'][1];
            $expYearCount = strlen($expYear);
            if ($expYearCount == 4) {
                $expYear = substr($expYear, -2);
            }
        }
        if (isset($submission_data['card_number'])) {
            $weepayArrayData['Data'] = array(
                'callBackUrl' => esc_url(site_url()) . '/?page=gf_weepay_callback', // add_query_arg('wc-api', 'WC_Gateway_Weepay', $order->get_checkout_order_received_url()),
                'paidPrice' => $this->priceParser(round($paymentAmount, 2)),
                'locale' => $locale,
                'ipAddress' => $_SERVER['REMOTE_ADDR'],
                'orderId' => $entry['id'],
                'currency' => $currency,
                'description' => $submission_data['form_title'],
                "cardHolderName" => $submission_data['card_name'],
                "cardNumber" => $submission_data['card_number'],
                "expireMonth" => $expMonth,
                "expireYear" => $expYear,
                "cvcNumber" => $submission_data['card_security_code'],
                "installmentNumber" => $ins,
                'paymentGroup' => 'PRODUCT',
                'paymentSource' => 'GRAVITYFORM|' . GFForms::$version . '|' . GF_WEEPAY_VERSION,
                'channel' => 'Module',
            );
            $endPointUrl = "https://api.weepay.co/Payment/PaymentRequestThreeD";
            $formType = "payment";
        } else {
            $weepayArrayData['Data'] = array(
                'callBackUrl' => esc_url(site_url()) . '/?page=gf_weepay_callback', // add_query_arg('wc-api', 'WC_Gateway_Weepay', $order->get_checkout_order_received_url()),
                'paidPrice' => $this->priceParser(round($paymentAmount, 2)),
                'locale' => $locale,
                'ipAddress' => $_SERVER['REMOTE_ADDR'],
                'orderId' => $entry['id'],
                'currency' => $currency,
                'description' => $submission_data['form_title'],

                'paymentGroup' => 'PRODUCT',
                'paymentSource' => 'GRAVITYFORM|' . GFForms::$version . '|' . GF_WEEPAY_VERSION,
                'channel' => 'Module',
            );
            $endPointUrl = "https://api.weepay.co/Payment/PaymentCreate";
            $formType = "sale";
        }

        if (isset($customerFields['shipping'])) {
            $weepayArrayData['ShippingAddress'] = [
                'contactName' => $customerFields['shipping']['contactName'],
                'address' => $customerFields['shipping']['address'],
                'city' => $customerFields['shipping']['city'],
                'country' => $customerFields['shipping']['country'],
                'zipCode' => $customerFields['shipping']['zipCode'],
            ];

            $ProductsBasket = $this->generateBasketItems($submission_data['line_items'], "PHYSICAL");
        } else {

            $ProductsBasket = $this->generateBasketItems($submission_data['line_items'], "VIRTUAL");
        }

        $weepayArrayData['Products'] = $ProductsBasket;
        $resultArray = array_merge($weepayArray, $weepayArrayData);

        $response = json_decode($this->curlPostExt(json_encode($resultArray), $endPointUrl, true), true);

        try {
            if ($response['status'] == 'success') {

                gform_update_meta($entry['id'], self::WEEPAY_ORDER_ID, $entry['id']);

                GFAPI::update_entry($entry);

                if ($formType == "payment") {
                    wp_redirect($response['threeDSecureUrl']);
                } else {

                    wp_redirect($response['paymentPageUrl']);

                }

                // echo $this->generate_weepay_form($entry, $form);
            } else {
                $errorMessage = $response['message'];
                throw new \Exception($errorMessage, 1);
            }
        } catch (\Exception$e) {
            do_action('gform_weepay_fail_payment', $entry, $feed);
            $errorMessage = $e->getMessage();
            $this->add_feed_error($errorMessage, $feed, $entry, $form);
            echo $errorMessage;
        }
    }

    public function curlPostExt($data, $url, $json = false)
    {
        $response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
            'body' => $data,
            'method' => 'POST',
            'data_format' => 'body',
        ));
        $body = wp_remote_retrieve_body($response);

        return $body;
    }
    public function generateBasketItems($items, $type = "VIRTUAL")
    {
        $keyNumber = 0;
        foreach ($items as $key => $item) {
            $basketItems[$keyNumber] = new stdClass();
            $basketItems[$keyNumber]->productId = $item['id'];
            $basketItems[$keyNumber]->productPrice = $this->priceParser(round($item['unit_price'], 2));
            $basketItems[$keyNumber]->name = $item['name'];
            $basketItems[$keyNumber]->itemType = $type;
            $keyNumber++;
        }
        return $basketItems;
    }

    public function priceParser($price)
    {

        if (strpos($price, ".") === false) {
            return $price . ".0";
        }
        $subStrIndex = 0;
        $priceReversed = strrev($price);
        for ($i = 0; $i < strlen($priceReversed); $i++) {
            if (strcmp($priceReversed[$i], "0") == 0) {
                $subStrIndex = $i + 1;
            } else if (strcmp($priceReversed[$i], ".") == 0) {
                $priceReversed = "0" . $priceReversed;
                break;
            } else {
                break;
            }
        }

        return strrev(substr($priceReversed, $subStrIndex));
    }

    public function feed_settings_fields()
    {

        return array(

            array(
                'description' => '',
                'fields' => array(
                    array(
                        'name' => 'feedName',
                        'label' => esc_html__('Name', 'gravityforms'),
                        'type' => 'text',
                        'class' => 'medium',
                        'required' => true,
                        'tooltip' => '<h6>' . esc_html__('Name', 'gravityforms') . '</h6>' . esc_html__('Enter a feed name to uniquely identify this setup.', 'gravityforms'),
                    ),
                    array(
                        'name' => 'transactionType',
                        'label' => esc_html__('Transaction Type', 'gravityforms'),
                        'type' => 'select',
                        'onchange' => "jQuery(this).parents('form').submit();",
                        'choices' => array(
                            array(
                                'label' => esc_html__('Select a transaction type', 'gravityforms'),
                                'value' => '',
                            ),
                            array(
                                'label' => esc_html__('Products and Services', 'gravityforms'),
                                'value' => 'product',
                            ),

                        ),
                        'tooltip' => '<h6>' . esc_html__('Transaction Type', 'gravityforms') . '</h6>' . esc_html__('Select a transaction type.', 'gravityforms'),
                    ),
                ),
            ),

            array(
                'title' => esc_html__('Products &amp; Services Settings', 'gravityforms'),
                'dependency' => array(
                    'field' => 'transactionType',
                    'values' => array('product', 'donation'),
                ),
                'fields' => array(
                    array(
                        'name' => 'paymentAmount',
                        'label' => esc_html__('Payment Amount', 'gravityforms'),
                        'type' => 'select',
                        'choices' => $this->product_amount_choices(),
                        'required' => true,
                        'default_value' => 'form_total',
                        'tooltip' => '<h6>' . esc_html__('Payment Amount', 'gravityforms') . '</h6>' . esc_html__("Select which field determines the payment amount, or select 'Form Total' to use the total of all pricing fields as the payment amount.", 'gravityforms'),
                    ),
                ),
            ),
            array(
                'title' => esc_html__('Other Settings', 'gravityforms'),
                'dependency' => array(
                    'field' => 'transactionType',
                    'values' => array('subscription', 'product', 'donation'),
                ),
                'fields' => $this->other_settings_fields(),
            ),

        );
    }
    public function other_settings_fields()
    {

        $other_settings = array(
            array(
                'name' => 'billingInformation',
                'label' => esc_html__('Billing Information', 'gravityforms'),
                'type' => 'field_map',
                'field_map' => $this->billing_info_fields(),
                'tooltip' => '<h6>' . esc_html__('Billing Information', 'gravityforms') . '</h6>' . esc_html__('Map your Form Fields to the available listed fields.', 'gravityforms'),
            ),

            array(
                'name' => 'customerInformation',
                'label' => esc_html__('Customer Information', 'gravityforms'),
                'type' => 'field_map',
                'field_map' => $this->customer_info_fields(),
                'tooltip' => '<h6>' . esc_html__('Billing Information', 'gravityforms') . '</h6>' . esc_html__('Map your Form Fields to the available listed fields.', 'gravityforms'),
            ),
            array(
                'name' => 'otherInformation',
                'label' => esc_html__('Other Information', 'gravityforms'),
                'type' => 'field_map',
                'field_map' => $this->other_info_fields(),
                'tooltip' => '<h6>' . esc_html__('Billing Information', 'gravityforms') . '</h6>' . esc_html__('Map your Form Fields to the available listed fields.', 'gravityforms'),
            ),
            array(
                'name' => 'shippingInformation',
                'label' => esc_html__('Shpping Information', 'gravityforms'),
                'type' => 'field_map',
                'field_map' => $this->shipping_info_fields(),
                'tooltip' => '<h6>' . esc_html__('if your product or service is not physical, you can leave it blank', 'gravityforms') . '</h6>' . esc_html__('Map your Form Fields to the available listed fields.', 'gravityforms'),
            ),
        );

        $option_choices = $this->option_choices();
        if (!empty($option_choices)) {
            $other_settings[] = array(
                'name' => 'options',
                'label' => esc_html__('Options', 'gravityforms'),
                'type' => 'checkbox',
                'choices' => $option_choices,
            );
        }

        $other_settings[] = array(
            'name' => 'conditionalLogic',
            'label' => esc_html__('Conditional Logic', 'gravityforms'),
            'type' => 'feed_condition',
            'tooltip' => '<h6>' . esc_html__('Conditional Logic', 'gravityforms') . '</h6>' . esc_html__('When conditions are enabled, form submissions will only be sent to the payment gateway when the conditions are met. When disabled, all form submissions will be sent to the payment gateway.', 'gravityforms'),
        );

        return $other_settings;
    }

    public function other_info_fields()
    {
        $fields = array(
            array('name' => "installmentNumber", 'label' => esc_html__('installment number', 'gravityforms'), 'required' => false),

        );
        return $fields;
    }
    public function customer_info_fields()
    {

        $fields = array(
            array('name' => "customerName", 'label' => esc_html__('Name', 'gravityforms'), 'required' => true),
            array('name' => "customerSurname", 'label' => esc_html__('Surname', 'gravityforms'), 'required' => true),
            array('name' => "gsmNumber", 'label' => esc_html__('Phone', 'gravityforms'), 'required' => true),
            array('name' => "email", 'label' => esc_html__('Email', 'gravityforms'), 'required' => true),
        );

        return $fields;
    }
    public function billing_info_fields()
    {

        $fields = array(
            array('name' => "address", 'label' => esc_html__('address', 'gravityforms'), 'required' => true),
            array('name' => "city", 'label' => esc_html__('city', 'gravityforms'), 'required' => true),
            array('name' => "country", 'label' => esc_html__('country', 'gravityforms'), 'required' => true),
            array('name' => "zipCode", 'label' => esc_html__('zipCode', 'gravityforms'), 'required' => false),

        );

        return $fields;
    }
    public function shipping_info_fields()
    {

        $fields = array(
            array('name' => "contactName", 'label' => esc_html__('contact name', 'gravityforms'), 'required' => false),
            array('name' => "address", 'label' => esc_html__('address', 'gravityforms'), 'required' => false),
            array('name' => "city", 'label' => esc_html__('city', 'gravityforms'), 'required' => false),
            array('name' => "country", 'label' => esc_html__('country', 'gravityforms'), 'required' => false),
            array('name' => "zipCode", 'label' => esc_html__('zipCode', 'gravityforms'), 'required' => false),

        );

        return $fields;
    }

    public function init()
    {
        add_filter('gform_notification_events', array($this, 'notification_events'), 10, 2);

        // Supports frontend feeds.
        $this->_supports_frontend_feeds = true;

        parent::init();
    }

    public function notification_events($notification_events, $form)
    {
        $has_weepay_feed = function_exists('gf_weepay') ? gf_weepay()->get_feeds($form['id']) : false;

        if ($has_weepay_feed) {
            $payment_events = array(
                'complete_payment' => __('Payment Completed', 'gravityforms'),
            );

            return array_merge($notification_events, $payment_events);
        }

        return $notification_events;
    }

    public function post_payment_action($entry, $action)
    {
        $form = GFAPI::get_form($entry['form_id']);

        GFAPI::send_notifications($form, $entry, rgar($action, 'type'));
    }

    public function GetOrderData($id_order)
    {
        $weepayArray = array();
        $weepayArray['Auth'] = array(
            'bayiId' => $this->get_plugin_setting(self::GF_WEEPAY_BAYI),
            'apiKey' => $this->get_plugin_setting(self::GF_WEEPAY_API),
            'secretKey' => $this->get_plugin_setting(self::GF_WEEPAY_SECRET),
        );
        $weepayArray['Data'] = array(
            'paymentId' => $id_order,
        );
        $weepayEndPoint = "https://api.weepay.co/GetPayment/Detail";
        return json_decode($this->curlPostExt(json_encode($weepayArray), $weepayEndPoint, true));
    }

}