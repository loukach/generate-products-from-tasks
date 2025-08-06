<?php
/**
 * Test script to verify API connectivity and data structure
 * Run this script from command line: php test-api.php
 */

// Configuration - Update this with your actual API URL
$api_url = 'https://joy-server-dev.onrender.com/api/tasks/full';

echo "Testing API connection to: $api_url\n";
echo str_repeat('-', 50) . "\n";

// Test 1: Check API connectivity
$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    echo "❌ CURL Error: $curl_error\n";
    exit(1);
}

echo "✅ API Response Code: $http_code\n";

if ($http_code !== 200) {
    echo "❌ Expected HTTP 200, got $http_code\n";
    exit(1);
}

// Test 2: Parse JSON response
$tasks = json_decode($response, true);
$json_error = json_last_error();

if ($json_error !== JSON_ERROR_NONE) {
    echo "❌ JSON Parse Error: " . json_last_error_msg() . "\n";
    exit(1);
}

echo "✅ JSON parsed successfully\n";
echo "✅ Total tasks found: " . count($tasks) . "\n\n";

// Test 3: Analyze first task structure
if (empty($tasks)) {
    echo "⚠️  No tasks found in response\n";
    exit(0);
}

$first_task = $tasks[0];
echo "First Task Structure:\n";
echo str_repeat('-', 30) . "\n";

// Expected fields for WooCommerce product mapping
$expected_fields = [
    '_id' => 'Task ID',
    'title' => 'Product Title',
    'description' => 'Product Description',
    'organization' => 'Brand/Organization',
    'location' => 'Location Attribute',
    'region' => 'Region Attribute',
    'taskTime' => 'Task Time Attribute',
    'commitment' => 'Commitment Attribute',
    'openFor' => 'Open For Attribute',
    'skills' => 'Skills Attribute',
    'traits' => 'Traits Attribute',
    'categories' => 'Product Categories',
    'tags' => 'Product Tags',
    'status' => 'Task Status'
];

foreach ($expected_fields as $field => $description) {
    if (isset($first_task[$field])) {
        $value = $first_task[$field];
        $type = gettype($value);
        
        if (is_array($value)) {
            $sample = array_slice($value, 0, 3);
            $value_display = json_encode($sample);
            if (count($value) > 3) {
                $value_display .= " ... (" . count($value) . " items total)";
            }
        } else {
            $value_display = is_string($value) ? 
                (strlen($value) > 100 ? substr($value, 0, 100) . '...' : $value) : 
                json_encode($value);
        }
        
        echo "✅ $field ($description): [$type] $value_display\n";
    } else {
        echo "❌ $field ($description): NOT FOUND\n";
    }
}

// Test 4: Check for any additional fields
echo "\nAdditional fields found:\n";
echo str_repeat('-', 30) . "\n";

$additional_fields = array_diff_key($first_task, $expected_fields);
foreach ($additional_fields as $field => $value) {
    $type = gettype($value);
    echo "ℹ️  $field: [$type]\n";
}

// Test 5: Sample data for verification
echo "\nSample Tasks (first 3):\n";
echo str_repeat('-', 30) . "\n";

for ($i = 0; $i < min(3, count($tasks)); $i++) {
    $task = $tasks[$i];
    echo "\nTask " . ($i + 1) . ":\n";
    echo "  ID: " . ($task['_id'] ?? 'N/A') . "\n";
    echo "  Title: " . ($task['title'] ?? 'N/A') . "\n";
    echo "  Organization: " . ($task['organization'] ?? 'N/A') . "\n";
    echo "  Categories: " . (isset($task['categories']) ? implode(', ', $task['categories']) : 'None') . "\n";
    echo "  Status: " . ($task['status'] ?? 'N/A') . "\n";
}

// Test 6: Task Status Analysis
echo "\n\nTask Status Analysis:\n";
echo str_repeat('-', 30) . "\n";

$status_counts = array();
$case_insensitive_active = 0;

foreach ($tasks as $task) {
    $status = isset($task['status']) ? $task['status'] : 'not_set';
    if (!isset($status_counts[$status])) {
        $status_counts[$status] = 0;
    }
    $status_counts[$status]++;
    
    // Count case-insensitive active
    if (isset($task['status']) && strtolower($task['status']) === 'active') {
        $case_insensitive_active++;
    }
}

foreach ($status_counts as $status => $count) {
    echo "Status '$status': $count tasks\n";
}

echo "\n✅ Active tasks that will be synced (case-insensitive): $case_insensitive_active\n";
echo "⏭️  Other tasks that will be skipped: " . (count($tasks) - $case_insensitive_active) . "\n";

// Test 7: Warnings and recommendations
echo "\n\nWarnings & Recommendations:\n";
echo str_repeat('-', 30) . "\n";

$warnings = [];

// Check for missing critical fields
if (!isset($first_task['_id'])) {
    $warnings[] = "Tasks don't have '_id' field - products won't be updatable";
}

if (!isset($first_task['title'])) {
    $warnings[] = "Tasks don't have 'title' field - products will have no name";
}

// Check for array fields
if (isset($first_task['openFor']) && !is_array($first_task['openFor'])) {
    $warnings[] = "'openFor' is not an array - code expects array";
}

if (isset($first_task['categories']) && !is_array($first_task['categories'])) {
    $warnings[] = "'categories' is not an array - code expects array";
}

if (isset($first_task['tags'])) {
    if (is_array($first_task['tags']) && !empty($first_task['tags'])) {
        $first_tag = $first_task['tags'][0];
        if (is_array($first_tag) && isset($first_tag['name'])) {
            echo "ℹ️  Tags are objects with 'name' property\n";
        } elseif (is_string($first_tag)) {
            echo "ℹ️  Tags are simple strings\n";
        }
    }
}

if (empty($warnings)) {
    echo "✅ No critical issues found!\n";
} else {
    foreach ($warnings as $warning) {
        echo "⚠️  $warning\n";
    }
}

echo "\n✅ API test completed successfully!\n";