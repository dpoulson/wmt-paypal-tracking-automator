<?php
if (!defined('ABSPATH')) exit;

class WMT_PayPal_Sync {

    public function __construct() {
        // High priority to ensure we are the last thing to run
        add_action('woocommerce_order_note_added', [$this, 'handle_order_note'], 99, 2);
        add_action('woocommerce_order_actions', [$this, 'add_resync_order_action']);
        add_action('woocommerce_order_action_resync_paypal_tracking', [$this, 'process_manual_resync']);
    }

    private function log($message) {
        $logger = wc_get_logger();
        $logger->info($message, ['source' => 'paypal-sync-debug']);
    }

    public function handle_order_note($note_id, $order) {
        $this->log("--- New Note Detected (ID: $note_id) ---");

        if (!$order || !is_a($order, 'WC_Order')) {
            $this->log("ERROR: Order object not valid.");
            return;
        }

        $payment_method = $order->get_payment_method();
        $this->log("Order #{$order->get_id()} | Payment Method: $payment_method");

        // Checking note content
        $note = get_comment($note_id);
        if (!$note) {
            $this->log("ERROR: Could not retrieve note content for ID $note_id");
            return;
        }

        $content = $note->comment_content;
        $this->log("Note Content: " . substr($content, 0, 50) . "...");

        // Process if PayPal
        if (strpos($payment_method, 'paypal') !== false || strpos($payment_method, 'ppcp') !== false) {
            $this->parse_and_sync($order, $content);
        } else {
            $this->log("Skipping: Not a PayPal order.");
        }
    }

    private function parse_and_sync($order, $text) {
        $this->log("Scanning text for tracking pattern...");
        
        // Pattern: Your tracking number is XXXXX
        if (preg_match('/Your tracking number is\s*([A-Z0-9]+)/i', $text, $matches)) {
            $tracking_number = trim($matches[1]);
            $this->log("Pattern Match Found: $tracking_number");

            $carrier_code = $this->identify_carrier($text);
            $this->log("Carrier Identified: $carrier_code");

            $this->apply_tracking_to_db($order, $tracking_number, $carrier_code);
            return true;
        }
        
        $this->log("No tracking pattern found in this note.");
        return false;
    }

    private function identify_carrier($content) {
        $map = [
            'International Tracked' => 'ROYAL_MAIL',
            'Royal Mail'            => 'ROYAL_MAIL',
            'DPD'                   => 'DPD_UK',
            'Evri'                  => 'HERMES_UK'
        ];
        foreach ($map as $keyword => $code) {
            if (stripos($content, $keyword) !== false) return $code;
        }
        return 'OTHER';
    }

    private function apply_tracking_to_db($order, $tracking_number, $carrier) {
        $order_id = $order->get_id();
        $this->log("Direct API: Starting push for #$order_id");
    
        // 1. Get credentials from the PPCP settings we identified
        $ppcp_settings = get_option('woocommerce-ppcp-settings');
    
        $client_id     = $ppcp_settings['client_id_production'] ?? $ppcp_settings['client_id'] ?? '';
        $client_secret = $ppcp_settings['client_secret_production'] ?? $ppcp_settings['client_secret'] ?? '';
        $merchant_id   = $ppcp_settings['merchant_id_production'] ?? $ppcp_settings['merchant_id'] ?? '';
        $is_sandbox    = isset($ppcp_settings['sandbox_on']) && $ppcp_settings['sandbox_on'] === true;
        $api_url       = $is_sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    
        if (!$client_id || !$client_secret) {
            $this->log("Error: Credentials missing.");
            return;
        }
    
        // 2. Authenticate
        $auth = wp_remote_post("$api_url/v1/oauth2/token", [
            'headers' => ['Authorization' => 'Basic ' . base64_encode("$client_id:$client_secret")],
            'body'    => 'grant_type=client_credentials',
        ]);
        $auth_body = json_decode(wp_remote_retrieve_body($auth), true);
        $token = $auth_body['access_token'] ?? '';
    
        if (!$token) {
            $this->log("Auth Failed: " . wp_remote_retrieve_body($auth));
            return;
        }
    
        // 3. Get the PayPal Transaction/Capture ID
        $transaction_id = get_post_meta($order_id, '_transaction_id', true);
        if (!$transaction_id) {
            $transaction_id = get_post_meta($order_id, '_ppcp_paypal_order_id', true);
        }
    
        if (!$transaction_id) {
            $this->log("Error: No PayPal Transaction ID for #$order_id");
            return;
        }
    
        // 4. Build Payload with the verified ROYAL_MAIL carrier
        $payload = [
            'trackers' => [[
                'transaction_id'  => (string)$transaction_id,
                'tracking_number' => (string)$tracking_number,
                'status'          => 'SHIPPED',
                'carrier'         => 'ROYAL_MAIL', // Verified as correct
                'notify_buyer'    => false
            ]]
        ];
    
        if ($merchant_id) {
            $payload['trackers'][0]['account_id'] = $merchant_id;
        }
    
        // 5. POST to PayPal
        $response = wp_remote_post("$api_url/v1/shipping/trackers-batch", [
            'headers' => [
                'Authorization' => "Bearer $token",
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode($payload),
        ]);
    
        $res_code = wp_remote_retrieve_response_code($response);
        if ($res_code >= 200 && $res_code < 300) {
            $this->log("SUCCESS: Tracking pushed to PayPal for #$order_id");
            $order->add_order_note(sprintf(
                /* translators: %s: tracking number */
                __('PayPal API: Tracking %s (Royal Mail) added to transaction.', 'wmt-paypal-tracking-automator'),
                $tracking_number
            ));
            
            // Mark as synced so the automated scan doesn't process it again
            update_post_meta($order_id, '_ppcp_paypal_tracking_sync_status', 'synced');
        } else {
            $this->log("API Error ($res_code): " . wp_remote_retrieve_body($response));
        }
    }

    public function add_resync_order_action($actions) {
        global $theorder;
        if ($theorder) {
            $actions['resync_paypal_tracking'] = __('Manual PayPal Sync Scan', 'wmt-paypal-tracking-automator');
        }
        return $actions;
    }

    public function process_manual_resync($order) {
        $this->log("MANUAL RESYNC START for Order #" . $order->get_id());
        $notes = wc_get_order_notes(['order_id' => $order->get_id()]);
        foreach ($notes as $note) {
            $this->parse_and_sync($order, $note->content);
        }
    }
}