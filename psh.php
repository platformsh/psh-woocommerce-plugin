<?php

/*
Plugin Name: Platform.sh API Integration
Plugin URI:  https://platform.sh
Description: Integrates with Platform.sh API so one can sell Platform.sh-backed products!
Version:     0.0.1-alpha
Author:      Vincenzo Russo
Author URI:  https://platform.sh
License:     GPL2
License URI: https://platform.sh
*/

use Platformsh\Client\PlatformClient;
use Platformsh\Client\Model\Subscription\SubscriptionOptions;
use PUGX\Shortid\Shortid;

class PlatformshPlugin {

    private $client;
    private $db;
    private $tableName;
    private $charsetCollate;
    private $projectPrefix;

    private const DB_VERSION = '1.0';

    public function __construct() {
        global $wpdb;

        $this->db             = $wpdb;
        $this->tableName      = $wpdb->prefix . "woocommerce_platformsh_subscription";
        $this->charsetCollate = $wpdb->get_charset_collate();
        // @TODO: PLATFORMSH_WP_PLUGIN_PROJECT_PREFIX should be a WordPress setting one can change in the admin.
        $this->projectPrefix = $_ENV['PLATFORMSH_WP_PLUGIN_PROJECT_PREFIX'] ?: "PSHWP";

        $this->client = new PlatformClient();
        $this->client->getConnector()->setApiToken( $_ENV['PLATFORMSH_TOKEN'], 'exchange' );
    }

    // @TODO: this routine can be improved:
    // Ideally, the plugin will require the Platforms.h account to have a dedicated organisation where to segregate
    // all the projects.
    private function currentSubscriptionCount( $region, $plan ): int {
        $subs  = $this->client->getSubscriptions();
        $count = 0;
        foreach ( $subs as $sub ) {
            if ( ( strpos( $sub->project_title, $this->projectPrefix ) !== false ) && ( strpos( $sub->project_region, $region ) !== false ) && ( $sub->plan === $plan ) ) {
                $count ++;
            }
        }

        return $count;
    }

    public function createSubscriptions( int $productId ) {
        $product   = wc_get_product( $productId );
        $region    = $product->get_attribute( 'region' );
        $plan      = $product->get_attribute( 'plan' );
        $stock     = $product->get_stock_quantity();
        $new_stock = $stock - $this->currentSubscriptionCount( $region, $plan );

        $subs = [];
        for ( $i = 0; $i < $new_stock; $i ++ ) {
            $subscriptionOpts = SubscriptionOptions::fromArray( [
                'project_region' => $region . ".platform.sh",
                'project_title'  => $this->projectPrefix . ' ' . Shortid::generate(),
                'plan'           => $plan
            ] );
            $sub              = $this->client->createSubscription( $subscriptionOpts );
            array_push( $subs, $productId, $sub->id, $sub->status, $subscriptionOpts->toArray()['project_title'] );
        }
        $this->saveSubscriptions( $subs );
    }

    public function saveSubscriptions( array $subscriptions ) {
        $placeholders = [];
        $query        = "INSERT INTO $this->tableName (product_id, subscription_id, status, project_title) VALUES ";
        for ( $i = 0; $i < count( $subscriptions ) / 4; $i ++ ) {
            $placeholders[] = "(%d, %d, %s, %s)";
        }
        $query .= implode( ', ', $placeholders ) . ";";
        $this->db->query( $this->db->prepare( "$query", $subscriptions ) );
    }

    public function processSubscriptionOrder( $order_id, $old_status, $new_status, $order ) {
        if ( $new_status === "completed" ) {
            $this->completeSubscriptionOrder( $order );
        }
        if ( $new_status === "cancelled" ) {
            $this->cancelSubscriptionOrder( $order );
        }
    }

    private function cancelSubscriptionOrder( $order ) {
        $updateQ = $this->db->prepare( "UPDATE $this->tableName SET order_id = NULL WHERE order_id = %d", [
            $order->get_id()
        ] );
        $this->db->query( $updateQ );

        $post_slug = "subscription-order-" . $order->get_id();
        $post      = get_page_by_path( $post_slug, OBJECT, 'post' );
        wp_delete_post( $post->id );
    }

    private function completeSubscriptionOrder( $order ) {
        $products = $order->get_items();
        $product  = array_shift( $products );

        $selectQ = $this->db->prepare( "SELECT subscription_id FROM $this->tableName WHERE product_id = %d AND order_id IS NULL AND status = 'active' ORDER BY subscription_id ASC LIMIT 1", [ $product->get_id() ] );
        $this->db->query( $selectQ );
        $sub = $this->db->get_results( $selectQ )[0];

        $updateQ = $this->db->prepare( "UPDATE $this->tableName SET order_id = %d WHERE subscription_id = %d", [
            $order->get_id(),
            $sub->subscription_id
        ] );
        $this->db->query( $updateQ );

        $post_slug = "subscription-order-" . $order->get_id();
        $project   = $this->client->getSubscription( $sub->subscription_id )->getProject();
        $routes    = $project->getEnvironment( $project->default_branch )
                             ->getCurrentDeployment()->routes;

        $primary_url = '';
        foreach ( $routes as $url => $route ) {
            if ( $route->getProperties()['primary'] ) {
                $primary_url = $url;
            }
        }

        $new_post = array(
            'post_type'    => 'post',               // Post Type Slug eg: 'page', 'post'
            'post_title'   => $product->get_name(), // Title of the Content
            'post_content' => $primary_url,         // Content
            'post_status'  => 'publish',            // Post Status
            'post_author'  => 1,                    // Post Author ID
            'post_name'    => $post_slug            // Slug of the Post
        );

        if ( ! get_page_by_path( $post_slug, OBJECT, 'post' ) ) {
            wp_insert_post( $new_post );
        }
    }

    public function getSubscription( $subscriptionId ) {
        return $this->client->getSubscription( $subscriptionId );
    }

    public function getProject( $projectId ) {
        return $this->client->getProject( $projectId );
    }

    public function onActivation() {
        $sql = "CREATE TABLE $this->tableName (
          id mediumint(9) NOT NULL AUTO_INCREMENT,
          product_id mediumint(9) NOT NULL,
          subscription_id mediumint(9) NOT NULL,
          status varchar(20) NOT NULL,
          project_id varchar(20),
          project_title varchar(20),
          project_ui varchar(255),
          order_id mediumint(9),
          PRIMARY KEY  (id)
        ) $this->charsetCollate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );

        add_option( 'psh_db_version', $this::DB_VERSION );
    }

    public function onUninstall() {
        $sql = "DROP TABLE IF EXISTS $this->tableName";
        $this->db->query( $sql );
        delete_option( 'psh_db_version' );
    }

    public function registerAdminMenu() {
        add_menu_page(
            'Platform.sh — Subscriptions',
            'Platform.sh',
            'publish_posts',
            'platform-sh',
            [ $this, 'renderAdminMenu' ]
        );
    }

    public function renderAdminMenu() {
        global $title;

        print '<div class="wrap">';
        print "<h1>$title</h1>";

        require_once plugin_dir_path( __FILE__ ) . "/includes/admin-table.php";

        $table = new Psh_List_Table();
        $table->prepare_items();
        $table->display();

        print '</div>';
    }
}

$psh = new PlatformshPlugin();

add_action( 'publish_product', [ $psh, 'createSubscriptions' ] );
add_action( 'admin_menu', [ $psh, 'registerAdminMenu' ] );
add_action( 'woocommerce_order_status_changed', [ $psh, 'processSubscriptionOrder' ], 10, 4 );
register_activation_hook( __FILE__, [ $psh, 'onActivation' ] );
register_uninstall_hook( __FILE__, [ $psh, 'onUninstall' ] );
