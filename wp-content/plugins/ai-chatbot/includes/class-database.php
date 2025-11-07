<?php
/**
 * Database management class
 *
 * @package AIChatbot
 */

namespace AIChatbot;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database class
 */
class Database {

    const TABLE_NAME = 'ai_chatbot_chunks';

    /**
     * Get table name with prefix
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;

        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            url varchar(2048) NOT NULL,
            title varchar(500) DEFAULT '',
            chunk_index int(11) NOT NULL DEFAULT 0,
            content text NOT NULL,
            token_estimate int(11) NOT NULL DEFAULT 0,
            checksum char(64) NOT NULL,
            embedding longtext DEFAULT NULL,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_url (url(191)),
            KEY idx_updated_at (updated_at),
            KEY idx_checksum (checksum),
            KEY idx_chunk_index (chunk_index)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Insert chunk
     */
    public static function insert_chunk($data) {
        global $wpdb;

        $defaults = [
            'url' => '',
            'title' => '',
            'chunk_index' => 0,
            'content' => '',
            'token_estimate' => 0,
            'checksum' => '',
            'embedding' => null,
            'updated_at' => current_time('mysql'),
        ];

        $data = wp_parse_args($data, $defaults);

        return $wpdb->insert(
            self::get_table_name(),
            $data,
            ['%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s']
        );
    }

    /**
     * Delete chunks by URL
     */
    public static function delete_chunks_by_url($url) {
        global $wpdb;

        return $wpdb->delete(
            self::get_table_name(),
            ['url' => $url],
            ['%s']
        );
    }

    /**
     * Get chunks by URL
     */
    public static function get_chunks_by_url($url) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_table_name() . " WHERE url = %s ORDER BY chunk_index ASC",
                $url
            )
        );
    }

    /**
     * Get checksum for URL
     */
    public static function get_url_checksum($url) {
        global $wpdb;

        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT checksum FROM " . self::get_table_name() . " WHERE url = %s LIMIT 1",
                $url
            )
        );
    }

    /**
     * Get all chunks
     */
    public static function get_all_chunks() {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT * FROM " . self::get_table_name() . " ORDER BY url, chunk_index ASC"
        );
    }

    /**
     * Get chunk count by URL
     */
    public static function get_chunk_count_by_url($url) {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::get_table_name() . " WHERE url = %s",
                $url
            )
        );
    }

    /**
     * Get all indexed URLs with metadata
     */
    public static function get_indexed_urls() {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT
                url,
                title,
                COUNT(*) as chunk_count,
                MAX(updated_at) as last_updated
            FROM " . self::get_table_name() . "
            GROUP BY url, title
            ORDER BY last_updated DESC"
        );
    }

    /**
     * Update embeddings for a chunk
     */
    public static function update_embedding($chunk_id, $embedding) {
        global $wpdb;

        return $wpdb->update(
            self::get_table_name(),
            ['embedding' => $embedding],
            ['id' => $chunk_id],
            ['%s'],
            ['%d']
        );
    }

    /**
     * Clear all data
     */
    public static function clear_all() {
        global $wpdb;
        return $wpdb->query("TRUNCATE TABLE " . self::get_table_name());
    }
}
