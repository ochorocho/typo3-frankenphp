<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Command;

use Ochorocho\FrankenPhp\Profiler\ProfileReport;
use Ochorocho\FrankenPhp\Profiler\RequestProfile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;

#[AsCommand(
    name: 'frankenphp:profile',
    description: 'Where a worker request spends its time: medians per middleware, TimeTracker span and query, from the profile log',
)]
final class ProfileCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('uri', null, InputOption::VALUE_REQUIRED, 'Only requests whose URI contains this string', '');
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Profile log (default var/log/' . RequestProfile::LOG_BASENAME . ')');
        $this->addOption('kind', null, InputOption::VALUE_REQUIRED, 'middleware, timetracker, service or all', 'all');
        $this->addOption('min-ms', null, InputOption::VALUE_REQUIRED, 'Hide spans below this median own time', '0.05');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'table or json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string)($input->getOption('file') ?: Environment::getVarPath() . '/log/' . RequestProfile::LOG_BASENAME);
        if (!is_file($file)) {
            $io->error(sprintf(
                'No profile log at %s. Development context only: start the worker with %s=1, send requests, then run this again. After changing TYPO3_CONTEXT flush the caches, the DI container is compiled per context.',
                $file,
                RequestProfile::ENV_FLAG
            ));
            return Command::FAILURE;
        }
        $uri = (string)$input->getOption('uri');
        $report = ProfileReport::fromFile($file, $uri);
        if ($report->requestCount() === 0) {
            $io->warning(sprintf('No requests%s in %s.', $uri !== '' ? ' matching "' . $uri . '"' : '', $file));
            return Command::FAILURE;
        }
        $rows = $report->rows((string)$input->getOption('kind'), (float)$input->getOption('min-ms'));
        $summary = $report->summary();

        if ($input->getOption('format') === 'json') {
            $output->writeln((string)json_encode(['requests' => $report->requestCount(), 'summary' => $summary, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $io->title(sprintf(
            '%d requests%s, median total %.3f ms',
            $report->requestCount(),
            $uri !== '' ? ' matching "' . $uri . '"' : '',
            $summary['total_ms']
        ));
        $io->table(
            ['kind', 'span', 'own ms', 'incl ms', 'share', 'calls', 'db ms', 'db calls', 'in requests'],
            array_map(static fn(array $row): array => [
                $row['kind'],
                $row['name'],
                sprintf('%.3f', $row['own_ms']),
                sprintf('%.3f', $row['inclusive_ms']),
                sprintf('%.1f%%', $row['share']),
                sprintf('%.0f', $row['calls']),
                sprintf('%.3f', $row['db_ms']),
                sprintf('%.0f', $row['db_calls']),
                sprintf('%d/%d', $row['requests'], $report->requestCount()),
            ], $rows)
        );
        $io->section('Summary (medians of per-request values)');
        $io->table(['part', 'ms', 'share'], [
            ['reset before the request', sprintf('%.3f', $summary['reset_ms']), $this->share($summary['reset_ms'], $summary['total_ms'])],
            ['middleware stack, own time', sprintf('%.3f', $summary['middleware_own_ms']), $this->share($summary['middleware_own_ms'], $summary['total_ms'])],
            ['  of which TimeTracker spans', sprintf('%.3f', $summary['timetracker_own_ms']), $this->share($summary['timetracker_own_ms'], $summary['total_ms'])],
            ['  of which database', sprintf('%.3f (%.0f queries)', $summary['db_ms'], $summary['db_calls']), $this->share($summary['db_ms'], $summary['total_ms'])],
            ['rest (dispatcher, response emit)', sprintf('%.3f', $summary['rest_ms']), $this->share($summary['rest_ms'], $summary['total_ms'])],
            ['reset after the response (not in total)', sprintf('%.3f', $summary['post_reset_ms']), ''],
        ]);
        return Command::SUCCESS;
    }

    private function share(float $part, float $total): string
    {
        return $total > 0 ? sprintf('%.1f%%', $part * 100 / $total) : '';
    }
}
