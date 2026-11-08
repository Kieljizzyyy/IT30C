
<?php
// ==========================================================
// AGRICOOP - MEMBER QUICK RESERVATION
// ==========================================================

require_once __DIR__ . '/auth.php';
require_role('member');

$userId = $_SESSION['user_id'] ?? null;
$memberId = $_SESSION['member_id'] ?? '';


// ==========================================================
// MEMBER INFORMATION
// ==========================================================

$stmtMember = $conn->prepare("
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

$stmtMember->bind_param('s', $memberId);
$stmtMember->execute();

$currentMember = $stmtMember->get_result()->fetch_assoc();

if (!$currentMember && $userId) {

    $stmtMember = $conn->prepare("
        SELECT
            m.member_id,
            m.member_name,
            m.contact,
            u.status
        FROM members m
        INNER JOIN users u ON u.user_id = m.user_id
        WHERE m.user_id = ?
        LIMIT 1
    ");

    $stmtMember->bind_param('i', $userId);
    $stmtMember->execute();

    $currentMember = $stmtMember->get_result()->fetch_assoc();
}

if (
    !$currentMember ||
    $currentMember['status'] !== 'active'
) {
    header('Location: logout.php');
    exit();
}

$memberId = $currentMember['member_id'];

$nameParts = preg_split(
    '/\s+/',
    trim($currentMember['member_name'])
) ?: [$currentMember['member_name']];

$shortName = count($nameParts) > 1
    ? mb_substr($nameParts[0], 0, 1) . '. ' . $nameParts[count($nameParts) - 1]
    : $nameParts[0];

$farmLine = 'Cooperative member';


// ==========================================================
// FETCH EQUIPMENT
// Only units in good physical condition are selectable.
// Date availability is checked again during submission.
// ==========================================================

$sqlEquipment = "
    SELECT
        et.type_id,
        et.type_name,
        et.description,
        ec.category_name,
        eu.equipment_code,
        eu.daily_rate,
        eu.condition_status
    FROM equipment_types et
    INNER JOIN equipment_categories ec
        ON et.category_id = ec.category_id
    INNER JOIN equipment_units eu
        ON et.type_id = eu.type_id
    WHERE eu.condition_status = 'good'
    ORDER BY et.type_name ASC, eu.equipment_code ASC
";

$resultEquip = $conn->query($sqlEquipment);

$equipmentList = $resultEquip
    ? $resultEquip->fetch_all(MYSQLI_ASSOC)
    : [];


// ==========================================================
// HANDLE RESERVATION SUBMISSION
// ==========================================================

$successMessage = '';
$errorMessage = '';

$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_check();

    $equipmentCode = trim($_POST['equipment_code'] ?? '');
    $pickupDate = trim($_POST['pickup_date'] ?? '');
    $dueDate = trim($_POST['due_date'] ?? '');

    // Validate date format.
    $pickupObject = DateTime::createFromFormat('!Y-m-d', $pickupDate);
    $dueObject = DateTime::createFromFormat('!Y-m-d', $dueDate);

    $validPickup = $pickupObject &&
        $pickupObject->format('Y-m-d') === $pickupDate;

    $validDue = $dueObject &&
        $dueObject->format('Y-m-d') === $dueDate;

    if (
        $equipmentCode === '' ||
        $pickupDate === '' ||
        $dueDate === ''
    ) {

        $errorMessage = 'Please complete all reservation fields.';

    } elseif (!$validPickup || !$validDue) {

        $errorMessage = 'Please enter valid pickup and due dates.';

    } elseif ($pickupDate < $today) {

        $errorMessage = 'Pickup date cannot be earlier than today.';

    } elseif ($dueDate < $pickupDate) {

        $errorMessage = 'Due date cannot be earlier than the pickup date.';

    } else {

        $conn->begin_transaction();

        try {

            // --------------------------------------------------
            // 1. LOCK THE SELECTED EQUIPMENT UNIT
            // --------------------------------------------------

            $stmtUnit = $conn->prepare("
                SELECT
                    equipment_code,
                    daily_rate,
                    condition_status
                FROM equipment_units
                WHERE equipment_code = ?
                LIMIT 1
                FOR UPDATE
            ");

            $stmtUnit->bind_param('s', $equipmentCode);
            $stmtUnit->execute();

            $unitData = $stmtUnit->get_result()->fetch_assoc();

            if (!$unitData) {
                throw new Exception('The selected equipment unit does not exist.');
            }

            if ($unitData['condition_status'] !== 'good') {
                throw new Exception('This equipment unit is currently not available for reservation.');
            }

            $dailyRate = (float)$unitData['daily_rate'];

            if ($dailyRate < 0) {
                throw new Exception('Invalid equipment rental rate.');
            }


            // --------------------------------------------------
            // 2. CHECK DATE OVERLAPPING
            //
            // Pending, approved, and issued rentals block the
            // equipment unit.
            //
            // A unit with no actual return date remains blocked
            // until the rental is properly returned.
            // --------------------------------------------------

            $stmtConflict = $conn->prepare("
                SELECT r.rental_ref
                FROM rental_items ri
                INNER JOIN rentals r
                    ON r.rental_ref = ri.rental_ref
                WHERE ri.equipment_code = ?
                  AND r.rental_status IN ('pending', 'approved', 'issued')
                  AND ri.item_status IN ('reserved', 'issued')
                  AND r.pickup_date <= ?
                  AND (
                      ri.actual_return_date IS NULL
                      OR ri.actual_return_date >= ?
                  )
                LIMIT 1
            ");

            $stmtConflict->bind_param(
                'sss',
                $equipmentCode,
                $dueDate,
                $pickupDate
            );

            $stmtConflict->execute();

            $conflict = $stmtConflict->get_result()->fetch_assoc();

            if ($conflict) {
                throw new Exception(
                    'This equipment unit already has a reservation or active rental that overlaps your selected dates. Please choose another unit or schedule.'
                );
            }


            // --------------------------------------------------
            // 3. GENERATE A UNIQUE RENTAL REFERENCE
            // --------------------------------------------------

            $rentalRef = '';

            for ($attempt = 0; $attempt < 5; $attempt++) {

                $candidate = 'REN-' . date('ymd') . random_int(100, 999);

                $checkRef = $conn->prepare("
                    SELECT rental_ref
                    FROM rentals
                    WHERE rental_ref = ?
                    LIMIT 1
                ");

                $checkRef->bind_param('s', $candidate);
                $checkRef->execute();

                if (!$checkRef->get_result()->fetch_assoc()) {
                    $rentalRef = $candidate;
                    break;
                }
            }

            if ($rentalRef === '') {
                throw new Exception('Could not generate a reservation reference. Please try again.');
            }


            // --------------------------------------------------
            // 4. INSERT RENTAL
            // --------------------------------------------------

            $insertRental = $conn->prepare("
                INSERT INTO rentals (
                    rental_ref,
                    member_id,
                    approved_by,
                    pickup_date,
                    due_date,
                    rental_status
                )
                VALUES (?, ?, NULL, ?, ?, 'pending')
            ");

            $insertRental->bind_param(
                'ssss',
                $rentalRef,
                $memberId,
                $pickupDate,
                $dueDate
            );

            $insertRental->execute();


            // --------------------------------------------------
            // 5. INSERT RENTAL ITEM
            // --------------------------------------------------

            $insertItem = $conn->prepare("
                INSERT INTO rental_items (
                    rental_ref,
                    equipment_code,
                    daily_rate_at_booking,
                    actual_return_date,
                    late_fee,
                    item_status
                )
                VALUES (?, ?, ?, NULL, 0, 'reserved')
            ");

            $insertItem->bind_param(
                'ssd',
                $rentalRef,
                $equipmentCode,
                $dailyRate
            );

            $insertItem->execute();


            // --------------------------------------------------
            // 6. COMMIT TRANSACTION
            // --------------------------------------------------

            $conn->commit();

            flash(
                'ok',
                'Reservation request successfully submitted! Reference Code: ' . $rentalRef
            );

            header('Location: User_Reservation.php');
            exit();

        } catch (Throwable $ex) {

            $conn->rollback();

            // Log technical details for debugging.
            error_log('Quick Reservation Error: ' . $ex->getMessage());

            $errorMessage = $ex instanceof mysqli_sql_exception
                ? 'Unable to process your reservation. Please check your details and try again.'
                : $ex->getMessage();
        }
    }
}


// ==========================================================
// INITIAL DATE VALUES
// ==========================================================

$defaultPickup = date('Y-m-d', strtotime('+1 day'));
$defaultDue = date('Y-m-d', strtotime('+4 days'));

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Reserve Equipment - AgriCoop</title>

<!-- MATERIAL SYMBOLS -->

<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">

<!-- GOOGLE FONTS -->

<link href="https://fonts.googleapis.com" rel="preconnect">
<link crossorigin href="https://fonts.gstatic.com" rel="preconnect">

<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Work+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

<!-- TAILWIND -->

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
     SIDEBAR - SAME STRUCTURE AS USER DASHBOARD
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

            <span class="material-symbols-outlined">
                add_circle
            </span>

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


            <a href="User_Reservation.php"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-on-surface-variant text-sm hover:bg-surface-container-low transition-colors">

                <span class="material-symbols-outlined">
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
                        <?= e($shortName) ?> (#<?= e($currentMember['member_id']) ?>)
                    </span>

                    <span class="text-[11px] text-outline truncate">
                        <?= e($farmLine) ?>
                    </span>

                </div>

            </div>

        </div>


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


        <div class="flex items-center gap-4">

            <span class="text-sm font-medium text-primary">
                Logged in as: <?= e($currentMember['member_name']) ?>
            </span>

        </div>

    </header>


    <!-- =================================================
         PAGE CONTENT
    ================================================== -->

    <main class="flex-1 p-6 md:p-8 space-y-8 max-w-7xl w-full mx-auto">


        <!-- PAGE TITLE -->

        <section class="flex flex-col md:flex-row md:items-end justify-between gap-4">

            <div>

                <span class="text-xs text-primary font-semibold tracking-wider uppercase">
                    Member Dashboard
                </span>

                <div class="flex items-center gap-3 mt-1">

                    <span class="material-symbols-outlined text-primary text-3xl">
                        calendar_month
                    </span>

                    <h1 class="text-2xl font-bold text-primary tracking-tight">
                        Reserve Equipment
                    </h1>

                </div>

                <p class="text-sm text-on-surface-variant mt-1">
                    Submit a reservation request for machinery and farm implements from Valley Basin Co-op Yard.
                </p>

            </div>

        </section>


        <!-- ALERTS -->

        <?= flash_html() ?>


        <?php if ($successMessage !== ''): ?>

            <div class="p-4 bg-primary-fixed text-on-primary-fixed rounded-lg border border-primary/20 flex items-center gap-3">

                <span class="material-symbols-outlined">
                    check_circle
                </span>

                <div><?= e($successMessage) ?></div>

            </div>

        <?php endif; ?>


        <?php if ($errorMessage !== ''): ?>

            <div class="p-4 bg-error-container text-on-error-container rounded-lg border border-error/20 flex items-center gap-3">

                <span class="material-symbols-outlined">
                    error
                </span>

                <div><?= e($errorMessage) ?></div>

            </div>

        <?php endif; ?>


        <!-- NO EQUIPMENT MESSAGE -->

        <?php if (empty($equipmentList)): ?>

            <section class="bg-surface-container-lowest border border-outline-variant rounded-xl p-10 text-center">

                <span class="material-symbols-outlined text-5xl text-outline">
                    inventory_2
                </span>

                <h2 class="text-lg font-semibold text-primary mt-3">
                    No Equipment Currently Listed
                </h2>

                <p class="text-sm text-on-surface-variant mt-2">
                    There are currently no equipment units in good condition available for reservation.
                </p>

                <a href="User_Browse.php"
                   class="inline-flex items-center gap-2 mt-5 px-4 py-2 bg-primary text-on-primary rounded-lg text-sm font-semibold hover:bg-primary-container">

                    <span class="material-symbols-outlined text-sm">
                        arrow_back
                    </span>

                    Browse Equipment

                </a>

            </section>

        <?php else: ?>


        <!-- =================================================
             RESERVATION FORM
        ================================================== -->

        <form method="POST" action="User_QuickReserve.php"
              class="grid grid-cols-1 lg:grid-cols-12 gap-6">

            <?= csrf_field() ?>


            <!-- LEFT COLUMN -->

            <div class="lg:col-span-8 space-y-6">


                <!-- STEP 1: EQUIPMENT SELECTION -->

                <section class="bg-surface-container-lowest border border-outline-variant rounded-xl p-6 shadow-sm">

                    <div class="flex items-center gap-3 mb-5">

                        <span class="w-7 h-7 rounded-full bg-primary-container text-on-primary text-sm flex items-center justify-center font-bold">
                            1
                        </span>

                        <div>

                            <h2 class="text-lg font-semibold text-on-surface">
                                Equipment Selection
                            </h2>

                            <p class="text-sm text-on-surface-variant">
                                Select an equipment unit from the cooperative fleet.
                            </p>

                        </div>

                    </div>


                    <div class="space-y-4">

                        <div>

                            <label for="equipment-select"
                                   class="block text-sm text-on-surface mb-2 font-medium">

                                Select Machinery or Implement Unit

                            </label>


                            <select
                                name="equipment_code"
                                id="equipment-select"
                                required
                                class="w-full bg-surface-container-low border border-outline-variant rounded-lg px-4 py-2.5 text-sm text-on-surface focus:outline-none focus:border-primary">

                                <option value="">
                                    Choose an equipment unit
                                </option>

                                <?php foreach ($equipmentList as $item): ?>

                                    <option
                                        value="<?= e($item['equipment_code']) ?>"
                                        data-name="<?= e($item['type_name']) ?>"
                                        data-cat="<?= e($item['category_name']) ?>"
                                        data-rate="<?= e($item['daily_rate']) ?>"
                                        data-tag="<?= e($item['equipment_code']) ?>"
                                        data-status="<?= e($item['condition_status']) ?>">

                                        <?= e($item['type_name']) ?>
                                        (Unit: <?= e($item['equipment_code']) ?>)
                                        — ₱<?= number_format((float)$item['daily_rate'], 2) ?>/day

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <!-- SELECTED EQUIPMENT CARD -->

                        <div class="p-4 rounded-lg bg-surface-container-low border border-outline-variant flex flex-col sm:flex-row sm:items-center justify-between gap-4">

                            <div class="flex items-center gap-4">

                                <div class="w-12 h-12 rounded-lg bg-surface-container-lowest border border-outline-variant flex items-center justify-center text-primary">

                                    <span class="material-symbols-outlined text-[28px]">
                                        precision_manufacturing
                                    </span>

                                </div>


                                <div>

                                    <div class="flex items-center gap-2 flex-wrap">

                                        <h4 class="text-base font-semibold text-primary"
                                            id="display-equipment-name">

                                            Select Equipment

                                        </h4>

                                        <span id="display-badge"
                                              class="px-2 py-0.5 rounded text-xs bg-surface-container text-on-surface-variant font-medium">

                                            Not selected

                                        </span>

                                    </div>


                                    <p class="text-sm text-on-surface-variant mt-1">

                                        Category:

                                        <span class="font-medium text-on-surface"
                                              id="display-equipment-cat">

                                            —

                                        </span>

                                        <span class="mx-1">|</span>

                                        Unit Tag:

                                        <span id="display-equipment-tag">
                                            —
                                        </span>

                                    </p>

                                </div>

                            </div>


                            <div class="text-left sm:text-right sm:border-l sm:border-outline-variant sm:pl-4">

                                <p class="text-xs text-on-surface-variant uppercase">
                                    Daily Member Rate
                                </p>

                                <p class="text-lg text-primary font-bold"
                                   id="display-equipment-rate">

                                    ₱0.00

                                    <span class="text-sm font-normal text-on-surface-variant">
                                        /day
                                    </span>

                                </p>

                            </div>

                        </div>


                        <p class="text-xs text-on-surface-variant flex items-center gap-2">

                            <span class="material-symbols-outlined text-sm text-primary">
                                info
                            </span>

                            Equipment must still be available for your selected rental dates. Availability is verified when you submit.

                        </p>

                    </div>

                </section>


                <!-- STEP 2: SCHEDULE -->

                <section class="bg-surface-container-lowest border border-outline-variant rounded-xl p-6 shadow-sm">

                    <div class="flex items-center gap-3 mb-5">

                        <span class="w-7 h-7 rounded-full bg-primary-container text-on-primary text-sm flex items-center justify-center font-bold">
                            2
                        </span>

                        <div>

                            <h2 class="text-lg font-semibold text-on-surface">
                                Schedule & Rental Duration
                            </h2>

                            <p class="text-sm text-on-surface-variant">
                                Select your intended pickup and return dates.
                            </p>

                        </div>

                    </div>


                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">


                        <!-- PICKUP DATE -->

                        <div>

                            <label for="pickup-date"
                                   class="block text-sm text-on-surface mb-2 font-medium">

                                Pickup Date

                            </label>

                            <div class="relative">

                                <span class="material-symbols-outlined absolute left-3 top-2.5 text-outline text-[18px]">
                                    calendar_today
                                </span>

                                <input
                                    name="pickup_date"
                                    id="pickup-date"
                                    class="w-full pl-9 pr-3 py-2.5 bg-surface-container-low border border-outline-variant rounded-lg text-sm text-on-surface focus:outline-none focus:border-primary"
                                    type="date"
                                    min="<?= e($today) ?>"
                                    value="<?= e($_POST['pickup_date'] ?? $defaultPickup) ?>"
                                    required
                                >

                            </div>

                        </div>


                        <!-- DUE DATE -->

                        <div>

                            <label for="return-date"
                                   class="block text-sm text-on-surface mb-2 font-medium">

                                Expected Return / Due Date

                            </label>

                            <div class="relative">

                                <span class="material-symbols-outlined absolute left-3 top-2.5 text-outline text-[18px]">
                                    event_repeat
                                </span>

                                <input
                                    name="due_date"
                                    id="return-date"
                                    class="w-full pl-9 pr-3 py-2.5 bg-surface-container-low border border-outline-variant rounded-lg text-sm text-on-surface focus:outline-none focus:border-primary"
                                    type="date"
                                    min="<?= e($today) ?>"
                                    value="<?= e($_POST['due_date'] ?? $defaultDue) ?>"
                                    required
                                >

                            </div>

                        </div>

                    </div>

                </section>


                <!-- STEP 3: AGREEMENT -->

                <section class="bg-surface-container-lowest border border-outline-variant rounded-xl p-6 shadow-sm">

                    <div class="flex items-center gap-3 mb-5">

                        <span class="w-7 h-7 rounded-full bg-primary-container text-on-primary text-sm flex items-center justify-center font-bold">
                            3
                        </span>

                        <div>

                            <h2 class="text-lg font-semibold text-on-surface">
                                Reservation Agreement
                            </h2>

                            <p class="text-sm text-on-surface-variant">
                                Review your reservation details before submitting.
                            </p>

                        </div>

                    </div>


                    <div class="p-4 bg-surface-container-low rounded-lg border border-outline-variant flex items-start gap-3">

                        <input
                            required
                            class="mt-1 h-4 w-4 rounded border-outline text-primary focus:ring-primary cursor-pointer"
                            id="equipment-agreement"
                            type="checkbox"
                            value="1"
                        >

                        <label for="equipment-agreement"
                               class="text-sm text-on-surface leading-relaxed cursor-pointer">

                            I understand that submitting this form creates a reservation request and does not automatically approve my rental. I agree to follow the cooperative's equipment handling and return guidelines.

                        </label>

                    </div>

                </section>

            </div>


            <!-- RIGHT COLUMN: SUMMARY -->

            <div class="lg:col-span-4">

                <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-6 shadow-sm sticky top-24">


                    <!-- SUMMARY HEADER -->

                    <div class="border-b border-outline-variant pb-4 mb-4">

                        <span class="text-xs uppercase tracking-wider text-primary font-bold">
                            Billing Breakdown
                        </span>

                        <h3 class="text-lg text-primary mt-1 font-semibold">
                            Reservation Summary
                        </h3>

                    </div>


                    <div class="space-y-4 text-sm">


                        <!-- EQUIPMENT -->

                        <div class="flex justify-between gap-3 py-1">

                            <span class="text-on-surface-variant">
                                Machinery Unit
                            </span>

                            <span class="font-semibold text-on-surface text-right"
                                  id="summary-unit">

                                —

                            </span>

                        </div>


                        <!-- DURATION -->

                        <div class="flex justify-between gap-3 py-1">

                            <span class="text-on-surface-variant">
                                Rental Duration
                            </span>

                            <span class="font-semibold text-on-surface"
                                  id="summary-days">

                                1 Day

                            </span>

                        </div>


                        <!-- RATE -->

                        <div class="flex justify-between gap-3 py-1">

                            <span class="text-on-surface-variant">
                                Daily Co-op Rate
                            </span>

                            <span class="font-medium text-on-surface"
                                  id="summary-rate">

                                ₱0.00 / day

                            </span>

                        </div>


                        <!-- TOTAL -->

                        <div class="border-t border-outline-variant pt-4 flex justify-between items-baseline gap-3">

                            <div>

                                <span class="text-base font-bold text-on-surface">
                                    Estimated Total
                                </span>

                                <p class="text-xs text-on-surface-variant">
                                    Estimated rental cost
                                </p>

                            </div>

                            <span class="text-xl text-primary font-bold"
                                  id="summary-total">

                                ₱0.00

                            </span>

                        </div>

                    </div>


                    <!-- INFORMATION -->

                    <div class="mt-5 p-3.5 bg-surface-container rounded-lg border border-outline-variant text-xs flex gap-3 items-start">

                        <span class="material-symbols-outlined text-primary text-[20px] flex-shrink-0 mt-0.5">
                            info
                        </span>

                        <p class="text-on-surface-variant leading-relaxed">

                            <strong class="text-on-surface font-semibold">
                                Important:
                            </strong>

                            Your reservation will remain pending until cooperative staff reviews and approves your request.

                        </p>

                    </div>


                    <!-- BUTTONS -->

                    <div class="mt-6 flex flex-col gap-3">


                        <button
                            type="submit"
                            class="w-full h-11 bg-primary text-on-primary hover:bg-primary-container rounded-lg text-sm font-semibold flex items-center justify-center gap-2 transition-colors shadow-sm">

                            <span class="material-symbols-outlined text-[18px]">
                                check_circle
                            </span>

                            Submit Reservation Request

                        </button>


                        <a href="UserDashboard.php"
                           class="w-full h-10 border border-primary text-primary hover:bg-surface-container-low rounded-lg text-sm font-semibold flex items-center justify-center transition-colors">

                            Cancel / Dashboard

                        </a>

                    </div>

                </div>

            </div>

        </form>

        <?php endif; ?>

    </main>

</div>


<!-- =====================================================
     DYNAMIC RESERVATION SUMMARY
====================================================== -->

<script>

document.addEventListener('DOMContentLoaded', function () {

    const equipSelect = document.getElementById('equipment-select');

    const pickupInput = document.getElementById('pickup-date');

    const returnInput = document.getElementById('return-date');


    const displayName = document.getElementById('display-equipment-name');

    const displayCat = document.getElementById('display-equipment-cat');

    const displayTag = document.getElementById('display-equipment-tag');

    const displayRate = document.getElementById('display-equipment-rate');

    const displayBadge = document.getElementById('display-badge');


    const summaryUnit = document.getElementById('summary-unit');

    const summaryDays = document.getElementById('summary-days');

    const summaryRate = document.getElementById('summary-rate');

    const summaryTotal = document.getElementById('summary-total');


    if (!equipSelect || !pickupInput || !returnInput) {
        return;
    }


    // Philippine Peso formatter.

    function formatPeso(value) {

        return '₱' + Number(value).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });

    }


    // Calculate rental duration.

    function calculateDays() {

        const pickup = pickupInput.value;

        const due = returnInput.value;

        if (!pickup || !due) {
            return 1;
        }

        const pickupParts = pickup.split('-').map(Number);

        const dueParts = due.split('-').map(Number);

        const pickupDate = Date.UTC(
            pickupParts[0],
            pickupParts[1] - 1,
            pickupParts[2]
        );

        const dueDate = Date.UTC(
            dueParts[0],
            dueParts[1] - 1,
            dueParts[2]
        );

        const difference = Math.round(
            (dueDate - pickupDate) / (1000 * 60 * 60 * 24)
        );

        return Math.max(1, difference);

    }


    // Update reservation summary.

    function updateCalculations() {

        const selectedOption = equipSelect.options[equipSelect.selectedIndex];

        if (!selectedOption || !selectedOption.value) {

            displayName.textContent = 'Select Equipment';

            displayCat.textContent = '—';

            displayTag.textContent = '—';

            displayRate.innerHTML =
                '₱0.00 <span class="text-sm font-normal text-on-surface-variant">/day</span>';

            displayBadge.textContent = 'Not selected';

            summaryUnit.textContent = '—';

            summaryRate.textContent = '₱0.00 / day';

            summaryDays.textContent = calculateDays() + ' Day(s)';

            summaryTotal.textContent = '₱0.00';

            return;
        }


        const name = selectedOption.dataset.name || '';

        const category = selectedOption.dataset.cat || '';

        const tag = selectedOption.dataset.tag || '';

        const rate = parseFloat(selectedOption.dataset.rate) || 0;

        const status = selectedOption.dataset.status || '';


        // Update equipment card.

        displayName.textContent = name;

        displayCat.textContent = category;

        displayTag.textContent = tag;

        displayRate.innerHTML =
            formatPeso(rate) +
            ' <span class="text-sm font-normal text-on-surface-variant">/day</span>';


        displayBadge.textContent =
            status.charAt(0).toUpperCase() + status.slice(1);


        // Update summary.

        summaryUnit.textContent = name;

        summaryRate.textContent = formatPeso(rate) + ' / day';


        const days = calculateDays();

        summaryDays.textContent =
            days + (days === 1 ? ' Day' : ' Days');


        const total = rate * days;

        summaryTotal.textContent = formatPeso(total);

    }


    // Keep the due date no earlier than pickup date.

    function updateMinimumDueDate() {

        if (pickupInput.value) {

            returnInput.min = pickupInput.value;

            if (returnInput.value && returnInput.value < pickupInput.value) {
                returnInput.value = pickupInput.value;
            }

        }

    }


    equipSelect.addEventListener('change', updateCalculations);

    pickupInput.addEventListener('change', function () {

        updateMinimumDueDate();

        updateCalculations();

    });

    returnInput.addEventListener('change', updateCalculations);


    updateMinimumDueDate();

    updateCalculations();

});

</script>

</body>
</html>
