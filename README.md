# Thinking Logic Integration for Sage Cloud API to create invoices for WooCommerce orders.

Based on code samples provided here: https://github.com/Sage/sageone_api_php_sample

An integration with the Sage One api (v3.1) for WooCommerce - 
allowing orders to be turned into Sage One invoices at the click of a button (per order). 

This WordPress plugin is intended for use with a WooCommerce-based website.

Once the plugin has been installed, go to Wordpress Dashboard -> Settings -> Sage One Integration
to complete the setup (you will need to register an application with developerselfservice.sageone.com).

By default, a single invoice will be created based on the date of the order.
Filters allow this to be amended, e.g. to split the cost of the order over several invoices.
If an invoice with the same reference (the id of the order) and date already exists for the customer then no invoice will be created.

If a 'customer' contact can be found in Sage with the same email address as the WooCommerce order, that contact will be used for the invoices.
Otherwise a new contact will be created using details from the order. By default no address details will be used - these can be added/amended via a filter if necessary. 

Filters that can be hooked into:

**tl_wc_sage_filter_invoice_dates:**
```$php
 /**
  * Filters the invoice dates & amounts for the given order.
  * For each element in the array one invoice will be created, with all amounts multiplied by ( invoice_amount / order total ).
  *
  * @param      array     $invoice_amounts  Associative array [invoice_date => invoice_amount], where invoice_date is in the format 'd/m/Y'.
  * @param      WC_Order  $order            The order
  * @return     array     $invoice_amounts  Associative array [invoice_date => invoice_amount], where invoice_date is in the format 'd/m/Y'.
  */
``` 

**tl_wc_sage_filter_create_customer:**
```$php
 /**
  * Filters the fields passed to Sage when creating a contact for the customer.
  *
  * @param      object    $contact          Object representing the json object sent to sage when creating the contact.
  * @param      WC_Order  $order            The order
  * @return     array     $invoice_amounts  Associative array [invoice_date => invoice_amount], where invoice_date is in the format 'd/m/Y'.
  */
``` 
 
**tl_wc_sage_filter_create_invoice:**
```$php
 /**
  * Filters the fields passed to Sage when creating a sales invoice.
  *
  * @param      array     $sales_invoice    See https://developer.columbus.sage.com/docs#/uki/sageone/accounts/v3/sales_invoices_sales_invoice
  * @param      WC_Order  $order            The order
  * @return     array     $sales_invoice    See https://developer.columbus.sage.com/docs#/uki/sageone/accounts/v3/sales_invoices_sales_invoice.
  */
``` 
 
**tl_wc_sage_filter_create_journals:**
```$php
 /**
  * Filters the list of journal entries to be created in Sage.
  *
  * @param      array     $journal_entries  An initially empty array, to be populated with journals.
  * @param      WC_Order  $order            The order. See https://woocommerce.github.io/code-reference/classes/WC-Order.html
  * @param      object    $customer         The customer as they exist in Sage. See See https://developer.sage.com/api/accounting/api/contacts/#operation/getContactsKey.
  * @param      object    $invoices         Map of [invoice_date => invoice] containing the invoice(s) just created for this order keyed by dates in 'd/m/y' format. See https://developer.sage.com/api/accounting/api/invoicing-sales/#operation/getSalesInvoicesKey.
  * @return     array     $journal_entries  Array of journals to create in Sage  See https://developer.columbus.sage.com/docs#/uki/sageone/accounts/v3/sales_invoices_sales_invoice.
  */
``` 
 
