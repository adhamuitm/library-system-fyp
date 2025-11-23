<?php
/**
 * AUTO-TRIGGER HELPER FOR FINE CALCULATION
 * FIX #5: Include this file in your main pages to automatically calculate fines
 * 
 * USAGE: Add this line to the top of circulation_control.php or librarian_dashboard.php:
 * require_once 'auto_calculate_fine_trigger.php';
 */

// Only run once per day
$last_run_file = __DIR__ . '/last_fine_calculation.txt';
$current_date = date('Y-m-d');

// Check if already run today
$last_run_date = file_exists($last_run_file) ? file_get_contents($last_run_file) : '';

if ($last_run_date !== $current_date) {
    // Run the fine calculation
    try {
        require_once __DIR__ . '/dbconnect.php';
        
        // Include the calculation function
        $calc_functions = include __DIR__ . '/auto_calculate_fine.php';
        
        if (function_exists('calculateAndInsertFines')) {
            // Run the calculation silently in the background
            $results = calculateAndInsertFines($conn);
            
            // Save the last run date
            file_put_contents($last_run_file, $current_date);
            
            // Optional: Log the results
            $log_file = __DIR__ . '/fine_calculation_log.txt';
            $log_entry = date('Y-m-d H:i:s') . " - " . $results['message'] . "\n";
            file_put_contents($log_file, $log_entry, FILE_APPEND);
        }
    } catch (Exception $e) {
        // Log error but don't break the page
        error_log("Auto fine calculation error: " . $e->getMessage());
    }
}
?>
