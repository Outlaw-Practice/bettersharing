<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Controller;

use OCA\BetterSharing\Service\FirmException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;

/**
 * Turns service results and user-facing errors into OCS responses.
 * Requires $this->logger (LoggerInterface) and $this->l (IL10N).
 */
trait Respond {
	private function respond(callable $fn): DataResponse {
		try {
			return new DataResponse($fn());
		} catch (FirmException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('BetterSharing request failed', ['exception' => $e]);
			return new DataResponse(
				['message' => $this->l->t('Something went wrong. Try again, and contact your administrator if it keeps happening.')],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}
}
