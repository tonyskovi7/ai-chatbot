<?php
/**
 * OpenAI API client class
 *
 * @package AIChatbot
 */

namespace AIChatbot;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * OpenAI Client class
 */
class OpenAI_Client {

    const API_BASE = 'https://api.openai.com/v1';
    const MAX_RETRIES = 3;

    /**
     * Get API key from constant or option
     */
    private static function get_api_key() {
        // Prefer constant from wp-config.php
        if (defined('AI_CHATBOT_OPENAI_API_KEY') && AI_CHATBOT_OPENAI_API_KEY) {
            return AI_CHATBOT_OPENAI_API_KEY;
        }

        return get_option('ai_chatbot_api_key', '');
    }

    /**
     * Generate chat completion
     */
    public static function generate_completion($messages, $max_tokens = 700) {
        $api_key = self::get_api_key();

        if (empty($api_key)) {
            return new \WP_Error('no_api_key', __('OpenAI API key not configured', 'ai-chatbot'));
        }

        $model = get_option('ai_chatbot_model', 'gpt-4o');
        $temperature = (float) get_option('ai_chatbot_temperature', 0.2);

        $body = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $max_tokens,
        ];

        $response = self::make_request('/chat/completions', $body, $api_key);

        if (is_wp_error($response)) {
            return $response;
        }

        if (isset($response['choices'][0]['message']['content'])) {
            return [
                'content' => $response['choices'][0]['message']['content'],
                'usage' => $response['usage'] ?? [],
            ];
        }

        return new \WP_Error('invalid_response', __('Invalid response from OpenAI', 'ai-chatbot'));
    }

    /**
     * Generate embedding
     */
    public static function generate_embedding($text) {
        $api_key = self::get_api_key();

        if (empty($api_key)) {
            return new \WP_Error('no_api_key', __('OpenAI API key not configured', 'ai-chatbot'));
        }

        $body = [
            'model' => 'text-embedding-3-small',
            'input' => $text,
        ];

        $response = self::make_request('/embeddings', $body, $api_key);

        if (is_wp_error($response)) {
            return $response;
        }

        if (isset($response['data'][0]['embedding'])) {
            return $response['data'][0]['embedding'];
        }

        return new \WP_Error('invalid_response', __('Invalid embedding response from OpenAI', 'ai-chatbot'));
    }

    /**
     * Make API request with retry logic
     */
    private static function make_request($endpoint, $body, $api_key, $retry_count = 0) {
        $response = wp_remote_post(self::API_BASE . $endpoint, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        // Handle rate limiting (429) and server errors (5xx)
        if (($code === 429 || $code >= 500) && $retry_count < self::MAX_RETRIES) {
            // Exponential backoff
            $wait_time = pow(2, $retry_count);
            sleep($wait_time);
            return self::make_request($endpoint, $body, $api_key, $retry_count + 1);
        }

        if ($code !== 200) {
            $error_message = $data['error']['message'] ?? sprintf(__('HTTP error %d', 'ai-chatbot'), $code);
            return new \WP_Error('api_error', $error_message);
        }

        return $data;
    }

    /**
     * Build chat messages
     */
    public static function build_messages($query, $context) {
        $fallback = get_option('ai_chatbot_fallback_message', __('I don\'t have enough information to answer that question.', 'ai-chatbot'));
        $extra_instructions = get_option('ai_chatbot_extra_instructions', '');

        $system_message = sprintf(
            "You are a helpful assistant. Answer questions ONLY using the provided context below. If the context doesn't contain enough information to answer the question, respond with: \"%s\"\n\nBe concise and accurate. Always cite your sources when possible.",
            $fallback
        );

        if (!empty($extra_instructions)) {
            $system_message .= "\n\nAdditional instructions:\n" . $extra_instructions;
        }

        if (!empty($context)) {
            $system_message .= "\n\nContext:\n" . $context;
        }

        return [
            [
                'role' => 'system',
                'content' => $system_message,
            ],
            [
                'role' => 'user',
                'content' => $query,
            ],
        ];
    }

    /**
     * Generate chat response
     */
    public static function chat($query) {
        // Retrieve relevant chunks
        $chunks = Retrieval::retrieve($query);

        // If no relevant chunks found, return fallback
        if (empty($chunks)) {
            $fallback = get_option('ai_chatbot_fallback_message', __('I don\'t have enough information to answer that question.', 'ai-chatbot'));
            return [
                'answer' => $fallback,
                'sources' => [],
                'usage' => [],
            ];
        }

        // Format context
        $context = Retrieval::format_context($chunks);

        // Build messages
        $messages = self::build_messages($query, $context);

        // Generate completion
        $completion = self::generate_completion($messages);

        if (is_wp_error($completion)) {
            // Return fallback on error
            $fallback = get_option('ai_chatbot_fallback_message', __('I don\'t have enough information to answer that question.', 'ai-chatbot'));
            return [
                'answer' => $fallback,
                'sources' => [],
                'usage' => [],
                'error' => $completion->get_error_message(),
            ];
        }

        // Extract sources
        $sources = Retrieval::extract_sources($chunks);

        return [
            'answer' => $completion['content'],
            'sources' => $sources,
            'usage' => $completion['usage'],
        ];
    }
}
