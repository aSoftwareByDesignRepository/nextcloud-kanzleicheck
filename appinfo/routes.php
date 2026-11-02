<?php

declare(strict_types=1);

return [
	'routes' => [
		// staff UI
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#settings', 'url' => '/settings/{section}', 'verb' => 'GET', 'requirements' => ['section' => '[a-z-]*'], 'defaults' => ['section' => '']],
		// staff API (door via StaffAccessMiddleware)
		['name' => 'staffApi#overview', 'url' => '/api/portal/overview', 'verb' => 'GET'],
		['name' => 'staffApi#inbox', 'url' => '/api/portal/inbox', 'verb' => 'GET'],
		['name' => 'staffApi#messages', 'url' => '/api/portal/orgs/{orgId}/messages', 'verb' => 'GET', 'requirements' => ['orgId' => '\d+']],
		['name' => 'staffApi#sendMessage', 'url' => '/api/portal/orgs/{orgId}/messages', 'verb' => 'POST', 'requirements' => ['orgId' => '\d+']],
		['name' => 'staffApi#orgs', 'url' => '/api/portal/orgs', 'verb' => 'GET'],
		['name' => 'staffApi#createOrg', 'url' => '/api/portal/orgs', 'verb' => 'POST'],
		['name' => 'staffApi#updateOrg', 'url' => '/api/portal/orgs/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'staffApi#board', 'url' => '/api/portal/board', 'verb' => 'GET'],
		['name' => 'staffApi#createTask', 'url' => '/api/portal/tasks', 'verb' => 'POST'],
		['name' => 'staffApi#updateTask', 'url' => '/api/portal/tasks/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'staffApi#moveTask', 'url' => '/api/portal/tasks/{id}/move', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'staffApi#requestsList', 'url' => '/api/portal/requests', 'verb' => 'GET'],
		['name' => 'staffApi#createRequest', 'url' => '/api/portal/requests', 'verb' => 'POST'],
		['name' => 'staffApi#importCsv', 'url' => '/api/portal/import', 'verb' => 'POST'],
		['name' => 'staffApi#applyLicense', 'url' => '/api/portal/license', 'verb' => 'POST'],
		['name' => 'staffApi#diagnostics', 'url' => '/api/portal/diagnostics', 'verb' => 'GET'],
		// client portal pages (guest)
		['name' => 'portal#login', 'url' => '/portal/login', 'verb' => 'GET'],
		['name' => 'portal#index', 'url' => '/portal', 'verb' => 'GET'],
		['name' => 'portal#magicGet', 'url' => '/portal/auth/magic/{token}', 'verb' => 'GET'],
		// client portal auth (public)
		['name' => 'portalAuth#send', 'url' => '/portal/api/auth/send', 'verb' => 'POST'],
		['name' => 'portalAuth#verify', 'url' => '/portal/api/auth/verify', 'verb' => 'POST'],
		['name' => 'portalAuth#magicPost', 'url' => '/portal/auth/magic/{token}', 'verb' => 'POST'],
		['name' => 'portalAuth#logout', 'url' => '/portal/api/auth/logout', 'verb' => 'POST'],
		// client portal API (session via middleware)
		['name' => 'portalApi#orgs', 'url' => '/portal/api/orgs', 'verb' => 'GET'],
		['name' => 'portalApi#todo', 'url' => '/portal/api/orgs/{orgId}/todo', 'verb' => 'GET', 'requirements' => ['orgId' => '\d+']],
		['name' => 'portalApi#messages', 'url' => '/portal/api/orgs/{orgId}/messages', 'verb' => 'GET', 'requirements' => ['orgId' => '\d+']],
		['name' => 'portalApi#sendMessage', 'url' => '/portal/api/orgs/{orgId}/messages', 'verb' => 'POST', 'requirements' => ['orgId' => '\d+']],
		['name' => 'portalApi#markRead', 'url' => '/portal/api/orgs/{orgId}/read', 'verb' => 'POST', 'requirements' => ['orgId' => '\d+']],
		['name' => 'portalApi#tasks', 'url' => '/portal/api/orgs/{orgId}/tasks', 'verb' => 'GET', 'requirements' => ['orgId' => '\d+']],
		['name' => 'portalApi#requests', 'url' => '/portal/api/orgs/{orgId}/requests', 'verb' => 'GET', 'requirements' => ['orgId' => '\d+']],
		['name' => 'portalApi#confirmItem', 'url' => '/portal/api/requests/{id}/items/{itemId}/confirm', 'verb' => 'POST', 'requirements' => ['id' => '\d+', 'itemId' => '\d+']],
		['name' => 'portalApi#approvals', 'url' => '/portal/api/orgs/{orgId}/approvals', 'verb' => 'GET', 'requirements' => ['orgId' => '\d+']],
		['name' => 'portalApi#approvalStart', 'url' => '/portal/api/approvals/{id}/start', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'portalApi#approvalDecide', 'url' => '/portal/api/approvals/{id}/decide', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'portalApi#unread', 'url' => '/portal/api/orgs/{orgId}/unread', 'verb' => 'GET', 'requirements' => ['orgId' => '\d+']],
		['name' => 'portalApi#devices', 'url' => '/portal/api/devices', 'verb' => 'GET'],
		['name' => 'portalApi#intake', 'url' => '/portal/api/orgs/{orgId}/intake', 'verb' => 'GET', 'requirements' => ['orgId' => '\d+']],
		['name' => 'portalApi#notifyPut', 'url' => '/portal/api/orgs/{orgId}/notify', 'verb' => 'PUT', 'requirements' => ['orgId' => '\d+']],
		// companion API
		['name' => 'companion#pair', 'url' => '/api/companion/v1/pair', 'verb' => 'POST'],
		['name' => 'companion#bootstrap', 'url' => '/api/companion/v1/bootstrap', 'verb' => 'GET'],
		// upload-link page + upload (public, token)
		['name' => 'ulink#page', 'url' => '/portal/u/{token}', 'verb' => 'GET'],
		['name' => 'ulink#upload', 'url' => '/portal/api/u/{token}/files', 'verb' => 'POST'],
	],
];
