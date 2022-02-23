<?php

  /*

  Plugin Name: Batch Processing API

  Plugin URI: tradersonly.com

  Description: Plugin that exposes a JSON API /wp-json/wc/v3/batch/[item] for processing of new: Customers [customer], Product Variations [product-variation], Orders [order], Subscriptions [subscription]. Also provides a [delete] method to wipe all customer records.

  Version: 1.0

  Author: TradersOnly

  Author URI: https://tradersonly.com

  Text Domain: tradersonly

  */

  require_once(WP_PLUGIN_DIR . "/batch_processing_api/inc/CustomAuth.php");
  require_once(WP_PLUGIN_DIR . "/batch_processing_api/inc/RouteRepository.php" );
  require_once(WP_PLUGIN_DIR . "/batch_processing_api/inc/BaseRepository.php" );
  require_once(WP_PLUGIN_DIR . "/batch_processing_api/inc/CustomerRepository.php" );
  require_once(WP_PLUGIN_DIR . "/batch_processing_api/inc/OrderRepository.php" );
  require_once(WP_PLUGIN_DIR . "/batch_processing_api/inc/ProductRepository.php" );
  require_once(WP_PLUGIN_DIR . "/batch_processing_api/inc/SubscriptionRepository.php" );
  require_once(WP_PLUGIN_DIR . "/batch_processing_api/inc/ScrubRepository.php" );

  add_action('rest_api_init', 'BatchProcessingApi\RouteRepository::registerRoutes');

  function batchprocessingapi_activated()
  {
    global $wpdb;
    $wpdb->query("
      ALTER TABLE `wp_users` ADD UNIQUE INDEX wp_users_user_email (user_email);
      ");
    $wpdb->query("
      ALTER TABLE `wp_usermeta` ADD UNIQUE INDEX wp_usermeta_uimk_unique (user_id, meta_key);
      ");
    $wpdb->query("
      ALTER TABLE `wp_postmeta` ADD UNIQUE INDEX wp_postmeta_pimk_unique (post_id, meta_key);
      ");
  }
  register_activation_hook( __FILE__, 'batchprocessingapi_activated' );

  function batchprocessingapi_deactivated()
  {
    global $wpdb;
    $wpdb->query("
      ALTER TABLE `wp_users` DROP INDEX `wp_users_user_email`;
      ");
    $wpdb->query("
      ALTER TABLE `wp_usermeta` DROP INDEX `wp_usermeta_uimk_unique`;
      ");
    $wpdb->query("
      ALTER TABLE `wp_postmeta` DROP INDEX `wp_postmeta_pimk_unique`;
      ");
  }
  register_deactivation_hook( __FILE__, 'batchprocessingapi_deactivated' );
