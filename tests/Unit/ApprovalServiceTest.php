<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Db\Approval;
use OCA\KanzleiCheck\Db\ApprovalMapper;
use OCA\KanzleiCheck\Exception\ApiException;
use OCA\KanzleiCheck\Exception\NotFoundException;
use OCA\KanzleiCheck\Exception\ValidationException;
use OCA\KanzleiCheck\Service\ActorResolver;
use OCA\KanzleiCheck\Service\ApprovalService;
use OCA\KanzleiCheck\Service\AuditService;
use OCA\KanzleiCheck\Service\DocumentService;
use OCA\KanzleiCheck\Service\OtpService;
use OCP\Files\File;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * ApprovalService — code-confirmed approvals. The security core: binding
 * to sha256+etag at request, void-on-delete/change, purpose-bound OTP
 * verified INSIDE the transaction after a final re-hash (file-swap race),
 * required-approver uniform-404, idempotent early-outs.
 */
class ApprovalServiceTest extends TestCase
{
	private ApprovalMapper&MockObject $approvals;
	private OtpService&MockObject $otp;
	private AuditService&MockObject $audit;
	private DocumentService&MockObject $docs;
	private IDBConnection&MockObject $db;
	private ApprovalService $svc;

	protected function setUp(): void
	{
		$this->approvals = $this->createMock(ApprovalMapper::class);
		$this->otp = $this->createMock(OtpService::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->docs = $this->createMock(DocumentService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->svc = new ApprovalService(
			$this->approvals, $this->otp, $this->audit, $this->docs, $this->db,
		);
	}

	private function file(string $etag = 'e1', string $name = 'doc.pdf'): File&MockObject
	{
		$f = $this->createMock(File::class);
		$f->method('getEtag')->willReturn($etag);
		$f->method('getName')->willReturn($name);
		return $f;
	}

	private function pending(int|string $orgId = 5, int $fileId = 11, string $sha = 'sha-a', string $etag = 'e1'): Approval
	{
		$a = new Approval();
		$a->setId(3);
		$a->setOrgId($orgId);
		$a->setFileId($fileId);
		$a->setFileSha256($sha);
		$a->setFileEtag($etag);
		$a->setState('pending');
		return $a;
	}

	// ── mustGetInOrg — the IDOR guard ──

	public function testMustGetInOrgUniform404(): void
	{
		$this->approvals->method('find')->willReturnMap([
			[1, null],
			[2, $this->pending(5)],
		]);
		try {
			$this->svc->mustGetInOrg(1, 5);
			$this->fail('expected');
		} catch (NotFoundException) {
		}
		// approval exists but belongs to org 9 → same uniform 404, no oracle
		try {
			$this->svc->mustGetInOrg(2, 9);
			$this->fail('expected');
		} catch (NotFoundException) {
		}
		$this->assertInstanceOf(Approval::class, $this->svc->mustGetInOrg(2, 5));
		// stringly-typed row: the (int) cast on orgId is load-bearing
		$stringy = $this->pending('5');
		$approvals2 = $this->createMock(ApprovalMapper::class);
		$approvals2->method('find')->willReturn($stringy);
		$svc2 = new ApprovalService($approvals2, $this->otp, $this->audit, $this->docs, $this->db);
		$this->assertInstanceOf(Approval::class, $svc2->mustGetInOrg(2, 5));
	}

	// ── request ──

	public function testRequestBindsCurrentHashAndEtag(): void
	{
		$node = $this->file('etag-9', str_repeat('n', 300) . '.pdf');
		$this->docs->method('documentNodeForOrg')->with(5, 11)->willReturn($node);
		$this->docs->method('hashFile')->with($node)->willReturn('sha-live');
		$captured = null;
		$this->approvals->method('insert')->willReturnCallback(function (Approval $a) use (&$captured) {
			$a->setId(9);
			$captured = $a;
			return $a;
		});
		$this->audit->expects($this->exactly(3))->method('log')->with('staff', 'approval.request', 'approval', 9);
		$out = $this->svc->request('staff', 5, 11, '2026-12-31', 'please sign', 44);
		$this->assertSame('sha-live', $captured->getFileSha256());
		$this->assertSame('etag-9', $captured->getFileEtag());
		$this->assertSame(5, $captured->getOrgId());  // setter executed
		$this->assertSame(11, $captured->getFileId());
		$this->assertSame(255, mb_strlen($captured->getFileName())); // truncated
		$this->assertTrue(str_starts_with($captured->getFileName(), 'n')); // offset 0
		$this->assertSame(44, $captured->getRequiredIdentityId());
		$this->assertSame('2026-12-31', $captured->getDeadline()->format('Y-m-d'));
		$this->assertSame('00:00:00', $captured->getDeadline()->format('H:i:s')); // '!' resets time
		$this->assertSame('please sign', $captured->getNote());
		$this->assertSame('pending', $captured->getState());
		$this->assertSame('staff', $captured->getRequestedBy());
		$this->assertNotNull($captured->getRequestedAt());
		// invalid/missing deadline → null, never a crash
		$out2 = $this->svc->request('staff', 5, 11, 'not-a-date', str_repeat('x', 2000), null);
		$this->assertNull($out2->getDeadline());
		$this->assertSame(1000, mb_strlen($out2->getNote()));
		// '' deadline → null
		$out3 = $this->svc->request('staff', 5, 11, '', null, null);
		$this->assertNull($out3->getDeadline());
		$this->assertNull($out3->getNote());
	}

	// ── checkVoid ──

	public function testCheckVoidNonPendingShortCircuits(): void
	{
		$a = $this->pending();
		$a->setState('approved');
		$this->docs->expects($this->never())->method('findNodeById');
		$this->assertSame('ok', $this->svc->checkVoid($a)); // decided rows never re-void
	}

	public function testCheckVoidMissingAndNonFileNode(): void
	{
		$this->docs->method('findNodeById')->willReturn(null);
		$this->approvals->expects($this->exactly(2))->method('update');
		$this->audit->expects($this->exactly(2))->method('log')
			->with(ActorResolver::system('scheduler'), 'approval.void', 'approval', 3, ['reason' => 'deleted']);
		$a = $this->pending();
		$this->assertSame('void', $this->svc->checkVoid($a));
		$this->assertSame('void', $a->getState());
		$this->assertSame('deleted', $a->getReason());

		// node resolves but isn't a File (folder at that id) → also 'deleted'
		$docs2 = $this->createMock(DocumentService::class);
		$docs2->method('findNodeById')->willReturn($this->createMock(\OCP\Files\Folder::class));
		$svc2 = new ApprovalService($this->approvals, $this->otp, $this->audit, $docs2, $this->db);
		$a2 = $this->pending();
		$this->assertSame('void', $svc2->checkVoid($a2));
		$this->assertSame('deleted', $a2->getReason());
	}

	public function testCheckVoidEtagMovedRehashDecides(): void
	{
		// etag moved + same sha → rebind etag, stays ok (move/rename is benign)
		$a = $this->pending(5, 11, 'sha-a', 'e1');
		$node = $this->file('e2');
		$this->docs->method('findNodeById')->willReturn($node);
		$this->docs->method('hashFile')->willReturn('sha-a');
		$this->approvals->expects($this->once())->method('update')->with($a);
		$this->assertSame('ok', $this->svc->checkVoid($a));
		$this->assertSame('e2', $a->getFileEtag()); // re-anchored
		$this->assertSame('pending', $a->getState());

		// etag moved + different sha → void 'changed'
		$a2 = $this->pending(5, 11, 'sha-a', 'e1');
		$approvals2 = $this->createMock(ApprovalMapper::class);
		$approvals2->expects($this->once())->method('update')->with($a2);
		$docs2 = $this->createMock(DocumentService::class);
		$docs2->method('findNodeById')->willReturn($this->file('e2'));
		$docs2->method('hashFile')->willReturn('sha-b');
		$svc2 = new ApprovalService($approvals2, $this->otp, $this->audit, $docs2, $this->db);
		$this->assertSame('void', $svc2->checkVoid($a2));
		$this->assertSame('changed', $a2->getReason());
	}

	// ── start ──

	public function testStartIssuesPurposeBoundCodeWithoutMagic(): void
	{
		$this->otp->expects($this->once())->method('issue')
			->with('c@x.de', 'approve:7', '1.2.3.4', false)
			->willReturn(['123456', null, 55]);
		$out = $this->svc->start(7, 'c@x.de', '1.2.3.4');
		$this->assertSame(['code' => '123456', 'otpId' => 55], $out);
	}

	// ── decide ──

	public function testDecideInvalidDecisionRejectedBeforeTxn(): void
	{
		$this->db->expects($this->never())->method('beginTransaction');
		try {
			$this->svc->decide('u', 'e@x.de', 3, 5, 'maybe', null, null);
			$this->fail('expected');
		} catch (ValidationException $e) {
			$this->assertSame(['decision' => 'invalid'], $e->getFields());
		}
	}

	public function testDecideNotPendingAndRequiredApprover(): void
	{
		$approved = $this->pending();
		$approved->setState('approved');
		$this->approvals->method('find')->willReturn($approved);
		$this->db->expects($this->once())->method('rollBack');
		try {
			$this->svc->decide('u', 'e@x.de', 3, 5, 'reject', null, 'why');
			$this->fail('expected');
		} catch (ApiException $e) {
			$this->assertSame(409, $e->getStatus());
			$this->assertSame('APPROVAL_VOID', $e->getErrorCode());
		}

		// required approver: wrong identity → uniform 404 (no existence oracle)
		$a2 = $this->pending();
		$a2->setRequiredIdentityId(77);
		$approvals2 = $this->createMock(ApprovalMapper::class);
		$approvals2->method('find')->willReturn($a2);
		$svc2 = new ApprovalService($approvals2, $this->otp, $this->audit, $this->docs, $this->createMock(IDBConnection::class));
		$this->expectException(NotFoundException::class);
		$svc2->decide(ActorResolver::portal(78), 'e@x.de', 3, 5, 'reject', null, 'why');
	}

	public function testDecideRequiredApproverMatchProceeds(): void
	{
		$a = $this->pending();
		$a->setRequiredIdentityId(77);
		$this->approvals->method('find')->willReturn($a);
		$this->docs->method('findNodeById')->willReturn($this->file('e1'));
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$out = $this->svc->decide(ActorResolver::portal(77), 'e@x.de', 3, 5, 'reject', null, 'no thanks');
		$this->assertSame('rejected', $out->getState());
		$this->assertSame(ActorResolver::portal(77), $out->getDecidedBy());
	}

	public function testDecideRejectRequiresReason(): void
	{
		$this->approvals->method('find')->willReturnCallback(fn () => $this->pending()); // fresh row per call
		$this->docs->method('findNodeById')->willReturn($this->file('e1'));
		foreach (['', '   ', str_repeat('x', 1001)] as $bad) {
			try {
				$this->svc->decide('u', 'e@x.de', 3, 5, 'reject', null, $bad);
				$this->fail('expected reason');
			} catch (ValidationException $e) {
				$this->assertSame(['reason' => 'required'], $e->getFields());
			}
		}
		// boundary: exactly 1000 chars is legal — stored trimmed
		$out = $this->svc->decide('u', 'e@x.de', 3, 5, 'reject', null, ' ' . str_repeat('y', 998) . ' ');
		$this->assertSame(str_repeat('y', 998), $out->getReason());
		$out2 = $this->svc->decide('u', 'e@x.de', 3, 5, 'reject', null, str_repeat('z', 1000));
		$this->assertSame(1000, mb_strlen($out2->getReason()));
	}

	public function testDecideApproveFlow(): void
	{
		$a = $this->pending();
		$this->approvals->method('find')->willReturn($a);
		$this->docs->method('findNodeById')->willReturn($this->file('e1'));
		$this->docs->method('hashFile')->willReturn('sha-a'); // unchanged inside txn
		$this->otp->method('verify')->with('e@x.de', 'approve:3', '123456')->willReturn(55);
		$this->approvals->expects($this->once())->method('update')->with($a);
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');
		$this->audit->expects($this->once())->method('log')->with('u', 'approval.approve', 'approval', 3);
		$out = $this->svc->decide('u', 'e@x.de', 3, 5, 'approve', '123456', null);
		$this->assertSame('approved', $out->getState());
		$this->assertSame('email_code', $out->getConfirmMethod());
		$this->assertSame(55, $out->getOtpId());
		$this->assertSame('u', $out->getDecidedBy());
		$this->assertNotNull($out->getDecidedAt());
	}

	public function testDecideApproveRequiresCodeAndValidOtp(): void
	{
		$this->approvals->method('find')->willReturn($this->pending());
		$this->docs->method('findNodeById')->willReturn($this->file('e1'));
		foreach ([null, ''] as $bad) {
			try {
				$this->svc->decide('u', 'e@x.de', 3, 5, 'approve', $bad, null);
				$this->fail('expected code');
			} catch (ValidationException $e) {
				$this->assertSame(['code' => 'required'], $e->getFields());
			}
		}
		// wrong/expired code → 400 INVALID_CODE, txn rolled back
		$this->otp->method('verify')->willReturn(null);
		try {
			$this->svc->decide('u', 'e@x.de', 3, 5, 'approve', '999999', null);
			$this->fail('expected');
		} catch (ApiException $e) {
			$this->assertSame(400, $e->getStatus());
			$this->assertSame('INVALID_CODE', $e->getErrorCode());
		}
	}

	public function testDecideApproveFileSwappedInsideTxn(): void
	{
		// checkVoid passes (etag same), OTP verifies, then the FINAL hash
		// compare catches a swap that happened mid-flow → void + 410
		$a = $this->pending();
		$this->approvals->method('find')->willReturn($a);
		$this->docs->method('findNodeById')->willReturn($this->file('e1'));
		$this->docs->method('hashFile')->willReturn('sha-EVIL'); // differs from bound sha-a
		$this->otp->method('verify')->willReturn(55);
		try {
			$this->svc->decide('u', 'e@x.de', 3, 5, 'approve', '123456', null);
			$this->fail('expected');
		} catch (ApiException $e) {
			$this->assertSame(410, $e->getStatus());
			$this->assertSame('APPROVAL_VOID', $e->getErrorCode());
		}
		$this->assertSame('void', $a->getState());
		$this->assertSame('changed', $a->getReason());
	}

	public function testDecideVoidedInsideTxn(): void
	{
		// file deleted between page load and decide → checkVoid fires → 410
		$a = $this->pending();
		$this->approvals->method('find')->willReturn($a);
		$this->docs->method('findNodeById')->willReturn(null);
		try {
			$this->svc->decide('u', 'e@x.de', 3, 5, 'approve', '123456', null);
			$this->fail('expected');
		} catch (ApiException $e) {
			$this->assertSame(410, $e->getStatus());
		}
		$this->assertSame('void', $a->getState());
		$this->assertSame('deleted', $a->getReason());
	}

	public function testDecideUnexpectedErrorRollsBack(): void
	{
		$this->approvals->method('find')->willThrowException(new \RuntimeException('db gone'));
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->expectException(\RuntimeException::class);
		$this->svc->decide('u', 'e@x.de', 3, 5, 'approve', '1', null);
	}

	// ── withdraw ──

	public function testWithdraw(): void
	{
		$this->approvals->method('find')->willReturnMap([
			[1, null],
			[2, $this->pending()],
			[3, (function () {
				$a = $this->pending();
				$a->setState('approved');
				return $a;
			})()],
		]);
		try {
			$this->svc->withdraw('staff', 1);
			$this->fail('expected');
		} catch (NotFoundException) {
		}
		try {
			$this->svc->withdraw('staff', 3);
			$this->fail('expected');
		} catch (ApiException $e) {
			$this->assertSame(409, $e->getStatus());
		}
		$this->audit->expects($this->once())->method('log')->with('staff', 'approval.withdraw', 'approval', 2);
		$out = $this->svc->withdraw('staff', 2);
		$this->assertSame('withdrawn', $out->getState());
	}
}
