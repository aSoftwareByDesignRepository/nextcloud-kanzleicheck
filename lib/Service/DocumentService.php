<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Service;

use OCA\KanzleiCheck\Exception\ApiException;
use OCA\KanzleiCheck\Exception\NotFoundException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException as NcNotFoundException;

/**
 * Files adapter (§9.6, AF-20).
 *
 * Documents live under an admin-chosen root folder (a Groupfolder or a
 * folder owned by a staff user — never an app-created account). Every
 * resolve is confined: the returned node's path MUST start with the org
 * folder's path under the root — no `..`, no share resolution, no escape
 * even if the root owner has wider permissions (NN-P11).
 *
 * Binding is by file id, never by path: kzc_org.folder_id pins the org
 * folder; Eingang/Dokumente resolve as its children.
 */
class DocumentService
{
	public const DIR_INBOX = 'Eingang';
	public const DIR_DOCS = 'Dokumente';

	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly SettingsService $settings,
	) {
	}

	public function rootFolder(): ?Folder
	{
		$id = (int)$this->settings->get('docs_root_id');
		if ($id <= 0) {
			return null;
		}
		$nodes = $this->rootFolder->getById($id);
		$node = $nodes[0] ?? null;
		return $node instanceof Folder ? $node : null;
	}

	public function hasRoot(): bool
	{
		return $this->rootFolder() !== null;
	}

	/** Health: root exists and is writable — else clients get NO_DOCS_ROOT. */
	public function rootHealthy(): bool
	{
		$root = $this->rootFolder();
		return $root !== null && $root->isCreatable();
	}

	/** Org folder (<root>/<org-slug>-<id>/), created on demand, rebound by id. */
	public function orgFolder(int $orgId, string $orgName, ?int $storedFolderId, ?string $storedPath): ?Folder
	{
		$root = $this->rootFolder();
		if ($root === null) {
			return null;
		}
		if ($storedFolderId !== null && $storedFolderId > 0) {
			$nodes = $root->getById($storedFolderId);
			$node = $nodes[0] ?? null;
			if ($node instanceof Folder && $this->inside($root, $node)) {
				return $node;
			}
		}
		// rebind by stored relative path once (moved/renamed org folder)
		if ($storedPath !== null && $storedPath !== '') {
			try {
				$node = $root->get($storedPath);
				if ($node instanceof Folder) {
					return $node;
				}
			} catch (NcNotFoundException) {
			}
		}
		$slug = $this->slug($orgName) . '-' . $orgId;
		try {
			$f = $root->get($slug);
			if ($f instanceof Folder) {
				return $f;
			}
		} catch (NcNotFoundException) {
		}
		return $root->newFolder($slug);
	}

	public function subFolder(Folder $orgFolder, string $name): ?Folder
	{
		try {
			$n = $orgFolder->get($name);
			return $n instanceof Folder ? $n : null;
		} catch (NcNotFoundException) {
			return null;
		}
	}

	public function ensureSubFolder(Folder $orgFolder, string $name): Folder
	{
		$f = $this->subFolder($orgFolder, $name);
		return $f ?? $orgFolder->newFolder($name);
	}

	public function findNodeById(int $fileId): ?Node
	{
		if ($fileId <= 0) {
			return null;
		}
		$nodes = $this->rootFolder->getById($fileId);
		return $nodes[0] ?? null;
	}

	/**
	 * Confined resolve: file inside the org's Dokumente/ tree or throw
	 * uniform NOT_FOUND — foreign-org and outside-root ids are identical.
	 */
	public function documentNodeForOrg(int $orgId, int $fileId): File
	{
		return $this->orgNodeForOrg($orgId, $fileId, self::DIR_DOCS);
	}

	/**
	 * Confined resolve inside <org>/<subdir>/ (or either managed subdir when
	 * $subdir is null): file id must live inside the org folder under the
	 * root — foreign ids and outside-root ids are uniformly NOT_FOUND.
	 */
	public function orgNodeForOrg(int $orgId, int $fileId, ?string $subdir): File
	{
		$root = $this->rootFolder();
		if ($root === null) {
			throw new NotFoundException();
		}
		// Resolve inside the docs root itself: IRootFolder->getById can pick a
		// different mount (owner vs sharee) than rootFolder(), and view paths
		// of different mounts are not comparable.
		$nodes = $root->getById($fileId);
		$node = $nodes[0] ?? null;
		if (!$node instanceof File) {
			throw new NotFoundException();
		}
		$parts = explode('/', trim($node->getPath(), '/'));
		$rootParts = explode('/', trim($root->getPath(), '/'));
		$rel = array_slice($parts, count($rootParts));
		$dirs = $subdir !== null ? [$subdir] : [self::DIR_DOCS, self::DIR_INBOX];
		if (count($rel) < 3 || !str_ends_with($rel[0], '-' . $orgId) || !in_array($rel[1], $dirs, true)) {
			throw new NotFoundException();
		}
		return $node;
	}

	public function hashFile(File $f): string
	{
		$ctx = hash_init('sha256');
		$stream = $f->fopen('r');
		if ($stream === false) {
			throw new NotFoundException();
		}
		hash_update_stream($ctx, $stream);
		fclose($stream);
		return hash_final($ctx);
	}

	public function inside(Folder $root, Node $node): bool
	{
		$rp = rtrim($root->getPath(), '/') . '/';
		return str_starts_with($node->getPath() . '/', $rp) || rtrim($node->getPath(), '/') === rtrim($root->getPath(), '/');
	}

	/** Collision-safe unique name inside a folder (US-P11 AC-3). */
	public function uniqueName(Folder $dir, string $name): string
	{
		if (!$dir->nodeExists($name)) {
			return $name;
		}
		$dot = strrpos($name, '.');
		$base = $dot === false ? $name : substr($name, 0, $dot);
		$ext = $dot === false ? '' : substr($name, $dot);
		for ($i = 2; $i < 1000; $i++) {
			$candidate = $base . ' (' . $i . ')' . $ext;
			if (!$dir->nodeExists($candidate)) {
				return $candidate;
			}
		}
		return $base . '-' . bin2hex(random_bytes(4)) . $ext;
	}

	private function slug(string $name): string
	{
		$s = strtolower(trim($name));
		$s = preg_replace('/[^a-z0-9äöüß]+/i', '-', $s) ?? 'org';
		$s = trim($s, '-');
		return mb_substr($s !== '' ? $s : 'org', 0, 60);
	}
}
