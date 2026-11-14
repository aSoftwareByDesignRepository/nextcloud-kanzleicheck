<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Controller;

use OCA\KanzleiCheck\AppInfo\Application;
use OCA\KanzleiCheck\Exception\ApiException;
use OCA\KanzleiCheck\Exception\NotFoundException;
use OCA\KanzleiCheck\Service\ActorResolver;
use OCA\KanzleiCheck\Service\DocumentService;
use OCA\KanzleiCheck\Service\UploadLinkService;
use OCA\KanzleiCheck\Service\UploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Third-party upload link (US-P29): GET → upload-only page; POST → files
 * into the org's Eingang. Every invalid state renders the uniform not-found.
 */
class UlinkController extends Controller
{
	use ApiTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly UploadLinkService $links,
		private readonly UploadService $uploads,
		private readonly DocumentService $docs,
		private readonly \OCA\KanzleiCheck\Db\OrgMapper $orgs,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function page(string $token): TemplateResponse
	{
		$link = $this->links->resolve($token);
		if ($link === null) {
			return new TemplateResponse(Application::APP_ID, 'portal/notfound', [], 'guest');
		}
		$org = $this->orgs->find((int)$link->getOrgId());
		return new TemplateResponse(Application::APP_ID, 'portal/ulink', [
			'label' => $link->getLabel(), 'token' => $token,
			'orgName' => (bool)$link->getShowOrg() && $org !== null ? $org->getName() : null,
		], 'guest');
	}

	#[PublicPage] #[NoCSRFRequired]
	public function upload(string $token): JSONResponse
	{
		$link = $this->links->resolve($token);
		if ($link === null) {
			throw new NotFoundException();
		}
		$org = $this->orgs->find((int)$link->getOrgId());
		if ($org === null || $org->getState() !== 'active') {
			throw new NotFoundException();
		}
		if (!$this->docs->rootHealthy()) {
			throw new ApiException(503, 'NO_DOCS_ROOT', 'uploads unavailable');
		}
		$file = $_FILES['file'] ?? null;
		if ($file === null || !is_uploaded_file($file['tmp_name'] ?? '')) {
			throw new ApiException(400, 'VALIDATION_FAILED', 'no file');
		}
		$size = (int)$file['size'];
		$this->links->claimSlot((int)$link->getId(), $size); // atomic; LINK_EXHAUSTED loser
		$orgFolder = $this->docs->orgFolder((int)$org->getId(), (string)$org->getName(), $org->getFolderId(), $org->getFolderPath());
		if ($orgFolder === null) {
			throw new ApiException(503, 'NO_DOCS_ROOT', 'uploads unavailable');
		}
		$eingang = $this->docs->ensureSubFolder($orgFolder, DocumentService::DIR_INBOX);
		$this->uploads->validateFile($file['tmp_name'], (string)$file['name'], min($this->uploads->maxBytes(), (int)$link->getMaxBytes() - (int)$link->getUsedBytes() + $size));
		$res = $this->uploads->store($eingang, (int)$org->getId(), ActorResolver::ulink((int)$link->getId()), $file['tmp_name'], (string)$file['name'], 'ulink', 'über Upload-Link: ' . $link->getLabel());
		return $this->ok(['id' => (int)$res['upload']->getId(), 'duplicate' => $res['duplicate'] !== null], 201);
	}
}
