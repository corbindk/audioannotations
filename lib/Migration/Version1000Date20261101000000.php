<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the notes and replies tables.
 */
class Version1000Date20261101000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('audioannotations_notes')) {
			$table = $schema->createTable('audioannotations_notes');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('owner_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('start_ms', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('end_ms', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'text']);
			$table->addColumn('body', Types::TEXT, ['notnull' => false]);
			$table->addColumn('voice_file_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('voice_duration_ms', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('color', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'blue']);
			$table->addColumn('visibility', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'shared']);
			$table->addColumn('resolved', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->addColumn('audio_etag', Types::STRING, ['notnull' => false, 'length' => 40]);
			$table->addColumn('audio_duration_ms', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'audioann_notes_pk');
			$table->addIndex(['file_id', 'start_ms'], 'audioann_file_start');
			$table->addIndex(['owner_uid'], 'audioann_owner');
		}

		if (!$schema->hasTable('audioannotations_replies')) {
			$table = $schema->createTable('audioannotations_replies');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('note_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('owner_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('body', Types::TEXT, ['notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			// Named explicitly: the default name for this table would exceed Nextcloud's 30-character index limit.
			$table->setPrimaryKey(['id'], 'audioann_replies_pk');
			$table->addIndex(['note_id'], 'audioann_reply_note');
		}

		return $schema;
	}
}
