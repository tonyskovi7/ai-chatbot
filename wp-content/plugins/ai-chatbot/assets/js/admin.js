/**
 * Admin JavaScript
 *
 * @package AIChatbot
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Add link button
        $('#ai-chatbot-add-link').on('click', function() {
            const container = $('#ai-chatbot-links-container');
            const currentLinks = container.find('.ai-chatbot-link-row').length;

            if (currentLinks >= 15) {
                alert('Maximum 15 links allowed');
                return;
            }

            const newRow = $('<div class="ai-chatbot-link-row" style="margin-bottom: 10px;"></div>');
            newRow.html(
                '<input type="url" name="links[]" class="regular-text" placeholder="https://example.com/page"> ' +
                '<button type="button" class="button ai-chatbot-remove-link">Remove</button>'
            );

            container.append(newRow);
        });

        // Remove link button
        $(document).on('click', '.ai-chatbot-remove-link', function() {
            $(this).closest('.ai-chatbot-link-row').remove();

            // Ensure at least one input remains
            const container = $('#ai-chatbot-links-container');
            if (container.find('.ai-chatbot-link-row').length === 0) {
                const newRow = $('<div class="ai-chatbot-link-row" style="margin-bottom: 10px;"></div>');
                newRow.html(
                    '<input type="url" name="links[]" class="regular-text" placeholder="https://example.com/page"> ' +
                    '<button type="button" class="button ai-chatbot-remove-link">Remove</button>'
                );
                container.append(newRow);
            }
        });

        // Reindex all button
        $('#ai-chatbot-reindex-all').on('click', function() {
            const button = $(this);
            const status = $('#ai-chatbot-reindex-status');

            button.prop('disabled', true);
            status.html('<span style="color: #0073aa;">' + aiChatbotAdmin.i18n.reindexing + '</span>');

            $.ajax({
                url: aiChatbotAdmin.restUrl + '/ingest',
                method: 'POST',
                headers: {
                    'X-WP-Nonce': wpApiSettings.nonce
                },
                data: JSON.stringify({
                    reindex: true
                }),
                contentType: 'application/json',
                success: function(response) {
                    status.html('<span style="color: #46b450;">' + aiChatbotAdmin.i18n.success + '</span>');
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                },
                error: function(xhr) {
                    let errorMsg = aiChatbotAdmin.i18n.error;
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg += ': ' + xhr.responseJSON.message;
                    }
                    status.html('<span style="color: #dc3232;">' + errorMsg + '</span>');
                },
                complete: function() {
                    button.prop('disabled', false);
                }
            });
        });

        // Reindex single URL button
        $('#ai-chatbot-reindex-single').on('click', function() {
            const button = $(this);
            const urlInput = $('#ai-chatbot-single-url');
            const url = urlInput.val().trim();
            const status = $('#ai-chatbot-single-status');

            if (!url) {
                status.html('<span style="color: #dc3232;">Please enter a URL</span>');
                return;
            }

            button.prop('disabled', true);
            status.html('<span style="color: #0073aa;">' + aiChatbotAdmin.i18n.reindexing + '</span>');

            $.ajax({
                url: aiChatbotAdmin.restUrl + '/ingest',
                method: 'POST',
                headers: {
                    'X-WP-Nonce': wpApiSettings.nonce
                },
                data: JSON.stringify({
                    url: url
                }),
                contentType: 'application/json',
                success: function(response) {
                    status.html('<span style="color: #46b450;">' + aiChatbotAdmin.i18n.success + '</span>');
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                },
                error: function(xhr) {
                    let errorMsg = aiChatbotAdmin.i18n.error;
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg += ': ' + xhr.responseJSON.message;
                    }
                    status.html('<span style="color: #dc3232;">' + errorMsg + '</span>');
                },
                complete: function() {
                    button.prop('disabled', false);
                }
            });
        });
    });

})(jQuery);
