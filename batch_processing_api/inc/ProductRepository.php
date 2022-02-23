<?php

  namespace BatchProcessingApi;

  if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
  }

  class ProductRepository extends BaseRepository
  {
    public static function createProductVariations($request)
    {
      $variations = self::getProductVariationData($request->get_param("product-variations") ?? []);

      $variations = self::insertWCCustomPost($variations);

      self::insertVariationPostMeta($variations);

      wp_send_json_success(self::formatResponse($variations), 201);
    }

    protected static function formatResponse($variations)
    {
      $response = [];
      foreach ($variations as $variation) {
        $response[] = [
          "id" => $variation["data"]["wp_id"],
          "regular_price" => $variation["meta"]["postmeta"]["_regular_price"],
          "attribute_option" => ucwords(str_replace("_", " ", $variation["meta"]["postmeta"]["attribute_pa_subscription_term"])) //time
        ];
      }

      return $response;
    }

    protected static function getMenuOrderByPostParentIds(array $postParentIds)
    {
      global $wpdb;

      $sql = "
        SELECT 
            post_parent, max(menu_order)+1 AS menu_order 
        FROM 
            wp_posts 
        WHERE 
            post_parent
        IN 
            (" . implode(", ", array_fill(0, count($postParentIds), "%d")) . ")
        GROUP BY
            post_parent";
      return $wpdb->get_results($wpdb->prepare($sql, $postParentIds));
    }

    protected static function getMenuOrders(array $userInput)
    {
      $menuOrders = [];
      foreach ($userInput ?? [] as $variation) {
        if (!empty($variation["product_id"])) {
          $menuOrders[$variation["product_id"]] = 0;
        }
      }

      if (!empty($menuOrders)) {
        $results = self::getMenuOrderByPostParentIds(array_keys($menuOrders));
        foreach ($results as $menuOrder) {
          $menuOrders[$menuOrder->post_parent] = $menuOrder->menu_order;
        }
      }

      return $menuOrders;
    }

    protected static function getProductVariationData(array $userInput)
    {

      [$variations, $userId, $time, $link, $menuOrders] = [
        [], get_current_user_id(), current_time("mysql", true),
        get_site_url() . "/?post_type=product_variation&p=",
        self::getMenuOrders($userInput)
      ];

      // getMenuOrders sanity check -- empty array if no product id's set for any userInput.
      if (!empty($menuOrders)) {

        $parentProducts = self::getPostData(array_keys($menuOrders));

        foreach ($userInput ?? [] as $variation) {

          // still need to check product id's as above only sees if there's at least 1 in total.
          if (!empty($variation["product_id"])) {

            $postTitle = $parentProducts[$variation["product_id"]]["post_title"] . ' - ' . $variation["attribute_option"];

            // Should have pulled attribute name from DB, but time constraints dictate.
            $postExcerpt = "Subscription Term: " . $variation["attribute_option"];

            // this might be improved upon later to allow for more than one attribute per variation.
            $postName = $parentProducts[$variation["product_id"]]["post_name"] . '-' .
                        str_replace(" ", "-", strtolower($variation["attribute_option"]));

            // We use this as a unique identifier & later clean it up
            $name = "batchapi:".random_int(10000000,99999999).":" . $postName;

            $productDownloadUUID = wp_generate_uuid4();

            $variations[$name]["data"] = [
              "post_author" => $userId,
              "post_date" => $time,
              "post_date_gmt" => $time,
              "post_content" => '',
              "post_title" => $postTitle,
              "post_excerpt" => $postExcerpt,
              "post_status" => 'publish',
              "comment_status" => 'closed',
              "ping_status" => 'closed',
              "post_password" => '',
              "post_name" => $name,
              "to_ping" =>  '',
              "pinged" => '',
              "post_modified" => $time,
              "post_modified_gmt" =>  $time,
              "post_content_filtered" => '',
              "post_parent" => $variation["product_id"],
              "guid" => $link,
              "menu_order" => $menuOrders[$variation["product_id"]],
              "post_type" => "product_variation",
              "post_mime_type" => "",
              "comment_count" => 0
            ];

            $variations[$name]["meta"]["postmeta"] = [
              "_variation_description" => "",
              "_regular_price" => $variation["_regular_price"],
              "total_sales" => 0,
              "_tax_status" => "taxable",
              "_tax_class" => "parent",
              "_manage_stock" => "no",
              "_backorders" => "no",
              "_sold_individually" => "no",
              "_virtual" => "yes",
              "_downloadable" => "yes",
              "_download_limit" => -1,
              "_download_expiry" => -1,
              "_stock" => null,
              "_stock_status" => "instock",
              "_wc_average_rating" => 0,
              "_wc_review_count" => 0,

              //time constraint.
              "attribute_pa_subscription_term" => str_replace(" ", "_", strtolower($variation["attribute_option"])),

              "_price" => $variation["_regular_price"],
              "_product_version" => "5.9.0", //woocommerce version
              "_thumbnail_id" => 0,
              "_subscription_payment_sync_date" => 0,
              "_downloadable_files" => serialize([
                $productDownloadUUID => [
                  "id" => $productDownloadUUID,
                  "name" => "data",
                  "file" => "http://wp.tradersonly.com/file.zip"
              ]]),
              "_subscription_sign_up_fee" => 0,
              "_subscription_price" => $variation["_regular_price"],
              "_subscription_period" => $variation["_subscription_period"],
              "_subscription_period_interval" => $variation["_subscription_period_interval"],
              "_subscription_length" => 0,
              "_subscription_trial_period" => "day",
              "_subscription_trial_length" => 0
            ];

            $variations[$name]["meta"]["wc_product_meta_lookup"] = [
              //"product_id" => 123,
              "sku" => '',
              "virtual" => 1,
              "downloadable" => 1,
              "min_price" => $variation["_regular_price"],
              "max_price" => $variation["_regular_price"],
              "onsale" => 0,
              "stock_quantity" => null,
              "stock_status" => "instock",
              "rating_count" => 0,
              "average_rating" => 0.00,
              "total_sales" => 0,
              "tax_status" => "taxable",
              "tax_class" => "parent"
            ];

            $menuOrders[$variation["product_id"]]++;
          }
        }
      }
      return $variations;
    }

    protected static function insertVariationPostMeta(array $variations)
    {
      global $wpdb;

      [$values,$wcvalues] = [[],[]];
      foreach ($variations as $variation) {
        foreach ($variation["meta"]["postmeta"] as $key => $value) {
          $values[] = [$variation["data"]["wp_id"], $key, $value];
        }

        array_unshift($variation["meta"]["wc_product_meta_lookup"], $variation["data"]["wp_id"]);
        $wcvalues[] = array_values($variation["meta"]["wc_product_meta_lookup"]);
      }

      $sql = "
        INSERT IGNORE INTO
            wp_postmeta
            (post_id, meta_key, meta_value)
        VALUES
            " . implode(", ", array_fill(0, count($values), "(%d,%s,%s)")) . "
        ON DUPLICATE KEY UPDATE
        meta_value=VALUES(meta_value)
        ";
      $wpdb->query($wpdb->prepare($sql, array_merge(...$values)));

      $sql = "
        INSERT IGNORE INTO wp_wc_product_meta_lookup
            (product_id, sku, `virtual`, downloadable,
            min_price, max_price, onsale, stock_quantity,
            stock_status, rating_count, average_rating, total_sales,
            tax_status, tax_class)
        VALUES
            " . implode(", ", array_fill(0, count($wcvalues), "(%d,%s,%d,%d,%s,%s,%d,%s,%s,%d,%s,%d,%s,%s)")) . "
        ";
      $wpdb->query($wpdb->prepare($sql, array_merge(...$wcvalues)));
    }

    public static function getProductDownloadsByVariationIds(array $postIds)
    {
      global $wpdb;
      $sql = "
        SELECT 
            post_id, meta_value
        FROM 
            wp_postmeta
        WHERE 
            meta_key='_downloadable_files'
        AND
            post_id 
        IN
            (" . implode(", ", array_fill(0, count($postIds), "%d")) . ")
        ";
      $results = $wpdb->get_results($wpdb->prepare($sql, $postIds));

      $downloads = [];
      foreach ($results as $result) {
        if (!empty($result->meta_value)) {
          $downloads[$result->post_id] = unserialize($result->meta_value);
        }
      }
      return $downloads;
    }
  }