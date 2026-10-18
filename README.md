# Custom Artifactory

A lightweight, self-hosted **artifact repository** you can run with a single Docker container. It combines **nginx WebDAV** (for fast, scriptable upload/download of very large files) with a modern **PHP + Bootstrap web UI** for browsing, searching, uploading, renaming and deleting artifacts — all protected by HTTP Basic Authentication.

Use it to host build outputs, release binaries, installers, ISOs, model weights, database dumps, or any other large files that you want to fetch with `curl` / `wget` from CI pipelines, servers, or your laptop.

---

## Table of Contents

- [Features](#features)
- [Architecture](#architecture)
- [Project Structure](#project-structure)
- [Prerequisites](#prerequisites)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [Using the Repository](#using-the-repository)
  - [Web UI](#web-ui)
  - [Command-line / API usage (WebDAV)](#command-line--api-usage-webdav)
  - [CI/CD examples](#cicd-examples)
- [Data Persistence](#data-persistence)
- [Security Notes](#security-notes)
- [Production Hardening](#production-hardening)
- [Troubleshooting](#troubleshooting)
- [Customization](#customization)
- [Tech Stack](#tech-stack)

---

## Features

**Storage & transfer**
- Upload and download files up to **20 GB** each (configurable).
- Long timeouts (1 hour) for slow links and large transfers.
- Nested folders with automatic creation of intermediate paths on `PUT`.
- Standard **WebDAV** verbs: `PUT`, `DELETE`, `MKCOL`, `COPY`, `MOVE`, `PROPFIND`, `OPTIONS`.

**Web UI**
- Folder tree sidebar plus breadcrumb navigation.
- Live **search / filter**, sortable columns (name, size, modified date) and incremental "load more" for large folders.
- **Drag-and-drop upload** (including whole folders), with a per-file queue, progress, cancel and retry.
- Create folders, **rename/move** and **delete** items from the UI.
- Repository statistics (item counts and total size).
- One-click **copy URL** and ready-made **`curl` / `wget`** download commands.
- **Light / dark theme** toggle, responsive layout with a collapsible sidebar.
- Keyboard shortcuts for power users.

**Operations**
- Single container, no database.
- Credentials supplied through environment variables at start-up.
- Config validation (`nginx -t`) on every container start.

---

## Architecture

```
                        ┌──────────────────────────── Docker container (Ubuntu 24.04) ────────────────────────────┐
                        │                                                                                         │
  Browser ──HTTP:80──►  │   nginx ──► /browser/   ──► PHP-FPM 8.3 ──► index.php (UI, folder create, upload form)   │
                        │     │                                                                                   │
  curl / wget / CI ───► │     └────► /artifacts/  ──► nginx WebDAV module (PUT/GET/DELETE/MKCOL/COPY/MOVE/PROPFIND)│
                        │                                       │                                                 │
                        │                                       ▼                                                 │
                        │                          /var/www/html/artifacts  (artifact storage)                    │
                        └─────────────────────────────────────────────────────────────────────────────────────────┘
```

| Path | Served by | Purpose |
|------|-----------|---------|
| `/browser/` | PHP-FPM | The web UI and its upload / create-folder handler (requires Basic Auth) |
| `/artifacts/` | nginx WebDAV | Raw file storage and transfer endpoint (requires Basic Auth) |

Both locations share the same credentials file (`/etc/nginx/.htpasswd`), which is generated at container start from environment variables.

---

## Project Structure

```
Custom-Artifactory/
├── Dockerfile              # Ubuntu 24.04 + nginx-extras + PHP-FPM 8.3 + apache2-utils
├── docker-install.sh       # Helper that installs Docker & Compose on Ubuntu hosts
├── start.sh                # Container entrypoint: creates credentials, starts PHP-FPM + nginx
├── .dockerignore           # Files excluded from the Docker build context
├── nginx/
│   ├── artifacts           # nginx site config (UI, WebDAV, PHP, upload limits)
│   └── .htpasswd           # Placeholder; real file is generated at runtime
└── html/
    └── browser/
        ├── index.php       # UI markup + backend (listing, upload, mkdir, path sanitising)
        ├── script.js       # Frontend logic (search, sort, uploads, rename, delete, theme)
        └── style.css       # Design tokens, layout, light/dark themes
```

### What each file does

| File | Description |
|------|-------------|
| **`Dockerfile`** | Installs `nginx`, `nginx-extras` (provides the WebDAV extension module for `PROPFIND`/`OPTIONS`), `php-fpm`, `php-cli` and `apache2-utils` (`htpasswd`). Raises PHP upload limits to 20 GB, removes the default nginx site, installs the `artifacts` site, copies the UI, and sets permissions for `www-data`. |
| **`start.sh`** | Fails fast if `ARTIFACT_USER` / `ARTIFACT_PASSWORD` are missing, generates `/etc/nginx/.htpasswd`, injects the credentials into `script.js` (used by the "copy curl/wget" buttons), ensures the artifacts directory exists with correct ownership, starts PHP-FPM, validates and starts nginx in the foreground. |
| **`nginx/artifacts`** | Defines the `/browser/` UI location, the `/artifacts/` WebDAV location, PHP handling, a 20 GB body limit and 3600 s timeouts. Dotfiles are denied. |
| **`html/browser/index.php`** | Renders the UI and handles `POST` actions (`upload`, `mkdir`). Includes path-traversal protection (`safeRel`), filename validation, and directory listing. |
| **`html/browser/script.js`** | Client-side behavior. Uses `XMLHttpRequest` for upload progress, `fetch` with `DELETE`/`MOVE` for WebDAV actions. |
| **`docker-install.sh`** | Convenience script for installing Docker Engine, Buildx and Compose plugins on Ubuntu, then adding the current user to the `docker` group. |

---

## Prerequisites

- A Linux host (Ubuntu recommended) or any machine that can run Docker.
- **Docker** 20.10+ (the included script can install it for you).
- Open inbound port **80** (or whichever host port you map).
- Enough disk space for your artifacts.

### Optional: install Docker automatically (Ubuntu)

```bash
chmod +x docker-install.sh
./docker-install.sh
# Log out and back in so the docker group membership takes effect
```

---

## Quick Start

### 1. Clone / unzip the project

```bash
git clone <your-repo-url> Custom-Artifactory
cd Custom-Artifactory
```

### 2. Update the `server_name` in the nginx config

Open `nginx/artifacts` and set `server_name` to your server's IP address or domain:

```nginx
server_name artifacts.example.com;   # or your server's IP
```

> The file currently contains a hard-coded IP address. Replace it with your own, or use `server_name _;` to match any host.

### 3. Build the image

```bash
docker build -t custom-artifactory .
```

### 4. Run the container

```bash
docker run -d \
  --name artifactory \
  -p 80:80 \
  -e ARTIFACT_USER=admin \
  -e ARTIFACT_PASSWORD='choose-a-strong-password' \
  -v artifactory-data:/var/www/html/artifacts \
  --restart unless-stopped \
  custom-artifactory
```

### 5. Open the UI

Visit `http://<your-server>/browser/` and sign in with the credentials you provided.

### Docker Compose alternative

Create a `docker-compose.yml` next to the Dockerfile:

```yaml
services:
  artifactory:
    build: .
    container_name: artifactory
    ports:
      - "80:80"
    environment:
      ARTIFACT_USER: admin
      ARTIFACT_PASSWORD: ${ARTIFACT_PASSWORD:?set ARTIFACT_PASSWORD}
    volumes:
      - artifactory-data:/var/www/html/artifacts
    restart: unless-stopped

volumes:
  artifactory-data:
```

Then:

```bash
export ARTIFACT_PASSWORD='choose-a-strong-password'
docker compose up -d --build
```

---

## Configuration

### Environment variables

| Variable | Required | Description |
|----------|----------|-------------|
| `ARTIFACT_USER` | Yes | Username for Basic Auth (UI and WebDAV). |
| `ARTIFACT_PASSWORD` | Yes | Password for Basic Auth. |

The container refuses to start if either is missing.

> **Tip:** Avoid the characters `|`, `&`, `\` and `'` in the username/password. `start.sh` writes them into `script.js` with `sed`, and these characters can break the substitution.

### Upload limits

Limits are set in two places and should be kept in sync:

| Setting | Location | Default |
|---------|----------|---------|
| `client_max_body_size` | `nginx/artifacts` (two places) | `20G` |
| `client_body_timeout` | `nginx/artifacts` | `3600s` |
| `upload_max_filesize` / `post_max_size` | `Dockerfile` (PHP ini) | `20G` |
| `max_execution_time` / `max_input_time` | `Dockerfile` (PHP ini) | `3600` |
| `memory_limit` | `Dockerfile` (PHP ini) | `512M` |
| `fastcgi_read_timeout` / `fastcgi_send_timeout` | `nginx/artifacts` | `3600` |

Note: uploads from the web UI are sent as multipart `POST` requests to the PHP handler (so the PHP limits above apply), while `curl -T` uploads go straight to nginx WebDAV (so only the nginx limits apply). Keep both in sync.

### Ports

Change the host port by editing the `-p` flag, e.g. `-p 8080:80`. Remember to include the port in URLs (`http://host:8080/browser/`).

---

## Using the Repository

### Web UI

Open `http://<host>/browser/`.

| Action | How |
|--------|-----|
| Browse folders | Click folders in the table or the sidebar tree |
| Search | Type in the search box or press `/` |
| Sort | Click the Name / Size / Modified column headers |
| Upload | Click **Upload**, drag files/folders onto the page, or press `U` |
| New folder | Click **New folder** or press `N` |
| Rename / move | Use the row action menu |
| Delete | Use the row action menu (confirmation required) |
| Copy download link | Row action menu → copy URL / `curl` / `wget` |
| Toggle theme | Theme button or press `D` |
| Refresh | Refresh button or press `R` |

**Keyboard shortcuts**

| Key | Action |
|-----|--------|
| `/` | Focus search |
| `U` | Open upload dialog |
| `N` | New folder |
| `R` | Refresh |
| `D` | Toggle dark mode |
| `Esc` | Close the sidebar (mobile) |

### Command-line / API usage (WebDAV)

All examples use `-u user:password` for Basic Auth. Replace `HOST` with your server address.

**Upload a file** (intermediate folders are created automatically):

```bash
curl -u admin:PASSWORD -T ./app-1.2.3.tar.gz \
  http://HOST/artifacts/releases/app/1.2.3/app-1.2.3.tar.gz
```

**Download a file:**

```bash
curl -u admin:PASSWORD -O http://HOST/artifacts/releases/app/1.2.3/app-1.2.3.tar.gz
# or
wget --user=admin --password=PASSWORD http://HOST/artifacts/releases/app/1.2.3/app-1.2.3.tar.gz
```

**Create a folder:**

```bash
curl -u admin:PASSWORD -X MKCOL http://HOST/artifacts/releases/
```

**List a folder (PROPFIND):**

```bash
curl -u admin:PASSWORD -X PROPFIND -H "Depth: 1" http://HOST/artifacts/releases/
```

**Delete a file:**

```bash
curl -u admin:PASSWORD -X DELETE http://HOST/artifacts/releases/app/1.2.3/app-1.2.3.tar.gz
```

**Move / rename:**

```bash
curl -u admin:PASSWORD -X MOVE \
  -H "Destination: http://HOST/artifacts/releases/app/latest.tar.gz" \
  http://HOST/artifacts/releases/app/1.2.3/app-1.2.3.tar.gz
```

**Copy:**

```bash
curl -u admin:PASSWORD -X COPY \
  -H "Destination: http://HOST/artifacts/backup/app-1.2.3.tar.gz" \
  http://HOST/artifacts/releases/app/1.2.3/app-1.2.3.tar.gz
```

**Resume a large download:**

```bash
curl -u admin:PASSWORD -C - -O http://HOST/artifacts/isos/big-image.iso
```

### CI/CD examples

**GitHub Actions**

```yaml
- name: Publish artifact
  run: |
    curl --fail -u "${{ secrets.ARTIFACT_USER }}:${{ secrets.ARTIFACT_PASSWORD }}" \
      -T build/app.zip \
      "http://${{ secrets.ARTIFACT_HOST }}/artifacts/builds/${{ github.run_number }}/app.zip"
```

**GitLab CI**

```yaml
publish:
  stage: deploy
  script:
    - curl --fail -u "$ARTIFACT_USER:$ARTIFACT_PASSWORD" -T dist/app.zip "http://$ARTIFACT_HOST/artifacts/builds/$CI_PIPELINE_ID/app.zip"
```

**Jenkins (shell step)**

```bash
curl --fail -u "$ARTIFACT_USER:$ARTIFACT_PASSWORD" -T target/app.jar \
  "http://$ARTIFACT_HOST/artifacts/builds/$BUILD_NUMBER/app.jar"
```

---

## Data Persistence

Artifacts live in `/var/www/html/artifacts` inside the container. **Without a volume, they are lost when the container is removed.** Always mount a volume or bind mount:

```bash
# Named volume
-v artifactory-data:/var/www/html/artifacts

# Bind mount to a host directory
-v /srv/artifacts:/var/www/html/artifacts
```

If you use a bind mount, make sure the host directory is writable by the container's `www-data` user (UID/GID 33):

```bash
sudo chown -R 33:33 /srv/artifacts
```

(`start.sh` also runs `chown -R www-data:www-data /var/www/html` on every start.)

### Backups

```bash
# Archive the volume contents
docker run --rm -v artifactory-data:/data -v "$PWD":/backup ubuntu \
  tar czf /backup/artifacts-backup.tar.gz -C /data .
```

---

## Security Notes

Please read this section before exposing the service to the internet.

1. **Credentials are visible to anyone who can log in.** `start.sh` writes `ARTIFACT_USER` / `ARTIFACT_PASSWORD` into `html/browser/script.js` so the UI can generate ready-to-run `curl`/`wget` commands. Any authenticated user can view the page source and read them. Since the same credentials grant full read/write/delete access, treat every user of the UI as an administrator, or modify the project to use a separate **read-only** account for this feature.
2. **Remove any hard-coded credentials from `script.js` before committing.** The repository's copy of `script.js` contains default `DL_USER` / `DL_PASS` values that are overwritten at container start. Replace them with neutral placeholders (e.g. `'changeme'`) and rotate any password that has already been committed or shared.
3. **HTTP only.** The shipped configuration listens on port 80 without TLS, so Basic Auth credentials and files travel in clear text. Put the service behind HTTPS (see below) for anything beyond a trusted LAN.
4. **Single shared account.** There are no per-user roles or audit logs.
5. **Path traversal protection** is implemented for UI actions (`safeRel` rejects `..` and null bytes), and dotfiles are denied by nginx. Uploaded filenames beginning with `.` are rejected.
6. **No file-type restrictions.** Anything can be uploaded. Files under `/artifacts/` are served as static downloads; do not enable PHP execution in that directory.

---

## Production Hardening

**Enable HTTPS with a reverse proxy** — for example Caddy, Traefik, or an nginx/Certbot front end:

```caddyfile
artifacts.example.com {
    request_body {
        max_size 20GB
    }
    reverse_proxy artifactory:80 {
        transport http {
            read_timeout 1h
            write_timeout 1h
        }
    }
}
```

**Other recommendations**
- Restrict access by IP / VPN / firewall (`ufw allow from <cidr> to any port 80`).
- Use a long, random password and rotate it regularly.
- Run a read-only account for download-only consumers (requires extending `start.sh` to create a second `.htpasswd` entry and a separate nginx location).
- Set Docker resource limits (`--memory`, `--cpus`) and a log rotation policy.
- Schedule regular backups of the artifacts volume.
- Pin the base image and rebuild periodically to pick up OS security updates.
- Monitor disk usage — uploads of up to 20 GB each can fill a disk quickly.

---

## Troubleshooting

| Symptom | Likely cause / fix |
|---------|--------------------|
| Container exits immediately with `ARTIFACT_USER environment variable is required` | Pass both `-e ARTIFACT_USER=...` and `-e ARTIFACT_PASSWORD=...`. |
| `401 Unauthorized` | Wrong username/password; verify the values used when the container was **started** (they are regenerated on each start). |
| `413 Request Entity Too Large` | Increase `client_max_body_size` in `nginx/artifacts` and any reverse proxy in front of it, then rebuild. |
| `Upload failed (error code 1/2)` | PHP upload limits too low; adjust the ini values in the `Dockerfile` and rebuild. |
| `Could not save file (check permissions)` | The artifacts directory is not writable by `www-data`. Fix ownership on bind mounts (`chown -R 33:33`). |
| `405 Not Allowed` on `PROPFIND` / `OPTIONS` | `nginx-extras` (the `dav_ext` module) is missing — ensure the image was built from this Dockerfile. |
| Uploads stall or time out through a proxy | Increase proxy read/send timeouts and body-size limits to match nginx (`3600s`, `20G`). |
| UI loads without styling or icons | The UI loads Bootstrap and Bootstrap Icons from `cdn.jsdelivr.net`; the *browser* needs internet access to that CDN (or vendor the files locally). |
| `nginx: configuration file ... test failed` | Check `nginx/artifacts` syntax; view logs with `docker logs artifactory`. |
| Changes to `nginx/artifacts` or `html/` not visible | They are copied at build time; rebuild the image with `docker build` / `docker compose up -d --build`. |

**Useful commands**

```bash
docker logs -f artifactory                       # follow logs
docker exec -it artifactory bash                 # shell into the container
docker exec artifactory nginx -t                 # validate nginx config
docker exec artifactory ls -lah /var/www/html/artifacts
```

---

## Customization

- **Branding / colors:** edit the CSS variables under `:root` in `html/browser/style.css` (e.g. `--primary`, `--sidebar-bg`), and the title/header markup in `index.php`.
- **Max tree depth / page size:** `buildTree(..., $maxDepth = 5)` in `index.php` controls the sidebar tree depth; `PAGE = 100` in `script.js` controls how many rows load per "load more".
- **Different storage location:** change `$baseDir` in `index.php`, the `root`/location settings in `nginx/artifacts`, and the mount path in your `docker run` command — they must stay consistent.
- **Self-hosted UI assets:** download Bootstrap 5.3.3 and Bootstrap Icons 1.11.3 into `html/browser/` and update the `<link>` / `<script>` tags in `index.php` for fully offline operation.

---

## Tech Stack

- **Base image:** Ubuntu 24.04
- **Web server:** nginx (`nginx-extras` with WebDAV and `dav_ext`)
- **Backend:** PHP 8.3 (PHP-FPM)
- **Frontend:** Vanilla JavaScript, Bootstrap 5.3.3, Bootstrap Icons 1.11.3
- **Auth:** HTTP Basic Authentication (`htpasswd`)
- **Packaging:** Docker

---

## License

No license file is included in this repository. Add one (for example MIT or Apache-2.0) if you plan to share or distribute it.
