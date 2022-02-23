<?php

  namespace BatchProcessingApi;

  if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
  }

  class BaseRepository
  {
    protected static function insertWCCustomPost(array $posts)
    {
      global $wpdb;

      $values = [];
      foreach ($posts as $post) {
        $values[] = array_values($post["data"]);
      }

      $sql = "
        INSERT INTO wp_posts
            (post_author, post_date, post_date_gmt, post_content, 
             post_title, post_excerpt, post_status, comment_status, 
             ping_status, post_password, post_name, to_ping, pinged, 
             post_modified, post_modified_gmt, post_content_filtered, 
             post_parent, guid, menu_order, post_type, post_mime_type, comment_count)
        VALUES
            " . implode(", ", array_fill(0, count(array_keys($values)), "(%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%s,%d,%s,%s,%d)")) . "
        ";
      $wpdb->query($wpdb->prepare($sql, array_merge(...$values)));

      $sql = "
        SELECT 
            ID, post_name 
        FROM 
            wp_posts 
        WHERE 
            post_name 
        IN
            (" . implode(", ", array_fill(0, count($posts), "%s")) . ")
        ";
      $postIds = $wpdb->get_results($wpdb->prepare($sql, array_keys($posts)));
      foreach ($postIds as $postId) {
        $posts[$postId->post_name]["data"]["wp_id"] = $postId->ID;
      }

      $sql = "
        UPDATE 
            wp_posts
        SET 
            post_name = SUBSTRING(post_name, 19),
            guid = CONCAT(guid,ID)
        WHERE 
            post_name LIKE 'batchapi:%'";
      $result = $wpdb->query($sql);

      return $posts;
    }

    protected static function getPostData(array $postIds)
    {
      global $wpdb;
      $sql = "
        SELECT 
            ID, post_name, post_title, post_excerpt
        FROM 
            wp_posts 
        WHERE 
            ID 
        IN
            (" . implode(", ", array_fill(0, count($postIds), "%d")) . ")
        ";
      $results = $wpdb->get_results($wpdb->prepare($sql, $postIds));

      $postData = [];
      foreach ($results as $result) {
        $postData[$result->ID] = [
          "post_name" => $result->post_name,
          "post_title" => $result->post_title,
          "post_excerpt" => $result->post_excerpt
        ];
      }

      return $postData;
    }
  }