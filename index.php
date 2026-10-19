
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Page</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <main class="login-container">
        <h1>Login</h1>

        <form action="login.php" method="post">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username"
                       name="username" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password"
                       name="password" required>
            </div>

            <button type="submit">Login</button>
        </form>

        <p class="register-link">
            Don't have an account?
            <a href="register.php">Register here</a>
        </p>
    </main>

</body>
</html>
