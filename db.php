<?php
$conn = mysqli_connect("localhost", "root", "", "sgdg_db");

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

echo " Database Connected Successfully!";
?>