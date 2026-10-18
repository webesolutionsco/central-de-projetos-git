<?php

use App\Services\CommitCheckJobs;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('central:verify {job}', function (CommitCheckJobs $jobs): void {
    $jobs->execute((string) $this->argument('job'));
})->purpose('Executa uma verificação isolada previamente solicitada pela Central');

Artisan::command('central:sanitize-check-history', function (CommitCheckJobs $jobs): void {
    $summary = $jobs->sanitizeHistory();
    $this->info("Relatórios sanitizados: {$summary['sanitized']}. Execuções ativas preservadas: {$summary['skipped']}.");
})->purpose('Remove saídas brutas antigas do histórico de verificações, preservando resultados seguros');
