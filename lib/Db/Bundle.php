<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One wizard result: what the user thinks of as "a share".
 *
 * @method string getOwner()
 * @method void setOwner(string $uid)
 * @method string getFirm()
 * @method void setFirm(string $firm)
 * @method int getFileId()
 * @method void setFileId(int $id)
 * @method string getName()
 * @method void setName(string $name)
 * @method ?string getMessage()
 * @method void setMessage(?string $message)
 * @method ?int getPresetId()
 * @method void setPresetId(?int $id)
 * @method ?string getPresetName()
 * @method void setPresetName(?string $name)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getIdemKey()
 * @method void setIdemKey(string $key)
 * @method string getFingerprint()
 * @method void setFingerprint(string $fp)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $time)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $time)
 */
class Bundle extends Entity {
	public const IN_PROGRESS = 'in_progress';
	public const COMPLETE = 'complete';

	protected $owner = null;
	protected $firm = null;
	protected $fileId = null;
	protected $name = null;
	protected $message = null;
	protected $presetId = null;
	protected $presetName = null;
	protected $status = null;
	protected $idemKey = null;
	protected $fingerprint = null;
	protected $createdAt = null;
	protected $updatedAt = null;

	public function __construct() {
		$this->addType('fileId', Types::INTEGER);
		$this->addType('presetId', Types::INTEGER);
		$this->addType('createdAt', Types::INTEGER);
		$this->addType('updatedAt', Types::INTEGER);
	}
}
