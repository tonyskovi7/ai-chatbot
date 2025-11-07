<?php
/**
 * REST API endpoints
 *
 * @package AIChatbot
 */

namespace AIChatbot;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API class
 */
class REST_API {

    const NAMESPACE = 'ai-chatbot/v1';
    const RATE_LIMIT_REQUESTS = 30;
    const RATE_LIMIT_WINDOW = 600; // 10 minutes

    /**
     * Register routes
     */
    public function register_routes() {
        // Public chat endpoint
        register_rest_route(self::NAMESPACE, '/chat', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_chat'],
            'permission_callback' => '__return_true', // Public endpoint
            'args' => [
                'message' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => [$this, 'sanitize_message'],
                    'validate_callback' => [$this, 'validate_message'],
                ],
            ],
        ]);

        // Admin ingest endpoint
        register_rest_route(self::NAMESPACE, '/ingest', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_ingest'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'args' => [
                'reindex' => [
                    'type' => 'boolean',
                    'default' => false,
                ],
                'url' => [
                    'type' => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                ],
            ],
        ]);
    }

    /**
     * Handle chat request
     */
    public function handle_chat($request) {
        // Check rate limit
        if (!$this->check_rate_limit()) {
            return new \WP_Error(
                'rate_limit_exceeded',
                __('Too many requests. Please try again later.', 'ai-chatbot'),
                ['status' => 429]
            );
        }

        $message = $request->get_param('message');

        // Generate response
        $response = OpenAI_Client::chat($message);

        if (isset($response['error'])) {
            // Log error if logging is enabled
            $this->log_error('chat_error', $response['error']);
        }

        return rest_ensure_response([
            'answer' => $response['answer'],
            'sources' => $response['sources'],
            'usage' => $response['usage'] ?? [],
        ]);
    }

    /**
     * Handle ingest request
     */
    public function handle_ingest($request) {
        $reindex = $request->get_param('reindex');
        $url = $request->get_param('url');

        if ($reindex) {
            // Reindex all links
            $results = Ingestion::reindex_all();

            return rest_ensure_response([
                'success' => true,
                'results' => $results,
            ]);
        } elseif ($url) {
            // Index single URL
            $result = Ingestion::index_url($url);

            if (is_wp_error($result)) {
                return new \WP_Error(
                    'index_error',
                    $result->get_error_message(),
                    ['status' => 400]
                );
            }

            return rest_ensure_response([
                'success' => true,
                'result' => $result,
            ]);
        }

        return new \WP_Error(
            'invalid_request',
            __('Either reindex or url parameter is required', 'ai-chatbot'),
            ['status' => 400]
        );
    }

    /**
     * Check admin permission
     */
    public function check_admin_permission() {
        return current_user_can('manage_options');
    }

    /**
     * Sanitize message
     */
    public function sanitize_message($message) {
        // Strip all HTML tags
        $message = wp_strip_all_tags($message);

        // Ensure UTF-8 encoding
        $message = mb_convert_encoding($message, 'UTF-8', 'UTF-8');

        // Trim whitespace
        $message = trim($message);

        return $message;
    }

    /**
     * Validate message
     */
    public function validate_message($message) {
        if (empty($message)) {
            return false;
        }

        if (mb_strlen($message) > 1000) {
            return false;
        }

        return true;
    }

    /**
     * Check rate limit
     */
    private function check_rate_limit() {
        $ip = $this->get_client_ip();
        $transient_key = 'ai_chatbot_rate_limit_' . md5($ip);

        $requests = get_transient($transient_key);

        if (false === $requests) {
            // First request in this window
            set_transient($transient_key, 1, self::RATE_LIMIT_WINDOW);
            return true;
        }

        if ($requests >= self::RATE_LIMIT_REQUESTS) {
            return false;
        }

        // Increment counter
        set_transient($transient_key, $requests + 1, self::RATE_LIMIT_WINDOW);
        return true;
    }

    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip = '';

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        }

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    /**
     * Log error
     */
    private function log_error($code, $message) {
        // Only log if error logging is enabled
        if (get_option('ai_chatbot_enable_logging', false)) {
            $log_entry = [
                'timestamp' => current_time('mysql'),
                'code' => $code,
                'message' => $message,
            ];

            $logs = get_option('ai_chatbot_error_logs', []);
            $logs[] = $log_entry;

            // Keep only last 50 entries
            $logs = array_slice($logs, -50);

            update_option('ai_chatbot_error_logs', $logs);
        }
    }
}
