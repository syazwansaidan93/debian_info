<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allow CORS for your frontend

// Function to safely execute shell commands
function safe_exec($command) {
    return shell_exec($command . ' 2>&1');
}

$interfaces_data = [];
$output = safe_exec('ip -j a'); // Get JSON output of network interfaces

$ip_data = json_decode($output, true);

if (json_last_error() === JSON_ERROR_NONE && is_array($ip_data)) {
    foreach ($ip_data as $iface) {
        $name = $iface['ifname'] ?? 'N/A';

        // Filter out 'lo' and 'wlan0' interfaces
        if ($name === 'lo' || $name === 'wlan0') {
            continue;
        }

        $interface_info = [
            'name' => $name,
            'status' => ($iface['operstate'] ?? 'unknown'),
            'ip_addresses' => [],
            'mac_address' => 'N/A'
        ];

        if (isset($iface['addr_info']) && is_array($iface['addr_info'])) {
            foreach ($iface['addr_info'] as $addr) {
                if (($addr['family'] ?? '') === 'inet') {
                    $interface_info['ip_addresses'][] = [
                        'family' => 'IPv4',
                        'address' => ($addr['local'] ?? 'N/A'),
                        'netmask' => ($addr['prefixlen'] ?? 'N/A'), // This is prefix length, not netmask
                        'broadcast' => ($addr['broadcast'] ?? 'N/A')
                    ];
                } elseif (($addr['family'] ?? '') === 'inet6') {
                    $interface_info['ip_addresses'][] = [
                        'family' => 'IPv6',
                        'address' => ($addr['local'] ?? 'N/A'),
                        'netmask' => ($addr['prefixlen'] ?? 'N/A') // This is prefix length, not netmask
                    ];
                }
            }
        }

        if (isset($iface['address'])) {
            $interface_info['mac_address'] = $iface['address'];
        }

        $interfaces_data[] = $interface_info;
    }
} else {
    // Fallback if ip -j a fails or is not available
    $output = safe_exec('ip a');
    $lines = explode("\n", $output);
    $current_iface = null;

    foreach ($lines as $line) {
        if (preg_match('/^\d+:\s+([a-zA-Z0-9]+):.*state\s+(\w+)/', $line, $matches)) {
            $name = $matches[1];
            $status = strtolower($matches[2]);

            // Filter out 'lo' and 'wlan0' interfaces
            if ($name === 'lo' || $name === 'wlan0') {
                $current_iface = null; // Reset to skip this interface
                continue;
            }

            if ($current_iface) {
                $interfaces_data[] = $current_iface;
            }
            $current_iface = [
                'name' => $name,
                'status' => $status,
                'ip_addresses' => [],
                'mac_address' => 'N/A'
            ];
        } elseif ($current_iface && preg_match('/link\/ether\s+([0-9a-f:]+)/', $line, $matches)) {
            $current_iface['mac_address'] = $matches[1];
        } elseif ($current_iface && preg_match('/inet\s+([0-9.]+)\/(\d+)\s+brd\s+([0-9.]+)/', $line, $matches)) {
            $current_iface['ip_addresses'][] = [
                'family' => 'IPv4',
                'address' => $matches[1],
                'netmask' => $matches[2], // This is prefix length
                'broadcast' => $matches[3]
            ];
        } elseif ($current_iface && preg_match('/inet6\s+([0-9a-f:]+)\/(\d+)/', $line, $matches)) {
            $current_iface['ip_addresses'][] = [
                'family' => 'IPv6',
                'address' => $matches[1],
                'netmask' => $matches[2] // This is prefix length
            ];
        }
    }
    if ($current_iface) {
        $interfaces_data[] = $current_iface;
    }
}

echo json_encode($interfaces_data);
?>
