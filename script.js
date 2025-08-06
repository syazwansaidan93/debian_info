const GO_API_BASE_URL = 'http://192.168.1.3:3040'; // Go API base URL
const PROCESS_UPDATE_INTERVAL_MS = 10000; // Interval for updating top processes (10 seconds)

let processUpdateIntervalId = null;

// Flag to track if initial load is complete, to hide loading overlay
let initialLoadComplete = false;

async function updateSystemInfo() {
    try {
        // Fetch real-time system information from the Go API endpoint
        const response = await fetch(`${GO_API_BASE_URL}/stats`);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status} ${response.statusText}`);
        }
        const data = await response.json();

        // Update CPU metrics
        document.getElementById('cpu-usage').textContent = data.cpu_percent;
        document.getElementById('cpu-uptime').textContent = `Uptime: ${data.cpu_uptime}`;

        // Update RAM metrics
        document.getElementById('ram-usage').textContent = data.ram_percent;
        document.getElementById('ram-total').textContent = `Total: ${data.ram_total_gb}`;

        // Update CPU Temperature and status
        const cpuTempElement = document.getElementById('cpu-temp');
        const cpuTempStatusElement = document.getElementById('cpu-temp-status');
        cpuTempElement.textContent = data.cpu_temp; // Data already includes °C from backend

        // Determine CPU temperature status and apply appropriate styling
        const tempValue = parseFloat(data.cpu_temp); // Parse float from string like "55.0°C"
        // Remove all existing color classes first to ensure only one is applied
        cpuTempElement.classList.remove('text-red-500', 'text-orange-500', 'text-indigo-600');
        if (!isNaN(tempValue)) {
            if (tempValue > 75) {
                cpuTempElement.classList.add('text-red-500');
                cpuTempStatusElement.textContent = 'High';
            } else if (tempValue > 60) {
                cpuTempElement.classList.add('text-orange-500');
                cpuTempStatusElement.textContent = 'Warm';
            } else {
                cpuTempElement.classList.add('text-indigo-600');
                cpuTempStatusElement.textContent = 'Normal';
            }
        } else {
            cpuTempStatusElement.textContent = 'N/A';
            cpuTempElement.classList.add('text-indigo-600'); // Default color for N/A
        }

        // Update Network Speed metrics
        document.getElementById('net-speed-upload-value').textContent = data.net_upload_speed;
        document.getElementById('net-speed-download-value').textContent = data.net_download_speed;
        document.getElementById('total-bytes-sent-value').textContent = `Sent: ${data.total_bytes_sent}`;
        document.getElementById('total-bytes-received-value').textContent = `Received: ${data.total_bytes_recv}`;

        // Update Main Disk Usage
        document.getElementById('disk-percent').textContent = data.main_disk_percent;
        document.getElementById('disk-used-total').textContent = `Used: ${data.main_disk_used_gb} / Total: ${data.main_disk_total_gb}`;

        // Update USB Disk Usage
        document.getElementById('usb-disk-percent').textContent = data.usb_disk_percent;
        document.getElementById('usb-disk-used-total').textContent = `Used: ${data.usb_disk_used_gb} / Total: ${data.usb_disk_total_gb}`;

        // Hide loading overlay after the first successful data load
        if (!initialLoadComplete) {
            document.getElementById('loading-overlay').classList.add('hidden');
            initialLoadComplete = true;
        }

    } catch (error) {
        console.error('Failed to fetch system info from Go backend:', error);
        // Set all displayed values to error state for clarity
        document.getElementById('cpu-usage').textContent = '--%';
        document.getElementById('cpu-uptime').textContent = 'Uptime: Error';
        document.getElementById('ram-usage').textContent = '--%';
        document.getElementById('ram-total').textContent = 'Total: Error';
        document.getElementById('cpu-temp').textContent = '--°C';
        document.getElementById('cpu-temp-status').textContent = 'Error';
        document.getElementById('net-speed-upload-value').textContent = '-- B/s';
        document.getElementById('net-speed-download-value').textContent = '-- B/s';
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
    processList.innerHTML = '<li>Fetching processes...</li>'; // Show loading state
    try {
        // Fetch top processes from Go API endpoint
        const response = await fetch(`${GO_API_BASE_URL}/processes`);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status} ${response.statusText}`);
        }
        const processes = await response.json();

        if (processes.length === 0) {
            processList.innerHTML = '<li>No active processes found.</li>';
            return;
        }

        // Clear previous list and populate with new data
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
        console.error('Failed to fetch top processes from Go backend:', error);
        processList.innerHTML = `<li>Error loading processes: ${error.message}</li>`;
    }
}

async function fetchAndDisplayNetworkInterfaces() {
    const interfaceList = document.getElementById('interface-list');
    interfaceList.innerHTML = '<li>Fetching interfaces...</li>'; // Show loading state
    try {
        // Fetch network interfaces from Go API endpoint
        const response = await fetch(`${GO_API_BASE_URL}/interfaces`);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status} ${response.statusText}`);
        }
        const interfaces = await response.json();

        if (interfaces.length === 0) {
            interfaceList.innerHTML = '<li>No network interfaces found.</li>';
            return;
        }

        // Clear previous list and populate with new data
        interfaceList.innerHTML = '';
        interfaces.forEach(iface => {
            // Filter out 'lo' (loopback) and 'wlan0' interfaces for cleaner display
            if (iface.name === 'lo' || iface.name === 'wlan0') {
                return; // Skip this interface
            }

            const li = document.createElement('li');
            li.className = 'interface-item';
            // Format IP addresses for display
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
        console.error('Failed to fetch network interfaces from Go backend:', error);
        interfaceList.innerHTML = `<li>Error loading interfaces: ${error.message}</li>`;
    }
}


/**
 * Toggles the expansion of a card section and loads content if not already loaded.
 * @param {string} headerId - The ID of the header element.
 * @param {string} contentId - The ID of the content element.
 * @param {Function} loadFunction - The asynchronous function to call to load the content.
 */
function toggleExpandableSection(headerId, contentId, loadFunction) {
    const header = document.getElementById(headerId);
    const content = document.getElementById(contentId);
    const icon = header.querySelector('.expand-icon');

    header.addEventListener('click', () => {
        const isExpanded = content.classList.toggle('expanded');
        icon.classList.toggle('rotated', isExpanded);

        if (isExpanded) {
            // Call the load function immediately when expanded
            loadFunction();
            // If it's the processes section, start an interval for continuous updates
            if (headerId === 'processes-header' && processUpdateIntervalId === null) {
                processUpdateIntervalId = setInterval(loadFunction, PROCESS_UPDATE_INTERVAL_MS);
            }
        } else {
            // If collapsing the processes section, clear the update interval
            if (headerId === 'processes-header' && processUpdateIntervalId !== null) {
                clearInterval(processUpdateIntervalId);
                processUpdateIntervalId = null;
            }
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    // Initial fetch of system information to populate cards and hide loading overlay
    updateSystemInfo();
    // Set interval for continuous updates of main system info (every second)
    setInterval(updateSystemInfo, 1000);

    // Setup expandable sections
    toggleExpandableSection('processes-header', 'processes-content', fetchAndDisplayTopProcesses);
    toggleExpandableSection('interfaces-header', 'interfaces-content', fetchAndDisplayNetworkInterfaces);
});
