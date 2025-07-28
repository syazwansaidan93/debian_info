<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allow CORS for your frontend

// Define paths for temporary files to store previous stats
define('CPU_STATS_FILE', '/tmp/last_cpu_stats.json');
define('NET_STATS_FILE', '/tmp/last_net_stats.json');
define('DISK_IO_STATS_FILE', '/tmp/last_disk_io_stats.json');

// --- Helper Functions ---

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

// Function to format bytes for human readability (e.g., 10.5 KB, 2.3 MB)
function format_bytes_human_readable($bytes) {
    if ($bytes === 0) return "0 Bytes";
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
    $i = floor(log($bytes, $k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

// Function to format speed for human readability (e.g., 10.5 KB/s, 2.3 MB/s)
function format_speed_human_readable($bytes_per_second) {
    if ($bytes_per_second === 0) return "0 B/s";
    $k = 1024;
    $sizes = ['B/s', 'KB/s', 'MB/s', 'GB/s', 'TB/s', 'PB/s', 'EB/s', 'ZB/s', 'YB/s'];
    $i = floor(log($bytes_per_second, $k));
    return round($bytes_per_second / pow($k, $i), 1) . ' ' . $sizes[$i];
}

// Function to format memory (bytes to GB)
function format_memory_gb($bytes) {
    return round($bytes / (1024 * 1024 * 1024), 1);
}

// Function to format uptime
function format_uptime($total_seconds) {
    if ($total_seconds === 0) return "N/A";
    $days = floor($total_seconds / (3600 * 24));
    $total_seconds %= (3600 * 24);
    $hours = floor($total_seconds / 3600);
    $total_seconds %= 3600;
    $minutes = floor($total_seconds / 60);

    $uptime_string = '';
    if ($days > 0) $uptime_string .= "{$days} days, ";
    $uptime_string .= "{$hours} hours, {$minutes} minutes";
    return $uptime_string;
}

// Function to load previous stats from a file
function load_previous_stats($file_path) {
    if (file_exists($file_path) && is_readable($file_path)) {
        $content = file_get_contents($file_path);
        return json_decode($content, true);
    }
    return null;
}

// Function to save current stats to a file
function save_current_stats($file_path, $stats) {
    file_put_contents($file_path, json_encode($stats));
}

// --- Main Data Collection and Processing ---

$data = [];
$current_time = microtime(true);

// --- CPU Usage Calculation ---
$current_cpu_stats_raw = read_sysfs_file('/proc/stat');
$current_cpu_times = null;
if ($current_cpu_stats_raw) {
    $lines = explode("\n", $current_cpu_stats_raw);
    foreach ($lines as $line) {
        if (str_starts_with($line, 'cpu ')) {
            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 11) {
                // user nice system idle iowait irq softirq steal guest guest_nice
                $current_cpu_times = [
                    'user' => intval($parts[1]),
                    'nice' => intval($parts[2]),
                    'system' => intval($parts[3]),
                    'idle' => intval($parts[4]),
                    'iowait' => intval($parts[5]),
                    'irq' => intval($parts[6]),
                    'softirq' => intval($parts[7]),
                    'steal' => intval($parts[8]),
                    'timestamp' => $current_time
                ];
                break;
            }
        }
    }
}

$last_cpu_stats = load_previous_stats(CPU_STATS_FILE);
$cpu_percent = '--%';
if ($last_cpu_stats && $current_cpu_times) {
    $delta_time = $current_cpu_times['timestamp'] - $last_cpu_stats['timestamp'];
    
    $last_total_cpu_time = $last_cpu_stats['user'] + $last_cpu_stats['nice'] + $last_cpu_stats['system'] + $last_cpu_stats['idle'] + $last_cpu_stats['iowait'] + $last_cpu_stats['irq'] + $last_cpu_stats['softirq'] + $last_cpu_stats['steal'];
    $current_total_cpu_time = $current_cpu_times['user'] + $current_cpu_times['nice'] + $current_cpu_times['system'] + $current_cpu_times['idle'] + $current_cpu_times['iowait'] + $current_cpu_times['irq'] + $current_cpu_times['softirq'] + $current_cpu_times['steal'];

    $last_idle_time = $last_cpu_stats['idle'] + $last_cpu_stats['iowait'];
    $current_idle_time = $current_cpu_times['idle'] + $current_cpu_times['iowait'];

    $delta_total_cpu_time = $current_total_cpu_time - $last_total_cpu_time;
    $delta_idle_time = $current_idle_time - $last_idle_time;

    if ($delta_time > 0 && $delta_total_cpu_time > 0) {
        $cpu_usage_raw = (($delta_total_cpu_time - $delta_idle_time) / $delta_total_cpu_time) * 100;
        $cpu_percent = sprintf("%.1f%%", $cpu_usage_raw);
    }
}
if ($current_cpu_times) {
    save_current_stats(CPU_STATS_FILE, $current_cpu_times);
}
$data['cpu_percent'] = $cpu_percent;


// --- Uptime ---
$uptime_output = safe_exec('cat /proc/uptime');
$uptime_seconds = 0;
if (preg_match('/^(\d+\.\d+)/', $uptime_output, $matches)) {
    $uptime_seconds = floatval($matches[1]);
}
$data['cpu_uptime'] = format_uptime($uptime_seconds);

// --- RAM Information ---
$free_output = safe_exec('free -b'); // Use -b for bytes
$ram_total_bytes = 0;
$ram_used_bytes = 0;
if (preg_match('/Mem:\s+(\d+)\s+(\d+)/', $free_output, $matches)) {
    $ram_total_bytes = intval($matches[1]);
    $ram_used_bytes = intval($matches[2]);
}
$ram_percent = ($ram_total_bytes > 0) ? sprintf("%.1f%%", ($ram_used_bytes / $ram_total_bytes) * 100) : '--%';
$data['ram_percent'] = $ram_percent;
$data['ram_total_gb'] = format_memory_gb($ram_total_bytes) . ' GB';
$data['ram_used_gb'] = format_memory_gb($ram_used_bytes) . ' GB';

// --- CPU Temperature ---
$cpu_temp_celsius = null;
$temp_paths = [
    '/sys/class/thermal/thermal_zone0/temp',
    '/sys/class/thermal/thermal_zone1/temp',
    '/sys/class/hwmon/hwmon0/temp1_input',
    '/etc/armbianmonitor/datasources/soctemp'
];
foreach ($temp_paths as $path) {
    $raw_temp = read_sysfs_file($path);
    if ($raw_temp !== null && is_numeric($raw_temp)) {
        $cpu_temp_celsius = floatval($raw_temp) / 1000.0; // Convert from millidegrees
        break;
    }
}
if ($cpu_temp_celsius === null) {
    $sensors_output = safe_exec('sensors');
    if (preg_match('/Package id 0:\s+\+([0-9.]+)/', $sensors_output, $matches) ||
        preg_match('/Core 0:\s+\+([0-9.]+)/', $sensors_output, $matches) ||
        preg_match('/CPU:\s+\+([0-9.]+)/', $sensors_output, $matches) ||
        preg_match('/temp1:\s+\+([0-9.]+)/', $sensors_output, $matches)) {
        $cpu_temp_celsius = floatval($matches[1]);
    }
}
$data['cpu_temp'] = ($cpu_temp_celsius !== null) ? sprintf("%.1f°C", $cpu_temp_celsius) : '--°C';

// --- Network Speed Calculation ---
$net_dev_output = safe_exec('cat /proc/net/dev');
$current_net_stats = ['bytes_sent' => 0, 'bytes_recv' => 0, 'timestamp' => $current_time];
if ($net_dev_output) {
    $lines = explode("\n", $net_dev_output);
    foreach ($lines as $line) {
        if (strpos($line, ':') !== false) {
            $parts = preg_split('/\s+/', trim($line));
            if (isset($parts[1]) && isset($parts[9])) {
                if (!str_starts_with(trim($parts[0]), 'lo:')) { // Exclude loopback
                    $current_net_stats['bytes_recv'] += intval($parts[1]);
                    $current_net_stats['bytes_sent'] += intval($parts[9]);
                }
            }
        }
    }
}

$last_net_stats = load_previous_stats(NET_STATS_FILE);
$upload_speed = 0;
$download_speed = 0;
if ($last_net_stats && $current_net_stats) {
    $delta_time = $current_net_stats['timestamp'] - $last_net_stats['timestamp'];
    if ($delta_time > 0) {
        $bytes_sent_diff = $current_net_stats['bytes_sent'] - $last_net_stats['bytes_sent'];
        $bytes_recv_diff = $current_net_stats['bytes_recv'] - $last_net_stats['bytes_recv'];
        $upload_speed = $bytes_sent_diff / $delta_time;
        $download_speed = $bytes_recv_diff / $delta_time;
    }
}
save_current_stats(NET_STATS_FILE, $current_net_stats);

$data['net_upload_speed'] = format_speed_human_readable($upload_speed);
$data['net_download_speed'] = format_speed_human_readable($download_speed);
$data['total_bytes_sent'] = format_bytes_human_readable($current_net_stats['bytes_sent']);
$data['total_bytes_recv'] = format_bytes_human_readable($current_net_stats['bytes_recv']);


// --- Main Disk Usage ---
$df_output_main = safe_exec('df -B1 /'); // Get bytes for root filesystem
$main_disk_total_bytes = 0;
$main_disk_used_bytes = 0;
if (preg_match('/\s+(\d+)\s+(\d+)\s+\d+\s+(\d+)%\s+\/$/', $df_output_main, $matches)) {
    $main_disk_total_bytes = intval($matches[1]);
    $main_disk_used_bytes = intval($matches[2]);
}
$data['main_disk_percent'] = ($main_disk_total_bytes > 0) ? sprintf("%.0f%%", ($main_disk_used_bytes / $main_disk_total_bytes) * 100) : '--%';
$data['main_disk_total_gb'] = format_memory_gb($main_disk_total_bytes) . ' GB';
$data['main_disk_used_gb'] = format_memory_gb($main_disk_used_bytes) . ' GB';

// --- USB Disk Usage ---
// IMPORTANT: Replace '/mnt/usb' with the actual mount point of your USB drive.
$df_output_usb = safe_exec('df -B1 /mnt/usb');
$usb_disk_total_bytes = 0;
$usb_disk_used_bytes = 0;
if (preg_match('/\s+(\d+)\s+(\d+)\s+\d+\s+(\d+)%\s+\/mnt\/usb$/', $df_output_usb, $matches)) {
    $usb_disk_total_bytes = intval($matches[1]);
    $usb_disk_used_bytes = intval($matches[2]);
}
$data['usb_disk_percent'] = ($usb_disk_total_bytes > 0) ? sprintf("%.0f%%", ($usb_disk_used_bytes / $usb_disk_total_bytes) * 100) : '--%';
$data['usb_disk_total_gb'] = format_memory_gb($usb_disk_total_bytes) . ' GB';
$data['usb_disk_used_gb'] = format_memory_gb($usb_disk_used_bytes) . ' GB';

echo json_encode($data);
?>
