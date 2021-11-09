<?php
if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class Psh_List_Table extends WP_List_Table {

    private $psh;
    private $tableName;
    private $db;

    public function __construct() {
        global $wpdb;

        parent::__construct( array(
            'singular' => 'subscription',
            'plural'   => 'subscriptions',
            'ajax'     => false
        ) );

        $this->db        = $wpdb;
        $this->tableName = $wpdb->prefix . "woocommerce_platformsh_subscription";
        $this->psh       = new PlatformshPlugin();
    }

    public function extra_tablenav( $which ) {
        // Goes both before and after the table.
        echo "Refreshing this page will check (and update, if necessary) the status of the subscriptions.";
    }

    public function get_columns() {
        return array(
            'col_psh_id'         => _x( 'ID', 'Column label', 'wp-list-table-example' ),
            'col_psh_prod_id'    => _x( 'Product ID', 'Column label', 'wp-list-table-example' ),
            'col_psh_sub_id'     => _x( 'Subscription ID', 'Column label', 'wp-list-table-example' ),
            'col_psh_sub_status' => _x( 'Subscription Status', 'Column label', 'wp-list-table-example' ),
            'col_psh_project'    => _x( 'Project', 'Column label', 'wp-list-table-example' ),
            'col_psh_order'      => _x( 'Order', 'Column label', 'wp-list-table-example' )
        );
    }

    public function get_sortable_columns() {
        return array(
            'col_psh_id'      => array( 'col_psh_id', false ),
            'col_psh_prod_id' => array( 'col_psh_prod_id', false )
        );
    }

    function prepare_items() {
        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = array( $columns, $hidden, $sortable );

        $query = "SELECT * FROM $this->tableName ORDER BY product_id";

        /* -- Ordering parameters -- */
        //Parameters that are going to be used to order the result

        /* -- Pagination parameters -- */
        //Number of elements in your table?
        $total_items = $this->db->query( $query ); //return the total number of affected rows
        //How many to display per page?
        $per_page = 5;
        //Which page is this?
        $paged = ! empty( $_GET["paged"] ) ? mysqli_real_escape_string( $_GET["paged"] ) : '';
        //Page Number
        if ( empty( $paged ) || ! is_numeric( $paged ) || $paged <= 0 ) {
            $paged = 1;
        } //How many pages do we have in total?
        $total_pages = ceil( $total_items / $per_page ); //adjust the query to take pagination into account
        if ( ! empty( $paged ) && ! empty( $per_page ) ) {
            $offset = ( $paged - 1 ) * $per_page;
            $query  .= ' LIMIT ' . (int) $offset . ',' . (int) $per_page;
        }

        $this->set_pagination_args( array(
            "total_items" => $total_items,
            "total_pages" => $total_pages,
            "per_page"    => $per_page,
        ) );

        $this->items = $this->db->get_results( $query );
        $this->refresh_sub_statuses( $this->items );
    }

    protected function refresh_sub_statuses( &$items ) {
        foreach ( $items as &$item ) {
            if ( $item->status !== 'active' ) {
                $sub = $this->psh->getSubscription( $item->subscription_id );
                if ( $item->status !== $sub->getStatus() ) {
                    $item->status        = $sub->getStatus();
                    $item->project_id    = $sub->getProperty( 'project_id' );
                    $item->project_title = $sub->getProperty( 'project_title' );
                    $item->project_ui    = $sub->getProperty( 'project_ui' );
                    $query               = "UPDATE $this->tableName SET status = %s, project_id = %s, project_title = %s, project_ui = %s WHERE id = %d";
                    $this->db->query( $this->db->prepare( "$query", [
                        $item->status,
                        $item->project_id,
                        $item->project_title,
                        $item->project_ui,
                        $item->id
                    ] ) );
                }
            }
        }
    }

    function display_rows() {
        $records = $this->items;

        list( $columns, $hidden ) = $this->get_column_info();
        $columns = $this->get_columns();

        if ( ! empty( $records ) ) {
            foreach ( $records as $rec ) {

                echo '<tr id="record_' . $rec->id . '">';
                foreach ( $columns as $column_name => $column_display_name ) {

                    $class = "class='$column_name column-$column_name'";
                    $style = "";
                    if ( in_array( $column_name, $hidden ) ) {
                        $style = ' style="display:none;"';
                    }
                    $attributes = $class . $style;

                    switch ( $column_name ) {
                        case "col_psh_id":
                            echo '<td ' . $attributes . '>' . stripslashes( $rec->id ) . '</td>';
                            break;
                        case "col_psh_prod_id":
                            $editlink = get_edit_post_link( $rec->product_id );
                            echo '<td ' . $attributes . '><a href="' . $editlink . '">' . $rec->product_id . '</a></td>';
                            break;
                        case "col_psh_sub_id":
                            echo '<td ' . $attributes . '>' . $rec->subscription_id . '</td>';
                            break;
                        case "col_psh_sub_status":
                            echo '<td ' . $attributes . '>' . $rec->status;
                            if ( $rec->project_id ) {
                                $project = $this->psh->getProject( $rec->project_id );
                                $env     = $project->getEnvironment( $project->default_branch );
                                if ( $env->getProperty( 'has_deployment' ) ) {
                                    echo ' (initialised)';
                                } else {
                                    echo ' (uninitialised)';
                                }
                            }
                            echo '</td>';
                            break;
                        case "col_psh_project":
                            if ( $rec->project_id ) {
                                $link = "<a href='$rec->project_ui'>" . $rec->project_title . " (" . $rec->project_id . ")</a>";
                                echo '<td ' . $attributes . '>' . $link . '</td>';
                            } else {
                                echo '<td ' . $attributes . '>N/A</td>';
                            }
                            break;
                        case "col_psh_order":
                            if ( $rec->order_id ) {
                                $editlink = get_edit_post_link( $rec->order_id );
                                echo '<td ' . $attributes . '><a href="' . $editlink . '">' . $rec->order_id . '</a></td>';
                            } else {
                                echo '<td ' . $attributes . '>N/A</td>';
                            }
                            break;
                    }
                }

                echo '</tr>';
            }
        }
    }
}
