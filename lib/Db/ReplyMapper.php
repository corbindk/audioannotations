<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Reply> */
class ReplyMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'audioannotations_replies', Reply::class);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): Reply {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @param list<int> $noteIds
	 * @return array<int, list<Reply>> replies per note id, oldest first
	 */
	public function findByNotes(array $noteIds): array {
		if ($noteIds === []) {
			return [];
		}
		$byNote = [];
		foreach (array_chunk($noteIds, 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->in('note_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('created_at', 'ASC')
				->addOrderBy('id', 'ASC');
			foreach ($this->findEntities($qb) as $reply) {
				$byNote[$reply->getNoteId()][] = $reply;
			}
		}
		return $byNote;
	}

	/** @param list<int> $noteIds */
	public function deleteByNotes(array $noteIds): void {
		foreach (array_chunk($noteIds, 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->in('note_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}

	public function deleteByOwner(string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid)))
			->executeStatement();
	}
}
