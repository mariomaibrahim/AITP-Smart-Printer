<?php
require_once __DIR__ . '/../config/database.php';

use Smalot\PdfParser\Parser;

// Check if Composer autoload exists
$autoload_paths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php'
];

$autoload_found = false;
foreach ($autoload_paths as $autoload_path) {
    if (file_exists($autoload_path)) {
        require_once $autoload_path;
        $autoload_found = true;
        break;
    }
}

if (!$autoload_found) {
    error_log("Composer autoload not found. Please run 'composer install'");
}

// Only use PDF parser if available
$pdf_parser_available = false;
if ($autoload_found && class_exists('Smalot\PdfParser\Parser')) {
    $pdf_parser_available = true;
}

class PrintJob {
    private $conn;
    private $table_jobs = "print_jobs";
    private $table_files = "print_files";
    private $pdf_parser_available;

    public $job_id;
    public $color_mode;
    public $sides;
    public $orientation;
    public $page_range_type;
    public $custom_range;
    public $copies;
    public $printer;
    public $total_pages;
    public $status;

    public function __construct($db) {
        $this->conn = $db;
        global $pdf_parser_available;
        $this->pdf_parser_available = $pdf_parser_available;
    }

    public function create() {
        try {
            $query = "INSERT INTO " . $this->table_jobs . "
                    SET job_id=:job_id, color_mode=:color_mode, sides=:sides,
                        orientation=:orientation, page_range_type=:page_range_type,
                        custom_range=:custom_range, copies=:copies, printer=:printer,
                        total_pages=:total_pages, status=:status";

            $stmt = $this->conn->prepare($query);

            // Sanitize
            $this->job_id = htmlspecialchars(strip_tags($this->job_id));
            $this->color_mode = htmlspecialchars(strip_tags($this->color_mode));
            $this->sides = htmlspecialchars(strip_tags($this->sides));
            $this->orientation = htmlspecialchars(strip_tags($this->orientation));
            $this->page_range_type = htmlspecialchars(strip_tags($this->page_range_type));
            $this->custom_range = htmlspecialchars(strip_tags($this->custom_range));
            $this->copies = (int)$this->copies;
            $this->printer = htmlspecialchars(strip_tags($this->printer));
            $this->total_pages = (int)$this->total_pages;
            $this->status = htmlspecialchars(strip_tags($this->status));

            // Bind values
            $stmt->bindParam(":job_id", $this->job_id);
            $stmt->bindParam(":color_mode", $this->color_mode);
            $stmt->bindParam(":sides", $this->sides);
            $stmt->bindParam(":orientation", $this->orientation);
            $stmt->bindParam(":page_range_type", $this->page_range_type);
            $stmt->bindParam(":custom_range", $this->custom_range);
            $stmt->bindParam(":copies", $this->copies);
            $stmt->bindParam(":printer", $this->printer);
            $stmt->bindParam(":total_pages", $this->total_pages);
            $stmt->bindParam(":status", $this->status);

            return $stmt->execute();
            
        } catch (PDOException $e) {
            error_log("Database error in PrintJob::create(): " . $e->getMessage());
            return false;
        }
    }

    public function addFile($original_filename, $stored_filename, $file_path, $file_size, $file_type) {
        try {
            $page_count = 0;
            
            // Count pages based on file type
            if ($file_type === 'application/pdf') {
                $page_count = $this->countPdfPages($file_path);
            } elseif (in_array($file_type, ['image/jpeg', 'image/png', 'image/jpg'])) {
                $page_count = 1; // Images are single page
            } elseif ($file_type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                $page_count = $this->estimateDocxPages($file_path);
            }

            $query = "INSERT INTO " . $this->table_files . "
                    SET job_id=:job_id, original_filename=:original_filename,
                        stored_filename=:stored_filename, file_path=:file_path,
                        file_size=:file_size, file_type=:file_type, page_count=:page_count";

            $stmt = $this->conn->prepare($query);

            $stmt->bindParam(":job_id", $this->job_id);
            $stmt->bindParam(":original_filename", $original_filename);
            $stmt->bindParam(":stored_filename", $stored_filename);
            $stmt->bindParam(":file_path", $file_path);
            $stmt->bindParam(":file_size", $file_size);
            $stmt->bindParam(":file_type", $file_type);
            $stmt->bindParam(":page_count", $page_count);

            if($stmt->execute()) {
                $this->updateTotalPages();
                return $page_count;
            }
            return false;
            
        } catch (PDOException $e) {
            error_log("Database error in PrintJob::addFile(): " . $e->getMessage());
            return false;
        }
    }

    private function countPdfPages($file_path) {
        if (!$this->pdf_parser_available) {
            // Fallback: estimate based on file size
            error_log("PDF parser not available, using size estimation");
            return max(1, round(filesize($file_path) / 51200));
        }
        
        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($file_path);
            $pages = $pdf->getPages();
            return count($pages);
        } catch (Exception $e) {
            error_log("Error counting PDF pages: " . $e->getMessage());
            // Fallback to size estimation
            return max(1, round(filesize($file_path) / 51200));
        }
    }

    private function estimateDocxPages($file_path) {
        try {
            $file_size = filesize($file_path);
            $estimated_pages = max(1, round($file_size / 51200)); // 50KB per page estimate
            return $estimated_pages;
        } catch (Exception $e) {
            error_log("Error estimating DOCX pages: " . $e->getMessage());
            return 1;
        }
    }

    private function updateTotalPages() {
        try {
            $query = "UPDATE " . $this->table_jobs . " 
                    SET total_pages = (
                        SELECT SUM(page_count) FROM " . $this->table_files . " 
                        WHERE job_id = :job_id
                    ) 
                    WHERE job_id = :job_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":job_id", $this->job_id);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Database error in PrintJob::updateTotalPages(): " . $e->getMessage());
            return false;
        }
    }

    public function getJobById($job_id) {
        try {
            $query = "SELECT * FROM " . $this->table_jobs . " WHERE job_id = :job_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":job_id", $job_id);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Database error in PrintJob::getJobById(): " . $e->getMessage());
            return false;
        }
    }

    public function getJobFiles($job_id) {
        try {
            $query = "SELECT * FROM " . $this->table_files . " WHERE job_id = :job_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":job_id", $job_id);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Database error in PrintJob::getJobFiles(): " . $e->getMessage());
            return false;
        }
    }
}