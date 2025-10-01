<?php
/**
 * Plugin Name: WebChain Order Sync
 * Description: Syncs WooCommerce orders to E-Talk's WebChain
 * Version: 2.9.2
 * Author: E-Talk
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Text Domain: webchain-order-sync
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

class WebChain_Order_Sync {
    private $api_base = 'https://e-talk.xyz/wp-json/webchain/v1';
    
    public function __construct() {
        $this->log("Plugin initialized at " . date('Y-m-d H:i:s'));
        // Admin interface
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        
        // Order handling
        add_action('woocommerce_order_status_completed', [$this, 'handle_completed_order'], 10, 1);
        add_filter('woocommerce_admin_order_actions', [$this, 'add_order_action'], 100, 2);
        add_action('admin_enqueue_scripts', [$this, 'add_order_action_style']);
        
        // AJAX handlers
        add_action('wp_ajax_webchain_test_connection', [$this, 'test_connection']);
        add_action('wp_ajax_webchain_broadcast_order', [$this, 'ajax_broadcast_order']);
        add_action('wp_ajax_webchain_test_broadcast', [$this, 'test_broadcast']);
        add_action('wp_ajax_webchain_clear_error_logs', [$this, 'clear_error_logs']);
        
        // Assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets'], 100);
    }

    public function add_admin_menu() {
        $this->log("WebChain: Adding admin menu at " . date('Y-m-d H:i:s'));
        add_menu_page(
            'WebChain Sync',
            'WebChain',
            'manage_options',
            'webchain-sync',
            [$this, 'render_settings_page'],
            'dashicons-block-default',
            56
        );
    }

    public function register_settings() {
        $this->log("WebChain: Registering settings at " . date('Y-m-d H:i:s'));
        register_setting('webchain_settings', 'webchain_user_email', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default' => ''
        ]);
        
        register_setting('webchain_settings', 'webchain_wallet', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_wallet'],
            'default' => ''
        ]);
    }

    public function render_settings_page() {
        $this->log("WebChain: Rendering settings page at " . date('Y-m-d H:i:s'));
    ?>
    <div class="wrap webchain-admin-wrap">
        <h1>WebChain Integration</h1>
        
        <form method="post" action="options.php" class="webchain-card">
            <?php settings_fields('webchain_settings'); ?>
            
            <h2>Validator Settings</h2>
            
            <table class="form-table webchain-form-table">
                <tr>
                    <th scope="row"><label for="webchain_user_email">E-Talk Account Email</label></th>
                    <td>
                        <input type="email" name="webchain_user_email" id="webchain_user_email" 
                               value="<?php echo esc_attr(get_option('webchain_user_email')); ?>">
                        <p class="description">The email associated with your E-Talk vendor account</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><label for="webchain_wallet">Wallet Address</label></th>
                    <td>
                        <input type="text" name="webchain_wallet" id="webchain_wallet"
                               value="<?php echo esc_attr(get_option('webchain_wallet')); ?>"
                               pattern="^0x[a-fA-F0-9]{40}$">
                        <p class="description">Your validator wallet address (0x...)</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Connection Status</th>
                    <td>
                        <button type="button" id="test-connection" class="webchain-button">
                            Verify Connection
                        </button>
                        <span id="connection-status" class="webchain-status">
                            <?php echo esc_html(get_option('webchain_connection_status', 'Not verified')); ?>
                        </span>
                    </td>
                </tr>
            </table>
            
            <?php submit_button('Save Settings', 'primary', 'submit', false); ?>
        </form>
        
        <!-- Test Broadcast Section -->
        <div class="webchain-card">
            <h2>Test Order Broadcast</h2>
            <table class="form-table webchain-form-table">
                <tr>
                    <th scope="row"><label for="test_order_id">Test Order ID</label></th>
                    <td>
                        <input type="number" id="test_order_id" name="test_order_id" min="1">
                        <button type="button" id="test-broadcast" class="webchain-button">Test Broadcast</button>
                        <p class="description">Enter an order ID to test broadcasting to WebChain.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Test Result</th>
                    <td>
                        <span id="test-broadcast-result" class="webchain-status"></span>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Recent Errors Section -->
        <div class="webchain-card">
            <h2>Recent Errors</h2>
            <?php
            $errors = get_option('webchain_sync_errors', []);
            if (empty($errors)) {
                echo '<p>No errors logged.</p>';
            } else {
                echo '<ul>';
                foreach (array_slice($errors, -10) as $error) {
                    echo '<li>' . esc_html($error['time'] . ': Order #' . $error['order_id'] . ' - ' . $error['message']) . '</li>';
                }
                echo '</ul>';
                ?>
                <button type="button" id="clear-error-logs" class="webchain-button">Clear Error Logs</button>
                <?php
            }
            ?>
        </div>
        
        <div id="webchain-alerts"></div>
    </div>
    <?php
    }

    public function handle_completed_order($order_id) {
        $this->log("WebChain: handle_completed_order triggered for Order ID: $order_id at " . date('Y-m-d H:i:s'));
        
        // Check for existing transaction hash to prevent duplicate broadcast
        $existing_tx_hash = get_post_meta($order_id, '_webchain_tx_hash', true);
        if ($existing_tx_hash) {
            $this->log("WebChain: Order $order_id already broadcast with TX Hash: $existing_tx_hash");
            return "already_broadcast:$existing_tx_hash";
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log_error($order_id, 'Order not found');
            return false;
        }
        
        // Validate order data
        $total = (float) $order->get_total();
        $items = $order->get_items();
        $billing_email = $order->get_billing_email() ?: 'guest@example.com';
        if ($total <= 0 || empty($items)) {
            $this->log_error($order_id, 'Invalid order data: Total=' . $total . ', Items=' . count($items));
            return false;
        }
        
        $email = get_option('webchain_user_email');
        $wallet = get_option('webchain_wallet');
        $this->log("WebChain: Order $order_id - Email: $email, Wallet: $wallet, Billing Email: $billing_email");
        
        if (empty($email) || empty($wallet)) {
            $this->log_error($order_id, 'Missing email or wallet configuration');
            return false;
        }
        
        // Pre-validate credentials
        $verify_response = wp_remote_post($this->api_base . '/verify-validator', [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'user_email' => $email,
                'wallet' => $wallet
            ])
        ]);
        
        if (is_wp_error($verify_response)) {
            $error_message = 'Validator verification failed: ' . $verify_response->get_error_message();
            $this->log_error($order_id, $error_message);
            return false;
        }
        
        $verify_code = wp_remote_retrieve_response_code($verify_response);
        $verify_body = wp_remote_retrieve_body($verify_response);
        $verify_data = json_decode($verify_body, true);
        $this->log("WebChain: Validator verification for Order $order_id - Code: $verify_code, Body: $verify_body");
        
        if ($verify_code !== 200) {
            $error_message = $verify_data['message'] ?? 'Validator verification failed';
            $this->log_error($order_id, $error_message);
            return false;
        }
        
        $payload = [
            'user_email' => $email,
            'wallet' => $wallet,
            'order_data' => [
                'order_id' => (int) $order_id,
                'amount' => $total,
                'currency' => $order->get_currency(),
                'customer' => [
                    'id' => (int) $order->get_customer_id(),
                    'email' => $billing_email
                ],
                'items' => array_values(array_map(function($item) {
                    $product = $item->get_product();
                    return [
                        'product_id' => (int) $item->get_product_id(),
                        'name' => $item->get_name(),
                        'quantity' => (int) $item->get_quantity(),
                        'price' => (float) $item->get_total(),
                        'sku' => $product ? $product->get_sku() : ''
                    ];
                }, $items))
            ]
        ];
        
        $this->log("WebChain: Sending payload for Order $order_id: " . json_encode($payload));
        
        $response = wp_remote_post($this->api_base . '/process-order', [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($payload)
        ]);
        
        if (is_wp_error($response)) {
            $error_message = 'API request failed: ' . $response->get_error_message();
            $this->log_error($order_id, $error_message);
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_headers = wp_remote_retrieve_headers($response);
        $response_body = wp_remote_retrieve_body($response);
        $this->log("WebChain: API Response for Order $order_id - Code: $response_code, Headers: " . json_encode($response_headers->getAll()) . ", Body: $response_body");
        
        $body = json_decode($response_body, true);
        
        // Handle both response formats for robustness
        if ($response_code === 200 && (isset($body['tx_hash']) || (isset($body['success']) && $body['success'] && isset($body['data']['tx_hash'])))) {
            $tx_hash = $body['tx_hash'] ?? $body['data']['tx_hash'];
            update_post_meta($order_id, '_webchain_tx_hash', $tx_hash);
            $this->log("WebChain: Order $order_id broadcasted successfully, TX Hash: " . $tx_hash);
            $this->send_broadcast_notification($order_id, $tx_hash, $billing_email);
            return true;
        }
        
        $error_message = isset($body['message']) ? $body['message'] : ($response_body ?: 'API returned invalid response');
        $this->log_error($order_id, $error_message);
        return false;
    }

    private function send_broadcast_notification($order_id, $tx_hash, $billing_email) {
        $this->log("WebChain: Sending broadcast notification for Order ID: $order_id, TX Hash: $tx_hash");
        
        $explorer_url = 'https://e-talk.xyz/webchain?tx=' . $tx_hash; // Updated URL
        $site_name = get_bloginfo('name');
        $admin_email = get_option('admin_email');
        
        // Email template
        $email_template = '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
                <h2 style="color: #1e4620;">Order Broadcast to WebChain</h2>
                <p>Order #%s has been successfully recorded on the WebChain blockchain.</p>
                <p><strong>Transaction Hash:</strong> %s</p>
                <p><a href="%s" style="color: #007cba; text-decoration: none;">View Transaction on WebChain Explorer</a></p>
                <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
                <p style="color: #666; font-size: 12px;">This email was sent by %s.</p>
            </div>';

        // Admin email
        $admin_subject = sprintf('WebChain Order Broadcast: Order #%s', $order_id);
        $admin_message = sprintf(
            $email_template,
            esc_html($order_id),
            esc_html($tx_hash),
            esc_url($explorer_url),
            esc_html($site_name)
        );
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $admin_sent = wp_mail($admin_email, $admin_subject, $admin_message, $headers);
        $this->log("WebChain: Admin email for Order $order_id " . ($admin_sent ? 'sent successfully' : 'failed to send'));

        // Customer email
        if ($billing_email !== 'guest@example.com') {
            $customer_subject = sprintf('Your Order #%s Has Been Recorded on WebChain', $order_id);
            $customer_message = sprintf(
                $email_template,
                esc_html($order_id),
                esc_html($tx_hash),
                esc_url($explorer_url),
                esc_html($site_name)
            );
            $customer_sent = wp_mail($billing_email, $customer_subject, $customer_message, $headers);
            $this->log("WebChain: Customer email for Order $order_id to $billing_email " . ($customer_sent ? 'sent successfully' : 'failed to send'));
        } else {
            $this->log("WebChain: Skipped customer email for Order $order_id (guest order)");
        }
    }
    
    private function log($message) {
        if (defined('WEBCHAIN_DEBUG') && WEBCHAIN_DEBUG) {
            error_log('[WebChain] ' . $message);
        }
    }
    
    public function ajax_broadcast_order() {
        $this->log("WebChain: AJAX broadcast_order started at " . date('Y-m-d H:i:s'));
        check_ajax_referer('webchain_broadcast_order', 'security');
        if (!current_user_can('edit_shop_orders')) {
            $this->log("WebChain: AJAX broadcast_order failed: Unauthorized");
            wp_send_json_error('Unauthorized', 403);
        }

        $order_id = absint($_REQUEST['order_id']);
        $this->log("WebChain: AJAX broadcast_order for Order ID: $order_id");
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log("WebChain: AJAX broadcast_order failed: Order $order_id not found");
            wp_send_json_error('Order not found', 404);
        }

        $result = $this->handle_completed_order($order_id);
        if ($result === true) {
            $tx_hash = get_post_meta($order_id, '_webchain_tx_hash', true);
            wp_send_json_success("Success: Transaction $tx_hash");
        } elseif (is_string($result) && strpos($result, 'already_broadcast:') === 0) {
            $tx_hash = substr($result, strlen('already_broadcast:'));
            wp_send_json_error("Order already broadcast: Transaction $tx_hash", 400);
        } else {
            $error_message = get_option('webchain_sync_errors', []);
            $last_error = end($error_message) ? end($error_message)['message'] : 'Broadcast failed: Check error logs';
            $this->log("WebChain: AJAX broadcast_order failed for Order $order_id: $last_error");
            wp_send_json_error($last_error, 500);
        }
    }

    public function test_broadcast() {
        $this->log("WebChain: AJAX test_broadcast started at " . date('Y-m-d H:i:s'));
        check_ajax_referer('webchain_ajax_nonce', 'security');
        if (!current_user_can('edit_shop_orders')) {
            $this->log("WebChain: AJAX test_broadcast failed: Unauthorized");
            wp_send_json_error('Unauthorized', 403);
        }
        $order_id = absint($_POST['order_id']);
        $this->log("WebChain: AJAX test_broadcast for Order ID: $order_id");
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log("WebChain: AJAX test_broadcast failed: Order $order_id not found");
            wp_send_json_error('Order not found', 404);
        }
        $result = $this->handle_completed_order($order_id);
        if ($result === true) {
            $tx_hash = get_post_meta($order_id, '_webchain_tx_hash', true);
            wp_send_json_success("Success: Transaction $tx_hash");
        } elseif (is_string($result) && strpos($result, 'already_broadcast:') === 0) {
            $tx_hash = substr($result, strlen('already_broadcast:'));
            wp_send_json_error("Order already broadcast: Transaction $tx_hash", 400);
        } else {
            $error_message = get_option('webchain_sync_errors', []);
            $last_error = end($error_message) ? end($error_message)['message'] : 'Test broadcast failed: Check error logs';
            $this->log("WebChain: AJAX test_broadcast failed for Order $order_id: $last_error");
            wp_send_json_error($last_error, 500);
        }
    }

    public function clear_error_logs() {
        $this->log("WebChain: AJAX clear_error_logs started at " . date('Y-m-d H:i:s'));
        check_ajax_referer('webchain_ajax_nonce', 'security');
        if (!current_user_can('manage_options')) {
            $this->log("WebChain: AJAX clear_error_logs failed: Unauthorized");
            wp_send_json_error('Unauthorized', 403);
        }

        update_option('webchain_sync_errors', []);
        $this->log("WebChain: Error logs cleared successfully");
        wp_send_json_success('Error logs cleared successfully');
    }

    public function add_order_action($actions, $order) {
        $order_id = $order->get_id();
        $tx_hash = get_post_meta($order_id, '_webchain_tx_hash', true);
        $this->log("WebChain: Adding action for Order ID: $order_id, TX Hash: " . ($tx_hash ?: 'None'));
    
        if ($tx_hash) {
            $actions['webchain_view'] = [
                'url' => 'https://e-talk.xyz/webchain?tx=' . $tx_hash, // Updated URL
                'name' => 'View on Explorer',
                'action' => 'webchain-view'
            ];
        } else {
            $actions['webchain_broadcast'] = [
                'url' => '#',
                'name' => 'Broadcast to WebChain',
                'action' => 'webchain-broadcast',
                'data' => [
                    'order_id' => $order_id,
                    'nonce' => wp_create_nonce('webchain_broadcast_order')
                ]
            ];
        }
    
        return $actions;
    }

    public function test_connection() {
        $this->log("WebChain: AJAX test_connection started at " . date('Y-m-d H:i:s'));
        check_ajax_referer('webchain_ajax_nonce', 'security');
        $email = sanitize_email($_POST['email']);
        $wallet = $this->sanitize_wallet($_POST['wallet']);
        
        if (empty($email) || empty($wallet)) {
            $this->log("WebChain: AJAX test_connection failed: Email or wallet missing");
            wp_send_json_error('Email and wallet are required', 400);
        }
        
        $response = wp_remote_post($this->api_base . '/verify-validator', [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'user_email' => $email,
                'wallet' => $wallet
            ])
        ]);
        
        if (is_wp_error($response)) {
            $error_message = 'API request failed: ' . $response->get_error_message();
            $this->log("WebChain: AJAX test_connection failed: $error_message");
            wp_send_json_error($error_message, 500);
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_headers = wp_remote_retrieve_headers($response);
        $response_body = wp_remote_retrieve_body($response);
        $this->log("WebChain: Verify Validator Response - Code: $response_code, Headers: " . json_encode($response_headers->getAll()) . ", Body: $response_body");
        
        $body = json_decode($response_body, true);
        
        if ($response_code !== 200) {
            $this->log("WebChain: AJAX test_connection failed: " . ($body['message'] ?? 'Verification failed'));
            wp_send_json_error($body['message'] ?? 'Verification failed', $response_code);
        }
        
        update_option('webchain_user_email', $email);
        update_option('webchain_wallet', $wallet);
        update_option('webchain_connection_status', '✓ Connected - Balance: ' . ($body['balance'] ?? 0) . ' ETK');
        
        wp_send_json_success([
            'message' => 'Successfully connected!',
            'balance' => $body['balance'] ?? 0
        ]);
    }

    public function enqueue_assets($hook) {
        global $post_type;
        $this->log("WebChain: Enqueue assets called on hook: $hook, Post type: " . ($post_type ?: 'none') . " at " . date('Y-m-d H:i:s'));
        if ('toplevel_page_webchain-sync' === $hook || 'shop_order' === $post_type || 'edit.php' === $hook) {
            wp_enqueue_style(
                'webchain-admin',
                plugins_url('assets/css/admin.css', __FILE__),
                [],
                filemtime(plugin_dir_path(__FILE__) . 'assets/css/admin.css')
            );
            
            wp_enqueue_script(
                'webchain-admin',
                plugins_url('assets/js/admin.js', __FILE__),
                ['jquery'],
                filemtime(plugin_dir_path(__FILE__) . 'assets/js/admin.js'),
                true
            );
            
            wp_localize_script('webchain-admin', 'webchainData', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('webchain_ajax_nonce')
            ]);
        }
    }

    public function add_order_action_style($hook) {
        $custom_css = "
            .webchain-broadcast::after { content: '\\f237'; font-family: dashicons; }
            .webchain-view::after { content: '\\f177'; font-family: dashicons; }
            .webchain-broadcast, .webchain-view { display: inline-block !important; visibility: visible !important; }
        ";
        wp_register_style('webchain-inline-style', false);
        wp_enqueue_style('webchain-inline-style');
        wp_add_inline_style('webchain-inline-style', $custom_css);
    }

    private function log_error($order_id, $message) {
        $this->log("WebChain Sync Error [Order $order_id]: $message at " . date('Y-m-d H:i:s'));
        $errors = get_option('webchain_sync_errors', []);
        $errors[] = [
            'time' => current_time('mysql'),
            'order_id' => $order_id,
            'message' => $message
        ];
        update_option('webchain_sync_errors', array_slice($errors, -100));
    }

    public function sanitize_wallet($wallet) {
        $wallet = strtolower(sanitize_text_field($wallet));
           return preg_match('/^0x[a-f0-9]{40}$/', $wallet) ? $wallet : '';
        }
    }


    add_action('admin_init', function() {
        if (function_exists('wp_add_privacy_policy_content')) {
            $content = '<p><strong>WebChain Order Sync:</strong> This plugin sends order data 
            (Order ID, order total, currency, customer ID, customer email, and product details) 
            to the E-Talk WebChain API (https://e-talk.xyz/) for decentralized order recording. 
            No payment card details, shipping addresses, or sensitive personal data are transmitted. 
            Site administrators should include this information in their site\'s privacy policy.</p>';

            wp_add_privacy_policy_content('WebChain Order Sync', wp_kses_post($content));
        }
    });
new WebChain_Order_Sync();
