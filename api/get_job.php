<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once '../classes/PrintJob.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

try {
    if (!isset($_GET['job_id'])) {
        throw new Exception('Job ID is required');
    }
    
    $job_id = $_GET['job_id'];
    
    // Database connection
    $database = new Database();
    $db = $database->getConnection();
    
    $printJob = new PrintJob($db);
    
    // Get job details
    $job_data = $printJob->getJobById($job_id);
    if (!$job_data) {
        throw new Exception('Job not found');
    }
    
    // Get job files
    $files = $printJob->getJobFiles($job_id);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'job' => $job_data,
            'files' => $files
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
