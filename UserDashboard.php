
<?php
// ==========================================================
// AGRICOOP - MEMBER DASHBOARD
// ==========================================================

require_once __DIR__ . '/auth.php';
require_role('member');

$memberId = $_SESSION['member_id'] ?? '';

if ($memberId === '') {
    header('Location: logout.php');
    exit();
}

// ==========================================================
// MEMBER INFORMATION
// ==========================================================

$memberStmt = $conn->prepare("
    SELECT
        m.member_id,
        m.member_name,
        m.contact,
        u.status
    FROM members m
    INNER JOIN users u ON u.user_id = m.user_id
    WHERE m.member_id = ?
    LIMIT 1
");

$memberStmt->bind_param('s', $memberId);
$memberStmt->execute();

$member = $memberStmt->get_result()->fetch_assoc();

if (!$member || $member['status'] !== 'active') {
    header('Location: logout.php');
    exit();
}

$nameParts = preg_split('/\s+/', trim($member['member_name'])) ?: [$member['member_name']];

$firstName = $nameParts[0];

$shortName = count($nameParts) > 1
    ? mb_substr($nameParts[0], 0, 1) . '. ' . $nameParts[count($nameParts) - 1]
    : $nameParts[0];

$farmLine = 'Cooperative member';

// ==========================================================
// AVAILABLE EQUIPMENT
// ==========================================================

$yard = $conn->query("
    SELECT COUNT(*) AS available
    FROM equipment_units u
    WHERE u.condition_status = 'good'
      AND NOT EXISTS (
        SELECT 1
        FROM rental_items ri
        JOIN rentals r ON r.rental_ref = ri.rental_ref
        WHERE ri.equipment_code = u.equipment_code
          AND ri.item_status IN ('reserved', 'issued')
          AND r.rental_status IN ('pending', 'approved', 'issued')
          AND r.pickup_date <= CURDATE()
          AND r.due_date >= CURDATE()
      )
")->fetch_assoc();

$availableUnits = (int)($yard['available'] ?? 0);

// ==========================================================
// ACTIVE RESERVATIONS
// ==========================================================

$activeStmt = $conn->prepare("
    SELECT COUNT(*) AS n
    FROM rentals
    WHERE member_id = ?
      AND rental_status IN ('pending', 'approved', 'issued')
");

$activeStmt->bind_param('s', $memberId);
$activeStmt->execute();

$activeCount = (int)$activeStmt->get_result()->fetch_assoc()['n'];

// ==========================================================
// RECENT BOOKINGS AND SEARCH
// ==========================================================

$q = trim($_GET['q'] ?? '');

$bookingSql = "
    SELECT
        r.rental_ref,
        r.pickup_date,
        r.due_date,
        r.rental_status,
        t.type_name,
        t.icon,
        ri.equipment_code
    FROM rentals r
    JOIN rental_items ri ON ri.rental_ref = r.rental_ref
    JOIN equipment_units u ON u.equipment_code = ri.equipment_code
    JOIN equipment_types t ON t.type_id = u.type_id
    WHERE r.member_id = ?
";

$bookingTypes = 's';
$bookingParams = [$memberId];

if ($q !== '') {
    $bookingSql .= "
        AND (
            t.type_name LIKE ?
            OR ri.equipment_code LIKE ?
            OR r.rental_ref LIKE ?
        )
    ";

    $like = '%' . $q . '%';

    $bookingTypes .= 'sss';

    array_push($bookingParams, $like, $like, $like);
}

$bookingSql .= "
    ORDER BY r.created_at DESC, ri.rental_item_id DESC
    LIMIT 8
";

$bookings = q_all($conn, $bookingSql, $bookingTypes, $bookingParams);

// ==========================================================
// STATUS BADGE
// ==========================================================

function dash_badge(string $status): string {

    $map = [
        'pending' => [
            'Pending',
            'bg-secondary-fixed text-on-secondary-fixed',
            'bg-secondary'
        ],

        'approved' => [
            'Approved',
            'bg-secondary-fixed text-on-secondary-fixed',
            'bg-secondary'
        ],

        'issued' => [
            'Checked Out',
            'bg-secondary-fixed text-on-secondary-fixed',
            'bg-secondary'
        ],

        'returned' => [
            'Returned',
            'bg-surface-container text-primary border border-outline-variant',
            'bg-primary-container'
        ],

        'rejected' => [
            'Rejected',
            'bg-error-container text-on-error-container',
            'bg-error'
        ],

        'cancelled' => [
            'Cancelled',
            'bg-surface-container text-on-surface-variant border border-outline-variant',
            'bg-outline'
        ],
    ];

    [$label, $cls, $dot] = $map[$status] ?? $map['cancelled'];

    return '<span class="px-3 py-1 rounded-full ' . $cls .
        ' text-xs font-medium inline-flex items-center gap-1.5 self-start sm:self-center">'
        . '<span class="w-1.5 h-1.5 rounded-full ' . $dot . '"></span>'
        . e($label)
        . '</span>';
}

// ==========================================================
// DATE RANGE FORMAT
// ==========================================================

function dash_range(string $from, string $to): string {

    $a = strtotime($from);
    $b = strtotime($to);

    $fmt = date('Y', $a) === date('Y', $b)
        ? 'M j'
        : 'M j, Y';

    return date($fmt, $a) . ' – ' . date($fmt, $b);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>AgriCoop Member - Dashboard</title>

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
                "surface-container-high": "#e6e9e7",
                "on-surface": "#191c1b",
                "on-surface-variant": "#414941",
                "outline": "#717970",
                "outline-variant": "#c1c9be",
                "error": "#ba1a1a",
                "error-container": "#ffdad6",
                "on-error-container": "#93000a",
                "primary-fixed": "#bbefc1",
                "on-primary-fixed": "#00210b",
                "secondary": "#904d00",
                "secondary-fixed": "#ffdcc3",
                "on-secondary-fixed": "#2f1500"
            },

            fontFamily: {
                "body-md": ["Work Sans"],
                "headline-lg": ["Work Sans"],
                "headline-sm": ["Work Sans"],
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

body {
    font-family: 'Work Sans', sans-serif;
}
</style>

</head>

<body class="bg-background text-on-surface font-body-md antialiased min-h-screen flex">

<!-- =====================================================
     SIDEBAR
====================================================== -->

<aside class="hidden md:flex w-64 h-screen flex-col justify-between p-4 flex-shrink-0 bg-surface-container-lowest border-r border-outline-variant fixed left-0 top-0 z-30">

    <div class="flex flex-col gap-6">

        <!-- BRAND -->

        <div class="flex items-center gap-3 px-2 py-1">

            <div class="w-10 h-10 rounded-lg bg-primary-container flex items-center justify-center text-on-primary">

                <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">
                    agriculture
                </span>

            </div>

            <div class="flex flex-col overflow-hidden">

                <span class="text-base font-bold text-primary leading-tight">
                    AgriCoop Member
                </span>

                <span class="text-xs text-on-surface-variant truncate">
                    Valley Basin Co-op Yard
                </span>

            </div>

        </div>

        <!-- RESERVE BUTTON -->

        <a href="User_QuickReserve.php"
           class="w-full h-11 bg-primary text-on-primary font-semibold text-sm rounded-lg flex items-center justify-center gap-2 hover:bg-primary-container transition-colors shadow-sm">

            <span class="material-symbols-outlined">add_circle</span>

            Reserve Equipment

        </a>

        <!-- NAVIGATION -->

        <nav aria-label="Main Navigation" class="flex flex-col gap-1.5">

            <!-- DASHBOARD ACTIVE -->

            <a href="UserDashboard.php"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-surface-container text-primary text-sm font-semibold border-l-4 border-primary">

                <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">
                    dashboard
                </span>

                <span>Dashboard</span>

            </a>

            <!-- BROWSE EQUIPMENT -->

            <a href="User_Browse.php"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-on-surface-variant text-sm hover:bg-surface-container-low transition-colors">

                <span class="material-symbols-outlined">
                    agriculture
                </span>

                <span>Browse Equipment</span>

            </a>

            <!-- RESERVATIONS -->

            <a href="User_Reservation.php"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-on-surface-variant text-sm hover:bg-surface-container-low transition-colors">

                <span class="material-symbols-outlined">
                    event_note
                </span>

                <span>My Reservations</span>

            </a>

            <!-- PROFILE -->

            <a href="User_Profile.php"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-on-surface-variant text-sm hover:bg-surface-container-low transition-colors">

                <span class="material-symbols-outlined">
                    person
                </span>

                <span>My Profile</span>

            </a>

        </nav>

    </div>

    <!-- SIDEBAR BOTTOM -->

    <div class="flex flex-col gap-2 pt-4 border-t border-outline-variant">

        <div class="flex items-center px-3 py-2 rounded-lg bg-surface-container-low">

            <div class="flex items-center gap-2 overflow-hidden">

                <span class="material-symbols-outlined text-primary">
                    badge
                </span>

                <div class="flex flex-col overflow-hidden">

                    <span class="text-xs font-semibold text-on-surface truncate">
                        <?= e($shortName) ?> (#<?= e($member['member_id']) ?>)
                    </span>

                    <span class="text-[11px] text-outline truncate">
                        <?= e($farmLine) ?>
                    </span>

                </div>

            </div>

        </div>

        <!-- LOGOUT -->

        <a href="logout.php"
           class="w-full flex items-center gap-3 px-3 py-2 rounded-lg text-error text-sm hover:bg-error-container/40 transition-colors">

            <span class="material-symbols-outlined">
                logout
            </span>

            <span>Logout</span>

        </a>

    </div>

</aside>

<!-- =====================================================
     MAIN CONTENT
====================================================== -->

<div class="flex-1 md:ml-64 flex flex-col min-h-screen">

    <!-- HEADER -->

    <header class="flex justify-between items-center h-16 px-6 w-full bg-surface-container-lowest border-b border-outline-variant sticky top-0 z-20">

        <div class="flex items-center gap-4">

            <button aria-label="Open menu"
                    class="md:hidden p-2 text-on-surface-variant hover:bg-surface-container-low rounded-lg">

                <span class="material-symbols-outlined">
                    menu
                </span>

            </button>

        </div>

        <!-- SEARCH -->

        <div class="flex items-center gap-4">

            <form method="get" action="UserDashboard.php" class="relative w-64 md:w-80">

                <input
                    name="q"
                    value="<?= e($q) ?>"
                    class="w-full pl-9 pr-3 py-2 text-sm bg-background rounded-lg border border-outline-variant focus:outline-none focus:border-primary text-on-surface"
                    placeholder="Search equipment or ID..."
                    type="search"
                >

                <span class="material-symbols-outlined absolute left-2.5 top-2.5 text-on-surface-variant text-lg">
                    search
                </span>

            </form>

        </div>

    </header>

    <!-- PAGE CONTENT -->

    <main class="flex-1 p-6 md:p-8 space-y-8 max-w-7xl w-full mx-auto">

        <!-- WELCOME SECTION -->

        <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">

            <div>

                <span class="text-xs text-primary font-semibold tracking-wider uppercase">
                    Member Dashboard
                </span>

                <h1 class="text-2xl font-bold text-primary tracking-tight mt-1">
                    Welcome back, <?= e($firstName) ?>
                </h1>

                <p class="text-sm text-on-surface-variant mt-1">
                    Here is a quick overview of your equipment rentals and cooperative status.
                </p>

            </div>

        </div>

        <!-- =================================================
             SUMMARY CARDS
        ================================================== -->

        <section class="grid grid-cols-1 md:grid-cols-3 gap-6">

            <!-- AVAILABLE EQUIPMENT -->

            <div class="bg-surface-container-lowest p-6 rounded-xl border border-outline-variant flex flex-col justify-between hover:border-primary transition-all">

                <div>

                    <div class="flex items-center justify-between mb-4">

                        <span class="text-xs text-on-surface-variant uppercase tracking-wider font-semibold">
                            Yard Inventory
                        </span>

                        <div class="w-10 h-10 rounded-lg bg-surface-container flex items-center justify-center text-primary">

                            <span class="material-symbols-outlined">
                                agriculture
                            </span>

                        </div>

                    </div>

                    <div class="text-3xl font-bold text-primary">
                        <?= $availableUnits ?>
                        <?= $availableUnits === 1 ? 'Unit' : 'Units' ?>
                    </div>

                    <p class="text-sm text-on-surface-variant mt-1">
                        Available in station yard today
                    </p>

                </div>

                <div class="mt-6 pt-4 border-t border-outline-variant">

                    <a href="User_Browse.php"
                       class="text-sm font-semibold text-primary hover:underline inline-flex items-center gap-1">

                        Browse Equipment

                        <span class="material-symbols-outlined text-base">
                            arrow_forward
                        </span>

                    </a>

                </div>

            </div>

            <!-- ACTIVE RESERVATIONS -->

            <div class="bg-surface-container-lowest p-6 rounded-xl border border-outline-variant flex flex-col justify-between hover:border-primary transition-all">

                <div>

                    <div class="flex items-center justify-between mb-4">

                        <span class="text-xs text-on-surface-variant uppercase tracking-wider font-semibold">
                            My Reservations
                        </span>

                        <div class="w-10 h-10 rounded-lg bg-secondary-fixed flex items-center justify-center text-secondary">

                            <span class="material-symbols-outlined">
                                event_note
                            </span>

                        </div>

                    </div>

                    <div class="text-3xl font-bold text-secondary">
                        <?= $activeCount ?> Active
                    </div>

                    <p class="text-sm text-on-surface-variant mt-1">
                        Currently checked out or scheduled
                    </p>

                </div>

                <div class="mt-6 pt-4 border-t border-outline-variant">

                    <a href="User_Reservation.php"
                       class="text-sm font-semibold text-primary hover:underline inline-flex items-center gap-1">

                        View Reservations

                        <span class="material-symbols-outlined text-base">
                            arrow_forward
                        </span>

                    </a>

                </div>

            </div>

            <!-- QUICK ACTIONS -->

            <div class="bg-surface-container-lowest p-6 rounded-xl border border-outline-variant flex flex-col justify-between hover:border-primary transition-all">

                <div>

                    <div class="flex items-center justify-between mb-4">

                        <span class="text-xs text-on-surface-variant uppercase tracking-wider font-semibold">
                            Quick Actions
                        </span>

                        <div class="w-10 h-10 rounded-lg bg-surface-container flex items-center justify-center text-primary">

                            <span class="material-symbols-outlined">
                                bolt
                            </span>

                        </div>

                    </div>

                    <div class="flex flex-col gap-2.5">

                        <a href="User_QuickReserve.php"
                           class="w-full py-2.5 px-3 rounded-lg bg-primary text-on-primary text-sm font-semibold hover:bg-primary-container transition-colors flex items-center justify-center gap-2 shadow-sm">

                            <span class="material-symbols-outlined text-lg">
                                add_circle
                            </span>

                            Browse &amp; Reserve

                        </a>

                        <a href="User_Reservation.php"
                           class="w-full py-2.5 px-3 rounded-lg bg-surface-container-low text-primary border border-outline-variant text-sm font-semibold hover:bg-surface-container transition-colors flex items-center justify-center gap-2">

                            <span class="material-symbols-outlined text-lg">
                                calendar_month
                            </span>

                            Manage Bookings

                        </a>

                    </div>

                </div>

                <div class="mt-4 pt-3 border-t border-outline-variant flex items-center justify-between text-xs text-on-surface-variant">

                    <span class="flex items-center gap-1">

                        <span class="w-1.5 h-1.5 rounded-full bg-primary"></span>

                        Gate: Mon–Sat 7am–5pm

                    </span>

                    <span class="text-primary font-semibold">
                        (555) 019-4822
                    </span>

                </div>

            </div>

        </section>

        <!-- =================================================
             RECENT BOOKINGS
        ================================================== -->

        <section class="space-y-6">

            <div class="bg-surface-container-lowest rounded-xl border border-outline-variant p-6">

                <div class="flex items-center justify-between pb-4 border-b border-outline-variant">

                    <div>

                        <h3 class="text-lg font-semibold text-primary">
                            Recent Bookings
                        </h3>

                        <p class="text-sm text-on-surface-variant mt-0.5">
                            Overview of your active and past reservations
                        </p>

                    </div>

                    <a href="User_Reservation.php"
                       class="text-sm font-semibold text-primary hover:underline flex items-center gap-1">

                        View All

                        <span class="material-symbols-outlined text-base">
                            arrow_forward
                        </span>

                    </a>

                </div>

                <div class="divide-y divide-outline-variant">

                    <?php if (!$bookings): ?>

                        <div class="py-8 text-center text-sm text-on-surface-variant">

                            <?= $q !== ''
                                ? 'No bookings match your search.'
                                : 'You have no reservations yet.' ?>

                        </div>

                    <?php else: ?>

                        <?php foreach ($bookings as $booking):

                            $icon = preg_match('/^[a-z0-9_]+$/', (string)($booking['icon'] ?? ''))
                                ? $booking['icon']
                                : 'agriculture';

                        ?>

                            <div class="py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">

                                <div class="flex items-center gap-3">

                                    <div class="w-10 h-10 rounded-lg bg-surface-container flex items-center justify-center text-primary flex-shrink-0">

                                        <span class="material-symbols-outlined">
                                            <?= e($icon) ?>
                                        </span>

                                    </div>

                                    <div>

                                        <h4 class="font-semibold text-on-surface text-base">
                                            <?= e($booking['type_name']) ?>
                                        </h4>

                                        <p class="text-xs text-on-surface-variant mt-1">

                                            Unit #<?= e($booking['equipment_code']) ?>

                                            &bull;

                                            <?= e(dash_range($booking['pickup_date'], $booking['due_date'])) ?>

                                            &bull;

                                            Ref: <?= e($booking['rental_ref']) ?>

                                        </p>

                                    </div>

                                </div>

                                <?= dash_badge($booking['rental_status']) ?>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            </div>

            <!-- STATION INFORMATION -->

            <div class="p-4 rounded-xl bg-surface-container-low border border-outline-variant flex flex-col sm:flex-row sm:items-center justify-between gap-3">

                <div class="flex items-center gap-3">

                    <div class="w-9 h-9 rounded-lg bg-surface-container flex items-center justify-center text-primary flex-shrink-0">

                        <span class="material-symbols-outlined text-lg">
                            schedule
                        </span>

                    </div>

                    <div>

                        <span class="text-sm font-semibold text-primary block">
                            Station Yard Gate Hours
                        </span>

                        <span class="text-sm text-on-surface-variant">

                            Open Monday – Saturday:

                            <strong class="text-primary font-semibold">
                                7:00 AM – 5:00 PM
                            </strong>

                            (Closed Sundays)

                        </span>

                    </div>

                </div>

                <div class="flex items-center gap-2 self-start sm:self-center text-sm">

                    <span class="text-on-surface-variant">
                        Hotline:
                    </span>

                    <span class="text-primary font-semibold bg-surface-container px-3 py-1 rounded-md border border-outline-variant">
                        (555) 019-4822
                    </span>

                </div>

            </div>

        </section>

    </main>

</div>

</body>
</html>
