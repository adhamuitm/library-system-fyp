<?php
session_start();
require_once 'auth_helper.php';
requireRole('librarian');

if (isset($_GET['file'])) {
    $filename = basename($_GET['file']);
    $filepath = 'exports/' . $filename;
    
    if (file_exists($filepath)) {
        // Determine content type
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $contentTypes = [
            'pdf' => 'application/pdf',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => 'text/csv'
        ];
        
        header('Content-Type: ' . ($contentTypes[$ext] ?? 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }
}

header('Location: report_management.php');
exit;