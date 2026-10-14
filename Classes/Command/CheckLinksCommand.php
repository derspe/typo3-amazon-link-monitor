<?php

declare(strict_types=1);

namespace Derspe\AmazonLinkMonitor\Command;

use Derspe\AmazonLinkMonitor\Service\CrawlService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Starts the link check for all sites (or one) and processes it until done or the
 * time limit is reached. Unfinished checks continue with the next run.
 */
#[AsCommand(
    name: 'amazon-link-monitor:check',
    description: 'Crawl the sitemap and check all Amazon links (continues an unfinished check).'
)]
final class CheckLinksCommand extends Command
{
    public function __construct(
        private readonly CrawlService $crawl,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('site', null, InputOption::VALUE_REQUIRED, 'Only this site identifier')
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Maximum runtime in seconds', '240')
            ->addOption('continue-only', null, InputOption::VALUE_NONE, 'Only continue running checks, do not start new ones');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deadline = microtime(true) + max(5, (int)$input->getOption('time-limit'));
        $onlySite = (string)$input->getOption('site');
        foreach ($this->siteFinder->getAllSites() as $site) {
            $identifier = $site->getIdentifier();
            if ($onlySite !== '' && $onlySite !== $identifier) {
                continue;
            }
            $active = $this->crawl->findActive($identifier);
            if ($active === null && $input->getOption('continue-only')) {
                continue;
            }
            $id = $active !== null ? (int)$active['uid'] : $this->crawl->start($identifier);
            while (($remaining = $deadline - microtime(true)) > 2) {
                $job = $this->crawl->step($identifier, $id, $remaining);
                if ($job === null) {
                    $output->writeln(sprintf('%s: check is processed by another process', $identifier));
                    break;
                }
                $dto = CrawlService::toDto($job);
                if ($dto['status'] !== 'running' && $dto['status'] !== 'pending') {
                    $output->writeln(sprintf('%s: %s %s', $identifier, $dto['status'], json_encode($dto['progress']['args'] ?? $dto['error'] ?? '')));
                    break;
                }
                $output->writeln(sprintf('%s: %s %d/%d', $identifier, $dto['phase'], $dto['done'], $dto['total']), OutputInterface::VERBOSITY_VERBOSE);
            }
        }
        return Command::SUCCESS;
    }
}
