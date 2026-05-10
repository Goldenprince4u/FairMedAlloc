<?php
require_once __DIR__ . '/UrgencyScoreService.php';
require_once __DIR__ . '/Logger.php';

class CsvImportService
{
    private mysqli $conn;
    private ?int $jobId;

    public function __construct(mysqli $conn, ?int $jobId = null)
    {
        $this->conn = $conn;
        $this->jobId = $jobId;
    }

    public function processCsvFile(string $filePath, ?callable $progressCallback = null): array
    {
        if (!is_readable($filePath)) {
            throw new RuntimeException('Import file is missing or unreadable.');
        }

        $importStart = microtime(true);
        $parsedRows = $this->parseCsvRows($filePath);
        $totalRows = count($parsedRows);

        if ($totalRows === 0) {
            throw new RuntimeException('No valid data rows were found in the uploaded CSV.');
        }

        $this->persistJobTotals($totalRows, 0, 'Validated CSV rows', 15);
        $this->emitProgress($progressCallback, 'Validated CSV rows', 15, $totalRows, 0);

        $existingUsernames = $this->loadExistingUsernames();
        $facultyCache = $this->loadFacultyCache();
        $departmentCache = $this->loadDepartmentCache();

        $this->persistJobTotals($totalRows, 0, 'Loaded reference data', 25);
        $this->emitProgress($progressCallback, 'Loaded reference data', 25, $totalRows, 0);

        $userInsertSql = FAIRMED_SUPPORTS_MUST_CHANGE_PASSWORD
            ? "INSERT INTO users (username, full_name, password_hash, must_change_password, role) VALUES (?, ?, ?, 1, 'student')"
            : "INSERT INTO users (username, full_name, password_hash, role) VALUES (?, ?, ?, 'student')";

        $stmtUser = $this->conn->prepare($userInsertSql);
        $stmtProfile = $this->conn->prepare("INSERT INTO student_profiles (user_id, level, department_id, gender, has_special_needs, is_paid) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtFaculty = $this->conn->prepare("INSERT INTO faculties (name) VALUES (?)");
        $stmtDepartment = $this->conn->prepare("INSERT INTO departments (faculty_id, name) VALUES (?, ?)");

        if (!$stmtUser || !$stmtProfile || !$stmtFaculty || !$stmtDepartment) {
            throw new RuntimeException('Unable to prepare import statements.');
        }

        $count = 0;
        $duplicates = 0;
        $pendingMedical = [];

        $this->conn->begin_transaction();
        try {
            foreach ($parsedRows as $row) {
                $matric = $row['matric'];
                $name = $row['name'];
                $level = (int)$row['level'];
                $faculty = $row['faculty'];
                $dept = $row['department'];
                $gender = $row['gender'];
                $condition = $row['condition'];
                $severity = $row['severity'];
                $mobility = $row['mobility'];
                $isPaid = (int)$row['is_paid'];

                $matricKey = strtolower($matric);
                if (isset($existingUsernames[$matricKey])) {
                    $duplicates++;
                    continue;
                }

                $hasMobilityNeed = $mobility !== 'Normal Mobility' ? 1 : 0;
                $hash = password_hash($matricKey, PASSWORD_BCRYPT, ['cost' => 4]);

                $stmtUser->bind_param('sss', $matric, $name, $hash);
                if (!$stmtUser->execute()) {
                    throw new RuntimeException('Unable to create student user record.');
                }

                $uid = (int)$this->conn->insert_id;
                $existingUsernames[$matricKey] = true;

                $facultyKey = strtolower(trim($faculty));
                if (isset($facultyCache[$facultyKey])) {
                    $facultyId = $facultyCache[$facultyKey];
                } else {
                    $stmtFaculty->bind_param('s', $faculty);
                    if (!$stmtFaculty->execute()) {
                        throw new RuntimeException('Unable to create faculty record.');
                    }
                    $facultyId = (int)$this->conn->insert_id;
                    $facultyCache[$facultyKey] = $facultyId;
                }

                $departmentKey = $facultyId . ':' . strtolower(trim($dept));
                if (isset($departmentCache[$departmentKey])) {
                    $departmentId = $departmentCache[$departmentKey];
                } else {
                    $stmtDepartment->bind_param('is', $facultyId, $dept);
                    if (!$stmtDepartment->execute()) {
                        throw new RuntimeException('Unable to create department record.');
                    }
                    $departmentId = (int)$this->conn->insert_id;
                    $departmentCache[$departmentKey] = $departmentId;
                }

                $stmtProfile->bind_param('iiisii', $uid, $level, $departmentId, $gender, $hasMobilityNeed, $isPaid);
                if (!$stmtProfile->execute()) {
                    throw new RuntimeException('Unable to create student profile.');
                }

                if ($condition !== 'None' || $hasMobilityNeed === 1) {
                    $pendingMedical[] = [
                        'id' => $uid,
                        'condition' => $condition,
                        'severity' => $severity,
                        'mobility' => $mobility,
                        'academic_level' => $level,
                        'has_special_needs' => $hasMobilityNeed,
                        'is_requested' => $hasMobilityNeed,
                    ];
                }

                $count++;
            }

            $this->emitProgress($progressCallback, 'Calculating import scores', 70, $totalRows, $count);

            // Keep imports fast and predictable by using deterministic PHP scoring here.
            // The allocation engine recalculates urgency scores again during allocation runs.
            $batchScores = [];
            foreach ($pendingMedical as $student) {
                $studentId = (int)($student['id'] ?? 0);
                if ($studentId <= 0) {
                    continue;
                }
                $batchScores[$studentId] = UrgencyScoreService::calculateFallbackScore([
                    'condition' => $student['condition'] ?? 'None',
                    'mobility' => $student['mobility'] ?? 'Normal Mobility',
                    'severity' => $student['severity'] ?? 'Low',
                    'academic_level' => (int)($student['academic_level'] ?? 100),
                    'has_special_needs' => (int)($student['has_special_needs'] ?? 0),
                    'is_requested' => (int)($student['is_requested'] ?? 0),
                ]);
            }

            $stmtMed = $this->conn->prepare("INSERT INTO medical_records (student_id, condition_category, condition_details, severity_level, urgency_score, mobility_status, is_requested_mobility) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if (!$stmtMed) {
                throw new RuntimeException('Unable to prepare medical record insert.');
            }

            foreach ($pendingMedical as $student) {
                $uid = (int)$student['id'];
                $condition = $student['condition'];
                $severity = $student['severity'];
                $mobility = $student['mobility'];
                $isRequested = (int)($student['is_requested'] ?? 0);
                $score = isset($batchScores[$uid])
                    ? (float)$batchScores[$uid]
                    : UrgencyScoreService::calculateFallbackScore([
                        'condition' => $condition,
                        'mobility' => $mobility,
                        'severity' => $severity,
                    ]);

                $details = "{$condition} (Imported via CSV)";
                $stmtMed->bind_param('isssdsi', $uid, $condition, $details, $severity, $score, $mobility, $isRequested);
                if (!$stmtMed->execute()) {
                    throw new RuntimeException('Unable to create medical record.');
                }
            }

            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }

        $durationMs = round((microtime(true) - $importStart) * 1000, 2);
        Logger::info("CSV import completed: {$count} students imported, {$duplicates} duplicates skipped in {$durationMs}ms");

        $this->persistJobTotals($totalRows, $count, 'Completed', 100);
        $this->emitProgress($progressCallback, 'Completed', 100, $totalRows, $count);

        return [
            'status' => 'success',
            'imported' => $count,
            'duplicates' => $duplicates,
            'total' => $totalRows,
            'duration_ms' => $durationMs,
            'message' => "Processed: {$count} students registered. Duplicates skipped: {$duplicates}. Payment, mobility, and department data were preserved for allocation.",
        ];
    }

    private function parseCsvRows(string $filePath): array
    {
        $file = fopen($filePath, 'r');
        if (!is_resource($file)) {
            throw new RuntimeException('Unable to open uploaded CSV file.');
        }

        fgetcsv($file);
        $rows = [];

        while (($row = fgetcsv($file)) !== false) {
            if (count($row) < 10) {
                continue;
            }

            $matric = trim($row[0]);
            $name = trim($row[1]);
            $level = (int)trim($row[2]);
            $faculty = trim($row[3]);
            $dept = trim($row[4]);
            $gender = trim($row[5]);
            $condition = UrgencyScoreService::normalizeCondition(trim($row[6]));
            $severity = trim($row[7]);
            $mobility = UrgencyScoreService::normalizeMobility(trim($row[8]));
            $paidStr = trim($row[9]);

            if (
                $matric === '' || $name === '' || $faculty === '' || $dept === '' || $gender === ''
                || $condition === '' || $severity === '' || $mobility === '' || $paidStr === ''
            ) {
                continue;
            }

            $rows[] = [
                'matric' => $matric,
                'name' => $name,
                'level' => $level,
                'faculty' => $faculty,
                'department' => $dept,
                'gender' => $gender,
                'condition' => $condition,
                'severity' => $severity,
                'mobility' => $mobility,
                'is_paid' => (int)$paidStr === 1 ? 1 : 0,
            ];
        }

        fclose($file);
        return $rows;
    }

    private function loadExistingUsernames(): array
    {
        $cache = [];
        $result = $this->conn->query("SELECT username FROM users WHERE role = 'student'");
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $cache[strtolower((string)$row['username'])] = true;
            }
            $result->free();
        }
        return $cache;
    }

    private function loadFacultyCache(): array
    {
        $cache = [];
        $result = $this->conn->query("SELECT faculty_id, name FROM faculties");
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $cache[strtolower(trim((string)$row['name']))] = (int)$row['faculty_id'];
            }
            $result->free();
        }
        return $cache;
    }

    private function loadDepartmentCache(): array
    {
        $cache = [];
        $result = $this->conn->query("SELECT department_id, faculty_id, name FROM departments");
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $key = (int)$row['faculty_id'] . ':' . strtolower(trim((string)$row['name']));
                $cache[$key] = (int)$row['department_id'];
            }
            $result->free();
        }
        return $cache;
    }

    private function persistJobTotals(int $totalRows, int $processedRows, string $stage, int $percent): void
    {
        if ($this->jobId === null) {
            return;
        }

        $stmt = $this->conn->prepare(
            "UPDATE allocation_jobs
                SET total_students = ?,
                    allocated_students = ?,
                    progress_stage = ?,
                    progress_percent = ?,
                    updated_at = NOW()
              WHERE job_id = ?"
        );
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('iisii', $totalRows, $processedRows, $stage, $percent, $this->jobId);
        $stmt->execute();
        $stmt->close();
    }

    private function emitProgress(?callable $progressCallback, string $stage, int $percent, int $totalRows, int $processedRows): void
    {
        if (!$progressCallback) {
            return;
        }

        $progressCallback([
            'stage' => $stage,
            'percent' => $percent,
            'total' => $totalRows,
            'processed' => $processedRows,
        ]);
    }
}
