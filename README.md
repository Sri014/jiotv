<div align="center">

  <img src="https://pub-d9e70e5abf3a43bcadb504fbe8d10dfa.r2.dev/jtv.png" width="128" height="128" style="border-radius: 24px; object-fit: contain;" alt="JioTV+ Proxy Logo" />

  # JioTV+ Proxy Localhost

  **A lightweight, high-performance PHP proxy and dynamic M3U playlist generator for JioTV+**

  [![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
  [![License: GPL-3.0](https://img.shields.io/badge/License-GPL--3.0-green.svg?style=for-the-badge)](https://www.gnu.org/licenses/gpl-3.0)
  [![Platform](https://img.shields.io/badge/Platform-XAMPP%20%7C%20KSWEB%20%7C%20Linux%20%7C%20Android-blue?style=for-the-badge)](https://github.com)
  [![Crafted With](https://img.shields.io/badge/Crafted%20With-%F0%9F%92%9A%20by%20LazyyXD-00E599?style=for-the-badge)](https://github.com)

</div>

---

## 📖 Overview

**JioTV+ Proxy Localhost** is an enterprise-grade, self-hosted PHP middleware that connects directly to the JioTV+ streaming infrastructure to deliver a **true JioTV+ Live TV streaming experience**. It seamlessly authenticates, caches channel lists, proxies DRM licenses, automatically refreshes expiring CDN tokens in real-time, and dynamically generates M3U playlists tailored for modern IPTV players.

---

## ✨ Features

- **📺 True JioTV+ Live TV Experience:** Delivers full HD/FHD live TV streaming with ultra-low latency, instant channel switching, and crystal-clear audio/video feeds.
- **⚡ Auto-Refreshing & Intelligent Caching:** Automated background CDN token refresh using fast `HEAD` requests before expiry, dynamic channel list caching, and zero playback stalling.
- **🔐 Native ClearKey DRM Support:** Built-in ClearKey DRM license acquisition proxy fully compatible with **Kodi InputStream Adaptive**, TiviMate, and modern IPTV decoders.
- **🧠 Smart Session Management:** Seamless OTP login flow, persistent local session tracking, automatic token renewal, and graceful failover recovery.
- **🚀 Multi-Server & Cross-Platform Support:** Tested and optimized for **Apache, XAMPP, KSWEB (Android), Nginx, Lighttpd**, and reverse proxies (Cloudflare Tunnels, Ngrok).
- **📋 Dynamic M3U Playlist Generation:** Generates real-time UTF-8 `#EXTM3U` playlists with channel logos, grouped categories, EPG XMLTV URLs, and format identifiers.
- **🎬 Dual-Protocol Streaming:** Instant format negotiation supporting both **MPEG-DASH (`.mpd`)** and **HLS (`.m3u8`)** streams.
- **🌍 Geo-Blocking Intelligence:** Built-in IP geolocation detection that redirects non-Indian traffic to format-matched fallback streams.
- **🛡️ Secure Storage Architecture:** Atomic writes (`tmp-and-rename`) with strict `.htaccess` restrictions preventing direct web access to sensitive tokens in `/data`.
- **🎯 Zero Bloat:** Pure, native PHP 8+ architecture powered by a unified `bootstrap.php` with zero third-party composer dependencies.

---

## 📁 Project Structure

```text
jiotv-localhost/
├── api/
│   ├── auth.php          # Authentication controller (OTP request, verification, session)
│   ├── bootstrap.php     # Core initialization, shared constants, and utility helpers
│   └── channels.php      # Live channel fetch and cache endpoint
├── data/
│   ├── channels.json     # Cached channel metadata & stream definitions
│   ├── session.json      # Local authentication session and API keys
│   └── stream_urls.json  # Cached stream CDN URLs and timestamped expiry tokens
├── uplink/
│   ├── license.php       # DRM ClearKey license exchange proxy
│   └── stream.php        # Stream URL resolver, token refresher & redirect handler
├── .htaccess             # Security rules & virtual path rewrite router
├── index.html            # Web management dashboard & player portal
├── login.html            # OTP authentication web interface
├── playlist.php          # Dynamic M3U playlist generator
└── README.md             # Project documentation
```

---

## 🛠️ Requirements

- **PHP**: `8.1` or higher
- **PHP Extensions**: `curl`, `json`, `openssl`, `mbstring`
- **Web Server**: Apache (`mod_rewrite` enabled) / KSWEB / Nginx / Caddy

---

## 🚀 Setup & Installation

### Option 1: XAMPP / WAMP (Windows / macOS / Linux)

1. Clone or download this repository.
2. Move the project folder into your web root:
   ```text
   C:\xampp\htdocs\jiotv\
   ```
3. Ensure Apache is running with `mod_rewrite` enabled in `httpd.conf`.
4. Open your browser and navigate to:
   ```
   http://localhost/jiotv/login.html
   ```

---

### Option 2: KSWEB (Android)

1. Install **KSWEB** from Google Play Store or official sources.
2. Copy the project files to your KSWEB root directory:
   ```text
   /sdcard/htdocs/jiotv/
   ```
3. Enable **Apache** or **Lighttpd** on port `8080` (or default port `80`).
4. Ensure write permissions are granted to the `/data` directory.
5. Open your mobile browser at:
   ```
   http://localhost:8080/jiotv/login.html
   ```

---

### Option 3: Nginx (Linux Server)

If using Nginx instead of Apache, add these rewrite rules to your server block:

```nginx
location /jiotv/data/ {
    deny all;
    return 403;
}

location ~ ^/jiotv/uplink/(.+)\.(m3u8|mpd)$ {
    try_files $uri /jiotv/uplink/stream.php?id=$1&fmt=$2&$args;
}

location ~ ^/jiotv/uplink/(.+)\.json$ {
    try_files $uri /jiotv/uplink/license.php?id=$1&$args;
}
```

---

## 📱 How to Use

### 1. Authenticate
1. Open `http://<your-server-ip>/jiotv/login.html`.
2. Enter your registered mobile number and click **Send OTP**.
3. Enter the received OTP to authenticate. Your session will be saved locally in `data/session.json`.

---

### 2. Add Playlist to IPTV Players

Copy your dynamic playlist URL and paste it into your favorite IPTV app:

```text
http://<your-server-ip>/jiotv/playlist.php
```

#### Recommended IPTV Players:
- **Android TV / Fire TV:** TiviMate, OTT Navigator, Televizo
- **Mobile (Android / iOS):** IPTV Smarters Pro, OTT Navigator, VLC
- **PC (Windows / Linux / macOS):** Kodi (InputStream Adaptive), VLC Player, SFVIP Player

---

## 📡 API Endpoints

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `POST` | `/api/auth.php?action=send_otp` | Request authentication OTP |
| `POST` | `/api/auth.php?action=verify_otp` | Verify OTP & initialize session |
| `GET` | `/api/auth.php?action=status` | Check local authentication status |
| `GET` | `/api/channels.php` | Fetch & cache full channel lineup |
| `GET` | `/playlist.php` | Render clean UTF-8 `#EXTM3U` playlist |
| `GET` | `/uplink/{channel_id}.m3u8` | Stream redirect for HLS players |
| `GET` | `/uplink/{channel_id}.mpd` | Stream redirect for DASH players |
| `POST` | `/uplink/{channel_id}.json` | ClearKey DRM license acquisition |

---

## 🔒 Security & Privacy

- **Protected Storage:** The `.htaccess` file prevents public web access to the `data/` folder (returns `403 Forbidden`).
- **No Hardcoded Tokens:** All authentication keys are stored locally on your server and are never committed to public repositories.

---

## ⚖️ Disclaimer

This software is developed for **educational and personal research purposes only**. All video streams, logos, and trademarks belong to their respective copyright holders (Jio Platforms Limited). The author assumes no responsibility for misuse of this code.

---

<div align="center">
  <b>Crafted With 💚 by LazyyXD</b>
</div>
