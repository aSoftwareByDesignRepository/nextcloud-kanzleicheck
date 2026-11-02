<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Service;

use OCP\App\IAppManager;

/**
 * Sibling integrations — facade-only, read-only, fail-safe (NN-P05/NN-P21).
 *
 * InvoiceCheck is consumed exclusively through its owned facade
 * `OCA\InvoiceCheck\Facade\CrmInvoiceReadFacade::listByPcCustomer()`
 * (FC-IC-READ, the only sanctioned sibling import per G-18). The class is
 * resolved lazily from the sibling's own DI container — never by touching
 * its tables. BookingCheck integration is a validated same-instance URL
 * (OrgService::validateBookingUrl), no code import at all.
 *
 * Any failure → empty list / hidden panel, never a route-breaking error.
 */
class IntegrationService
{
	private const FACADE = 'OCA\\InvoiceCheck\\Facade\\CrmInvoiceReadFacade';

	public function __construct(
		private readonly IAppManager $appManager,
	) {
	}

	public function invoiceAvailable(): bool
	{
		try {
			return $this->appManager->isEnabledForUser('invoicecheck')
				&& class_exists(self::FACADE);
		} catch (\Throwable) {
			return false;
		}
	}

	private function invoiceFacade(): ?object
	{
		if (!$this->invoiceAvailable()) {
			return null;
		}
		try {
			// siblings expose read-facades via registerPublicService (family pattern)
			return \OCP\Server::get(self::FACADE);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Read-only invoice list for an org's linked customer (pc_cust_id).
	 * @return list<array{id:int,ref:?string,status:string,issuedAt:?string,dueAt:?string,totalMinor:int,currency:string,overdue:bool}>
	 */
	public function invoicesForOrg(int $pcCustId): array
	{
		$facade = $this->invoiceFacade();
		if ($pcCustId <= 0 || $facade === null) {
			return [];
		}
		try {
			$rows = $facade->listByPcCustomer($pcCustId, 50);
			return array_values(array_map(static function (array $r): array {
				return [
					'id' => (int)($r['icInvoiceId'] ?? $r['id'] ?? 0),
					'ref' => isset($r['invoiceRef']) ? (string)$r['invoiceRef'] : null,
					'status' => (string)($r['status'] ?? 'open'),
					'issuedAt' => isset($r['issueDate']) ? (string)$r['issueDate'] : null,
					'dueAt' => isset($r['dueDate']) ? (string)$r['dueDate'] : null,
					'totalMinor' => (int)($r['grossTotalCents'] ?? 0),
					'currency' => 'EUR',
					'overdue' => !empty($r['overdue']),
				];
			}, is_array($rows) ? $rows : []));
		} catch (\Throwable) {
			return []; // facade threw → degrade silently per contract
		}
	}

	public function bookingUrlFor(?\OCA\PortalCheck\Db\Org $org): ?string
	{
		$url = $org?->getBookUrl();
		return ($url !== null && $url !== '') ? $url : null;
	}
}
