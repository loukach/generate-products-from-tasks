# Generate Products from Tasks Plugin - Claude Documentation

## Overview
This WordPress plugin syncs tasks from a MongoDB database (via API) to WooCommerce products. It's part of the Joy From Giving ecosystem.

## Project Structure
```
generate-products-from-tasks-clean/
├── generate-products-from-tasks.php  # Main plugin file
├── admin/
│   ├── css/admin.css                 # Admin styles
│   └── js/admin.js                   # Admin JavaScript
├── test-api.php                      # API testing script
├── README.md                         # User documentation
└── CLAUDE.md                         # This file - AI assistant documentation
```

## Key Information

### API Configuration
- The plugin uses `JOY_AI_BACKEND_URL` constant defined in WordPress config
- Main endpoint: `JOY_AI_BACKEND_URL . '/api/tasks/full'`
- Test endpoint in test-api.php: `https://joy-server-dev.onrender.com/api/tasks/full`

### Task to Product Mapping
- Task `_id` → Product meta `_task_id` (for updates)
- Task `title` → Product title
- Task `description` → Product description
- Task `organization` → Product meta `_product_brand`
- Task `categories` → WooCommerce product categories
- Task `tags` → Product tags
- Various task fields → Product attributes (location, region, skills, etc.)

### Recent Issues & Solutions

#### Issue 1: Categories Showing as Numbers
**Problem**: Categories were displaying as "671" instead of "Children/Young People"
**Cause**: The plugin was applying a mapping function that added emojis to category names
**Solution**: Removed the `map_category_name()` function call to use categories exactly as they come from the API

### Git Repository
- Remote: https://github.com/loukach/generate-products-from-tasks.git
- Main branch: `master`
- Clean structure without nested directories

### Common Tasks

#### Running a Sync
1. Go to WooCommerce → Tasks to Products in WordPress admin
2. Click "Sync Tasks Now" button
3. Check WordPress debug.log for detailed sync information

#### Debugging Category Issues
The plugin logs category processing:
```
[Generate Products] Setting categories for product X: Array(...)
[Generate Products] Processing category: CategoryName (type: string)
```

#### Testing API Connection
```bash
php test-api.php
```

### Important Notes
1. Only syncs tasks with `status: "active"` (case-insensitive)
2. Products are created with configurable status (draft/publish)
3. Default product image is set to a specific URL if no image exists
4. The plugin schedules hourly automatic syncs if enabled

### Brand/Organization Handling
Currently stores organization as post meta `_product_brand`. To properly integrate with WooCommerce brand plugins:
1. Identify which brand plugin is installed (e.g., `product_brand`, `pwb-brand`, `yith_product_brand`)
2. Create brand taxonomy terms from organizations
3. Assign products to brand terms instead of just meta

### Future Improvements
1. Implement proper WooCommerce brand taxonomy integration
2. Add category ID to name mapping if API returns numeric IDs
3. Handle organization deduplication for brands
4. Add more robust error handling and recovery

### Useful Commands
```bash
# Check error logs
tail -f /path/to/wordpress/wp-content/debug.log | grep "Generate Products"

# Test API endpoint
curl -s "https://joy-server-dev.onrender.com/api/tasks/full" | jq '.[0]'
```