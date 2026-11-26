<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Db\UploadChunkMapper;
use OCA\KanzleiCheck\Exception\ApiException;
use OCA\KanzleiCheck\Service\ChunkUploadService;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Chunk ledger (regression): a re-sent chunk replaces its .part file but
 * must NOT double-count received_bytes — the old code bricked uploads on
 * any sequential retry (received > total → finish() 'incomplete' forever).
 * Disk is now the ledger authority.
 */
class ChunkLedgerTest extends TestCase
{
	private UploadChunkMapper&MockObject $chunks;
	private ISimpleFolder&MockObject $folder;
	/** @var array<string,int> part name → size the mock FS claims */
	private array $partSizes = [];
	private array $reconciled = [];
	private ChunkUploadService $svc;

	protected function setUp(): void
	{
		$this->chunks = $this->createMock(UploadChunkMapper::class);
		$this->folder = $this->createMock(ISimpleFolder::class);
		$this->partSizes = [];
		$this->reconciled = [];

		$this->folder->method('fileExists')
			->willReturnCallback(fn (string $n) => isset($this->partSizes[$n]));
		$this->folder->method('getFile')
			->willReturnCallback(function (string $n) {
				$f = $this->createMock(ISimpleFile::class);
				$f->method('getSize')->willReturn($this->partSizes[$n]);
				$f->method('read')->willReturnCallback(function () use ($n) {
					$mem = fopen('php://memory', 'r+');
					fwrite($mem, str_repeat("\0", $this->partSizes[$n]));
					rewind($mem);
					return $mem;
				});
				$f->method('delete')->willReturnCallback(function () use ($n) {
					unset($this->partSizes[$n]);
				});
				return $f;
			});
		$this->folder->method('newFile')
			->willReturnCallback(function (string $n) {
				$f = $this->createMock(ISimpleFile::class);
				$f->method('write')->willReturnCallback(function () use ($n) {
					// write() streams into a temp sink, then we size it from the copy
					$tmp = tmpfile();
					$this->partSizes[$n] = -1; // placeholder until copy completes
					return $tmp;
				});
				return $f;
			});

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($this->folder);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);

		$this->chunks->method('reconcileReceived')
			->willReturnCallback(function (string $id, int $bytes, int $count) {
				$this->reconciled[] = [$bytes, $count];
			});
		$this->svc = new ChunkUploadService($this->chunks, $factory, new NullLogger());
	}

	private function row(int $received, int $total, string $state = 'receiving'): array
	{
		return [
			'upload_id' => 'abc12345', 'org_id' => 7, 'actor' => 'u1',
			'state' => $state, 'received_bytes' => $received,
			'total_bytes' => $total, 'chunk_count' => 0,
		];
	}

	private function tmpOf(int $bytes): string
	{
		$t = tempnam(sys_get_temp_dir(), 'kzc_test_');
		file_put_contents($t, str_repeat("\0", $bytes));
		return $t;
	}

	public function testResentChunkNeverDoubleCounts(): void
	{
		$tmp = $this->tmpOf(100);
		$this->chunks->method('find')->willReturn($this->row(0, 100));

		$this->svc->putChunk('abc12345', 7, 'u1', 0, $tmp);
		// pretend the first write materialized a 100-byte part
		$this->partSizes['c_abc12345.part0'] = 100;

		// RETRY the same index (timeout retry — sequential, not concurrent)
		$this->svc->putChunk('abc12345', 7, 'u1', 0, $tmp);

		// ledger must read 100 both times, never 200
		$this->assertSame([[100, 1], [100, 1]], $this->reconciled);
	}

	public function testFinishAfterResendIsNotBricked(): void
	{
		// ledger inflated to 200 by the old bug, disk has the true 100 bytes
		$this->chunks->method('find')->willReturn($this->row(200, 100));
		$this->chunks->method('claimAssembly')->willReturn(true);
		$this->partSizes['c_abc12345.part0'] = 100;
		$this->chunks->expects($this->never())->method('setState');
		$out = $this->svc->finish('abc12345', 7, 'u1');
		$this->assertFileExists($out['tmp']);
		$this->assertSame(100, filesize($out['tmp']));
		@unlink($out['tmp']);
	}

	public function testFinishRejectsTrulyIncomplete(): void
	{
		$this->chunks->method('find')->willReturn($this->row(50, 100));
		$this->chunks->method('claimAssembly')->willReturn(true);
		$this->partSizes['c_abc12345.part0'] = 50;
		$this->chunks->expects($this->once())->method('setState')->with('abc12345', 'receiving');
		try {
			$this->svc->finish('abc12345', 7, 'u1');
			$this->fail('expected');
		} catch (ApiException $e) {
			$this->assertSame(400, $e->getStatus());
		}
	}

	public function testOverTotalFromDiskIs413NotLedgerGuess(): void
	{
		$this->chunks->method('find')->willReturn($this->row(80, 100));
		$this->partSizes['c_abc12345.part0'] = 80;
		try {
			$this->svc->putChunk('abc12345', 7, 'u1', 1, $this->tmpOf(30));
			$this->fail('expected');
		} catch (ApiException $e) {
			$this->assertSame(413, $e->getStatus());
		}
	}
}
