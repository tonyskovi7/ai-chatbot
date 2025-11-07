# AI Chatbot WordPress Plugin

A lean, dependency-free, secure-by-default WordPress chatbot plugin powered by OpenAI. Uses curated links as context to provide accurate, source-based answers.

## Features

- **Zero Dependencies**: No Composer, no external JS/CSS libraries - uses WordPress core APIs and vanilla JavaScript only
- **Curated Knowledge Base**: Answers limited to content from admin-configured links
- **OpenAI Integration**: Uses GPT-4o or GPT-3.5 for intelligent responses
- **Smart Retrieval**: Keyword-based scoring with optional embeddings support
- **Security First**: Nonces, rate limiting, prepared statements, input sanitization
- **Accessibility**: WCAG compliant, keyboard navigation, screen reader support
- **Responsive Design**: Works on all devices, mobile-optimized
- **Auto-Reindexing**: Optional automatic indexing when new links are added
- **Dark Mode**: Respects `prefers-color-scheme` for automatic dark mode

## Requirements

- PHP 8.1 or higher
- WordPress 6.4 or higher
- MySQL 5.7+ / MariaDB 10.3+
- OpenAI API key

## Installation

1. Upload the `ai-chatbot` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to 'AI Chatbot' in the admin menu to configure

## Configuration

### General Settings

1. **OpenAI API Key**: Add your OpenAI API key
   - Can be set in admin UI or via `AI_CHATBOT_OPENAI_API_KEY` constant in `wp-config.php`
   - Example for wp-config.php: `define('AI_CHATBOT_OPENAI_API_KEY', 'sk-...');`

2. **Model Selection**: Choose between GPT-4o or GPT-3.5 Turbo

3. **Temperature**: Fixed at 0.2 for conservative, accurate responses

4. **Extra Instructions**: Optional additional instructions for the AI

5. **Fallback Message**: Message shown when context is insufficient

6. **Widget Settings**: Customize widget title and input placeholder

7. **Options**:
   - Enable embeddings (beta) for improved accuracy
   - Auto-reindex when new links are added

### Links Management

1. Navigate to the "Links" tab
2. Add up to 15 URLs to use as context
3. URLs are automatically validated, normalized, and deduplicated
4. Click "Save Links" to store your changes
5. If auto-reindex is enabled, new links are indexed automatically

### Indexing

1. Go to the "Indexing" tab
2. Click "Reindex All Links" to index all configured URLs
3. Or enter a single URL to reindex just that page
4. View indexing status and chunk counts for each URL

## How It Works

### Content Ingestion

1. Fetches HTML from configured URLs
2. Extracts readable text (prioritizes `<article>`, `<main>`, `<body>`)
3. Chunks content into 900-1200 character segments with overlap
4. Stores chunks in custom database table with checksums

### Answer Generation

1. User asks a question via the chat widget
2. Plugin retrieves most relevant chunks using keyword scoring
3. Chunks are sent to OpenAI as context
4. AI generates answer based only on provided context
5. Response includes cited sources

### Rate Limiting

- Public chat endpoint: 30 requests per 10 minutes per IP
- Prevents abuse and controls API costs

## Usage

### Frontend Widget

The chat widget appears automatically on all frontend pages as a floating button in the bottom-right corner.

**User Actions:**
- Click the button to open chat
- Type a question and press Enter or click Send
- Press ESC to close chat
- Chat history is saved in localStorage (last 10 exchanges)

**Keyboard Navigation:**
- Tab to navigate between elements
- Enter to send message
- ESC to close widget

### Admin API

The plugin provides REST API endpoints:

**Chat Endpoint** (Public)
```
POST /wp-json/ai-chatbot/v1/chat
{
  "message": "Your question here"
}
```

**Ingest Endpoint** (Admin Only)
```
POST /wp-json/ai-chatbot/v1/ingest
{
  "reindex": true
}
```

or

```
POST /wp-json/ai-chatbot/v1/ingest
{
  "url": "https://example.com/page"
}
```

## Security Features

- **Capability Checks**: All admin actions require `manage_options`
- **Nonces**: All POST actions protected with nonces
- **Prepared Statements**: All database queries use `$wpdb->prepare()`
- **Input Sanitization**: All user inputs sanitized and validated
- **Output Escaping**: All output properly escaped
- **Rate Limiting**: IP-based rate limiting on public endpoints
- **CORS**: Same-origin only, no credentials exposed
- **API Key Protection**: Never exposed client-side

## Auto-Reindexing Feature

When enabled (default), the plugin automatically indexes newly added links when you save changes in the Links tab. This ensures:

1. New content is immediately available to the chatbot
2. No manual reindexing step required
3. Only new links are processed (efficient)

You can disable this in General Settings if you prefer manual control.

## Embeddings (Beta)

Enable embeddings for improved retrieval accuracy:

1. Uses OpenAI's `text-embedding-3-small` model
2. Stores vector embeddings in database
3. Retrieves using cosine similarity
4. Increases API usage and costs

**Note:** When enabling embeddings, you must reindex all links to generate embeddings.

## Performance Considerations

- Ingestion is manual by default (no background cron)
- Efficient chunking and token limits reduce API usage
- Optional result caching for identical queries (60s)
- Lightweight keyword scoring minimizes processing

## Accessibility

- ARIA roles and labels
- Keyboard navigation support
- Focus management
- Screen reader compatible
- High contrast mode support
- Reduced motion support
- Visible focus indicators

## Browser Support

Latest 2 major versions of:
- Chrome
- Firefox
- Safari
- Edge

Graceful degradation for older browsers.

## Internationalization

All strings are wrapped in i18n functions with text domain `ai-chatbot`. Translation-ready.

## Privacy

- No PII collected
- No cookies set by plugin
- Chat history stored client-side only (localStorage)
- No third-party analytics or tracking

## Troubleshooting

### Chat widget doesn't appear
- Check if plugin is activated
- Clear browser cache
- Check browser console for JavaScript errors

### API errors
- Verify OpenAI API key is correct
- Check API key has sufficient credits
- Review error logs in browser console

### No relevant answers
- Ensure links are indexed (check Indexing tab)
- Verify URLs contain relevant content
- Try enabling embeddings for better retrieval

### Indexing fails
- Check URL is publicly accessible
- Verify content type is HTML
- Ensure server can make outbound HTTP requests

## Development

### File Structure
```
ai-chatbot/
├── ai-chatbot.php          # Main plugin file
├── includes/
│   ├── class-database.php  # Database management
│   ├── class-ingestion.php # Content fetching & chunking
│   ├── class-retrieval.php # Context retrieval
│   ├── class-openai-client.php # OpenAI API client
│   └── class-rest-api.php  # REST endpoints
├── admin/
│   └── admin-page.php      # Admin settings UI
├── assets/
│   ├── js/
│   │   ├── admin.js        # Admin JavaScript
│   │   └── widget.js       # Frontend widget
│   └── css/
│       ├── admin.css       # Admin styles
│       └── widget.css      # Widget styles
└── README.md
```

## License

GPL v2 or later

## Support

For issues and feature requests, please contact the plugin author.

## Changelog

### 1.0.0
- Initial release
- Core chatbot functionality
- Admin settings UI
- Keyword-based retrieval
- Optional embeddings support
- Auto-reindexing feature
- Rate limiting
- Accessibility features
