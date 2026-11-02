<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Service;

use OCP\App\IAppManager;
use OCP\IDBConnection;

/**
 * Sibling integrations — facade-only, read-only, fail-safe (NN-P05).
 * InvoiceCheck: invoice list + PDF link via its service if the app is
 * enabled; BookingCheck: org booking-URL display (already validated
 * same-instance /pub/o/{slug} by OrgService::validateBookingUrl).
 * Any failure → empty list / hidden panel, never a route-breaking error.
 */
class IntegrationService
{
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IDBConnection $db,
	) {
	}

	public function invoiceAvailable(): bool
	{
		try {
			return $this->appManager->isEnabledForUser('invoicecheck');
		} catch (\Throwable) {
			return false;
		}
	}

	/** Read-only invoice list for an org's linked customer (pc_cust_id). */
	public function invoicesForOrg(int $pcCustId): array
	{
		if ($pcCustId <= 0 || !$this->invoiceAvailable()) {
			return [];
		}
		try {
			// read the sibling's own table read-only — never write (NN-P05)
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'invoice_no', 'amount_minor', 'currency', 'state', 'issued_at', 'due_at')
				->from('iv_invoice')
				->where($qb->expr()->eq('cust_id', $qb->createNamedParameter($pcCustId)))
				->orderBy('issued_at', 'DESC')
				->setMaxResults(50);
			return $qb->executeQuery()->fetchAll() ?: [];
		} catch (\Throwable) {
			return []; // sibling table shape differs or app absent → hide panel
		}
	}

	public function bookingUrlFor(?\OCA\PortalCheck\Db\Org $org): ?string
	{
		$url = $org?->getBookUrl();
		return ($url !== null && $url !== '') ? $url : null;
	}
}
