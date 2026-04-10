<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** @psalm-suppress UnusedClass */
final class CodeownersSuggest extends Command
{
    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<options=bold>CODEOWNERS Suggest</>');
        $output->writeln('');
        $output->writeln('Generates a CODEOWNERS file draft based on who actually committed to each module in the selected period.');
        $output->writeln('Each module is assigned to its dominant team — the team with the most commits to files under that path.');
        $output->writeln('Review the output carefully: a low dominance percentage (e.g. 45%) means ownership is contested and the suggestion may need discussion.');
        $output->writeln('');

        $teamsFile = $input->getOption('teams');
        if (null === $teamsFile) {
            $output->writeln('<error>The --teams option is required.</error>');

            return Command::FAILURE;
        }

        $repoPath = $input->getArgument('path');
        $depth = (int) $input->getOption('depth');
        $excludePatterns = $input->getOption('exclude');
        $filter = $input->getOption('filter');
        $githubOrg = $input->getOption('github-org');
        $outputFile = $input->getOption('output');

        $since = $input->getOption('since');
        $since = $since ? new \DateTimeImmutable($since) : new \DateTimeImmutable('-6 months');

        $until = $input->getOption('until');
        $until = $until ? new \DateTimeImmutable($until) : new \DateTimeImmutable();

        $teamConfig = TeamConfig::fromFile($teamsFile);
        $git = new Git($repoPath);
        $analyzer = new TerritoryMapAnalyzer($git, $teamConfig);

        $result = $analyzer->analyze($depth, $since, $until, $excludePatterns, $filter);

        if ([] === $result->modules) {
            $output->writeln('No modules found.');

            return Command::SUCCESS;
        }

        // Sort modules by path for a clean CODEOWNERS file
        $modules = $result->modules;
        usort($modules, static fn (TerritoryMapModule $a, TerritoryMapModule $b): int => strcmp($a->name, $b->name));

        // Preview table
        $table = new Table($output);
        $table->setHeaders(['Module', 'Dominant Team', 'Dominance', 'Entropy', 'Commits']);
        foreach ($modules as $module) {
            $dominance = (int) $module->dominantTeamPercentage;
            $warning = $dominance < 50 ? ' <comment>⚠</comment>' : '';
            $table->addRow([
                $module->name.'/',
                $module->dominantTeam,
                $dominance.'%'.$warning,
                number_format($module->ownershipEntropy, 2),
                $module->totalCommits,
            ]);
        }
        $table->render();
        $output->writeln('');
        $output->writeln('<comment>⚠ = dominance below 50%, ownership is contested — review before committing</comment>');
        $output->writeln('');

        $content = $this->buildCodeownersContent($modules, $githubOrg, $since, $until);

        if (null !== $outputFile) {
            file_put_contents($outputFile, $content);
            $output->writeln(\sprintf('Written: <info>%s</info>', $outputFile));
        } else {
            $output->writeln('<options=bold>Generated CODEOWNERS</>');
            $output->writeln('');
            $output->writeln($content);
        }

        return Command::SUCCESS;
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('codeowners:suggest')
            ->setDescription('Generate a CODEOWNERS draft from recent commit history')
            ->addArgument('path', InputArgument::REQUIRED, 'Path to the git repository')
            ->addOption('teams', 'T', InputOption::VALUE_REQUIRED, 'Path to teams JSON config file')
            ->addOption('depth', 'd', InputOption::VALUE_OPTIONAL, 'Number of path segments that define a module', 2)
            ->addOption('filter', 'f', InputOption::VALUE_OPTIONAL, 'Only include files matching this path prefix (e.g. src/)', default: null)
            ->addOption('since', 's', InputOption::VALUE_OPTIONAL, 'Start of the commit window (default: 6 months ago)', null)
            ->addOption('until', 'u', InputOption::VALUE_OPTIONAL, 'End of the commit window (default: today)', null)
            ->addOption('exclude', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, default: [])
            ->addOption('github-org', null, InputOption::VALUE_OPTIONAL, 'GitHub org slug — formats owners as @org/team-name', null)
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Write CODEOWNERS to this file instead of stdout', null);
    }

    /**
     * @param TerritoryMapModule[] $modules already sorted by path
     */
    private function buildCodeownersContent(
        array $modules,
        ?string $githubOrg,
        \DateTimeImmutable $since,
        \DateTimeImmutable $until,
    ): string {
        $lines = [];
        $lines[] = '# Generated by dx-metrics codeowners:suggest';
        $lines[] = \sprintf('# Dominant team per module based on commits from %s to %s', $since->format('Y-m-d'), $until->format('Y-m-d'));
        $lines[] = '# Review and adjust team slugs before committing.';
        $lines[] = '# Lines marked with (!) have dominance below 50% — ownership is contested.';
        $lines[] = '';

        foreach ($modules as $module) {
            $slug = $this->teamSlug($module->dominantTeam, $githubOrg);
            $dominance = (int) $module->dominantTeamPercentage;
            $warn = $dominance < 50 ? ' (!)' : '';
            $lines[] = \sprintf('%-44s %s  # %d%%%s', $module->name.'/', $slug, $dominance, $warn);
        }

        return implode("\n", $lines)."\n";
    }

    private function teamSlug(string $teamName, ?string $githubOrg): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $teamName) ?? $teamName);

        return null !== $githubOrg
            ? \sprintf('@%s/%s', $githubOrg, $slug)
            : \sprintf('@%s', $slug);
    }
}
