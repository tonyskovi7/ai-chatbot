<?php
/**
 * Plugin Name: AI Chatbot
 * Plugin URI: https://example.com/ai-chatbot
 * Description: Lean, secure AI chatbot using curated links and OpenAI. No external dependencies.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-chatbot
 * Domain Path: /languages
 */

namespace AIChatbot;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('AI_CHATBOT_VERSION', '1.0.0');
define('AI_CHATBOT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AI_CHATBOT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AI_CHATBOT_PLUGIN_FILE', __FILE__);

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'AIChatbot\\';
    $base_dir = AI_CHATBOT_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . 'class-' . strtolower(str_replace('_', '-', $relative_class)) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Main plugin class
 */
class AI_Chatbot {

    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        register_activation_hook(AI_CHATBOT_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(AI_CHATBOT_PLUGIN_FILE, [$this, 'deactivate']);

        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('init', [$this, 'init']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        if (is_admin()) {
            add_action('admin_menu', [$this, 'register_admin_menu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        } else {
            add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
            add_action('wp_footer', [$this, 'render_chat_widget']);
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        require_once AI_CHATBOT_PLUGIN_DIR . 'includes/class-database.php';
        Database::create_tables();

        // Set default options
        $defaults = [
            'api_key' => '',
            'model' => 'gpt-4o',
            'temperature' => 0.2,
            'extra_instructions' => '',
            'fallback_message' => __('I don\'t have enough information to answer that question accurately.', 'ai-chatbot'),
            'links' => [],
            'use_embeddings' => false,
            'auto_reindex_on_add' => true,
            'widget_title' => __('Chat with us', 'ai-chatbot'),
            'widget_placeholder' => __('Ask a question...', 'ai-chatbot'),
        ];

        foreach ($defaults as $key => $value) {
            if (false === get_option('ai_chatbot_' . $key)) {
                add_option('ai_chatbot_' . $key, $value);
            }
        }

        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain('ai-chatbot', false, dirname(plugin_basename(AI_CHATBOT_PLUGIN_FILE)) . '/languages');
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Additional initialization if needed
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        require_once AI_CHATBOT_PLUGIN_DIR . 'includes/class-rest-api.php';
        $api = new REST_API();
        $api->register_routes();
    }

    /**
     * Register admin menu
     */
    public function register_admin_menu() {
        add_menu_page(
            __('AI Chatbot', 'ai-chatbot'),
            __('AI Chatbot', 'ai-chatbot'),
            'manage_options',
            'ai-chatbot',
            [$this, 'render_admin_page'],
            'dashicons-format-chat',
            30
        );
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        require_once AI_CHATBOT_PLUGIN_DIR . 'admin/admin-page.php';
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if ('toplevel_page_ai-chatbot' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'ai-chatbot-admin',
            AI_CHATBOT_PLUGIN_URL . 'assets/css/admin.css',
            [],
            AI_CHATBOT_VERSION
        );

        wp_enqueue_script(
            'ai-chatbot-admin',
            AI_CHATBOT_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'wp-api'],
            AI_CHATBOT_VERSION,
            true
        );

        wp_localize_script('ai-chatbot-admin', 'aiChatbotAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('ai-chatbot/v1'),
            'nonce' => wp_create_nonce('ai_chatbot_admin'),
            'i18n' => [
                'reindexing' => __('Reindexing...', 'ai-chatbot'),
                'success' => __('Success!', 'ai-chatbot'),
                'error' => __('Error occurred', 'ai-chatbot'),
            ]
        ]);
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        wp_enqueue_style(
            'ai-chatbot-widget',
            AI_CHATBOT_PLUGIN_URL . 'assets/css/widget.css',
            [],
            AI_CHATBOT_VERSION
        );

        wp_enqueue_script(
            'ai-chatbot-widget',
            AI_CHATBOT_PLUGIN_URL . 'assets/js/widget.js',
            [],
            AI_CHATBOT_VERSION,
            true
        );

        wp_localize_script('ai-chatbot-widget', 'aiChatbotConfig', [
            'restUrl' => rest_url('ai-chatbot/v1/chat'),
            'nonce' => wp_create_nonce('wp_rest'),
            'title' => get_option('ai_chatbot_widget_title', __('Chat with us', 'ai-chatbot')),
            'placeholder' => get_option('ai_chatbot_widget_placeholder', __('Ask a question...', 'ai-chatbot')),
            'i18n' => [
                'thinking' => __('Thinking...', 'ai-chatbot'),
                'error' => __('Sorry, something went wrong. Please try again.', 'ai-chatbot'),
                'sendButton' => __('Send', 'ai-chatbot'),
                'closeButton' => __('Close', 'ai-chatbot'),
            ]
        ]);
    }

    /**
     * Render chat widget HTML
     */
    public function render_chat_widget() {
        echo '<div id="ai-chatbot-container"></div>';
    }
}

// Initialize plugin
AI_Chatbot::instance();
