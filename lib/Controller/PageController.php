<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Controller;

use OCA\KanzleiCheck\AppInfo\Application;
use OCA\KanzleiCheck\ConfigTransfer\TransferReceiptStore;
use OCA\KanzleiCheck\Support\AssetRegistrar;
use OCA\KanzleiCheck\Support\SupportUsLinks;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

/** Staff app shell — access door enforced by StaffAccessMiddleware. */
class PageController extends Controller implements \OCA\KanzleiCheck\Contracts\StaffContextAware
{
	public string $staffUid = '';
	public bool $staffIsAdmin = false;

	/** SPA views rendered inside the shell — id => IconCatalog name. */
	private const VIEWS = [
		'overview' => 'home',
		'inbox' => 'inbox',
		'board' => 'clipboard-list',
		'requests' => 'file-check',
		'orgs' => 'users',
		'uploads' => 'upload',
		'holds' => 'file-warning',
		'forms' => 'clipboard-check',
		'people' => 'users',
	];

	/** Settings sections — one URL per topic (/settings/{section}).
	 * 'transfer' and 'about' are server-rendered; the rest mount the SPA. */
	private const SETTINGS_SECTIONS = [
		'access' => 'lock',
		'general' => 'settings',
		'documents' => 'file-text',
		'mail' => 'inbox',
		'license' => 'file-check',
		'diagnostics' => 'activity',
		'transfer' => 'download',
		'about' => 'info',
	];
	private const SETTINGS_DEFAULT = 'general';

	public function setStaffContext(string $uid, bool $isAppAdmin): void
	{
		$this->staffUid = $uid;
		$this->staffIsAdmin = $isAppAdmin;
	}

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TransferReceiptStore $transferReceipts,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l,
		private readonly IConfig $config,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		$view = (string)$this->request->getParam('view', 'overview');
		if (!isset(self::VIEWS[$view]) || ($view === 'people' && !$this->staffIsAdmin)) {
			$view = 'overview';
		}
		return $this->page($view, $this->viewLabel($view), self::VIEWS[$view]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(string $section = ''): TemplateResponse|RedirectResponse
	{
		if ($section === '' || !isset(self::SETTINGS_SECTIONS[$section])) {
			return new RedirectResponse(
				$this->urlGenerator->linkToRoute('kanzleicheck.page.settings', ['section' => self::SETTINGS_DEFAULT])
			);
		}
		$isAdmin = $this->staffIsAdmin;
		if ($section === 'transfer' && $isAdmin) {
			$uid = $this->staffUid !== ''
				? $this->staffUid
				: (string)($this->userSession->getUser()?->getUID() ?? '');
			return $this->page('settings', $this->sectionLabel($section), 'download', [
				'pageTemplate' => 'parts/settings/transfer.php',
				'settingsSection' => $section,
				'pageScript' => '',
				'styles' => ['common/transfer'],
				'scripts' => ['transfer-bridge', 'transfer'],
				'params' => [
					'isSystemAdmin' => $uid !== '' && $this->groupManager->isAdmin($uid),
					'transferLastReceipt' => $this->transferReceipts->lastReceipt(),
				],
			]);
		}
		if ($section === 'about') {
			return $this->page('settings', $this->sectionLabel($section), 'info', [
				'pageTemplate' => 'parts/settings/about.php',
				'settingsSection' => $section,
				'pageScript' => '',
				'params' => [
					'supportUsLinks' => new SupportUsLinks(
						'KanzleiCheck',
						true,
						$this->urlGenerator->linkToRoute('kanzleicheck.page.settings', ['section' => 'license'])
					),
				],
			]);
		}
		// SPA-rendered settings sections — the SPA boots into this section and
		// shows its no-access card for non-admins (requireAdmin stays on the API).
		return $this->page('settings', $this->sectionLabel($section), self::SETTINGS_SECTIONS[$section], [
			'settingsSection' => $section,
			'spaView' => 'settings',
		]);
	}

	/** Print-proof page (US-P23): hash-bound decision record, printable. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function approvalProof(int $id): TemplateResponse
	{
		$a = \OCP\Server::get(\OCA\KanzleiCheck\Db\ApprovalMapper::class)->find($id);
		if ($a === null) {
			throw new \OCA\KanzleiCheck\Exception\NotFoundException();
		}
		return new TemplateResponse(Application::APP_ID, 'staff/approval-proof', [
			'id' => (int)$a->getId(), 'fileName' => $a->getFileName(), 'sha' => $a->getFileSha256(),
			'state' => $a->getState(), 'requestedBy' => $a->getRequestedBy(),
			'requestedAt' => $a->getRequestedAt()->format('d.m.Y H:i'),
			'decidedBy' => $a->getDecidedBy(), 'decidedAt' => $a->getDecidedAt()?->format('d.m.Y H:i'),
			'method' => $a->getConfirmMethod(), 'reason' => $a->getReason(),
		]);
	}

	private function viewLabel(string $view): string
	{
		return match ($view) {
			'inbox' => $this->l->t('Messages'),
			'board' => $this->l->t('Tasks'),
			'requests' => $this->l->t('Requests'),
			'orgs' => $this->l->t('Clients'),
			'uploads' => $this->l->t('Uploads'),
			'holds' => $this->l->t('Mail review'),
			'forms' => $this->l->t('Questionnaires'),
			'people' => $this->l->t('People'),
			default => $this->l->t('Overview'),
		};
	}

	private function sectionLabel(string $section): string
	{
		return match ($section) {
			'access' => $this->l->t('Access'),
			'general' => $this->l->t('Appearance & preset'),
			'documents' => $this->l->t('Documents'),
			'mail' => $this->l->t('Inbound e-mail'),
			'license' => $this->l->t('License'),
			'diagnostics' => $this->l->t('Diagnostics'),
			'transfer' => $this->l->t('Transfer'),
			'about' => $this->l->t('About this project'),
			default => $section,
		};
	}

	/**
	 * Shared page builder — every staff page funnels through here so the
	 * check-shell chrome (sidebar nav groups, settings nav, help footer,
	 * role strip, assets) never diverges between pages.
	 *
	 * @param array{pageTemplate?:string,pageScript?:string,spaView?:string,
	 *   settingsSection?:string,styles?:list<string>,scripts?:list<string>,
	 *   params?:array<string,mixed>} $opt
	 */
	private function page(string $pageId, string $title, string $icon, array $opt = []): TemplateResponse
	{
		AssetRegistrar::register($opt['pageScript'] ?? 'staff-app');
		Util::addHeader('link', [
			'rel' => 'icon',
			'type' => 'image/svg+xml',
			'href' => $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'),
		]);
		foreach ($opt['styles'] ?? [] as $s) {
			Util::addStyle(Application::APP_ID, $s);
		}
		foreach ($opt['scripts'] ?? [] as $s) {
			Util::addScript(Application::APP_ID, $s);
		}

		$indexUrl = $this->urlGenerator->linkToRoute('kanzleicheck.page.index');
		$items = [];
		foreach (self::VIEWS as $id => $itemIcon) {
			if ($id === 'people' && !$this->staffIsAdmin) {
				continue;
			}
			$items[] = [
				'id' => $id,
				'label' => $this->viewLabel($id),
				'href' => $indexUrl . '?view=' . $id,
				'icon' => $itemIcon,
				'active' => $pageId === $id,
			];
		}
		$navGroups = [[
			'label' => $this->l->t('Workspace'),
			'items' => $items,
		]];

		$activeSection = (string)($opt['settingsSection'] ?? '');
		$settingsNav = [];
		$settingsSectionLabels = [];
		$settingsSectionUrls = [];
		foreach (self::SETTINGS_SECTIONS as $sec => $secIcon) {
			if (!$this->staffIsAdmin && $sec !== 'about') {
				continue;
			}
			$href = $this->urlGenerator->linkToRoute('kanzleicheck.page.settings', ['section' => $sec]);
			$settingsNav[] = [
				'id' => 'settings-' . $sec,
				'label' => $this->sectionLabel($sec),
				'href' => $href,
				'icon' => $secIcon,
				'active' => $activeSection === $sec,
			];
			$settingsSectionLabels[$sec] = $this->sectionLabel($sec);
			$settingsSectionUrls[$sec] = $href;
		}

		$lang = method_exists($this->l, 'getLanguageCode') ? (string)$this->l->getLanguageCode() : 'en';
		$uid = $this->staffUid !== ''
			? $this->staffUid
			: (string)($this->userSession->getUser()?->getUID() ?? '');
		$timezone = $uid !== ''
			? (string)$this->config->getUserValue($uid, 'core', 'timezone', 'UTC')
			: 'UTC';

		return new TemplateResponse(Application::APP_ID, 'staff/main', array_merge([
			'appId' => Application::APP_ID,
			'pageId' => (string)($opt['spaView'] ?? $pageId),
			'hideScopeStrip' => (string)($opt['spaView'] ?? $pageId) !== 'overview',
			'pageTitle' => $title,
			'pageHelp' => '',
			'pageIcon' => $icon,
			'pageTemplate' => $opt['pageTemplate'] ?? '',
			'settingsSection' => $activeSection,
			'roleLabel' => $this->staffIsAdmin ? $this->l->t('App admin') : $this->l->t('Staff'),
			'brandIcon' => 'shield',
			'brandTitle' => $this->l->t('KanzleiCheck'),
			'brandSubtitle' => $this->l->t('Client portal'),
			'navGroups' => $navGroups,
			'settingsNav' => $settingsNav,
			'settingsSectionLabels' => $settingsSectionLabels,
			'kzcRequestedSection' => $activeSection,
			'footerPartial' => \dirname(__DIR__, 2) . '/templates/parts/feedback-nav-footer.php',
			'appFeedbackCssPrefix' => 'kzc',
			'urls' => ['home' => $indexUrl, 'settingsSections' => $settingsSectionUrls],
			'clientHints' => [
				'locale' => $lang,
				'htmlLang' => $lang,
				'timezone' => $timezone,
			],
		], $opt['params'] ?? []));
	}
}
