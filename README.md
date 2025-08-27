# Generate WooCommerce Products from Tasks

A WordPress plugin that automatically creates WooCommerce products from tasks fetched from a remote API.

## Features

- **Automatic Sync**: Fetches tasks from API and creates WooCommerce products
- **Brand Integration**: Syncs organizations to WooCommerce brands with automatic logo download
- **Smart Mapping**: Maps task data to product attributes, categories, and tags
- **Update Handling**: Updates existing products when tasks change
- **Category Management**: Creates WooCommerce product categories from task categories
- **Attribute Support**: Maps task fields to product attributes (Location, Region, Task Time, etc.)
- **Admin Interface**: Easy-to-use admin panel for configuration and manual sync

## Requirements

- WordPress 5.0+
- WooCommerce 4.0+
- **Perfect WooCommerce Brands plugin** (for brand integration)
- PHP 7.4+
- cURL enabled
- Access to remote API endpoints

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
2. Click **"Sync Tasks Now"** (syncs both tasks and brands automatically)
3. Monitor the sync status and results including:
   - Products synced/updated
   - Brands created  
   - Brand images downloaded

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
| `organization` | **Product Brand** (PWB taxonomy) |
| `categories` | Product Categories |
| `tags` | Product Tags |
| `location` | Product Attribute |
| `region` | Product Attribute |
| `taskTime` | Product Attribute |
| `commitment` | Product Attribute |
| `openFor` | Product Attribute |
| `skills` | Product Attribute |
| `traits` | Product Attribute |

### Brand Integration

Organizations from MongoDB are automatically synced to Perfect WooCommerce Brands:

- **Organization Name** → Brand name  
- **Organization Description** → Brand description
- **Organization Logo URL** → Brand image (auto-downloaded)
- **MongoDB ID** → Stored for linking

### Category Processing

Categories from tasks are created exactly as received from the API, without modification.

## Troubleshooting

### Common Issues

1. **"WooCommerce not found" error**
   - Install and activate WooCommerce plugin first

2. **"Perfect WooCommerce Brands not found" error**  
   - Install and activate Perfect WooCommerce Brands plugin
   - Brand sync will be skipped if PWB is not available

3. **"API connection failed"**
   - Check your `JOY_AI_BACKEND_URL` constant
   - Verify API endpoint is accessible
   - Run `php test-api.php` to diagnose

4. **"No products created"**
   - Check WordPress debug log for errors
   - Verify tasks have `_id` and `title` fields
   - Ensure tasks have `status: "active"`

5. **"Brand images not downloading"**
   - Check internet connectivity
   - Verify organization `imageUrl` fields are valid
   - Images will be skipped but brands still created

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

### Version 2.7.0 (Current)
- **NEW**: Brand integration with Perfect WooCommerce Brands
- **NEW**: Automatic organization logo download from GitHub
- **NEW**: Enhanced admin UI with detailed sync statistics
- **IMPROVED**: Integrated brand sync with main task sync operation
- **IMPROVED**: Comprehensive error handling and logging

### Version 2.6.x
- Fixed category display issues (numeric categories)
- Added task limit selector for testing
- Enhanced debugging and error logging

### Version 1.0.0
- Initial release
- Basic task to product synchronization
- Admin interface for configuration
- Debug logging and error handling
- Support for product attributes, categories, and tags

## License

This plugin is provided as-is for internal use.