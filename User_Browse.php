
<?php
// ==========================================================
// AGRISHARE - BROWSE EQUIPMENT CATALOG (MEMBER)
// ==========================================================
require_once __DIR__ . '/auth.php';
require_role('member');

$user_id = $_SESSION['user_id'] ?? null;
$memberId = $_SESSION['member_id'] ?? '';

// 1. Fetch Logged-in Member Info
$currentMember = null;

if ($memberId !== '') {
    $stmtMember = $conn->prepare(
        "SELECT * FROM members WHERE member_id = ? LIMIT 1"
    );
    $stmtMember->bind_param("s", $memberId);
    $stmtMember->execute();
    $currentMember = $stmtMember->get_result()->fetch_assoc();
}

if (!$currentMember && $user_id) {
    $stmtMember = $conn->prepare(
        "SELECT * FROM members WHERE user_id = ? LIMIT 1"
    );
    $stmtMember->bind_param("i", $user_id);
    $stmtMember->execute();
    $currentMember = $stmtMember->get_result()->fetch_assoc();
}

if (!$currentMember) {
    header('Location: logout.php');
    exit();
}

$nameParts = preg_split('/\s+/', trim($currentMember['member_name'])) ?: [$currentMember['member_name']];

$shortName = count($nameParts) > 1
    ? mb_substr($nameParts[0], 0, 1) . '. ' . $nameParts[count($nameParts) - 1]
    : $nameParts[0];

$farmLine = ($currentMember['farm_location'] ?? $currentMember['address'] ?? '') !== ''
    ? ($currentMember['farm_location'] ?? $currentMember['address'])
    : 'Cooperative member';

// 2. Fetch Categories
$categoriesResult = $conn->query(
    "SELECT * FROM equipment_categories ORDER BY category_id ASC"
);
$categories = $categoriesResult
    ? $categoriesResult->fetch_all(MYSQLI_ASSOC)
    : [];

// 3. Filters and Search
$selectedCategory = $_GET['category'] ?? 'all';
$searchQuery = trim($_GET['search'] ?? '');

// 4. Equipment Query
$sql = "
    SELECT
        et.type_id,
        et.type_code,
        et.type_name,
        et.icon,
        et.description,
        ec.category_id,
        ec.category_name,
        ec.short_label,
        MIN(eu.daily_rate) AS min_rate,
        COUNT(eu.equipment_code) AS total_units,
        SUM(
            CASE
                WHEN eu.condition_status = 'good' THEN 1
                ELSE 0
            END
        ) AS available_units
    FROM equipment_types et
    JOIN equipment_categories ec
        ON et.category_id = ec.category_id
    JOIN equipment_units eu
        ON et.type_id = eu.type_id
    WHERE eu.condition_status != 'retired'
";

$types = '';
$params = [];

if ($selectedCategory !== 'all' && is_numeric($selectedCategory)) {
    $sql .= " AND ec.category_id = ?";
    $types .= 'i';
    $params[] = (int)$selectedCategory;
}

if ($searchQuery !== '') {
    $sql .= "
        AND (
            et.type_name LIKE ?
            OR et.type_code LIKE ?
            OR et.description LIKE ?
        )
    ";

    $like = '%' . $searchQuery . '%';
    $types .= 'sss';
    array_push($params, $like, $like, $like);
}

$sql .= "
    GROUP BY et.type_id
    ORDER BY ec.category_id ASC, et.type_name ASC
";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$equipmentItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 5. Total Equipment Types
$totalCountResult = $conn->query("
    SELECT COUNT(DISTINCT et.type_id) AS total_types
    FROM equipment_types et
    JOIN equipment_units eu
        ON et.type_id = eu.type_id
    WHERE eu.condition_status != 'retired'
");

$totalActiveItems = $totalCountResult
    ? ($totalCountResult->fetch_assoc()['total_types'] ?? 0)
    : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>AgriCoop Member - Browse Equipment</title>

<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">

<link href="https://fonts.googleapis.com" rel="preconnect">
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect">

<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Work+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>

<script>
tailwind.config = {
    darkMode: "class",
    theme: {
        extend: {
            colors: {
                "surface-container-highest": "#e1e3e1",
                "primary-fixed-dim": "#a0d3a6",
                "surface-variant": "#e1e3e1",
                "on-primary-fixed-variant": "#21502e",
                "on-background": "#191c1b",
                "secondary": "#904d00",
                "primary-container": "#1e4d2b",
                "on-surface-variant": "#414941",
                "surface-container-lowest": "#ffffff",
                "surface-tint": "#396844",
                "inverse-primary": "#a0d3a6",
                "tertiary": "#003709",
                "inverse-surface": "#2e3130",
                "on-secondary": "#ffffff",
                "background": "#f8faf8",
                "on-error": "#ffffff",
                "outline": "#717970",
                "primary-fixed": "#bbefc1",
                "on-error-container": "#93000a",
                "error-container": "#ffdad6",
                "secondary-fixed": "#ffdcc3",
                "on-primary-container": "#8bbd92",
                "on-secondary-container": "#663500",
                "surface-dim": "#d8dad9",
                "tertiary-fixed-dim": "#88d982",
                "tertiary-container": "#005011",
                "on-primary-fixed": "#00210b",
                "surface-bright": "#f8faf8",
                "secondary-fixed-dim": "#ffb77d",
                "on-tertiary-fixed": "#002204",
                "on-primary": "#ffffff",
                "error": "#ba1a1a",
                "on-surface": "#191c1b",
                "on-tertiary-container": "#73c46f",
                "surface-container-low": "#f2f4f2",
                "secondary-container": "#fe932c",
                "surface-container": "#eceeec",
                "on-tertiary": "#ffffff",
                "inverse-on-surface": "#eff1ef",
                "surface-container-high": "#e6e9e7",
                "surface": "#f8faf8",
                "on-secondary-fixed-variant": "#6e3900",
                "tertiary-fixed": "#a3f69c",
                "on-tertiary-fixed-variant": "#005312",
                "outline-variant": "#c1c9be",
                "on-secondary-fixed": "#2f1500",
                "primary": "#023616"
            },
            fontFamily: {
                "body-md": ["Work Sans"],
                "headline-lg": ["Work Sans"],
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

* {
    box-sizing: border-box;
}

body {
    font-family: 'Work Sans', sans-serif;
}

.sidebar-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: 8px;
    color: #414941;
    font-size: 14px;
    transition: all 0.2s ease;
}

.sidebar-link:hover {
    background: #f2f4f2;
}

.sidebar-link.active {
    background: #eceeec;
    color: #023616;
    font-weight: 600;
    border-left: 4px solid #023616;
}

.equipment-card {
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.equipment-card:hover {
    border-color: #023616;
    box-shadow: 0 4px 14px rgba(2, 54, 22, 0.06);
}
</style>
</head>

<body class="bg-background text-on-surface antialiased min-h-screen flex">

<!-- ======================================================
     LEFT SIDEBAR
====================================================== -->


<!-- LEFT SIDEBAR - KEEP CONSISTENT ACROSS MEMBER PAGES -->
<aside class="hidden md:flex w-64 h-screen flex-col justify-between p-4 flex-shrink-0 bg-surface-container-lowest border-r border-outline-variant fixed left-0 top-0 z-30">

    <div class="flex flex-col gap-6">

        <!-- Brand -->
        <div class="flex items-center gap-3 px-2 py-1">
            <div class="w-10 h-10 rounded-lg bg-primary-container flex items-center justify-center text-on-primary">
                <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">agriculture</span>
            </div>

            <div class="flex flex-col overflow-hidden">
                <span class="text-body-lg font-bold text-primary leading-tight">AgriCoop Member</span>
                <span class="font-label-sm text-xs text-on-surface-variant truncate">Valley Basin Co-op Yard</span>
            </div>
        </div>

        <!-- Reserve Button -->
        <a href="User_QuickReserve.php" class="w-full h-11 bg-primary text-on-primary font-semibold text-sm rounded-lg flex items-center justify-center gap-2 hover:bg-primary-container transition-colors shadow-sm">
            <span class="material-symbols-outlined">add_circle</span>
            Reserve Equipment
        </a>

        <!-- Navigation -->
        <nav aria-label="Main Navigation" class="flex flex-col gap-1.5">

            <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-on-surface-variant text-sm hover:bg-surface-container-low transition-colors" href="UserDashboard.php">
                <span class="material-symbols-outlined">dashboard</span>
                <span>Dashboard</span>
            </a>

            <!-- ACTIVE PAGE -->
            <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-surface-container text-primary text-sm font-semibold border-l-4 border-primary" href="User_Browse.php">
                <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">agriculture</span>
                <span>Browse Equipment</span>
            </a>

            <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-on-surface-variant text-sm hover:bg-surface-container-low transition-colors" href="User_Reservation.php">
                <span class="material-symbols-outlined">event_note</span>
                <span>My Reservations</span>
            </a>

            <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-on-surface-variant text-sm hover:bg-surface-container-low transition-colors" href="User_Profile.php">
                <span class="material-symbols-outlined">person</span>
                <span>My Profile</span>
            </a>

        </nav>
    </div>

    <!-- Member Information and Logout -->
    <div class="flex flex-col gap-2 pt-4 border-t border-outline-variant">

        <div class="flex items-center justify-between px-3 py-2 rounded-lg bg-surface-container-low">
            <div class="flex items-center gap-2 overflow-hidden">
                <span class="material-symbols-outlined text-primary">badge</span>

                <div class="flex flex-col min-w-0">
                    <span class="font-label-sm text-xs font-semibold text-on-surface truncate">
                        <?= e($shortName) ?> (#<?= e($currentMember['member_id']) ?>)
                    </span>

                    <span class="font-label-sm text-[11px] text-outline truncate">
                        <?= e($farmLine) ?>
                    </span>
                </div>
            </div>
        </div>

        <a href="logout.php" class="w-full flex items-center gap-3 px-3 py-2 rounded-lg text-error text-sm hover:bg-error-container/40 transition-colors">
            <span class="material-symbols-outlined">logout</span>
            <span>Logout</span>
        </a>

    </div>
</aside>



<!-- ======================================================
     MAIN CONTENT AREA
====================================================== -->

<div class="flex-1 md:ml-64 flex flex-col min-h-screen">

    <!-- HEADER -->
    <header class="flex justify-between items-center min-h-16 px-6 py-3 w-full bg-surface-container-lowest border-b border-outline-variant sticky top-0 z-20">

        <div class="flex items-center gap-4">
            <button aria-label="Open menu"
                    class="md:hidden p-2 text-on-surface-variant hover:bg-surface-container-low rounded-lg">
                <span class="material-symbols-outlined">menu</span>
            </button>

        </div>

        <div class="flex items-center gap-4">

            <form method="GET" action="User_Browse.php" class="relative w-48 sm:w-64 md:w-80">

                <?php if ($selectedCategory !== 'all'): ?>
                    <input type="hidden" name="category" value="<?= e($selectedCategory) ?>">
                <?php endif; ?>

                <input
                    name="search"
                    value="<?= e($searchQuery) ?>"
                    class="w-full pl-9 pr-3 py-2 text-sm bg-surface rounded-lg border border-outline-variant focus:outline-none focus:border-primary text-on-surface"
                    placeholder="Search equipment..."
                    type="search">

                <span class="material-symbols-outlined absolute left-2.5 top-2 text-on-surface-variant text-base">
                    search
                </span>
            </form>

        </div>
    </header>


    <!-- ==================================================
         PAGE CONTENT
    ================================================== -->

    <main class="flex-1 p-6 md:p-8 space-y-8 max-w-7xl w-full mx-auto">

        <!-- PAGE TITLE -->
        <section class="flex flex-col md:flex-row md:items-end justify-between gap-4">

            <div class="space-y-1">

                <div class="flex items-center gap-2 text-primary text-xs font-semibold tracking-wider uppercase">
                    <span class="material-symbols-outlined text-base">
                        inventory_2
                    </span>
                    <span>Cooperative Equipment Catalog</span>
                </div>

                <h1 class="text-2xl font-bold text-primary tracking-tight">
                    Browse Equipment
                </h1>

                <p class="text-sm text-on-surface-variant">
                    Explore the machinery and equipment available at Valley Basin Co-op Yard.
                </p>

            </div>

            <!-- Catalog Count -->
            <div class="flex items-center gap-2 px-4 py-2 rounded-lg bg-primary-fixed border border-primary/20">

                <span class="material-symbols-outlined text-primary">
                    inventory
                </span>

                <div>
                    <span class="block text-lg font-bold text-primary leading-tight">
                        <?= (int)$totalActiveItems ?>
                    </span>
                    <span class="text-xs text-on-primary-fixed-variant">
                        Equipment Types
                    </span>
                </div>

            </div>

        </section>


        <!-- FILTER SECTION -->
        <section class="bg-surface-container-lowest p-5 rounded-xl border border-outline-variant shadow-sm">

            <div class="flex flex-col gap-4">

                <div>
                    <h2 class="text-base font-semibold text-primary">
                        Find Equipment
                    </h2>
                    <p class="text-xs text-on-surface-variant mt-1">
                        Search by equipment name or select a category.
                    </p>
                </div>

                <form method="GET" action="User_Browse.php"
                      class="flex flex-col gap-4">

                    <!-- Search -->
                    <div class="relative w-full">

                        <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-outline text-xl pointer-events-none">
                            search
                        </span>

                        <input
                            name="search"
                            value="<?= e($searchQuery) ?>"
                            class="w-full pl-10 pr-4 py-3 rounded-lg border border-outline-variant bg-background text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all"
                            placeholder="Search machinery by keyword or ID..."
                            type="text">

                    </div>

                    <!-- Category Filters -->
                    <div class="flex flex-col gap-2">

                        <span class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider">
                            Equipment Categories
                        </span>

                        <div class="flex items-center gap-2 overflow-x-auto pb-1">

                            <a href="User_Browse.php?category=all<?= $searchQuery !== '' ? '&search=' . urlencode($searchQuery) : '' ?>"
                               class="px-4 py-2 rounded-full text-xs font-semibold whitespace-nowrap transition-colors
                               <?= $selectedCategory === 'all'
                                   ? 'bg-primary text-on-primary'
                                   : 'bg-surface-container hover:bg-surface-container-high text-on-surface border border-outline-variant' ?>">
                                All Equipment
                            </a>

                            <?php foreach ($categories as $cat): ?>

                                <a href="User_Browse.php?category=<?= (int)$cat['category_id'] ?><?= $searchQuery !== '' ? '&search=' . urlencode($searchQuery) : '' ?>"
                                   class="px-4 py-2 rounded-full text-xs font-medium whitespace-nowrap transition-colors
                                   <?= (string)$selectedCategory === (string)$cat['category_id']
                                       ? 'bg-primary text-on-primary'
                                       : 'bg-surface-container hover:bg-surface-container-high text-on-surface border border-outline-variant' ?>">

                                    <?= e($cat['category_name']) ?>

                                </a>

                            <?php endforeach; ?>

                        </div>
                    </div>

                </form>

            </div>
        </section>


        <!-- EQUIPMENT SECTION HEADER -->
        <section class="space-y-5">

            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">

                <div>
                    <h2 class="text-lg font-bold text-primary">
                        Available Equipment
                    </h2>

                    <p class="text-sm text-on-surface-variant mt-1">
                        Browse the equipment types registered in the cooperative.
                    </p>
                </div>

                <span class="text-xs text-on-surface-variant">
                    <?= count($equipmentItems) ?> result(s) found
                </span>

            </div>


            <!-- EQUIPMENT GRID -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">

                <?php if (empty($equipmentItems)): ?>

                    <div class="col-span-full bg-surface-container-lowest border border-outline-variant rounded-xl py-16 px-6 text-center">

                        <div class="w-16 h-16 mx-auto rounded-full bg-surface-container flex items-center justify-center text-outline mb-4">
                            <span class="material-symbols-outlined text-4xl">
                                inventory_2
                            </span>
                        </div>

                        <h3 class="text-base font-semibold text-on-surface">
                            No Equipment Found
                        </h3>

                        <p class="text-sm text-on-surface-variant mt-1">
                            No equipment matches your current search or category filter.
                        </p>

                        <a href="User_Browse.php"
                           class="inline-flex items-center gap-2 mt-5 px-4 py-2 bg-primary text-on-primary text-sm font-semibold rounded-lg hover:bg-primary-container transition-colors">
                            <span class="material-symbols-outlined text-base">refresh</span>
                            Clear Filters
                        </a>

                    </div>

                <?php else: ?>

                    <?php foreach ($equipmentItems as $item):

                        $icon = !empty($item['icon'])
                            ? $item['icon']
                            : 'precision_manufacturing';

                        $availableCount = (int)$item['available_units'];
                        $isAvailable = $availableCount > 0;

                        if (!preg_match('/^[a-z0-9_]+$/', $icon)) {
                            $icon = 'precision_manufacturing';
                        }

                    ?>

                        <article class="equipment-card bg-surface-container-lowest rounded-xl border border-outline-variant flex flex-col justify-between overflow-hidden shadow-sm">

                            <div>

                                <!-- Card Top -->
                                <div class="p-4 border-b border-outline-variant bg-surface-container-low flex items-center justify-between gap-2">

                                    <span class="text-xs font-bold text-secondary uppercase tracking-wide">
                                        <?= e($item['short_label']) ?> #<?= e($item['type_code']) ?>
                                    </span>

                                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold <?= $isAvailable ? 'text-primary' : 'text-error' ?>">

                                        <span class="w-2 h-2 rounded-full <?= $isAvailable ? 'bg-primary' : 'bg-error' ?>"></span>

                                        <?= $isAvailable
                                            ? $availableCount . ' Units Available'
                                            : 'Out of Stock' ?>

                                    </span>

                                </div>


                                <!-- Card Details -->
                                <div class="p-5">

                                    <div class="flex items-start gap-3 mb-4">

                                        <div class="w-12 h-12 rounded-xl bg-primary-fixed flex items-center justify-center text-primary flex-shrink-0">

                                            <span class="material-symbols-outlined text-2xl">
                                                <?= e($icon) ?>
                                            </span>

                                        </div>

                                        <div class="min-w-0">

                                            <span class="text-[11px] font-semibold text-on-surface-variant uppercase tracking-wider block mb-1">
                                                <?= e($item['category_name']) ?>
                                            </span>

                                            <h3 class="text-base font-bold text-on-surface leading-snug">
                                                <?= e($item['type_name']) ?>
                                            </h3>

                                        </div>

                                    </div>

                                    <p class="text-sm text-on-surface-variant leading-relaxed min-h-[48px]">
                                        <?= e($item['description'] ?? 'Cooperative equipment staged for dispatch.') ?>
                                    </p>

                                </div>

                            </div>


                            <!-- Card Footer -->
                            <div class="p-5 bg-surface-container-lowest border-t border-outline-variant flex items-center justify-between gap-3">

                                <div>

                                    <span class="text-[10px] text-on-surface-variant uppercase tracking-wider block mb-1">
                                        Starting Daily Rate
                                    </span>

                                    <span class="text-lg font-bold text-primary">
                                        ₱<?= number_format((float)$item['min_rate'], 2) ?>
                                    </span>

                                    <span class="text-xs font-normal text-on-surface-variant">
                                        / day
                                    </span>

                                </div>

                                <?php if ($isAvailable): ?>

                                    <a href="User_QuickReserve.php"
                                       class="px-4 py-2.5 bg-primary hover:bg-primary-container text-on-primary text-xs font-semibold rounded-lg flex items-center gap-1.5 transition-colors shadow-sm whitespace-nowrap">

                                        <span class="material-symbols-outlined text-base">
                                            add_circle
                                        </span>

                                        Reserve
                                    </a>

                                <?php else: ?>

                                    <button disabled
                                            class="px-4 py-2.5 bg-surface-container text-outline text-xs font-semibold rounded-lg cursor-not-allowed whitespace-nowrap">
                                        Unavailable
                                    </button>

                                <?php endif; ?>

                            </div>

                        </article>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>

        </section>


        <!-- INFORMATION PANEL -->
        <section class="p-5 rounded-xl bg-surface-container-low border border-outline-variant flex flex-col sm:flex-row sm:items-center justify-between gap-4">

            <div class="flex items-center gap-3">

                <div class="w-10 h-10 rounded-lg bg-surface-container flex items-center justify-center text-primary flex-shrink-0">
                    <span class="material-symbols-outlined">
                        info
                    </span>
                </div>

                <div>
                    <span class="text-sm font-semibold text-primary block">
                        Need to reserve equipment?
                    </span>

                    <span class="text-xs text-on-surface-variant">
                        Select Reserve to continue to the reservation page.
                    </span>
                </div>

            </div>

            <a href="User_QuickReserve.php"
               class="inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-primary text-on-primary text-sm font-semibold rounded-lg hover:bg-primary-container transition-colors whitespace-nowrap">

                <span class="material-symbols-outlined text-base">add_circle</span>
                Reserve Equipment

            </a>

        </section>

    </main>

</div>

</body>
</html>
