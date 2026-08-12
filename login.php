<?php
require 'config/db_connect.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>SmartLib | Login</title>
    <link rel="stylesheet" href="assets/styles.css">

    <style>
        .modal-container {
            width: 420px;
            margin: 40px auto;
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 18px rgba(0,0,0,0.15);
        }

        .tab-buttons {
            display: flex;
            margin-bottom: 20px;
        }

        .tab-buttons button {
            flex: 1;
            padding: 10px;
            border: none;
            background: #eef1f5;
            cursor: pointer;
            font-size: 15px;
        }

        .tab-buttons .active {
            background: #1f4f8f;
            color: white;
            font-weight: bold;
        }

        .form-box { display: none; }
        .form-box.active { display: block; }

        input, select {
            width: 100%;
            padding: 10px;
            margin-top: 6px;
            margin-bottom: 15px;
            border-radius: 8px;
            border: 1px solid #ddd;
        }

        button.submit-btn {
            width: 100%;
            padding: 12px;
            background: #1f4f8f;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
        }

    </style>
</head>

<body>

<div class="modal-container">

    <!-- TAB BUTTONS -->
    <div class="tab-buttons">
        <button id="tab-login" class="active">Sign In</button>
        <button id="tab-register">Create Account</button>
    </div>

    <!-- LOGIN FORM -->
    <div id="login-box" class="form-box active">
        <form action="login_handler.php" method="POST">
            <h3>Student / Faculty Login</h3>

            <input type="text" name="user_id" placeholder="Student ID or Employee ID" required>
            <input type="password" name="password" placeholder="PIN / Password" required>

            <button type="submit" class="submit-btn">Sign In</button>
        </form>
    </div>

    <!-- REGISTER FORM -->
    <div id="register-box" class="form-box">
        <form action="register_handler.php" method="POST">

            <h3>Create an Account</h3>

            <label>Account Type</label>
            <select name="account_type" required>
                <option value="student">Student</option>
                <option value="faculty">Faculty</option>
            </select>

            <label>Full Name</label>
            <input type="text" name="full_name" required>

            <label>ID Number</label>
            <input type="text" name="user_id" placeholder="Student ID or Employee ID" required>

            <label>Email</label>
            <input type="email" name="email" required>

            <label>Course / Department</label>
            <input type="text" name="course_program" placeholder="BSCS / BSHM / IT Dept." required>

            <label>4-Digit PIN</label>
            <input type="password" maxlength="4" name="password" required>

            <button type="submit" class="submit-btn">Create Account</button>
        </form>
    </div>

</div>


<script>
// Switching tabs
document.getElementById("tab-login").onclick = function () {
    document.getElementById("login-box").classList.add("active");
    document.getElementById("register-box").classList.remove("active");

    this.classList.add("active");
    document.getElementById("tab-register").classList.remove("active");
};

document.getElementById("tab-register").onclick = function () {
    document.getElementById("register-box").classList.add("active");
    document.getElementById("login-box").classList.remove("active");

    this.classList.add("active");
    document.getElementById("tab-login").classList.remove("active");
};
</script>

</body>
</html>
