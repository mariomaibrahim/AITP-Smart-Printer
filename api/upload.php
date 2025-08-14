<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); 
ini_set('log_errors', 1);
ini_set('error_log', '../logs/php_errors.log');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

try {

    $required_files = [
        '../config/database.php',
        '../classes/PrintJob.php'
    ];
    
    foreach ($required_files as $file) {
        if (!file_exists($file)) {
            throw new Exception("Required file not found: " . basename($file));
        }
    }
    
    require_once '../config/database.php';
    require_once '../classes/PrintJob.php';
    
    // Database connection with error handling
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('Database connection failed');
    }
    
    $printJob = new PrintJob($db);
    
    // Generate unique job ID
    $job_id = 'JOB_' . date('Ymd_His') . '_' . rand(1000, 9999);
    
    // Get and validate print settings
    $color_mode = $_POST['colorMode'] ?? 'black-white';
    $sides = $_POST['sides'] ?? 'single';
    $orientation = $_POST['orientation'] ?? 'portrait';
    $page_range_type = $_POST['pageRange'] ?? 'all';
    $custom_range = $_POST['customRange'] ?? '';
    $copies = (int)($_POST['copies'] ?? 1);
    $printer = $_POST['printer'] ?? 'printer-1';
    
    // Validation arrays
    $valid_settings = [
        'colorMode' => ['black-white', 'color'],
        'sides' => ['single', 'duplex'],
        'orientation' => ['portrait', 'landscape'],
        'pageRange' => ['all', 'custom'],
        'printer' => ['printer-1', 'printer-2', 'printer-3']
    ];
    
    // Validate settings
    if (!in_array($color_mode, $valid_settings['colorMode']) ||
        !in_array($sides, $valid_settings['sides']) ||
        !in_array($orientation, $valid_settings['orientation']) ||
        !in_array($page_range_type, $valid_settings['pageRange']) ||
        !in_array($printer, $valid_settings['printer']) ||
        $copies < 1 || $copies > 99) {
        throw new Exception('Invalid print settings provided');
    }
    
    // Check files
    if (!isset($_FILES['files']) || empty($_FILES['files']['name'][0])) {
        throw new Exception('No files uploaded');
    }
    
    // Create uploads directory
    $upload_base_dir = '../uploads/';
    $upload_dir = $upload_base_dir . date('Y/m/d') . '/';
    
    if (!is_dir($upload_base_dir)) {
        if (!mkdir($upload_base_dir, 0755, true)) {
            throw new Exception('Cannot create uploads directory');
        }
    }
    
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            throw new Exception('Cannot create date-specific upload directory');
        }
    }
    
    // Set print job properties
    $printJob->job_id = $job_id;
    $printJob->color_mode = $color_mode;
    $printJob->sides = $sides;
    $printJob->orientation = $orientation;
    $printJob->page_range_type = $page_range_type;
    $printJob->custom_range = $custom_range;
    $printJob->copies = $copies;
    $printJob->printer = $printer;
    $printJob->total_pages = 0;
    $printJob->status = 'pending';
    
    // Create print job
    if (!$printJob->create()) {
        throw new Exception('Failed to create print job in database');
    }
    
    $uploaded_files = [];
    $total_pages = 0;
    
    // Process files
    $files = $_FILES['files'];
    $file_count = is_array($files['name']) ? count($files['name']) : 1;
    
    if ($file_count > 3) {
        throw new Exception('Maximum 3 files allowed');
    }
    
    // Handle single or multiple files
    for ($i = 0; $i < $file_count; $i++) {
        $original_filename = is_array($files['name']) ? $files['name'][$i] : $files['name'];
        $file_tmp = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $file_size = is_array($files['size']) ? $files['size'][$i] : $files['size'];
        $file_type = is_array($files['type']) ? $files['type'][$i] : $files['type'];
        $file_error = is_array($files['error']) ? $files['error'][$i] : $files['error'];
        
        if ($file_error !== UPLOAD_ERR_OK) {
            throw new Exception('Upload error for file: ' . $original_filename . ' (Error code: ' . $file_error . ')');
        }
        
        // Validate file type
        $allowed_types = [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'image/jpeg',
            'image/jpg',
            'image/png'
        ];
        
        if (!in_array($file_type, $allowed_types)) {
            throw new Exception('Unsupported file type: ' . $original_filename . ' (' . $file_type . ')');
        }
        
        // Validate file size (10MB max)
        if ($file_size > 10 * 1024 * 1024) {
            throw new Exception('File too large: ' . $original_filename . ' (Max 10MB)');
        }
        
        // Generate safe filename
        $file_extension = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
        $safe_filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($original_filename, PATHINFO_FILENAME));
        $stored_filename = $job_id . '_' . ($i + 1) . '_' . $safe_filename . '.' . $file_extension;
        $file_path = $upload_dir . $stored_filename;
        
        // Move file
        if (!move_uploaded_file($file_tmp, $file_path)) {
            throw new Exception('Failed to save file: ' . $original_filename);
        }
        
        // Add to database and get page count
        $page_count = $printJob->addFile($original_filename, $stored_filename, $file_path, $file_size, $file_type);
        
        if ($page_count === false) {
            throw new Exception('Failed to save file info to database: ' . $original_filename);
        }
        
        $uploaded_files[] = [
            'original_filename' => $original_filename,
            'stored_filename' => $stored_filename,
            'page_count' => $page_count,
            'file_size' => $file_size,
            'file_type' => $file_type
        ];
        
        $total_pages += $page_count;
    }
    
    // Success response
    $response = [
        'success' => true,
        'job_id' => $job_id,
        'message' => 'Print job created successfully',
        'data' => [
            'job_id' => $job_id,
            'files' => $uploaded_files,
            'total_pages' => $total_pages,
            'settings' => [
                'color_mode' => $color_mode,
                'sides' => $sides,
                'orientation' => $orientation,
                'page_range' => $page_range_type,
                'custom_range' => $custom_range,
                'copies' => $copies,
                'printer' => $printer
            ],
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s')
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(400);
    $error_response = [
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($error_response, JSON_UNESCAPED_UNICODE);
    
    // Log the error
    error_log("Print job error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
}
?>
