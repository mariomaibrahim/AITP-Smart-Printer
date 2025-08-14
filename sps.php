<?php

/*
CREATE DATABASE IF NOT EXISTS smart_printer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smart_printer;

CREATE TABLE IF NOT EXISTS print_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id VARCHAR(100) UNIQUE NOT NULL,
    color_mode ENUM('black-white', 'color') DEFAULT 'black-white',
    sides ENUM('single', 'duplex') DEFAULT 'single',
    orientation ENUM('portrait', 'landscape') DEFAULT 'portrait',
    page_range_type ENUM('all', 'custom') DEFAULT 'all',
    custom_range VARCHAR(255) NULL,
    copies INT DEFAULT 1 CHECK (copies >= 1 AND copies <= 99),
    printer VARCHAR(50) DEFAULT 'printer-1',
    total_pages INT DEFAULT 0,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_job_id (job_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS print_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id VARCHAR(100) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT NOT NULL,
    file_type VARCHAR(50) NOT NULL,
    page_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES print_jobs(job_id) ON DELETE CASCADE,
    INDEX idx_job_id (job_id)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
*/


?>