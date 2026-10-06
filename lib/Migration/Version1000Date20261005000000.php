<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20261005000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('bettersharing_presets')) {
			$t = $schema->createTable('bettersharing_presets');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('firm', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 120]);
			$t->addColumn('permissions', Types::SMALLINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('expire_days', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('message', Types::TEXT, ['notnull' => false]);
			$t->addColumn('pinned', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('use_count', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('last_used_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('updated_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['firm', 'name'], 'bs_presets_firm_name');
		}

		if (!$schema->hasTable('bettersharing_bundles')) {
			$t = $schema->createTable('bettersharing_bundles');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('owner', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('firm', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('message', Types::TEXT, ['notnull' => false]);
			$t->addColumn('preset_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('preset_name', Types::STRING, ['notnull' => false, 'length' => 120]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('idem_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('fingerprint', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['owner', 'firm', 'file_id', 'idem_key'], 'bs_bundles_idem');
			$t->addIndex(['owner', 'file_id'], 'bs_bundles_owner_file');
		}

		if (!$schema->hasTable('bettersharing_recipients')) {
			$t = $schema->createTable('bettersharing_recipients');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('bundle_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 8]);
			$t->addColumn('recipient', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('display', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('permissions', Types::SMALLINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('expire_date', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
			// Set only on the row that created the share; unique, so one share has one owner row.
			$t->addColumn('share_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			// Access that existed before (or was won by another row); never changed by BetterSharing.
			$t->addColumn('existing_share_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('result', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('claimed_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['bundle_id'], 'bs_recipients_bundle');
			$t->addUniqueIndex(['share_id'], 'bs_recipients_share');
		}

		return $schema;
	}
}
