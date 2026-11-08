
<?php
// ==========================================================
// AGRICOOP - MEMBER PROFILE MANAGEMENT
// ==========================================================

require_once __DIR__ . '/auth.php';
require_role('member');

$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id) {
    header('Location: logout.php');
    exit();
}

$successMessage = '';
$errorMessage = '';

// ==========================================================
// HANDLE PROFILE AND PASSWORD UPDATES
// ==========================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_check();

    $action = $_POST['action'] ?? '';

    // ------------------------------------------------------
    // UPDATE PROFILE
    // ------------------------------------------------------

    if ($action === 'update_profile') {

        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $contact = trim($_POST['contact'] ?? '');

        if ($fullName === '' || $email === '' || $contact === '') {
            $errorMessage = "Full Name, Email, and Phone Number are required.";

        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = "Please enter a valid email address.";

        } else {

            try {
                $conn->begin_transaction();

                // Update email in users table
                $stmtUser = $conn->prepare("
                    UPDATE users
                    SET email = ?
                    WHERE user_id = ?
                ");

                $stmtUser->bind_param("si", $email, $user_id);
                $stmtUser->execute();

                // Update member information
                $stmtMember = $conn->prepare("
                    UPDATE members
                    SET member_name = ?, contact = ?
                    WHERE user_id = ?
                ");

                $stmtMember->bind_param(
                    "ssi",
                    $fullName,
                    $contact,
                    $user_id
                );

                $stmtMember->execute();

                $conn->commit();

                // Update session information
                $_SESSION['member_name'] = $fullName;
                $_SESSION['email'] = $email;

                $successMessage = "Your profile has been updated successfully!";

            } catch (Throwable $e) {

                $conn->rollback();

                if ($e instanceof mysqli_sql_exception && $e->getCode() == 1062) {
                    $errorMessage = "This email address is already being used by another account.";
                } else {
                    error_log("Profile update error: " . $e->getMessage());
                    $errorMessage = "Unable to update your profile. Please try again.";
                }
            }
        }
    }

    // ------------------------------------------------------
    // CHANGE PASSWORD
    // ------------------------------------------------------

    elseif ($action === 'change_password') {

        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (
            $currentPassword === '' ||
            $newPassword === '' ||
            $confirmPassword === ''
        ) {
            $errorMessage = "Please complete all password fields.";

        } elseif (strlen($newPassword) < 8) {
            $errorMessage = "Your new password must contain at least 8 characters.";

        } elseif ($newPassword !== $confirmPassword) {
            $errorMessage = "New password and confirmation do not match.";

        } elseif ($currentPassword === $newPassword) {
            $errorMessage = "Your new password must be different from your current password.";

        } else {

            try {
                // Get the current password hash
                $stmtPassword = $conn->prepare("
                    SELECT password_hash
                    FROM users
                    WHERE user_id = ?
                    LIMIT 1
                ");

                $stmtPassword->bind_param("i", $user_id);
                $stmtPassword->execute();

                $passwordData = $stmtPassword->get_result()->fetch_assoc();

                if (!$passwordData || !password_verify(
                    $currentPassword,
                    $passwordData['password_hash']
                )) {

                    $errorMessage = "Your current password is incorrect.";

                } else {

                    // Hash the new password
                    $newPasswordHash = password_hash(
                        $newPassword,
                        PASSWORD_DEFAULT
                    );

                    // Save the new password
                    $stmtUpdatePassword = $conn->prepare("
                        UPDATE users
                        SET password_hash = ?
                        WHERE user_id = ?
                    ");

                    $stmtUpdatePassword->bind_param(
                        "si",
                        $newPasswordHash,
                        $user_id
                    );

                    $stmtUpdatePassword->execute();

                    $successMessage = "Your password has been changed successfully!";
                }

            } catch (Throwable $e) {

                error_log("Password update error: " . $e->getMessage());
                $errorMessage = "Unable to change your password. Please try again.";
            }
        }
    }
}

// ==========================================================
// GET MEMBER PROFILE
// ==========================================================

$stmtProfile = $conn->prepare("
    SELECT
        m.member_id,
        m.member_name,
        m.contact,
        u.username,
        u.email,
        u.role,
        u.status,
        u.created_at AS joined_at
    FROM members m
    INNER JOIN users u ON m.user_id = u.user_id
    WHERE m.user_id = ?
    LIMIT 1
");

$stmtProfile->bind_param("i", $user_id);
$stmtProfile->execute();

$profile = $stmtProfile->get_result()->fetch_assoc();

if (!$profile) {
    die("Member record not found.");
}

// Display name
$nameParts = preg_split('/\s+/', trim($profile['member_name']));

$shortName = count($nameParts) > 1
    ? mb_substr($nameParts[0], 0, 1) . '. ' . $nameParts[count($nameParts) - 1]
    : $nameParts[0];

$farmLine = 'Cooperative member';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">

<title>AgriCoop Member - My Profile</title>

<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">

<link href="https://fonts.googleapis.com" rel="preconnect">
<link crossorigin href="https://fonts.gstatic.com" rel="preconnect">

<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Work+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>

<script>
tailwind.config = {
    darkMode: "class",
    theme: {
        extend: {
            colors: {
                "primary": "#023616",
                "primary-container": "#1e4d2b",
                "on-primary": "#ffffff",
                "background": "#f8faf8",
                "surface-container-lowest": "#ffffff",
                "surface-container-low": "#f2f4f2",
                "surface-container": "#eceeec",
                "on-surface": "#191c1b",
                "on-surface-variant": "#414941",
                "outline": "#717970",
                "outline-variant": "#c1c9be",
                "error": "#ba1a1a",
                "error-container": "#ffdad6",
                "on-error-container": "#93000a",
                "primary-fixed": "#bbefc1",
                "on-primary-fixed": "#00210b"
            },
            fontFamily: {
                "body-md": ["Work Sans"],
                "label-sm": ["JetBrains Mono"]
            }
        }
    }
}
</script>

<style>
.material-symbols-outlined {
    font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
    display: inline-block;
    vertical-align: middle;
    line-height: 1;
}
</style>
</head>

<body class="bg-background text-on-surface font-body-md antialiased min-h-screen flex">

<!-- =====================================================
     SIDEBAR
===================================================== -->

<aside class="hidden md:flex w-64 h-screen flex-col justify-between p-4 flex-shrink-0 bg-surface-container-lowest border-r border-outline-variant fixed left-0 top-0 z-30">

    <div class="flex flex-col gap-6">

        <div class="flex items-center gap-3 px-2 py-1">
            <div class="w-10 h-10 rounded-lg bg-primary-container flex items-center justify-center text-on-primary">
                <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">agriculture</span>
            </div>

            <div class="flex flex-col overflow-hidden">
                <span class="text-body-lg font-bold text-primary leading-tight">AgriCoop Member</span>
                <span class="text-xs text-on-surface-variant truncate">Valley Basin Co-op Yard</span>
            </div>
        </div>

        <a href="User_QuickReserve.php"
           class="w-full h-11 bg-primary text-on-primary font-semibold text-sm rounded-lg flex items-center justify-center gap-2 hover:bg-primary-container transition-colors shadow-sm">

            <span class="material-symbols-outlined">add_circle</span>
            Reserve Equipment
        </a>

        <nav aria-label="Main Navigation" class="flex flex-col gap-1.5">

            <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-on-surface-variant text-sm hover:bg-surface-container-low transition-colors"
               href="UserDashboard.php">

                <span class="material-symbols-outlined">dashboard</span>
                <span>Dashboard</span>
            </a>

            <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-on-surface-variant text-sm hover:bg-surface-container-low transition-colors"
               href="User_Browse.php">

                <span class="material-symbols-outlined">agriculture</span>
                <span>Browse Equipment</span>
            </a>

            <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-on-surface-variant text-sm hover:bg-surface-container-low transition-colors"
               href="User_Reservation.php">

                <span class="material-symbols-outlined">event_note</span>
                <span>My Reservations</span>
            </a>

            <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-surface-container text-primary text-sm font-semibold border-l-4 border-primary"
               href="User_Profile.php">

                <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">person</span>
                <span>My Profile</span>
            </a>

        </nav>
    </div>

    <div class="flex flex-col gap-2 pt-4 border-t border-outline-variant">

        <div class="flex items-center justify-between px-3 py-2 rounded-lg bg-surface-container-low">

            <div class="flex items-center gap-2 overflow-hidden">
                <span class="material-symbols-outlined text-primary">badge</span>

                <div class="flex flex-col">
                    <span class="text-xs font-semibold text-on-surface truncate">
                        <?= e($shortName) ?> (#<?= e($profile['member_id']) ?>)
                    </span>

                    <span class="text-[11px] text-outline">
                        <?= e($farmLine) ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Logout: logout.php is beside this file -->
        <a href="logout.php"
           class="w-full flex items-center gap-3 px-3 py-2 rounded-lg text-error text-sm hover:bg-error-container/40 transition-colors">

            <span class="material-symbols-outlined">logout</span>
            <span>Logout</span>
        </a>

    </div>
</aside>

<!-- =====================================================
     MAIN CONTENT
===================================================== -->

<div class="flex-1 md:ml-64 flex flex-col min-h-screen">

    <header class="flex justify-between items-center h-16 px-6 w-full bg-surface-container-lowest border-b border-outline-variant sticky top-0 z-20">

        <div class="flex items-center gap-4">
            <button aria-label="Open menu" class="md:hidden p-2 text-on-surface-variant hover:bg-surface-container-low rounded-lg">
                <span class="material-symbols-outlined">menu</span>
            </button>
        </div>

        <div class="flex items-center gap-4">
            <span class="text-sm font-medium text-primary">
                Logged in as: <?= e($profile['member_name']) ?>
            </span>
        </div>

    </header>

    <main class="flex-1 p-6 md:p-8 space-y-8 max-w-7xl w-full mx-auto">

        <div class="space-y-8">

            <!-- ALERT MESSAGES -->

            <?php if ($successMessage !== ''): ?>
                <div class="p-4 bg-primary-fixed text-on-primary-fixed rounded-lg border border-primary/20 flex items-center gap-3">
                    <span class="material-symbols-outlined">check_circle</span>
                    <div><?= e($successMessage) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="p-4 bg-error-container text-on-error-container rounded-lg border border-error/20 flex items-center gap-3">
                    <span class="material-symbols-outlined">error</span>
                    <div><?= e($errorMessage) ?></div>
                </div>
            <?php endif; ?>

            <!-- PAGE TITLE -->

            <div class="flex flex-col gap-1 pb-2">

                <div class="flex items-center gap-2 text-primary text-xs font-semibold">
                    <span class="material-symbols-outlined text-base">home_work</span>
                    <span>COOPERATIVE MEMBER REGISTRY</span>
                </div>

                <h1 class="text-2xl font-bold text-primary tracking-tight">
                    My Profile
                </h1>

                <p class="text-sm text-on-surface-variant">
                    Manage your cooperative membership details, contact information, and account security.
                </p>

            </div>

            <!-- MEMBER INFORMATION CARD -->

            <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-6 shadow-sm">

                <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">

                    <div class="flex items-start sm:items-center gap-4">

                        <div class="relative w-16 h-16 rounded-xl overflow-hidden border border-outline-variant bg-surface-container flex-shrink-0 flex items-center justify-center">

                            <span class="material-symbols-outlined text-on-surface-variant text-4xl">
                                person
                            </span>

                        </div>

                        <div class="flex flex-col">

                            <div class="flex items-center gap-3 flex-wrap">

                                <h2 class="text-lg font-semibold text-on-surface">
                                    <?= e($profile['member_name']) ?>
                                </h2>

                                <span class="px-2 py-0.5 rounded text-xs uppercase font-semibold
                                    <?= $profile['status'] === 'active'
                                        ? 'bg-primary-fixed text-on-primary-fixed'
                                        : 'bg-error-container text-on-error-container' ?>">

                                    <?= e(strtoupper($profile['status'])) ?>

                                </span>

                            </div>

                            <div class="flex flex-wrap items-center gap-y-1 gap-x-3 text-sm text-on-surface-variant mt-1">

                                <span class="font-medium text-on-surface">
                                    Username: @<?= e($profile['username']) ?>
                                </span>

                                <span>&bull;</span>

                                <span class="text-xs">
                                    Membership ID: #<?= e($profile['member_id']) ?>
                                </span>

                                <span>&bull;</span>

                                <span class="text-outline text-xs">
                                    Joined:
                                    <?= !empty($profile['joined_at'])
                                        ? date('F Y', strtotime($profile['joined_at']))
                                        : 'Not available' ?>
                                </span>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <!-- =================================================
                 PERSONAL AND CONTACT INFORMATION
            ================================================== -->

            <form method="POST" action="User_Profile.php"
                  class="bg-surface-container-lowest border border-outline-variant rounded-xl divide-y divide-outline-variant">

                <?= csrf_field() ?>

                <input type="hidden" name="action" value="update_profile">

                <div class="p-6">

                    <div class="flex items-center gap-2 mb-4">

                        <span class="material-symbols-outlined text-primary text-xl">
                            contact_page
                        </span>

                        <h3 class="text-base text-on-surface font-semibold">
                            Personal &amp; Contact Info
                        </h3>

                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

                        <div>
                            <label class="block text-xs font-semibold text-on-surface-variant mb-1.5">
                                Full Name
                            </label>

                            <input
                                name="full_name"
                                class="w-full h-11 px-3.5 text-sm bg-surface-container-lowest border border-outline-variant rounded-lg text-on-surface focus:outline-none focus:border-primary"
                                type="text"
                                value="<?= e($profile['member_name']) ?>"
                                required
                            >
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-on-surface-variant mb-1.5">
                                Email Address
                            </label>

                            <input
                                name="email"
                                class="w-full h-11 px-3.5 text-sm bg-surface-container-lowest border border-outline-variant rounded-lg text-on-surface focus:outline-none focus:border-primary"
                                type="email"
                                value="<?= e($profile['email'] ?? '') ?>"
                                required
                            >
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-on-surface-variant mb-1.5">
                                Phone Number
                            </label>

                            <input
                                name="contact"
                                class="w-full h-11 px-3.5 text-sm bg-surface-container-lowest border border-outline-variant rounded-lg text-on-surface focus:outline-none focus:border-primary"
                                type="tel"
                                value="<?= e($profile['contact']) ?>"
                                required
                            >
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-on-surface-variant mb-1.5">
                                Farm / Home Location
                            </label>

                            <input
                                class="w-full h-11 px-3.5 text-sm bg-surface-container-low border border-outline-variant rounded-lg text-on-surface"
                                type="text"
                                value="Not provided"
                                disabled
                            >

                            <p class="text-xs text-outline mt-1">
                                Farm location is not currently stored in your member database.
                            </p>
                        </div>

                    </div>

                </div>

                <div class="p-6 bg-surface-container-low flex flex-col sm:flex-row items-center justify-between gap-4 rounded-b-xl">

                    <div class="text-xs text-on-surface-variant flex items-center gap-2">

                        <span class="material-symbols-outlined text-base text-primary">
                            info
                        </span>

                        <span>
                            Your updated information will be saved to your member account.
                        </span>

                    </div>

                    <div class="flex items-center gap-3 w-full sm:w-auto">

                        <button type="submit"
                                class="w-full sm:w-auto h-11 px-6 bg-primary-container hover:bg-primary text-on-primary text-sm font-semibold rounded-lg transition-colors flex items-center justify-center gap-2 shadow-sm">

                            <span class="material-symbols-outlined text-base">save</span>
                            <span>Save Changes</span>

                        </button>

                    </div>

                </div>

            </form>

            <!-- =================================================
                 CHANGE PASSWORD
            ================================================== -->

            <form method="POST" action="User_Profile.php"
                  class="bg-surface-container-lowest border border-outline-variant rounded-xl divide-y divide-outline-variant">

                <?= csrf_field() ?>

                <input type="hidden" name="action" value="change_password">

                <div class="p-6">

                    <div class="flex items-center gap-2 mb-2">

                        <span class="material-symbols-outlined text-primary text-xl">
                            lock
                        </span>

                        <h3 class="text-base text-on-surface font-semibold">
                            Change Password
                        </h3>

                    </div>

                    <p class="text-sm text-on-surface-variant mb-6">
                        Update your account password to keep your member account secure.
                    </p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

                        <div class="md:col-span-2">

                            <label class="block text-xs font-semibold text-on-surface-variant mb-1.5">
                                Current Password
                            </label>

                            <input
                                name="current_password"
                                class="w-full h-11 px-3.5 text-sm bg-surface-container-lowest border border-outline-variant rounded-lg text-on-surface focus:outline-none focus:border-primary"
                                type="password"
                                autocomplete="current-password"
                                placeholder="Enter your current password"
                                required
                            >

                        </div>

                        <div>

                            <label class="block text-xs font-semibold text-on-surface-variant mb-1.5">
                                New Password
                            </label>

                            <input
                                name="new_password"
                                class="w-full h-11 px-3.5 text-sm bg-surface-container-lowest border border-outline-variant rounded-lg text-on-surface focus:outline-none focus:border-primary"
                                type="password"
                                minlength="8"
                                autocomplete="new-password"
                                placeholder="Enter new password"
                                required
                            >

                            <p class="text-xs text-outline mt-1">
                                Minimum of 8 characters.
                            </p>

                        </div>

                        <div>

                            <label class="block text-xs font-semibold text-on-surface-variant mb-1.5">
                                Confirm New Password
                            </label>

                            <input
                                name="confirm_password"
                                class="w-full h-11 px-3.5 text-sm bg-surface-container-lowest border border-outline-variant rounded-lg text-on-surface focus:outline-none focus:border-primary"
                                type="password"
                                minlength="8"
                                autocomplete="new-password"
                                placeholder="Confirm your new password"
                                required
                            >

                        </div>

                    </div>

                </div>

                <div class="p-6 bg-surface-container-low flex flex-col sm:flex-row items-center justify-between gap-4 rounded-b-xl">

                    <div class="text-xs text-on-surface-variant flex items-center gap-2">

                        <span class="material-symbols-outlined text-base text-primary">
                            shield
                        </span>

                        <span>
                            Your password is securely hashed before being saved.
                        </span>

                    </div>

                    <button type="submit"
                            class="w-full sm:w-auto h-11 px-6 bg-primary-container hover:bg-primary text-on-primary text-sm font-semibold rounded-lg transition-colors flex items-center justify-center gap-2 shadow-sm">

                        <span class="material-symbols-outlined text-base">
                            lock_reset
                        </span>

                        <span>Update Password</span>

                    </button>

                </div>

            </form>

        </div>

    </main>

</div>

</body>
</html>
