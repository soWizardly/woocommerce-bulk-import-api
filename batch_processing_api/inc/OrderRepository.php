<?php

  namespace BatchProcessingApi;

  if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
  }

  class OrderRepository extends BaseRepository
  {
    public static function createOrders($request)
    {
      $orders = self::getOrderData($request->get_param("orders") ?? []);
      if (!empty($orders)) {
        $orders = self::insertWCCustomPost($orders);
        self::insertOrderMeta($orders);

        $refunds = self::getRefundedOrders($orders);
        if (!empty($refunds)) {
          $refunds = self::insertWCCustomPost($refunds);
          self::insertOrderMeta($refunds, true);
          self::setParentOrderStatus();
        }
      }

      wp_send_json_success(self::formatResponse($orders), 201);
    }

    protected static function setParentOrderStatus()
    {
      global $wpdb;
      $sql = "
        UPDATE 
            wp_posts
        SET 
            post_status = 'wc-refunded'
        WHERE 
            post_status = 'wc-bulk-refunded'";
      $wpdb->query($sql);
    }

    protected static function getRefundedOrders($orders)
    {
      $refundedOrders = [];
      foreach ($orders as $order) {
        if ($order["data"]["post_status"] == "wc-bulk-refunded") {

          $orderKey = 'wc_order_' . bin2hex(random_bytes(5));
          // random number tacked to end. not ideal, time constraint.
          $postName = substr(str_replace("order", "refund", $order["data"]["post_name"]), 18);
          // We use this as a unique identifier & later clean it up
          $name = "batchapi:" . random_int(10000000, 99999999) . ":" . $postName;

          $refundedOrders[$name]["data"] = $order["data"];
          $refundedOrders[$name]["data"]["post_title"] = str_replace("Order", "Refund", $order["data"]["post_title"]);
          $refundedOrders[$name]["data"]["post_status"] = "wc-completed";
          $refundedOrders[$name]["data"]["post_password"] = $orderKey;
          $refundedOrders[$name]["data"]["post_name"] = $name;
          $refundedOrders[$name]["data"]["post_parent"] = $order["data"]["wp_id"];
          $refundedOrders[$name]["data"]["post_type"] = "shop_order_refund";
          unset($refundedOrders[$name]["data"]["wp_id"]);


          $refundedOrders[$name]["meta"]["postmeta"] = [
            "_cart_discount" => 0,
            "_cart_discount_tax" => 0,
            "_order_currency" => "USD",
            "_order_shipping" => 0,
            "_order_shipping_tax" => 0,
            "_order_tax" => 0,
            "_order_total" => "-" . $order["meta"]["postmeta"]["_order_total"],
            "_order_version" => "6.1.1", //wc version
            "_prices_include_tax" => "no",
            "_refund_amount" => $order["meta"]["postmeta"]["_order_total"],
            "_refund_reason" => "Order fully refunded",
            "_refunded_by" => get_current_user_id(),
            "_refunded_payment" => ""
          ];
        }
      }
      return $refundedOrders;
    }

    protected static function formatResponse($orders)
    {
      $response = [];
      foreach ($orders as $order) {
        $response[] = [
          "id" => $order["data"]["wp_id"],
          "customer_id" => $order["meta"]["postmeta"]["_customer_user"]
        ];
      }

      return $response;
    }

    protected static function insertOrderMeta($orders, $isRefund = false)
    {
      global $wpdb;

      $values = [];
      $comments = [];
      $wcOrderStats = [];
      $wcDownloadProductPerms = [];
      $wcOrderItems = [];
      $orderIds = [];
      $orderItemIds = [];
      $wcOrderItemMeta = [];
      $wcOrderProductLookup = [];

      foreach ($orders as $order) {
        foreach ($order["meta"]["postmeta"] as $key => $value) {
          $values[] = [$order["data"]["wp_id"], $key, $value];
        }

        if (!empty($order["meta"]["comments"])) {
          array_unshift($order["meta"]["comments"], $order["data"]["wp_id"]);
          $comments[] = array_values($order["meta"]["comments"]);
        }

        if (!empty($order["meta"]["wc_order_stats"])) {
          array_unshift($order["meta"]["wc_order_stats"], $order["data"]["wp_id"]);
          $wcOrderStats[] = array_values($order["meta"]["wc_order_stats"]);
        }

        if (!empty($order["meta"]["woocommerce_downloadable_product_permissions"])) {
          array_unshift($order["meta"]["woocommerce_downloadable_product_permissions"], $order["data"]["wp_id"]);
          $wcDownloadProductPerms[] = array_values($order["meta"]["woocommerce_downloadable_product_permissions"]);
        }

        if (!empty($order["meta"]["woocommerce_order_items"])) {
          array_unshift($order["meta"]["woocommerce_order_items"], $order["data"]["wp_id"]);
          $wcOrderItems[] = array_values($order["meta"]["woocommerce_order_items"]);
        }

        $orderIds[] = $order["data"]["wp_id"];
      }

      $sql = "
        INSERT INTO
            wp_postmeta
            (post_id, meta_key, meta_value)
        VALUES
            " . implode(", ", array_fill(0, count($values), "(%d,%s,%s)")) . "
        ON DUPLICATE KEY UPDATE
        meta_value=VALUES(meta_value)
        ";
      $wpdb->query($wpdb->prepare($sql, array_merge(...$values)));

      if (!empty($comments)) {
        $sql = "
        INSERT IGNORE INTO wp_comments
            (comment_post_ID, comment_author, comment_author_email, comment_author_url,
             comment_author_IP, comment_date, comment_date_gmt, comment_content,
             comment_karma, comment_approved, comment_agent, comment_type,
             comment_parent, user_id)
        VALUES
            " . implode(", ", array_fill(0, count($comments), "(%d,%s,%s,%s,%s,%s,%s,%s,%d,%d,%s,%s,%d,%d)")) . "
        ";
        $wpdb->query($wpdb->prepare($sql, array_merge(...$comments)));
      }

      if (!empty($wcOrderStats)) {
        $sql = "
        INSERT IGNORE INTO wp_wc_order_stats
            (order_id, parent_id, date_created, date_created_gmt, num_items_sold, total_sales,
             tax_total, shipping_total, net_total, returning_customer, status, customer_id)
        VALUES
            " . implode(", ", array_fill(0, count($wcOrderStats), "(%d, %d, %s, %s, %d, %d, %d, %d, %d, %d, %s, %d)")) . "
        ";
        $wpdb->query($wpdb->prepare($sql, array_merge(...$wcOrderStats)));
      }

      if (!empty($wcDownloadProductPerms)) {
        $sql = "
        INSERT INTO wp_woocommerce_downloadable_product_permissions
            (order_id, download_id, product_id, order_key, user_email, user_id, downloads_remaining, 
             access_granted, access_expires, download_count)
        VALUES
            " . implode(", ", array_fill(0, count($wcDownloadProductPerms), "(%d, %s, %d, %s, %s, %d, %s, %s, %s, %d)")) . "
        ";
        $wpdb->query($wpdb->prepare($sql, array_merge(...$wcDownloadProductPerms)));
      }

      if (!empty($wcOrderItems)) {
        $sql = "
        INSERT IGNORE INTO wp_woocommerce_order_items
            (order_id, order_item_name, order_item_type)
        VALUES
            " . implode(", ", array_fill(0, count($wcOrderItems), "(%d, %s, %s)")) . "
        ";
        $wpdb->query($wpdb->prepare($sql, array_merge(...$wcOrderItems)));
      }

      if (!$isRefund) {
        $sql = "
        SELECT 
            order_id, order_item_id
        FROM 
            wp_woocommerce_order_items 
        WHERE 
            order_id
        IN
            (" . implode(", ", array_fill(0, count($orderIds), "%d")) . ")
        ";
        $results = $wpdb->get_results($wpdb->prepare($sql, $orderIds));
        foreach ($results as $result) {
          $orderItemIds[$result->order_id] = $result->order_item_id;
        }

        foreach ($orders as $order) {
          foreach ($order["meta"]["woocommerce_order_itemmeta"] as $key => $value) {
            $wcOrderItemMeta[] = [$orderItemIds[$order["data"]["wp_id"]], $key, $value];
          }

          array_unshift($order["meta"]["wc_order_product_lookup"], $order["data"]["wp_id"]);
          array_unshift($order["meta"]["wc_order_product_lookup"], $orderItemIds[$order["data"]["wp_id"]]);
          $wcOrderProductLookup[] = array_values($order["meta"]["wc_order_product_lookup"]);
        }

        $sql = "
        INSERT IGNORE INTO wp_woocommerce_order_itemmeta
            (order_item_id, meta_key, meta_value)
        VALUES
            " . implode(", ", array_fill(0, count($wcOrderItemMeta), "(%d, %s, %s)")) . "
        ";
        $wpdb->query($wpdb->prepare($sql, array_merge(...$wcOrderItemMeta)));

        $sql = "
        INSERT IGNORE INTO wp_wc_order_product_lookup
            (order_item_id, order_id, product_id, variation_id, customer_id, 
             date_created, product_qty, product_net_revenue, product_gross_revenue, 
             coupon_amount, tax_amount, shipping_amount, shipping_tax_amount)
        VALUES
            " . implode(", ", array_fill(0, count($wcOrderProductLookup), "(%d, %d, %d, %d, %d, %s, %d, %d, %d, %d, %d, %d, %d)")) . "
        ";
        $wpdb->query($wpdb->prepare($sql, array_merge(...$wcOrderProductLookup)));
      }
    }

    protected static function getOrderData($userInput)
    {
      [$orders, $userId, $link] = [
        [], get_current_user_id(),
        get_site_url() . "/?post_type=shop_order&#038;p="
      ];

      $users = [];
      $variations = [];
      foreach ($userInput ?? [] as $order) {
        $users[] = $order["customer_id"];
        $variations[] = $order["line_items"][0]["variation_id"];
      }
      $users = CustomerRepository::getUserEmailByIds($users);
      $variations = self::getPostData($variations);
      $downloads = ProductRepository::getProductDownloadsByVariationIds(array_keys($variations));

      if (!empty($users)) {

        foreach ($userInput ?? [] as $order) {

          if (!empty($order["order_date"]) && !empty($order["status"])) {

            $time = date("Y-m-d h:i:s", $order["order_date"]);

            $postTitle = "Order &ndash; " . date("F d, Y \@ g:i A", $order["order_date"]);

            // random number tacked to end. not ideal, time constraint.
            $postName = "order-" . strtolower(date("M-d-Y-hi-a-", $order["order_date"])) . random_int(100, 999);

            // We use this as a unique identifier & later clean it up
            $name = "batchapi:" . random_int(10000000, 99999999) . ":" . $postName;

            $status = "wc-" . $order["status"];

            $orderKey = 'wc_order_' . bin2hex(random_bytes(5));

            // hack (time constraint) to get the subscription term since we know how it's formatted.
            $subscriptionTerm = str_replace(" ", "_", strtolower(trim((explode(": ", $variations[$order["line_items"][0]["variation_id"]]["post_excerpt"]))[1])));

            $orders[$name]["data"] = [
              "post_author" => $userId,
              "post_date" => $time,
              "post_date_gmt" => $time,
              "post_content" => '',
              "post_title" => $postTitle,
              "post_excerpt" => '',
              "post_status" => $status,
              "comment_status" => 'open',
              "ping_status" => 'closed',
              "post_password" => $orderKey,
              "post_name" => $name,
              "to_ping" => '',
              "pinged" => '',
              "post_modified" => $time,
              "post_modified_gmt" => $time,
              "post_content_filtered" => '',
              "post_parent" => 0,
              "guid" => $link,
              "menu_order" => 0,
              "post_type" => "shop_order",
              "post_mime_type" => "",
              "comment_count" => 1
            ];

            $orders[$name]["meta"]["postmeta"] = [
              "_order_key" => $orderKey,
              "_customer_user" => $order["customer_id"],
              "_payment_method" => $order["payment_method"],
              "_payment_method_title" => $order["payment_method_title"],
              "_created_via" => "rest-api",
              "_billing_first_name" => $order["billing"]["first_name"] ?? '',
              "_billing_last_name" => $order["billing"]["last_name"] ?? '',
              "_billing_address_1" => $order["billing"]["address_1"] ?? '',
              "_billing_city" => $order["billing"]["city"] ?? '',
              "_billing_state" => $order["billing"]["state"] ?? '',
              "_billing_postcode" => $order["billing"]["postcode"] ?? '',
              "_billing_country" => $order["billing"]["country"] ?? '',
              "_billing_email" => $order["billing"]["email"] ?? '',
              "_billing_phone" => $order["billing"]["phone"] ?? '',
              "_shipping_first_name" => $order["shipping"]["first_name"] ?? '',
              "_shipping_last_name" => $order["shipping"]["last_name"] ?? '',
              "_order_currency" => "USD",
              "_cart_discount" => 0,
              "_cart_discount_tax" => 0,
              "_order_shipping" => 0,
              "_order_shipping_tax" => 0,
              "_order_tax" => 0,
              "_order_total" => $order["order_total"],
              "_order_version" => "5.9.0", //wc version
              "_prices_include_tax" => "no",
              //added
              "_wpo_order_creator" => $order["_wpo_order_creator"],
              "_subscription_renewal" => $order["_subscription_renewal"]
            ];

            $orders[$name]["meta"]["comments"] = [
              //"comment_post_ID" => 65536,
              "comment_author" => "WooCommerce",
              "comment_author_email" => "woocommerce@wp.tradersonly.com",
              "comment_author_url" => "",
              "comment_author_IP" => "",
              "comment_date" => $time,
              "comment_date_gmt" => $time,
              "comment_content" => "Order status changed from Pending payment to {$order["status"]}.",
              "comment_karma" => 0,
              "comment_approved" => 1,
              "comment_agent" => "WooCommerce",
              "comment_type" => "order_note",
              "comment_parent" => 0,
              "user_id" => 0,
            ];

            $orders[$name]["meta"]["wc_order_stats"] = [
              //"order_id" => 123,
              "parent_id" => 0,
              "date_created" => $time,
              "date_created_gmt" => $time,
              "num_items_sold" => 1,
              "total_sales" => $order["order_total"],
              "tax_total" => 0,
              "shipping_total" => 0,
              "net_total" => $order["order_total"],
              "returning_customer" => 0,
              "status" => $status,
              "customer_id" => $order["customer_id"]
            ];

            //work around old PHP no array_key_first
            $downloadId = array_keys($downloads[$order["line_items"][0]["variation_id"]])[0];

            $orders[$name]["meta"]["woocommerce_downloadable_product_permissions"] = [
              "download_id" => $downloadId,
              "product_id" => $order["line_items"][0]["product_id"],
              //"order_id" => '',
              "order_key" => $orderKey,
              "user_email" => $users[$order["customer_id"]],
              "user_id" => $order["customer_id"],
              "downloads_remaining" => '',
              "access_granted" => $time,
              "access_expires" => null,
              "download_count" => 0,
            ];

            $orders[$name]["meta"]["woocommerce_order_items"] = [
              "order_item_name" => $variations[$order["line_items"][0]["variation_id"]]["post_title"],
              "order_item_type" => "line_item",
              //"order_id" => 123
            ];

            $orders[$name]["meta"]["woocommerce_order_itemmeta"] = [
              "_product_id" => $order["line_items"][0]["product_id"],
              "_variation_id" => $order["line_items"][0]["variation_id"],
              "_qty" => 1,
              "_tax_class" => '',
              "_line_subtotal" => $order["order_total"],
              "_line_subtotal_tax" => 0,
              "_line_total" => $order["order_total"],
              "_line_tax" => 0,
              "_line_tax_data" => 'a:2:{s:5:"total";a:0:{}s:8:"subtotal";a:0:{}}',
              // time constraint.
              "pa_subscription_term" => $subscriptionTerm
            ];

            $orders[$name]["meta"]["wc_order_product_lookup"] = [
              //"order_item_id" => 123,
              //"order_id" => 123,
              // time constraints. this could be handled better.
              "product_id" => $order["line_items"][0]["product_id"],
              "variation_id" => $order["line_items"][0]["variation_id"],
              "customer_id" => $order["customer_id"],
              "date_created" => $time,
              "product_qty" => 1,
              "product_net_revenue" => $order["order_total"],
              "product_gross_revenue" => $order["order_total"],
              "coupon_amount" => 0,
              "tax_amount" => 0,
              "shipping_amount" => 0,
              "shipping_tax_amount" => 0,
            ];
          }
        }
      }

      return $orders;

    }

    public static function getOrderKeyByOrderId($postIds)
    {
      global $wpdb;
      $sql = "
        SELECT 
            post_id, meta_value
        FROM 
            wp_postmeta
        WHERE 
            meta_key='_order_key'
        AND
            post_id
        IN
            (" . implode(", ", array_fill(0, count($postIds), "%d")) . ")
        ";
      $results = $wpdb->get_results($wpdb->prepare($sql, $postIds));

      $postData = [];
      foreach ($results as $result) {
        $postData[$result->post_id] = $result->meta_value;
      }

      return $postData;
    }
  }