<?php
/**
 * XGBoost score integration helper
 * =================================
 * Centralizes all Python scoring calls so the rest of the PHP app can stay
 * focused on user flows. The Python layer uses the provided XGBoost .pkl
 * model and only falls back to deterministic PHP rules when a request cannot
 * be satisfied by the model path.
 */

require_once __DIR__ . '/MlServiceClient.php';

class UrgencyScoreService {
    private $timeout;
    private $baseUrl;

    public function __construct($baseUrl = null, $timeout = null) {
        $this->baseUrl = rtrim($baseUrl ?? ML_SERVICE_URL, '/');
        $this->timeout = (float)($timeout ?? ML_SERVICE_TIMEOUT);
    }

    public function scoreStudent(array $student): array {
        if (!isset($student['id'])) {
            $student['id'] = '__single__';
        }

        $result = $this->scoreBatch([$student]);
        if (($result['status'] ?? '') !== 'success') {
            throw new Exception($result['message'] ?? 'Unable to score student.');
        }

        $studentId = $student['id'];
        if (!isset($result['results'][$studentId])) {
            throw new Exception('Scoring response did not include the requested student.');
        }

        $score = (float)$result['results'][$studentId];
        $tier = $result['tiers'][$studentId] ?? self::tierFromScore($score);

        return [
            'status' => 'success',
            'score' => $score,
            'tier' => $tier,
            'mode' => $result['mode'] ?? 'XGBoost',
        ];
    }

    public function scoreBatch(array $payload): array {
        $serviceErrorMessage = null;

        try {
            $client = new MlServiceClient($this->baseUrl, $this->timeout);
            $result = $client->scoreBatch($payload);
            if (($result['status'] ?? '') === 'success') {
                if (!isset($result['tiers']) && isset($result['results']) && is_array($result['results'])) {
                    $result['tiers'] = $this->buildTiersFromScores($result['results']);
                }
                return $result;
            }
        } catch (Exception $serviceError) {
            $serviceErrorMessage = $serviceError->getMessage();
        }

        try {
            return $this->runLocalPredictScript($payload);
        } catch (Exception $localError) {
            if ($serviceErrorMessage !== null) {
                error_log('[FairMedAlloc] XGBoost service unavailable, and local predict.py also failed: ' . $serviceErrorMessage . ' | ' . $localError->getMessage());
            }
            throw $localError;
        }
    }

    public static function calculateFallbackScore(array $student): float {
        if (isset($student['urgency_score']) && $student['urgency_score'] !== null) {
            $value = (float)$student['urgency_score'];
            if ($value > 0) {
                return min(max($value, 0.0), 100.0);
            }
        }

        $condition = self::normalizeCondition($student['condition'] ?? 'None');
        $mobility = self::normalizeMobility($student['mobility'] ?? ($student['mobility_status'] ?? 'Normal Mobility'));
        $severity = self::normalizeSeverityValue($student['severity'] ?? ($student['severity_level'] ?? 'Low'));

        $weights = [
            'Sickle Cell' => 90.0,
            'Epilepsy' => 90.0,
            'Diabetes' => 90.0,
            'Cardiac' => 90.0,
            'Cardiovascular' => 90.0,
            'Neurological' => 70.0,
            'Orthopaedic' => 65.0,
            'Physical Disability' => 65.0,
            'Visual Impairment' => 60.0,
            'Asthma' => 50.0,
            'Respiratory' => 50.0,
            'Ulcer' => 30.0,
            'Other' => 20.0,
            'Mobility' => 0.0,
            'Wheelchair User' => 0.0,
            'Crutches/Walker' => 0.0,
            'Artificial Limb' => 0.0,
            'None' => 0.0,
        ];

        $score = 10.0 + ($weights[$condition] ?? 0.0);
        $isRequested = isset($student['is_requested'])
            ? (bool)$student['is_requested']
            : ((int)($student['has_special_needs'] ?? 0) === 1);

        $mobilityScore = 0.0;
        if (in_array($mobility, ['Wheelchair User', 'Crutches/Walker', 'Artificial Limb'], true)) {
            $mobilityScore = $isRequested ? 90.0 : 75.0;
        }

        $score = max($score, $mobilityScore);
        $score += ($severity * 5.0);

        return min(max($score, 0.0), 100.0);
    }

    public static function tierFromScore(float $score): string {
        if ($score >= 70) {
            return 'High';
        }
        if ($score >= 40) {
            return 'Medium';
        }
        return 'Low';
    }

    public static function normalizeCondition(string $condition): string {
        $raw = trim($condition);
        $aliases = [
            '' => 'None',
            'none' => 'None',
            'none / healthy' => 'None',
            'healthy' => 'None',
            'sickle cell disease' => 'Sickle Cell',
            'sickle cell' => 'Sickle Cell',
            'cardiac issue' => 'Cardiac',
            'cardiac' => 'Cardiac',
            'cardiovascular' => 'Cardiovascular',
            'orthopedic' => 'Orthopaedic',
            'orthopaedic' => 'Orthopaedic',
            'crutches / walker' => 'Crutches/Walker',
            'crutches/walker' => 'Crutches/Walker',
        ];

        $lookup = strtolower(preg_replace('/\s+/', ' ', $raw));
        return $aliases[$lookup] ?? $raw;
    }

    public static function normalizeMobility($mobility): string {
        $raw = trim((string)$mobility);
        $aliases = [
            '' => 'Normal Mobility',
            '0' => 'Normal Mobility',
            'normal' => 'Normal Mobility',
            'normal mobility' => 'Normal Mobility',
            '1' => 'Artificial Limb',
            'artificial limb' => 'Artificial Limb',
            '2' => 'Crutches/Walker',
            'crutches' => 'Crutches/Walker',
            'walker' => 'Crutches/Walker',
            'crutches / walker' => 'Crutches/Walker',
            'crutches/walker' => 'Crutches/Walker',
            '3' => 'Wheelchair User',
            'wheelchair' => 'Wheelchair User',
            'wheelchair user' => 'Wheelchair User',
        ];

        $lookup = strtolower(preg_replace('/\s+/', ' ', $raw));
        return $aliases[$lookup] ?? $raw;
    }

    public static function normalizeSeverityValue($severity): int {
        if (is_numeric($severity)) {
            return max(0, min((int)$severity, 3));
        }

        $lookup = strtolower(trim((string)$severity));
        $aliases = [
            '0' => 0,
            'low' => 1,
            '1' => 1,
            'medium' => 2,
            '2' => 2,
            'high' => 3,
            '3' => 3,
        ];

        return $aliases[$lookup] ?? 1;
    }

    private function runLocalPredictScript(array $payload): array {
        $tempFile = tempnam(sys_get_temp_dir(), 'fairmed_predict_');
        if ($tempFile === false) {
            throw new Exception('Unable to create a temporary file for the prediction payload.');
        }

        if (file_put_contents($tempFile, json_encode($payload)) === false) {
            @unlink($tempFile);
            throw new Exception('Unable to write the prediction payload.');
        }

        try {
            $scriptPath = __DIR__ . '/../ml_models/predict.py';
            $lastError = 'No Python runtime could execute predict.py.';

            foreach ($this->getPythonCommandCandidates() as $commandParts) {
                $output = $this->executeCommand(array_merge($commandParts, [$scriptPath, $tempFile]));
                if (!is_string($output) || trim($output) === '') {
                    continue;
                }

                $decoded = $this->extractJsonResponse($output);
                if (is_array($decoded) && isset($decoded['status'])) {
                    if (($decoded['status'] ?? '') === 'success' && !isset($decoded['tiers']) && isset($decoded['results'])) {
                        $decoded['tiers'] = $this->buildTiersFromScores($decoded['results']);
                    }
                    return $decoded;
                }

                $lastError = trim($output);
            }

            throw new Exception($lastError);
        } finally {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    private function getPythonCommandCandidates(): array {
        $candidates = [];
        $configured = defined('PYTHON_BIN') && PYTHON_BIN !== ''
            ? trim((string)PYTHON_BIN)
            : (defined('FAIRMED_PYTHON_BIN') ? trim((string)FAIRMED_PYTHON_BIN) : '');

        if ($configured !== '') {
            $parts = array_values(array_filter(str_getcsv($configured, ' '), static function ($part) {
                return $part !== null && $part !== '';
            }));
            if (is_array($parts) && !empty($parts)) {
                $candidates[] = $parts;
            }
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $candidates[] = ['python'];
            $candidates[] = ['py', '-3'];
        } else {
            $candidates[] = ['python3'];
            $candidates[] = ['python'];
        }

        return $candidates;
    }

    private function executeCommand(array $commandParts): ?string {
        $escapedParts = array_map([$this, 'escapeCommandPart'], $commandParts);
        $command = implode(' ', $escapedParts);
        $output = @shell_exec($command . ' 2>&1');
        return is_string($output) ? trim($output) : null;
    }

    private function escapeCommandPart(string $value): string {
        if (DIRECTORY_SEPARATOR === '\\') {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return escapeshellarg($value);
    }

    private function buildTiersFromScores(array $scores): array {
        $tiers = [];
        foreach ($scores as $studentId => $score) {
            $tiers[$studentId] = self::tierFromScore((float)$score);
        }
        return $tiers;
    }

    private function extractJsonResponse(string $output): ?array {
        $lines = preg_split('/\r\n|\r|\n/', trim($output)) ?: [];
        for ($index = count($lines) - 1; $index >= 0; $index--) {
            $candidate = trim($lines[$index]);
            if ($candidate === '') {
                continue;
            }

            $decoded = json_decode($candidate, true);
            if (is_array($decoded) && isset($decoded['status'])) {
                return $decoded;
            }
        }

        return null;
    }
}
?>
