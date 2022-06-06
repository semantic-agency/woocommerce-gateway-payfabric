<?php
/**
 * Fired during gateway request
 *
 * @since      1.0.0
 *
 * @package    PayFabric_Gateway_Woocommerce
 * @subpackage PayFabric_Gateway_Woocommerce/admin
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generates requests to send to Payfabric.
 *
 * This class defines all code necessary for gateway request.
 *
 * @since      1.0.0
 * @package    PayFabric_Gateway_Woocommerce
 * @subpackage PayFabric_Gateway_Woocommerce/admin
 */
class PayFabric_Gateway_Request
{
    /**
     * Pointer to gateway making the request.
     *
     * @since    1.0.0
     * @access   protected
     * @var      PayFabric_Gateway_Gateway $gateway Gateway instance
     */
    protected $gateway;

    /**
     * Endpoint for payment notification url
     *
     * @since    1.0.0
     * @access   protected
     * @var      string $notify_url Endpoint URL
     */
    protected $notify_url;
    /**
     * Initialize the class and set its properties.
     *
     * @since   1.0.0
     * @param    PayFabric_Gateway_Gateway $gateway
     */
    public function __construct($gateway)
    {
        $this->gateway = $gateway;
        $this->notify_url = WC()->api_request_url($this->gateway->id);//http://localhost/wordpress/index.php/wc-api/payfabric/
        //include the gateway sdk and init the class
        include_once('lib/payfabric/Autoload.php');
        include_once('lib/payments.php');
    }
    //Generate transaction data
    public function get_payfabric_gateway_post_args($order)
    {
       
        // to get only the site's domain url name to assign to the parameter allowOriginUrl . 
        //otherwise it will encounter a CORS issue when wordpress deployed inside a subfolder of the web server.
       
        $parse_result = parse_url(site_url());
        if(isset($parse_result['port'])){
            $allowOriginUrl = $parse_result['scheme']."://".$parse_result['host'].":".$parse_result['port'];
        }else{
            $allowOriginUrl = $parse_result['scheme']."://".$parse_result['host'];
        }

        $ip = $order->get_customer_ip_address();
        if($ip == '::1'){
            $ip = '127.0.0.1';
        }
        $level3_data = $this->get_level3_data_from_order($order);
        return array(
    		"referenceNum" => $order->get_order_number(), // REQUIRED - Merchant internal order number //
            "Amount" => $order->get_total(), // REQUIRED - Transaction amount in US format //
            "Currency" => get_woocommerce_currency(), // Optional - Valid only for ChasePaymentech multi-currecy setup. Please see full documentation for more info
            "pluginName" => "WooCommerce PayFabric Gateway",
            "pluginVersion" => $this->gateway->version,
            //Shipping Information
            "shippingCity" => $order->get_shipping_city(), // Optional - Customer city //
            "shippingCountry" => $order->get_shipping_country(), // Optional - Customer country code per ISO 3166-2 //
            "customerId" => $order->get_customer_id(),
            "shippingEmail" => $order->get_billing_email(), // Optional - Customer email address, use the billing email as shipping email because there is no shipping email//
            "shippingAddress1" => $order->get_shipping_address_1(), // Optional - Customer address //
            "shippingAddress2" => $order->get_shipping_address_2(), // Optional - Customer address //
            "shippingPhone" => $order->get_billing_phone(), // Optional - Customer phone number, use the billing phone as shipping phone because there is no shipping phone //
            "shippingState" => $order->get_shipping_state(), // Optional - Customer state with 2 characters //
            "shippingPostalCode" => $order->get_shipping_postcode(), // Optional - Customer zip code //
            //Billing Information
            'billingFirstName' => $order->get_billing_first_name(),
            'billingLastName'  => $order->get_billing_last_name(),
            'billingCompany'    => $order->get_billing_company(),
            'billingAddress1'  => $order->get_billing_address_1(),
            'billingAddress2'  => $order->get_billing_address_2(),
            'billingCity'       => $order->get_billing_city(),
            'billingState'      => $order->get_billing_state(),
            'billingPostalCode'   => $order->get_billing_postcode(),
            'billingCountry'    => $order->get_billing_country(),
            'billingEmail'      => $order->get_billing_email(),
            'billingPhone'      => $order->get_billing_phone(),
            //level2/3
            'freightAmount'    => $order->get_shipping_total(),
            'taxAmount' => $order->get_total_tax(),
            'lineItems' => $level3_data,
            //Optional
            'allowOriginUrl' => $allowOriginUrl,
            "merchantNotificationUrl" => $this->notify_url.'?order_id='. $order->get_id(),
            "userAgent" => $order->get_customer_user_agent(),
            "customerIPAddress" => $ip
        );
    }
    //Generate level3 data
    private function get_level3_data_from_order( $order ) {
        // Get the order items. Don't need their keys, only their values.
        // Order item IDs are used as keys in the original order items array.
        $order_items = array_values( $order->get_items( [ 'line_item', 'fee' ] ) );

        $currency    = $order->get_currency();

        return array_map(
            function( $item ) use ( $currency ) {
                if ( is_a( $item, 'WC_Order_Item_Product' ) ) {
                    $product_id = $item->get_variation_id()
                        ? $item->get_variation_id()
                        : $item->get_product_id();
                    $subtotal   = $item->get_subtotal();
                } else {
                    $product_id = substr( sanitize_title( $item->get_name() ), 0, 12 );
                    $subtotal   = $item->get_total();
                }
                $product_description = substr( $item->get_name(), 0, 26 );
                $quantity            = $item->get_quantity();
                $unit_cost           = wc_format_decimal($subtotal / $quantity);
                $tax_amount          = $item->get_total_tax();
                $discount_amount     = wc_format_decimal($subtotal - $item->get_total());

                return array(
                    'product_code'        => $product_id,
                    'product_description' => $product_description,
                    'unit_cost'           => $unit_cost,
                    'quantity'            => $quantity,
                    'discount_amount'     => $discount_amount,
                    'tax_amount'          => $tax_amount,
                    'item_amount'          => $item->get_total()
                );
            },
            $order_items
        );
    }
    //Execute payment synchronization callback
    public function generate_check_request_form($order, $merchantTxId, $sandbox = false)
    {
        if ( $this->gateway->api_merchant_id === null || $this->gateway->api_merchant_id === ''
            || $this->gateway->api_password === null || $this->gateway->api_password === '' ) {
            return false;
        }
        try {
            $maxiPago = new payments;
            $maxiPago->setLogger(PayFabric_LOG_DIR,PayFabric_LOG_SEVERITY);

            // Set your credentials before any other transaction methods
            $maxiPago->setCredentials($this->gateway->api_merchant_id, $this->gateway->api_password);

            $maxiPago->setDebug(PayFabric_DEBUG);
            $maxiPago->setEnvironment($sandbox);

            $maxiPago->retrieveTransaction($merchantTxId);
            $result = json_decode($maxiPago->response);
        } catch (Exception $e) {
            return false;
        }
        if(empty($result) || empty($result->TrxResponse->Status)){
            return false;
        }
        $status = strtolower($result->TrxResponse->Status);
        $transactionState = strtolower($result->TransactionState);//Sale: Pending Settlement, Auth: Pending Capture
        $order_id = $result->TrxUserDefine1;
        $order = wc_get_order($order_id);
        $order_status = $order->get_status();
        if($status == "approved"){
            if($transactionState == "pending capture") {
                if ($order_status != 'on-hold') {
                    //Auth transaction
                    update_post_meta($order->get_id(), '_payment_status', 'on-hold');
                    $order->update_status('on-hold', sprintf(__('Card payment authorized.', 'payfabric-gateway-woocommerce')));

                    // Reduce stock levels
                    wc_reduce_stock_levels($order_id);
                }
            }elseif (in_array($transactionState,array('pending settlement','settled','captured'))){
                if($order_status != 'completed' && $order_status != 'processing'){
                    //Purchase transaction
                    update_post_meta( $order->get_id(), '_payment_status', 'completed');
                    $order->payment_complete();
                    if($this->gateway->api_success_status == '1'){
                        $order->update_status( 'completed', sprintf( __( 'Card payment completed.', 'payfabric-gateway-woocommerce' ) ) );
                    }
                    //                 do_action( 'woocommerce_payment_complete', $order_id);

                    // Reduce stock levels
                    wc_reduce_stock_levels($order_id);
                }
            }else{
                $message = __('Thank you for shopping with us.<br />Your payment is in progress.', 'payfabric-gateway-woocommerce');
                $message_type = 'success';
                $payfabric_message = array(
                    'message' => $message,
                    'message_type' => $message_type
                );
                // Empty cart
                if( function_exists('WC') ){
                    WC()->cart->empty_cart();
                }
                update_post_meta($order_id, '_payfabric_gateway_message', $payfabric_message);
                //Do not handle these order status in the plugin system
                //header($_SERVER['SERVER_PROTOCOL'] . ' 200 OK', true, 200);
                return;
            }
        }else{
            if($order_status != 'failed'){
                $order->update_status( 'failed', sprintf( __( 'Card payment failed.', 'payfabric-gateway-woocommerce' ) ) );
            }
            $payfabric_message = array(
                'message' =>  __( 'Card payment failed.', 'payfabric-gateway-woocommerce' ).__( 'Order ID:', 'payfabric-gateway-woocommerce' ) . $order->get_id() . '.'.__( 'Transaction ID:', 'payfabric-gateway-woocommerce' ).  $merchantTxId,
                'message_type' => 'error'
            );
            update_post_meta($order_id, '_payfabric_gateway_message', $payfabric_message);
            $order->save();
            //header($_SERVER['SERVER_PROTOCOL'] . ' 200 OK', true, 200);
            return;
        }

        
        
        // Empty cart
        if( function_exists('WC') ){
            WC()->cart->empty_cart();
        }
        
        //save the EVO transaction ID into the database
        update_post_meta( $order->get_id(), '_transaction_id', $merchantTxId );
        
        $message = __('Thank you for shopping with us.<br />Your transaction was successful, payment was received.<br />Your order is currently being processed.', 'payfabric-gateway-woocommerce');
        $message .= '<br />'.__( 'Order ID:', 'payfabric-gateway-woocommerce' ).$order->get_id() . '. '.__( 'Transaction ID:', 'payfabric-gateway-woocommerce' ) . $merchantTxId;
        $message_type = 'success';
        $payfabric_message = array(
            'message' => $message,
            'message_type' => $message_type
        );
        update_post_meta($order_id, '_payfabric_gateway_message', $payfabric_message);
        $order->save();
//        header($_SERVER['SERVER_PROTOCOL'] . ' 200 OK', true, 200);
    }
    //Integrate PayFabric Cashier UI
    public function generate_payfabric_gateway_form($order, $sandbox)
    {
        $maxiPago = new payments;
        $maxiPago->setLogger(PayFabric_LOG_DIR,PayFabric_LOG_SEVERITY);

        // Set your credentials before any other transaction methods
        $maxiPago->setCredentials($this->gateway->api_merchant_id, $this->gateway->api_password);

        $maxiPago->setDebug(PayFabric_DEBUG);
        $maxiPago->setEnvironment($sandbox);
        $cashierUrl = $maxiPago->cashierUrl;
        $jsUrl = $maxiPago->jsUrl;

//        $shop_page_url = get_permalink( wc_get_page_id( 'shop' ) );
//        if(empty($shop_page_url)){
//            $shop_page_url = site_url();
//        }
        $shop_page_url = $this->gateway->get_return_url($order);
        $data = $this->get_payfabric_gateway_post_args($order);
        if ($this->gateway->api_payment_action){
            $maxiPago->creditCardAuth($data);
        }else {
            $maxiPago->creditCardSale($data);
        }
        $responseTran = json_decode($maxiPago->response);
        if(!$responseTran->Key){
            if (is_object(payFabric_RequestBase::$logger)) {
                payFabric_RequestBase::$logger->logCrit($maxiPago->response);
            }
            throw new UnexpectedValueException($maxiPago->response, 503);
        }
        $maxiPago->token(array("Audience" => "PaymentPage" , "Subject" => $responseTran->Key));
        $responseToken= json_decode($maxiPago->response);
        if(!$responseToken->Token) {
            if (is_object(payFabric_RequestBase::$logger)) {
                payFabric_RequestBase::$logger->logCrit($maxiPago->response);
            }
            throw new UnexpectedValueException($maxiPago->response, 503);
        }

        switch($this->gateway->api_payment_modes){
            //api_payment_modes : array('Iframe','Redirect')
            case '0':
                $payfabric_cashier_args = array(
                    'environment' => $sandbox ? (stripos(TESTGATEWAY,'DEV-US2')===FALSE ? (stripos(TESTGATEWAY,'QA')===FALSE ? 'SANDBOX' : 'QA') : 'DEV-US2') : 'LIVE',
                    'target' => 'cashierDiv',
                    'displayMethod' => 'IN_PLACE',
                    'session' => $responseToken->Token,
                    'disableCancel' => true
                );
                $payfabric_form[] = '<form id="payForm" action="'.$shop_page_url;
                $payfabric_form[] = '" method="get"><input type="hidden" name="wcapi" value="payfabric"/><input type="hidden" name="order_id" value="'.$order->get_id().'"/><input type="hidden" id="TrxKey" name="TrxKey" value=""/><input type="hidden" name="key" value="'.$order->get_order_key().'"/></form>';
                $payfabric_form[] = '<div id="cashierDiv"></div>';
                $payfabric_form[] = '<script type="text/javascript" src="'. $jsUrl . '"></script>';
                $payfabric_form[] = '<script type="text/javascript">';
                $payfabric_form[] = 'function handleResult(data) {console.log(data);';
                $payfabric_form[] = 'if(data.RespStatus == "Approved"){';
                $payfabric_form[] = 'document.getElementById("TrxKey").value = data.TrxKey;';
                $payfabric_form[] = 'document.getElementById("payForm").submit();}else{ setTimeout(function(){location.reload();}, 3000);}';
                $payfabric_form[] = '}';
                $payfabric_form[] = 'new payfabricpayments({';
                foreach ($payfabric_cashier_args as $key => $value) {
                    $payfabric_form[] = esc_attr($key) . ' : "' . esc_attr($value) . '",';
                }
                $payfabric_form[] = 'successCallback:handleResult,';
                $payfabric_form[] = 'failureCallback:handleResult,';
                $payfabric_form[] = '});';
                $payfabric_form[] = '</script>';

                return implode('', $payfabric_form);
            default:
                $form_data = array();
                $form_data['token'] = $responseToken->Token;
                $form_data['successUrl'] = $shop_page_url . "&wcapi=payfabric&order_id=" . $order->get_id();
                $form_html = '';
                $form_html .= '<form action=' . $cashierUrl. ' method="get">';
                foreach ($form_data as $key => $value) {
                    $form_html .= "<input type='hidden' name='" . htmlentities($key) . "' value='" . htmlentities($value) . "'>";
                }
                $form_html .= '<button type="submit" class="button alt">'.__( 'Pay with PayFabric', 'payfabric-gateway-woocommerce' ).'</button> </form>';
                return $form_html;
        }
    }
    //Integrate direct payment UI before place order
    public function generate_payfabric_gateway_iframe($sandbox)
    {
        $maxiPago = new payments;
        $maxiPago->setLogger(PayFabric_LOG_DIR, PayFabric_LOG_SEVERITY);

        // Set your credentials before any other transaction methods
        $maxiPago->setCredentials($this->gateway->api_merchant_id, $this->gateway->api_password);

        $maxiPago->setDebug(PayFabric_DEBUG);
        $maxiPago->setEnvironment($sandbox);
        $jsUrl = $maxiPago->jsUrl;

        $data = array(
            "Amount" => WC()->cart->total, // REQUIRED - Transaction amount in US format //
            "Currency" => get_woocommerce_currency(),
            "customerId" => get_current_user_id()// wp_get_current_user();
        );
        if ($this->gateway->api_payment_action) {
            $maxiPago->creditCardAuth($data);
        } else {
            $maxiPago->creditCardSale($data);
        }
        $responseTran = json_decode($maxiPago->response);
        if (!$responseTran->Key) {
            if (is_object(payFabric_RequestBase::$logger)) {
                payFabric_RequestBase::$logger->logCrit($maxiPago->response);
            }
            throw new UnexpectedValueException($maxiPago->response, 503);
        }
        $maxiPago->token(array("Audience" => "PaymentPage", "Subject" => $responseTran->Key));
        $responseToken = json_decode($maxiPago->response);
        WC()->session->set('transaction_key', $responseTran->Key);
        WC()->session->set('transaction_token', $responseToken->Token);
        $payfabric_cashier_args = array(
            'environment' => $sandbox ? (stripos(TESTGATEWAY, 'DEV-US2') === FALSE ? (stripos(TESTGATEWAY, 'QA') === FALSE ? 'SANDBOX' : 'QA') : 'DEV-US2') : 'LIVE',
            'target' => 'cashierDiv',
            'displayMethod' => 'IN_PLACE',
            'session' => $responseToken->Token,
            'disableCancel' => true
        );

        $payfabric_form[] = '<form id="payForm" action="';
        $payfabric_form[] = '" method="get"><input type="hidden" name="wcapi" value="payfabric"/><input type="hidden" id="wc_order_id" name="order_id" value=""/><input type="hidden" id="TrxKey" name="TrxKey" value=""/><input type="hidden" id="key" name="key" value=""/></form>';
        $payfabric_form[] = '<div id="cashierDiv"></div>';
        $payfabric_form[] = '<script type="text/javascript" src="' . $jsUrl . '"></script>';
        $payfabric_form[] = '<script type="text/javascript">';
        $payfabric_form[] = 'function handleResult(data) {console.log(data);';
        $payfabric_form[] = 'if(data.RespStatus == "Approved"){';
        $payfabric_form[] = 'document.getElementById("TrxKey").value = data.TrxKey;';
        $payfabric_form[] = 'document.getElementById("payForm").submit();}else{ setTimeout(function(){location.reload();}, 3000);}';
        $payfabric_form[] = '}';
        $payfabric_form[] = 'new payfabricpayments({';
        foreach ($payfabric_cashier_args as $key => $value) {
            $payfabric_form[] = esc_attr($key) . ' : "' . esc_attr($value) . '",';
        }
        $payfabric_form[] = 'successCallback:handleResult,';
        $payfabric_form[] = 'failureCallback:handleResult,';
        $payfabric_form[] = '});';
        $payfabric_form[] = 'var ajaxurl = "'. admin_url( 'admin-ajax.php' ) .'";</script>';

        return implode('', $payfabric_form);
    }
    //Do the payment update process
    public function do_update_process($sandbox = false, $order)
    {
        if ($this->gateway->api_merchant_id === null || $this->gateway->api_merchant_id === ''
            || $this->gateway->api_password === null || $this->gateway->api_password === '') {
            throw new UnexpectedValueException('Miss merchant configuration info', 503);
        }

        $maxiPago = new payments;
        $maxiPago->setLogger(PayFabric_LOG_DIR, PayFabric_LOG_SEVERITY);

        // Set your credentials before any other transaction methods
        $maxiPago->setCredentials($this->gateway->api_merchant_id, $this->gateway->api_password);

        $maxiPago->setDebug(PayFabric_DEBUG);
        $maxiPago->setEnvironment($sandbox);

        $data = array_merge(array(
            "Key" => WC()->session->get('transaction_key')
        ), $this->get_payfabric_gateway_post_args($order));
        $maxiPago->creditCardUpdate($data);

        $result = json_decode($maxiPago->response);
        if ($result->Result) {
            return true;
        } else {
            throw new UnexpectedValueException(!empty($maxiPago->response) ? $maxiPago->response : __('Update error!'));
        }
    }
    //Do the payment refund process
    public function do_refund_process($sandbox = false,$order, $merchantTxId,$amount) {
        if ( $this->gateway->api_merchant_id === null || $this->gateway->api_merchant_id === ''
            || $this->gateway->api_password === null || $this->gateway->api_password === '' ) {
                return new WP_Error( 'invalid_order', 'miss merchant configuration info' );
            }

            $maxiPago = new payments;
            $maxiPago->setLogger(PayFabric_LOG_DIR,PayFabric_LOG_SEVERITY);

            // Set your credentials before any other transaction methods
            $maxiPago->setCredentials($this->gateway->api_merchant_id, $this->gateway->api_password);

            $maxiPago->setDebug(PayFabric_DEBUG);
            $maxiPago->setEnvironment($sandbox);

            $maxiPago->creditCardRefund(
                array(
                    'Amount'=>$amount,
                    'ReferenceKey'=>$merchantTxId
                )
            );
            $result = json_decode($maxiPago->response);
            if(strtolower($result->Status) == 'approved') {
                return true;
            }else{
                return new WP_Error( 'invalid_order', $result->Message );
            }
    }
    //Do the payment capture process
    public function do_capture_process($sandbox = false,$order, $merchantTxId,$amount){
        if ( $this->gateway->api_merchant_id === null || $this->gateway->api_merchant_id === ''
            || $this->gateway->api_password === null || $this->gateway->api_password === '' ) {
                return new WP_Error( 'invalid_order', 'miss merchant configuration info' );
        }

        $maxiPago = new payments;
        $maxiPago->setLogger(PayFabric_LOG_DIR,PayFabric_LOG_SEVERITY);

        // Set your credentials before any other transaction methods
        $maxiPago->setCredentials($this->gateway->api_merchant_id, $this->gateway->api_password);

        $maxiPago->setDebug(PayFabric_DEBUG);
        $maxiPago->setEnvironment($sandbox);

        $maxiPago->creditCardCapture($merchantTxId);
        $result = json_decode($maxiPago->response);
            
        if(strtolower($result->Status) == 'approved') {
            $order->add_order_note( sprintf( __( 'Capture charge complete (Amount: %s)' ), $amount ) );
            $order->update_meta_data( '_payment_status', 'completed' );
            $order->payment_complete();
            if($this->gateway->api_success_status == '1'){
                $order->update_status( 'completed', sprintf( __( 'Card payment completed.', 'payfabric-gateway-woocommerce' ) ) );
            }
            $order_id = $order->get_id();
            //update the transaction ID with the new capture transaction ID for refund use
            update_post_meta( $order_id, '_transaction_id', $result->TrxKey );
            do_action( 'woocommerce_payment_complete', $order_id);
            $order->save();
            return true;
        }else{
            $order->add_order_note(!empty($result->Message) ? $result->Message : __( 'Capture error!' ) );
            return;
        }
    }
    //Do the payment VOID process
    public function do_void_process($sandbox = false,$order, $merchantTxId){
        if ( $this->gateway->api_merchant_id === null || $this->gateway->api_merchant_id === ''
            || $this->gateway->api_password === null || $this->gateway->api_password === '' ) {
                return new WP_Error( 'invalid_order', 'miss merchant configuration info' );
            }

            $maxiPago = new payments;
            $maxiPago->setLogger(PayFabric_LOG_DIR,PayFabric_LOG_SEVERITY);

            // Set your credentials before any other transaction methods
            $maxiPago->setCredentials($this->gateway->api_merchant_id, $this->gateway->api_password);

            $maxiPago->setDebug(PayFabric_DEBUG);
            $maxiPago->setEnvironment($sandbox);

            $maxiPago->creditCardVoid($merchantTxId);
            $result = json_decode($maxiPago->response);
            if(strtolower($result->Status) == 'approved') {
                $order->update_meta_data( '_payment_status', 'cancelled' );
                $order->update_status( 'cancelled', sprintf( __( 'Payment void complete', 'payfabric-gateway-woocommerce' ) ) );
                $order->save();
                return true;
            }else{
                $order->add_order_note(!empty($result->Message) ? $result->Message : __( 'Void error!' ) );
                return;
            }
    }
    //Do the payment VOID process
    public function do_check_gateway($sandbox = false, $api_merchant_id, $api_password, $payment_action){
        $maxiPago = new payments;
        $maxiPago->setLogger(PayFabric_LOG_DIR,PayFabric_LOG_SEVERITY);

        // Set your credentials before any other transaction methods
        $maxiPago->setCredentials($api_merchant_id, $api_password);

        $maxiPago->setDebug(PayFabric_DEBUG);
        $maxiPago->setEnvironment($sandbox);
        $data = array(
            'Amount' => '0.01',
            'Currency' => get_woocommerce_currency()
        );
        if ($payment_action){
            $maxiPago->creditCardAuth($data);
        }else {
            $maxiPago->creditCardSale($data);
        }
        $responseTran = json_decode($maxiPago->response);
        if(!$responseTran->Key){
            if (is_object(payFabric_RequestBase::$logger)) {
                payFabric_RequestBase::$logger->logCrit($maxiPago->response);
            }
            throw new UnexpectedValueException($maxiPago->response, 503);
        }
        return $responseTran->Key;
    }
}
