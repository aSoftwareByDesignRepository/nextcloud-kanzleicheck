<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Command;

use OCA\PortalCheck\Service\IdentityService;
use OCA\PortalCheck\Service\ImportService;
use OCA\PortalCheck\Service\OrgService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** occ portalcheck:import-clients <file.csv> [--dry-run] [--send-invites] — US-P06. */
class ImportClientsCommand extends Command
{
	public function __construct(
		private readonly OrgService $orgs,
		private readonly IdentityService $identities,
		private readonly ImportService $import,
		private readonly \OCA\PortalCheck\Service\MailOutboxService $outbox,
		private readonly \OCP\IURLGenerator $urls,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('portalcheck:import-clients')
			->setDescription('Import client orgs and contact e-mails from CSV (org_name, org_ref, email, display_name)')
			->addArgument('file', InputArgument::REQUIRED, 'CSV file path')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'plan only, no writes')
			->addOption('send-invites', null, InputOption::VALUE_NONE, 'queue invitation mails for new identities');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$file = (string)$input->getArgument('file');
		if (!is_file($file)) {
			$output->writeln('<error>file not found: ' . $file . '</error>');
			return 1;
		}
		$csv = file_get_contents($file);
		if ($csv === false) {
			$output->writeln('<error>cannot read file</error>');
			return 1;
		}
		try {
			$rows = ImportService::parse($csv);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		if ($input->getOption('dry-run')) {
			$plan = $this->import->plan($rows, $this->orgs, $this->identities);
			foreach ($plan as $p) {
				$output->writeln(sprintf('line %d: %s → %s', $p['line'], $p['email'], $p['action']));
			}
			$output->writeln('<info>' . count($plan) . ' row(s), dry run — no writes.</info>');
			return 0;
		}
		$applied = 0;
		$invites = 0;
		foreach (array_chunk($rows, 100) as $batch) {
			foreach ($batch as $r) {
				$orgName = trim((string)($r['org_name'] ?? ''));
				$email = IdentityService::normalizeEmail((string)($r['email'] ?? ''));
				if ($orgName === '' || !IdentityService::isValidEmail($email)) {
					continue;
				}
				$ref = trim((string)($r['org_ref'] ?? ''));
				$org = $ref !== '' ? $this->orgs->findByRefPublic($ref) : null;
				if ($org === null) {
					$org = $this->orgs->create('system:import', $orgName, $ref !== '' ? $ref : null);
				}
				$isNew = $this->identities->activeByEmail($email) === null;
				$identity = $this->identities->upsert($email, (string)($r['display_name'] ?? $email));
				$this->identities->addMembership((int)$identity->getId(), (int)$org->getId(), 'system:import');
				if ($isNew && $input->getOption('send-invites')) {
					$this->outbox->enqueue($email, 'invite', 'invite:' . $email,
						['portalUrl' => $this->urls->linkToRouteAbsolute('portalcheck.portal.index')], 'de');
					$invites++;
				}
				$applied++;
			}
		}
		$output->writeln('<info>' . $applied . ' row(s) applied' . ($invites > 0 ? ', ' . $invites . ' invitation(s) queued' : '') . '.</info>');
		return 0;
	}
}
