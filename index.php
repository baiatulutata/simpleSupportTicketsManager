<?php
/**
 * Plugin Name: Support Tickets Manager
 * Description: Manages support tickets with admin interface, Gutenberg blocks, and image uploads
 * Version: 1.0.0
 * Author: Ionut Baldazar
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SupportTicketsPlugin {

    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        add_action('wp_ajax_submit_support_ticket', array($this, 'handle_ajax_ticket_submission'));
        add_action('wp_ajax_nopriv_submit_support_ticket', array($this, 'handle_ajax_ticket_submission'));
        add_action('wp_ajax_get_user_tickets', array($this, 'handle_ajax_get_user_tickets'));
        add_action('wp_ajax_reply_to_ticket', array($this, 'handle_ajax_reply_to_ticket'));
        add_action('wp_ajax_upload_ticket_image', array($this, 'handle_ajax_image_upload'));
        add_action('wp_ajax_nopriv_upload_ticket_image', array($this, 'handle_ajax_image_upload'));
        register_activation_hook(__FILE__, array($this, 'activate'));
    }

    public function init() {
        $this->register_post_type();
        $this->register_blocks();
        $this->create_tables();
    }

    public function register_post_type() {
        $args = array(
            'labels' => array(
                'name' => 'Support Tickets',
                'singular_name' => 'Support Ticket',
                'menu_name' => 'Support Tickets',
                'add_new' => 'Add New Ticket',
                'add_new_item' => 'Add New Support Ticket',
                'edit_item' => 'Edit Support Ticket',
                'new_item' => 'New Support Ticket',
                'view_item' => 'View Support Ticket',
                'search_items' => 'Search Support Tickets',
                'not_found' => 'No support tickets found',
                'not_found_in_trash' => 'No support tickets found in trash'
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'capability_type' => 'post',
            'supports' => array('title', 'editor', 'author'),
            'meta_box_cb' => false
        );

        register_post_type('support_ticket', $args);

        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_data'));
    }

    public function create_tables() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'support_ticket_replies';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            ticket_id bigint(20) NOT NULL,
            user_id bigint(20) DEFAULT NULL,
            user_email varchar(100) NOT NULL,
            user_name varchar(100) NOT NULL,
            reply_content longtext NOT NULL,
            is_admin tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ticket_id (ticket_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Table for ticket images
        $images_table = $wpdb->prefix . 'support_ticket_images';

        $sql_images = "CREATE TABLE $images_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            ticket_id bigint(20) NOT NULL,
            reply_id mediumint(9) DEFAULT NULL,
            image_url varchar(255) NOT NULL,
            image_name varchar(255) NOT NULL,
            uploaded_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ticket_id (ticket_id)
        ) $charset_collate;";

        dbDelta($sql_images);
    }

    public function register_blocks() {
        if (!function_exists('register_block_type')) {
            return;
        }

        wp_register_script(
            'support-tickets-blocks',
            plugin_dir_url(__FILE__) . 'blocks.js',
            array('wp-blocks', 'wp-element', 'wp-editor', 'wp-components'),
            '1.0.0'
        );

        // Ticket submission block
        register_block_type('support-tickets/submit-form', array(
            'editor_script' => 'support-tickets-blocks',
            'render_callback' => array($this, 'render_submit_form_block'),
        ));

        // User tickets management block
        register_block_type('support-tickets/user-tickets', array(
            'editor_script' => 'support-tickets-blocks',
            'render_callback' => array($this, 'render_user_tickets_block'),
        ));
    }

    public function enqueue_frontend_scripts() {
        wp_enqueue_script('jquery');
        wp_enqueue_script(
            'support-tickets-frontend',
            plugin_dir_url(__FILE__) . 'frontend.js',
            array('jquery'),
            '1.0.0',
            true
        );

        wp_localize_script('support-tickets-frontend', 'supportTicketsAjax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('support_tickets_nonce'),
            'user_id' => get_current_user_id(),
            'is_user_logged_in' => is_user_logged_in()
        ));

        wp_enqueue_style(
            'support-tickets-frontend',
            plugin_dir_url(__FILE__) . 'frontend.css',
            array(),
            '1.0.0'
        );
    }

    public function render_submit_form_block($attributes) {
        ob_start();
        ?>
        <div class="support-ticket-form-container">
            <h3>Submit Support Ticket</h3>
            <form id="support-ticket-form" enctype="multipart/form-data">
                <?php if (!is_user_logged_in()): ?>
                    <div class="form-group">
                        <label for="customer_name">Name *</label>
                        <input type="text" id="customer_name" name="customer_name" required>
                    </div>
                    <div class="form-group">
                        <label for="customer_email">Email *</label>
                        <input type="email" id="customer_email" name="customer_email" required>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="ticket_title">Subject *</label>
                    <input type="text" id="ticket_title" name="ticket_title" required>
                </div>

                <div class="form-group">
                    <label for="ticket_category">Category</label>
                    <select id="ticket_category" name="ticket_category">
                        <option value="">Select Category</option>
                        <option value="technical">Technical Support</option>
                        <option value="billing">Billing</option>
                        <option value="general">General Inquiry</option>
                        <option value="bug_report">Bug Report</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="ticket_priority">Priority</label>
                    <select id="ticket_priority" name="ticket_priority">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="ticket_content">Description *</label>
                    <textarea id="ticket_content" name="ticket_content" rows="6" required></textarea>
                </div>

                <div class="form-group">
                    <label for="ticket_images">Attach Images (optional)</label>
                    <input type="file" id="ticket_images" name="ticket_images[]" multiple accept="image/*">
                    <small>You can upload multiple images. Supported formats: JPG, PNG, GIF</small>
                </div>

                <div class="form-group">
                    <button type="submit" class="submit-ticket-btn">Submit Ticket</button>
                </div>

                <div id="ticket-form-messages"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public function render_user_tickets_block($attributes) {
        if (!is_user_logged_in()) {
            return '<p>Please log in to view your support tickets.</p>';
        }

        ob_start();
        ?>
        <div class="user-tickets-container">
            <h3>My Support Tickets</h3>
            <div id="user-tickets-list">
                <div class="loading">Loading your tickets...</div>
            </div>
        </div>

        <!-- Reply Modal -->
        <div id="ticket-reply-modal" class="ticket-modal" style="display: none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h4>Ticket Details & Replies</h4>
                    <span class="close-modal">&times;</span>
                </div>
                <div class="modal-body">
                    <div id="ticket-details"></div>
                    <div id="ticket-replies"></div>
                    <div class="reply-form">
                        <h5>Add Reply</h5>
                        <textarea id="reply-content" rows="4" placeholder="Type your reply..."></textarea>
                        <input type="file" id="reply-images" multiple accept="image/*">
                        <button id="submit-reply" class="submit-reply-btn">Send Reply</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_ajax_ticket_submission() {
        check_ajax_referer('support_tickets_nonce', 'nonce');

        $title = sanitize_text_field($_POST['ticket_title']);
        $content = sanitize_textarea_field($_POST['ticket_content']);
        $category = sanitize_text_field($_POST['ticket_category']);
        $priority = sanitize_text_field($_POST['ticket_priority']);

        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $customer_name = $current_user->display_name;
            $customer_email = $current_user->user_email;
            $user_id = $current_user->ID;
        } else {
            $customer_name = sanitize_text_field($_POST['customer_name']);
            $customer_email = sanitize_email($_POST['customer_email']);
            $user_id = 0;
        }

        // Create the ticket
        $post_data = array(
            'post_title' => $title,
            'post_content' => $content,
            'post_type' => 'support_ticket',
            'post_status' => 'publish',
            'post_author' => $user_id
        );

        $ticket_id = wp_insert_post($post_data);

        if ($ticket_id) {
            // Save meta data
            update_post_meta($ticket_id, '_customer_email', $customer_email);
            update_post_meta($ticket_id, '_customer_name', $customer_name);
            update_post_meta($ticket_id, '_ticket_status', 'open');
            update_post_meta($ticket_id, '_ticket_priority', $priority);
            update_post_meta($ticket_id, '_ticket_category', $category);

            // Handle image uploads
            if (!empty($_FILES['ticket_images']['name'][0])) {
                $this->handle_ticket_images($ticket_id, $_FILES['ticket_images']);
            }

            wp_send_json_success(array(
                'message' => 'Support ticket submitted successfully!',
                'ticket_id' => $ticket_id
            ));
        } else {
            wp_send_json_error('Failed to create support ticket.');
        }
    }

    public function handle_ajax_get_user_tickets() {
        check_ajax_referer('support_tickets_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error('Please log in to view tickets.');
        }

        $user_id = get_current_user_id();
        $user_email = wp_get_current_user()->user_email;

        // Get tickets by user ID or email
        $args = array(
            'post_type' => 'support_ticket',
            'posts_per_page' => -1,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_customer_email',
                    'value' => $user_email,
                    'compare' => '='
                )
            )
        );

        if ($user_id > 0) {
            $args['author'] = $user_id;
        }

        $tickets = get_posts($args);
        $tickets_data = array();

        foreach ($tickets as $ticket) {
            $status = get_post_meta($ticket->ID, '_ticket_status', true);
            $priority = get_post_meta($ticket->ID, '_ticket_priority', true);
            $category = get_post_meta($ticket->ID, '_ticket_category', true);

            // Get reply count
            global $wpdb;
            $reply_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}support_ticket_replies WHERE ticket_id = %d",
                $ticket->ID
            ));

            $tickets_data[] = array(
                'id' => $ticket->ID,
                'title' => $ticket->post_title,
                'content' => $ticket->post_content,
                'status' => $status,
                'priority' => $priority,
                'category' => $category,
                'date' => get_the_date('Y-m-d H:i', $ticket->ID),
                'reply_count' => intval($reply_count)
            );
        }

        wp_send_json_success($tickets_data);
    }

    public function handle_ajax_reply_to_ticket() {
        check_ajax_referer('support_tickets_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error('Please log in to reply.');
        }

        $ticket_id = intval($_POST['ticket_id']);
        $reply_content = sanitize_textarea_field($_POST['reply_content']);
        $action_type = sanitize_text_field($_POST['action_type']);

        $current_user = wp_get_current_user();

        global $wpdb;

        if ($action_type === 'get_replies') {
            // Get ticket details and replies
            $ticket = get_post($ticket_id);
            if (!$ticket) {
                wp_send_json_error('Ticket not found.');
            }

            $status = get_post_meta($ticket_id, '_ticket_status', true);
            $priority = get_post_meta($ticket_id, '_ticket_priority', true);
            $category = get_post_meta($ticket_id, '_ticket_category', true);

            $replies = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}support_ticket_replies WHERE ticket_id = %d ORDER BY created_at ASC",
                $ticket_id
            ));

            // Get images for this ticket
            $images = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}support_ticket_images WHERE ticket_id = %d ORDER BY uploaded_at ASC",
                $ticket_id
            ));

            wp_send_json_success(array(
                'ticket' => array(
                    'id' => $ticket->ID,
                    'title' => $ticket->post_title,
                    'content' => $ticket->post_content,
                    'status' => $status,
                    'priority' => $priority,
                    'category' => $category,
                    'date' => get_the_date('Y-m-d H:i', $ticket->ID)
                ),
                'replies' => $replies,
                'images' => $images
            ));
        } else {
            // Add new reply
            $result = $wpdb->insert(
                $wpdb->prefix . 'support_ticket_replies',
                array(
                    'ticket_id' => $ticket_id,
                    'user_id' => $current_user->ID,
                    'user_email' => $current_user->user_email,
                    'user_name' => $current_user->display_name,
                    'reply_content' => $reply_content,
                    'is_admin' => 0,
                    'created_at' => current_time('mysql')
                )
            );

            if ($result) {
                $reply_id = $wpdb->insert_id;

                // Handle image uploads for reply
                if (!empty($_FILES['reply_images']['name'][0])) {
                    $this->handle_reply_images($ticket_id, $reply_id, $_FILES['reply_images']);
                }

                wp_send_json_success('Reply added successfully.');
            } else {
                wp_send_json_error('Failed to add reply.');
            }
        }
    }

    private function handle_ticket_images($ticket_id, $files) {
        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        global $wpdb;

        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $file = array(
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i]
                );

                $upload = wp_handle_upload($file, array('test_form' => false));

                if (!isset($upload['error'])) {
                    $wpdb->insert(
                        $wpdb->prefix . 'support_ticket_images',
                        array(
                            'ticket_id' => $ticket_id,
                            'image_url' => $upload['url'],
                            'image_name' => $files['name'][$i],
                            'uploaded_at' => current_time('mysql')
                        )
                    );
                }
            }
        }
    }

    private function handle_reply_images($ticket_id, $reply_id, $files) {
        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        global $wpdb;

        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $file = array(
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i]
                );

                $upload = wp_handle_upload($file, array('test_form' => false));

                if (!isset($upload['error'])) {
                    $wpdb->insert(
                        $wpdb->prefix . 'support_ticket_images',
                        array(
                            'ticket_id' => $ticket_id,
                            'reply_id' => $reply_id,
                            'image_url' => $upload['url'],
                            'image_name' => $files['name'][$i],
                            'uploaded_at' => current_time('mysql')
                        )
                    );
                }
            }
        }
    }

    // Keep all the previous admin functions...
    public function add_meta_boxes() {
        add_meta_box(
            'support_ticket_details',
            'Ticket Details',
            array($this, 'meta_box_callback'),
            'support_ticket',
            'normal',
            'high'
        );

        add_meta_box(
            'support_ticket_replies',
            'Ticket Replies',
            array($this, 'replies_meta_box_callback'),
            'support_ticket',
            'normal',
            'high'
        );
    }

    public function meta_box_callback($post) {
        wp_nonce_field('support_ticket_meta', 'support_ticket_nonce');

        $status = get_post_meta($post->ID, '_ticket_status', true) ?: 'open';
        $priority = get_post_meta($post->ID, '_ticket_priority', true) ?: 'medium';
        $customer_email = get_post_meta($post->ID, '_customer_email', true);
        $customer_name = get_post_meta($post->ID, '_customer_name', true);
        $category = get_post_meta($post->ID, '_ticket_category', true);

        echo '<table class="form-table">';
        echo '<tr><th><label for="customer_name">Customer Name</label></th>';
        echo '<td><input type="text" name="customer_name" id="customer_name" value="' . esc_attr($customer_name) . '" style="width: 100%;" /></td></tr>';

        echo '<tr><th><label for="customer_email">Customer Email</label></th>';
        echo '<td><input type="email" name="customer_email" id="customer_email" value="' . esc_attr($customer_email) . '" style="width: 100%;" /></td></tr>';

        echo '<tr><th><label for="ticket_status">Status</label></th>';
        echo '<td><select name="ticket_status" id="ticket_status">';
        echo '<option value="open"' . selected($status, 'open', false) . '>Open</option>';
        echo '<option value="in_progress"' . selected($status, 'in_progress', false) . '>In Progress</option>';
        echo '<option value="resolved"' . selected($status, 'resolved', false) . '>Resolved</option>';
        echo '<option value="closed"' . selected($status, 'closed', false) . '>Closed</option>';
        echo '</select></td></tr>';

        echo '<tr><th><label for="ticket_priority">Priority</label></th>';
        echo '<td><select name="ticket_priority" id="ticket_priority">';
        echo '<option value="low"' . selected($priority, 'low', false) . '>Low</option>';
        echo '<option value="medium"' . selected($priority, 'medium', false) . '>Medium</option>';
        echo '<option value="high"' . selected($priority, 'high', false) . '>High</option>';
        echo '<option value="urgent"' . selected($priority, 'urgent', false) . '>Urgent</option>';
        echo '</select></td></tr>';

        echo '<tr><th><label for="ticket_category">Category</label></th>';
        echo '<td><select name="ticket_category" id="ticket_category">';
        echo '<option value="">Select Category</option>';
        echo '<option value="technical"' . selected($category, 'technical', false) . '>Technical Support</option>';
        echo '<option value="billing"' . selected($category, 'billing', false) . '>Billing</option>';
        echo '<option value="general"' . selected($category, 'general', false) . '>General Inquiry</option>';
        echo '<option value="bug_report"' . selected($category, 'bug_report', false) . '>Bug Report</option>';
        echo '</select></td></tr>';
        echo '</table>';

        // Show attached images
        global $wpdb;
        $images = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}support_ticket_images WHERE ticket_id = %d AND reply_id IS NULL",
            $post->ID
        ));

        if ($images) {
            echo '<h4>Attached Images:</h4>';
            echo '<div class="ticket-images">';
            foreach ($images as $image) {
                echo '<div class="image-item">';
                echo '<img src="' . esc_url($image->image_url) . '" style="max-width: 150px; max-height: 150px; margin: 5px;" />';
                echo '<p><small>' . esc_html($image->image_name) . '</small></p>';
                echo '</div>';
            }
            echo '</div>';
        }
    }

    public function replies_meta_box_callback($post) {
        global $wpdb;

        $replies = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}support_ticket_replies WHERE ticket_id = %d ORDER BY created_at ASC",
            $post->ID
        ));

        echo '<div class="ticket-replies">';
        if ($replies) {
            foreach ($replies as $reply) {
                echo '<div class="reply-item" style="border: 1px solid #ddd; padding: 10px; margin: 10px 0;">';
                echo '<div class="reply-header" style="background: #f9f9f9; padding: 5px; margin: -10px -10px 10px -10px;">';
                echo '<strong>' . esc_html($reply->user_name) . '</strong>';
                echo ' (' . esc_html($reply->user_email) . ')';
                if ($reply->is_admin) {
                    echo ' <span style="color: #0073aa;">[Admin]</span>';
                }
                echo ' - ' . esc_html($reply->created_at);
                echo '</div>';
                echo '<div class="reply-content">' . nl2br(esc_html($reply->reply_content)) . '</div>';

                // Show reply images
                $reply_images = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}support_ticket_images WHERE reply_id = %d",
                    $reply->id
                ));

                if ($reply_images) {
                    echo '<div class="reply-images" style="margin-top: 10px;">';
                    foreach ($reply_images as $image) {
                        echo '<img src="' . esc_url($image->image_url) . '" style="max-width: 100px; max-height: 100px; margin: 2px;" />';
                    }
                    echo '</div>';
                }
                echo '</div>';
            }
        } else {
            echo '<p>No replies yet.</p>';
        }
        echo '</div>';

        // Admin reply form
        echo '<div class="admin-reply-form" style="margin-top: 20px; border-top: 1px solid #ddd; padding-top: 20px;">';
        echo '<h4>Add Admin Reply:</h4>';
        echo '<textarea name="admin_reply" rows="4" style="width: 100%;" placeholder="Type your reply..."></textarea>';
        echo '<p><input type="submit" name="add_admin_reply" class="button button-primary" value="Add Reply" /></p>';
        echo '</div>';
    }

    public function save_meta_data($post_id) {
        if (!isset($_POST['support_ticket_nonce']) || !wp_verify_nonce($_POST['support_ticket_nonce'], 'support_ticket_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $fields = array('ticket_status', 'ticket_priority', 'customer_email', 'customer_name', 'ticket_category');

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, '_' . $field, sanitize_text_field($_POST[$field]));
            }
        }

        // Handle admin reply
        if (isset($_POST['add_admin_reply']) && !empty($_POST['admin_reply'])) {
            global $wpdb;
            $current_user = wp_get_current_user();

            $wpdb->insert(
                $wpdb->prefix . 'support_ticket_replies',
                array(
                    'ticket_id' => $post_id,
                    'user_id' => $current_user->ID,
                    'user_email' => $current_user->user_email,
                    'user_name' => $current_user->display_name,
                    'reply_content' => sanitize_textarea_field($_POST['admin_reply']),
                    'is_admin' => 1,
                    'created_at' => current_time('mysql')
                )
            );
        }
    }

    // Keep all previous admin interface methods...
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'support-tickets') !== false) {
            wp_enqueue_style('support-tickets-admin', plugin_dir_url(__FILE__) . 'admin-style.css');
        }
    }

    public function add_admin_menu() {
        add_menu_page(
            'Support Tickets',
            'Support Tickets',
            'manage_options',
            'support-tickets',
            array($this, 'admin_page'),
            'dashicons-tickets-alt',
            30
        );

        add_submenu_page(
            'support-tickets',
            'All Tickets',
            'All Tickets',
            'manage_options',
            'support-tickets',
            array($this, 'admin_page')
        );

        add_submenu_page(
            'support-tickets',
            'Add New Ticket',
            'Add New Ticket',
            'manage_options',
            'support-tickets-new',
            array($this, 'new_ticket_page')
        );
    }

    public function admin_page() {
        // Handle bulk actions
        if (isset($_POST['action']) && $_POST['action'] !== -1) {
            $this->handle_bulk_actions();
        }

        // Get search and filter parameters
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $priority_filter = isset($_GET['priority']) ? sanitize_text_field($_GET['priority']) : '';
        $category_filter = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '';

        // Pagination
        $per_page = 20;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($page - 1) * $per_page;

        // Build query
        $args = array(
            'post_type' => 'support_ticket',
            'posts_per_page' => $per_page,
            'offset' => $offset,
            'post_status' => 'publish',
            'meta_query' => array()
        );

        if (!empty($search)) {
            $args['s'] = $search;
        }

        if (!empty($status_filter)) {
            $args['meta_query'][] = array(
                'key' => '_ticket_status',
                'value' => $status_filter,
                'compare' => '='
            );
        }

        if (!empty($priority_filter)) {
            $args['meta_query'][] = array(
                'key' => '_ticket_priority',
                'value' => $priority_filter,
                'compare' => '='
            );
        }

        if (!empty($category_filter)) {
            $args['meta_query'][] = array(
                'key' => '_ticket_category',
                'value' => $category_filter,
                'compare' => '='
            );
        }

        $query = new WP_Query($args);
        $total_items = $query->found_posts;
        $total_pages = ceil($total_items / $per_page);

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Support Tickets</h1>
            <a href="<?php echo admin_url('admin.php?page=support-tickets-new'); ?>" class="page-title-action">Add New</a>

            <!-- Search and Filters -->
            <form method="get" id="tickets-filter">
                <input type="hidden" name="page" value="support-tickets" />
                <p class="search-box">
                    <label class="screen-reader-text" for="ticket-search-input">Search Tickets:</label>
                    <input type="search" id="ticket-search-input" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search tickets..." />
                    <input type="submit" id="search-submit" class="button" value="Search" />
                </p>

                <div class="tablenav top">
                    <div class="alignleft actions">
                        <select name="status">
                            <option value="">All Statuses</option>
                            <option value="open" <?php selected($status_filter, 'open'); ?>>Open</option>
                            <option value="in_progress" <?php selected($status_filter, 'in_progress'); ?>>In Progress</option>
                            <option value="resolved" <?php selected($status_filter, 'resolved'); ?>>Resolved</option>
                            <option value="closed" <?php selected($status_filter, 'closed'); ?>>Closed</option>
                        </select>

                        <select name="priority">
                            <option value="">All Priorities</option>
                            <option value="low" <?php selected($priority_filter, 'low'); ?>>Low</option>
                            <option value="medium" <?php selected($priority_filter, 'medium'); ?>>Medium</option>
                            <option value="high" <?php selected($priority_filter, 'high'); ?>>High</option>
                            <option value="urgent" <?php selected($priority_filter, 'urgent'); ?>>Urgent</option>
                        </select>

                        <select name="category">
                            <option value="">All Categories</option>
                            <option value="technical" <?php selected($category_filter, 'technical'); ?>>Technical Support</option>
                            <option value="billing" <?php selected($category_filter, 'billing'); ?>>Billing</option>
                            <option value="general" <?php selected($category_filter, 'general'); ?>>General Inquiry</option>
                            <option value="bug_report" <?php selected($category_filter, 'bug_report'); ?>>Bug Report</option>
                        </select>

                        <input type="submit" class="button" value="Filter" />
                    </div>
                </div>
            </form>

            <!-- Bulk Actions Form -->
            <form method="post" id="bulk-action-form">
                <?php wp_nonce_field('bulk_action_nonce', 'bulk_nonce'); ?>

                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="action">
                            <option value="-1">Bulk Actions</option>
                            <option value="delete">Delete</option>
                            <option value="mark_resolved">Mark as Resolved</option>
                            <option value="mark_closed">Mark as Closed</option>
                        </select>
                        <input type="submit" class="button action" value="Apply" />
                    </div>

                    <!-- Pagination -->
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo $total_items; ?> items</span>
                        <?php
                        if ($total_pages > 1) {
                            $page_links = paginate_links(array(
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total' => $total_pages,
                                'current' => $page,
                                'type' => 'plain'
                            ));
                            echo $page_links;
                        }
                        ?>
                    </div>
                </div>

                <!-- Tickets Table -->
                <table class="wp-list-table widefat fixed striped posts">
                    <thead>
                    <tr>
                        <td class="manage-column column-cb check-column">
                            <input type="checkbox" id="cb-select-all-1" />
                        </td>
                        <th class="manage-column column-title">Title</th>
                        <th class="manage-column">Customer</th>
                        <th class="manage-column">Status</th>
                        <th class="manage-column">Priority</th>
                        <th class="manage-column">Category</th>
                        <th class="manage-column">Date</th>
                        <th class="manage-column">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($query->have_posts()) : ?>
                        <?php while ($query->have_posts()) : $query->the_post(); ?>
                            <?php
                            $ticket_id = get_the_ID();
                            $status = get_post_meta($ticket_id, '_ticket_status', true) ?: 'open';
                            $priority = get_post_meta($ticket_id, '_ticket_priority', true) ?: 'medium';
                            $customer_email = get_post_meta($ticket_id, '_customer_email', true);
                            $customer_name = get_post_meta($ticket_id, '_customer_name', true);
                            $category = get_post_meta($ticket_id, '_ticket_category', true);

                            $status_colors = array(
                                'open' => '#e74c3c',
                                'in_progress' => '#f39c12',
                                'resolved' => '#27ae60',
                                'closed' => '#95a5a6'
                            );

                            $priority_colors = array(
                                'low' => '#3498db',
                                'medium' => '#f39c12',
                                'high' => '#e67e22',
                                'urgent' => '#e74c3c'
                            );
                            ?>
                            <tr>
                                <th class="check-column">
                                    <input type="checkbox" name="ticket_ids[]" value="<?php echo $ticket_id; ?>" />
                                </th>
                                <td class="column-title">
                                    <strong>
                                        <a href="<?php echo admin_url('post.php?post=' . $ticket_id . '&action=edit'); ?>">
                                            <?php echo esc_html(get_the_title()); ?>
                                        </a>
                                    </strong>
                                    <div class="row-actions">
                                            <span class="edit">
                                                <a href="<?php echo admin_url('post.php?post=' . $ticket_id . '&action=edit'); ?>">Edit</a> |
                                            </span>
                                        <span class="trash">
                                                <a href="<?php echo get_delete_post_link($ticket_id); ?>" class="submitdelete">Delete</a>
                                            </span>
                                    </div>
                                </td>
                                <td><?php echo esc_html($customer_name . ' (' . $customer_email . ')'); ?></td>
                                <td>
                                        <span class="status-badge" style="background-color: <?php echo $status_colors[$status]; ?>; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px;">
                                            <?php echo ucwords(str_replace('_', ' ', $status)); ?>
                                        </span>
                                </td>
                                <td>
                                        <span class="priority-badge" style="background-color: <?php echo $priority_colors[$priority]; ?>; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px;">
                                            <?php echo ucwords($priority); ?>
                                        </span>
                                </td>
                                <td><?php echo ucwords(str_replace('_', ' ', $category)); ?></td>
                                <td><?php echo get_the_date(); ?></td>
                                <td>
                                    <a href="<?php echo admin_url('post.php?post=' . $ticket_id . '&action=edit'); ?>" class="button button-small">Edit</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="8">No support tickets found.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>

                <!-- Bottom Pagination -->
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        if ($total_pages > 1) {
                            echo $page_links;
                        }
                        ?>
                    </div>
                </div>
            </form>
        </div>

        <style>
            .status-badge, .priority-badge {
                display: inline-block;
                font-weight: bold;
                text-transform: uppercase;
            }
            .tablenav .actions select {
                margin-right: 5px;
            }
        </style>
        <?php

        wp_reset_postdata();
    }

    public function new_ticket_page() {
        if (isset($_POST['create_ticket'])) {
            $this->create_new_ticket();
        }

        ?>
        <div class="wrap">
            <h1>Add New Support Ticket</h1>

            <form method="post" action="">
                <?php wp_nonce_field('create_ticket_nonce', 'ticket_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="ticket_title">Title *</label></th>
                        <td><input name="ticket_title" type="text" id="ticket_title" class="regular-text" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ticket_content">Description</label></th>
                        <td>
                            <?php
                            wp_editor('', 'ticket_content', array(
                                'textarea_name' => 'ticket_content',
                                'media_buttons' => false,
                                'textarea_rows' => 10,
                                'teeny' => true
                            ));
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="customer_name">Customer Name *</label></th>
                        <td><input name="customer_name" type="text" id="customer_name" class="regular-text" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="customer_email">Customer Email *</label></th>
                        <td><input name="customer_email" type="email" id="customer_email" class="regular-text" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ticket_status">Status</label></th>
                        <td>
                            <select name="ticket_status" id="ticket_status">
                                <option value="open">Open</option>
                                <option value="in_progress">In Progress</option>
                                <option value="resolved">Resolved</option>
                                <option value="closed">Closed</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ticket_priority">Priority</label></th>
                        <td>
                            <select name="ticket_priority" id="ticket_priority">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ticket_category">Category</label></th>
                        <td>
                            <select name="ticket_category" id="ticket_category">
                                <option value="">Select Category</option>
                                <option value="technical">Technical Support</option>
                                <option value="billing">Billing</option>
                                <option value="general">General Inquiry</option>
                                <option value="bug_report">Bug Report</option>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Create Ticket', 'primary', 'create_ticket'); ?>
            </form>
        </div>
        <?php
    }

    private function create_new_ticket() {
        if (!wp_verify_nonce($_POST['ticket_nonce'], 'create_ticket_nonce')) {
            wp_die('Security check failed');
        }

        $title = sanitize_text_field($_POST['ticket_title']);
        $content = wp_kses_post($_POST['ticket_content']);
        $customer_name = sanitize_text_field($_POST['customer_name']);
        $customer_email = sanitize_email($_POST['customer_email']);
        $status = sanitize_text_field($_POST['ticket_status']);
        $priority = sanitize_text_field($_POST['ticket_priority']);
        $category = sanitize_text_field($_POST['ticket_category']);

        $post_data = array(
            'post_title' => $title,
            'post_content' => $content,
            'post_type' => 'support_ticket',
            'post_status' => 'publish',
            'post_author' => get_current_user_id()
        );

        $post_id = wp_insert_post($post_data);

        if ($post_id) {
            update_post_meta($post_id, '_customer_name', $customer_name);
            update_post_meta($post_id, '_customer_email', $customer_email);
            update_post_meta($post_id, '_ticket_status', $status);
            update_post_meta($post_id, '_ticket_priority', $priority);
            update_post_meta($post_id, '_ticket_category', $category);

            wp_redirect(admin_url('admin.php?page=support-tickets&created=1'));
            exit;
        }
    }

    private function handle_bulk_actions() {
        if (!wp_verify_nonce($_POST['bulk_nonce'], 'bulk_action_nonce')) {
            return;
        }

        $action = sanitize_text_field($_POST['action']);
        $ticket_ids = array_map('intval', $_POST['ticket_ids']);

        foreach ($ticket_ids as $ticket_id) {
            switch ($action) {
                case 'delete':
                    wp_delete_post($ticket_id, true);
                    break;
                case 'mark_resolved':
                    update_post_meta($ticket_id, '_ticket_status', 'resolved');
                    break;
                case 'mark_closed':
                    update_post_meta($ticket_id, '_ticket_status', 'closed');
                    break;
            }
        }

        wp_redirect(admin_url('admin.php?page=support-tickets&bulk_action=1'));
        exit;
    }

    public function activate() {
        $this->register_post_type();
        $this->create_tables();
        flush_rewrite_rules();
    }
}

// Initialize the plugin
new SupportTicketsPlugin();



