<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Db\ConversationMapper;
use OCA\KanzleiCheck\Db\Message;
use OCA\KanzleiCheck\Db\MessageMapper;
use OCA\KanzleiCheck\Db\ReadMarkerMapper;
use OCA\KanzleiCheck\Service\ChatService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unread badges clear via a per-actor monotonic read marker. markRead must
 * clamp the client-supplied id to the latest real message — a cursor ahead
 * of every existing id would stay in front of all future messages and hide
 * them as unread forever.
 */
final class ChatServiceMarkReadTest extends TestCase
{
	private function msg(int $id): Message
	{
		$m = new Message();
		$m->setId($id);
		return $m;
	}

	private function svc(MessageMapper&MockObject $messages, ReadMarkerMapper&MockObject $reads): ChatService
	{
		return new ChatService($this->createMock(ConversationMapper::class), $messages, $reads, $this->createMock(IDBConnection::class));
	}

	public function testAdvancesToRequestedIdWithinRange(): void
	{
		$messages = $this->createMock(MessageMapper::class);
		$messages->expects($this->once())->method('listPage')->with(5, 1)->willReturn([$this->msg(10)]);
		$reads = $this->createMock(ReadMarkerMapper::class);
		$reads->expects($this->once())->method('advance')->with(5, 'alice', 7);
		$this->svc($messages, $reads)->markRead(5, 'alice', 7);
	}

	public function testClampsToLatestMessageId(): void
	{
		$messages = $this->createMock(MessageMapper::class);
		$messages->method('listPage')->willReturn([$this->msg(10)]);
		$reads = $this->createMock(ReadMarkerMapper::class);
		$reads->expects($this->once())->method('advance')->with(5, 'alice', 10);
		$this->svc($messages, $reads)->markRead(5, 'alice', 999999);
	}

	public function testIgnoresNonPositiveIdsWithoutTouchingDb(): void
	{
		$messages = $this->createMock(MessageMapper::class);
		$messages->expects($this->never())->method('listPage');
		$reads = $this->createMock(ReadMarkerMapper::class);
		$reads->expects($this->never())->method('advance');
		$this->svc($messages, $reads)->markRead(5, 'alice', 0);
		$this->svc($messages, $reads)->markRead(5, 'alice', -3);
	}

	public function testSkipsEmptyConversation(): void
	{
		$messages = $this->createMock(MessageMapper::class);
		$messages->method('listPage')->willReturn([]);
		$reads = $this->createMock(ReadMarkerMapper::class);
		$reads->expects($this->never())->method('advance');
		$this->svc($messages, $reads)->markRead(5, 'alice', 7);
	}

	public function testUnreadCountsAfterActorMarkerExcludingOwnMessages(): void
	{
		$messages = $this->createMock(MessageMapper::class);
		$messages->expects($this->once())->method('countAfter')->with(5, 12, 'alice')->willReturn(3);
		$reads = $this->createMock(ReadMarkerMapper::class);
		$reads->expects($this->once())->method('get')->with(5, 'alice')->willReturn(12);
		$this->assertSame(3, $this->svc($messages, $reads)->unreadCount(5, 'alice'));
	}
}
