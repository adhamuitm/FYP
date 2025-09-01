<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unauthorized Access - SMK Chendering Library</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e3c72, #2a5298);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .container {
            text-align: center;
            max-width: 500px;
            padding: 3rem;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .error-icon {
            font-size: 4rem;
            color: #ff6b6b;
            margin-bottom: 2rem;
            animation: pulse 2s infinite;
        }

        .error-code {
            font-size: 6rem;
            font-weight: 900;
            margin-bottom: 1rem;
            background: linear-gradient(45deg, #ff6b6b, #ffa500);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        h1 {
            font-size: 2rem;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        p {
            font-size: 1.1rem;
            margin-bottom: 2rem;
            opacity: 0.9;
            line-height: 1.6;
        }

        .buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: 25px;
            font-size: 1rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .btn-primary:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }

        .btn-secondary {
            background: transparent;
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.5);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: white;
            transform: translateY(-2px);
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .school-info {
            position: absolute;
            top: 2rem;
            left: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            color: white;
        }

        .school-logo {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        @media (max-width: 768px) {
            .container {
                margin: 1rem;
                padding: 2rem;
            }

            .error-code {
                font-size: 4rem;
            }

            h1 {
                font-size: 1.5rem;
            }

            .buttons {
                flex-direction: column;
            }

            .school-info {
                position: static;
                justify-content: center;
                margin-bottom: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="school-info">
        <div class="school-logo">SMK</div>
        <span>SMK Chendering Library</span>
    </div>

    <div class="container">
        <div class="error-icon">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        
        <div class="error-code">403</div>
        
        <h1>Access Denied</h1>
        
        <p>
            Sorry, you don't have permission to access this page. 
            This area is restricted to authorized users only.
        </p>
        
        <div class="buttons">
            <a href="javascript:history.back()" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i>
                Go Back
            </a>
            <a href="index.php" class="btn btn-primary">
                <i class="fas fa-home"></i>
                Home Page
            </a>
        </div>
    </div>

    <script>
        // Auto-redirect after 5 seconds if no user interaction
        let redirectTimer = setTimeout(() => {
            window.location.href = 'index.php';
        }, 5000);

        // Clear timer if user interacts with the page
        document.addEventListener('click', () => {
            clearTimeout(redirectTimer);
        });

        document.addEventListener('keydown', () => {
            clearTimeout(redirectTimer);
        });
    </script>
</body>
</html>