<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Controller;

use OCA\KanzleiCheck\AppInfo\Application;
use OCA\KanzleiCheck\Service\CsrfService;
use OCA\KanzleiCheck\Service\UploadLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/** Client portal pages (guest layout + NC theming, no slate — BF-09). */
class PortalController extends Controller
{
	public function __construct(string $appName, IRequest $request, private readonly UploadLinkService $ulinks)
	{
		parent::__construct($appName, $request);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function login(): TemplateResponse
	{
		return new TemplateResponse(Application::APP_ID, 'portal/login', [], 'guest');
	}

	#[PublicPage] #[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		return new TemplateResponse(Application::APP_ID, 'portal/app', ['csrfCookie' => CsrfService::COOKIE_NAME], 'guest');
	}

	#[PublicPage] #[NoCSRFRequired]
	public function ulink(string $token): TemplateResponse
	{
		$link = $this->ulinks->resolve($token);
		if ($link === null) {
			return new TemplateResponse(Application::APP_ID, 'portal/notfound', [], 'guest');
		}
		return new TemplateResponse(Application::APP_ID, 'portal/ulink', ['label' => $link->getLabel(), 'token' => $token, 'showOrg' => (bool)$link->getShowOrg()], 'guest');
	}
}
