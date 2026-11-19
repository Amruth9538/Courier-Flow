# CourierFlow — Parcel Delivery Management System

CourierFlow is a native PHP web application for managing parcel deliveries. Administrators manage riders and parcels, riders share their GPS location and update delivery status, and recipients can view a public parcel tracking page with route history when route data is available.

![Admin dashboard](docs/screenshots/02-admin-dashboard.png)

## Contents

- [Features](#features)
- [Technology stack](#technology-stack)
- [Architecture](#architecture)
- [Application flow](#application-flow)
- [Methodology](#methodology)
- [Repository layout](#repository-layout)
- [Local setup](#local-setup)
- [AWS deployment: Amazon Linux EC2 and S3](#aws-deployment-amazon-linux-ec2-and-s3)
- [Current implementation notes](#current-implementation-notes)
- [Screenshots](#screenshots)
- [License](#license)

## Features

- Admin dashboard, rider directory, parcel management, and delivery reports
- Customer/admin shipment booking, saved address book, commodities, package-weight calculations, and printable invoice preview
- Rider accounts, online/offline state, assigned delivery list, and browser GPS updates
- Sequential rider dispatch: each rider receives one active parcel at a time; the oldest pending parcel is assigned after the current delivery is completed
- Parcel status history and delivery proof photo uploads. Rider updates follow Pending → In Transit → Out for Delivery → Delivered; a failed delivery can be retried, and active deliveries may be cancelled.
- Public tracking page showing parcel status, rider position, and available planned/actual route history
- Malaysia address geocoding through OpenStreetMap Nominatim
- Leaflet maps using OpenStreetMap tiles
- OpenRouteService (`driving-car`) route calculation endpoint and saved route snapshots

## Technology stack

| Area | Technology |
| --- | --- |
| Server | Native PHP (no PHP framework; no Composer or Node build is required) |
| Database | MySQL or MariaDB, accessed through PHP PDO |
| Browser UI | HTML, CSS, JavaScript |
| Maps | Leaflet and OpenStreetMap |
| Address lookup | OpenStreetMap Nominatim |
| Road directions | OpenRouteService Directions API |
| File uploads | Local `uploads/proofs/` directory |
| AWS deployment target | Amazon Linux 2023 on EC2; private S3 bucket for the release archive |

## Architecture

```mermaid
flowchart LR
    Browser["Admin, rider, or recipient browser"]
    Apache["Apache HTTP Server"]
    PHP["CourierFlow PHP pages and API endpoints"]
    DB[("MySQL / MariaDB on the EC2 instance")]
    Uploads["EC2 local uploads/proofs"]
    S3[("Private Amazon S3 bucket<br/>release archive")]
    ORS["OpenRouteService"]
    Nominatim["OpenStreetMap Nominatim"]
    OSM["OpenStreetMap tile servers"]

    Browser -->|"HTTPS / HTTP requests, GPS, map rendering"| Apache
    Apache --> PHP
    PHP -->|"PDO queries and updates"| DB
    PHP -->|"Proof photo files"| Uploads
    PHP -->|"Directions API requests (when invoked)"| ORS
    PHP -->|"Malaysia address geocoding"| Nominatim
    Browser -->|"Map tiles"| OSM
    S3 -->|"Release archive downloaded during deployment"| Apache
```

The application is a traditional server-rendered PHP site. PHP handles pages, sessions, authorization, database operations, and JSON endpoints. JavaScript in the browser requests GPS permission and updates maps. On the AWS setup below, Apache, PHP, and the database run on one EC2 instance; S3 is used to transfer the release bundle, not to execute PHP.

## Application flow

The following diagram shows the main operational flow. Solid connections represent requests or data writes; external mapping services are optional dependencies used by relevant screens/endpoints.

```mermaid
flowchart TD
    Admin["Admin browser"]
    Rider["Rider browser"]
    Recipient["Recipient browser"]
    App["Apache + PHP application"]
    Auth["Session and role checks"]
    AdminWork["Parcel and rider management"]
    RiderWork["Rider dashboard and delivery updates"]
    Tracking["Public tracking page"]
    LocationAPI["api/location.php"]
    StatusAPI["api/status.php"]
    TrackingAPI["api/route-history.php"]
    Database[("MySQL / MariaDB<br/>users, parcels, riders,<br/>locations and route history")]
    Proofs["Local uploads/proofs"]
    Geocoder["Nominatim geocoding"]
    Router["OpenRouteService directions"]
    MapTiles["OpenStreetMap tiles"]

    Admin -->|"Sign in / manage deliveries"| App
    Rider -->|"Sign in / delivery work"| App
    Recipient -->|"Tracking number"| Tracking
    App --> Auth
    Auth --> AdminWork
    Auth --> RiderWork
    AdminWork -->|"Create/update parcel and assignments"| Database
    RiderWork -->|"Browser GPS"| LocationAPI
    RiderWork -->|"Status update"| StatusAPI
    LocationAPI -->|"Save current and historical coordinates"| Database
    StatusAPI -->|"Save delivery status/history"| Database
    RiderWork -->|"Multipart proof-photo form"| App
    App -->|"Save proof image"| Proofs
    App -->|"Save proof metadata"| Database
    Tracking --> TrackingAPI
    TrackingAPI -->|"Read parcel and available tracking data"| Database
    TrackingAPI --> Recipient
    AdminWork -.->|"Address lookup when requested"| Geocoder
    RiderWork -.->|"Route request when enabled/configured"| Router
    Admin -->|"Render map"| MapTiles
    Rider -->|"Render map"| MapTiles
    Recipient -->|"Render tracking map"| MapTiles
```

In sequence:

1. An admin or rider signs in. PHP validates the session and role before rendering protected pages or accepting protected API requests. A recipient can visit `track.php` without signing in and provide a tracking number.
2. The admin creates and manages parcels and riders. Parcel/rider data is stored in MySQL/MariaDB.
3. When online, the rider dashboard requests browser location access and sends coordinates to `api/location.php`. PHP updates the rider's current position and records location history in the database.
4. Rider status changes are submitted through the rider dashboard or the `api/status.php` endpoint. The server validates each next status and the one-active-parcel rule; delivery proof images, when uploaded, are saved under `uploads/proofs/` and status/history data is stored in the database.
5. The tracking page requests `api/route-history.php`, which reads parcel status and available location/route data. The page renders available details and locations on a Leaflet map.
6. When a relevant lookup or route endpoint is used and configured, PHP calls Nominatim for Malaysia address geocoding or OpenRouteService for road directions. Map tiles are fetched by the browser from OpenStreetMap.

## Methodology

CourierFlow follows a lightweight, role-based, request/response methodology:

1. **Role-oriented interaction:** Admin, rider, and public recipient screens present different actions. Session checks and role checks protect authenticated pages and API operations.
2. **Server-rendered application:** PHP serves pages and handles business operations. Browser JavaScript provides interactive maps, GPS capture, and asynchronous requests to PHP JSON endpoints.
3. **Relational persistence:** MySQL/MariaDB is the system of record for users, riders, parcels, delivery status, and location/route history. PDO prepared statements are used for database operations.
4. **Event-by-request updates:** Rider GPS and status changes are submitted as browser requests. The server persists the latest state and relevant history, which admin and tracking views read back.
5. **External service integration:** Geocoding and driving directions are requested from external services by PHP when the corresponding code path is active and credentials/connectivity are available. Leaflet displays maps in the browser using OpenStreetMap tiles.
6. **File handling:** Proof images are uploaded to the application host's `uploads/proofs/` directory; their metadata is associated with delivery records in the database. The current implementation does not store these files in S3.
7. **Incremental deployment:** The application can run on a local Apache/PHP/MySQL environment or a single Amazon Linux EC2 host. S3 in the documented AWS setup stores and transfers a private application release archive; it is not the PHP runtime.

This describes the current implementation rather than a claim of continuous real-time streaming or a fully managed cloud architecture. See [Current implementation notes](#current-implementation-notes) for known feature/schema limitations.

## Repository layout

| Path | Purpose |
| --- | --- |
| `admin/` | Admin parcel, rider, and reports pages |
| `api/` | JSON endpoints for locations, routes, status, riders, and route history |
| `assets/css/`, `assets/js/`, `assets/images/` | Front-end styles, scripts, and images |
| `includes/` | Shared page fragments |
| `database.sql` | MySQL/MariaDB schema and initial admin/rider records |
| `database_shipments.sql` | Additive schema for shipment bookings, addresses, packages, commodities, and status history |
| `database_shipments_validation.sql` | Adds GST, pickup-contact, and commodity-origin fields to existing shipment installations |
| `database_split.sql` | Adds domestic/international separation, domestic rate slabs, rider assignment, and Customs Clearance status |
| `database_split_samples.sql` | Optional idempotent demo data for one domestic and one international booking |
| `database.example.php` | Local/server database configuration template |
| `track.php` | Public parcel tracking page |
| `uploads/proofs/` | Runtime destination for delivery proof images (not included as app code) |
| `uploads/invoices/` | Runtime destination for uploaded shipment invoice PDFs |

## Local setup

### Requirements

- XAMPP on Windows, or another Apache + PHP + MySQL/MariaDB environment
- PHP PDO MySQL and cURL extensions enabled
- A browser; browser GPS is available on `localhost` or a secure HTTPS origin
- Optional OpenRouteService API key for route calculations

### Install

1. Clone or copy the repository into the web root, for example:

   ```text
   C:\xampp\htdocs\parcel_delivery_system
   ```

2. Copy `database.example.php` to `database.php` in the project root:

   ```powershell
   Copy-Item database.example.php database.php
   ```

3. Edit `database.php` with your local MySQL credentials. The XAMPP defaults are shown:

   ```php
   $db_host = '127.0.0.1';
   $db_user = 'root';
   $db_pass = '';
   $db_name = 'parcel_delivery_system';
   $ors_api_key = ''; // Optional: OpenRouteService key
   ```

   `database.php` is ignored by Git. Never commit real passwords or API keys.

4. Start **Apache** and **MySQL** in the XAMPP Control Panel.
5. Import `database.sql` into MySQL using phpMyAdmin (`http://localhost/phpmyadmin`) or the MySQL command line. The script creates the `parcel_delivery_system` database and its initial admin/rider records. Do not rely on `install.php`: it is not part of the tracked release.
6. Import `database_shipments.sql` into the same database. It creates the shipment tables with `CREATE TABLE IF NOT EXISTS` and does not drop existing tables or shipment data.
7. For an existing shipment installation, also import `database_shipments_validation.sql` to add GST, pickup, and commodity fields used by strict booking validation.
8. Import `database_split.sql` to separate domestic and international bookings, create domestic rates, and add the international Customs Clearance status. Existing shipments are classified as international.
9. Optionally import `database_split_samples.sql` to seed one domestic and one international example booking. It attaches records to the first admin account; the script can be rerun without creating duplicate samples.
10. Ensure upload directories exist and are writable by Apache:

   ```powershell
   New-Item -ItemType Directory -Force uploads\proofs
   New-Item -ItemType Directory -Force uploads\invoices
   ```

11. Open `http://localhost/parcel_delivery_system/`.

### Domestic and international bookings

After importing `database_shipments.sql` and `database_split.sql` (plus `database_shipments_validation.sql` for an existing installation), a logged-in customer or administrator can book domestic parcels at `parcels-management.php` and international shipments at `create-shipment.php`. Domestic bookings require India-to-India addresses and use `DM` tracking numbers. International bookings require an Indian shipper and non-Indian receiver and use `IN` tracking numbers. Neither form accepts a user-selected shipment type; each save handler sets it on the server.

Use `parcel-list.php` for domestic bookings and `shipment-list.php` for international bookings. Both lists support tracking search, status filtering, and date filtering. Customers see their own bookings, admins see all, and riders can only access bookings assigned to their rider profile. Admins can advance international shipments through Booked, Picked Up, Customs Clearance, In Transit, Out for Delivery, and Delivered; status transitions are recorded in history.

Domestic rates are read from `domestic_rates`; administrators can edit rate slabs directly in that table. The domestic form calculates chargeable weight as the greater of actual and volumetric weight. International invoice uploads accept PDF files up to 2 MB and are protected by `uploads/invoices/.htaccess`. Browser input filtering and validation are backed by server-side validation.

#### Manual separation checks

1. Sign in as an admin or customer and book a domestic parcel with India as both sender and receiver country. Confirm a `DM` tracking number appears in `parcel-list.php`.
2. Try to submit an India-to-India shipment through `create-shipment.php` by tampering with the request. The server must reject it.
3. Try to submit a foreign receiver country through `parcels-management.php` by tampering with the hidden country value or POST body. The server must reject it.
4. Book an international shipment with India as the shipper and a foreign receiver. Confirm an `IN` tracking number appears only in `shipment-list.php`.

The initial accounts created by the checked-in SQL are:

| Role | Email | Password |
| --- | --- | --- |
| Admin | `admin@example.com` | `password` |
| Rider | `rider@example.com` | `password` |

### Optional routing key

Set the OpenRouteService key in the `$ors_api_key` value in the ignored `database.php`, or set `ORS_API_KEY` in the server environment. A non-empty `$ors_api_key` takes precedence; when it is empty, the environment variable is used. Do not place a real key in tracked source files. If OpenRouteService is not configured or rejects a request, the route endpoint falls back to the public OSRM demo service, which does not require a key. The public demo service is best-effort and rate-limited; use a contracted provider or self-hosted OSRM service for production traffic.

## AWS deployment: Amazon Linux EC2 and S3

This is a low-cost single-instance demonstration deployment, not a highly available production architecture. PHP must run on EC2; an S3 static website endpoint cannot execute this application. The S3 bucket below is private and is used to hold/download the release archive. Proof photos continue to be stored on the EC2 instance.

### Before you start

- An AWS account with billing enabled. Free Tier eligibility, instance sizes, included hours/storage, and account offers vary by account creation date and region. Confirm the current offer and pricing in the AWS Console before launch.
- A region close to the intended users.
- A release ZIP containing the tracked application files. Exclude local `database.php`, credentials/API keys, `.git`, local database dumps, and any private uploads. Arrange the ZIP so the application files (including `database.sql` and `database.example.php`) are at its top level.
- An OpenRouteService key if road routing is required.

Set a billing budget/alert in **Billing and Cost Management** before launching. Budget alerts notify you; they do not automatically stop resources. Avoid creating a NAT Gateway or other chargeable services for this single-instance setup. Public IPv4 addresses, data transfer, storage, and services outside your account's offer may incur charges.

### 1. Create a private S3 release bucket

1. In the AWS Console, open **S3 → Create bucket**.
2. Choose a globally unique bucket name and the same AWS Region you will use for EC2.
3. Keep **Block all public access** enabled and leave the bucket private. Do not enable static website hosting.
4. Create the bucket, then choose **Upload** and upload the release ZIP, for example `courierflow-release.zip`.
5. Keep the bucket name and ZIP object key for the EC2 download step.

### 2. Create a least-privilege EC2 role for S3 download

1. Open **IAM → Roles → Create role**. Select **AWS service → EC2** as the trusted entity.
2. Add a permissions policy that allows `s3:GetObject` on only the release object (and `s3:ListBucket` on only the release bucket if needed). Do not make the bucket public or put long-lived AWS access keys on the instance.
3. Name the role (for example, `CourierFlowEc2ReleaseRead`) and create it.
4. If you use an inline/custom policy, scope its resources to your bucket ARN and the release object's ARN; do not grant broad S3 access unnecessarily.

### 3. Launch Amazon Linux EC2

1. Open **EC2 → Instances → Launch instances**.
2. Set a name such as `courierflow-web`.
3. For **Application and OS Images (Amazon Machine Image)**, select **Amazon Linux 2023 AMI**. Choose an instance type explicitly marked eligible for your account's current Free Tier offer; availability and names vary by region/account.
4. Create or select a key pair, unless you will connect using an available EC2 Instance Connect option.
5. Create a security group with inbound rules:
   - SSH (TCP 22): **My IP** only, if using SSH.
   - HTTP (TCP 80): `0.0.0.0/0` (and `::/0` if using IPv6).
   - HTTPS (TCP 443): `0.0.0.0/0` (and `::/0` if using IPv6), for the later TLS setup.
   - Do **not** expose MySQL/MariaDB port 3306 to the internet.
6. Expand **Advanced details → IAM instance profile** and select the EC2 role created in step 2.
7. Launch the instance. Wait for status checks to pass and note its public IPv4 address or DNS name.

### 4. Connect and install the web/database services

Use **EC2 → Instances → Connect** (EC2 Instance Connect, if available) or SSH with your downloaded key. On the Amazon Linux shell, run:

```bash
sudo dnf update -y
sudo dnf install -y httpd php php-mysqlnd php-curl mariadb105-server unzip
sudo systemctl enable --now httpd mariadb
php -v
aws --version
```

Amazon Linux images may differ in which optional utilities are preinstalled. If the `aws` command is unavailable, install AWS CLI v2 using AWS's current Amazon Linux instructions before continuing; do not configure permanent access keys on the instance.

### 5. Download and unpack the application from S3

Replace the bucket and object-key placeholders with the values from step 1:

```bash
aws s3 cp s3://YOUR-PRIVATE-BUCKET/courierflow-release.zip /tmp/courierflow-release.zip
sudo unzip -o /tmp/courierflow-release.zip -d /var/www/html
```

The ZIP should have application files at its root, not an extra enclosing directory. Confirm that `/var/www/html/index.php`, `/var/www/html/database.sql`, and `/var/www/html/database.example.php` exist.

### 6. Create the application database and account

Import the checked-in schema and demo admin/rider records:

```bash
sudo mysql < /var/www/html/database.sql
```

Then open the local database shell:

```bash
sudo mysql
```

At the MariaDB prompt, create a database user with a unique strong password (replace the example value) and grant it access only to the application database:

```sql
CREATE USER 'courierflow_app'@'localhost' IDENTIFIED BY 'REPLACE_WITH_A_LONG_RANDOM_PASSWORD';
GRANT ALL PRIVILEGES ON parcel_delivery_system.* TO 'courierflow_app'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 7. Configure PHP and file permissions

Copy the template, then edit the server-only config:

```bash
cd /var/www/html
sudo cp database.example.php database.php
sudo nano database.php
```

Set the values in `database.php` to the database name/user/password created above, use `localhost` for the database host, and optionally set `$ors_api_key` to your OpenRouteService key. Keep this file private and never put it in the S3 release ZIP or source control.

Create the proof upload directory and give Apache write access only to that directory:

```bash
sudo mkdir -p /var/www/html/uploads/proofs
sudo chown -R root:apache /var/www/html
sudo find /var/www/html -type d -exec chmod 755 {} \;
sudo find /var/www/html -type f -exec chmod 644 {} \;
sudo chown -R apache:apache /var/www/html/uploads
sudo chmod -R 750 /var/www/html/uploads
sudo chown root:apache /var/www/html/database.php
sudo chmod 640 /var/www/html/database.php
```

If SELinux is enforcing, allow Apache to connect to the local database:

```bash
sudo setsebool -P httpd_can_network_connect_db 1
```

### 8. Test the site

1. In a browser, open `http://EC2-PUBLIC-IP/`. Confirm that the home/login page loads.
2. Log in using the demo admin account (`admin@example.com` / `password`) and verify the dashboard and database connection.
3. Change the demo passwords immediately. Do not use the demo accounts for real deliveries.
4. Check **EC2 → Security Groups** if the page cannot be reached. Do not solve connectivity issues by opening port 3306.
5. Review the Apache error log if the page fails:

   ```bash
   sudo tail -n 100 /var/log/httpd/error_log
   ```

### 9. Configure HTTPS before using rider GPS

Browser geolocation requires a secure context: HTTPS, or localhost for local development. An EC2 public IP over HTTP is not enough for rider GPS. Point a domain name to the instance, obtain/configure a TLS certificate (for example, with a certificate authority such as Let's Encrypt), enable HTTPS in Apache, and allow inbound TCP 443. Do not collect real rider locations until HTTPS is working.

### S3 scope and backups

S3 is used here as a private release artifact store. The current PHP upload code writes proof photos to the EC2 `uploads/proofs/` directory; it does not upload those photos to S3. Moving proofs to S3 requires an application change and IAM permissions. Consider a separate, restricted backup process for database dumps and uploads; do not place database credentials or unencrypted sensitive data in a public bucket.

## Current implementation notes

- The tracked `database.sql` schema defines only the `admin` and `rider` roles and does not define the `customer_id`, `notifications`, or OTP tables referenced by some customer-related pages and code. Customer registration/dashboard, customer notifications, and OTP-related flows therefore need matching schema migrations before they can be relied on.
- The rider dashboard requests road routes from `api/route.php` using the rider's live GPS location and the active parcel destination. It refreshes after meaningful movement or one minute and displays distance and estimated travel time. Live road directions require an OpenRouteService API key; route errors are shown in the dashboard.
- The database schema seeds demo credentials. Change them before deployment and treat all location, recipient, and proof-photo data as sensitive.
- Shipment booking requires running the additive [`database_shipments.sql`](database_shipments.sql) migration after the base schema. Existing shipment installations should also run [`database_shipments_validation.sql`](database_shipments_validation.sql). Uploaded shipment invoices are stored in `uploads/invoices/` on the application host.
- The single EC2 instance is a learning/demo deployment. It has no automatic scaling, managed database, multi-AZ failover, or built-in S3 photo storage.

## Screenshots

| Login | Admin dashboard |
| --- | --- |
| ![Login](docs/screenshots/01-login.png) | ![Admin dashboard](docs/screenshots/02-admin-dashboard.png) |

| Parcel management | Rider directory |
| --- | --- |
| ![Parcels](docs/screenshots/03-admin-parcels.png) | ![Riders](docs/screenshots/04-admin-riders.png) |

| Delivery reports & route history | Rider dashboard |
| --- | --- |
| ![Reports](docs/screenshots/05-admin-reports.png) | ![Rider dashboard](docs/screenshots/06-rider-dashboard.png) |

![Public tracking](docs/screenshots/07-public-tracking.png)

More images are in [`docs/screenshots/`](docs/screenshots/).

## License

This project is released under the [MIT License](LICENSE).
