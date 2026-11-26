<?php

declare(strict_types=1);

namespace OCP\AppFramework;

/**
 * Fixture: controller outside the app namespace — CompanionSeatMiddleware's
 * class-prefix check must leave it untouched.
 */
class FakeForeignController extends Controller
{
	public function __construct(string $appName, \OCP\IRequest $request)
	{
		parent::__construct($appName, $request);
	}
}
