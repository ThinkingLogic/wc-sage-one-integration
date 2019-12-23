<?php

if ( ! class_exists( 'ThinkingLogicWCSage' ) ) {
    /**
     * Main Class.
     *
     * @class ThinkingLogicWCSage
     */
    class ThinkingLogicWCSage {
        const OPTIONS_GROUP                 = 'tl-wc-sage-plugin-options';
        const OPTION_CLIENT_ID              = 'tl_wc_sage_client_id';
        const OPTION_CLIENT_SECRET          = 'tl_wc_sage_client_secret';
        const OPTION_SIGNING_SECRET         = 'tl_wc_sage_signing_secret';
        const OPTION_SUBSCRIPTION_KEY       = 'tl_wc_sage_apim_subscription_key';
        const OPTION_ACCESS_TOKEN           = 'tl_wc_sage_access_token';
        const OPTION_ACCESS_TOKEN_EXPIRES   = 'tl_wc_sage_access_token_expires';
        const OPTION_REFRESH_TOKEN          = 'tl_wc_sage_refresh_token';
        const OPTION_CALLBACK_URL           = 'tl_wc_sage_callback_url';
        const OPTION_CARRIAGE_TAX_ID        = 'tl_wc_sage_carriage_tax_id';
        const OPTION_LINE_ITEM_TAX_ID       = 'tl_wc_sage_line_item_tax_id';
        const OPTION_LEDGER_CODES           = 'tl_wc_sage_ledger_codes';

        const FILTER_INVOICE_DATES          = 'tl_wc_sage_filter_invoice_dates';
        const FILTER_CUSTOMER               = 'tl_wc_sage_filter_create_customer';
        const FILTER_INVOICE                = 'tl_wc_sage_filter_create_invoice';

        const PRODUCT_FIELD_LEDGER_CODE     = '_tl_wc_sage_ledger_code';
        const ORDER_FIELD_CUSTOMER_ID       = '_tl_wc_sage_customer_id';

        const BASE_ENDPOINT                 = 'https://api.accounting.sage.com/v3.1/';
        const SAGEONE_UI_URL_BASE           = 'https://accounts-extra.sageone.com';

        const DATE_TIME_FORMAT              = 'd/m/Y H:i:s';
        const DATE_FORMAT                   = 'd/m/Y';
        const APPLICATION_NAME              = 'Thinking Logic WooCommerce Sage Integration';
        const CREATE_INVOICE_BUTTON_ID      = 'tl_wc_sage_create_invoice';

        protected static $sageone_client = null;
        protected static $_instance = null;
        protected static $_messages = false;
 
        private $_invoice_cache = array();

        /**
         * Creates and returns a singleton instance of this class.
         *
         * @return     ThinkingLogicWCSage
         */
        public static function instance() {
            if ( is_null( self::$_instance ) ) {
                self::$_instance = new ThinkingLogicWCSage();
            }
            return self::$_instance;
        }

        /**
         * Creates and returns a singleton SageApiClient.
         *
         * @return     SageApiClient
         */
        public static function sageClient() {
            if ( is_null( self::$sageone_client ) ) {
                $client_id = get_option(self::OPTION_CLIENT_ID );
                $client_secret = get_option(self::OPTION_CLIENT_SECRET );
                $callback_url = get_option(self::OPTION_CALLBACK_URL );
                if (!$callback_url) {
                    $callback_url = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
                    update_option( self::OPTION_CALLBACK_URL, $callback_url );
                }

                self::$sageone_client = new SageApiClient($client_id, $client_secret, urlencode($callback_url), self::SCOPE);

            }
            return self::$sageone_client;
        }

        /**
         * Tests whether we have client details.
         *
         * @return     boolean  True if has client details, False otherwise.
         */
        public static function hasClientDetails() {
            $client_id = get_option(self::OPTION_CLIENT_ID );
            $client_secret = get_option(self::OPTION_CLIENT_SECRET );
            return ($client_id && $client_secret);
        }

        /**
         * Logs a message to the error log, if both WP_DEBUG and WP_DEBUG_LOG are true.
         *
         * @param      string  $message  The message
         */
        public static function log($message) {
            if (constant('WP_DEBUG') && constant('WP_DEBUG_LOG')) {
                error_log("ThinkingLogicWCSage: " . $message);
            }
        }

        /**
         * Adds an admin notice to be displayed to the user.
         *
         * @param      <type>  $message  The message
         */
        public static function addAdminNotice($message) {
            self::log($message);
            self::addWCCustomNotice( $message );
        }

        /**
         * Adds an admin notice to be displayed to the user, formatted as a warning.
         *
         * @param      <type>  $message  The message
         */
        public static function addAdminWarning($message) {
            self::log('WARN: ' . $message);
            self::addWCCustomNotice( '<span style="color: red; font-weight: bold">' . $message . '</span>');
        }

        /**
         * Adds a message to the WooCommerce custom notices.
         *
         * @param      string  $message  The message
         */
        private static function addWCCustomNotice($message) {
            if (!self::$_messages) {
                self::$_messages = get_option( 'woocommerce_admin_notice_ThinkingLogicWCSage');
            }
            self::$_messages .= '<p>' . $message . '</p>';
            WC_Admin_Notices::add_custom_notice( "ThinkingLogicWCSage", self::$_messages );
        }

        /**
         * Removes admin notices.
         */
        public static function clearAdminNotices() {
            self::log("Clearing admin notices");
            WC_Admin_Notices::remove_notice( "ThinkingLogicWCSage" );
            self::$_messages = '';
        }

        /**
         * Saves tokens from the given sage token_response.
         * See: https://developers.sageone.com/docs/en/v1
         *
         * @param      string  $response  The response
         */
        public static function saveTokensFromResponse($response) {
            if ($response) {
                $tokens = json_decode($response, true);
                $access_token = $tokens['access_token'];
                update_option( self::OPTION_ACCESS_TOKEN, $access_token );
                $refresh_token = $tokens['refresh_token'];
                update_option( self::OPTION_REFRESH_TOKEN, $refresh_token );
                $date = new DateTime();
                $interval = new DateInterval('PT' . $tokens['expires_in'] . 'S');
                $date = $date->add($interval); 
                $expires = $date->format(self::DATE_TIME_FORMAT);
                update_option( self::OPTION_ACCESS_TOKEN_EXPIRES, $expires );
            }
        }

        /**
         * Ensures that the access token is valid, refreshing it if necessary.
         */
        public static function refreshTokenIfNecessary() {
            $now = new DateTime();
            $expires = DateTime::createFromFormat(self::DATE_TIME_FORMAT, get_option(self::OPTION_ACCESS_TOKEN_EXPIRES));
            if ($expires) {
                if ($now >= $expires) {
                    self::log("Refreshing token");
                    $client = self::sageClient();
                    $refresh_token = get_option(self::OPTION_REFRESH_TOKEN );
                    $tokens = $client->renewAccessToken( $refresh_token );
                    self::saveTokensFromResponse($tokens);
                } else {
                    self::log("token does not require refreshing");
                }
            } else {
                self::log("Cannot renew token - no expires value found");
            }
        }

        /**
         * Creates invoice(s) in SageOne if they are not already present, also creating a customer if not found.
         *
         * @param      string  $order_id  The order identifier
         */
        public function handleCreateSageInvoices($order_id) {
            $order = wc_get_order($order_id);

            if (!$this->validateOrder($order)) {
                return;
            }

            $customer = $this->findOrCreateCustomer($order);

            $invoice_amounts = array($order->get_date_created()->format(self::DATE_FORMAT) => $order->get_total());
            ThinkingLogicWCSage::log("handleCreateSageInvoices: Invoice amounts before filter: " . json_encode($invoice_amounts));
            $invoice_amounts = apply_filters( self::FILTER_INVOICE_DATES, $invoice_amounts, $order );
            ThinkingLogicWCSage::log("handleCreateSageInvoices: Invoice amounts after filter: " . json_encode($invoice_amounts));
            $this->createInvoices($order, $customer, $invoice_amounts);
        }

        /**
         * Validates that the order is ready to have an invoice created - i.e. that each product has a ledger id.
         *
         * @param      WC_Order  $order  The order
         * @return     boolean   true if the order is valid
         */
        private function validateOrder($order) {
            $result = true;
            foreach ( $order->get_items() as $item ) {
                if (!$this->getLedgerId($item)) {
                    self::addAdminWarning($item->get_name() . ' does not have a valid SageOne Ledger code - cannot create invoice. (Edit the ledger code in the advanced tab of the product).');
                    $result = false;
                }
            }
            return $result;
        }

        /**
         * Gets the sales ledger id for the product associated with the given item.
         *
         * @param      WC_Order_Item   $item   The item
         *
         * @return     mixed  The ledger identifier, or null if none exists for the product.
         */
        private function getLedgerId($item) {
            $id = null;
            $product = wc_get_product( $item->get_product_id() );
            if ($product && $product->meta_exists(self::PRODUCT_FIELD_LEDGER_CODE)) {
                $code = $product->get_meta(self::PRODUCT_FIELD_LEDGER_CODE);
                $map = $this->getLedgerCodeMap();
                if (!array_key_exists($code, $map)) {
                    $map = $this->getLedgerCodeMap(true);
                }
                $id = $map[$code];
            }
            return $id;
        }

        /**
         * Gets the sales ledger codes from SageOne.
         *
         * @param      boolean  $refresh    if true, the sales ledger codes will be refreshed from SageOne.
         *
         * @return     array    an array of ledger codes and ids [code => id].
         */
        private function getLedgerCodeMap($refresh = false) {
            if ($refresh) {
                $response = json_decode($this->makeGetRequest('/ledger_accounts'));
                $map = array();
                if ($response->{'$totalResults'}) {
                    $ledgers = (array) $response->{'$resources'};
                    foreach ($ledgers as $ledger) {
                        $map[strval($ledger->nominal_code)] = $ledger->id;
                    }
                }
                $map_json = json_encode($map);
                self::log('ledgerCodeMap: ' . $map_json);
                update_option(self::OPTION_LEDGER_CODES, $map_json);
            }
            return json_decode(get_option(self::OPTION_LEDGER_CODES), true);
        }

        /**
         * Lists customers, optionally restricting the list to those with the given email address.
         *
         * @param      (optional) string  $email  The email address
         * @return     string the response from sage, as a json string.
         */
        public function listCustomers($email = '') {
            $url = '/contacts?contact_type=1';
            if ($email) {
                $url .= '&email=' . $email;
            }
            $response = $this->getData($url);
            return $response;
        }

        /**
         * Make an arbitrary get request to SageOne.
         *
         * @return     string the response from sage, as a json string.
         */
        public function makeGetRequest($url) {
            $response = $this->getData($url);
            return $response;
        }

        /**
         * Lists tax rates.
         *
         * @return     string the response from sage, as a json string.
         */
        public function listTaxRates() {
            $response = $this->getData('/tax_rates');
            return $response;
        }

        /**
         * Finds an existing customer using the paypal or billing email address, or creates a new customer.
         *
         * @param      WC_Order  order       The order
         *
         * @return     object  the customer
         */
        public function findOrCreateCustomer($order) {
            $custom = get_post_custom($order->get_id());
            $email = $order->get_billing_email();

            $matches = json_decode($this->listCustomers($email));
            if ($matches->{'$totalResults'} > 0) {
                $customer = $matches->{'$resources'}[0];
                if ($matches->{'$totalResults'} > 1) {
                    $message = 'Found ' . $matches->{'$totalResults'} . ' SageOne customers with email address "' . $email . '", defaulting to the first match: #' . $customer->id;
                    self::addAdminWarning( $message );
                    $order->add_order_note( $message );
                } else {
                    self::addAdminNotice("Found existing SageOne customer #" . $customer->id);
                }
            } else {
                $first_name = $order->get_billing_first_name();
                $last_name = $order->get_billing_last_name();
                $phone = $order->get_billing_phone();
                $customer = json_decode( $this->createCustomer($email, $first_name . ' ' . $last_name, $phone, $order) );
                self::addAdminNotice( "Created SageOne customer #" . $customer->id );
                $order->add_order_note( "Created SageOne customer #" . $customer->id );
            }
            if (! $order->meta_exists(self::ORDER_FIELD_CUSTOMER_ID)) {
                $order->update_meta_data(self::ORDER_FIELD_CUSTOMER_ID, $customer->id );
                $order->save();
            }
            return $customer;
        }

        /**
         * Creates a customer.
         * See https://developers.sageone.com/docs/en/v1#contacts-create_a_contact
         *
         * @param      string    $email     The email
         * @param      string    $name      The name
         * @param      string    $phone     The phone
         * @param      WC_Order  $order     The order
         *
         * @return     string  the customer in json format
         */
        private function createCustomer($email, $name, $phone, $order) {
            $params = array();
            $params['contact[contact_type_id]'] = '1';
            $params['contact[email]'] = $email;
            $params['contact[name]'] = $name;
            $params['contact[notes]'] = 'Created from ' . $_SERVER['HTTP_HOST'] . ' order #' . $order->get_id();
            $params['contact[telephone]'] = $phone;

            self::log("createCustomer: before filter: ". json_encode($params));
            $params = apply_filters( ThinkingLogicWCSage::FILTER_CUSTOMER, $params, $order );
            self::log("createCustomer: after filter: ". json_encode($params));

            $response = $this->postData('/contacts', $params);
            return $response;
        }

        /**
         * Creates invoices for the given dates and amounts.
         *
         * @param      WC_Order  $order            The order
         * @param      object    $customer         The customer
         * @param      array     $invoice_amounts  Associative array [invoice_date => invoice_amount], where invoice_date is in the format ::DATE_FORMAT.
         */
        public function createInvoices($order, $customer, $invoice_amounts) {
            $invoices = array();
            $existing_invoices = $this->listInvoices($customer->id, $this->invoiceReference($order), array_keys($invoice_amounts));
            foreach ($invoice_amounts as $date_string => $invoice_amount) {
                $invoice = $this->maybeCreateInvoice($order, $customer, $date_string, $invoice_amount, $existing_invoices);
                if ($invoice) {
                    $invoices[$date_string] = $invoice;
                }
            }
            if (count($invoices) > 0) {
                $message = 'Created SageOne invoices for the following dates and amounts: ';
                foreach ($invoices as $date_string => $invoice) {
                    $message .= ' <br/>&nbsp;&nbsp;' . $date_string . ', £' . $invoice_amounts[$date_string] . ' => <a href="' . self::SAGEONE_UI_URL_BASE . '/invoicing/sales_invoices/' . $invoice->id . '">' . $invoice->invoice_number . '</a>';
                }
                $message .= "<br/>Invoice reference is '" . $this->invoiceReference($order) . "'";
                $order->add_order_note($message);
                self::addAdminNotice($message);
            }
            $invoice_sum = array_sum(array_values($invoice_amounts));
            if ($invoice_sum != $order->get_total()) {
                self::addAdminWarning('Sum of invoice amounts: ' . $invoice_sum . ' does not equal order total: ' . $order->get_total() . '. Manual correction required.');
            }

        }

        /**
         * @param      WC_Order  $order  The order
         * @return     string    The invoice reference
         */
        private function invoiceReference($order) {
            return $this->getPaypalOrderPrefix() . $order->get_id();
        }

        /**
         * List all invoices matching the given customer id and reference, within the range of dates given. 
         * See https://developers.sageone.com/docs/en/v1#sales_invoices-list_all_invoices.
         *
         * @param      string  $customer_id  The customer identifier
         * @param      string  $reference    The reference - e.g. order id
         * @param      array   $the dates in which we're interested
         *
         * @return     array   existing invoices as returned by Sage, keyed by the invoice date.
         */
        private function listInvoices($customer_id, $reference, $dates) {
            // sort the invoice dates to get the first and last date
            $formatted_dates = array();
            foreach ($dates as $date) {
                $formatted = DateTime::createFromFormat(self::DATE_FORMAT, $date)->format('Y-m-d');
                $formatted_dates[$formatted] = $date;
            }
            ksort($formatted_dates);
            $first = reset($formatted_dates);
            $last = end($formatted_dates);
            $result = array();

            $url = '/sales_invoices?contact=' . $customer_id . '&from_date=' . $first . '&to_date=' . $last;
            $response = json_decode($this->getData($url));
            if ($response->{'$totalResults'} > 0) {
                $result = $this->mapInvoicesByDate($response->{'$resources'}, $reference);
            }
            return $result;
        }

        /**
         * Creates an invoice in SageOne (if one for the given customer, order and date cannot be found).
         *
         * @param      WC_Order  $order             The order
         * @param      object    $customer          The customer
         * @param      string    $invoice_date      The invoice date
         * @param      number    $invoice_amount    The invoice amount
         * @param      array     $existing_invoices array of existing invoices, keyed by date
         * @return     object the invoice that was created, or null if not created.
         */
        private function maybeCreateInvoice($order, $customer, $invoice_date, $invoice_amount, $existing_invoices) {
            $result = null;
            if (array_key_exists($invoice_date, $existing_invoices)) {
                $message = 'Invoice for customer ' . $customer->id . ', order ' . $order->get_id() . ' and date ' . $invoice_date . ' already exists: ' . $existing_invoices[$invoice_date]->invoice_number ;
                self::addAdminWarning($message);
            } else {
                $invoice = $this->createInvoice($order, $customer, $invoice_date, $invoice_amount);
                if (property_exists($invoice, 'invoice_number')) {
                    $result = $invoice;
                } else {
                    self::addAdminWarning('Unable to create invoice for ' . $invoice_amount . ' ' . $order->get_currency() . ' on ' . $invoice_date . ' : ' . json_encode($invoice) );
                }         
            }
            return $result;
        }

        /**
         * Constructs an array of the given invoices keyed by date.
         *
         * @param      object  $invoices  The invoices
         * @param      string  $reference Only invoices with this reference will be returned.
         *
         * @return     array   filtered invoices keyed by date: [date => invoice]
         */
        private function mapInvoicesByDate($invoices, $reference) {
            $map = array();
            foreach ($invoices as $index => $invoice) {
                if ($invoice->reference == $reference) {
                    $map[$invoice->date] = $invoice;
                }
            }
            return $map;
        }

        /**
         * Creates an invoice in SageOne.
         *
         * @param      WC_Order  $order           The order
         * @param      object    $customer        The customer
         * @param      string    $invoice_date    The invoice date
         * @param      number    $invoice_amount  The invoice amount
         * @return the response from sage, as a json-formatted string.
         */
        private function createInvoice($order, $customer, $invoice_date, $invoice_amount) {
            $params = array();
            $params['sales_invoice[contact_id]'] = $customer->id;
            $params['sales_invoice[contact_name]'] = $customer->name_and_company_name;
            $params['sales_invoice[date]'] = $invoice_date;
            $params['sales_invoice[due_date]'] = $invoice_date;
            $params['sales_invoice[main_address]'] = $order->get_billing_email();
            $params['sales_invoice[carriage_tax_code_id]'] = get_option(ThinkingLogicWCSage::OPTION_CARRIAGE_TAX_ID );
            $params['sales_invoice[reference]'] = $this->invoiceReference($order);
            $notes = 'WooCommerce order #' . $order->get_id() . ' (total ' .  $order->get_total() . ' ' . $order->get_currency() . ')';
            $custom = get_post_custom($order->get_id());
            if ( array_key_exists("Payer PayPal address", $custom) ) {
                $notes .= ' paid via PayPal by: ';
                $notes .= "name=" . $custom["Payer first name"][0] . ' ' . $custom["Payer last name"][0];
                $notes .= ", email=" . $custom["Payer PayPal address"][0];
            }
            $notes .= ", transaction id=" . $order->get_transaction_id();
            if ( array_key_exists("PayPal Transaction Fee", $custom) ) {
                $notes .= ", transaction fee=" . $custom["PayPal Transaction Fee"][0];
            }
            $index = 0;
            foreach ( $order->get_items() as $item ) {
                if ( $item->is_type( 'line_item' ) ) {
                    $prefix = 'sales_invoice[line_items_attributes][' . $index . ']';
                    $params[$prefix . '[ledger_account_id]'] = (int) $this->getLedgerId($item);
                    $params[$prefix . '[quantity]'] = $item->get_quantity();
                    $line_item_amount = $item->get_total() * ($invoice_amount / $order->get_total());
                    $params[$prefix . '[unit_price]'] = number_format( $line_item_amount / $item->get_quantity(), 2, '.', '' );
                    $params[$prefix . '[tax_code]'] = get_option(self::OPTION_LINE_ITEM_TAX_ID);
                    $description = $this->getLineItemDetail($item);
                    $params[$prefix . '[description]'] = $description; 
                    $index += 1;
                }
            }

            $params['sales_invoice[notes]'] = $notes;

            self::log("createInvoice: before filter: ". json_encode($params));
            $params = apply_filters( ThinkingLogicWCSage::FILTER_INVOICE, $params, $order );
            self::log("createInvoice: after filter: ". json_encode($params));

            $response = json_decode($this->postData('/sales_invoices', $params));
            return $response;
        }

        /**
         * Appends meta data to the item name.
         *
         * @param      WC_Order_item  $item   The item
         *
         * @return     string  The line item detail.
         */
        private function getLineItemDetail($item) {
            $detail = $item->get_name();
            $meta_data = $item->get_meta_data();
            foreach ($meta_data as $meta) {
                // $key = $meta->key;
                // if ((substr($key, 0, 3) === 'pa_')) {
                //     $key = substr($key, 3);
                // }
                $detail .= ', ' . $meta->value; //' ' . $key . '=' . $meta->value;
            }
            return $detail;
        }

        /**
         * Gets the paypal order prefix.
         *
         * @return     string  The paypal order prefix.
         */
        private function getPaypalOrderPrefix() {
            $paypal_settings = get_option('woocommerce_paypal_settings');
            return $paypal_settings['invoice_prefix'];
        }

        /**
         * Makes a post request to to SageOne, refreshing the token if necessary and generating all required headers.
         *
         * @param      string  $endpoint  The endpoint, the portion after the BASE_ENDPOINT
         * @param      array   $params    The parameters
         *
         * @return     string  The response from SageOne.
         */
        private function postData($endpoint, $params) {
            self::refreshTokenIfNecessary();
            $url = self::BASE_ENDPOINT . $endpoint;
            $client = self::sageClient();
            $headers = $this->getHeaders();
            $response = $client->postData($url, $params, $headers);
            return $response;
        }

        /**
         * Makes a get request to SageOne, refreshing the token if necessary and generating all required headers.
         *
         * @param      string  $url      The url
         *
         * @return     string  The response from SageOne.
         */
        private function getData($endpoint) {
            self::refreshTokenIfNecessary();
            $url = self::BASE_ENDPOINT . $endpoint;
            $headers = $this->getHeaders();
            $client = self::sageClient();
            $response = $client->getData($url, $headers);
            return $response;
        }

        /**
         * Constructs an array of headers for the given request.
         *
         * @return     array   The headers.
         */
        private function getHeaders() {
            $token = get_option(self::OPTION_ACCESS_TOKEN);

            $header = array("Accept: *.*",
                "Content_Type: application/x-www-form-urlencoded",
                "User-Agent: " . self::APPLICATION_NAME,
                "Authorization: Bearer " . $token);

            return $header;
        }
    }
}
