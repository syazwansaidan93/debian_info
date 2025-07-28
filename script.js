const API_BASE_URL = 'http://status.home/api'; // Correctly points to your PHP API base URL

let processUpdateIntervalId = null; 

// Global variables to store previous raw data for calculations
let lastCpuStats = null;
let lastNetStats = null;
// Removed lastDiskIoStats as disk I/O speed is no longer calculated in JS

let initialLoadComplete = false; // New flag to track initial load

// Function to format speed for human readability (e.g., 10.5 KB/s, 2.3 MB/s)
function formatSpeedHumanReadable(bytesPerSecond) {
    if (bytesPerSecond === 0) return "0 B/s";
    const k = 1024;
    const sizes = ['B/s', 'KB/s', 'MB/s', 'GB/s', 'TB/s', 'PB/s', 'EB/s', 'ZB/s', 'YB/s'];
    const i = Math.floor(Math.log(bytesPerSecond) / Math.log(k));
    return (bytesPerSecond / Math.pow(k, i)).toFixed(1) + ' ' + sizes[i];
}

// Function to format total bytes for human readability
function formatBytesHumanReadable(bytes) {
    if (bytes === 0) return "0 Bytes";
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return (bytes / Math.pow(k, i)).toFixed(2) + ' ' + sizes[i];
}

// Function to format memory for human readability
function formatMemoryHumanReadable(bytes) {
    if (bytes === 0) return "0 Bytes";
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return (bytes / Math.pow(k, i)).toFixed(1) + ' ' + sizes[i];
}

// Function to format uptime for human readability
function formatUptime(totalSeconds) {
    if (totalSeconds === 0) return "N/A";
    const days = Math.floor(totalSeconds / (3600 * 24));
    totalSeconds %= (3600 * 24);
    const hours = Math.floor(totalSeconds / 3600);
    totalSeconds %= 3600;
    const minutes = Math.floor(totalSeconds / 60);

    let uptimeString = '';
    if (days > 0) uptimeString += `${days} days, `;
    uptimeString += `${hours} hours, ${minutes} minutes`;
    return uptimeString;
}


async function updateSystemInfo() {
    try {
        const response = await fetch(`${API_BASE_URL}/system_info.php`);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status} ${response.statusText}`);
        }
        const data = await response.json();

        // --- CPU Usage Calculation ---
        const currentCpuStats = data.cpu_stats;
        if (lastCpuStats && currentCpuStats) {
            const deltaTime = currentCpuStats.timestamp - lastCpuStats.timestamp;
            const deltaTotalCpuTime = currentCpuStats.total_cpu_time - lastCpuStats.total_cpu_time;
            const deltaIdleTime = currentCpuStats.idle_time - lastCpuStats.idle_time;

            if (deltaTime > 0 && deltaTotalCpuTime > 0) {
                const cpuUsagePercent = ((deltaTotalCpuTime - deltaIdleTime) / deltaTotalCpuTime) * 100;
                document.getElementById('cpu-usage').textContent = `${cpuUsagePercent.toFixed(1)}%`;
            } else {
                document.getElementById('cpu-usage').textContent = 'N/A%';
            }
        } else {
            document.getElementById('cpu-usage').textContent = '--%';
        }
        lastCpuStats = currentCpuStats; // Store current for next calculation

        // --- Uptime ---
        document.getElementById('cpu-uptime').textContent = `Uptime: ${formatUptime(data.uptime_seconds)}`;

        // --- RAM Usage Calculation ---
        const ramTotalBytes = data.ram_total_bytes;
        const ramUsedBytes = data.ram_used_bytes;
        const ramTotalGB = (ramTotalBytes / (1024 * 1024 * 1024)).toFixed(1);
        const ramUsedGB = (ramUsedBytes / (1024 * 1024 * 1024)).toFixed(1);
        const ramPercent = ramTotalBytes > 0 ? ((ramUsedBytes / ramTotalBytes) * 100).toFixed(1) : '--';
        
        document.getElementById('ram-usage').textContent = `${ramPercent}%`;
        document.getElementById('ram-total').textContent = `Total: ${ramTotalGB} GB`;

        // --- CPU Temperature ---
        const cpuTempElement = document.getElementById('cpu-temp');
        const cpuTempStatusElement = document.getElementById('cpu-temp-status');
        const tempValue = data.cpu_temp_celsius;

        if (tempValue !== null && !isNaN(tempValue)) {
            cpuTempElement.textContent = `${tempValue.toFixed(1)}°C`;
            if (tempValue > 75) {
                cpuTempElement.classList.add('text-red-500');
                cpuTempElement.classList.remove('text-indigo-600', 'text-orange-500');
                cpuTempStatusElement.textContent = 'High';
            } else if (tempValue > 60) {
                cpuTempElement.classList.add('text-orange-500');
                cpuTempElement.classList.remove('text-indigo-600', 'text-red-500');
                cpuTempStatusElement.textContent = 'Warm';
            } else {
                cpuTempElement.classList.add('text-indigo-600');
                cpuTempElement.classList.remove('text-red-500', 'text-orange-500');
                cpuTempStatusElement.textContent = 'Normal';
            }
        } else {
            cpuTempElement.textContent = '--°C';
            cpuTempStatusElement.textContent = 'N/A';
            cpuTempElement.classList.remove('text-red-500', 'text-orange-500');
            cpuTempElement.classList.add('text-indigo-600');
        }

        // --- Network Speed Calculation ---
        const currentNetStats = data.net_stats;
        let uploadSpeed = 0;
        let downloadSpeed = 0;

        if (lastNetStats && currentNetStats) {
            const deltaTime = currentNetStats.timestamp - lastNetStats.timestamp;
            if (deltaTime > 0) {
                const bytesSentDiff = currentNetStats.bytes_sent - lastNetStats.bytes_sent;
                const bytesRecvDiff = currentNetStats.bytes_recv - lastNetStats.bytes_recv;
                uploadSpeed = bytesSentDiff / deltaTime;
                downloadSpeed = bytesRecvDiff / deltaTime;
            }
        }
        lastNetStats = currentNetStats; // Store current for next calculation

        document.getElementById('net-speed-upload-value').textContent = formatSpeedHumanReadable(uploadSpeed);
        document.getElementById('net-speed-download-value').textContent = formatSpeedHumanReadable(downloadSpeed);
        document.getElementById('total-bytes-sent-value').textContent = `Sent: ${formatBytesHumanReadable(data.net_stats.bytes_sent)}`;
        document.getElementById('total-bytes-received-value').textContent = `Received: ${formatBytesHumanReadable(data.net_stats.bytes_recv)}`;

        // --- Disk Usage Calculation (Main Disk) ---
        const mainDiskTotalBytes = data.main_disk_total_bytes;
        const mainDiskUsedBytes = data.main_disk_used_bytes;
        const mainDiskTotalGB = (mainDiskTotalBytes / (1024 * 1024 * 1024)).toFixed(1);
        const mainDiskUsedGB = (mainDiskUsedBytes / (1024 * 1024 * 1024)).toFixed(1);
        const mainDiskPercent = mainDiskTotalBytes > 0 ? ((mainDiskUsedBytes / mainDiskTotalBytes) * 100).toFixed(0) : '--';

        document.getElementById('disk-percent').textContent = `${mainDiskPercent}%`;
        document.getElementById('disk-used-total').textContent = `Used: ${mainDiskUsedGB} GB / Total: ${mainDiskTotalGB} GB`;
        
        // --- USB Disk Usage Calculation ---
        const usbDiskTotalBytes = data.usb_disk_total_bytes;
        const usbDiskUsedBytes = data.usb_disk_used_bytes;
        const usbDiskTotalGB = (usbDiskTotalBytes / (1024 * 1024 * 1024)).toFixed(1);
        const usbDiskUsedGB = (usbDiskUsedBytes / (1024 * 1024 * 1024)).toFixed(1);
        const usbDiskPercent = usbDiskTotalBytes > 0 ? ((usbDiskUsedBytes / usbDiskTotalBytes) * 100).toFixed(0) : '--';

        document.getElementById('usb-disk-percent').textContent = `${usbDiskPercent}%`;
        document.getElementById('usb-disk-used-total').textContent = `Used: ${usbDiskUsedGB} GB / Total: ${usbDiskTotalGB} GB`;

        // Hide loading overlay after first successful data load
        if (!initialLoadComplete) {
            document.getElementById('loading-overlay').classList.add('hidden');
            initialLoadComplete = true;
        }

    } catch (error) {
        console.error('Failed to fetch system info from PHP backend:', error);
        document.getElementById('cpu-usage').textContent = '--%';
        document.getElementById('cpu-uptime').textContent = 'Uptime: Error';
        document.getElementById('ram-usage').textContent = '--%';
        document.getElementById('ram-total').textContent = 'Total: Error';
        document.getElementById('cpu-temp').textContent = '--°C';
        document.getElementById('cpu-temp-status').textContent = 'Error';
        document.getElementById('net-speed-upload-value').textContent = '0 B/s';
        document.getElementById('net-speed-download-value').textContent = '0 B/s';
        // Removed disk I/O specific error messages
        document.getElementById('disk-percent').textContent = '--%';
        document.getElementById('disk-used-total').textContent = 'Used: -- GB / Total: -- GB';
        document.getElementById('usb-disk-percent').textContent = '--%';
        document.getElementById('usb-disk-used-total').textContent = 'Used: -- GB / Total: -- GB';
        document.getElementById('total-bytes-sent-value').textContent = 'Sent: --';
        document.getElementById('total-bytes-received-value').textContent = 'Received: --';
    }
}

async function fetchAndDisplayTopProcesses() {
    const processList = document.getElementById('process-list');
    processList.innerHTML = '<li>Fetching processes...</li>';
    try {
        const response = await fetch(`${API_BASE_URL}/top_processes.php`);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status} ${response.statusText}`);
        }
        const processes = await response.json();
        
        if (processes.length === 0) {
            processList.innerHTML = '<li>No active processes found.</li>';
            return;
        }

        processList.innerHTML = '';
        processes.forEach(proc => {
            const li = document.createElement('li');
            li.className = 'process-item';
            li.innerHTML = `
                <span class="process-name">${proc.name} (PID: ${proc.pid})</span>
                <span class="process-stats">CPU: ${proc.cpu_percent} | Mem: ${proc.memory_percent}</span>
            `;
            processList.appendChild(li);
        });
    } catch (error) {
        console.error('Failed to fetch top processes:', error);
        processList.innerHTML = `<li>Error loading processes: ${error.message}</li>`;
    }
}

async function fetchAndDisplayNetworkInterfaces() {
    const interfaceList = document.getElementById('interface-list');
    interfaceList.innerHTML = '<li>Fetching interfaces...</li>'; 
    try {
        const response = await fetch(`${API_BASE_URL}/network_interfaces.php`);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status} ${response.statusText}`);
        }
        const interfaces = await response.json();

        if (interfaces.length === 0) {
            interfaceList.innerHTML = '<li>No network interfaces found.</li>';
            return;
        }

        interfaceList.innerHTML = ''; 
        interfaces.forEach(iface => {
            // Filter out 'lo' (loopback) and 'wlan0' interfaces
            if (iface.name === 'lo' || iface.name === 'wlan0') {
                return; // Skip this interface
            }

            const li = document.createElement('li');
            li.className = 'interface-item';
            let ipAddressesHtml = iface.ip_addresses.map(ip => `<span class="interface-ip">${ip.family}: ${ip.address}</span>`).join('');
            if (!ipAddressesHtml) ipAddressesHtml = '<span class="interface-ip">No IP Addresses</span>';

            li.innerHTML = `
                <div class="interface-name">
                    ${iface.name} (${iface.status})
                    <span class="block text-xs text-gray-500 dark:text-gray-400">MAC: ${iface.mac_address}</span>
                </div>
                <div class="interface-stats">
                    ${ipAddressesHtml}
                </div>
            `;
            interfaceList.appendChild(li);
        });
    } catch (error) {
        console.error('Failed to fetch network interfaces:', error);
        interfaceList.innerHTML = `<li>Error loading interfaces: ${error.message}</li>`;
    }
}


function toggleExpandableSection(headerId, contentId, loadFunction) {
    const header = document.getElementById(headerId);
    const content = document.getElementById(contentId);
    const icon = header.querySelector('.expand-icon');

    header.addEventListener('click', () => {
        const isExpanded = content.classList.toggle('expanded');
        icon.classList.toggle('rotated', isExpanded);

        if (isExpanded) {
            loadFunction(); 
            if (headerId === 'processes-header' && processUpdateIntervalId === null) {
                processUpdateIntervalId = setInterval(loadFunction, 10000); 
            }
        } else {
            if (headerId === 'processes-header' && processUpdateIntervalId !== null) {
                clearInterval(processUpdateIntervalId);
                processUpdateIntervalId = null;
            }
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    // Initial fetch to prime the lastStats variables
    updateSystemInfo(); 
    setInterval(updateSystemInfo, 1000);

    toggleExpandableSection('processes-header', 'processes-content', fetchAndDisplayTopProcesses);
    toggleExpandableSection('interfaces-header', 'interfaces-content', fetchAndDisplayNetworkInterfaces);
});
