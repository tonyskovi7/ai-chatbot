<?php
/**
 * Content ingestion class
 *
 * @package AIChatbot
 */

namespace AIChatbot;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ingestion class
 */
class Ingestion {

    const CHUNK_MIN_SIZE = 900;
    const CHUNK_MAX_SIZE = 1200;
    const CHUNK_OVERLAP = 200;

    /**
     * Fetch and index a URL
     */
    public static function index_url($url) {
        // Validate and normalize URL
        $url = self::normalize_url($url);
        if (!$url) {
            return new \WP_Error('invalid_url', __('Invalid URL provided', 'ai-chatbot'));
        }

        // Fetch content
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'redirection' => 5,
            'user-agent' => 'AI-Chatbot-Plugin/' . AI_CHATBOT_VERSION,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new \WP_Error('http_error', sprintf(__('HTTP error %d', 'ai-chatbot'), $code));
        }

        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (!self::is_valid_content_type($content_type)) {
            return new \WP_Error('invalid_content_type', __('Unsupported content type', 'ai-chatbot'));
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return new \WP_Error('empty_content', __('Empty content received', 'ai-chatbot'));
        }

        // Extract text and title
        $extracted = self::extract_content($html);
        $text = $extracted['text'];
        $title = $extracted['title'];

        // Generate checksum
        $checksum = hash('sha256', $text);

        // Check if content has changed
        $existing_checksum = Database::get_url_checksum($url);
        if ($existing_checksum === $checksum) {
            return [
                'status' => 'unchanged',
                'url' => $url,
                'title' => $title,
                'chunks' => Database::get_chunk_count_by_url($url),
            ];
        }

        // Delete old chunks
        Database::delete_chunks_by_url($url);

        // Chunk the content
        $chunks = self::chunk_text($text);

        // Store chunks
        $stored_count = 0;
        foreach ($chunks as $index => $chunk) {
            $token_estimate = self::estimate_tokens($chunk);

            $result = Database::insert_chunk([
                'url' => $url,
                'title' => $title,
                'chunk_index' => $index,
                'content' => $chunk,
                'token_estimate' => $token_estimate,
                'checksum' => $checksum,
            ]);

            if ($result) {
                $stored_count++;
            }
        }

        // Generate embeddings if enabled
        if (get_option('ai_chatbot_use_embeddings', false)) {
            self::generate_embeddings_for_url($url);
        }

        return [
            'status' => 'indexed',
            'url' => $url,
            'title' => $title,
            'chunks' => $stored_count,
        ];
    }

    /**
     * Normalize URL
     */
    private static function normalize_url($url) {
        $url = esc_url_raw($url);
        if (!$url) {
            return false;
        }

        // Ensure HTTPS
        $url = str_replace('http://', 'https://', $url);

        // Normalize trailing slash
        $url = rtrim($url, '/');

        return $url;
    }

    /**
     * Check if content type is valid
     */
    private static function is_valid_content_type($content_type) {
        $valid_types = [
            'text/html',
            'text/plain',
            'application/xhtml+xml',
        ];

        foreach ($valid_types as $type) {
            if (stripos($content_type, $type) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract readable content from HTML
     */
    private static function extract_content($html) {
        // Suppress warnings from DOMDocument
        libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        libxml_clear_errors();

        // Extract title
        $title = '';
        $title_tags = $dom->getElementsByTagName('title');
        if ($title_tags->length > 0) {
            $title = trim($title_tags->item(0)->textContent);
        }

        // Remove unwanted elements
        $remove_tags = ['script', 'style', 'noscript', 'svg', 'iframe'];
        foreach ($remove_tags as $tag) {
            $elements = $dom->getElementsByTagName($tag);
            while ($elements->length > 0) {
                $elements->item(0)->parentNode->removeChild($elements->item(0));
            }
        }

        // Try to find main content area
        $xpath = new \DOMXPath($dom);
        $text = '';

        // Try article first
        $articles = $xpath->query('//article');
        if ($articles->length > 0) {
            $text = $articles->item(0)->textContent;
        }

        // Try main
        if (empty($text)) {
            $mains = $xpath->query('//main');
            if ($mains->length > 0) {
                $text = $mains->item(0)->textContent;
            }
        }

        // Fall back to body
        if (empty($text)) {
            $bodies = $dom->getElementsByTagName('body');
            if ($bodies->length > 0) {
                $text = $bodies->item(0)->textContent;
            }
        }

        // Clean text
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return [
            'text' => $text,
            'title' => $title,
        ];
    }

    /**
     * Chunk text into overlapping segments
     */
    private static function chunk_text($text) {
        $chunks = [];
        $length = mb_strlen($text);
        $start = 0;

        while ($start < $length) {
            $chunk_size = rand(self::CHUNK_MIN_SIZE, self::CHUNK_MAX_SIZE);
            $chunk = mb_substr($text, $start, $chunk_size);

            if (!empty(trim($chunk))) {
                $chunks[] = trim($chunk);
            }

            $start += $chunk_size - self::CHUNK_OVERLAP;
        }

        return $chunks;
    }

    /**
     * Estimate token count (simple heuristic)
     */
    private static function estimate_tokens($text) {
        // Simple estimation: ~4 characters per token
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * Generate embeddings for all chunks of a URL
     */
    private static function generate_embeddings_for_url($url) {
        $chunks = Database::get_chunks_by_url($url);

        foreach ($chunks as $chunk) {
            $embedding = OpenAI_Client::generate_embedding($chunk->content);

            if (!is_wp_error($embedding)) {
                Database::update_embedding($chunk->id, json_encode($embedding));
            }
        }
    }

    /**
     * Reindex all configured links
     */
    public static function reindex_all() {
        $links = get_option('ai_chatbot_links', []);
        $results = [];

        foreach ($links as $url) {
            $result = self::index_url($url);
            $results[] = [
                'url' => $url,
                'result' => $result,
            ];
        }

        update_option('ai_chatbot_last_reindex', current_time('mysql'));

        return $results;
    }

    /**
     * Validate URL
     */
    public static function validate_url($url) {
        $url = self::normalize_url($url);

        if (!$url) {
            return false;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        return $url;
    }

    /**
     * Deduplicate URLs
     */
    public static function deduplicate_urls($urls) {
        $normalized = array_map([self::class, 'normalize_url'], $urls);
        $unique = array_unique(array_filter($normalized));

        return array_values($unique);
    }
}
