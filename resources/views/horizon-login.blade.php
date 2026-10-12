<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Horizon - Authentication Required</title>
    <style>
        :root { --ink: #162c39; --muted: #536b77; --line: #d8e2e5; --teal: #096b62; --paper: #fff; --canvas: #f2f5f5; --danger: #9d3434; }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--canvas);
            color: var(--ink);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-container {
            background: var(--paper);
            border: 1px solid var(--line);
            border-radius: 12px;
            box-shadow: 0 7px 28px #1534440a;
            padding: 40px;
            max-width: 400px;
            width: 100%;
        }
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo h1 {
            font-size: 32px;
            color: var(--ink);
            margin-bottom: 5px;
        }
        .logo p {
            color: var(--muted);
            font-size: 14px;
        }
        .error {
            background: #fbeaea;
            border: 1px solid #edc7c7;
            color: var(--danger);
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: var(--ink);
            font-weight: 500;
            font-size: 14px;
        }
        input[type="password"] {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--line);
            background: var(--paper);
            color: var(--ink);
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        input[type="password"]:focus {
            outline: none;
            border-color: var(--teal);
            outline: 3px solid #096b6233;
        }
        button {
            width: 100%;
            padding: 14px;
            background: var(--teal);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        button:hover {
            transform: translateY(-2px);
            background: #08544e;
            box-shadow: 0 4px 14px #096b6233;
        }
        button:active {
            transform: translateY(0);
        }
        .hint {
            text-align: center;
            margin-top: 20px;
            color: var(--muted);
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <h1>🚀 Horizon</h1>
            <p>Queue Management Dashboard</p>
        </div>

        @if(isset($error))
            <div class="error">
                {{ $error }}
            </div>
        @endif

        <form method="POST" action="{{ url()->current() }}">
            @csrf
            <div class="form-group">
                <label for="password">Master Password</label>
                <input
                    type="password"
                    id="password"
                    name="horizon_password"
                    placeholder="Enter master password"
                    autofocus
                    required
                >
            </div>
            <button type="submit">Access Horizon</button>
        </form>

        <div class="hint">
            Use your Web UI master password
        </div>
    </div>
</body>
</html>
