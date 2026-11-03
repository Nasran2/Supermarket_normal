<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Login</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #0a0a0a;
            font-family: system-ui, -apple-system, sans-serif;
        }
        .card {
            background: #111;
            border: 1px solid #222;
            border-radius: 12px;
            padding: 40px 36px;
            width: 100%;
            max-width: 380px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.8);
        }
        .logo {
            text-align: center;
            margin-bottom: 28px;
        }
        .logo-icon {
            display: inline-flex;
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #dc2626, #991b1b);
            border-radius: 14px;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            margin-bottom: 12px;
        }
        h1 { color: #f1f5f9; font-size: 20px; font-weight: 700; }
        .subtitle { color: #64748b; font-size: 13px; margin-top: 4px; }
        .badge {
            display: inline-block;
            background: #7f1d1d;
            color: #fca5a5;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
            letter-spacing: 0.05em;
            margin-top: 8px;
            text-transform: uppercase;
        }
        .form-group { margin-bottom: 18px; }
        label { display: block; color: #94a3b8; font-size: 13px; margin-bottom: 6px; font-weight: 500; }
        input[type=text], input[type=password] {
            width: 100%;
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-radius: 8px;
            color: #f1f5f9;
            font-size: 14px;
            padding: 10px 14px;
            outline: none;
            transition: border-color 0.2s;
        }
        input:focus { border-color: #dc2626; }
        .error-msg { color: #f87171; font-size: 12px; margin-top: 5px; }
        .btn {
            width: 100%;
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s;
            margin-top: 6px;
        }
        .btn:hover { opacity: 0.88; }
        .footer-note { text-align: center; color: #334155; font-size: 12px; margin-top: 24px; }
        .alert-error {
            background: #450a0a;
            border: 1px solid #7f1d1d;
            color: #fca5a5;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 13px;
            margin-bottom: 18px;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">
            <div class="logo-icon">🔐</div>
            <h1>Super Admin</h1>
            <p class="subtitle">Restricted access panel</p>
            <span class="badge">⚠ Authorized personnel only</span>
        </div>

        @if ($errors->any())
            <div class="alert-error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('superadmin.login.post') }}">
            @csrf
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username"
                    value="{{ old('username') }}" autocomplete="username" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn">Sign In</button>
        </form>

        <p class="footer-note">This panel is hidden when SUPER_ADMIN=false</p>
    </div>
</body>
</html>

