<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;
use Inertia\Inertia;

class TerminalController extends Controller
{
    private function resolveProjectRoot(): string
    {
        $basePath = base_path();

        if (File::exists($basePath . DIRECTORY_SEPARATOR . 'artisan') && File::exists($basePath . DIRECTORY_SEPARATOR . 'composer.json')) {
            return $basePath;
        }

        $dir = realpath(__DIR__) ?: $basePath;

        while ($dir && $dir !== dirname($dir)) {
            if (File::exists($dir . DIRECTORY_SEPARATOR . 'artisan') && File::exists($dir . DIRECTORY_SEPARATOR . 'composer.json')) {
                return $dir;
            }

            $dir = dirname($dir);
        }

        return $basePath;
    }

    private function toShellPath(string $path, string $shellType): string
    {
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('/^([A-Za-z]):\\\\(.*)$/', $path, $matches)) {
            $drive = strtolower($matches[1]);
            $rest = str_replace('\\', '/', $matches[2]);

            if ($shellType === 'wsl') {
                return "/mnt/{$drive}/{$rest}";
            }

            return "/{$drive}/{$rest}";
        }

        return str_replace('\\', '/', $path);
    }

    private function toWindowsPath(string $path): string
    {
        $path = trim($path);

        if (DIRECTORY_SEPARATOR === '\\' && preg_match('#^/mnt/([a-zA-Z])/(.*)$#', $path, $matches)) {
            return strtoupper($matches[1]) . ':\\' . str_replace('/', '\\', $matches[2]);
        }

        if (DIRECTORY_SEPARATOR === '\\' && preg_match('#^/([a-zA-Z])/(.*)$#', $path, $matches)) {
            return strtoupper($matches[1]) . ':\\' . str_replace('/', '\\', $matches[2]);
        }

        return $path;
    }

    /**
     * Build an enriched PATH string that merges the web-server PATH with
     * the directories of key binaries (PHP, Composer) that are typically
     * absent from the minimal PATH Apache/WAMP provides.
     */
    private function buildEnrichedPath(string $phpBinaryDir, string $shellType, string $homeDirectory): string
    {
        $existing = getenv('PATH') ?: (getenv('Path') ?: '');

        $extras = [$phpBinaryDir];

        if (DIRECTORY_SEPARATOR === '\\') {
            $extras[] = 'C:\\ProgramData\\ComposerSetup\\bin';
            $extras[] = getenv('APPDATA') . '\\Composer\\vendor\\bin';
            $extras[] = 'C:\\Program Files\\Git\\usr\\bin';
            $extras[] = 'C:\\Program Files\\Git\\bin';
            $extras[] = 'C:\\Windows\\System32';
        } else {
            $extras[] = '/usr/local/bin';
            $extras[] = '/usr/bin';
            $extras[] = '/bin';
            $extras[] = $homeDirectory . '/bin';
            $extras[] = $homeDirectory . '/.local/bin';
            $extras[] = $homeDirectory . '/.local/ffmpeg';
            $extras[] = $homeDirectory . '/.composer/vendor/bin';
        }

        $all = array_unique(array_filter(array_map('trim', array_merge($extras, explode(PATH_SEPARATOR, $existing)))));

        if ($shellType === 'wsl' || DIRECTORY_SEPARATOR !== '\\') {
            return implode(':', $all);
        }

        $bashPaths = array_map(fn($p) => $this->toShellPath($p, $shellType), $all);

        return implode(':', $bashPaths);
    }

    private function resolveHomeDirectory(string $currentDirectory, string $projectRoot): string
    {
        $home = trim((string) getenv('HOME'));

        if ($home !== '' && is_dir($home)) {
            return str_replace('\\', '/', $home);
        }

        $candidates = array_values(array_unique(array_filter([
            $currentDirectory,
            $projectRoot,
            getcwd() ?: null,
        ])));

        foreach ($candidates as $candidate) {
            $normalized = str_replace('\\', '/', $candidate);

            if (preg_match('#^/home[^/]*/[^/]+#', $normalized, $matches) === 1) {
                return $matches[0];
            }

            if (preg_match('#^/Users/[^/]+#', $normalized, $matches) === 1) {
                return $matches[0];
            }
        }

        // Final fallback: keep non-root writable path if available.
        return str_replace('\\', '/', $projectRoot);
    }

    private function resolveShell(): ?array
    {
        $finder = new ExecutableFinder();

        if (DIRECTORY_SEPARATOR === '\\') {
            $candidates = [
                'C:\\Program Files\\Git\\bin\\bash.exe',
                'C:\\Program Files\\Git\\usr\\bin\\bash.exe',
            ];

            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    return ['type' => 'bash', 'command' => [$candidate, '--noprofile', '--norc', '-c']];
                }
            }

            $bash = $finder->find('bash');

            if ($bash) {
                return ['type' => 'bash', 'command' => [$bash, '--noprofile', '--norc', '-c']];
            }

            $wsl = $finder->find('wsl');
            if ($wsl) {
                return ['type' => 'wsl', 'command' => [$wsl, 'bash', '--noprofile', '--norc', '-c']];
            }

            return null;
        }

        $bash = $finder->find('bash');

        if ($bash) {
            return ['type' => 'bash', 'command' => [$bash, '--noprofile', '--norc', '-c']];
        }

        return null;
    }

    /**
     * Find the Composer binary using both ExecutableFinder (which respects
     * the current runtime PATH) and a set of well-known installation paths,
     * so it works even when Apache/WAMP provides a minimal PATH.
     */
    private function findComposer(): ?string
    {
        $finder = new ExecutableFinder();

        $phpDir = dirname(PHP_BINARY);

        $searchPaths = [
            $phpDir,
            'C:\\ProgramData\\ComposerSetup\\bin',
            getenv('APPDATA') . '\\Composer\\vendor\\bin',
            '/usr/local/bin',
            '/usr/bin',
            getenv('HOME') . '/.composer/vendor/bin',
        ];

        return $finder->find('composer', null, array_filter($searchPaths));
    }

    private function isCliPhpBinary(string $binary): bool
    {
        try {
            $process = new Process([$binary, '-r', 'echo PHP_SAPI;']);
            $process->setTimeout(5);
            $process->run();

            if (!$process->isSuccessful()) {
                return false;
            }

            $sapi = strtolower(trim($process->getOutput()));

            return in_array($sapi, ['cli', 'phpdbg'], true);
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveCliPhpBinary(): string
    {
        $finder = new ExecutableFinder();

        $candidates = [
            PHP_BINARY,
        ];

        if (defined('PHP_BINDIR')) {
            $candidates[] = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php';

            if (DIRECTORY_SEPARATOR === '\\') {
                $candidates[] = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe';
            }
        }

        $foundPhp = $finder->find('php');
        if ($foundPhp) {
            $candidates[] = $foundPhp;
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            $candidates[] = '/usr/local/bin/php';
            $candidates[] = '/usr/bin/php';
        }

        $uniqueCandidates = array_values(array_unique(array_filter($candidates)));

        foreach ($uniqueCandidates as $candidate) {
            if ($this->isCliPhpBinary($candidate)) {
                return $candidate;
            }
        }

        return PHP_BINARY;
    }

    private function resolveCdTarget(string $argument, string $currentDirectory, string $projectRoot): ?string
    {
        $argument = trim($argument);

        if ($argument === '' || $argument === '~') {
            return $projectRoot;
        }

        if (
            (str_starts_with($argument, '"') && str_ends_with($argument, '"')) ||
            (str_starts_with($argument, "'") && str_ends_with($argument, "'"))
        ) {
            $argument = substr($argument, 1, -1);
        }

        $argument = $this->toWindowsPath($argument);

        $isAbsolute = preg_match('/^[A-Za-z]:\\\\/', $argument) === 1
            || str_starts_with($argument, '/')
            || str_starts_with($argument, '\\\\');

        $candidate = $isAbsolute
            ? $argument
            : $currentDirectory . DIRECTORY_SEPARATOR . $argument;

        $resolved = realpath($candidate);

        if ($resolved === false || !is_dir($resolved)) {
            return null;
        }

        return $resolved;
    }

    private function cleanOutput(string $text): string
    {
        // Strip ANSI escape/control sequences so web terminal shows clean text.
        $text = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $text) ?? $text; // CSI
        $text = preg_replace('/\x1B\][^\x07]*\x07/', '', $text) ?? $text; // OSC (BEL-terminated)
        $text = preg_replace('/\x1B[@-Z\\-_]/', '', $text) ?? $text; // single-char escapes

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\t ]+$/m', '', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    public function index(Request $request)
    {
        $request->session()->put('terminal.cwd', $this->resolveProjectRoot());

        return Inertia::render("Terminal");
    }

    public function execute(Request $request)
    {
        $request->validate([
            'command' => ['required', 'string'],
        ]);

        $userCommand = $request->input('command');

        // Log the received command
        Log::channel('terminal')->info("Executing command: {$userCommand}");

        try {
            $projectRoot = $this->resolveProjectRoot();
            $session = $request->session();
            $currentDirectory = $session->get('terminal.cwd', $projectRoot);

            if (!is_dir($currentDirectory)) {
                $currentDirectory = $projectRoot;
                $session->put('terminal.cwd', $currentDirectory);
            }

            if (preg_match('/^\s*cd(?:\s+(.*))?\s*$/i', $userCommand, $cdMatch) === 1) {
                $cdArgument = $cdMatch[1] ?? '';
                $targetDirectory = $this->resolveCdTarget($cdArgument, $currentDirectory, $projectRoot);

                if ($targetDirectory === null) {
                    return response()->json([
                        'success' => false,
                        'output' => sprintf('cd: no such file or directory: %s', trim($cdArgument) === '' ? '~' : trim($cdArgument)),
                    ], 500);
                }

                $session->put('terminal.cwd', $targetDirectory);

                return response()->json([
                    'success' => true,
                    'output' => $this->toShellPath($targetDirectory, 'bash'),
                ]);
            }

            $shell = $this->resolveShell();
            if ($shell === null) {
                return response()->json([
                    'success' => false,
                    'output' => 'No Linux shell found. Install Git Bash or WSL and ensure it is available in PATH.',
                ], 500);
            }
            $shellType = $shell['type'];

            $env = app()->environment();

            if ($env !== 'local') {
                $composerHome = getenv('COMPOSER_HOME');
                if (!empty($composerHome)) {
                    putenv("COMPOSER_HOME={$composerHome}");
                }
            }

            // Prefer a real CLI PHP binary for terminal commands.
            $phpBinary    = $this->resolveCliPhpBinary();
            $phpBinaryDir = dirname($phpBinary);
            $php          = escapeshellarg($this->toShellPath($phpBinary, $shellType));

            $homeDirectory = $this->resolveHomeDirectory($currentDirectory, $projectRoot);
            putenv('HOME=' . $homeDirectory);

            // Enrich the shell PATH so the Process can find php, composer, etc.
            $enrichedPath = $this->buildEnrichedPath($phpBinaryDir, $shellType, $homeDirectory);

            $isComposerCommand = stripos($userCommand, 'composer') === 0;

            if ($isComposerCommand) {
                $composerArgs   = trim(substr($userCommand, strlen('composer')));
                $systemComposer = $this->findComposer();

                // Web terminal output is cleaner and predictable without ANSI/control UI updates.
                if (!preg_match('/(^|\s)--no-ansi(\s|$)/', $composerArgs)) {
                    $composerArgs = trim($composerArgs . ' --no-ansi');
                }

                if (!preg_match('/(^|\s)--no-interaction(\s|$)/', $composerArgs)) {
                    $composerArgs = trim($composerArgs . ' --no-interaction');
                }

                if ($systemComposer) {
                    $composerBin = escapeshellarg($this->toShellPath($systemComposer, $shellType));

                    $composerExt = strtolower(pathinfo($systemComposer, PATHINFO_EXTENSION));

                    if (in_array($composerExt, ['bat', 'cmd'], true)) {
                        $command = "{$composerBin} {$composerArgs}";
                    } else {
                        // Composer requires argv to be available so subcommands
                        // like `install`/`update` are parsed correctly.
                        $command = trim("{$php} -d register_argc_argv=1 {$composerBin} {$composerArgs}");
                    }
                } else {
                    $composerPath = $projectRoot . DIRECTORY_SEPARATOR . 'composer.phar';

                    if (!File::exists($composerPath)) {
                        $msg = 'composer not found: no global composer binary and no composer.phar in the project root.';
                        Log::channel('terminal')->error($msg);
                        return response()->json([
                            'success' => false,
                            'output' => $msg,
                        ], 400);
                    }

                    // Keep argv enabled so composer subcommands and flags work.
                    $command = trim("{$php} -d register_argc_argv=1 composer.phar {$composerArgs}");
                }
            } elseif (stripos($userCommand, 'php artisan') === 0) {
                $artisanArgs = trim(substr($userCommand, strlen('php artisan')));
                $command = trim("$php artisan {$artisanArgs}");
            } elseif (stripos($userCommand, 'artisan') === 0) {
                $artisanArgs = trim(substr($userCommand, strlen('artisan')));
                $command = trim("$php artisan {$artisanArgs}");
            } elseif (stripos($userCommand, 'migrate') === 0) {
                $command = "$php artisan $userCommand";
            } else {
                $command = $userCommand;
            }

            $shellDirectory = $this->toShellPath($currentDirectory, $shellType);
            $bashCommand = "export HOME=" . escapeshellarg($homeDirectory)
                . " && export PATH=" . escapeshellarg($enrichedPath) . ":\$PATH && cd "
                . escapeshellarg($shellDirectory) . " && " . $command;

            $process = new Process([...$shell['command'], $bashCommand], $projectRoot);
            $process->setTimeout(3600);
            $process->run();

            $stdout = $this->cleanOutput($process->getOutput());
            $stderr = $this->cleanOutput($process->getErrorOutput());

            if (!$process->isSuccessful()) {
                $sections = [];

                $sections[] = sprintf(
                    'The command "%s" failed (exit code %d).',
                    $userCommand,
                    $process->getExitCode() ?? 1,
                );

                if (!empty($stderr)) {
                    $sections[] = $stderr;
                } elseif (!empty($stdout)) {
                    $sections[] = $stdout;
                }

                $errorOutput = implode("\n\n", $sections);

                Log::channel('terminal')->error("Command failed: {$userCommand}", [
                    'exit_code' => $process->getExitCode(),
                    'stderr' => $stderr,
                    'stdout' => $stdout,
                ]);

                Log::channel('terminal')->info("*************************************");

                return response()->json([
                    'success' => false,
                    'output' => $errorOutput,
                ], 500);
            }

            $output = $stdout;

            if ($output === '' && $stderr !== '') {
                $output = $stderr;
            }

            if ($output === '') {
                $output = 'Command executed successfully.';
            }

            Log::channel('terminal')->info("Output: {$output}");
            Log::channel('terminal')->info("*************************************");

            return response()->json([
                'success' => true,
                'output' => $output,
            ]);
        } catch (\Throwable $e) {
            Log::channel('terminal')->error("Command failed: {$userCommand}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            Log::channel('terminal')->info("*************************************");

            return response()->json([
                'success' => false,
                'output' => $this->cleanOutput($e->getMessage()) ?: 'Error executing command.',
            ], 500);
        }
    }
}
