
<?php
// ==========================================================
// AGRICOOP - MEMBER RESERVATIONS
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

$shortName = count($nameParts) > 1
    ? mb_substr($nameParts[0], 0, 1) . '. ' . $nameParts[count($nameParts) - 1]
    : $nameParts[0];

$farmLine = 'Cooperative member';


// ==========================================================
// RESERVATION FILTERS
// ==========================================================

function reservation_filters(array $src): array {

    $status = $src['status'] ?? 'all';

    if (!in_array($status, ['all', 'approved', 'pending', 'completed'], true)) {
        $status = 'all';
    }

    $month = $src['month'] ?? '';

    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = '';
    }

    return [
        'status' => $status,
        'month' => $month,
        'q' => trim($src['q'] ?? '')
    ];
}

$filters = reservation_filters($_GET);


// ==========================================================
// CANCEL RESERVATION
// ==========================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'cancel'
) {

    csrf_check();

    $filters = reservation_filters($_POST);
    $ref = trim($_POST['rental_ref'] ?? '');

    $own = $conn->prepare("
        SELECT rental_status
        FROM rentals
        WHERE rental_ref = ?
          AND member_id = ?
        LIMIT 1
    ");

    $own->bind_param('ss', $ref, $memberId);
    $own->execute();

    $row = $own->get_result()->fetch_assoc();

    if (!$row) {

        flash('err', 'That reservation was not found on your account.');

    } elseif (!in_array($row['rental_status'], ['pending', 'approved'], true)) {

        flash('err', 'Only pending or approved reservations can be cancelled.');

    } else {

        $conn->begin_transaction();

        try {

            $up = $conn->prepare("
                UPDATE rentals
                SET rental_status = 'cancelled'
                WHERE rental_ref = ?
                  AND member_id = ?
                  AND rental_status IN ('pending', 'approved')
            ");

            $up->bind_param('ss', $ref, $memberId);
            $up->execute();

            $items = $conn->prepare("
                UPDATE rental_items
                SET item_status = 'cancelled'
                WHERE rental_ref = ?
                  AND item_status = 'reserved'
            ");

            $items->bind_param('s', $ref);
            $items->execute();

            $conn->commit();

            flash('ok', $ref . ' was cancelled.');

        } catch (Throwable $ex) {

            $conn->rollback();

            flash('err', 'Could not cancel that reservation. Please try again.');
        }
    }

    $back = array_filter([
        'status' => $filters['status'] === 'all' ? '' : $filters['status'],
        'month' => $filters['month'],
        'q' => $filters['q']
    ], static fn($v) => $v !== '');

    header(
        'Location: User_Reservation.php' .
        ($back ? '?' . http_build_query($back) : '')
    );

    exit();
}


// ==========================================================
// RESERVATION QUERY FILTER BUILDER
// ==========================================================

function reservation_scope(
    string $memberId,
    string $month,
    string $q,
    string $status
): array {

    $sql = " WHERE r.member_id = ?";

    $types = 's';
    $params = [$memberId];

    if ($month !== '') {

        $start = $month . '-01';
        $end = date('Y-m-d', strtotime($start . ' +1 month'));

        $sql .= " AND r.pickup_date >= ? AND r.pickup_date < ?";

        $types .= 'ss';

        $params[] = $start;
        $params[] = $end;
    }

    if ($q !== '') {

        $like = '%' . $q . '%';

        $sql .= "
            AND (
                r.rental_ref LIKE ?
                OR EXISTS (
                    SELECT 1
                    FROM rental_items ri
                    JOIN equipment_units u
                        ON u.equipment_code = ri.equipment_code
                    JOIN equipment_types t
                        ON t.type_id = u.type_id
                    WHERE ri.rental_ref = r.rental_ref
                    AND (
                        t.type_name LIKE ?
                        OR ri.equipment_code LIKE ?
                    )
                )
            )
        ";

        $types .= 'sss';

        array_push($params, $like, $like, $like);
    }

    if ($status === 'approved') {

        $sql .= " AND r.rental_status = 'approved'";

    } elseif ($status === 'pending') {

        $sql .= " AND r.rental_status = 'pending'";

    } elseif ($status === 'completed') {

        $sql .= " AND r.rental_status = 'returned'";
    }

    return [$sql, $types, $params];
}


// ==========================================================
// RESERVATION COUNTS
// ==========================================================

[$countSql, $countTypes, $countParams] = reservation_scope(
    $memberId,
    $filters['month'],
    $filters['q'],
    'all'
);

$counts = q_all(
    $conn,
    "
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(r.rental_status = 'approved'), 0) AS approved,
        COALESCE(SUM(r.rental_status = 'pending'), 0) AS pending,
        COALESCE(SUM(r.rental_status = 'returned'), 0) AS completed
    FROM rentals r
    " . $countSql,
    $countTypes,
    $countParams
)[0];


// ==========================================================
// FETCH RESERVATIONS
// ==========================================================

[$listSql, $listTypes, $listParams] = reservation_scope(
    $memberId,
    $filters['month'],
    $filters['q'],
    $filters['status']
);

$rentals = q_all(
    $conn,
    "
    SELECT
        r.rental_ref,
        r.pickup_date,
        r.due_date,
        r.rental_status,
        r.created_at,
        approver.username AS approver_name
    FROM rentals r
    LEFT JOIN users approver
        ON approver.user_id = r.approved_by
    " . $listSql . "
    ORDER BY r.pickup_date DESC, r.rental_ref DESC
    ",
    $listTypes,
    $listParams
);


// ==========================================================
// FETCH EQUIPMENT ITEMS
// ==========================================================

$itemMap = [];

$refs = array_column($rentals, 'rental_ref');

if (!empty($refs)) {

    $in = implode(',', array_fill(0, count($refs), '?'));

    $itemStmt = $conn->prepare("
        SELECT
            ri.rental_ref,
            ri.equipment_code,
            t.type_name,
            t.icon,
            ri.daily_rate_at_booking,
            ri.late_fee,
            ri.item_status,
            ri.actual_return_date
        FROM rental_items ri
        JOIN equipment_units u
            ON u.equipment_code = ri.equipment_code
        JOIN equipment_types t
            ON t.type_id = u.type_id
        WHERE ri.rental_ref IN ($in)
        ORDER BY ri.rental_item_id
    ");

    $itemStmt->bind_param(str_repeat('s', count($refs)), ...$refs);
    $itemStmt->execute();

    foreach ($itemStmt->get_result() as $item) {
        $itemMap[$item['rental_ref']][] = $item;
    }
}


// ==========================================================
// FILTER URL HELPER
// ==========================================================

function res_keep(array $filters, ?string $status = null): string {

    $params = [];

    $useStatus = $status ?? $filters['status'];

    if ($useStatus !== 'all') {
        $params['status'] = $useStatus;
    }

    if ($filters['month'] !== '') {
        $params['month'] = $filters['month'];
    }

    if ($filters['q'] !== '') {
        $params['q'] = $filters['q'];
    }

    return $params ? '?' . http_build_query($params) : '';
}


// ==========================================================
// STATUS BADGE
// ==========================================================

function res_badge(string $status): string {

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
        ]
    ];

    [$label, $cls, $dot] = $map[$status] ?? $map['cancelled'];

    return '<span class="px-3 py-1 rounded-full ' . $cls .
        ' text-xs font-medium inline-flex items-center gap-1.5 self-start sm:self-center">'
        . '<span class="w-1.5 h-1.5 rounded-full ' . $dot . '"></span>'
        . e($label)
        . '</span>';
}


// ==========================================================
// DASHBOARD-STYLE FILTER CHIP
// ==========================================================

function res_chip(
    string $key,
    string $label,
    int $count,
    array $filters
): string {

    $active = $filters['status'] === $key;

    if ($active) {

        $class = 'flex items-center gap-3 px-3 py-2.5 rounded-lg bg-surface-container text-primary text-sm font-semibold border-l-4 border-primary transition-colors';

        $countClass = 'bg-primary-container text-on-primary';

    } else {

        $class = 'flex items-center gap-3 px-3 py-2.5 rounded-lg text-on-surface-variant text-sm hover:bg-surface-container-low transition-colors';

        $countClass = 'bg-surface-container text-on-surface-variant';
    }

    return '
        <a href="User_Reservation.php' . e(res_keep($filters, $key)) . '"
           class="' . $class . '"
           role="tab"
           aria-selected="' . ($active ? 'true' : 'false') . '">

            <span>' . e($label) . '</span>

            <span class="text-xs font-semibold px-2 py-0.5 rounded-md ' . $countClass . '">
                ' . $count . '
            </span>

        </a>
    ';
}


// ==========================================================
// PAGE TITLE
// ==========================================================

$scheduleTitle = 'All Reservations';

if ($filters['status'] === 'completed') {
    $scheduleTitle = 'Completed Rentals';
} elseif ($filters['status'] === 'pending') {
    $scheduleTitle = 'Pending Requests';
} elseif ($filters['status'] === 'approved') {
    $scheduleTitle = 'Approved Schedule';
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>AgriCoop Member - My Reservations</title>

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
     SIDEBAR - SAME AS USER DASHBOARD
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

            <a href="UserDashboard.php"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-on-surface-variant text-sm hover:bg-surface-container-low transition-colors">

                <span class="material-symbols-outlined">
                    dashboard
                </span>

                <span>Dashboard</span>

            </a>


            <a href="User_Browse.php"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-on-surface-variant text-sm hover:bg-surface-container-low transition-colors">

                <span class="material-symbols-outlined">
                    agriculture
                </span>

                <span>Browse Equipment</span>

            </a>


            <!-- RESERVATIONS ACTIVE -->

            <a href="User_Reservation.php"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-surface-container text-primary text-sm font-semibold border-l-4 border-primary">

                <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">
                    event_note
                </span>

                <span>My Reservations</span>

            </a>


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

            <form method="get" action="User_Reservation.php" class="relative w-64 md:w-80">

                <?php if ($filters['status'] !== 'all'): ?>
                    <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
                <?php endif; ?>

                <?php if ($filters['month'] !== ''): ?>
                    <input type="hidden" name="month" value="<?= e($filters['month']) ?>">
                <?php endif; ?>

                <input
                    name="q"
                    value="<?= e($filters['q']) ?>"
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


    <!-- =================================================
         PAGE CONTENT
    ================================================== -->

    <main class="flex-1 p-6 md:p-8 space-y-8 max-w-7xl w-full mx-auto">

        <?= flash_html() ?>


        <!-- PAGE TITLE -->

        <section class="flex flex-col md:flex-row md:items-end justify-between gap-4">

            <div>

                <span class="text-xs text-primary font-semibold tracking-wider uppercase">
                    Member Dashboard
                </span>

                <h1 class="text-2xl font-bold text-primary tracking-tight mt-1">
                    My Reservations
                </h1>

                <p class="text-sm text-on-surface-variant mt-1">
                    View your bookings, rental statuses, and reservation schedules.
                    Member Account <?= e($member['member_id']) ?>.
                </p>

            </div>


            <!-- MONTH FILTER -->

            <form method="get" action="User_Reservation.php" class="flex items-center gap-2.5">

                <?php if ($filters['status'] !== 'all'): ?>
                    <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
                <?php endif; ?>

                <?php if ($filters['q'] !== ''): ?>
                    <input type="hidden" name="q" value="<?= e($filters['q']) ?>">
                <?php endif; ?>

                <label class="inline-flex items-center gap-2 px-3 py-2 border border-outline-variant bg-surface-container-lowest rounded-lg text-sm text-on-surface hover:bg-surface-container-low transition-colors cursor-pointer">

                    <span class="material-symbols-outlined text-sm">
                        calendar_month
                    </span>

                    <input
                        class="bg-transparent border-0 p-0 text-sm text-on-surface focus:ring-0"
                        name="month"
                        type="month"
                        value="<?= e($filters['month']) ?>"
                        onchange="this.form.submit()"
                        aria-label="Filter by pickup month"
                    >

                </label>

                <?php if ($filters['month'] !== ''): ?>

                    <a class="text-sm text-primary hover:underline"
                       href="User_Reservation.php<?= e(res_keep(['status' => $filters['status'], 'month' => '', 'q' => $filters['q']])) ?>">

                        Clear

                    </a>

                <?php endif; ?>

            </form>

        </section>


        <!-- =================================================
             RESERVATION FILTERS - DASHBOARD STYLE
        ================================================== -->

        <section class="space-y-3">

            <div class="flex flex-wrap items-center gap-1.5"
                 role="tablist"
                 aria-label="Reservation Status Filter">

                <?= res_chip('all', 'All', (int)$counts['total'], $filters) ?>

                <?= res_chip('pending', 'Pending', (int)$counts['pending'], $filters) ?>

                <?= res_chip('approved', 'Approved', (int)$counts['approved'], $filters) ?>

                <?= res_chip('completed', 'Completed', (int)$counts['completed'], $filters) ?>

            </div>

        </section>


        <!-- =================================================
             RESERVATION TABLE
        ================================================== -->

        <section class="bg-surface-container-lowest rounded-xl border border-outline-variant shadow-sm overflow-hidden">

            <div class="flex items-center justify-between px-6 py-5 border-b border-outline-variant">

                <div>

                    <h3 class="text-lg font-semibold text-primary">
                        <?= e($scheduleTitle) ?>
                    </h3>

                    <p class="text-sm text-on-surface-variant mt-0.5">
                        Overview of your equipment reservations
                    </p>

                </div>

                <div class="w-10 h-10 rounded-lg bg-surface-container flex items-center justify-center text-primary">

                    <span class="material-symbols-outlined">
                        event_note
                    </span>

                </div>

            </div>


            <div class="overflow-x-auto">

                <table class="w-full text-left border-collapse">

                    <thead>

                        <tr class="border-b border-outline-variant bg-surface-container-low text-xs text-on-surface-variant uppercase tracking-wider">

                            <th class="py-3 px-4">Reference #</th>

                            <th class="py-3 px-4">Equipment Name</th>

                            <th class="py-3 px-4">Pickup Date</th>

                            <th class="py-3 px-4">Due Date</th>

                            <th class="py-3 px-4">Daily Rate</th>

                            <th class="py-3 px-4">Total Cost</th>

                            <th class="py-3 px-4">Status</th>

                            <th class="py-3 px-4 text-right">Actions</th>

                        </tr>

                    </thead>


                    <tbody class="divide-y divide-outline-variant text-sm text-on-surface">

                    <?php if (empty($rentals)): ?>

                        <tr>

                            <td colspan="8" class="py-12 text-center">

                                <div class="flex flex-col items-center">

                                    <span class="material-symbols-outlined text-4xl text-outline mb-2">
                                        event_busy
                                    </span>

                                    <p class="font-semibold text-on-surface">
                                        No Reservations Found
                                    </p>

                                    <p class="text-sm text-on-surface-variant mt-1">
                                        There are no reservations matching your selected filters.
                                    </p>

                                    <a href="User_Reservation.php"
                                       class="mt-4 text-sm text-primary font-semibold hover:underline">

                                        Clear Filters

                                    </a>

                                </div>

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($rentals as $rent):

                            $items = $itemMap[$rent['rental_ref']] ?? [];

                            $firstItem = $items[0] ?? null;

                            $days = max(
                                1,
                                (strtotime($rent['due_date']) - strtotime($rent['pickup_date'])) / 86400
                            );

                            $totalCost = 0;

                            foreach ($items as $it) {
                                $totalCost += (float)$it['daily_rate_at_booking'] * $days;
                            }

                        ?>

                            <tr class="hover:bg-surface-container-low transition-colors">

                                <!-- REFERENCE -->

                                <td class="py-4 px-4 font-semibold text-primary whitespace-nowrap">

                                    <?= e($rent['rental_ref']) ?>

                                </td>


                                <!-- EQUIPMENT -->

                                <td class="py-4 px-4">

                                    <div class="font-semibold text-on-background">

                                        <?= e($firstItem['type_name'] ?? 'Multiple Items') ?>

                                    </div>

                                    <div class="text-xs text-on-surface-variant flex items-center gap-1 mt-1">

                                        <span class="material-symbols-outlined text-xs">
                                            tag
                                        </span>

                                        Unit #<?= e($firstItem['equipment_code'] ?? 'N/A') ?>

                                        <?php if (count($items) > 1): ?>

                                            (+<?= count($items) - 1 ?> more)

                                        <?php endif; ?>

                                    </div>

                                </td>


                                <!-- PICKUP -->

                                <td class="py-4 px-4 whitespace-nowrap">

                                    <?= e($rent['pickup_date']) ?>

                                </td>


                                <!-- DUE DATE -->

                                <td class="py-4 px-4 whitespace-nowrap">

                                    <?= e($rent['due_date']) ?>

                                </td>


                                <!-- DAILY RATE -->

                                <td class="py-4 px-4 whitespace-nowrap">

                                    ₱<?= number_format((float)($firstItem['daily_rate_at_booking'] ?? 0), 2) ?>/day

                                </td>


                                <!-- TOTAL COST -->

                                <td class="py-4 px-4 font-semibold whitespace-nowrap">

                                    ₱<?= number_format($totalCost, 2) ?>

                                </td>


                                <!-- STATUS -->

                                <td class="py-4 px-4 whitespace-nowrap">

                                    <?= res_badge($rent['rental_status']) ?>

                                </td>


                                <!-- ACTIONS -->

                                <td class="py-4 px-4 text-right">

                                    <?php if (in_array($rent['rental_status'], ['pending', 'approved'], true)): ?>

                                        <form
                                            method="POST"
                                            action="User_Reservation.php<?= e(res_keep($filters)) ?>"
                                            onsubmit="return confirm('Are you sure you want to cancel this reservation?');"
                                            class="inline">

                                            <?= csrf_field() ?>

                                            <input type="hidden" name="action" value="cancel">

                                            <input
                                                type="hidden"
                                                name="rental_ref"
                                                value="<?= e($rent['rental_ref']) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="status"
                                                value="<?= e($filters['status']) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="month"
                                                value="<?= e($filters['month']) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="q"
                                                value="<?= e($filters['q']) ?>"
                                            >

                                            <button
                                                class="px-3 py-1.5 bg-surface-container-lowest hover:bg-error-container text-error text-xs font-semibold rounded-lg border border-outline-variant hover:border-error transition-colors"
                                                type="submit">

                                                Cancel

                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <span class="text-xs text-outline italic">
                                            Closed
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>


        <!-- =================================================
             COOPERATIVE RENTAL POLICIES
        ================================================== -->

        <section class="bg-surface-container-lowest rounded-xl border border-outline-variant p-6 shadow-sm">

            <div class="flex items-center gap-3 pb-4 border-b border-outline-variant">

                <div class="w-10 h-10 rounded-lg bg-surface-container flex items-center justify-center text-primary">

                    <span class="material-symbols-outlined">
                        policy
                    </span>

                </div>

                <div>

                    <h3 class="text-lg font-semibold text-primary">
                        Cooperative Rental Policies
                    </h3>

                    <p class="text-sm text-on-surface-variant">
                        Guidelines for equipment reservations and rentals.
                    </p>

                </div>

            </div>


            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-5">


                <!-- POLICY 1 -->

                <div class="p-4 rounded-lg bg-surface-container-low border border-outline-variant">

                    <div class="flex items-start gap-3">

                        <div class="w-8 h-8 rounded-lg bg-surface-container flex items-center justify-center text-primary font-semibold text-sm flex-shrink-0">
                            1
                        </div>

                        <div>

                            <h4 class="font-semibold text-on-surface text-sm mb-1">
                                Return of Equipment
                            </h4>

                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                Members should return all rented equipment on or before the agreed due date and ensure that equipment is properly maintained during use.
                            </p>

                        </div>

                    </div>

                </div>


                <!-- POLICY 2 -->

                <div class="p-4 rounded-lg bg-surface-container-low border border-outline-variant">

                    <div class="flex items-start gap-3">

                        <div class="w-8 h-8 rounded-lg bg-surface-container flex items-center justify-center text-primary font-semibold text-sm flex-shrink-0">
                            2
                        </div>

                        <div>

                            <h4 class="font-semibold text-on-surface text-sm mb-1">
                                Reservation Schedule
                            </h4>

                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                Members should follow the approved pickup and return schedule. Any changes should be coordinated with cooperative staff.
                            </p>

                        </div>

                    </div>

                </div>


                <!-- POLICY 3 -->

                <div class="p-4 rounded-lg bg-surface-container-low border border-outline-variant">

                    <div class="flex items-start gap-3">

                        <div class="w-8 h-8 rounded-lg bg-surface-container flex items-center justify-center text-primary font-semibold text-sm flex-shrink-0">
                            3
                        </div>

                        <div>

                            <h4 class="font-semibold text-on-surface text-sm mb-1">
                                Equipment Responsibility
                            </h4>

                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                Members are responsible for using rented equipment properly and reporting any damage or operational issues to cooperative staff.
                            </p>

                        </div>

                    </div>

                </div>

            </div>

        </section>

    </main>

</div>

</body>
</html>
