<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Service\ChunkUploadService;
use PHPUnit\Framework\TestCase;

/** Chunk protocol invariants: caps, id grammar, bounds (AF-17). */
class ChunkBoundsTest extends TestCase
{
	public function testConstants(): void
	{
		$this->assertSame(10 * 1024 * 1024, ChunkUploadService::MAX_CHUNK);
		$this->assertSame(512, ChunkUploadService::MAX_CHUNKS);
		$this->assertLessThanOrEqual(512 * 10 * 1024 * 1024, ChunkUploadService::MAX_CHUNK * ChunkUploadService::MAX_CHUNKS);
	}
}
