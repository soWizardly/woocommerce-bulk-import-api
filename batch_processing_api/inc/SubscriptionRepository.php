<?php

  namespace BatchProcessingApi;

  if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
  }

  class SubscriptionRepository extends BaseRepository
  {
    public static function createSubscriptions($request)
    {
      $subscriptions = self::getSubscriptionData($request->get_param("subscriptions") ?? []);
      if (!empty($subscriptions)) {
        $subscriptions = self::insertWCCustomPost($subscriptions);
        self::insertSubscriptionMeta($subscriptions);
      }
      wp_send_json_success([], 201);
    }

    protected static function insertSubscriptionMeta($subscriptions)
    {
      global $wpdb;

      $values = [];
      $comments = [];
      $wcOrderItems = [];
      $subscriptionIds = [];
      $wcOrderItemMeta = [];
      $orderItemIds = [];

      foreach ($subscriptions as $subscription) {
        foreach ($subscription["meta"]["postmeta"] as $key => $value) {
          $values[] = [$subscription["data"]["wp_id"], $key, $value];
        }
        array_unshift($subscription["meta"]["comments"], $subscription["data"]["wp_id"]);
        $comments[] = array_values($subscription["meta"]["comments"]);

        array_unshift($subscription["meta"]["woocommerce_order_items"], $subscription["data"]["wp_id"]);
        $wcOrderItems[] = array_values($subscription["meta"]["woocommerce_order_items"]);

        $subscriptionIds[] = $subscription["data"]["wp_id"];
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

      $sql = "
        INSERT IGNORE INTO wp_woocommerce_order_items
            (order_id, order_item_name, order_item_type)
        VALUES
            " . implode(", ", array_fill(0, count($wcOrderItems), "(%d, %s, %s)")) . "
        ";
      $wpdb->query($wpdb->prepare($sql, array_merge(...$wcOrderItems)));

      $sql = "
        SELECT 
            order_id, order_item_id
        FROM 
            wp_woocommerce_order_items 
        WHERE 
            order_id
        IN
            (" . implode(", ", array_fill(0, count($subscriptionIds), "%d")) . ")
        ";
      $results = $wpdb->get_results($wpdb->prepare($sql, $subscriptionIds));
      foreach ($results as $result) {
        $orderItemIds[$result->order_id] = $result->order_item_id;
      }

      foreach ($subscriptions as $subscription) {
        foreach ($subscription["meta"]["woocommerce_order_itemmeta"] as $key => $value) {
          $wcOrderItemMeta[] = [$orderItemIds[$subscription["data"]["wp_id"]], $key, $value];
        }
      }

      $sql = "
        INSERT IGNORE INTO wp_woocommerce_order_itemmeta
            (order_item_id, meta_key, meta_value)
        VALUES
            " . implode(", ", array_fill(0, count($wcOrderItemMeta), "(%d, %s, %s)")) . "
        ";
      $wpdb->query($wpdb->prepare($sql, array_merge(...$wcOrderItemMeta)));
    }

    protected static function getSubscriptionData($userInput)
    {
      [$subscriptions, $userId, $link] = [
        [], get_current_user_id(),
        get_site_url() . "/?post_type=shop_subscription&p="
      ];

      $orderIds = [];

      if (!empty($userId)) {


        $variations = [];
        foreach ($userInput ?? [] as $subscription) {
          $orderIds[] = $subscription["parent_id"];
          $variations[] = $subscription["line_items"][0]["variation_id"];
        }
        $orderKeys = OrderRepository::getOrderKeyByOrderId($orderIds);
        $variations = self::getPostData($variations);

        if (!empty($orderKeys)) {

          foreach ($userInput ?? [] as $subscription) {

            $time = date("Y-m-d h:i:s", strtotime($subscription["start_date"]));

            // random number tacked to end. not ideal, time constraint.
            $postName = "order-" . strtolower(date("M-d-Y-hi-a-", strtotime($subscription["start_date"]))) . random_int(100, 999);

            $postTitle = "Order &ndash; " . date("F d, Y \@ g:i A", strtotime($subscription["start_date"]));

            // We use this as a unique identifier & later clean it up
            $name = "batchapi:" . random_int(10000000, 99999999) . ":" . $postName;

            $status = "wc-" . $subscription["status"];

            $orderKey = 'wc_order_' . bin2hex(random_bytes(5));

            // hack (time constraint) to get the subscription term since we know how it's formatted.
            $subscriptionTerm = str_replace(" ", "_", strtolower(trim((explode(": ",$variations[$subscription["line_items"][0]["variation_id"]]["post_excerpt"]))[1])));

            $billingIndex = $subscription["billing"]["first_name"] . " " .
              $subscription["billing"]["last_name"] . " " .
              $subscription["billing"]["address_1"] . " " .
              $subscription["billing"]["city"] . " " .
              $subscription["billing"]["state"] . " " .
              $subscription["billing"]["postcode"] . " " .
              $subscription["billing"]["country"] . " " .
              $subscription["billing"]["email"] . " " .
              $subscription["billing"]["phone"];

            $shippingIndex = $subscription["shipping"]["first_name"] . " " .
              $subscription["shipping"]["last_name"] . " " .
              $subscription["shipping"]["address_1"] . " " .
              $subscription["shipping"]["city"] . " " .
              $subscription["shipping"]["state"] . " " .
              $subscription["shipping"]["postcode"] . " " .
              $subscription["shipping"]["country"];

            $subscriptions[$name]["data"] = [
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
              "post_parent" => $subscription["parent_id"],
              "guid" => $link,
              "menu_order" => 0,
              "post_type" => "shop_subscription",
              "post_mime_type" => "",
              "comment_count" => 1
            ];

            $subscriptions[$name]["meta"]["postmeta"] = array_merge([
              "_billing_period" => $subscription["billing_period"],
              "_billing_interval" => $subscription["billing_interval"],
              "_suspension_count" => 0,
              "_cancelled_email_sent" => null,
              "_requires_manual_renewal" => false,
              "_trial_period" => null,
              "_schedule_trial_end" => 0,
              "_schedule_next_payment" => $subscription["next_payment_date"],
              "_schedule_cancelled" => 0,
              "_schedule_end" => $subscription["next_payment_date"],
              "_schedule_payment_retry" => 0,
              "_schedule_start" => $subscription["start_date"],
              "_subscription_switch_data" => "a:0:{}",
              "_order_key" => $orderKeys[$subscription["parent_id"]],
              "_customer_user" => $subscription["customer_id"],
              "_payment_method" => $subscription["payment_method"],
              "_payment_method_title" => $subscription["payment_method_title"],
              "_created_via" => "rest-api",
              "_billing_first_name" => $subscription["billing"]["first_name"],
              "_billing_last_name" => $subscription["billing"]["last_name"],
              "_billing_address_1" => $subscription["billing"]["address_1"],
              "_billing_city" => $subscription["billing"]["city"],
              "_billing_state" => $subscription["billing"]["state"],
              "_billing_postcode" => $subscription["billing"]["postcode"],
              "_billing_country" => $subscription["billing"]["country"],
              "_billing_email" => $subscription["billing"]["email"],
              "_billing_phone" => $subscription["billing"]["phone"],
              "_shipping_first_name" => $subscription["shipping"]["first_name"],
              "_shipping_last_name" => $subscription["shipping"]["last_name"],
              "_shipping_address_1" => $subscription["shipping"]["address_1"],
              "_shipping_city" => $subscription["shipping"]["city"],
              "_shipping_state" => $subscription["shipping"]["state"],
              "_shipping_postcode" => $subscription["shipping"]["postcode"],
              "_shipping_country" => $subscription["shipping"]["country"],
              "_order_currency" => "USD",
              "_cart_discount" => 0,
              "_cart_discount_tax" => 0,
              "_order_shipping" => 0,
              "_order_shipping_tax" => 0,
              "_order_tax" => 0,
              "_order_total" => $subscription["order_total"],
              "_order_version" => "5.9.0",
              "_prices_include_tax" => "no",
              "_billing_address_index" => $billingIndex,
              "_shipping_address_index" => $shippingIndex,
              "_subscription_renewal_order_ids_cache" => "a:0:{}",
              "_subscription_resubscribe_order_ids_cache" => "a:0:{}",
              "_subscription_switch_order_ids_cache" => "a:0:{}",

              // $subscription["payment_details"]["post_meta"] contains...
              // "_wc_authorize_net_cim_credit_card_customer_id" => 123,
              // "_wc_authorize_net_cim_credit_card_payment_token" => 456
            ], $subscription["payment_details"]["post_meta"] ?? []);

            $subscriptions[$name]["meta"]["woocommerce_order_items"] = [
              "order_item_name" => $variations[$subscription["line_items"][0]["variation_id"]]["post_title"],
              "order_item_type" => "line_item",
              //"order_id" => 123
            ];

            $subscriptions[$name]["meta"]["woocommerce_order_itemmeta"] = [
              "_product_id" => $subscription["line_items"][0]["product_id"],
              "_variation_id" => $subscription["line_items"][0]["variation_id"],
              "_qty" => 1,
              "_tax_class" => '',
              "_line_subtotal" => $subscription["order_total"],
              "_line_subtotal_tax" => 0,
              "_line_total" => $subscription["order_total"],
              "_line_tax" => 0,
              "_line_tax_data" => 'a:2:{s:5:"total";a:0:{}s:8:"subtotal";a:0:{}}',
              // time constraint.
              "pa_subscription_term" => $subscriptionTerm
            ];

            $subscriptions[$name]["meta"]["comments"] = [
              //"comment_post_ID" => 65536,
              "comment_author" => "WooCommerce",
              "comment_author_email" => "woocommerce@wp.tradersonly.com",
              "comment_author_url" => "",
              "comment_author_IP" => "",
              "comment_date" => $time,
              "comment_date_gmt" => $time,
              "comment_content" => "Order status changed from Pending payment to {$subscription["status"]}.",
              "comment_karma" => 0,
              "comment_approved" => 1,
              "comment_agent" => "WooCommerce",
              "comment_type" => "order_note",
              "comment_parent" => 0,
              "user_id" => 0,
            ];

          }
        }
      }
      return $subscriptions;
    }
  }