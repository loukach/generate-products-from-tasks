# Generate WooCommerce Products from Tasks

A WordPress plugin that automatically creates WooCommerce products from tasks fetched from a remote API.

## Features

- **Automatic Sync**: Fetches tasks from API and creates WooCommerce products
- **Smart Mapping**: Maps task data to product attributes, categories, and tags
- **Update Handling**: Updates existing products when tasks change
- **Category Management**: Creates WooCommerce product categories from task categories
- **Attribute Support**: Maps task fields to product attributes (Location, Region, Task Time, etc.)
- **Admin Interface**: Easy-to-use admin panel for configuration and manual sync

## Requirements

- WordPress 5.0+
- WooCommerce 4.0+
- PHP 7.4+
- cURL enabled
- Access to remote API endpoint

## Installation

1. **Upload the plugin files** to your WordPress `/wp-content/plugins/generate-products-from-tasks` directory

2. **Define API URL** in your `wp-config.php` file:
   ```php
   define('JOY_AI_BACKEND_URL', 'https://your-api-server.com');
   ```

3. **Activate the plugin** through the 'Plugins' menu in WordPress

4. **Verify WooCommerce** is installed and active

## Testing Before Use

### 1. Test API Connection

Run the test script to verify API connectivity and data structure:

```bash
cd /path/to/your/plugin
php test-api.php
```

This will check:
- ✅ API connectivity
- ✅ JSON response parsing
- ✅ Required field presence
- ✅ Data structure validation

### 2. Expected API Response Format

The plugin expects tasks in this format:

```json
[
  {
    "_id": "unique-task-id",
    "title": "Task Title",
    "description": "Task description",
    "organization": "Organization Name",
    "location": "Location",
    "region": "North|Central|South|Gozo",
    "taskTime": "1 to 2 hours a week",
    "commitment": "At least 3 months",
    "openFor": ["Individuals", "Small Groups"],
    "skills": "Required skills",
    "traits": "Required traits",
    "categories": ["Category1", "Category2"],
    "tags": ["tag1", "tag2"],
    "status": "active"
  }
]
```

### 3. WordPress Debug Logging

Enable WordPress debug logging to monitor the sync process:

```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Logs will appear in `/wp-content/debug.log` with prefix `[Generate Products]`.

## Configuration

Navigate to **WooCommerce → Tasks to Products** in your WordPress admin.

### Settings Options

- **Product Status**: Choose whether synced products are published, draft, or pending
- **Default Price**: Set default price for products (0 for free)
- **Product Visibility**: Control where products appear (shop, search, hidden)
- **Auto Sync**: Enable/disable automatic hourly synchronization

## Usage

### Manual Sync

1. Go to **WooCommerce → Tasks to Products**
2. Click **"Sync Tasks Now"**
3. Monitor the sync status and results

### Automatic Sync

- Runs hourly when enabled
- Processes all active tasks from the API
- Creates new products or updates existing ones
- Logs all activities for debugging

## Data Mapping

| Task Field | Product Mapping |
|------------|-----------------|
| `title` | Product Name |
| `description` | Product Description |
| `organization` | Product Brand |
| `categories` | Product Categories (with emoji mapping) |
| `tags` | Product Tags |
| `location` | Product Attribute |
| `region` | Product Attribute |
| `taskTime` | Product Attribute |
| `commitment` | Product Attribute |
| `openFor` | Product Attribute |
| `skills` | Product Attribute |
| `traits` | Product Attribute |

### Category Mapping

Categories are mapped with emojis for better visual organization:

- `Sports & Recreation` → `⚽ Sports & Recreation`
- `Environmental` → `🌱 Environmental`
- `Educational` → `🎓 Educational`
- `Arts & Culture` → `🎨 Arts & Culture`
- And more...

## Troubleshooting

### Common Issues

1. **"WooCommerce not found" error**
   - Install and activate WooCommerce plugin first

2. **"API connection failed"**
   - Check your `JOY_AI_BACKEND_URL` constant
   - Verify API endpoint is accessible
   - Run `php test-api.php` to diagnose

3. **"No products created"**
   - Check WordPress debug log for errors
   - Verify tasks have `_id` and `title` fields
   - Ensure tasks have `status: "active"`

4. **"Categories not appearing"**
   - Check that task categories are arrays
   - Verify WooCommerce product categories are enabled

### Debug Information

Enable detailed logging by adding this to your `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Look for log entries with prefix `[Generate Products]` in `/wp-content/debug.log`.

### Manual Testing

1. **Test API connectivity**:
   ```bash
   php test-api.php
   ```

2. **Check WordPress logs**:
   ```bash
   tail -f /wp-content/debug.log | grep "Generate Products"
   ```

3. **Verify WooCommerce products**:
   - Go to **Products** in WordPress admin
   - Check for products with task-related attributes
   - Verify categories and tags are created

## Security

- All task data is sanitized before saving
- API requests use WordPress HTTP API
- Admin actions require proper permissions
- CSRF protection on all AJAX requests

## Support

For issues or questions:

1. Check the WordPress debug log first
2. Run the test script to verify API connectivity
3. Review the troubleshooting section above
4. Contact the plugin developer with specific error messages

## Changelog

### Version 1.0.0
- Initial release
- Basic task to product synchronization
- Admin interface for configuration
- Debug logging and error handling
- Support for product attributes, categories, and tags

## License

This plugin is provided as-is for internal use.