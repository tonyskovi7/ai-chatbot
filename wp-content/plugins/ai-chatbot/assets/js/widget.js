/**
 * Frontend Chat Widget
 *
 * @package AIChatbot
 */

(function() {
    'use strict';

    // Chat widget state
    let isOpen = false;
    let previousFocus = null;

    // DOM elements
    let container;
    let button;
    let chatWindow;
    let messagesContainer;
    let inputField;
    let sendButton;

    /**
     * Initialize the widget
     */
    function init() {
        createWidget();
        attachEventListeners();
    }

    /**
     * Create widget HTML
     */
    function createWidget() {
        container = document.getElementById('ai-chatbot-container');

        if (!container) {
            return;
        }

        // Create floating button
        button = document.createElement('button');
        button.id = 'ai-chatbot-button';
        button.className = 'ai-chatbot-button';
        button.setAttribute('aria-label', aiChatbotConfig.title);
        button.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>';

        // Create chat window
        chatWindow = document.createElement('div');
        chatWindow.id = 'ai-chatbot-window';
        chatWindow.className = 'ai-chatbot-window ai-chatbot-hidden';
        chatWindow.setAttribute('role', 'dialog');
        chatWindow.setAttribute('aria-modal', 'true');
        chatWindow.setAttribute('aria-labelledby', 'ai-chatbot-title');

        chatWindow.innerHTML = `
            <div class="ai-chatbot-header">
                <h2 id="ai-chatbot-title">${escapeHtml(aiChatbotConfig.title)}</h2>
                <button id="ai-chatbot-close" class="ai-chatbot-close" aria-label="${escapeHtml(aiChatbotConfig.i18n.closeButton)}">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="4" y1="4" x2="16" y2="16"></line>
                        <line x1="16" y1="4" x2="4" y2="16"></line>
                    </svg>
                </button>
            </div>
            <div class="ai-chatbot-messages" id="ai-chatbot-messages">
                <div class="ai-chatbot-message ai-chatbot-message-bot">
                    <div class="ai-chatbot-message-content">
                        ${escapeHtml(aiChatbotConfig.i18n.greeting || 'Hello! How can I help you today?')}
                    </div>
                </div>
            </div>
            <div class="ai-chatbot-input-container">
                <input
                    type="text"
                    id="ai-chatbot-input"
                    class="ai-chatbot-input"
                    placeholder="${escapeHtml(aiChatbotConfig.placeholder)}"
                    maxlength="1000"
                    aria-label="${escapeHtml(aiChatbotConfig.placeholder)}"
                >
                <button id="ai-chatbot-send" class="ai-chatbot-send" aria-label="${escapeHtml(aiChatbotConfig.i18n.sendButton)}">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="2" y1="10" x2="18" y2="10"></line>
                        <polyline points="12 4 18 10 12 16"></polyline>
                    </svg>
                </button>
            </div>
        `;

        container.appendChild(button);
        container.appendChild(chatWindow);

        // Get references to elements
        messagesContainer = document.getElementById('ai-chatbot-messages');
        inputField = document.getElementById('ai-chatbot-input');
        sendButton = document.getElementById('ai-chatbot-send');
    }

    /**
     * Attach event listeners
     */
    function attachEventListeners() {
        // Toggle button
        button.addEventListener('click', toggleChat);

        // Close button
        document.getElementById('ai-chatbot-close').addEventListener('click', closeChat);

        // Send button
        sendButton.addEventListener('click', sendMessage);

        // Input field - Enter key
        inputField.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                sendMessage();
            }
        });

        // ESC key to close
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && isOpen) {
                closeChat();
            }
        });

        // Click outside to close
        chatWindow.addEventListener('click', function(e) {
            if (e.target === chatWindow) {
                closeChat();
            }
        });
    }

    /**
     * Toggle chat window
     */
    function toggleChat() {
        if (isOpen) {
            closeChat();
        } else {
            openChat();
        }
    }

    /**
     * Open chat window
     */
    function openChat() {
        isOpen = true;
        previousFocus = document.activeElement;

        chatWindow.classList.remove('ai-chatbot-hidden');
        button.setAttribute('aria-expanded', 'true');

        // Focus input
        setTimeout(() => {
            inputField.focus();
        }, 100);
    }

    /**
     * Close chat window
     */
    function closeChat() {
        isOpen = false;

        chatWindow.classList.add('ai-chatbot-hidden');
        button.setAttribute('aria-expanded', 'false');

        // Return focus to button
        if (previousFocus && previousFocus !== document.body) {
            previousFocus.focus();
        } else {
            button.focus();
        }
    }

    /**
     * Send message
     */
    function sendMessage() {
        const message = inputField.value.trim();

        if (!message) {
            return;
        }

        // Add user message to UI
        addMessage(message, 'user');

        // Clear input
        inputField.value = '';

        // Disable input while processing
        inputField.disabled = true;
        sendButton.disabled = true;

        // Show thinking indicator
        const thinkingId = addThinkingMessage();

        // Send to API
        fetch(aiChatbotConfig.restUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': aiChatbotConfig.nonce
            },
            body: JSON.stringify({
                message: message
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            // Remove thinking indicator
            removeMessage(thinkingId);

            // Add bot response
            addMessage(data.answer, 'bot', data.sources);
        })
        .catch(error => {
            console.error('Chat error:', error);

            // Remove thinking indicator
            removeMessage(thinkingId);

            // Show error message
            addMessage(aiChatbotConfig.i18n.error, 'bot');
        })
        .finally(() => {
            // Re-enable input
            inputField.disabled = false;
            sendButton.disabled = false;
            inputField.focus();
        });
    }

    /**
     * Add message to chat
     */
    function addMessage(content, type, sources = []) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `ai-chatbot-message ai-chatbot-message-${type}`;

        const contentDiv = document.createElement('div');
        contentDiv.className = 'ai-chatbot-message-content';
        contentDiv.textContent = content;

        messageDiv.appendChild(contentDiv);

        // Add sources if available
        if (sources && sources.length > 0) {
            const sourcesDiv = document.createElement('div');
            sourcesDiv.className = 'ai-chatbot-sources';
            sourcesDiv.innerHTML = '<strong>Sources:</strong>';

            const sourcesList = document.createElement('ul');
            sources.forEach(source => {
                const li = document.createElement('li');
                const a = document.createElement('a');
                a.href = source.url;
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                a.textContent = source.title || source.url;
                li.appendChild(a);
                sourcesList.appendChild(li);
            });

            sourcesDiv.appendChild(sourcesList);
            messageDiv.appendChild(sourcesDiv);
        }

        messagesContainer.appendChild(messageDiv);

        // Scroll to bottom
        messagesContainer.scrollTop = messagesContainer.scrollHeight;

        return messageDiv;
    }

    /**
     * Add thinking indicator
     */
    function addThinkingMessage() {
        const messageDiv = document.createElement('div');
        messageDiv.className = 'ai-chatbot-message ai-chatbot-message-bot ai-chatbot-thinking';
        messageDiv.id = 'ai-chatbot-thinking-' + Date.now();

        const contentDiv = document.createElement('div');
        contentDiv.className = 'ai-chatbot-message-content';
        contentDiv.innerHTML = '<span class="ai-chatbot-dots"><span>.</span><span>.</span><span>.</span></span>';

        messageDiv.appendChild(contentDiv);
        messagesContainer.appendChild(messageDiv);

        // Scroll to bottom
        messagesContainer.scrollTop = messagesContainer.scrollHeight;

        return messageDiv.id;
    }

    /**
     * Remove message by ID
     */
    function removeMessage(id) {
        const message = document.getElementById(id);
        if (message) {
            message.remove();
        }
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }


    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
