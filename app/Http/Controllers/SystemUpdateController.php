<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Support\Access\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Inertia\Inertia;
use Inertia\Response;

class SystemUpdateController extends Controller
{
    public function index(Request $request): Response
    {
        $this->ensureAuthorized($request);

        $gitInfo = $this->getGitStatus();
        $recentCommits = $this->getRecentCommits(15);
        $systemInfo = [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'os' => PHP_OS_FAMILY.' ('.php_uname('s').' '.php_uname('r').')',
            'environment' => app()->environment(),
            'app_url' => config('app.url'),
        ];

        return Inertia::render('System/Update', [
            'git' => $gitInfo,
            'commits' => $recentCommits,
            'system' => $systemInfo,
            'updateOutput' => session('update_output'),
        ]);
    }

    public function check(Request $request): RedirectResponse
    {
        $this->ensureAuthorized($request);

        $res = Process::path(base_path())->run('git fetch origin');
        if (! $res->successful()) {
            return back()->with('error', 'Gagal memeriksa update dari Git remote: '.$res->errorOutput());
        }

        return back()->with('success', 'Pemeriksaan update selesai. Status Git telah diperbarui.');
    }

    public function apply(Request $request): RedirectResponse
    {
        $this->ensureAuthorized($request);

        $request->validate([
            'run_composer' => ['nullable', 'boolean'],
            'run_migrate' => ['nullable', 'boolean'],
            'run_optimize' => ['nullable', 'boolean'],
        ]);

        $runComposer = $request->boolean('run_composer', true);
        $runMigrate = $request->boolean('run_migrate', true);
        $runOptimize = $request->boolean('run_optimize', true);

        $basePath = base_path();
        $logs = [];
        $hasError = false;

        // 1. Git pull
        $gitPull = Process::path($basePath)->timeout(120)->run('git pull origin main');
        $logs[] = "[1/4] GIT PULL:\n".($gitPull->output() ?: $gitPull->errorOutput());
        if (! $gitPull->successful()) {
            $hasError = true;
        }

        // 2. Composer install (optional)
        if (! $hasError && $runComposer) {
            $composerBinary = 'composer';
            if (! Process::path($basePath)->run('which composer')->successful()) {
                if (file_exists($basePath.'/composer.phar')) {
                    $composerBinary = PHP_BINARY.' '.$basePath.'/composer.phar';
                }
            }
            $compProc = Process::path($basePath)->timeout(300)->run($composerBinary.' install --no-interaction --prefer-dist --optimize-autoloader');
            $logs[] = "[2/4] COMPOSER INSTALL:\n".($compProc->output() ?: $compProc->errorOutput());
            if (! $compProc->successful()) {
                $hasError = true;
            }
        }

        // 3. Database Migration
        if (! $hasError && $runMigrate) {
            $migrateProc = Process::path($basePath)->timeout(120)->run(PHP_BINARY.' artisan migrate --force');
            $logs[] = "[3/4] DATABASE MIGRATION:\n".($migrateProc->output() ?: $migrateProc->errorOutput());
            if (! $migrateProc->successful()) {
                $hasError = true;
            }
        }

        // 4. Optimize Clear & Cache
        if ($runOptimize) {
            $optProc = Process::path($basePath)->timeout(60)->run(PHP_BINARY.' artisan optimize:clear');
            $logs[] = "[4/4] OPTIMIZE CACHE:\n".($optProc->output() ?: $optProc->errorOutput());
        }

        $fullLog = implode("\n----------------------------------------\n", $logs);

        AuditLogger::record('system.git_update', new: [
            'success' => ! $hasError,
            'composer' => $runComposer,
            'migrate' => $runMigrate,
        ]);

        if ($hasError) {
            return back()->with([
                'error' => 'Pembaruan sistem mengalami kendala. Periksa log detail di bawah.',
                'update_output' => $fullLog,
            ]);
        }

        return back()->with([
            'success' => 'Sistem MikWeb berhasil diperbarui ke versi terbaru dari Git.',
            'update_output' => $fullLog,
        ]);
    }

    private function getGitStatus(): array
    {
        $base = base_path();

        $branch = trim(Process::path($base)->run('git branch --show-current')->output()) ?: 'main';
        $remoteUrl = trim(Process::path($base)->run('git config --get remote.origin.url')->output());

        $currentCommitHash = trim(Process::path($base)->run('git rev-parse HEAD')->output());
        $currentShortHash = trim(Process::path($base)->run('git rev-parse --short HEAD')->output());
        $currentMessage = trim(Process::path($base)->run('git log -1 --pretty=format:%s')->output());
        $currentAuthor = trim(Process::path($base)->run('git log -1 --pretty=format:%an')->output());
        $currentDate = trim(Process::path($base)->run('git log -1 --pretty=format:%ar')->output());

        $statusPorcelain = trim(Process::path($base)->run('git status --porcelain')->output());
        $hasLocalModifications = ! empty($statusPorcelain);

        $behindCount = 0;
        $aheadCount = 0;
        $remoteLatestCommit = null;

        $revList = Process::path($base)->run("git rev-list --left-right --count HEAD...origin/{$branch}");
        if ($revList->successful()) {
            $counts = explode("\t", trim($revList->output()));
            $aheadCount = isset($counts[0]) ? (int) $counts[0] : 0;
            $behindCount = isset($counts[1]) ? (int) $counts[1] : 0;
        }

        $remoteLog = Process::path($base)->run("git log -1 origin/{$branch} --pretty=format:%H|%h|%an|%ar|%s");
        if ($remoteLog->successful() && ! empty(trim($remoteLog->output()))) {
            $parts = explode('|', trim($remoteLog->output()), 5);
            $remoteLatestCommit = [
                'hash' => $parts[0] ?? '',
                'short_hash' => $parts[1] ?? '',
                'author' => $parts[2] ?? '',
                'date' => $parts[3] ?? '',
                'message' => $parts[4] ?? '',
            ];
        }

        return [
            'branch' => $branch,
            'remote_url' => $remoteUrl,
            'current' => [
                'hash' => $currentCommitHash,
                'short_hash' => $currentShortHash,
                'message' => $currentMessage,
                'author' => $currentAuthor,
                'date' => $currentDate,
            ],
            'has_modifications' => $hasLocalModifications,
            'behind' => $behindCount,
            'ahead' => $aheadCount,
            'is_up_to_date' => $behindCount === 0,
            'remote_latest' => $remoteLatestCommit,
        ];
    }

    private function getRecentCommits(int $limit = 15): array
    {
        $base = base_path();
        $output = Process::path($base)->run("git log -n {$limit} --pretty=format:%H|%h|%an|%ae|%ad|%ar|%s")->output();

        if (empty(trim($output))) {
            return [];
        }

        $lines = explode("\n", trim($output));
        $commits = [];

        foreach ($lines as $line) {
            $parts = explode('|', $line, 7);
            if (count($parts) >= 6) {
                $commits[] = [
                    'hash' => $parts[0],
                    'short_hash' => $parts[1],
                    'author_name' => $parts[2],
                    'author_email' => $parts[3],
                    'date' => $parts[4],
                    'relative_date' => $parts[5],
                    'message' => $parts[6] ?? '',
                ];
            }
        }

        return $commits;
    }

    private function ensureAuthorized(Request $request): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $isAllowed = ($user->is_super_admin ?? false)
            || $user->hasRole(Permissions::ROLE_SUPER_ADMIN)
            || $user->hasRole(Permissions::ROLE_ORG_ADMIN);

        if (! $isAllowed) {
            abort(403, 'Akses ditolak: Hanya Administrator yang berhak mengelola pembaruan sistem.');
        }
    }
}
