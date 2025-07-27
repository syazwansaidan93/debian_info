from flask import Flask, jsonify
from flask_cors import CORS
import psutil
import datetime
import math
import socket
import time

app = Flask(__name__)
CORS(app)

def _format_bytes_human_readable(bytes_val):
    if bytes_val is None:
        return "--"
    if bytes_val == 0:
        return "0 Bytes"
    sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB']
    i = int(math.floor(math.log(bytes_val, 1024)))
    return f"{bytes_val / (1024 ** i):.2f} {sizes[i]}"

def _format_speed_mib_kib(bytes_per_sec):
    if bytes_per_sec is None:
        return "-- MiB/s"
    kib_factor = 1024
    mib_factor = 1024 * 1024
    if bytes_per_sec < mib_factor:
        return f"{bytes_per_sec / kib_factor:.0f} KiB/s"
    else:
        return f"{bytes_per_sec / mib_factor:.2f} MiB/s"

last_net_io_counters = None
last_net_io_time = None

_last_proc_cpu_times = {}
_last_proc_cpu_time_check = time.time()

_last_disk_io_counters = None
_last_disk_io_time = time.time()

@app.route('/api/system_info', methods=['GET'])
def get_system_info():
    global last_net_io_counters, last_net_io_time, _last_disk_io_counters, _last_disk_io_time

    cpu_percent = psutil.cpu_percent(interval=1)
    ram = psutil.virtual_memory()
    ram_percent = ram.percent

    ram_total_gb = f"{ram.total / (1024**3):.1f} GB"
    ram_used_gb = f"{ram.used / (1024**3):.1f} GB"

    uptime_seconds = psutil.boot_time()
    uptime_delta = datetime.timedelta(seconds=int(time.time() - uptime_seconds))
    
    total_seconds = int(uptime_delta.total_seconds())
    days = total_seconds // (24 * 3600)
    hours = (total_seconds % (24 * 3600)) // 3600
    minutes = (total_seconds % 3600) // 60
    uptime_str = f"{days} days, {hours} hours, {minutes} minutes"

    cpu_temp_val = "N/A"
    cpu_temp_status = "N/A"
    if hasattr(psutil, "sensors_temperatures"):
        temps = psutil.sensors_temperatures()
        if "coretemp" in temps:
            for entry in temps["coretemp"]:
                if "Package id" in entry.label or "Core 0" in entry.label:
                    cpu_temp_val = f"{float(entry.current):.1f}"
                    break
        elif "cpu_thermal" in temps:
            for entry in temps["cpu_thermal"]:
                cpu_temp_val = f"{float(entry.current):.1f}"
                break
    
    if cpu_temp_val != "N/A":
        temp_float = float(cpu_temp_val)
        if temp_float > 75:
            cpu_temp_status = 'High'
        elif temp_float > 60:
            cpu_temp_status = 'Warm'
        else:
            cpu_temp_status = 'Normal'

    disk_usage = psutil.disk_usage('/')
    disk_percent = disk_usage.percent
    
    disk_total_gb = f"{disk_usage.total / (1024**3):.1f} GB"
    disk_used_gb = f"{disk_usage.used / (1024**3):.1f} GB"

    net_io_counters = psutil.net_io_counters()
    
    total_bytes_sent_formatted = _format_bytes_human_readable(net_io_counters.bytes_sent)
    total_bytes_recv_formatted = _format_bytes_human_readable(net_io_counters.bytes_recv)

    net_upload_speed_bytes_per_sec = 0
    net_download_speed_bytes_per_sec = 0
    current_time = time.time()

    if last_net_io_counters and last_net_io_time:
        time_diff = current_time - last_net_io_time
        if time_diff > 0:
            upload_diff = net_io_counters.bytes_sent - last_net_io_counters.bytes_sent
            download_diff = net_io_counters.bytes_recv - last_net_io_counters.bytes_recv
            net_upload_speed_bytes_per_sec = upload_diff / time_diff
            net_download_speed_bytes_per_sec = download_diff / time_diff

    last_net_io_counters = net_io_counters
    last_net_io_time = current_time

    disk_io_counters = psutil.disk_io_counters()
    disk_read_speed_bytes_per_sec = 0
    disk_write_speed_bytes_per_sec = 0

    if _last_disk_io_counters and _last_disk_io_time:
        disk_time_diff = current_time - _last_disk_io_time
        if disk_time_diff > 0:
            read_diff = disk_io_counters.read_bytes - _last_disk_io_counters.read_bytes
            write_diff = disk_io_counters.write_bytes - _last_disk_io_counters.write_bytes
            disk_read_speed_bytes_per_sec = read_diff / disk_time_diff
            disk_write_speed_bytes_per_sec = write_diff / disk_time_diff
    
    _last_disk_io_counters = disk_io_counters
    _last_disk_io_time = current_time

    return jsonify({
        'cpu_percent': f"{cpu_percent:.1f}%",
        'cpu_uptime': uptime_str,
        'ram_percent': f"{ram_percent:.1f}%",
        'ram_total_gb': ram_total_gb,
        'ram_used_gb': ram_used_gb,
        'cpu_temp': f"{cpu_temp_val}°C" if cpu_temp_val != "N/A" else "N/A",
        'cpu_temp_status': cpu_temp_status,
        'disk_percent': f"{disk_percent:.1f}%",
        'disk_total_gb': disk_total_gb,
        'disk_used_gb': disk_used_gb,
        'net_upload_speed': _format_speed_mib_kib(net_upload_speed_bytes_per_sec),
        'net_download_speed': _format_speed_mib_kib(net_download_speed_bytes_per_sec),
        'total_bytes_sent': total_bytes_sent_formatted,
        'total_bytes_recv': total_bytes_recv_formatted,
        'disk_read_speed': _format_speed_mib_kib(disk_read_speed_bytes_per_sec),
        'disk_write_speed': _format_speed_mib_kib(disk_write_speed_bytes_per_sec)
    })

@app.route('/api/top_processes', methods=['GET'])
def get_top_processes():
    processes = []
    current_time = time.time()
    global _last_proc_cpu_times, _last_proc_cpu_time_check

    time_diff = current_time - _last_proc_cpu_time_check
    if time_diff < 0.01:
        time_diff = 0.01

    new_proc_cpu_times = {}

    for proc in psutil.process_iter(['pid', 'name', 'cpu_times', 'memory_percent', 'cmdline']):
        try:
            pid = proc.info['pid']
            name = proc.info['name']
            mem_percent = proc.info['memory_percent']
            proc_cpu_times = proc.info['cpu_times']

            new_proc_cpu_times[pid] = proc_cpu_times

            cpu_percent = 0.0
            if pid in _last_proc_cpu_times:
                last_cpu_times = _last_proc_cpu_times[pid]
                total_last_cpu_time = sum(last_cpu_times)
                total_current_cpu_time = sum(proc_cpu_times)
                total_delta_cpu = total_current_cpu_time - total_last_cpu_time

                num_cores = psutil.cpu_count(logical=True)
                if num_cores > 0:
                    cpu_percent = (total_delta_cpu / time_diff) * 100.0 / num_cores
                    cpu_percent = round(cpu_percent, 1)
                else:
                    cpu_percent = 0.0

            processes.append({
                'pid': pid,
                'name': name,
                'cpu_percent': f"{cpu_percent:.1f}%",
                'memory_percent': f"{mem_percent:.1f}%",
                'cmdline': ' '.join(proc.info['cmdline']) if proc.info['cmdline'] else ''
            })
        except (psutil.NoSuchProcess, psutil.AccessDenied, psutil.ZombieProcess):
            continue
        except Exception as inner_e:
            pass
    
    _last_proc_cpu_times = new_proc_cpu_times
    _last_proc_cpu_time_check = current_time

    processes.sort(key=lambda x: float(x['cpu_percent'].strip('%')), reverse=True)
    return jsonify(processes[:10])

@app.route('/api/network_interfaces', methods=['GET'])
def get_network_interfaces():
    try:
        interfaces_data = []
        net_if_addrs = psutil.net_if_addrs()
        net_if_stats = psutil.net_if_stats()

        for name, addrs in net_if_addrs.items():
            interface_info = {
                'name': name,
                'status': 'unknown',
                'ip_addresses': [],
                'mac_address': 'N/A'
            }

            if name in net_if_stats:
                stats = net_if_stats[name]
                interface_info['status'] = 'up' if stats.isup else 'down'
            
            for addr in addrs:
                if addr.family == psutil.AF_LINK:
                    interface_info['mac_address'] = addr.address
                elif addr.family == socket.AF_INET:
                    interface_info['ip_addresses'].append({'family': 'IPv4', 'address': addr.address, 'netmask': addr.netmask, 'broadcast': addr.broadcast})
                elif addr.family == socket.AF_INET6:
                    interface_info['ip_addresses'].append({'family': 'IPv6', 'address': addr.address, 'netmask': addr.netmask})
            
            interfaces_data.append(interface_info)
        
        return jsonify(interfaces_data)
    except Exception as e:
        return jsonify({'error': 'Failed to retrieve network interfaces', 'details': str(e)}), 500

if __name__ == '__main__':
    last_net_io_counters = psutil.net_io_counters()
    last_net_io_time = time.time()
    
    for proc in psutil.process_iter(['pid', 'cpu_times']):
        try:
            _last_proc_cpu_times[proc.info['pid']] = proc.info['cpu_times']
        except (psutil.NoSuchProcess, psutil.AccessDenied):
            continue
    _last_proc_cpu_time_check = time.time()

    _last_disk_io_counters = psutil.disk_io_counters()
    _last_disk_io_time = time.time()

    app.run(host='0.0.0.0', port=5000)
