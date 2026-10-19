<?php
declare(strict_types=1);
require __DIR__.'/app.php';

// If already logged in, redirect to main page
if (!empty($_SESSION['lufly_auth']) && $_SESSION['lufly_auth'] === true) {
    header('Location: index.php');
    exit;
}

// Load config to get password
$appPassword = $config['app_password'] ?? '';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = (string)($_POST['password'] ?? '');
    
    if ($appPassword === '' || $input === '') {
        $error = 'الرجاء إدخال كلمة المرور';
    } elseif (hash_equals($appPassword, $input)) {
        session_regenerate_id(true);
        $_SESSION['lufly_auth'] = true;
        $_SESSION['lufly_login_time'] = time();
        header('Location: index.php');
        exit;
    } else {
        $error = 'كلمة المرور غير صحيحة';
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LUFLY - تسجيل الدخول</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            background: linear-gradient(135deg, #1F9E9E 0%, #147070 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-container {
            background: white;
            padding: 48px 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 420px;
            text-align: center;
        }
        .login-logo {
            margin-bottom: 32px;
        }
        .login-logo img {
            height: 60px;
        }
        .login-title {
            font-size: 1.8rem;
            font-weight: 800;
            color: #1a1a2e;
            margin-bottom: 8px;
        }
        .login-subtitle {
            color: #6b7280;
            font-size: 0.95rem;
            margin-bottom: 32px;
        }
        .input-group {
            margin-bottom: 24px;
            text-align: right;
        }
        .input-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #374151;
            font-size: 0.9rem;
        }
        .input-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 1rem;
            outline: none;
            transition: border-color 0.2s;
            direction: ltr;
            text-align: left;
        }
        .input-group input:focus {
            border-color: #1F9E9E;
        }
        .login-btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #1F9E9E 0%, #147070 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.05rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }
        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(31, 158, 158, 0.4);
        }
        .error-message {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .footer-text {
            margin-top: 24px;
            color: #9ca3af;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-logo">
            <img src="assets/logo.png" alt="LUFLY">
        </div>
        <h1 class="login-title">LUFLY Catalog</h1>
        <p class="login-subtitle">سجل الدخول للوصول إلى النظام</p>
        
        <?php if ($error): ?>
            <div class="error-message">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="input-group">
                <label for="password">كلمة المرور</label>
                <input type="password" id="password" name="password" required autofocus>
            </div>
            <button type="submit" class="login-btn">تسجيل الدخول</button>
        </form>
        
        <p class="footer-text">نظام إدارة كتالوج LUFLY</p>
    </div>
</body>
</html>
