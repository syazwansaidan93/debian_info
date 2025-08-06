# System Monitoring Dashboard

A lightweight, real-time system monitoring dashboard built with **Go** for the backend API and a simple HTML/CSS/JavaScript frontend. This project is designed to provide quick insights into your server's performance metrics, accessible via a web browser.

---

## Features

* **Real-time System Metrics (all powered by Go):**
    * CPU Usage (%) and Uptime
    * RAM Usage (%, Total, Used)
    * CPU Temperature (°C) with status (Normal, Warm, High)
    * Disk Usage (Main and USB, %, Total, Used)
    * Network Speed (Upload/Download with auto KB/MB conversion)
    * Total Bytes Sent/Received
* **Top Processes:** View processes by CPU and Memory usage.
* **Network Interfaces:** List detailed information about each network interface (IPs, MAC, status).
* **Efficient Backend Processing:** All data collection is handled by the high-performance Go backend using `gopsutil`, minimizing server-side CPU load.
* **Clean Separation:** Clear distinction between backend (Go), styling (CSS), and interactivity (JavaScript) for easier maintenance.

---

## Prerequisites

Before you begin, ensure you have the following installed on your Debian server (like Orange Pi Zero 3):

* **Go 1.18+** (or newer)
* **Nginx** (web server)

---

## Installation and Setup

Follow these steps to get your system monitoring dashboard up and running.

### 1. Go Backend Setup

This sets up the Go API that collects system data.

**a. Place Go API File:**

Place your `main.go` file (the Go backend code) into a directory, for example, `/home/wan/status/`.

**b. Initialize Go Module and Build:**

Navigate to your Go project directory and build the executable.

```bash
cd /home/wan/status
go mod init system_monitor # Only if you haven't done this already
go mod tidy
go build -o system_monitor
```

### 2. Systemd Service Setup

This configures your Go application to run as a `systemd` service, ensuring it starts automatically on boot and restarts if it crashes.

**a. Create the Service File:**

Open a new file for the `systemd` service:

```bash
sudo nano /etc/systemd/system/system_monitor.service
```

**b. Paste the Service Configuration:**

Paste the following content into the `system_monitor.service` file. Save and exit.

```ini
[Unit]
Description=Go System Monitor API
After=network.target

[Service]
ExecStart=/home/wan/status/system_monitor
WorkingDirectory=/home/wan/status/
Restart=always
RestartSec=5s
User=root
Group=root

[Install]
WantedBy=multi-user.target
```

**c. Reload `systemd` Daemon:**

Tell `systemd` to reload its configuration to recognize the new service file.

```bash
sudo systemctl daemon-reload
```

**d. Enable the Service:**

Enable the service to start automatically on boot.

```bash
sudo systemctl enable system_monitor.service
```

**e. Start the Service:**

Start your Go system monitor service immediately.

```bash
sudo systemctl start system_monitor.service
```

**f. Check the Service Status and Logs:**

Verify that your service is running and check for any errors:

```bash
sudo systemctl status system_monitor.service
sudo journalctl -u system_monitor.service -f
```
(Press `Ctrl+C` to exit the live log view.)

### 3. Frontend Web Files Setup

This sets up the HTML, CSS, and JavaScript for your dashboard.

**a. Create Web Root Directory:**

```bash
sudo mkdir -p /var/www/html/system_info_dashboard
```

**b. Place Frontend Files:**

Place the following files from your repository into the `/var/www/html/system_info_dashboard/` directory:

* `index.html`
* `style.css`
* `script.js` (Ensure this `script.js` contains the latest JavaScript code that points to the Go API at `http://192.168.1.3:3040`)

### 4. Nginx Configuration

Nginx will serve your static web files and proxy API requests to your Go backend.

**a. Create Nginx Site Configuration:**

```bash
sudo nano /etc/nginx/sites-available/system_info_dashboard
```

Paste the following content:

```nginx
server {
    listen 80;
    server_name your_server_ip; # Replace with your server's actual IP address

    root /var/www/html/system_info_dashboard; # Your web root for static files
    index index.html index.htm; # No index.php needed for static frontend

    location / {
        try_files $uri $uri/ =404; # Serve static files
    }

    # Proxy API requests to the Go backend
    location /api/ {
        proxy_pass http://127.0.0.1:3040; # Go API is listening on port 3040
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # Deny access to .ht* files, if any
    location ~ /\.ht {
        deny all;
    }
}
```

**b. Create a Symlink to Enable the Site:**

```bash
sudo ln -s /etc/nginx/sites-available/system_info_dashboard /etc/nginx/sites-enabled/
```

**c. Test and Restart Nginx:**

```bash
sudo nginx -t
sudo systemctl restart nginx
```

---

## Usage

Once all services are running and configured:

* Open your web browser and navigate to `http://YOUR_SERVER_IP`.
* The dashboard will display real-time system information, processes, and network interfaces, all powered by your Go backend.

---

## API Endpoints (Go Backend)

You can also access the JSON data directly from the Go backend (proxied via Nginx):

* **Main System Information:** `http://YOUR_SERVER_IP/api/stats`
* **Top Processes:** `http://YOUR_SERVER_IP/api/processes`
* **Network Interfaces:** `http://YOUR_SERVER_IP/api/interfaces`

---

## Contributing

Feel free to fork this repository, make improvements, and submit pull requests.
