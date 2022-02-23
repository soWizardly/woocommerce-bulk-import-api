# Woocommerce Bulk Import API

Plugin that exposes a Bulk Import JSON API: `/wp-json/wc/v3/batch/[item]`

Customers [customer]

Product Variations [product-variation]

Orders [order]

Subscriptions [subscription]

Also provides a [delete] method to wipe all customer records.

These endpoints generally follow the schema of the default Woocommerce API's, 
just that they accept an array of records instead.