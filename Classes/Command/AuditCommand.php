<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Command;

use Ochorocho\FrankenPhp\DependencyInjection\ClassStateAnalyzer;
use Ochorocho\FrankenPhp\DependencyInjection\WorkerKeepListPass;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Container;

#[AsCommand(
    name: 'frankenphp:audit',
    description: 'List which DI services survive a worker request and which are discarded, with reasons',
)]
final class AuditCommand extends Command
{
    private const string FORMAT_TABLE = 'table';
    private const string FORMAT_JSON = 'json';
    private const string FORMAT_MARKDOWN = 'markdown';

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ClassStateAnalyzer $analyzer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'table, json or markdown', self::FORMAT_TABLE);
        $this->addOption('filter', null, InputOption::VALUE_REQUIRED, 'Only show services whose id or class contains this string');
        $this->addOption('category', null, InputOption::VALUE_REQUIRED, 'Only show "keep" or "discard" entries');
        $this->addOption('summary', null, InputOption::VALUE_NONE, 'Only print the summary and the top demotion causes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->container instanceof Container || !$this->container->hasParameter(WorkerKeepListPass::PARAMETER_REPORT)) {
            $io->error('No worker classification found in the container. Flush the DI cache (`typo3 cache:flush`) so WorkerKeepListPass runs.');
            return Command::FAILURE;
        }
        /** @var array<string, array{category: string, reason: string, class?: string}> $report */
        $report = $this->container->getParameter(WorkerKeepListPass::PARAMETER_REPORT);

        $filter = $input->getOption('filter');
        $category = $input->getOption('category');
        $entries = array_filter(
            $report,
            static function (array $entry, string $id) use ($filter, $category): bool {
                if (is_string($category) && $category !== '' && $entry['category'] !== $category) {
                    return false;
                }
                if (is_string($filter) && $filter !== '') {
                    return str_contains($id, $filter) || str_contains($entry['class'] ?? '', $filter);
                }
                return true;
            },
            ARRAY_FILTER_USE_BOTH
        );

        $format = (string)$input->getOption('format');
        foreach ($entries as $id => $entry) {
            $entries[$id]['properties'] = $this->analyzer->describeProperties($entry['class'] ?? $id);
        }
        if ($format === self::FORMAT_JSON) {
            $output->writeln((string)json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $this->printSummary($io, $report);
        $this->printUnmatched($io);
        $this->printDemotionCauses($io, $report);
        if ((bool)$input->getOption('summary')) {
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($entries as $id => $entry) {
            $rows[] = [$entry['category'], $id, $entry['reason'], $entry['properties']];
        }
        usort($rows, static fn(array $a, array $b): int => [$a[0], $a[2], $a[1]] <=> [$b[0], $b[2], $b[1]]);

        $headers = ['category', 'service id', 'reason', 'properties'];
        if ($format === self::FORMAT_MARKDOWN) {
            $output->writeln('| ' . implode(' | ', $headers) . ' |');
            $output->writeln('|' . str_repeat(' --- |', count($headers)));
            foreach ($rows as $row) {
                $output->writeln('| ' . implode(' | ', array_map(static fn(string $cell): string => str_replace('|', '\|', $cell), $row)) . ' |');
            }
            return Command::SUCCESS;
        }

        $io->table($headers, $rows);
        return Command::SUCCESS;
    }

    /**
     * @param array<string, array{category: string, reason: string, class?: string}> $report
     */
    private function printSummary(SymfonyStyle $io, array $report): void
    {
        $counts = [];
        foreach ($report as $entry) {
            $group = $entry['category'] . ' / ' . $this->reasonGroup($entry['reason']);
            $counts[$group] = ($counts[$group] ?? 0) + 1;
        }
        ksort($counts);
        $rows = [];
        foreach ($counts as $group => $count) {
            $rows[] = [$group, (string)$count];
        }
        $rows[] = ['total shared services', (string)count($report)];
        $io->section('Summary');
        $io->table(['group', 'count'], $rows);
    }

    private function printUnmatched(SymfonyStyle $io): void
    {
        if (!$this->container instanceof Container || !$this->container->hasParameter(WorkerKeepListPass::PARAMETER_UNMATCHED)) {
            return;
        }
        /** @var list<array{list: string, name: string, origin: string}> $unmatched */
        $unmatched = $this->container->getParameter(WorkerKeepListPass::PARAMETER_UNMATCHED);
        if ($unmatched === []) {
            return;
        }
        $io->warning(sprintf(
            '%d FrankenPhpWorker.php entr%s matched no shared service (typo, or the package is not installed):',
            count($unmatched),
            count($unmatched) === 1 ? 'y' : 'ies'
        ));
        $rows = [];
        foreach ($unmatched as $entry) {
            $rows[] = [$entry['origin'], $entry['list'], $entry['name']];
        }
        $io->table(['origin', 'list', 'name'], $rows);
    }

    /**
     * @param array<string, array{category: string, reason: string, class?: string}> $report
     */
    private function printDemotionCauses(SymfonyStyle $io, array $report): void
    {
        $causes = [];
        foreach ($report as $entry) {
            if (preg_match('/^demoted-via:(\S+) \((.+)\)$/', $entry['reason'], $matches) === 1) {
                $key = $matches[1] . ' (' . $matches[2] . ')';
                $causes[$key] = ($causes[$key] ?? 0) + 1;
            }
        }
        if ($causes === []) {
            return;
        }
        arsort($causes);
        $rows = [];
        foreach (array_slice($causes, 0, 25, true) as $cause => $count) {
            $rows[] = [$cause, (string)$count];
        }
        $io->section('Top demotion causes (dependency that pulled kept services into discard)');
        $io->table(['dependency', 'demoted services'], $rows);
    }

    private function reasonGroup(string $reason): string
    {
        $group = explode(':', $reason, 2)[0];
        return $group === 'pinned-via' ? 'pinned' : $group;
    }
}
