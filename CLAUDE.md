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

### Brand/Organization Handling ⚠️ NEEDS IMPROVEMENT
Currently stores organization as post meta `_product_brand`. **This is a major limitation that needs to be fixed.**

**Current Problem:**
- Organizations from tasks are only stored as simple post meta (`_product_brand`)
- No integration with WooCommerce brand systems or plugins
- Missing connection to rich organization data (with images) from Organizations Manager plugin
- No proper brand taxonomy, browsing, or brand pages

**PRIORITY FEATURE TO ADD:**
**Integrate Organizations from MongoDB as WooCommerce Brands**

1. **Fetch Organization Data**: Use Organizations Manager API (`/api/organizations`) to get full organization details including:
   - Organization name, location, contact info
   - **Image URLs** (now available from GitHub: `https://raw.githubusercontent.com/loukach/joyfromgiving-images/main/organizations/`)
   - Description and metadata

2. **Create WooCommerce Brand Integration**:
   - Detect installed brand plugin (e.g., `product_brand`, `pwb-brand`, `yith_product_brand`)
   - Create brand taxonomy terms from organization data
   - Set brand images using organization imageUrl from MongoDB
   - Assign products to proper brand terms instead of just meta

3. **Implementation Steps**:
   ```php
   // Example implementation needed:
   function sync_organizations_as_brands() {
       $organizations = fetch_organizations_from_api();
       foreach ($organizations as $org) {
           $brand_term = wp_insert_term($org['organizationName'], 'product_brand');
           // Set brand image from GitHub repository
           if ($org['imageUrl']) {
               update_term_meta($brand_term['term_id'], 'thumbnail_id', attach_brand_image($org['imageUrl']));
           }
       }
   }
   ```

4. **Benefits of This Integration**:
   - Products properly linked to organization brands with images
   - Brand browsing and filtering in WooCommerce
   - Professional brand pages with organization details
   - Consistent brand experience across the platform
   - Leverage existing Organizations Manager data and images

### Future Improvements
1. **🚨 PRIORITY**: Implement proper WooCommerce brand taxonomy integration (see Brand/Organization Handling above)
2. Add category ID to name mapping if API returns numeric IDs  
3. Handle organization deduplication for brands
4. Add more robust error handling and recovery

### Related Components
- **Organizations Manager Plugin**: Already manages organization CRUD with images
- **MongoDB Organizations**: Contains rich organization data with imageUrl field
- **GitHub Images Repository**: `https://github.com/loukach/joyfromgiving-images` contains all organization logos
- **Organizations → Tasks → Products**: Complete data flow that needs proper brand integration

### Useful Commands
```bash
# Check error logs
tail -f /path/to/wordpress/wp-content/debug.log | grep "Generate Products"

# Test API endpoint
curl -s "https://joy-server-dev.onrender.com/api/tasks/full" | jq '.[0]'
```