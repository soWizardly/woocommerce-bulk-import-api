<?php

  namespace BatchProcessingApi;

  if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
  }

  class CustomerRepository extends BaseRepository
  {
    public static function createCustomers($request)
    {
      [$customers, $values] = self::getCustomerData($request->get_param("customers") ?? []);

      if (empty($customers)) {
        wp_send_json_error(['message' => 'You must provide customers to process.'], 400);
      }

      self::insertUsers($values);

      $results = self::getUserIds($customers);

      $metaValues = [];
      foreach ($results as $result) {
        foreach ($customers[$result->user_email]["meta"] as $mv) {
          // the id is intentionally set first.
          array_unshift($mv, $result->id);
          $metaValues[] = $mv;
        }
        $customers[$result->user_email]["id"] = $result->id;
      }

      self::insertUserMeta($metaValues);

      wp_send_json_success(self::formatResponse($customers), 201);

    }

    protected static function formatResponse($customers)
    {
      $response = [];
      foreach ($customers as $customer) {
        $response[] = [
          "id" => $customer["id"],
          "email" => $customer["email"]
        ];
      }

      return $response;
    }

    protected static function getCustomerData(array $userInput)
    {

      [$customers, $values, $time] = [[], [], current_time("mysql", true)];

      foreach ($userInput ?? [] as $customer) {

        if (!empty($customer["email"])) {
          $nice = preg_replace("/[^a-zA-Z0-9]+/", "", $customer["email"]);
          $customers[$customer["email"]] = $customer;
          $customers[$customer["email"]]["meta"] = [
            ["virtual", $customer["virtual"] ?? ''],
            ["first_name", $customer["first_name"] ?? ''],
            ["last_name", $customer["last_name"] ?? ''],
            ["billing_first_name", $customer["billing"]["first_name"] ?? ''],
            ["billing_last_name", $customer["billing"]["last_name"] ?? ''],
            ["billing_address_1", $customer["billing"]["address_1"] ?? ''],
            ["billing_city", $customer["billing"]["city"] ?? ''],
            ["billing_state", $customer["billing"]["state"] ?? ''],
            ["billing_postcode", $customer["billing"]["postcode"] ?? ''],
            ["billing_country", $customer["billing"]["country"] ?? ''],
            ["billing_email", $customer["billing"]["email"] ?? ''],
            ["billing_phone", $customer["billing"]["phone"] ?? ''],
            ["shipping_first_name", $customer["shipping"]["first_name"] ?? ''],
            ["shipping_last_name", $customer["shipping"]["last_name"] ?? ''],
            ["shipping_address_1", $customer["shipping"]["address_1"] ?? ''],
            ["shipping_city", $customer["shipping"]["city"] ?? ''],
            ["shipping_state", $customer["shipping"]["state"] ?? ''],
            ["shipping_postcode", $customer["shipping"]["postcode"] ?? ''],
            ["shipping_country", $customer["shipping"]["country"] ?? ''],
            //
            ["wp_capabilities", 'a:1:{s:8:"customer";b:1;}'],
            ["nickname", $nice],
            ["wp_user_level", 0]
          ];
          foreach ($customer["meta_data"] as $md) {
            if (!empty($md["key"]) && !empty($md["value"])) {
              array_push($customers[$customer["email"]]["meta"], [$md["key"], $md["value"]]);
            }
          }

          $values[] = [
            $customer["email"],
            md5(bin2hex(random_bytes(10))),
            $customer["email"],
            '',
            '',
            $time,
            $nice,
            $nice
          ];
        }
      }

      return [$customers, $values];
    }

    protected static function insertUsers(array $values)
    {
      global $wpdb;
      $sql = "
        INSERT IGNORE INTO wp_users
            (user_login, user_pass, user_email, user_url, user_activation_key, user_registered, display_name, user_nicename)
        VALUES
            " . implode(", ", array_fill(0, count(array_keys($values)), "(%s,%s,%s,%s,%s,%s,%s,%s)")) . "
        ";

      // flatten the array & run query
      $wpdb->query($wpdb->prepare($sql, array_merge(...$values)));
    }

    protected static function getUserIds(array $customers)
    {
      global $wpdb;
      $sql = "
        SELECT id, user_email FROM
            wp_users
        WHERE
            user_email 
        IN (" . implode(", ", array_fill(0, count(array_keys($customers)), "%s")) . ")
        ";
      return $wpdb->get_results($wpdb->prepare($sql, array_keys($customers)));
    }

    public static function getUserEmailByIds(array $ids)
    {
      global $wpdb;
      $sql = "
        SELECT ID, user_email FROM
            wp_users
        WHERE
            ID 
        IN (" . implode(", ", array_fill(0, count($ids), "%d")) . ")
        ";
      $results = $wpdb->get_results($wpdb->prepare($sql, $ids));
      foreach ($results as $result) {
        $return[$result->ID] = $result->user_email;
      }
      return $return ?? [];
    }

    protected static function insertUserMeta($metaValues)
    {
      global $wpdb;

      $sql = "
        INSERT INTO
            wp_usermeta
            (user_id, meta_key, meta_value)
        VALUES
            " . implode(", ", array_fill(0, count($metaValues), "(%d,%s,%s)")) . "
        ON DUPLICATE KEY UPDATE
        meta_value=VALUES(meta_value)
        ";

      // flatten the array & run query
      $wpdb->query($wpdb->prepare($sql, array_merge(...$metaValues)));
    }
  }