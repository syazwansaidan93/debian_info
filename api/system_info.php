<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allow CORS for your frontend

// Function to safely execute shell commands
function safe_exec($command) {
    return shell_exec($command . ' 2>&1'); // Redirect stderr to stdout
}

// Function to read content from a sysfs file
function read_sysfs_file($path) {
    if (file_exists($path) && is_readable($path)) {
        return trim(file_get_contents($path));
    }
    return null;
}

// Function to get raw CPU stats from /proc/stat
function get_cpu_stats() {
    $current_stats_raw = read_sysfs_file('/proc/stat');
    if (!$current_stats_raw) {
        return null;
    }

    $lines = explode("\n", $current_stats_raw);
    $cpu_line = null;
    foreach ($lines as $line) {
        if (str_starts_with($line, 'cpu ')) {
            $cpu_line = $line;
            break;
        }
    }

    if (!$cpu_line) {
        return null;
    }

    // Parse the cpu line: user nice system idle iowait irq softirq steal guest guest_nice
    $parts = preg_split('/\s+/', $cpu_line);
    if (count($parts) < 11) {
        return null;
    }

    $user = intval($parts[1]);
    $nice = intval($parts[2]);
    $system = intval($parts[3]);
    $idle = intval($parts[4]);
    $iowait = intval($parts[5]);
    $irq = intval($parts[6]);
    $softirq = intval($parts[7]);
    $steal = intval($parts[8]);

    $total_cpu_time = $user + $nice + $system + $idle + $iowait + $irq + $softirq + $steal;
    $idle_time = $idle + $iowait;

    return [
        'total_cpu_time' => $total_cpu_time,
        'idle_time' => $idle_time,
        'timestamp' => microtime(true)
    ];
}

// Function to get raw network stats from /proc/net/dev
function get_network_stats() {
    $net_dev_output = safe_exec('cat /proc/net/dev');
    if (!$net_dev_output) {
        return ['bytes_sent' => 0, 'bytes_recv' => 0, 'timestamp' => microtime(true)];
    }

    $current_bytes_recv = 0;
    $current_bytes_sent = 0;

    $lines = explode("\n", $net_dev_output);
    foreach ($lines as $line) {
        if (strpos($line, ':') !== false) {
            $parts = preg_split('/\s+/', trim($line));
            if (isset($parts[1]) && isset($parts[9])) {
                // Exclude loopback interface 'lo' from speed calculation
                if (!str_starts_with(trim($parts[0]), 'lo:')) {
                    $current_bytes_recv += intval($parts[1]);
                    $current_bytes_sent += intval($parts[9]);
                }
            }
        }
    }
    return [
        'bytes_sent' => $current_bytes_sent,
        'bytes_recv' => $current_bytes_recv,
        'timestamp' => microtime(true)
    ];
}

$data = [];

// --- CPU Usage (Raw) ---
$data['cpu_stats'] = get_cpu_stats();
if ($data['cpu_stats'] === null) {
    $data['cpu_stats'] = ['total_cpu_time' => 0, 'idle_time' => 0, 'timestamp' => microtime(true)];
}

// --- Uptime (Raw) ---
$uptime_output = safe_exec('cat /proc/uptime');
if (preg_match('/^(\d+\.\d+)/', $uptime_output, $matches)) {
    $data['uptime_seconds'] = floatval($matches[1]);
} else {
    $data['uptime_seconds'] = 0;
}

// --- RAM Information (Raw Bytes) ---
$free_output = safe_exec('free -b'); // Use -b for bytes
if (preg_match('/Mem:\s+(\d+)\s+(\d+)/', $free_output, $matches)) {
    $data['ram_total_bytes'] = intval($matches[1]);
    $data['ram_used_bytes'] = intval($matches[2]);
} else {
    $data['ram_total_bytes'] = 0;
    $data['ram_used_bytes'] = 0;
}

// --- CPU Temperature (Raw) ---
$cpu_temp_celsius = null; // Initialize as null

// Try common Armbian /sys paths first (temp in millidegrees Celsius)
$temp_paths = [
    '/sys/class/thermal/thermal_zone0/temp',
    '/sys/class/thermal/thermal_zone1/temp',
    '/sys/class/hwmon/hwmon0/temp1_input', // Often symlinked by armbianmonitor
    '/etc/armbianmonitor/datasources/soctemp' // Armbian specific symlink
];

foreach ($temp_paths as $path) {
    $raw_temp = read_sysfs_file($path);
    if ($raw_temp !== null && is_numeric($raw_temp)) {
        $cpu_temp_celsius = floatval($raw_temp) / 1000.0; // Convert from millidegrees to Celsius
        break; // Found a temperature, stop searching
    }
}

// Fallback to sensors command if sysfs paths didn't yield a result
if ($cpu_temp_celsius === null) {
    $sensors_output = safe_exec('sensors');
    if (preg_match('/Package id 0:\s+\+([0-9.]+)/', $sensors_output, $matches) ||
        preg_match('/Core 0:\s+\+([0-9.]+)/', $sensors_output, $matches) ||
        preg_match('/CPU:\s+\+([0-9.]+)/', $sensors_output, $matches) ||
        preg_match('/temp1:\s+\+([0-9.]+)/', $sensors_output, $matches)) {
        $cpu_temp_celsius = floatval($matches[1]);
    }
}

$data['cpu_temp_celsius'] = $cpu_temp_celsius; // Return raw float value, null if not found

// --- Main Disk Usage (Raw) ---
$df_output = safe_exec('df -B1 /'); // Get bytes for total/used for root filesystem
if (preg_match('/\s+(\d+)\s+(\d+)\s+\d+\s+(\d+)%\s+\/$/', $df_output, $matches)) {
    $data['main_disk_total_bytes'] = intval($matches[1]);
    $data['main_disk_used_bytes'] = intval($matches[2]);
} else {
    $data['main_disk_total_bytes'] = 0;
    $data['main_disk_used_bytes'] = 0;
}

// --- USB Disk Usage (Raw) ---
// IMPORTANT: Replace '/mnt/usb' with the actual mount point of your USB drive.
// You can find mount points using the `mount` command or `df -h`.
$usb_df_output = safe_exec('df -B1 /mnt/usb'); // Example: assuming USB is mounted at /mnt/usb
if (preg_match('/\s+(\d+)\s+(\d+)\s+\d+\s+(\d+)%\s+\/mnt\/usb$/', $usb_df_output, $matches)) {
    $data['usb_disk_total_bytes'] = intval($matches[1]);
    $data['usb_disk_used_bytes'] = intval($matches[2]);
} else {
    // If USB disk is not found or mounted at a different path, return 0 for total/used
    $data['usb_disk_total_bytes'] = 0;
    $data['usb_disk_used_bytes'] = 0;
}


// --- Network Stats (Raw) ---
$data['net_stats'] = get_network_stats();

echo json_encode($data);
?>
