
<?php
require_once __DIR__ . '/config.php';

// If already logged in, go to the correct dashboard
if (is_logged_in()) {
    $role = $_SESSION['role'] ?? '';

    if ($role === 'admin') {
        redirect('dashboard.php');
    } elseif ($role === 'rider') {
        redirect('rider-dashboard.php');
    } elseif ($role === 'customer') {
        redirect('customer-dashboard.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CourierFlow | Welcome</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
    font-family: Arial, sans-serif;
    min-height: 100vh;
    color: white;
    background:
        linear-gradient(rgba(2, 15, 30, 0.50), rgba(2, 15, 30, 0.50)),
        url('assets/images/courierflow1-bg.png');
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    background-attachment: fixed;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 25px;
}

        .container {
            width: 100%;
            max-width: 1050px;
            text-align: center;
        }

        .logo {
    font-size: 38px;
    font-weight: bold;
    color: #42d9ff;
    letter-spacing: 2px;
    margin-bottom: 12px;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.8);
}

.tagline {
    color: #ffffff;
    font-size: 16px;
    margin-bottom: 45px;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.8);
}

h1 {
    font-size: 34px;
    margin-bottom: 12px;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.8);
}

.description {
    color: #e6eefc;
    margin-bottom: 35px;
    line-height: 1.6;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.8);
}

        .cards {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 22px;
        }

        .card {
    text-decoration: none;
    color: white;
    background: rgba(8, 20, 50, 0.85);
    border: 1px solid rgba(66, 217, 255, 0.5);
    border-radius: 16px;
    padding: 30px 20px;
    transition: 0.3s;
    backdrop-filter: blur(6px);
}

        .card:hover {
            transform: translateY(-6px);
            background: rgba(66,217,255,0.12);
            border-color: #42d9ff;
            box-shadow: 0 8px 30px rgba(0,190,255,0.15);
        }

        .icon {
            font-size: 42px;
            margin-bottom: 15px;
        }

        .card h2 {
            color: #42d9ff;
            font-size: 22px;
            margin-bottom: 10px;
        }

        .card p {
    color: #e6eefc;
    font-size: 14px;
    line-height: 1.5;
}

footer {
    margin-top: 40px;
    color: #d5e0f2;
    font-size: 13px;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.8);
}

        @media (max-width: 600px) {
            .cards {
                grid-template-columns: 1fr;
            }

            h1 {
                font-size: 27px;
            }

            .logo {
                font-size: 30px;
            }
        }
    </style>
</head>

<body>
    <div class="container">

        <div class="logo">COURIERFLOW</div>
        <p class="tagline">Smart Delivery • Real-Time Tracking • Faster Service</p>

        <h1>Welcome to CourierFlow</h1>

        <p class="description">
            Your complete parcel delivery and shipment tracking platform.
            Please select an option to continue.
        </p>

        <div class="cards">

            <a href="login.php?role=admin" class="card">
                <div class="icon">🛡️</div>
                <h2>Admin Login</h2>
                <p>Manage parcels, riders, customers and deliveries.</p>
            </a>

            <a href="login.php?role=rider" class="card">
                <div class="icon">🛵</div>
                <h2>Rider Login</h2>
                <p>View assigned parcels and manage your deliveries.</p>
            </a>

<a href="rider-register.php" class="card">
    <div class="icon">📝</div>
    <h2>Rider Register</h2>
    <p>Create a new rider account and submit your details for admin approval.</p>
</a>


            <a href="customer-register.php" class="card">
                <div class="icon">📝</div>
                <h2>Customer Register</h2>
                <p>Create a new account to use CourierFlow services.</p>
            </a>

            <a href="login.php?role=customer" class="card">
                <div class="icon">📦</div>
                <h2>Customer Login</h2>
                <p>Track your parcels and check delivery updates.</p>
            </a>

        </div>

        <footer>
            © 2026 CourierFlow. All Rights Reserved.
        </footer>

    </div>
</body>
</html>
