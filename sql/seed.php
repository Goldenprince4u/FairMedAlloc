<?php

declare(strict_types=1);

require_once __DIR__ . '/../db_config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

function ensureEmptySeedTarget(mysqli $conn): void
{
    foreach (['faculties', 'departments', 'hostels', 'rooms', 'settings', 'faqs'] as $table) {
        $count = (int) $conn->query("SELECT COUNT(*) AS total FROM {$table}")->fetch_assoc()['total'];
        if ($count > 0) {
            throw new RuntimeException(
                "Seeder stopped: table '{$table}' already contains data. Import sql/schema.sql into a fresh database first."
            );
        }
    }
}

function insertFaculties(mysqli $conn): array
{
    $faculties = [
        'Faculty of Basic Medical Sciences',
        'Faculty of Engineering',
        'Faculty of Built Environment Studies',
        'Faculty of Humanities',
        'Faculty of Law',
        'Faculty of Management Sciences',
        'Faculty of Natural Sciences',
        'Faculty of Social Sciences',
        'Faculty of Computing and Digital Technology',
    ];

    $stmt = $conn->prepare('INSERT INTO faculties (name) VALUES (?)');
    $ids = [];

    foreach ($faculties as $name) {
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $ids[$name] = $conn->insert_id;
    }

    return $ids;
}

function insertDepartments(mysqli $conn, array $facultyIds): void
{
    $departmentsByFaculty = [
        'Faculty of Basic Medical Sciences' => [
            'Biochemistry',
            'Human Anatomy',
            'Human Physiology',
            'Public Health',
            'Nursing Science',
            'Physiotherapy',
            'Medical Laboratory Science',
        ],
        'Faculty of Engineering' => [
            'Civil Engineering',
            'Computer Engineering',
            'Electrical & Electronic Engineering',
            'Mechanical Engineering',
        ],
        'Faculty of Built Environment Studies' => [
            'Architecture',
            'Building Technology',
            'Estate Management',
            'Quantity Surveying',
            'Urban & Regional Planning',
        ],
        'Faculty of Humanities' => [
            'Christian Religious Studies',
            'English',
            'French',
            'History & International Studies',
            'Philosophy',
            'Theatre Arts',
        ],
        'Faculty of Law' => [
            'Law',
        ],
        'Faculty of Management Sciences' => [
            'Accounting',
            'Banking & Finance',
            'Business Administration',
            'Public Administration',
            'Hospitality & Tourism Management',
            'Insurance',
            'Marketing',
            'Transport Management',
            'Actuarial Science',
        ],
        'Faculty of Natural Sciences' => [
            'Environmental Management & Toxicology',
            'Geology',
            'Industrial Chemistry',
            'Industrial Mathematics',
            'Industrial Mathematics and Computer Science',
            'Microbiology',
            'Petroleum Chemistry',
            'Physics with Electronics',
            'Statistics',
            'Statistics & Data Science',
        ],
        'Faculty of Social Sciences' => [
            'Economics',
            'Mass Communication',
            'Political Science',
            'Psychology',
            'Sociology',
            'Social Work',
        ],
        'Faculty of Computing and Digital Technology' => [
            'Computer Science',
            'Cyber Security',
            'Information Technology',
        ],
    ];

    $stmt = $conn->prepare('INSERT INTO departments (faculty_id, name) VALUES (?, ?)');

    foreach ($departmentsByFaculty as $facultyName => $departments) {
        $facultyId = $facultyIds[$facultyName];
        foreach ($departments as $departmentName) {
            $stmt->bind_param('is', $facultyId, $departmentName);
            $stmt->execute();
        }
    }
}

function insertStaticData(mysqli $conn): void
{
    $settings = [
        ['current_session', '2025/2026'],
        ['urgency_threshold_proximal', '75'],
        ['urgency_threshold_medium', '40'],
        ['allocation_algorithm_version', 'allocation_engine_v3'],
        ['allocation_solver_backend', 'ortools'],
        ['allocation_status', 'open'],
    ];

    $faqs = [
        [
            'How is the urgency score calculated?',
            'The system uses XGBoost trained on medical data. It considers condition, mobility, and severity to assign a priority score (0-100).',
        ],
        [
            'What if my allocation is pending?',
            'Allocations run in batches. Ensure your profile is complete.',
        ],
        [
            'How do I correct a wrong medical entry?',
            'Edit your profile via Student Dashboard. False claims are verified at the University Health Center.',
        ],
    ];

    $settingsStmt = $conn->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ($settings as [$key, $value]) {
        $settingsStmt->bind_param('ss', $key, $value);
        $settingsStmt->execute();
    }

    $faqStmt = $conn->prepare('INSERT INTO faqs (question, answer) VALUES (?, ?)');
    foreach ($faqs as [$question, $answer]) {
        $faqStmt->bind_param('ss', $question, $answer);
        $faqStmt->execute();
    }
}

function makeRoom(
    int $number,
    int $capacity,
    bool $isCorner,
    ?string $bedConfig,
    int $floorLevel = 0,
    bool $isReserved = false
): array {
    return [
        'room_number' => (string) $number,
        'floor_level' => $floorLevel,
        'capacity' => $capacity,
        'is_corner' => $isCorner,
        'is_reserved' => $isReserved,
        'bed_config' => $bedConfig,
    ];
}

function buildProphetMosesStandardRooms(): array
{
    $rooms = [];
    $cornerRooms = [1, 12, 13, 24];

    for ($room = 1; $room <= 24; $room++) {
        $isCorner = in_array($room, $cornerRooms, true);
        $rooms[] = makeRoom(
            $room,
            $isCorner ? 4 : 3,
            $isCorner,
            $isCorner ? 'LB,UB,LB,UB' : 'SB,UB,LB'
        );
    }

    return $rooms;
}

function buildPostgrad24Rooms(): array
{
    $rooms = [];

    for ($room = 1; $room <= 24; $room++) {
        $rooms[] = makeRoom($room, 2, false, 'LB,UB');
    }

    return $rooms;
}

function buildExtensionBlock21Rooms(): array
{
    $rooms = [];

    for ($room = 1; $room <= 24; $room++) {
        if ($room === 12 || $room === 13) {
            $rooms[] = makeRoom($room, 6, true, 'LB,UB,LB,UB,LB,UB');
            continue;
        }

        if ($room === 1 || $room === 24) {
            $rooms[] = makeRoom($room, 4, true, 'LB,UB,LB,UB');
            continue;
        }

        $rooms[] = makeRoom($room, 3, false, 'LB,UB,SB');
    }

    return $rooms;
}

function buildExtensionStandardRooms(): array
{
    $rooms = [];
    $cornerRooms = [1, 12, 13, 24];

    for ($room = 1; $room <= 24; $room++) {
        $isCorner = in_array($room, $cornerRooms, true);
        $rooms[] = makeRoom(
            $room,
            $isCorner ? 4 : 3,
            $isCorner,
            $isCorner ? 'LB,UB,LB,UB' : 'LB,UB,SB'
        );
    }

    return $rooms;
}

function buildBlock26Rooms(): array
{
    $rooms = [];

    for ($room = 1; $room <= 27; $room++) {
        if ($room === 26) {
            $rooms[] = makeRoom($room, 8, true, 'LB,UB,LB,UB,LB,UB,LB,UB');
            continue;
        }

        $rooms[] = makeRoom($room, 4, false, 'LB,UB,LB,UB');
    }

    return $rooms;
}

function buildJoshuaRooms(bool $reservedCornersDownstairs = false): array
{
    $rooms = [];

    for ($room = 1; $room <= 54; $room++) {
        $floorLevel = $room <= 27 ? 0 : 1;
        $isCorner = in_array($room, [1, 27, 28, 54], true);
        $capacity = 4;
        $isReserved = false;
        $bedConfig = 'LB,UB,LB,UB';

        if ($room === 1 || $room === 54) {
            $capacity = 8;
            $bedConfig = 'LB,UB,LB,UB,LB,UB,LB,UB';
        }

        if ($reservedCornersDownstairs && ($room === 1 || $room === 27)) {
            $capacity = 0;
            $isReserved = true;
            $bedConfig = null;
        }

        $rooms[] = makeRoom($room, $capacity, $isCorner, $bedConfig, $floorLevel, $isReserved);
    }

    return $rooms;
}

function buildQueenEstherRooms(): array
{
    $rooms = [];

    for ($room = 1; $room <= 24; $room++) {
        if ($room === 12 || $room === 13) {
            $rooms[] = makeRoom($room, 6, true, 'LB,UB,LB,UB,LB,UB');
            continue;
        }

        if ($room === 1 || $room === 24) {
            $rooms[] = makeRoom($room, 4, true, 'LB,UB,LB,UB');
            continue;
        }

        $rooms[] = makeRoom($room, 3, false, 'SB,UB,LB');
    }

    return $rooms;
}

/**
 * Queen Esther Hall blocks 33-37 — 28 rooms per block.
 * Room 1: 8-bed corner (LB,UB,LB,UB,LB,UB,LB,UB).
 * Rooms 2-28: 4-bed standard (LB,UB,LB,UB).
 * All rooms are ground floor (single-storey blocks).
 * Capacity per block: 8 + (27 × 4) = 116.
 */
function buildQueenEstherLargeBlockRooms(): array
{
    $rooms = [];

    for ($room = 1; $room <= 28; $room++) {
        if ($room === 1) {
            $rooms[] = makeRoom($room, 8, true, 'LB,UB,LB,UB,LB,UB,LB,UB');
            continue;
        }
        $rooms[] = makeRoom($room, 4, false, 'LB,UB,LB,UB');
    }

    return $rooms;
}

function buildDeborahRooms(): array
{
    $rooms = [];

    for ($room = 1; $room <= 28; $room++) {
        $floorLevel = $room <= 14 ? 0 : 1;
        if ($room === 1) {
            $rooms[] = makeRoom($room, 8, true, 'LB,UB,LB,UB,LB,UB,LB,UB', $floorLevel);
            continue;
        }

        $rooms[] = makeRoom($room, 4, false, 'LB,UB,LB,UB', $floorLevel);
    }

    return $rooms;
}

function buildHostelSeedPlan(array $facultyIds): array
{
    $engineeringId = $facultyIds['Faculty of Engineering'];
    $hostels = [];

    for ($block = 1; $block <= 18; $block++) {
        $hostels[] = [
            'name' => 'Prophet Moses Hall',
            'block_name' => (string) $block,
            'gender_allowed' => 'Male',
            'proximal_faculty_id' => null,
            'is_proximal' => false,
            'is_postgrad' => false,
            'is_foundation' => false,
            'total_capacity' => 76,
            'rooms' => buildProphetMosesStandardRooms(),
        ];
    }

    $hostels[] = [
        'name' => 'Prophet Moses Extension Hall',
        'block_name' => '19',
        'gender_allowed' => 'Male',
        'proximal_faculty_id' => null,
        'is_proximal' => false,
        'is_postgrad' => true,
        'is_foundation' => false,
        'total_capacity' => 48,
        'rooms' => buildPostgrad24Rooms(),
    ];

    $hostels[] = [
        'name' => 'Prophet Moses Extension Hall',
        'block_name' => '20',
        'gender_allowed' => 'Male',
        'proximal_faculty_id' => null,
        'is_proximal' => false,
        'is_postgrad' => true,
        'is_foundation' => false,
        'total_capacity' => 48,
        'rooms' => buildPostgrad24Rooms(),
    ];

    $hostels[] = [
        'name' => 'Prophet Moses Extension Hall',
        'block_name' => '21',
        'gender_allowed' => 'Male',
        'proximal_faculty_id' => null,
        'is_proximal' => false,
        'is_postgrad' => false,
        'is_foundation' => false,
        'total_capacity' => 80,
        'rooms' => buildExtensionBlock21Rooms(),
    ];

    foreach (['22', '23', '24', '25'] as $block) {
        $hostels[] = [
            'name' => 'Prophet Moses Extension Hall',
            'block_name' => $block,
            'gender_allowed' => 'Male',
            'proximal_faculty_id' => null,
            'is_proximal' => false,
            'is_postgrad' => false,
            'is_foundation' => false,
            'total_capacity' => 76,
            'rooms' => buildExtensionStandardRooms(),
        ];
    }

    $hostels[] = [
        'name' => 'Prophet Moses Extension Hall',
        'block_name' => '26',
        'gender_allowed' => 'Male',
        'proximal_faculty_id' => null,
        'is_proximal' => false,
        'is_postgrad' => false,
        'is_foundation' => false,
        'total_capacity' => 112,
        'rooms' => buildBlock26Rooms(),
    ];

    $hostels[] = [
        'name' => 'Prophet Moses Extension Hall',
        'block_name' => '27',
        'gender_allowed' => 'Male',
        'proximal_faculty_id' => null,
        'is_proximal' => false,
        'is_postgrad' => false,
        'is_foundation' => true,
        'total_capacity' => 112,
        'rooms' => buildBlock26Rooms(),
    ];

    $hostels[] = [
        'name' => 'Joshua Hall',
        'block_name' => '28',
        'gender_allowed' => 'Male',
        'proximal_faculty_id' => $engineeringId,
        'is_proximal' => true,
        'is_postgrad' => false,
        'is_foundation' => false,
        'total_capacity' => 212,
        'rooms' => buildJoshuaRooms(true),
    ];

    foreach (['29', '30', '31', '32', '33'] as $block) {
        $hostels[] = [
            'name' => 'Joshua Hall',
            'block_name' => $block,
            'gender_allowed' => 'Male',
            'proximal_faculty_id' => $engineeringId,
            'is_proximal' => true,
            'is_postgrad' => false,
            'is_foundation' => false,
            'total_capacity' => 224,
            'rooms' => buildJoshuaRooms(false),
        ];
    }

    // Blocks 1-32: 24 rooms, corners at 1,12,13,24 (rooms 1&24=4 beds, 12&13=6 beds), others=3 beds.
    for ($block = 1; $block <= 32; $block++) {
        $hostels[] = [
            'name' => 'Queen Esther Hall',
            'block_name' => (string) $block,
            'gender_allowed' => 'Female',
            'proximal_faculty_id' => null,
            'is_proximal' => false,
            'is_postgrad' => false,
            'is_foundation' => false,
            'total_capacity' => 80,
            'rooms' => buildQueenEstherRooms(),
        ];
    }

    // Blocks 33-37: 28 rooms, Room 1=8 beds corner, all others=4 beds. Capacity=116 per block.
    for ($block = 33; $block <= 37; $block++) {
        $hostels[] = [
            'name' => 'Queen Esther Hall',
            'block_name' => (string) $block,
            'gender_allowed' => 'Female',
            'proximal_faculty_id' => null,
            'is_proximal' => false,
            'is_postgrad' => false,
            'is_foundation' => false,
            'total_capacity' => 116,
            'rooms' => buildQueenEstherLargeBlockRooms(),
        ];
    }

    // Queen Esther Extension Hall: blocks 38–42 (continuing QE Hall's sequence).
    // Blocks 38 and 39 are clinic-proximal.
    for ($block = 38; $block <= 42; $block++) {
        $hostels[] = [
            'name' => 'Queen Esther Extension Hall',
            'block_name' => (string) $block,
            'gender_allowed' => 'Female',
            'proximal_faculty_id' => null,
            'is_proximal' => false,
            'is_postgrad' => false,
            'is_foundation' => false,
            'total_capacity' => 80,
            'rooms' => buildQueenEstherRooms(),
        ];
    }

    for ($block = 1; $block <= 5; $block++) {
        $hostels[] = [
            'name' => 'Deborah Hall',
            'block_name' => (string) $block,
            'gender_allowed' => 'Female',
            'proximal_faculty_id' => $engineeringId,
            'is_proximal' => true,
            'is_postgrad' => false,
            'is_foundation' => false,
            'total_capacity' => 116,
            'rooms' => buildDeborahRooms(),
        ];
    }

    return $hostels;
}

function insertHostelsAndRooms(mysqli $conn, array $hostels): array
{
    $hostelStmt = $conn->prepare(
        'INSERT INTO hostels (name, block_name, gender_allowed, proximal_faculty_id, is_proximal, is_postgrad, is_foundation, total_capacity)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $roomStmt = $conn->prepare(
        'INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, is_reserved, bed_config)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $hostelCount = 0;
    $roomCount = 0;

    foreach ($hostels as $hostel) {
        $hostelStmt->bind_param(
            'sssiiiii',
            $hostel['name'],
            $hostel['block_name'],
            $hostel['gender_allowed'],
            $hostel['proximal_faculty_id'],
            $hostel['is_proximal'],
            $hostel['is_postgrad'],
            $hostel['is_foundation'],
            $hostel['total_capacity']
        );
        $hostelStmt->execute();
        $hostelId = $conn->insert_id;
        $hostelCount++;

        foreach ($hostel['rooms'] as $room) {
            $roomStmt->bind_param(
                'isiiiis',
                $hostelId,
                $room['room_number'],
                $room['floor_level'],
                $room['capacity'],
                $room['is_corner'],
                $room['is_reserved'],
                $room['bed_config']
            );
            $roomStmt->execute();
            $roomCount++;
        }
    }

    return ['hostels' => $hostelCount, 'rooms' => $roomCount];
}

try {
    ensureEmptySeedTarget($conn);
    $conn->begin_transaction();

    $facultyIds = insertFaculties($conn);
    insertDepartments($conn, $facultyIds);
    insertStaticData($conn);
    $counts = insertHostelsAndRooms($conn, buildHostelSeedPlan($facultyIds));

    $conn->commit();

    echo "Seed complete.\n";
    echo 'Faculties: ' . count($facultyIds) . "\n";
    echo 'Hostels: ' . $counts['hostels'] . "\n";
    echo 'Rooms: ' . $counts['rooms'] . "\n";
    echo "Admin accounts are still created separately via create_admin.php.\n";
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    fwrite(STDERR, "[seed.php] " . $e->getMessage() . PHP_EOL);
    exit(1);
}
