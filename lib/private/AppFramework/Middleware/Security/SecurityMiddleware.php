<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Bernhard Posselt <dev@bernhard-posselt.com>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Stefan Weil <sw@weilnetz.de>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Thomas Tanghus <thomas@tanghus.net>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */


namespace OC\AppFramework\Middleware\Security;

use OC\AppFramework\Middleware\Security\Exceptions\AppNotEnabledException;
use OC\AppFramework\Middleware\Security\Exceptions\CrossSiteRequestForgeryException;
use OC\AppFramework\Middleware\Security\Exceptions\NotAdminException;
use OC\AppFramework\Middleware\Security\Exceptions\NotLoggedInException;
use OC\AppFramework\Middleware\Security\Exceptions\StrictCookieMissingException;
use OC\AppFramework\Utility\ControllerMethodReflector;
use OC\Security\CSP\ContentSecurityPolicyManager;
use OC\Security\CSP\ContentSecurityPolicyNonceManager;
use OC\Security\CSRF\CsrfTokenManager;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IRequest;
use OCP\ILogger;
use OCP\AppFramework\Controller;
use OCP\Util;
use OC\AppFramework\Middleware\Security\Exceptions\SecurityException;

/**
 * Used to do all the authentication and checking stuff for a controller method
 * It reads out the annotations of a controller method and checks which if
 * security things should be checked and also handles errors in case a security
 * check fails
 */
class SecurityMiddleware extends Middleware {
	/** @var INavigationManager */
	private $navigationManager;
	/** @var IRequest */
	private $request;
	/** @var ControllerMethodReflector */
	private $reflector;
	/** @var string */
	private $appName;
	/** @var IURLGenerator */
	private $urlGenerator;
	/** @var ILogger */
	private $logger;
	/** @var bool */
	private $isLoggedIn;
	/** @var bool */
	private $isAdminUser;
	/** @var ContentSecurityPolicyManager */
	private $contentSecurityPolicyManager;
	/** @var CsrfTokenManager */
	private $csrfTokenManager;
	/** @var ContentSecurityPolicyNonceManager */
	private $cspNonceManager;
	/** @var IAppManager */
	private $appManager;
	/** @var IL10N */
	private $l10n;

	public function __construct(IRequest $request,
								ControllerMethodReflector $reflector,
								INavigationManager $navigationManager,
								IURLGenerator $urlGenerator,
								ILogger $logger,
								string $appName,
								bool $isLoggedIn,
								bool $isAdminUser,
								ContentSecurityPolicyManager $contentSecurityPolicyManager,
								CsrfTokenManager $csrfTokenManager,
								ContentSecurityPolicyNonceManager $cspNonceManager,
								IAppManager $appManager,
								IL10N $l10n
	) {
		$this->navigationManager = $navigationManager;
		$this->request = $request;
		$this->reflector = $reflector;
		$this->appName = $appName;
		$this->urlGenerator = $urlGenerator;
		$this->logger = $logger;
		$this->isLoggedIn = $isLoggedIn;
		$this->isAdminUser = $isAdminUser;
		$this->contentSecurityPolicyManager = $contentSecurityPolicyManager;
		$this->csrfTokenManager = $csrfTokenManager;
		$this->cspNonceManager = $cspNonceManager;
		$this->appManager = $appManager;
		$this->l10n = $l10n;
	}

	/**
	 * This runs all the security checks before a method call. The
	 * security checks are determined by inspecting the controller method
	 * annotations
	 * @param Controller $controller the controller
	 * @param string $methodName the name of the method
	 * @throws SecurityException when a security check fails
	 */
	public function beforeController($controller, $methodName) {

		// this will set the current navigation entry of the app, use this only
		// for normal HTML requests and not for AJAX requests
		$this->navigationManager->setActiveEntry($this->appName);

		// security checks
		$isPublicPage = $this->reflector->hasAnnotation('PublicPage');
		if(!$isPublicPage) {
			if(!$this->isLoggedIn) {
				throw new NotLoggedInException();
			}

			if(!$this->reflector->hasAnnotation('NoAdminRequired') && !$this->isAdminUser) {
				throw new NotAdminException($this->l10n->t('Logged in user must be an admin'));
			}
		}

		// Check for strict cookie requirement
		if($this->reflector->hasAnnotation('StrictCookieRequired') || !$this->reflector->hasAnnotation('NoCSRFRequired')) {
			if(!$this->request->passesStrictCookieCheck()) {
				throw new StrictCookieMissingException();
			}
		}
		// CSRF check - also registers the CSRF token since the session may be closed later
		Util::callRegister();
		if(!$this->reflector->hasAnnotation('NoCSRFRequired')) {
			/*
			 * Only allow the CSRF check to fail on OCS Requests. This kind of
			 * hacks around that we have no full token auth in place yet and we
			 * do want to offer CSRF checks for web requests.
			 *
			 * Additionally we allow Bearer authenticated requests to pass on OCS routes.
			 * This allows oauth apps (e.g. moodle) to use the OCS endpoints
			 */
			if(!$this->request->passesCSRFCheck() && !(
					$controller instanceof OCSController && (
						$this->request->getHeader('OCS-APIREQUEST') === 'true' ||
						strpos($this->request->getHeader('Authorization'), 'Bearer ') === 0
					)
				)) {
				throw new CrossSiteRequestForgeryException();
			}
		}

		/**
		 * Checks if app is enabled (also includes a check whether user is allowed to access the resource)
		 * The getAppPath() check is here since components such as settings also use the AppFramework and
		 * therefore won't pass this check.
		 * If page is public, app does not need to be enabled for current user/visitor
		 */
		try {
			$appPath = $this->appManager->getAppPath($this->appName);
		} catch (AppPathNotFoundException $e) {
			$appPath = false;
		}

		if ($appPath !== false && !$isPublicPage && !$this->appManager->isEnabledForUser($this->appName)) {
			throw new AppNotEnabledException();
		}
	}

	/**
	 * Performs the default CSP modifications that may be injected by other
	 * applications
	 *
	 * @param Controller $controller
	 * @param string $methodName
	 * @param Response $response
	 * @return Response
	 */
	public function afterController($controller, $methodName, Response $response): Response {
		$policy = !is_null($response->getContentSecurityPolicy()) ? $response->getContentSecurityPolicy() : new ContentSecurityPolicy();

		if (get_class($policy) === EmptyContentSecurityPolicy::class) {
			return $response;
		}

		$defaultPolicy = $this->contentSecurityPolicyManager->getDefaultPolicy();
		$defaultPolicy = $this->contentSecurityPolicyManager->mergePolicies($defaultPolicy, $policy);

		if($this->cspNonceManager->browserSupportsCspV3()) {
			$defaultPolicy->useJsNonce($this->csrfTokenManager->getToken()->getEncryptedValue());
		}

		$response->setContentSecurityPolicy($defaultPolicy);

		return $response;
	}

	/**
	 * If an SecurityException is being caught, ajax requests return a JSON error
	 * response and non ajax requests redirect to the index
	 * @param Controller $controller the controller that is being called
	 * @param string $methodName the name of the method that will be called on
	 *                           the controller
	 * @param \Exception $exception the thrown exception
	 * @throws \Exception the passed in exception if it can't handle it
	 * @return Response a Response object or null in case that the exception could not be handled
	 */
	public function afterException($controller, $methodName, \Exception $exception): Response {
		if($exception instanceof SecurityException) {
			if($exception instanceof StrictCookieMissingException) {
				return new RedirectResponse(\OC::$WEBROOT);
 			}
			if (stripos($this->request->getHeader('Accept'),'html') === false) {
				$response = new JSONResponse(
					['message' => $exception->getMessage()],
					$exception->getCode()
				);
			} else {
				if($exception instanceof NotLoggedInException) {
					$params = [];
					if (isset($this->request->server['REQUEST_URI'])) {
						$params['redirect_url'] = $this->request->server['REQUEST_URI'];
					}
					$url = $this->urlGenerator->linkToRoute('core.login.showLoginForm', $params);
					$response = new RedirectResponse($url);
				} else {
					$response = new TemplateResponse('core', '403', ['file' => $exception->getMessage()], 'guest');
					$response->setStatus($exception->getCode());
				}
			}

			$this->logger->logException($exception, [
				'level' => \OCP\Util::DEBUG,
				'app' => 'core',
			]);
			return $response;
		}

		throw $exception;
	}

}
