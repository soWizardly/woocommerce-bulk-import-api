<?php

  namespace BatchProcessingApi;

  if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
  }

  class RouteRepository
  {
    public static function registerRoutes()
    {
      $routes = [
        "batch/customer" => ['BatchProcessingApi\CustomerRepository', 'createCustomers'],
        "batch/product-variation" => ['BatchProcessingApi\ProductRepository', 'createProductVariations'],
        "batch/order" => ['BatchProcessingApi\OrderRepository', 'createOrders'],
        "batch/subscription" => ['BatchProcessingApi\SubscriptionRepository', 'createSubscriptions'],
        "batch/delete" => ['BatchProcessingApi\ScrubRepository', 'deleteBulkDataByUserId']
      ];

      foreach ($routes as $route => $callback) {
        register_rest_route('wc/v3', $route, [
          'methods' => ['POST'],
          'callback' => $callback,
          'permission_callback' => function (\WP_REST_Request $request) {
              $userId = (new CustomAuth)->authenticate();
              wp_set_current_user($userId);
              return true;
          }
        ]);
      }
    }
  }