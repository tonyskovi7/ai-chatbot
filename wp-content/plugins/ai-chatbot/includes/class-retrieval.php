<?php
/**
 * Content retrieval class
 *
 * @package AIChatbot
 */

namespace AIChatbot;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Retrieval class
 */
class Retrieval {

    const DEFAULT_TOP_K = 6;
    const EMBEDDING_TOP_K = 8;
    const MAX_CONTEXT_TOKENS = 1800;
    const MIN_SCORE_THRESHOLD = 0.1;

    /**
     * Common stopwords (small list for performance)
     */
    private static $stopwords = [
        'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
        'of', 'with', 'by', 'from', 'as', 'is', 'was', 'are', 'were', 'been',
        'be', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
        'could', 'should', 'may', 'might', 'can', 'this', 'that', 'these',
        'those', 'i', 'you', 'he', 'she', 'it', 'we', 'they', 'what', 'which',
        'who', 'when', 'where', 'why', 'how',
    ];

    /**
     * Retrieve relevant chunks for a query
     */
    public static function retrieve($query) {
        $use_embeddings = get_option('ai_chatbot_use_embeddings', false);

        if ($use_embeddings) {
            return self::retrieve_with_embeddings($query);
        } else {
            return self::retrieve_with_keywords($query);
        }
    }

    /**
     * Retrieve using keyword-based scoring
     */
    private static function retrieve_with_keywords($query) {
        $chunks = Database::get_all_chunks();

        if (empty($chunks)) {
            return [];
        }

        // Tokenize query
        $query_tokens = self::tokenize($query);

        // Score each chunk
        $scored_chunks = [];
        foreach ($chunks as $chunk) {
            $chunk_tokens = self::tokenize($chunk->content);
            $score = self::cosine_similarity($query_tokens, $chunk_tokens);

            if ($score >= self::MIN_SCORE_THRESHOLD) {
                $scored_chunks[] = [
                    'chunk' => $chunk,
                    'score' => $score,
                ];
            }
        }

        // Sort by score descending
        usort($scored_chunks, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // Select top k chunks within token limit
        return self::select_top_chunks($scored_chunks, self::DEFAULT_TOP_K);
    }

    /**
     * Retrieve using embeddings
     */
    private static function retrieve_with_embeddings($query) {
        // Generate query embedding
        $query_embedding = OpenAI_Client::generate_embedding($query);

        if (is_wp_error($query_embedding)) {
            // Fall back to keyword search
            return self::retrieve_with_keywords($query);
        }

        $chunks = Database::get_all_chunks();

        if (empty($chunks)) {
            return [];
        }

        // Score each chunk using cosine similarity with embeddings
        $scored_chunks = [];
        foreach ($chunks as $chunk) {
            if (empty($chunk->embedding)) {
                continue;
            }

            $chunk_embedding = json_decode($chunk->embedding, true);
            if (!$chunk_embedding) {
                continue;
            }

            $score = self::cosine_similarity_vectors($query_embedding, $chunk_embedding);

            if ($score >= self::MIN_SCORE_THRESHOLD) {
                $scored_chunks[] = [
                    'chunk' => $chunk,
                    'score' => $score,
                ];
            }
        }

        // Sort by score descending
        usort($scored_chunks, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // Select top k chunks within token limit
        return self::select_top_chunks($scored_chunks, self::EMBEDDING_TOP_K);
    }

    /**
     * Tokenize text
     */
    private static function tokenize($text) {
        // Convert to lowercase
        $text = mb_strtolower($text);

        // Remove punctuation
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);

        // Split into words
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Remove stopwords
        $words = array_filter($words, function ($word) {
            return !in_array($word, self::$stopwords, true);
        });

        // Count word frequency
        $tokens = [];
        foreach ($words as $word) {
            if (!isset($tokens[$word])) {
                $tokens[$word] = 0;
            }
            $tokens[$word]++;
        }

        return $tokens;
    }

    /**
     * Calculate cosine similarity between token arrays
     */
    private static function cosine_similarity($tokens1, $tokens2) {
        if (empty($tokens1) || empty($tokens2)) {
            return 0.0;
        }

        // Calculate dot product
        $dot_product = 0.0;
        foreach ($tokens1 as $word => $count1) {
            if (isset($tokens2[$word])) {
                $dot_product += $count1 * $tokens2[$word];
            }
        }

        // Calculate magnitudes
        $magnitude1 = sqrt(array_sum(array_map(function ($c) {
            return $c * $c;
        }, $tokens1)));

        $magnitude2 = sqrt(array_sum(array_map(function ($c) {
            return $c * $c;
        }, $tokens2)));

        if ($magnitude1 == 0 || $magnitude2 == 0) {
            return 0.0;
        }

        return $dot_product / ($magnitude1 * $magnitude2);
    }

    /**
     * Calculate cosine similarity between embedding vectors
     */
    private static function cosine_similarity_vectors($vec1, $vec2) {
        if (count($vec1) !== count($vec2)) {
            return 0.0;
        }

        $dot_product = 0.0;
        $magnitude1 = 0.0;
        $magnitude2 = 0.0;

        for ($i = 0; $i < count($vec1); $i++) {
            $dot_product += $vec1[$i] * $vec2[$i];
            $magnitude1 += $vec1[$i] * $vec1[$i];
            $magnitude2 += $vec2[$i] * $vec2[$i];
        }

        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);

        if ($magnitude1 == 0 || $magnitude2 == 0) {
            return 0.0;
        }

        return $dot_product / ($magnitude1 * $magnitude2);
    }

    /**
     * Select top chunks within token limit
     */
    private static function select_top_chunks($scored_chunks, $top_k) {
        $selected = [];
        $total_tokens = 0;

        $count = 0;
        foreach ($scored_chunks as $item) {
            if ($count >= $top_k) {
                break;
            }

            $chunk = $item['chunk'];
            $estimated_tokens = $chunk->token_estimate;

            if ($total_tokens + $estimated_tokens > self::MAX_CONTEXT_TOKENS) {
                break;
            }

            $selected[] = [
                'chunk' => $chunk,
                'score' => $item['score'],
            ];

            $total_tokens += $estimated_tokens;
            $count++;
        }

        return $selected;
    }

    /**
     * Format chunks for context
     */
    public static function format_context($chunks) {
        if (empty($chunks)) {
            return '';
        }

        $context_parts = [];
        foreach ($chunks as $item) {
            $chunk = $item['chunk'];
            $context_parts[] = sprintf(
                "[Source: %s - %s]\n%s",
                esc_html($chunk->title),
                esc_url($chunk->url),
                $chunk->content
            );
        }

        return implode("\n\n---\n\n", $context_parts);
    }

    /**
     * Extract unique sources from chunks
     */
    public static function extract_sources($chunks) {
        $sources = [];
        $seen_urls = [];

        foreach ($chunks as $item) {
            $chunk = $item['chunk'];
            if (!in_array($chunk->url, $seen_urls, true)) {
                $sources[] = [
                    'url' => $chunk->url,
                    'title' => $chunk->title,
                ];
                $seen_urls[] = $chunk->url;
            }
        }

        return $sources;
    }
}
