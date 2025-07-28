<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allow CORS for your frontend

// Function to safely execute shell commands
function safe_exec($command) {
    return shell_exec($command . ' 2>&1');
}

$processes = [];
$output = safe_exec('ps aux --sort=-%cpu | head -n 11'); // Get header + 10 processes
$lines = explode("\n", $output);

// Skip header line
if (count($lines) > 1) {
    array_shift($lines);
}

foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line)) continue;

    // Split by multiple spaces to handle variable spacing
    $parts = preg_split('/\s+/', $line, 11); // Limit to 11 parts to get command as one string

    if (count($parts) >= 11) {
        $user = $parts[0];
        $pid = $parts[1];
        $cpu_percent = floatval($parts[2]);
        $memory_percent = floatval($parts[3]);
        $command = $parts[10]; // The rest is the command

        $processes[] = [
            'pid' => $pid,
            'name' => basename($command), // Get just the program name
            'cpu_percent' => sprintf("%.1f", $cpu_percent) . '%',
            'memory_percent' => sprintf("%.1f", $memory_percent) . '%',
            'cmdline' => $command
        ];
    }
}

echo json_encode($processes);
?>
