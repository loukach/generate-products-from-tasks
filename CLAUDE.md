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
2. Click "Sync Tasks Now" button (creates/updates products)
3. Click "Sync Brands Now" button (creates brands from organizations)
4. Check WordPress debug.log for detailed sync information

#### Brand Integration Requirements
- Perfect WooCommerce Brands plugin must be installed and active
- Run brand sync after task sync to ensure products exist for brand assignment

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

### Brand/Organization Handling ✅ IMPLEMENTED
Organizations from MongoDB are now integrated with Perfect WooCommerce Brands (PWB) plugin.

**Current Implementation:**
- Organizations sync from `/api/organizations` to PWB brands (`pwb-brand` taxonomy)
- Products are assigned to brands based on their organization  
- **Brand images automatically downloaded** from GitHub repository during sync
- MongoDB organization IDs stored in term meta for reliable linking

**How It Works:**
1. **Brand Sync**: "Sync Tasks Now" automatically syncs organizations to PWB brands
2. **Product Assignment**: Products with organization meta get assigned to matching brands
3. **Image Download**: Organization logos automatically downloaded from GitHub
4. **No Duplicates**: Existing brands are not modified (preserves manual edits)

**Benefits Achieved:**
- Products properly linked to organization brands
- Brand browsing and filtering in WooCommerce
- Consistent brand experience across the platform
- Leverages existing MongoDB organizations data

### Future Improvements
1. Add category ID to name mapping if API returns numeric IDs  
2. Handle organization deduplication for brands
3. Add more robust error handling and recovery
4. Add rollback functionality for brand sync
5. Add image retry mechanism for failed downloads

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