<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getFirm()
 * @method void setFirm(string $firm)
 * @method string getName()
 * @method void setName(string $name)
 * @method int getPermissions()
 * @method void setPermissions(int $permissions)
 * @method ?int getExpireDays()
 * @method void setExpireDays(?int $days)
 * @method ?string getMessage()
 * @method void setMessage(?string $message)
 * @method bool getPinned()
 * @method void setPinned(bool $pinned)
 * @method int getUseCount()
 * @method void setUseCount(int $count)
 * @method ?int getLastUsedAt()
 * @method void setLastUsedAt(?int $time)
 * @method string getUpdatedBy()
 * @method void setUpdatedBy(string $uid)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $time)
 */
class Preset extends Entity implements JsonSerializable {
	protected $firm = null;
	protected $name = null;
	protected $permissions = null;
	protected $expireDays = null;
	protected $message = null;
	protected $pinned = null;
	protected $useCount = null;
	protected $lastUsedAt = null;
	protected $updatedBy = null;
	protected $updatedAt = null;

	public function __construct() {
		$this->addType('permissions', Types::INTEGER);
		$this->addType('expireDays', Types::INTEGER);
		$this->addType('pinned', Types::BOOLEAN);
		$this->addType('useCount', Types::INTEGER);
		$this->addType('lastUsedAt', Types::INTEGER);
		$this->addType('updatedAt', Types::INTEGER);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'name' => $this->getName(),
			'permissions' => $this->getPermissions(),
			'expireDays' => $this->getExpireDays(),
			'message' => $this->getMessage() ?? '',
			'pinned' => (bool)$this->getPinned(),
			'useCount' => $this->getUseCount(),
			'lastUsedAt' => $this->getLastUsedAt(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
