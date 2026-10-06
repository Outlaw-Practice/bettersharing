<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Controller;

use OCA\BetterSharing\Service\AccessService;
use OCA\BetterSharing\Service\BundleService;
use OCA\BetterSharing\Service\FirmException;
use OCA\BetterSharing\Service\FirmService;
use OCA\BetterSharing\Service\OutsideSharingReadiness;
use OCA\BetterSharing\Service\PresetService;
use OCA\BetterSharing\Service\RecipientResolver;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * BetterSharing's API. Every route acts as the signed-in user, inside their
 * firm; services check firm membership, ownership and edit rights.
 */
class ApiController extends OCSController {
	use Respond;

	public function __construct(
		IRequest $request,
		private IUserSession $session,
		private FirmService $firms,
		private OutsideSharingReadiness $readiness,
		private RecipientResolver $resolver,
		private PresetService $presets,
		private BundleService $bundles,
		private AccessService $access,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct('bettersharing', $request);
	}

	private function user(): IUser {
		$user = $this->session->getUser();
		if ($user === null) {
			throw new FirmException($this->l->t('Sign in to share.'));
		}
		return $user;
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/status')]
	public function status(): DataResponse {
		return $this->respond(function () {
			$user = $this->user();
			try {
				$firm = $this->firms->firmOf($user);
			} catch (FirmException $e) {
				return ['firm' => null, 'problem' => $e->getMessage(), 'isFirmAdmin' => false, 'outsideProblem' => null];
			}
			return [
				'firm' => ['id' => $firm, 'name' => $this->firms->firmName($firm)],
				'problem' => null,
				'isFirmAdmin' => $this->firms->isFirmAdmin($user, $firm),
				'outsideProblem' => $this->readiness->problem(),
			];
		});
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/recipients')]
	public function recipients(string $search = ''): DataResponse {
		return $this->respond(function () use ($search) {
			$user = $this->user();
			$firm = $this->firms->firmOf($user);
			return $this->resolver->search($user, $firm, $search, $this->readiness->problem() === null);
		});
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/presets')]
	public function presets(): DataResponse {
		return $this->respond(fn () => $this->presets->list($this->user()));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/presets')]
	public function createPreset(string $name = '', int $permissions = 0, ?int $expireDays = null, string $message = '', bool $pinned = false): DataResponse {
		$data = compact('name', 'permissions', 'expireDays', 'message', 'pinned');
		return $this->respond(fn () => $this->presets->create($this->user(), $data));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/presets/{id}')]
	public function updatePreset(int $id, string $name = '', int $permissions = 0, ?int $expireDays = null, string $message = '', bool $pinned = false): DataResponse {
		$data = compact('name', 'permissions', 'expireDays', 'message', 'pinned');
		return $this->respond(fn () => $this->presets->update($this->user(), $id, $data));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/presets/{id}')]
	public function deletePreset(int $id): DataResponse {
		return $this->respond(function () use ($id) {
			$this->presets->delete($this->user(), $id);
			return [];
		});
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/bundles')]
	public function createBundle(int $fileId = 0, array $recipients = [], ?int $presetId = null, int $permissions = 0, ?string $expireDate = null, string $name = '', string $message = '', string $idempotencyKey = ''): DataResponse {
		$data = compact('fileId', 'recipients', 'presetId', 'permissions', 'expireDate', 'name', 'message', 'idempotencyKey');
		return $this->respond(fn () => $this->bundles->create($this->user(), $data));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/bundles')]
	public function bundles(int $fileId = 0): DataResponse {
		return $this->respond(fn () => $this->bundles->listForFile($this->user(), $fileId));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/bundles/{id}')]
	public function bundle(int $id): DataResponse {
		return $this->respond(fn () => $this->bundles->get($this->user(), $id));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/bundles/{id}')]
	public function updateBundle(int $id, string $name = '', string $message = '', int $permissions = 0, ?string $expireDate = null, array $versions = [], array $add = []): DataResponse {
		$data = compact('name', 'message', 'permissions', 'expireDate', 'versions', 'add');
		return $this->respond(fn () => $this->bundles->update($this->user(), $id, $data));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/bundles/{id}/retry')]
	public function retry(int $id): DataResponse {
		return $this->respond(fn () => $this->bundles->retry($this->user(), $id));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/bundles/{id}/stop')]
	public function stopSharing(int $id, array $versions = []): DataResponse {
		return $this->respond(fn () => $this->bundles->stopSharing($this->user(), $id, $versions));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/bundles/{id}/recipients/{recipientId}/remove')]
	public function removeRecipient(int $id, int $recipientId, string $version = ''): DataResponse {
		return $this->respond(fn () => $this->bundles->removeRecipient($this->user(), $id, $recipientId, $version));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/bundles/{id}/recipients/{recipientId}/password')]
	public function newPassword(int $id, int $recipientId): DataResponse {
		return $this->respond(fn () => $this->bundles->sendNewPassword($this->user(), $id, $recipientId));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/access')]
	public function access(int $fileId = 0): DataResponse {
		return $this->respond(fn () => $this->access->list($this->user(), $fileId));
	}
}
