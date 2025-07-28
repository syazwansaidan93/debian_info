# System Monitoring Dashboard

A lightweight, real-time system monitoring dashboard built with PHP for the backend API and a simple HTML/CSS/JavaScript frontend. This project is designed to provide quick insights into your server's performance metrics, accessible via a web browser.

## Features

* **Real-time System Metrics:**
    * CPU Usage (%) and Uptime
    * RAM Usage (%, Total, Used)
    * CPU Temperature (°C) with status (Normal, Warm, High)
    * Disk Usage (Main and USB, %, Total, Used)
    * Network Speed (Upload/Download with auto KB/MB conversion)
    * Total Bytes Sent/Received
* **Top Processes:** View the top 10 processes by CPU and Memory usage.
* **Network Interfaces:** List detailed information about each network interface (IPs, MAC, status).
* **Client-Side Processing:** All data calculations and formatting are handled by the JavaScript frontend, making the PHP backend lightweight.
* **Clean Separation:** Clear distinction between backend (PHP), styling (CSS), and interactivity (JavaScript) for easier maintenance.

## Prerequisites

Before you begin, ensure you have the following installed on your Debian server:

* **PHP 7.4+** (with `php-fpm` and `json`, `mbstring` extensions, usually enabled by default)
* **Nginx** (web server)

## Installation and Setup

Follow these steps to get your system monitoring dashboard up and running.

### 1\. PHP Backend Setup

This sets up the PHP API that collects system data.

**a. Create API Directory:**

```bash
sudo mkdir -p /var/www/html/api
```

**b. Place PHP API Files:**

Place the following PHP files from your repository into the `/var/www/html/api/` directory:

* `system_info.php`
* `top_processes.php`
* `network_interfaces.php`

Ensure your PHP-FPM service is running (e.g., `sudo systemctl start php7.4-fpm` and `sudo systemctl enable php7.4-fpm`).

### 2\. Frontend Web Files Setup

This sets up the HTML, CSS, and JavaScript for your dashboard.

**a. Create Web Root Directory:**

```bash
sudo mkdir -p /var/www/html/system_info_dashboard
```

**b. Place Frontend Files:**

Place the following files from your repository into the `/var/www/html/system_info_dashboard/` directory:

* `index.html`
* `style.css`
* `script.js`

### 3\. Nginx Configuration

Nginx will serve your static web files and pass PHP requests to PHP-FPM.

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
    index index.html index.htm index.php; # Add index.php to default index files

    location / {
        try_files $uri $uri/ =404; # Serve static files
    }

    # Pass PHP scripts to FastCGI (PHP-FPM)
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock; # Ensure this path matches your PHP-FPM socket
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
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

## Usage

Once all services are running and configured:

* Open your web browser and navigate to `http://YOUR_SERVER_IP`.
* The dashboard will display real-time system information.
* Expand the "Top Processes" and "Network Interfaces" sections to view more details.

## API Endpoints

You can also access the JSON data directly from the PHP backend (proxied via Nginx):

* **System Information:** `http://YOUR_SERVER_IP/api/system_info.php`
* **Top Processes:** `http://YOUR_SERVER_IP/api/top_processes.php`
* **Network Interfaces:** `http://YOUR_SERVER_IP/api/network_interfaces.php`

## Contributing

Feel free to fork this repository, make improvements, and submit pull requests.

## License

This project is open-source and available under the [MIT License](https://www.google.com/search?q=LICENSE).
