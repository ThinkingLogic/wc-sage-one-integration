<?php

use SageAccounting\ApiResponse;
use ThinkingLogic\SageApiClient;
use ThinkingLogic\Logger;

if ( ! class_exists( 'ThinkingLogicWCSage' ) ) {
	/**
	 * Main Class.
	 *
	 * @class ThinkingLogicWCSage
	 */
	class ThinkingLogicWCSage {
		const OPTIONS_GROUP = 'tl-wc-sage-plugin-options';
		const OPTION_CLIENT_ID = 'tl_wc_sage_client_id';
		const OPTION_CLIENT_SECRET = 'tl_wc_sage_client_secret';
		const OPTION_ACCESS_TOKEN = 'tl_wc_sage_access_token';
		const OPTION_ACCESS_TOKEN_EXPIRES = 'tl_wc_sage_access_token_expires';
		const OPTION_REFRESH_TOKEN = 'tl_wc_sage_refresh_token';
		const OPTION_REFRESH_TOKEN_EXPIRES = 'tl_wc_sage_refresh_token_expires';
		const OPTION_REFRESH_TOKEN_EXPIRES_AT = 'tl_wc_sage_refresh_token_expires_at';
		const OPTION_CALLBACK_URL = 'tl_wc_sage_callback_url';
		const OPTION_SHIPPING_TAX_ID = 'tl_wc_sage_shipping_tax_id';
		const OPTION_LINE_ITEM_TAX_ID = 'tl_wc_sage_line_item_tax_id';
		const OPTION_LEDGER_CODES = 'tl_wc_sage_ledger_codes';
		const OPTION_DEFAULT_ACCRUALS_LEDGER_CODE = 'tl_wc_sage_accruals_ledger_code';

		const FILTER_INVOICE_DATES = 'tl_wc_sage_filter_invoice_dates';
		const FILTER_CUSTOMER = 'tl_wc_sage_filter_create_customer';
		const FILTER_INVOICE = 'tl_wc_sage_filter_create_invoice';
		const FILTER_JOURNALS = 'tl_wc_sage_filter_create_journals';

		const PRODUCT_FIELD_LEDGER_CODE = '_tl_wc_sage_ledger_code'; // sales ledger code
		const PRODUCT_FIELD_ACCRUALS_LEDGER_CODE = '_tl_wc_sage_accruals_ledger_code';
		const ORDER_FIELD_CUSTOMER_ID = '_tl_wc_sage_customer_id';
		const ORDER_FIELD_CUSTOMER_LINK = '_tl_wc_sage_customer_link';
		const ORDER_FIELD_LATEST_INVOICE_DATE = '_tl_wc_sage_latest_invoice_date'; // in DATE_FORMAT format
		const SAGEONE_UI_URL_BASE = 'https://accounts-extra.sageone.com';

		const DEFAULT_TAX_ID = 'GB_ZERO';
		const DATE_TIME_FORMAT = 'd/m/Y H:i:s';
		const DATE_FORMAT = 'd/m/Y';
		const SAGE_DATE_FORMAT = 'Y-m-d';
		const CREATE_INVOICE_BUTTON_ID = 'tl_wc_sage_create_invoice';
		const CALLBACK_REQUEST_PATH = '/tl-wc-sage-plugin-callback';
		const CUSTOMER_TYPE_ID = 'CUSTOMER';
		const CONTACT_PERSON_TYPE_ID = 'ACCOUNTS';
		const ADDRESS_TYPE_ID = 'ACCOUNTS';

		protected static $sageone_client = null;
		protected static $_instance = null;

		/**
		 * Creates and returns a singleton instance of this class.
		 *
		 * @return     ThinkingLogicWCSage
		 */
		public static function instance(): ?ThinkingLogicWCSage {
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
				$client_id     = get_option( self::OPTION_CLIENT_ID );
				$client_secret = get_option( self::OPTION_CLIENT_SECRET );
				$callback_url  = ( isset( $_SERVER['HTTPS'] ) ? "https" : "http" ) . "://$_SERVER[HTTP_HOST]" . self::CALLBACK_REQUEST_PATH;

				self::$sageone_client = new SageApiClient( $client_id, $client_secret, $callback_url );
			}

			return self::$sageone_client;
		}

		/**
		 * Tests whether we have client details.
		 *
		 * @return     boolean  True if has client details, False otherwise.
		 */
		public static function hasClientDetails(): bool {
			$client_id     = get_option( self::OPTION_CLIENT_ID );
			$client_secret = get_option( self::OPTION_CLIENT_SECRET );

			return ( $client_id && $client_secret );
		}

		/**
		 * Creates invoice(s) in SageOne if they are not already present, also creating a customer if not found.
		 *
		 * @param string $order_id The order identifier
		 */
		public function handleCreateSageInvoices( string $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $this->validateOrder( $order ) ) {
				return;
			}

			$customer = $this->findOrCreateCustomer( $order );

			$invoice_amounts = array( $order->get_date_created()->format( self::DATE_FORMAT ) => $order->get_total() );
			Logger::log( "handleCreateSageInvoices: Invoice amounts before filter: " . json_encode( $invoice_amounts ) );
			$invoice_amounts = apply_filters( self::FILTER_INVOICE_DATES, $invoice_amounts, $order );
			Logger::log( "handleCreateSageInvoices: Invoice amounts after filter: " . json_encode( $invoice_amounts ) );
			$invoices = $this->createInvoices( $order, $customer, $invoice_amounts );

			$journal_entries = array();
			$journal_entries = apply_filters( self::FILTER_JOURNALS, $journal_entries, $order, $customer, $invoices );
			Logger::log( "handleCreateSageInvoices: Journal entries after filter: " . json_encode( $journal_entries ) );
			$this->createJournalEntries( $order, $journal_entries );
		}

		/**
		 * Validates that the order is ready to have an invoice created - i.e. that each product has a ledger id.
		 *
		 * @param WC_Order $order The order
		 *
		 * @return     boolean   true if the order is valid
		 */
		private function validateOrder( WC_Order $order ): bool {
			$result = true;
			foreach ( $order->get_items() as $item ) {
				if ( ! $this->getSalesLedgerId( $item ) ) {
					Logger::addAdminWarning( $item->get_name() . ' does not have a valid SageOne Ledger code - cannot create invoice. (Edit the ledger code in the advanced tab of the product).' );
					$result = false;
				}
			}

			return $result;
		}

		/**
		 * Gets the sales ledger id for the product associated with the given item.
		 *
		 * @param WC_Order_Item $item The item
		 *
		 * @return     mixed  The ledger identifier, or null if none exists for the product.
		 */
		public function getSalesLedgerId( WC_Order_Item $item ) {
			return $this->getProductLedgerId( $item, self::PRODUCT_FIELD_LEDGER_CODE, null );
		}

		/**
		 * Gets the accruals ledger id for the product associated with the given item,
		 * or the default accruals code if none associated with the product.
		 *
		 * @param WC_Order_Item $item The item
		 *
		 * @return     mixed  The ledger identifier, or the default if none exists for the product.
		 */
		public function getAccrualsLedgerId( WC_Order_Item $item ) {
			$code = get_option( self::OPTION_DEFAULT_ACCRUALS_LEDGER_CODE );

			return $this->getProductLedgerId( $item, self::PRODUCT_FIELD_ACCRUALS_LEDGER_CODE, $code );
		}

		/**
		 * Gets the desired ledger id for the product associated with the given item.
		 *
		 * @param WC_Order_Item $item The item
		 * @param $metadata_key string the metadata key holding the ledger code to look up.
		 * @param $default_code string the default ledger code to use if none specified on the product.
		 *
		 * @return mixed  The ledger identifier, or the id of the default code if none exists for the product.
		 */
		public function getProductLedgerId( WC_Order_Item $item, string $metadata_key, $default_code ) {
			$code = $default_code;
			$product   = wc_get_product( $item->get_product_id() );
			if ( $product && $product->meta_exists( $metadata_key ) ) {
				$code = $product->get_meta( $metadata_key );
			}
			$map  = $this->getLedgerCodeMap();
			if ( $map == null || ! array_key_exists( $code, $map ) ) {
				$map = $this->getLedgerCodeMap( true );
			}

			return $map[ $code ];
		}

		/**
		 * Gets the ledger codes from SageOne.
		 *
		 * @param boolean $refresh if true, the sales ledger codes will be refreshed from SageOne.
		 *
		 * @return     array    an array of ledger codes and ids [code => id].
		 */
		private function getLedgerCodeMap( bool $refresh = false ): ?array {
			if ( $refresh ) {
				$ledgers = $this->getAllItems( '/ledger_accounts?items_per_page=200&attributes=nominal_code' );
				$map     = array();
				foreach ( $ledgers as $ledger ) {
					$map[ strval( $ledger->nominal_code ) ] = $ledger->id;
				}
				$map_json = json_encode( $map );
				Logger::debug( 'ledgerCodeMap built from ' . sizeof( $ledgers ) . ' ledgers: ' . $map_json );
				update_option( self::OPTION_LEDGER_CODES, $map_json );
			}

			return json_decode( get_option( self::OPTION_LEDGER_CODES ), true );
		}

		/**
		 * Lists customers, optionally restricting the list to those with the given email address.
		 *
		 * @param      (optional) string  $email  The email address
		 *
		 * @return     ApiResponse
		 */
		public function listCustomers( $email = '' ): ApiResponse {
			$url = '/contacts?contact_type_id=' . self::CUSTOMER_TYPE_ID;
			if ( $email ) {
				$url .= '&email=' . urlencode( $email );
			}

			return $this->getData( $url );
		}

		/**
		 * Make an arbitrary get request to Sage.
		 *
		 * @param $url
		 *
		 * @return     ApiResponse
		 */
		public function makeGetRequest( $url ): ApiResponse {
			return $this->getData( $url );
		}

		/**
		 * makes a get request to Sage, repeatedly calling the 'next' url until 'next' is null
		 *
		 * @param $url
		 *
		 * @return array of items containing items from all pages of the sage response
		 */
		public function getAllItems( $url ): array {
			$api_response = $this->makeGetRequest( $url );
			$json         = $api_response->getJSON();
			$items        = $json->{'$items'};
			$next         = $json->{'$next'};
			while ( isset( $next ) ) {
				$api_response = $this->makeGetRequest( $next );
				$json         = $api_response->getJSON();
				$items        = array_merge( $items, $json->{'$items'} );
				$next         = $json->{'$next'};
			}

			return $items;
		}

		/**
		 * Finds an existing customer using the billing email address, or creates a new customer.
		 *
		 * @param WC_Order  order       The order
		 *
		 * @return     object  the customer as returned by Sage. See https://developer.sage.com/api/accounting/api/contacts/#operation/getContactsKey.
		 */
		public function findOrCreateCustomer( $order ) {
			$email = $order->get_billing_email();

			$matches = $this->listCustomers( $email )->getJSON();
			if ( $matches->{'$total'} > 0 ) {
				$customer = $matches->{'$items'}[0];
				if ( $matches->{'$total'} > 1 ) {
					$message = 'Found ' . $matches->{'$total'} . ' SageOne customers with email address "' . $email . '", defaulting to the first match: #' . $customer->id;
					Logger::addAdminWarning( $message );
					$order->add_order_note( $message );
				} else {
					Logger::addAdminNotice( "Found existing SageOne customer " . $customer->displayed_as );
				}
			} else {
				$first_name   = $order->get_billing_first_name();
				$last_name    = $order->get_billing_last_name();
				$phone        = $order->get_billing_phone();
				$api_response = $this->createCustomer( $email, $first_name . ' ' . $last_name, $phone, $order );
				$customer     = $api_response->getJSON();
				Logger::addAdminNotice( "Created SageOne customer " . $customer->displayed_as );
				$order->add_order_note( 'Created SageOne customer '	. '<a href="' . self::SAGEONE_UI_URL_BASE . '/contacts/customers/' . $customer->id . '">' . $customer->displayed_as . '</a>' );
			}
			if ( ! $order->meta_exists( self::ORDER_FIELD_CUSTOMER_ID ) ) {
				$order->update_meta_data( self::ORDER_FIELD_CUSTOMER_ID, $customer->id );
				if ( isset( $customer->links) && sizeof( $customer->links ) > 0 && isset( $customer->links[0]->href ) ) {
					$order->update_meta_data( self::ORDER_FIELD_CUSTOMER_LINK, $customer->links[0]->href );
				}
				$order->save();
			}

			return $customer;
		}

		/**
		 * Creates a customer.
		 * See https://developer.sage.com/api/accounting/api/contacts/#operation/postContacts
		 *
		 * @param string $email The email
		 * @param string $name The name
		 * @param string $phone The phone
		 * @param WC_Order $order The order
		 *
		 * @return     ApiResponse
		 */
		private function createCustomer( string $email, string $name, string $phone, WC_Order $order ): ApiResponse {
			$contact = [
				'name'                => $name,
				'contact_type_ids'    => [ self::CUSTOMER_TYPE_ID ],
				'email'               => $email,
				'notes'               => 'Created from ' . $_SERVER['HTTP_HOST'] . ' order #' . $order->get_id(),
				'currency_id'         => $order->get_currency(),
				'main_contact_person' => [
					'contact_person_type_ids' => [ self::CONTACT_PERSON_TYPE_ID ],
					'name'                    => $name,
					'email'                   => $email,
					'telephone'               => $phone,
					'is_main_contact'         => true,
					'is_preferred_contact'    => true,
				]
			];

			Logger::log( "createCustomer: before filter: " . json_encode( $contact ) );
			$contact = apply_filters( ThinkingLogicWCSage::FILTER_CUSTOMER, $contact, $order );
			Logger::log( "createCustomer: after filter: " . json_encode( $contact ) );

			return $this->postData( '/contacts', [ 'contact' => $contact ] );
		}

		/**
		 * Creates invoices for the given dates and amounts.
		 *
		 * @param WC_Order $order The order
		 * @param object $customer The customer
		 * @param array $invoice_amounts Associative array [invoice_date => invoice_amount], where invoice_date is in the format ::DATE_FORMAT.
		 *
		 * @return array Associative array [invoice_date => invoice] containing the invoices created, keyed by dates in ::DATE_FORMAT. See https://developer.sage.com/api/accounting/api/invoicing-sales/#operation/getSalesInvoicesKey.
		 */
		public function createInvoices( WC_Order $order, $customer, array $invoice_amounts ): array {
			$invoices          = array();
			$existing_invoices = $this->listInvoices( $customer->id, $this->invoiceReference( $order ), array_keys( $invoice_amounts ) );
			foreach ( $invoice_amounts as $date_string => $invoice_amount ) {
				$invoice = $this->maybeCreateInvoice( $order, $customer, $date_string, $invoice_amount, $existing_invoices );
				if ( $invoice ) {
					$invoices[ $date_string ] = $invoice;
				}
			}
			if ( count( $invoices ) > 0 ) {
				$message             = 'Created SageOne invoices for the following dates and amounts: ';
				$latest_invoice_date = null;
				foreach ( $invoices as $date_string => $invoice ) {
					$message             .= ' <br/>&nbsp;&nbsp;' . $date_string . ', ' . $order->get_currency() . $invoice_amounts[ $date_string ] . ' => <a href="' . self::SAGEONE_UI_URL_BASE . '/invoicing/sales_invoices/' . $invoice->id . '">' . $invoice->invoice_number . '</a>';
					$latest_invoice_date = $date_string;
				}
				$message .= "<br/>Invoice reference is '" . $this->invoiceReference( $order ) . "'";
				$order->add_order_note( $message );
				$order->update_meta_data( self::ORDER_FIELD_LATEST_INVOICE_DATE, $latest_invoice_date );
				Logger::addAdminNotice( $message );
			}
			$invoice_sum = array_sum( array_values( $invoice_amounts ) );
			if ( $invoice_sum != $order->get_total() ) {
				Logger::addAdminWarning( 'Sum of invoice amounts: ' . $invoice_sum . ' does not equal order total: ' . $order->get_total() . '. Manual correction required.' );
			}

			return $invoices;
		}

		/**
		 * Creates the given journal entries and adds an order note.
		 *
		 * @param WC_Order $order The order
		 * @param array $journal_entries An array of journal entries as defined by sage: https://developer.sage.com/api/accounting/api/accounting/#tag/Journals
		 */
		public function createJournalEntries( WC_Order $order, array $journal_entries ) {
			if ( count( $journal_entries ) > 0 ) {
				$message = '';
				foreach ( $journal_entries as $journal ) {
					$response = $this->postData( '/journals', [ 'journal' => $journal ] )->getJSON();
					if ( is_object( $response ) && property_exists( $response, 'id' ) ) {
						$message .= ' <br/>&nbsp;&nbsp;' . $response->date
						            . ': <a href="' . self::SAGEONE_UI_URL_BASE . '/journals#' . $response->id . '">'
						            . $response->displayed_as . ' (' . $response->total . $order->get_currency() . ')</a>';
					} else {
						Logger::addAdminWarning( 'Unable to create journal entry ' . json_encode( $journal ) . ' : ' . json_encode( $response ) );
					}
				}
				if ( strlen( $message ) > 0 ) {
					$message = 'Created SageOne journal entries: ' . $message;
					$order->add_order_note( $message );
					Logger::addAdminNotice( $message );
				}
			}

		}

		/**
		 * Constructs an associative array representing a journal entry in the format accepted by Sage:
		 * https://developer.sage.com/api/accounting/api/accounting/#operation/postJournals
		 *
		 * @param $journal_date string the date of the journal, in ::SAGE_DATE_FORMAT.
		 * @param $reference string the reference for the journal.
		 * @param $description string a description. Used as both the description of the journal and the 'details' of each line.
		 * @param $ledger_from string the id of the ledger to debit.
		 * @param $ledger_to string the id of the ledger to credit.
		 * @param $amount numeric the amount of the journal entry.
		 *
		 * @return array the new journal ready to be POSTed to Sage.
		 */
		public function constructJournalEntry( string $journal_date, string $reference, string $description, string $ledger_from, string $ledger_to, $amount ): array {
			$journal                  = [
				'date'        => $journal_date,
				'reference'   => $reference,
				'description' => $description,
				'total'       => $amount,
			];
			$lines                    = array();
			$lines[]                  = [
				'ledger_account_id' => $ledger_from,
				'debit'             => $amount,
				'credit'            => 0,
				'details'           => $description
			];
			$lines[]                  = [
				'ledger_account_id' => $ledger_to,
				'debit'             => 0,
				'credit'            => $amount,
				'details'           => $description
			];
			$journal['journal_lines'] = $lines;

			return $journal;
		}

		/**
		 * @param WC_Order $order The order
		 *
		 * @return     string    The invoice reference (the order id prefixed with a hash)
		 */
		public function invoiceReference( WC_Order $order ): string {
			return "#" . $order->get_id();
		}

		/**
		 * List all invoices matching the given customer id and reference, within the range of dates given.
		 * See https://developer.sage.com/api/accounting/api/invoicing-sales/#operation/getSalesInvoices.
		 *
		 * @param string $customer_id The customer identifier
		 * @param string $reference The reference - e.g. order id
		 * @param array $dates - array of dates formatted as ::DATE_FORMAT.
		 *
		 * @return     array   existing invoices as returned by Sage, keyed by the invoice date formatted as ::SAGE_DATE_FORMAT.
		 */
		private function listInvoices( string $customer_id, string $reference, array $dates ): array {
			// sort the invoice dates to get the first and last date
			$formatted_dates = array();
			foreach ( $dates as $date ) {
				$formatted                     = $this->convertDateToSageFormat( $date );
				$formatted_dates[ $formatted ] = $date;
			}
			ksort( $formatted_dates );
			$first  = reset( $formatted_dates );
			$last   = end( $formatted_dates );
			$result = array();

			$url      = '/sales_invoices?contact_id=' . $customer_id . '&attributes=reference' . '&from_date=' . $first . '&to_date=' . $last . '&items_per_page=200';
			$response = $this->getData( $url )->getJSON();
			if ( $response->{'$total'} > 0 ) {
				$result = $this->mapInvoicesByDate( $response->{'$items'}, $reference );
			}

			return $result;
		}

		/**
		 * Creates an invoice in SageOne (if one for the given customer, order and date cannot be found).
		 *
		 * @param WC_Order $order The order
		 * @param object $customer The customer
		 * @param string $invoice_date The invoice date in the format ::DATE_FORMAT
		 * @param number $invoice_amount The invoice amount
		 * @param array $existing_invoices array of existing invoices, keyed by date in ::SAGE_DATE_FORMAT
		 *
		 * @return     object the invoice that was created, or null if not created.
		 */
		private function maybeCreateInvoice( WC_Order $order, $customer, string $invoice_date, $invoice_amount, array $existing_invoices ) {
			$result = null;
			$sage_invoice_date = $this->convertDateToSageFormat($invoice_date);
			if ( array_key_exists( $sage_invoice_date, $existing_invoices ) ) {
				$message = 'Invoice for customer ' . $customer->id . ', order ' . $order->get_id() . ' and date ' . $invoice_date . ' already exists: ' . $existing_invoices[ $sage_invoice_date ]->invoice_number;
				Logger::addAdminWarning( $message );
			} else {
				$invoice = $this->createInvoice( $order, $customer, $sage_invoice_date, $invoice_amount );
				if ( is_object( $invoice ) && property_exists( $invoice, 'invoice_number' ) ) {
					$result = $invoice;
				} else {
					Logger::addAdminWarning( 'Unable to create invoice for ' . $invoice_amount . ' ' . $order->get_currency() . ' on ' . $invoice_date . ' : ' . json_encode( $invoice ) );
				}
			}

			return $result;
		}

		/**
		 * Constructs an array of the given invoices keyed by date.
		 *
		 * @param object $invoices The invoices
		 * @param string $reference Only invoices with this reference will be returned.
		 *
		 * @return     array   filtered invoices keyed by date: [date => invoice] where date is formatted as ::SAGE_DATE_FORMAT.
		 */
		private function mapInvoicesByDate( $invoices, string $reference ): array {
			$map = array();
			foreach ( $invoices as $index => $invoice ) {
				if ( $invoice->reference == $reference ) {
					$map[ $invoice->date ] = $invoice;
				}
			}

			return $map;
		}

		/**
		 * Creates an invoice in SageOne.
		 * See also: https://developer.columbus.sage.com/docs#/uki/sageone/accounts/v3/sales_invoices_sales_invoice
		 *
		 * @param WC_Order $order The order
		 * @param object $customer The customer as a Sage contact. https://developer.sage.com/api/accounting/api/contacts/#tag/Contacts.
		 * @param string $invoice_date The invoice date in the format ::DATE_FORMAT
		 * @param number $invoice_amount The invoice amount
		 *
		 * @return     object    the response from sage, as a json object. https://developer.columbus.sage.com/docs#/uki/sageone/accounts/v3/sales_invoices_sales_invoice
		 */
		private function createInvoice( WC_Order $order, $customer, string $invoice_date, $invoice_amount ) {
			$invoice_fraction = $this->calculateInvoiceFraction( $order, $invoice_amount );
			$shipping_net     = ( floatval( $order->get_shipping_total() ) - floatval( $order->get_shipping_tax() ) ) * $invoice_fraction;
			$sales_invoice    = [
				'contact_id'           => $customer->id,
				'date'                 => $invoice_date,
				'contact_name'         => $customer->displayed_as,
				'due_date'             => $invoice_date,
				'reference'            => $this->invoiceReference( $order ),
				'notes'                => $this->getSalesInvoiceNotes( $order ),
				'shipping_net_amount'  => number_format( $shipping_net, 2, '.', '' ),
				'currency_id'          => $order->get_currency(),
				'shipping_tax_rate_id' => get_option( ThinkingLogicWCSage::OPTION_SHIPPING_TAX_ID, ThinkingLogicWCSage::DEFAULT_TAX_ID ),
				'main_address'         => [ // sage requires an invoice address :(
					'address_type_id' => ThinkingLogicWCSage::ADDRESS_TYPE_ID,
					'address_line_1'  => 'N/A'
				],
			];
			$line_items       = array();
			$order_items      = $order->get_items();
			foreach ( $order_items as $item ) {
				if ( $item->is_type( 'line_item' ) ) {
					$line_items[] = $this->getSalesInvoiceLineItem( $item, $invoice_fraction );
				}
			}
			$sales_invoice['invoice_lines'] = $line_items;

			Logger::log( "createInvoice: before filter: " . json_encode( $sales_invoice ) );
			$sales_invoice = apply_filters( ThinkingLogicWCSage::FILTER_INVOICE, $sales_invoice, $order );
			Logger::log( "createInvoice: after filter: " . json_encode( $sales_invoice ) );

			return $this->postData( '/sales_invoices', [ 'sales_invoice' => $sales_invoice ] )->getJSON();
		}

		/**
		 * @param WC_Order $order The order
		 * @param float $invoice_amount The invoice amount
		 *
		 * @return float the fraction of the total order value accounted for by this invoice.
		 */
		private function calculateInvoiceFraction( $order, $invoice_amount ) {
			if ( $order->get_total() == 0 ) {
				return 1;
			}

			return $invoice_amount / $order->get_total();
		}

		/**
		 * @param WC_Order_Item $item The line item
		 * @param float $invoice_fraction the fraction of the order value accounted for by this invoice.
		 *
		 * @return array the line item as defined by https://developer.columbus.sage.com/docs#/uki/sageone/accounts/v3/sales_invoices_sales_invoice_invoice_lines
		 */
		private function getSalesInvoiceLineItem( WC_Order_Item $item, float $invoice_fraction ): array {
			$line_item_amount = $item->get_total() * $invoice_fraction;
			$line_item_tax    = $item->get_total_tax() * $invoice_fraction;
			$description      = $this->getLineItemDetail( $item );

			return [
				'ledger_account_id'       => $this->getSalesLedgerId( $item ),
				'quantity'                => $item->get_quantity(),
				'unit_price'              => number_format( $line_item_amount / $item->get_quantity(), 2, '.', '' ),
				'unit_price_includes_tax' => 'false',
				'total_amount'            => number_format( $line_item_amount, 2, '.', '' ),
				'tax_amount'              => number_format( $line_item_tax, 2, '.', '' ),
				'tax_rate_id'             => get_option( self::OPTION_LINE_ITEM_TAX_ID, ThinkingLogicWCSage::DEFAULT_TAX_ID ),
				'description'             => $description,
			];
		}

		/**
		 * Appends meta data to the item name.
		 *
		 * @param WC_Order_item $item The item
		 *
		 * @return     string  The line item detail.
		 */
		private function getLineItemDetail( WC_Order_item $item ): string {
			$detail    = $item->get_name();
			$meta_data = $item->get_meta_data();
			foreach ( $meta_data as $meta ) {
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
		private function getPaypalOrderPrefix(): string {
			$paypal_settings = get_option( 'woocommerce_paypal_settings' );

			return $paypal_settings['invoice_prefix'];
		}

		/**
		 * Makes a post request to SageOne, refreshing the token if necessary and generating all required headers.
		 *
		 * @param string $endpoint The endpoint, the portion after the BASE_ENDPOINT
		 * @param object $postData Object representing the body of the post request
		 *
		 * @return     ApiResponse
		 */
		private function postData( string $endpoint, $postData ): ApiResponse {
			$client = self::sageClient();

			return $client->execApiRequest( $endpoint, 'POST', json_encode( $postData ) );
		}

		/**
		 * Makes a get request to SageOne, refreshing the token if necessary and generating all required headers.
		 *
		 * @param string $endpoint The endpoint, the portion after the BASE_ENDPOINT
		 *
		 * @return     ApiResponse
		 */
		private function getData( string $endpoint ): ApiResponse {
			$client = self::sageClient();

			return $client->execApiRequest( $endpoint, 'GET' );
		}

		/**
		 * @param $order WC_Order
		 *
		 * @return string
		 */
		private function getSalesInvoiceNotes( WC_Order $order ): string {
			$notes  = 'WooCommerce order #' . $order->get_id() . ' (total ' . $order->get_total() . ' ' . $order->get_currency() . ')';
			$notes  .= ", payment method=" . $order->get_payment_method();
			$custom = get_post_custom( $order->get_id() );
			if ( array_key_exists( "Payer PayPal address", $custom ) ) {
				$notes .= ' paid via PayPal by: ';
				$notes .= "name=" . $custom["Payer first name"][0] . ' ' . $custom["Payer last name"][0];
				$notes .= ", email=" . $custom["Payer PayPal address"][0];
			}
			$notes .= ", transaction id=" . $order->get_transaction_id();
			if ( array_key_exists( "PayPal Transaction Fee", $custom ) ) {
				$notes .= ", transaction fee=" . $custom["PayPal Transaction Fee"][0];
			}

			return $notes;
		}

		/**
		 * @param $date string formatted as ::DATE_FORMAT
		 *
		 * @return string the date formatted as ::SAGE_DATE_FORMAT
		 */
		private function convertDateToSageFormat( string $date ): string {
			return DateTime::createFromFormat( self::DATE_FORMAT, $date )->format( self::SAGE_DATE_FORMAT );
		}
	}
}
