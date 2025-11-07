<?php
/**
 * Admin settings page
 *
 * @package AIChatbot
 */

namespace AIChatbot;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Handle form submissions
if (isset($_POST['ai_chatbot_save_settings']) && check_admin_referer('ai_chatbot_settings')) {
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';

    if ($tab === 'general') {
        // Save general settings
        $api_key_readonly = defined('AI_CHATBOT_OPENAI_API_KEY') && AI_CHATBOT_OPENAI_API_KEY;

        if (!$api_key_readonly && isset($_POST['api_key'])) {
            update_option('ai_chatbot_api_key', sanitize_text_field($_POST['api_key']));
        }

        if (isset($_POST['model'])) {
            $model = sanitize_text_field($_POST['model']);
            if (in_array($model, ['gpt-4o', 'gpt-3.5'], true)) {
                update_option('ai_chatbot_model', $model);
            }
        }

        if (isset($_POST['extra_instructions'])) {
            update_option('ai_chatbot_extra_instructions', wp_kses_post($_POST['extra_instructions']));
        }

        if (isset($_POST['fallback_message'])) {
            update_option('ai_chatbot_fallback_message', wp_kses_post($_POST['fallback_message']));
        }

        if (isset($_POST['widget_title'])) {
            update_option('ai_chatbot_widget_title', sanitize_text_field($_POST['widget_title']));
        }

        if (isset($_POST['widget_placeholder'])) {
            update_option('ai_chatbot_widget_placeholder', sanitize_text_field($_POST['widget_placeholder']));
        }

        update_option('ai_chatbot_use_embeddings', isset($_POST['use_embeddings']));
        update_option('ai_chatbot_auto_reindex_on_add', isset($_POST['auto_reindex_on_add']));

        echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved successfully.', 'ai-chatbot') . '</p></div>';
    } elseif ($tab === 'links') {
        // Save links
        $new_links = [];

        if (isset($_POST['links']) && is_array($_POST['links'])) {
            foreach ($_POST['links'] as $link) {
                $validated = Ingestion::validate_url($link);
                if ($validated) {
                    $new_links[] = $validated;
                }
            }
        }

        // Deduplicate
        $new_links = Ingestion::deduplicate_urls($new_links);

        // Limit to 15
        $new_links = array_slice($new_links, 0, 15);

        $old_links = get_option('ai_chatbot_links', []);
        update_option('ai_chatbot_links', $new_links);

        // Auto-reindex newly added links
        if (get_option('ai_chatbot_auto_reindex_on_add', true)) {
            $added_links = array_diff($new_links, $old_links);
            if (!empty($added_links)) {
                foreach ($added_links as $url) {
                    Ingestion::index_url($url);
                }
                echo '<div class="notice notice-success"><p>' . esc_html__('Links saved and newly added links have been indexed.', 'ai-chatbot') . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>' . esc_html__('Links saved successfully.', 'ai-chatbot') . '</p></div>';
            }
        } else {
            echo '<div class="notice notice-success"><p>' . esc_html__('Links saved successfully.', 'ai-chatbot') . '</p></div>';
        }
    }
}

// Get current tab
$current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';

// Get options
$api_key = get_option('ai_chatbot_api_key', '');
$api_key_readonly = defined('AI_CHATBOT_OPENAI_API_KEY') && AI_CHATBOT_OPENAI_API_KEY;
$model = get_option('ai_chatbot_model', 'gpt-4o');
$temperature = get_option('ai_chatbot_temperature', 0.2);
$extra_instructions = get_option('ai_chatbot_extra_instructions', '');
$fallback_message = get_option('ai_chatbot_fallback_message', __('I don\'t have enough information to answer that question.', 'ai-chatbot'));
$links = get_option('ai_chatbot_links', []);
$use_embeddings = get_option('ai_chatbot_use_embeddings', false);
$auto_reindex = get_option('ai_chatbot_auto_reindex_on_add', true);
$widget_title = get_option('ai_chatbot_widget_title', __('Chat with us', 'ai-chatbot'));
$widget_placeholder = get_option('ai_chatbot_widget_placeholder', __('Ask a question...', 'ai-chatbot'));
$last_reindex = get_option('ai_chatbot_last_reindex', '');
$indexed_urls = Database::get_indexed_urls();

?>

<div class="wrap">
    <h1><?php echo esc_html__('AI Chatbot Settings', 'ai-chatbot'); ?></h1>

    <nav class="nav-tab-wrapper">
        <a href="?page=ai-chatbot&tab=general" class="nav-tab <?php echo $current_tab === 'general' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e('General', 'ai-chatbot'); ?>
        </a>
        <a href="?page=ai-chatbot&tab=links" class="nav-tab <?php echo $current_tab === 'links' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e('Links', 'ai-chatbot'); ?>
        </a>
        <a href="?page=ai-chatbot&tab=indexing" class="nav-tab <?php echo $current_tab === 'indexing' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e('Indexing', 'ai-chatbot'); ?>
        </a>
    </nav>

    <form method="post" action="">
        <?php wp_nonce_field('ai_chatbot_settings'); ?>

        <?php if ($current_tab === 'general') : ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="api_key"><?php esc_html_e('OpenAI API Key', 'ai-chatbot'); ?></label>
                    </th>
                    <td>
                        <?php if ($api_key_readonly) : ?>
                            <input type="password" class="regular-text" value="••••••••••••••••" disabled>
                            <p class="description">
                                <?php esc_html_e('API key is set via AI_CHATBOT_OPENAI_API_KEY constant in wp-config.php', 'ai-chatbot'); ?>
                            </p>
                        <?php else : ?>
                            <input type="password" id="api_key" name="api_key" class="regular-text" value="<?php echo esc_attr($api_key); ?>">
                            <p class="description">
                                <?php esc_html_e('Your OpenAI API key. You can also set this via the AI_CHATBOT_OPENAI_API_KEY constant in wp-config.php', 'ai-chatbot'); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="model"><?php esc_html_e('Model', 'ai-chatbot'); ?></label>
                    </th>
                    <td>
                        <select id="model" name="model">
                            <option value="gpt-4o" <?php selected($model, 'gpt-4o'); ?>>GPT-4o</option>
                            <option value="gpt-3.5" <?php selected($model, 'gpt-3.5'); ?>>GPT-3.5 Turbo</option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="temperature"><?php esc_html_e('Temperature', 'ai-chatbot'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="temperature" class="small-text" value="<?php echo esc_attr($temperature); ?>" disabled>
                        <p class="description"><?php esc_html_e('Fixed at 0.2 for conservative, accurate responses', 'ai-chatbot'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="extra_instructions"><?php esc_html_e('Extra Instructions', 'ai-chatbot'); ?></label>
                    </th>
                    <td>
                        <textarea id="extra_instructions" name="extra_instructions" rows="5" class="large-text"><?php echo esc_textarea($extra_instructions); ?></textarea>
                        <p class="description"><?php esc_html_e('Additional instructions to append to the system prompt', 'ai-chatbot'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="fallback_message"><?php esc_html_e('Fallback Message', 'ai-chatbot'); ?></label>
                    </th>
                    <td>
                        <textarea id="fallback_message" name="fallback_message" rows="3" class="large-text"><?php echo esc_textarea($fallback_message); ?></textarea>
                        <p class="description"><?php esc_html_e('Message shown when context is insufficient to answer', 'ai-chatbot'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="widget_title"><?php esc_html_e('Widget Title', 'ai-chatbot'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="widget_title" name="widget_title" class="regular-text" value="<?php echo esc_attr($widget_title); ?>">
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="widget_placeholder"><?php esc_html_e('Input Placeholder', 'ai-chatbot'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="widget_placeholder" name="widget_placeholder" class="regular-text" value="<?php echo esc_attr($widget_placeholder); ?>">
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Options', 'ai-chatbot'); ?></th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="checkbox" name="use_embeddings" value="1" <?php checked($use_embeddings); ?>>
                                <?php esc_html_e('Use embeddings (beta) - Improves accuracy but increases API usage', 'ai-chatbot'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox" name="auto_reindex_on_add" value="1" <?php checked($auto_reindex); ?>>
                                <?php esc_html_e('Auto-reindex when new links are added', 'ai-chatbot'); ?>
                            </label>
                        </fieldset>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" name="ai_chatbot_save_settings" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'ai-chatbot'); ?>">
            </p>

        <?php elseif ($current_tab === 'links') : ?>
            <p><?php esc_html_e('Configure up to 15 URLs to use as context for the chatbot. Links are validated and deduplicated automatically.', 'ai-chatbot'); ?></p>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('URLs', 'ai-chatbot'); ?></label>
                    </th>
                    <td>
                        <div id="ai-chatbot-links-container">
                            <?php
                            $link_count = max(count($links), 1);
                            for ($i = 0; $i < $link_count; $i++) :
                                $link = $links[$i] ?? '';
                            ?>
                                <div class="ai-chatbot-link-row" style="margin-bottom: 10px;">
                                    <input type="url" name="links[]" class="regular-text" value="<?php echo esc_url($link); ?>" placeholder="https://example.com/page">
                                    <button type="button" class="button ai-chatbot-remove-link"><?php esc_html_e('Remove', 'ai-chatbot'); ?></button>
                                </div>
                            <?php endfor; ?>
                        </div>
                        <button type="button" id="ai-chatbot-add-link" class="button"><?php esc_html_e('Add Link', 'ai-chatbot'); ?></button>
                        <p class="description"><?php printf(esc_html__('Maximum %d links allowed', 'ai-chatbot'), 15); ?></p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" name="ai_chatbot_save_settings" class="button button-primary" value="<?php esc_attr_e('Save Links', 'ai-chatbot'); ?>">
            </p>

        <?php elseif ($current_tab === 'indexing') : ?>
            <h2><?php esc_html_e('Indexing Status', 'ai-chatbot'); ?></h2>

            <?php if ($last_reindex) : ?>
                <p>
                    <?php
                    printf(
                        esc_html__('Last reindex: %s', 'ai-chatbot'),
                        '<strong>' . esc_html(get_date_from_gmt($last_reindex, get_option('date_format') . ' ' . get_option('time_format'))) . '</strong>'
                    );
                    ?>
                </p>
            <?php endif; ?>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('URL', 'ai-chatbot'); ?></th>
                        <th><?php esc_html_e('Title', 'ai-chatbot'); ?></th>
                        <th><?php esc_html_e('Chunks', 'ai-chatbot'); ?></th>
                        <th><?php esc_html_e('Last Updated', 'ai-chatbot'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($indexed_urls)) : ?>
                        <tr>
                            <td colspan="4"><?php esc_html_e('No indexed content yet. Click "Reindex All" to start.', 'ai-chatbot'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($indexed_urls as $item) : ?>
                            <tr>
                                <td><a href="<?php echo esc_url($item->url); ?>" target="_blank"><?php echo esc_html($item->url); ?></a></td>
                                <td><?php echo esc_html($item->title); ?></td>
                                <td><?php echo esc_html($item->chunk_count); ?></td>
                                <td><?php echo esc_html(get_date_from_gmt($item->last_updated, get_option('date_format') . ' ' . get_option('time_format'))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <h3><?php esc_html_e('Manual Reindexing', 'ai-chatbot'); ?></h3>

            <p>
                <button type="button" id="ai-chatbot-reindex-all" class="button button-primary">
                    <?php esc_html_e('Reindex All Links', 'ai-chatbot'); ?>
                </button>
                <span id="ai-chatbot-reindex-status"></span>
            </p>

            <h4><?php esc_html_e('Reindex Single URL', 'ai-chatbot'); ?></h4>
            <p>
                <input type="url" id="ai-chatbot-single-url" class="regular-text" placeholder="https://example.com/page">
                <button type="button" id="ai-chatbot-reindex-single" class="button">
                    <?php esc_html_e('Reindex This URL', 'ai-chatbot'); ?>
                </button>
                <span id="ai-chatbot-single-status"></span>
            </p>

        <?php endif; ?>
    </form>
</div>
