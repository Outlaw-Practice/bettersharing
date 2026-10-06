<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One recipient of a bundle. Written with the bundle, before any share exists,
 * so an interrupted operation can always be recovered or reported.
 *
 * @method int getBundleId()
 * @method void setBundleId(int $id)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getRecipient()
 * @method void setRecipient(string $recipient)
 * @method string getDisplay()
 * @method void setDisplay(string $display)
 * @method int getPermissions()
 * @method void setPermissions(int $permissions)
 * @method ?string getExpireDate()
 * @method void setExpireDate(?string $date)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method ?string getShareId()
 * @method void setShareId(?string $id)
 * @method ?string getExistingShareId()
 * @method void setExistingShareId(?string $id)
 * @method ?string getResult()
 * @method void setResult(?string $result)
 * @method ?int getClaimedAt()
 * @method void setClaimedAt(?int $time)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $time)
 */
class Recipient extends Entity {
	public const KIND_USER = 'user';
	public const KIND_GROUP = 'group';
	public const KIND_EMAIL = 'email';

	public const PENDING = 'pending';
	public const CLAIMED = 'claimed';
	public const SHARED = 'shared';
	public const EXISTING = 'existing';
	public const FAILED = 'failed';
	public const REMOVED = 'removed';

	protected $bundleId = null;
	protected $kind = null;
	protected $recipient = null;
	protected $display = null;
	protected $permissions = null;
	protected $expireDate = null;
	protected $status = null;
	protected $shareId = null;
	protected $existingShareId = null;
	protected $result = null;
	protected $claimedAt = null;
	protected $updatedAt = null;

	public function __construct() {
		$this->addType('bundleId', Types::INTEGER);
		$this->addType('permissions', Types::INTEGER);
		$this->addType('claimedAt', Types::INTEGER);
		$this->addType('updatedAt', Types::INTEGER);
	}
}
