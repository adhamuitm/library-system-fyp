<?php
// AUTO-TRIGGER FOR FINE CALCULATION
// This runs ONCE per day automatically

$last_run_file = __DIR__ . '/last_fine_calc.txt';
$today = date('Y-m-d');

if (!file_exists($last_run_file) || file_get_contents($last_run_file) !== $today) {
    require_once __DIR__ . '/dbconnect.php';
    
    try {
        // Call the stored procedure
        $conn->query("CALL CalculateOverdueFines()");
        
        // Mark as run today
        file_put_contents($last_run_file, $today);
        
        error_log("Auto fine calculation completed: " . date('Y-m-d H:i:s'));
    } catch (Exception $e) {
        error_log("Auto fine calc error: " . $e->getMessage());
    }
}
?>