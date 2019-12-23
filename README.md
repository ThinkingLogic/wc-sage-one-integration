# Thinking Logic Integration for Sage Cloud API to create invoices for WooCommerce orders.

Based on code samples provided here: https://github.com/Sage/sageone_api_php_sample

An integration with the Sage One api (v3.1) for WooCommerce - 
allowing orders to be turned into Sage One invoices at the click of a button (per order). 

This WordPress plugin is intended for use with a WooCommerce-based website that allows customers to book courses that
occur over several weeks.

One invoice will be created per order per month. 
Each invoice will be allocated a fraction of the total cost of the order, according to how many lessons occur in that month.