# MikWeb — MikroTik Web Management Platform

A centralized, multi-router MikroTik management platform designed as a modern web alternative to WinBox. Built on **Laravel + Inertia.js + Vue 3 + TypeScript + Tailwind CSS**. Every operation interacts directly with real RouterOS devices over the native API protocol — there is no mock or simulation layer in production.

---

## 🚀 Fitur Utama (Features)

### 1. Fleet & Multi-Router Management
- **Multi-Tenant / Organization Scoping**: Kelola puluhan router dalam grup, site, dan organisasi yang berbeda.
- **Credential Encryption**: Semua kredensial RouterOS (API, SSH, SNMP) dienkripsi secara aman saat disimpan (`aes-256-cbc`) dan disembunyikan dari serialisasi maupun log audit.
- **Enterprise Resilience**: Dilengkapi dengan **Circuit Breaker**, **Retry Mechanism**, dan **Per-Router Write Lock** berbasis Redis untuk menjaga stabilitas koneksi router.
- **Dual Driver Engine**: Mendukung koneksi live via native protocol (`LibraryRouterOsClient`) dan in-memory testing (`MockRouterOsClient`).

### 2. Modul RouterOS (WinBox Standard)
Setiap halaman modul dilengkapi dengan **6 KPI Stat Cards**, **multi-select batch actions bar**, **1-click quick copy** dengan feedback visual, **sorting kolom** (termasuk numeric IP octet sorting), **paginasi fleksibel**, dan **fitur Export CSV**:

- 🌐 **Interfaces**: Status link, MTU, MAC Address, byte counter, real-time traffic rate, dan batch enable/disable.
- 📡 **IP Addresses & Routes**: Subnet CIDR, visualisasi interface, gateway reachability, numeric IP octet sorting, dan batch actions.
- 🌉 **Bridge & Ports**: Manajemen bridge interfaces dan bridge member ports (priority, path cost, horizon).
- 🏷 **ARP Table**: Pemetaan IP ke MAC Address, deteksi interface, 1-click make-static, dan batch ops.
- 🔌 **DHCP Server & Leases**: Pemantauan lease aktif, expired lease, status dynamic/static, binding MAC, dan batch make-static.
- 📥 **DHCP Client**: Status bound, gateway address, primary DNS, use peer NTP, renew, release, dan batch toggle.
- 🛡 **Firewall & NAT**: Rule chains (Forward, Input, Output), NAT (masquerade/dst-nat), Mangle, Address Lists, dan reset counters.
- 🔑 **PPP (Point-to-Point)**: PPPoE/PPTP/L2TP/SSTP Secrets CRUD, profile bandwidth, AAA accounting, active sessions monitor, 1-click disconnect, bulk secret generator, dan batch isolate/restore.
- 📶 **Hotspot**: Hotspot users, active login sessions, user profiles (shared users, rate-limit), server profiles, hosts IP binding, dan batch operations.
- 📊 **Simple Queues & Tree**: Manajemen bandwidth pelanggan, visualisasi target IP, quick limit sliders/presets, packet/byte counters reset, dan batch toggle.
- 🌊 **IP Pools**: Address pool ranges visualizer, next-pool chaining, dan tabel real-time used addresses.
- 🌍 **DNS**: Pengaturan upstream DNS, cache settings, allow remote requests, static DNS records CRUD, dan batch actions.
- ⚙️ **IP Services**: Konfigurasi port & IP filtering untuk API, API-SSL, Winbox, SSH, Telnet, WWW, dan WWW-SSL.
- 🔭 **Neighbors Discovery**: Deteksi perangkat tetangga via protokol MNDP, CDP, dan LLDP.
- 📜 **System Logs**: Live event logs dengan klasifikasi badge topics (info, warning, error, firewall, ppp, hotspot).
- 👥 **System Users & Groups**: Akun administrator RouterOS, permission groups (full, write, read), policy flags, dan active login sessions.
- 🔒 **WireGuard VPN**: Konfigurasi WireGuard interfaces, public keys, allowed IPs, endpoints, handshake status, dan statistik traffic.
- 🏢 **RADIUS AAA**: Konfigurasi external RADIUS server untuk autentikasi terpusat (PPP, Hotspot, Login).
- 📁 **Files Explorer**: Manajemen penyimpanan disk router, pembuatan backup binary (`.backup`), dan batch remove.
- 💾 **Configuration Backups**: Export text konfigurasi (`/export`) langsung via API, disimpan terenkripsi di server, dan dapat diunduh kapan saja.
- 🛠 **Tools & Diagnostics**: Eksekusi live Ping, Traceroute, Interface Torch (real-time bandwidth per IP/port), dan Netwatch ICMP monitoring.
- 💻 **Web Terminal**: Console terminal RouterOS interaktif dengan riwayat perintah cepat (*quick commands*).

### 3. Monitoring, Automasi & Operasi Massal
- 📈 **Fleet Monitoring**: Polling metrik otomatis (CPU load, memory usage, uptime, board temp), grafik riwayat ECharts, dan tombol on-demand "Collect Now".
- ⚡ **Automation Engine**: Rule engine otomatis (deteksi router offline, unreachable, atau CPU spike) dengan aksi alert via Webhook dan Telegram bot.
- 📦 **Bulk Operations**: Eksekusi serentak uji koneksi (*mass ping/test*) dan ekspor backup untuk seluruh armada router dalam 1 klik.
- 🔍 **Global Inventory Search**: Pencarian cepat lintas semua router berdasarkan nama, host/IP, serial number, versi RouterOS, dan lokasi/site.
- 🔐 **Keamanan & RBAC**: Autentikasi berbasis session & Sanctum tokens, Two-Factor Authentication (TOTP 2FA) dengan recovery codes, 6 level peran (Super Admin, Org Admin, Network Admin, Network Operator, Auditor, Read-Only), dan audit log lengkap.

---

## 🛠 Tech Stack

- **Backend**: Laravel 12/13, PHP 8.4
- **Frontend**: Vue 3 (Composition API), Inertia.js v2, TypeScript
- **Styling**: Tailwind CSS (Dark Mode first)
- **Database & Cache**: MySQL 8, Redis (Cache, Session, Queues, Write Lock)
- **Testing**: PHPUnit (106 tests, 842 assertions)
- **Code Quality**: Laravel Pint (PSR-12)

---

## 💻 Instalasi Lokal (Setup Guide)

### Prasyarat
- PHP >= 8.4 dengan ekstensi `pdo_mysql`, `redis`, `mbstring`, `bcmath`, `curl`
- Composer >= 2.x
- Node.js >= 20.x & NPM
- MySQL 8.x
- Redis Server

### Langkah Instalasi

1. **Clone repositori**:
   ```bash
   git clone https://github.com/ginginabdulgoni/mikweb.git
   cd mikweb
   ```

2. **Instal dependensi PHP & JavaScript**:
   ```bash
   composer install
   npm install
   ```

3. **Konfigurasi Environment**:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   Sesuaikan konfigurasi database dan Redis di dalam file `.env`:
   ```dotenv
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=mikweb
   DB_USERNAME=root
   DB_PASSWORD=

   REDIS_CLIENT=predis
   REDIS_HOST=127.0.0.1
   REDIS_PORT=6379

   # Driver RouterOS: gunakan 'library' untuk perangkat asli, atau 'mock' untuk dev offline
   MIKWEB_ROUTEROS_DRIVER=library
   ```

4. **Jalankan Migrasi & Database Seeder**:
   ```bash
   php artisan migrate --seed
   ```

5. **Build Aset Frontend**:
   ```bash
   npm run build
   # atau untuk mode pengembangan:
   # npm run dev
   ```

6. **Jalankan Aplikasi**:
   ```bash
   php artisan serve
   ```
   Buka browser di `http://127.0.0.1:8000`.

---

## 🔑 Akun Default (Seeded Accounts)

| Email | Password | Role | Hak Akses |
|---|---|---|---|
| `admin@mikweb.local` | `password` | Super Admin | Akses penuh seluruh organisasi dan konfigurasi |
| `operator@mikweb.local` | `password` | Network Operator | Monitoring, reboot, dan manajemen operasional |

---

## 🧪 Menjalankan Pengujian (Testing)

```bash
# Jalankan test suite PHPUnit
php artisan test --compact

# Cek formatting kode dengan Laravel Pint
vendor/bin/pint --test

# Build aset frontend
npm run build
```

---

## ☕ Dukungan

Jika Anda merasa proyek ini bermanfaat dan ingin mendukung saya, traktir kopi saya melalui:
- [Trakteer Kopi](https://trakteer.id/ginginabdulgoni/tip)
- [Paypal](https://paypal.me/ginginabdulgoni)

---

## 📄 Lisensi
Proyek ini dilisensikan di bawah [MIT License](LICENSE).
