package main

import (
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"strings"
	"sync"
	"time"

	// Import gopsutil packages
	"github.com/shirou/gopsutil/v3/cpu"
	"github.com/shirou/gopsutil/v3/disk"
	"github.com/shirou/gopsutil/v3/host"
	"github.com/shirou/gopsutil/v3/mem"
	"github.com/shirou/gopsutil/v3/net"
	"github.com/shirou/gopsutil/v3/process" // Added for process info
)

const (
	usbMountPoint = "/mnt/usb" // IMPORTANT: Adjust this if your USB drive is mounted elsewhere
	serverPort    = ":3040"
)

// SystemStats struct defines the structure of the main JSON response (/stats)
type SystemStats struct {
	CPUPercent         string `json:"cpu_percent"`
	CPUUptime          string `json:"cpu_uptime"`
	RAMPercent         string `json:"ram_percent"`
	RAMTotalGB         string `json:"ram_total_gb"`
	RAMUsedGB          string `json:"ram_used_gb"`
	CPUTemp            string `json:"cpu_temp"`
	NetUploadSpeed     string `json:"net_upload_speed"`
	NetDownloadSpeed   string `json:"net_download_speed"`
	TotalBytesSent     string `json:"total_bytes_sent"`
	TotalBytesRecv     string `json:"total_bytes_recv"`
	MainDiskPercent    string `json:"main_disk_percent"`
	MainDiskTotalGB    string `json:"main_disk_total_gb"`
	MainDiskUsedGB     string `json:"main_disk_used_gb"`
	USBDiskPercent     string `json:"usb_disk_percent"`
	USBDiskTotalGB     string `json:"usb_disk_total_gb"`
	USBDiskUsedGB      string `json:"usb_disk_used_gb"`
}

// ProcessInfo struct defines the structure for individual processes
type ProcessInfo struct {
	PID         int32  `json:"pid"`
	Name        string `json:"name"`
	CPUPercent  string `json:"cpu_percent"`    // Formatted string
	MemoryPercent string `json:"memory_percent"` // Formatted string
}

// NetworkInterfaceInfo struct defines the structure for network interfaces
type NetworkInterfaceInfo struct {
	Name        string   `json:"name"`
	Status      string   `json:"status"` // e.g., "up", "down"
	MACAddress  string   `json:"mac_address"`
	IPAddresses []IPAddress `json:"ip_addresses"`
}

// IPAddress struct for IP details
type IPAddress struct {
	Family  string `json:"family"` // "IPv4" or "IPv6"
	Address string `json:"address"`
}

// NetStats struct for storing raw network bytes for delta calculation
type NetStats struct {
	BytesSent uint64  `json:"bytes_sent"`
	BytesRecv uint64  `json:"bytes_recv"`
	Timestamp float64 `json:"timestamp"`
}

var (
	// Mutex to protect access to shared in-memory stats
	mu sync.Mutex
	// In-memory storage for previous network stats (CPU percent is handled by gopsutil directly)
	lastNetStats NetStats
	// Store previous CPU times for process CPU percentage calculation
	prevProcessCPUTimes map[int32]float64
	prevProcessTimestamp float64
)

func init() {
    prevProcessCPUTimes = make(map[int32]float64)
    prevProcessTimestamp = 0.0
}


// formatBytesHumanReadable formats bytes into human-readable string (e.g., 10.5 KB).
func formatBytesHumanReadable(bytes uint64) string {
	if bytes == 0 {
		return "0 Bytes"
	}
	k := 1024.0
	sizes := []string{"Bytes", "KB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB"}
	i := 0
	for bytes >= uint64(k) && i < len(sizes)-1 {
		bytes = uint64(float64(bytes) / k)
		i++
	}
	return fmt.Sprintf("%.2f %s", float64(bytes), sizes[i])
}

// formatSpeedHumanReadable formats bytes per second into human-readable string (e.g., 10.5 KB/s).
func formatSpeedHumanReadable(bytesPerSecond float64) string {
	if bytesPerSecond == 0 {
		return "0 B/s"
	}
	k := 1024.0
	sizes := []string{"B/s", "KB/s", "MB/s", "GB/s", "TB/s", "PB/s", "EB/s", "ZB/s", "YB/s"}
	i := 0
	for bytesPerSecond >= k && i < len(sizes)-1 {
		bytesPerSecond /= k
		i++
	}
	return fmt.Sprintf("%.1f %s", bytesPerSecond, sizes[i])
}

// formatMemoryGB formats bytes into gigabytes.
func formatMemoryGB(bytes uint64) string {
	return fmt.Sprintf("%.1f GB", float64(bytes)/(1024*1024*1024))
}

// formatUptime formats total seconds into a human-readable uptime string.
func formatUptime(totalSeconds uint64) string {
	if totalSeconds == 0 {
		return "N/A"
	}
	days := totalSeconds / (3600 * 24)
	totalSeconds %= (3600 * 24)
	hours := totalSeconds / 3600
	totalSeconds %= 3600
	minutes := totalSeconds / 60

	uptimeString := ""
	if days > 0 {
		uptimeString += fmt.Sprintf("%d days, ", days)
	}
	uptimeString += fmt.Sprintf("%d hours, %d minutes", hours, minutes)
	return uptimeString
}

// getSystemStats collects all system statistics using gopsutil only.
func getSystemStats() SystemStats {
	stats := SystemStats{}
	currentTime := float64(time.Now().UnixNano()) / 1e9 // Current time in seconds with nanosecond precision

	// Acquire mutex for shared state
	mu.Lock()
	defer mu.Unlock()

	// --- CPU Usage Calculation (using gopsutil) ---
	cpuPercent := "--%"
	cpuPercentages, err := cpu.Percent(0, false) // false for total CPU, 0 duration for non-blocking
	if err != nil {
		log.Printf("Error getting CPU percent with gopsutil: %v", err)
	} else {
		if len(cpuPercentages) > 0 {
			cpuPercent = fmt.Sprintf("%.1f%%", cpuPercentages[0])
		}
	}
	stats.CPUPercent = cpuPercent

	// --- Uptime (using gopsutil) ---
	uptimeSeconds := uint64(0)
	uptimeVal, err := host.Uptime()
	if err != nil {
		log.Printf("Error getting uptime with gopsutil: %v", err)
	} else {
		uptimeSeconds = uptimeVal
	}
	stats.CPUUptime = formatUptime(uptimeSeconds)

	// --- RAM Information (using gopsutil) ---
	ramTotalBytes := uint64(0)
	ramUsedBytes := uint64(0)
	vmStat, err := mem.VirtualMemory()
	if err != nil {
		log.Printf("Error getting virtual memory with gopsutil: %v", err)
	} else {
		ramTotalBytes = vmStat.Total
		ramUsedBytes = vmStat.Used
	}
	ramPercent := "--%"
	if ramTotalBytes > 0 {
		ramPercent = fmt.Sprintf("%.1f%%", (float64(ramUsedBytes)/float64(ramTotalBytes))*100)
	}
	stats.RAMPercent = ramPercent
	stats.RAMTotalGB = formatMemoryGB(ramTotalBytes)
	stats.RAMUsedGB = formatMemoryGB(ramUsedBytes)

	// --- CPU Temperature (using gopsutil.host.SensorsTemperatures) ---
	cpuTempCelsius := -1.0
	temps, err := host.SensorsTemperatures()
	if err != nil {
		log.Printf("Error getting sensor temperatures with gopsutil: %v", err)
	} else {
		// Look for common CPU temperature labels
		for _, temp := range temps {
			// Common labels for CPU temperature on Linux
			if strings.Contains(strings.ToLower(temp.SensorKey), "cpu") ||
				strings.Contains(strings.ToLower(temp.SensorKey), "core") ||
				strings.Contains(strings.ToLower(temp.SensorKey), "package") ||
				strings.Contains(strings.ToLower(temp.SensorKey), "temp") { // Generic temp sensor
				cpuTempCelsius = temp.Temperature
				break // Take the first one found
			}
		}
	}
	if cpuTempCelsius != -1.0 {
		stats.CPUTemp = fmt.Sprintf("%.1f°C", cpuTempCelsius)
	} else {
		stats.CPUTemp = "--°C"
	}

	// --- Network Speed Calculation (using gopsutil) ---
	uploadSpeed := 0.0
	downloadSpeed := 0.0
	totalBytesSent := uint64(0)
	totalBytesRecv := uint64(0)

	netIOCounters, err := net.IOCounters(false) // false for total across all interfaces
	if err != nil {
		log.Printf("Error getting net IO counters with gopsutil: %v", err)
	} else {
		if len(netIOCounters) > 0 {
			currentNetStats := NetStats{
				BytesSent: netIOCounters[0].BytesSent,
				BytesRecv: netIOCounters[0].BytesRecv,
				Timestamp: currentTime,
			}
			totalBytesSent = currentNetStats.BytesSent
			totalBytesRecv = currentNetStats.BytesRecv

			if lastNetStats.Timestamp != 0 && currentNetStats.Timestamp > lastNetStats.Timestamp {
				deltaTime := currentNetStats.Timestamp - lastNetStats.Timestamp
				bytesSentDiff := currentNetStats.BytesSent - lastNetStats.BytesSent
				bytesRecvDiff := currentNetStats.BytesRecv - lastNetStats.BytesRecv
				uploadSpeed = float64(bytesSentDiff) / deltaTime
				downloadSpeed = float64(bytesRecvDiff) / deltaTime
			}
			lastNetStats = currentNetStats
		}
	}
	stats.NetUploadSpeed = formatSpeedHumanReadable(uploadSpeed)
	stats.NetDownloadSpeed = formatSpeedHumanReadable(downloadSpeed)
	stats.TotalBytesSent = formatBytesHumanReadable(totalBytesSent)
	stats.TotalBytesRecv = formatBytesHumanReadable(totalBytesRecv)

	// --- Main Disk Usage (Root Filesystem) (using gopsutil) ---
	mainDiskTotalBytes := uint64(0)
	mainDiskUsedBytes := uint64(0)
	diskUsageRoot, err := disk.Usage("/")
	if err != nil {
		log.Printf("Error getting disk usage for / with gopsutil: %v", err)
	} else {
		mainDiskTotalBytes = diskUsageRoot.Total
		mainDiskUsedBytes = diskUsageRoot.Used
	}
	mainDiskPercent := "--%"
	if mainDiskTotalBytes > 0 {
		mainDiskPercent = fmt.Sprintf("%.0f%%", (float64(mainDiskUsedBytes)/float64(mainDiskTotalBytes))*100)
	}
	stats.MainDiskPercent = mainDiskPercent
	stats.MainDiskTotalGB = formatMemoryGB(mainDiskTotalBytes)
	stats.MainDiskUsedGB = formatMemoryGB(mainDiskUsedBytes)

	// --- USB Disk Usage (using gopsutil) ---
	usbDiskTotalBytes := uint64(0)
	usbDiskUsedBytes := uint64(0)
	diskUsageUSB, err := disk.Usage(usbMountPoint)
	if err != nil {
		// Silencing this specific log as requested
		// log.Printf("Info: Error getting disk usage for %s with gopsutil (USB might not be mounted): %v", usbMountPoint, err)
	} else {
		usbDiskTotalBytes = diskUsageUSB.Total
		usbDiskUsedBytes = diskUsageUSB.Used
	}
	usbDiskPercent := "--%"
	if usbDiskTotalBytes > 0 {
		usbDiskPercent = fmt.Sprintf("%.0f%%", (float64(usbDiskUsedBytes)/float64(usbDiskTotalBytes))*100)
	}
	stats.USBDiskPercent = usbDiskPercent
	stats.USBDiskTotalGB = formatMemoryGB(usbDiskTotalBytes)
	stats.USBDiskUsedGB = formatMemoryGB(usbDiskUsedBytes)

	return stats
}

// getProcesses collects and formats top processes information.
func getProcesses() []ProcessInfo {
	var processesInfo []ProcessInfo
	currentTime := float64(time.Now().UnixNano()) / 1e9

	procs, err := process.Processes()
	if err != nil {
		log.Printf("Error getting processes with gopsutil: %v", err)
		return processesInfo
	}

	mu.Lock() // Lock to protect prevProcessCPUTimes and prevProcessTimestamp
	defer mu.Unlock()

	currentProcessCPUTimes := make(map[int32]float64)

	for _, p := range procs {
		pid := p.Pid
		name, err := p.Name()
		if err != nil {
			// log.Printf("Could not get name for PID %d: %v", pid, err)
			name = "unknown"
		}

		// Get CPU Times for delta calculation
		cpuTimes, err := p.Times()
		if err != nil {
			// log.Printf("Could not get CPU times for PID %d: %v", pid, err)
			continue
		}
		totalCPUTime := cpuTimes.User + cpuTimes.System // Sum of user and system CPU time

		currentProcessCPUTimes[pid] = totalCPUTime

		cpuPercent := "--%"
		if prevProcessTimestamp != 0 && currentTime > prevProcessTimestamp {
			deltaTime := currentTime - prevProcessTimestamp
			if prevTotalCPUTime, ok := prevProcessCPUTimes[pid]; ok {
				deltaCPUTime := totalCPUTime - prevTotalCPUTime
				if deltaTime > 0 && deltaCPUTime >= 0 { // deltaCPUTime should not be negative
					// Calculate CPU usage as a percentage of total CPU time available in the interval
					// Multiplied by number of CPU cores for htop-like display (e.g., 200% on a 2-core CPU)
					numCPUs, _ := cpu.Counts(true) // Logical CPU count
					if numCPUs == 0 { numCPUs = 1 } // Avoid division by zero
					
					cpuUsageRaw := (deltaCPUTime / deltaTime) * 100.0 / float64(numCPUs) // Normalize by numCPUs for per-core percentage
					cpuPercent = fmt.Sprintf("%.1f%%", cpuUsageRaw)
				}
			}
		}

		memInfo, err := p.MemoryInfo()
		memoryPercent := "--%"
		if err == nil {
			// Calculate memory percentage relative to total system RAM
			vmStat, err := mem.VirtualMemory()
			if err == nil && vmStat.Total > 0 {
				memUsageRaw := (float64(memInfo.RSS) / float64(vmStat.Total)) * 100
				memoryPercent = fmt.Sprintf("%.1f%%", memUsageRaw)
			}
		}

		processesInfo = append(processesInfo, ProcessInfo{
			PID:         pid,
			Name:        name,
			CPUPercent:  cpuPercent,
			MemoryPercent: memoryPercent,
		})
	}

	// Update previous process CPU times and timestamp for the next cycle
	prevProcessCPUTimes = currentProcessCPUTimes
	prevProcessTimestamp = currentTime

	return processesInfo
}

// getNetworkInterfaces collects and formats network interface information.
func getNetworkInterfaces() []NetworkInterfaceInfo {
	var interfacesInfo []NetworkInterfaceInfo

	netInterfaces, err := net.Interfaces()
	if err != nil {
		log.Printf("Error getting network interfaces with gopsutil: %v", err)
		return interfacesInfo
	}

	for _, iface := range netInterfaces {
		// Filter out 'lo' (loopback) and 'wlan0' interfaces if desired,
		// or keep them based on frontend needs.
		if iface.Name == "lo" || iface.Name == "wlan0" {
			continue // Skip these for cleaner display as in original JS
		}

		status := "unknown"
		if iface.Flags != nil {
			if strings.Contains(strings.Join(iface.Flags, ","), "up") {
				status = "up"
			} else {
				status = "down"
			}
		}

		var ipAddrs []IPAddress
		for _, addr := range iface.Addrs {
			family := "unknown"
			if strings.Contains(addr.Addr, ":") { // IPv6 contains colons
				family = "IPv6"
			} else if strings.Contains(addr.Addr, ".") { // IPv4 contains dots
				family = "IPv4"
			}
			ipAddrs = append(ipAddrs, IPAddress{Family: family, Address: addr.Addr})
		}

		interfacesInfo = append(interfacesInfo, NetworkInterfaceInfo{
			Name:        iface.Name,
			Status:      status,
			MACAddress:  iface.HardwareAddr,
			IPAddresses: ipAddrs,
		})
	}
	return interfacesInfo
}

// statsHandler handles HTTP requests for main system statistics.
func statsHandler(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Access-Control-Allow-Origin", "*") // Allow CORS

	stats := getSystemStats()
	json.NewEncoder(w).Encode(stats)
}

// processesHandler handles HTTP requests for top processes.
func processesHandler(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Access-Control-Allow-Origin", "*") // Allow CORS

	processes := getProcesses()
	json.NewEncoder(w).Encode(processes)
}

// interfacesHandler handles HTTP requests for network interfaces.
func interfacesHandler(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Access-Control-Allow-Origin", "*") // Allow CORS

	interfaces := getNetworkInterfaces()
	json.NewEncoder(w).Encode(interfaces)
}

func main() {
	log.Printf("Starting system monitor API on port %s", serverPort)
	http.HandleFunc("/stats", statsHandler)
	http.HandleFunc("/processes", processesHandler)   // New endpoint for processes
	http.HandleFunc("/interfaces", interfacesHandler) // New endpoint for network interfaces
	log.Fatal(http.ListenAndServe(serverPort, nil))
}
