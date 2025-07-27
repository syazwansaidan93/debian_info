# System Monitoring Dashboard

A lightweight, real-time system monitoring dashboard built with Python Flask for the backend API and a simple HTML/CSS/JavaScript frontend. This project is designed to provide quick insights into your server's performance metrics, accessible via a web browser.

## Features

* **Real-time System Metrics:**

  * CPU Usage (%) and Uptime

  * RAM Usage (%, Total, Used)

  * CPU Temperature (°C) with status (Normal, Warm, High)

  * Disk Usage (%, Total, Used)

  * Network Speed (Upload/Download in MiB/s)

  * Total Bytes Sent/Received

* **Top Processes:** View the top 10 processes by CPU and Memory usage.

* **Network Interfaces:** List detailed information about each network interface (IPs, MAC, status).

* **Optimized for Low-Power Devices:** Most of the data processing and formatting is handled by the Python backend, reducing the load on the client-side browser (e.g., on a mobile device).

* **Clean Separation:** Clear distinction between backend (Python), styling (CSS), and interactivity (JavaScript) for easier maintenance.

## Prerequisites

Before you begin, ensure you have the following installed on your Debian server:

* **Python 3.x**

* **pip** (Python package installer)

* **Nginx** (web server)

* **AdGuard Home** (or any DNS server capable of DNS rewrites, if you want to use `status.home` domain)

* **`lm-sensors`** (for CPU temperature readings)

## Installation and Setup

Follow these steps to get your system monitoring dashboard up and running.

### 1. Python Backend Setup

This sets up the Flask API that collects system data.

**a. Create Project Directory:**



```

sudo mkdir -p /home/wan/system_info_dashboard
cd /home/wan/system_info_dashboard

```

**b. Create a Virtual Environment:**



```

python3 -m venv venv
source venv/bin/activate

```

**c. Install Dependencies:**



```

pip install Flask Flask-Cors psutil gunicorn

```

**d. Create the Python Flask Application (`system_info_server.py`):**

Create a file named `system_info_server.py` in your project directory (`/home/wan/system_info_dashboard/`) and paste the content from the `Python System Info Server (Production Ready)` Canvas into it.

**e. Create a Systemd Service for Gunicorn:**

This ensures your Flask app runs as a background service and starts on boot.



```

sudo nano /etc/systemd/system/system_info_server.service

```

Paste the following content:



```

\[Unit\]
Description=Gunicorn instance for System Info Server
After=network.target

\[Service\]
User=www-data
Group=www-data
WorkingDirectory=/home/wan/system_info_dashboard
ExecStart=/home/wan/system_info_dashboard/venv/bin/gunicorn -w 4 -b 0.0.0.0:5000 system_info_server:app
Restart=always
PrivateTmp=true

\[Install\]
WantedBy=multi-user.target

```

* **`User=www-data`**: Runs the service as the `www-data` user, which is common for web servers.

* **`WorkingDirectory`**: Points to your project directory.

* **`ExecStart`**: Specifies the Gunicorn command. `-w 4` means 4 worker processes, `-b 0.0.0.0:5000` binds to all interfaces on port 5000. `system_info_server:app` refers to the `app` object in `system_info_server.py`.

**f. Enable and Start the Service:**



```

sudo systemctl daemon-reload
sudo systemctl start system_info_server
sudo systemctl enable system_info_server

```

Verify its status: `sudo systemctl status system_info_server` (should be active/running).

### 2. Frontend Web Files Setup

This sets up the HTML, CSS, and JavaScript for your dashboard.

**a. Create Web Root Directory:**



```

sudo mkdir -p /var/www/html/system_info_dashboard

```

**b. Create HTML, CSS, and JavaScript Files:**

* Create `index.html` in `/var/www/html/system_info_dashboard/` and paste the content from the `Consolidated System Information Dashboard (Weather Removed)` Canvas.

* Create `style.css` in `/var/www/html/system_info_dashboard/` and paste the content from the `Dashboard Stylesheet` Canvas.

* Create `script.js` in `/var/www/html/system_info_dashboard/` and paste the content from the `Dashboard JavaScript (Weather Removed)` Canvas.

### 3. Nginx Configuration

Nginx will serve your static web files and proxy requests to your Python API.

**a. Create Nginx Site Configuration:**



```

sudo nano /etc/nginx/sites-available/system_info_dashboard

```

Paste the content from the `Nginx Configuration for WebSocket Proxy` Canvas. **Remember to replace `your_domain_or_ip` with `status.home` or your server's actual IP address.**

**b. Create a Symlink to Enable the Site:**



```

sudo ln -s /etc/nginx/sites-available/system_info_dashboard /etc/nginx/sites-enabled/

```

**c. Test and Restart Nginx:**



```

sudo nginx -t
sudo systemctl restart nginx

```

### 4. AdGuard Home DNS Rewrite (Optional but Recommended)

If you want to access the dashboard using `http://status.home`, configure AdGuard Home.

**a. Log in to AdGuard Home.**
**b. Navigate to Filters > DNS rewrites.**
**c. Add a new DNS rewrite entry:**
* **Domain:** `status.home`
* **IP Address:** `YOUR_SERVER_IP` (The internal IP address of your Debian server).
**d. Save** your changes and flush DNS cache on your client device.

### 5. Install `lm-sensors` for CPU Temperature



```

sudo apt update
sudo apt install lm-sensors
sudo sensors-detect # Follow prompts, say YES to all defaults and saving modules
sudo systemctl restart kmod # Or `sudo modprobe coretemp` if suggested

```

Verify with `sensors` command: `sensors`.

## Usage

Once all services are running and configured:

* Open your web browser and navigate to `http://status.home` (or `http://YOUR_SERVER_IP`).

* The dashboard will display real-time system information.

* Expand the "Top Processes" and "Network Interfaces" sections to view more details.

## API Endpoints

You can also access the JSON data directly from the Python backend (proxied via Nginx):

* **System Information:** `http://status.home/api/system_info`

* **Top Processes:** `http://status.home/api/top_processes`

* **Network Interfaces:** `http://status.home/api/network_interfaces`

## Contributing

Feel free to fork this repository, make improvements, and submit pull requests.

## License

This project is open-source and available under the [MIT License](https://www.google.com/search?q=LICENSE).
