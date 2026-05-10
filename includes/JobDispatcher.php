<?php
require_once __DIR__ . '/Logger.php';

if (!function_exists('fairmedResolvePhpCliBinary')) {
    function fairmedResolvePhpCliBinary(): string
    {
        $candidates = [];

        if (defined('PHP_BINARY') && PHP_BINARY !== '') {
            $candidates[] = PHP_BINARY;
        }

        if (defined('PHP_BINDIR') && PHP_BINDIR !== '') {
            $binDir = rtrim((string)PHP_BINDIR, "\\/");
            if (DIRECTORY_SEPARATOR === '\\') {
                $candidates[] = $binDir . DIRECTORY_SEPARATOR . 'php.exe';
                $candidates[] = $binDir . DIRECTORY_SEPARATOR . 'php-cli.exe';
            } else {
                $candidates[] = $binDir . DIRECTORY_SEPARATOR . 'php';
            }
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $candidates[] = 'C:\\xampp\\php\\php.exe';
        } else {
            $candidates[] = 'php';
        }

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            if ($candidate === 'php') {
                return $candidate;
            }
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return DIRECTORY_SEPARATOR === '\\' ? 'php' : 'php';
    }
}

if (!function_exists('fairmedDispatchWorker')) {
    function fairmedDispatchWorker(int $job_id): array
    {
        $php = fairmedResolvePhpCliBinary();
        $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'worker_allocation.php';

        if (!file_exists($script)) {
            $message = "Worker script not found at $script";
            Logger::error("dispatchWorker: $message");
            return ['launched' => false, 'message' => $message];
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['file', 'NUL', 'w'],
                2 => ['file', 'NUL', 'w'],
            ];
            $proc = @proc_open(
                [$php, $script, '--job-id=' . (int)$job_id],
                $descriptors,
                $pipes,
                dirname(__DIR__),
                null,
                ['bypass_shell' => true, 'create_process_group' => true]
            );
            if (is_resource($proc)) {
                foreach ($pipes as $pipe) {
                    @fclose($pipe);
                }
                // Do NOT call proc_close() — that blocks until the child exits,
                // defeating the purpose of background dispatch. With
                // create_process_group:true the child is in its own Windows
                // job object / process group and survives the parent.
                // Let the handle go out of scope; PHP will not wait for it.
                unset($proc);
                Logger::info("dispatchWorker: proc_open (detached) launched Job #$job_id");
                return ['launched' => true, 'message' => null];
            }

            $cmd = 'start /b "" ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --job-id=' . (int)$job_id;
            $handle = @popen($cmd, 'r');
            if ($handle !== false) {
                // Do NOT call pclose() — it blocks until the spawned process exits.
                // The pipe handle will be closed by PHP's garbage collector.
                Logger::info("dispatchWorker: popen (fallback) launched Job #$job_id");
                return ['launched' => true, 'message' => null];
            }

            Logger::error("dispatchWorker: all Windows launch methods failed for Job #$job_id");
            return [
                'launched' => false,
                'message'  => "Unable to launch the background worker for Job #$job_id. Ensure php.exe is accessible to the Apache process.",
            ];
        }

        $errLog = sys_get_temp_dir() . '/fairmedalloc_worker_' . $job_id . '.err';
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script)
             . ' --job-id=' . (int)$job_id
             . ' > /dev/null 2>' . escapeshellarg($errLog) . ' &';
        exec($cmd, $out, $rc);
        if ($rc === 0) {
            Logger::info("dispatchWorker: exec launched Job #$job_id");
            return ['launched' => true, 'message' => null];
        }

        Logger::error("dispatchWorker: exec() returned code $rc for Job #$job_id - command: $cmd");
        return [
            'launched' => false,
            'message' => "Unable to launch the background worker for Job #$job_id."
        ];
    }
}
