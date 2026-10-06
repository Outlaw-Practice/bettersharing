<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Service;

use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Share\IManager;

/**
 * Sharing with people outside the firm needs working mail, the email share
 * provider, enforced passwords, and password emails turned on. If any of these
 * is missing, outside sharing is off (fails closed) and the reason is shown.
 */
class OutsideSharingReadiness {
	public function __construct(
		private IConfig $config,
		private IAppManager $appManager,
		private IManager $shareManager,
		private IL10N $l,
	) {
	}

	/** @return string|null null when ready, otherwise a reason a user can read */
	public function problem(): ?string {
		$mode = (string)$this->config->getSystemValue('mail_smtpmode', '');
		if ($mode === '' || $mode === 'null') {
			return $this->l->t('Sharing with people outside your firm is off because this server cannot send email yet.');
		}
		if ($mode === 'smtp' && (string)$this->config->getSystemValue('mail_smtphost', '') === '') {
			return $this->l->t('Sharing with people outside your firm is off because this server cannot send email yet.');
		}
		if (!$this->appManager->isEnabledForAnyone('sharebymail') || !$this->shareManager->shareProviderExists(\OCP\Share\IShare::TYPE_EMAIL)) {
			return $this->l->t('Sharing with people outside your firm is off because email sharing is turned off on this server.');
		}
		if (!$this->shareManager->shareApiLinkEnforcePassword()) {
			return $this->l->t('Sharing with people outside your firm is off because passwords are not required for shared links on this server.');
		}
		// Read exactly as sharebymail's SettingsManager does, so both always agree.
		if (!self::passwordMailEnabled((string)$this->config->getAppValue('sharebymail', 'sendpasswordmail', 'yes'))) {
			return $this->l->t('Sharing with people outside your firm is off because password emails are turned off on this server.');
		}
		return null;
	}

	/** Same rule as sharebymail's SettingsManager::sendPasswordByMail(): unset or "yes" means on. */
	public static function passwordMailEnabled(string $stored): bool {
		return $stored === 'yes';
	}
}
