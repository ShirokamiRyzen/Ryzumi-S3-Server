<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance On Progress</title>
    <style>
        :root {
            --bg-color: #f7f9fc;
            --text-color: #333;
            --card-bg: white;
            --heading-color: #d9534f;
            --shadow-color: rgba(0, 0, 0, 0.1);
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg-color: #121212;
                --text-color: #e0e0e0;
                --card-bg: #1e1e1e;
                --heading-color: #ff6b6b;
                --shadow-color: rgba(0, 0, 0, 0.5);
            }
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: background-color 0.3s, color 0.3s;
        }
        .container {
            text-align: center;
            padding: 2rem;
            background: var(--card-bg);
            border-radius: 8px;
            box-shadow: 0 4px 6px var(--shadow-color);
            max-width: 500px;
            transition: background-color 0.3s, box-shadow 0.3s;
        }
        h1 {
            color: var(--heading-color);
            margin-bottom: 1rem;
        }
        p {
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }
        .maintenance-img {
            max-width: 50%;
            height: auto;
            margin-bottom: 2rem;
            border-radius: 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        <img src="https://api.ryzumi.vip/images/code/404.webp" alt="Maintenance" class="maintenance-img">
        <h1>Under Maintenance</h1>
        <p>We are currently performing scheduled maintenance. We should be back shortly. Thank you for your patience.</p>
    </div>
</body>
</html>
