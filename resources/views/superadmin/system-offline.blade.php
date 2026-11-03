<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 Internal Server Error</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #111;
            font-family: system-ui, -apple-system, sans-serif;
            color: #e2e8f0;
        }
        .container { text-align: center; padding: 40px 24px; }
        .code { font-size: 120px; font-weight: 900; color: #1e1e1e; line-height: 1; user-select: none; }
        .title { font-size: 28px; font-weight: 700; color: #f1f5f9; margin-top: 8px; }
        .message { color: #64748b; font-size: 15px; margin-top: 12px; max-width: 400px; line-height: 1.6; }
        .divider { width: 40px; height: 3px; background: #dc2626; border-radius: 2px; margin: 20px auto; }
    </style>
</head>
<body>
    <div class="container">
        <div class="code">500</div>
        <div class="divider"></div>
        <div class="title">Internal Server Error</div>
        <p class="message">
            The server encountered an unexpected condition that prevented it from fulfilling your request.
            Please try again later.
        </p>
    </div>
</body>
</html>

