<?php

  namespace BatchProcessingApi;

  if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
  }

  class ScrubRepository extends BaseRepository
  {
    public static function deleteBulkDataByUserId($request)
    {

      set_time_limit(0);

      global $wpdb;

      $response = [];

      // Delete users

      $sql = "
        DELETE FROM 
            wp_users
        WHERE
            ID
        IN
            (
                SELECT 
                    user_id 
                FROM 
                    wp_usermeta 
                WHERE 
                    meta_key = 'wp_capabilities' 
                AND 
                    meta_value = 'a:1:{s:8:\"customer\";b:1;}'
            )
        ";
      $response["wp_users"] = $wpdb->query($wpdb->prepare($sql));

      $sql = "
        DELETE 
            um 
        FROM 
            wp_usermeta AS um
        WHERE
            user_id
        IN
            ( SELECT user_id FROM (
                SELECT 
                    user_id 
                FROM 
                    wp_usermeta
                WHERE 
                    meta_key = 'wp_capabilities' 
                AND 
                    meta_value = 'a:1:{s:8:\"customer\";b:1;}'
            ) AS x)
        ";
      $response["wp_usermeta"] = $wpdb->query($wpdb->prepare($sql));


      // Delete Orders & Subscriptions
      $sql = "
        DELETE FROM 
            wp_posts
        WHERE
            ID
        IN
            (
                SELECT 
                    post_id 
                FROM 
                    wp_postmeta
                WHERE 
                    meta_key = '_customer_user'
            )
        ";
      $response["wp_posts"] = $wpdb->query($wpdb->prepare($sql));

      $sql = "
        DELETE FROM 
            wp_comments
        WHERE
            comment_post_ID
        IN
            (
                SELECT 
                    post_id 
                FROM 
                    wp_postmeta 
                WHERE 
                    meta_key = '_customer_user'
            )
        ";
      $response["wp_comments"] = $wpdb->query($wpdb->prepare($sql));

      $sql = "DELETE FROM wp_wc_order_stats";
      $response["wp_wc_order_stats"] = $wpdb->query($wpdb->prepare($sql));

      $sql = "DELETE FROM wp_woocommerce_downloadable_product_permissions";
      $response["wp_woocommerce_downloadable_product_permissions"] = $wpdb->query($wpdb->prepare($sql));

      $sql = "DELETE FROM wp_wc_order_product_lookup";
      $response["wp_wc_order_product_lookup"] = $wpdb->query($wpdb->prepare($sql));

      $sql = "DELETE FROM wp_woocommerce_order_items";
      $response["wp_woocommerce_order_items"] = $wpdb->query($wpdb->prepare($sql));

      $sql = "DELETE pm FROM 
                    wp_postmeta AS pm
                WHERE 
                    post_id 
                IN 
                    ( SELECT post_id FROM (
                        SELECT 
                            post_id 
                        FROM 
                            wp_postmeta
                        WHERE 
                            meta_key = '_customer_user'
                    ) AS x)
        ";
      $response["wp_postmeta"] = $wpdb->query($wpdb->prepare($sql));

      $sql = "DELETE FROM wp_woocommerce_order_items";
      $response["wp_woocommerce_order_items"] = $wpdb->query($wpdb->prepare($sql));

      $sql = "DELETE FROM wp_woocommerce_order_itemmeta";
      $response["wp_woocommerce_order_itemmeta"] = $wpdb->query($wpdb->prepare($sql));

      wp_send_json_success($response, 201);
    }
  }